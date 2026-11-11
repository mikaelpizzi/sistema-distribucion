#!/usr/bin/env python3
"""
Test Suite: Verificación de Vistas Modulares y Feature-Flags (SICM)
1. Verifica HTTP 200 y HTML de todas las pestañas autenticado como Administrador.
2. Comprueba apagado y encendido de módulos dinámicamente vía src/config/modules.php.
"""

import sys
import re
import time
import urllib.request
import urllib.parse
import http.cookiejar

BASE_URL = "http://localhost:8080"
MODULES_CONFIG_FILE = "src/config/modules.php"

class NoRedirectHandler(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

class SicmClient:
    def __init__(self):
        self.cj = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cj)
        )
        self.no_redirect_opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cj),
            NoRedirectHandler
        )

    def get(self, path, follow_redirects=True):
        url = BASE_URL + path if path.startswith('/') else f"{BASE_URL}/{path}"
        op = self.opener if follow_redirects else self.no_redirect_opener
        req = urllib.request.Request(url, headers={"User-Agent": "SicmModular-TestRunner/1.0"})
        try:
            with op.open(req) as resp:
                body = resp.read().decode('utf-8', errors='replace')
                return resp.status, body, resp.headers
        except urllib.error.HTTPError as e:
            body = e.read().decode('utf-8', errors='replace') if e.fp else ""
            return e.code, body, e.headers

    def post(self, path, data, follow_redirects=True):
        url = BASE_URL + path if path.startswith('/') else f"{BASE_URL}/{path}"
        op = self.opener if follow_redirects else self.no_redirect_opener
        encoded_data = urllib.parse.urlencode(data).encode('utf-8')
        req = urllib.request.Request(
            url,
            data=encoded_data,
            headers={
                "User-Agent": "SicmModular-TestRunner/1.0",
                "Content-Type": "application/x-www-form-urlencoded"
            }
        )
        try:
            with op.open(req) as resp:
                body = resp.read().decode('utf-8', errors='replace')
                return resp.status, body, resp.headers
        except urllib.error.HTTPError as e:
            body = e.read().decode('utf-8', errors='replace') if e.fp else ""
            return e.code, body, e.headers

    def login(self, email, password):
        status, body, _ = self.get('/login.php')
        m = re.search(r'name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']', body)
        csrf = m.group(1) if m else ""
        data = {
            'email': email,
            'password': password,
            'csrf_token': csrf,
            'accion': 'login'
        }
        status, body, headers = self.post('/login.php', data, follow_redirects=False)
        return status in [302, 303]

def set_module_flag(module_name: str, active: bool):
    with open(MODULES_CONFIG_FILE, 'r', encoding='utf-8') as f:
        content = f.read()
    
    val_str = 'true' if active else 'false'
    pattern = rf"('{module_name}'\s*=>\s*)(true|false)"
    new_content = re.sub(pattern, rf"\g<1>{val_str}", content)
    with open(MODULES_CONFIG_FILE, 'w', encoding='utf-8') as f:
        f.write(new_content)
    # Dar tiempo a opcache para revalidar el timestamp del archivo
    time.sleep(3.0)

def main():
    print("=" * 60)
    print("TEST: Modularización de Vistas y Feature-Flags (SICM)")
    print("=" * 60)

    client = SicmClient()
    ok = client.login("admin@salud.prueba.ve", "admin123")
    assert ok, "Fallo inicio de sesión de Administrador"
    print("✓ Login exitoso como Administrador")

    # 1. Comprobar que todas las pestañas retornan HTTP 200 y contienen su vista modular
    expected_snippets = {
        'centros': 'Red de Ambulatorios',
        'medicamentos': 'Vademécum',
        'pacientes': 'Padrón de Pacientes',
        'recipes': 'Récipes Médicos',
        'despachos': 'Dispensación FEFO',
        'maestras': 'Catálogos Maestros',
        'usuarios': 'Operadores',
        'auditoria': 'Auditoría Forense',
        'configuracion': 'Identidad Institucional',
    }

    print("\n--- Fase 1: Verificación de todas las pestañas activas (HTTP 200) ---")
    for tab, snippet in expected_snippets.items():
        status, body, _ = client.get(f'/index.php?tab={tab}')
        assert status == 200, f"Error: tab={tab} retornó HTTP {status}"
        assert snippet in body, f"Error: tab={tab} no contiene el texto esperado '{snippet}'"
        print(f"  ✓ tab={tab:<14} -> HTTP 200 | Vista modular cargada ({snippet})")

    # 2. Desactivar módulo 'despachos' temporalmente
    print("\n--- Fase 2: Prueba de Feature-Flag: Desactivando 'despachos' ---")
    try:
        set_module_flag('despachos', False)
        print("  ✓ Flag de 'despachos' configurada en false")

        # Verificar sidebar: no debe tener enlace a despachos
        status, body, _ = client.get('/index.php?tab=centros')
        assert status == 200, "Error cargando index.php?tab=centros"
        assert 'href="index.php?tab=despachos"' not in body, "El enlace a despachos aún aparece en el sidebar"
        print("  ✓ Sidebar: enlace a 'despachos' ocultado correctamente")

        # Intentar acceder a index.php?tab=despachos con follow_redirects=False
        status, body, headers = client.get('/index.php?tab=despachos', follow_redirects=False)
        location = headers.get('Location', '')
        assert status in [302, 303], f"Esperado HTTP 302 hacia tab permitida, obtenido HTTP {status}"
        assert 'tab=centros' in location or 'index.php' in location, f"Redirección inesperada: {location}"
        print(f"  ✓ Acceso directo a tab inactiva redirige de inmediato (HTTP {status} -> {location})")

        # Probar navegación con follow_redirects=True
        status, body, _ = client.get('/index.php?tab=despachos', follow_redirects=True)
        assert status == 200, "Error en redirección transparente"
        assert "Red de Ambulatorios" in body or "Ambulatorio" in body, "La redirección no aterrizó en la pestaña por defecto"
        print("  ✓ Redirección transparente aterriza en pestaña autorizada sin errores")

    finally:
        # 3. Restaurar módulo 'despachos'
        set_module_flag('despachos', True)
        print("\n--- Fase 3: Restauración de Feature-Flag: Reactivando 'despachos' ---")
        status, body, _ = client.get('/index.php?tab=despachos')
        assert status == 200, "Error cargando despachos reactivado"
        assert 'Dispensación FEFO' in body, "Error: despachos reactivado no muestra Dispensación FEFO"
        assert 'href="index.php?tab=despachos"' in body, "El enlace a despachos no reapareció en el sidebar"
        print("  ✓ Módulo 'despachos' reactivado satisfactoriamente (HTTP 200 y presente en sidebar)")

    print("\n" + "=" * 60)
    print("RESULTADO: TODAS LAS PRUEBAS MODULARES Y FEATURE-FLAGS PASARON EXITOSAMENTE (100%)")
    print("=" * 60)

if __name__ == "__main__":
    main()
