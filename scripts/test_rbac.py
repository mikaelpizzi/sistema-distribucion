#!/usr/bin/env python3
"""
Test Suite: Verificación Exhaustiva de Matriz Dinámica RBAC en SICM
Comprueba el inicio de sesión y alcance de permisos para:
1. Administrador (admin@salud.prueba.ve)
2. Farmaceuta (farmacia.llano@salud.prueba.ve)
3. Auditor Sanitario (auditor@salud.prueba.ve)
"""

import sys
import re
import urllib.request
import urllib.parse
import http.cookiejar

BASE_URL = "http://localhost:8080"

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
        req = urllib.request.Request(url, headers={"User-Agent": "SicmRBAC-TestRunner/1.0"})
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
                "User-Agent": "SicmRBAC-TestRunner/1.0",
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
        status, body, headers = self.post('/login.php', {'email': email, 'password': password}, follow_redirects=False)
        loc = headers.get('Location', '')
        if status in (302, 303) and 'index.php' in loc:
            return True
        return False

    def extract_csrf(self, html):
        m = re.search(r'name=["\']csrf_token["\']\s+value=["\']([a-f0-9]{64})["\']', html)
        return m.group(1) if m else None

def test_admin():
    print("=" * 60)
    print("TEST 1: Administrador Central (admin@salud.prueba.ve)")
    print("=" * 60)
    c = SicmClient()
    ok = c.login("admin@salud.prueba.ve", "admin123")
    assert ok, "Fallo inicio de sesión de Administrador"
    print("✓ Login exitoso como Administrador")

    # Todas las pestañas deben estar autorizadas y responder HTTP 200 sin redirigir fuera
    tabs = ['centros', 'medicamentos', 'pacientes', 'recipes', 'despachos', 'maestras', 'configuracion', 'usuarios', 'auditoria']
    for t in tabs:
        status, body, headers = c.get(f'/index.php?tab={t}', follow_redirects=False)
        assert status == 200, f"Admin no pudo acceder a tab={t} (HTTP {status})"
        print(f"  ✓ tab={t} accesible (HTTP 200)")

    # Comprobar elementos visuales de Admin
    status, body, _ = c.get('/index.php?tab=centros')
    assert "Red de Ambulatorios" in body, "Admin debe ver 'Red de Ambulatorios'"
    assert "Nuevo Ambulatorio" in body, "Admin debe ver botón 'Nuevo Ambulatorio'"
    print("  ✓ Vista centros: muestra 'Red de Ambulatorios' y botón 'Nuevo Ambulatorio'")

    status, body, _ = c.get('/index.php?tab=medicamentos')
    assert "Registrar Fármaco" in body, "Admin debe ver botón 'Registrar Fármaco'"
    print("  ✓ Vista vademécum: muestra botón 'Registrar Fármaco'")

    status, body, _ = c.get('/index.php?tab=despachos')
    assert "Cambiar Centro:" in body, "Admin debe ver selector 'Cambiar Centro:' en despachos"
    print("  ✓ Vista despachos: selector de cambio de centro disponible")

    status, body, _ = c.get('/index.php?tab=usuarios')
    assert "Nuevo Operador Sanitario" in body or "Guardar Operador" in body, "Admin debe ver formulario de usuarios"
    print("  ✓ Vista usuarios: formulario USR_GESTIONAR habilitado")

    status, body, _ = c.get('/index.php?tab=auditoria')
    assert "Exportar Eventos (CSV)" in body, "Admin debe ver botón Exportar Eventos (CSV)"
    print("  ✓ Vista auditoría: exportación CSV disponible")

def test_farmaceuta():
    print("\n" + "=" * 60)
    print("TEST 2: Farmaceuta Sectorial (farmacia.llano@salud.prueba.ve)")
    print("=" * 60)
    c = SicmClient()
    ok = c.login("farmacia.llano@salud.prueba.ve", "farmacia123")
    assert ok, "Fallo inicio de sesión de Farmaceuta"
    print("✓ Login exitoso como Farmaceuta")

    # Pestañas asistenciales autorizadas
    tabs_ok = ['centros', 'medicamentos', 'pacientes', 'recipes', 'despachos']
    for t in tabs_ok:
        status, body, headers = c.get(f'/index.php?tab={t}', follow_redirects=False)
        assert status == 200, f"Farmaceuta debería acceder a tab={t} (HTTP {status})"
        print(f"  ✓ tab={t} accesible (HTTP 200)")

    # Pestañas restringidas: deben redirigir a una pestaña autorizada (HTTP 302)
    tabs_forbidden = ['usuarios', 'maestras', 'configuracion', 'auditoria']
    for t in tabs_forbidden:
        status, body, headers = c.get(f'/index.php?tab={t}', follow_redirects=False)
        assert status in (302, 303), f"Farmaceuta NO debe acceder a tab={t} (recibió {status})"
        loc = headers.get('Location', '')
        print(f"  ✓ tab={t} bloqueada con redirección a: {loc}")

    # Delimitación de jurisdicción y botones
    status, body, _ = c.get('/index.php?tab=centros')
    assert "Mi Ambulatorio" in body, "Farmaceuta debe ver 'Mi Ambulatorio'"
    assert "Nuevo Ambulatorio" not in body, "Farmaceuta NO debe ver botón 'Nuevo Ambulatorio'"
    print("  ✓ Vista centros: muestra 'Mi Ambulatorio' y botón 'Nuevo Ambulatorio' oculto")

    status, body, _ = c.get('/index.php?tab=medicamentos')
    assert "Registrar Fármaco" not in body, "Farmaceuta NO debe ver botón 'Registrar Fármaco'"
    print("  ✓ Vista vademécum: botón de alta oculto (solo lectura/consulta vademécum)")

    status, body, _ = c.get('/index.php?tab=despachos')
    assert "Cambiar Centro:" not in body, "Farmaceuta NO debe poder conmutar centro en despachos"
    assert "Jurisdicción Asignada a Farmaceuta" in body, "Debe mostrar badge de jurisdicción asignada"
    print("  ✓ Vista despachos: restringido a su ambulatorio fijo asignado")

    status, body, _ = c.get('/index.php?tab=pacientes')
    assert "Registrar Paciente" in body, "Farmaceuta debe poder registrar pacientes (PACIENTES_GESTIONAR)"
    print("  ✓ Vista pacientes: botón 'Registrar Paciente' presente")

    # Escudo Backend en procesar.php contra operaciones no autorizadas
    csrf = c.extract_csrf(body)
    assert csrf, "No se pudo extraer token CSRF"
    status, body, headers = c.post('/procesar.php', {
        'csrf_token': csrf,
        'accion': 'guardar_centro',
        'nombre': 'Intento Ilegal Ambulatorio'
    }, follow_redirects=False)
    loc = headers.get('Location', '')
    assert 'error=' in loc and 'Acceso+denegado' in loc, f"Backend no bloqueó guardar_centro a farmaceuta: {loc}"
    print("  ✓ Escudo Backend: procesar.php bloqueó 'guardar_centro' con ACCESO_DENEGADO")

def test_auditor():
    print("\n" + "=" * 60)
    print("TEST 3: Auditor Sanitario (auditor@salud.prueba.ve)")
    print("=" * 60)
    c = SicmClient()
    ok = c.login("auditor@salud.prueba.ve", "auditor123")
    assert ok, "Fallo inicio de sesión de Auditor"
    print("✓ Login exitoso como Auditor Sanitario")

    # Pestañas autorizadas para Auditor:
    # centros, medicamentos, pacientes, recipes, despachos, usuarios (USR_VER), auditoria (AUDIT_VER)
    tabs_ok = ['centros', 'medicamentos', 'pacientes', 'recipes', 'despachos', 'usuarios', 'auditoria']
    for t in tabs_ok:
        status, body, headers = c.get(f'/index.php?tab={t}', follow_redirects=False)
        assert status == 200, f"Auditor debería acceder a tab={t} (HTTP {status})"
        print(f"  ✓ tab={t} accesible (HTTP 200)")

    # Pestañas prohibidas para Auditor:
    # maestras (MAESTRAS_GESTIONAR), configuracion (CONFIG_SISTEMA)
    tabs_forbidden = ['maestras', 'configuracion']
    for t in tabs_forbidden:
        status, body, headers = c.get(f'/index.php?tab={t}', follow_redirects=False)
        assert status in (302, 303), f"Auditor NO debe acceder a tab={t} (recibió {status})"
        loc = headers.get('Location', '')
        print(f"  ✓ tab={t} bloqueada con redirección a: {loc}")

    # Jurisdicción de Auditor: CENTROS_VER_GLOBAL
    status, body, _ = c.get('/index.php?tab=centros')
    assert "Red de Ambulatorios" in body, "Auditor debe ver 'Red de Ambulatorios' (CENTROS_VER_GLOBAL)"
    assert "Nuevo Ambulatorio" not in body, "Auditor NO debe ver botón de creación de ambulatorio"
    print("  ✓ Vista centros: supervisión global de red activa y botones de mutación ocultos")

    status, body, _ = c.get('/index.php?tab=despachos')
    assert "Cambiar Centro:" in body, "Auditor debe poder auditar cualquier ambulatorio (Cambiar Centro)"
    assert "Modo Fiscalización / Solo Lectura" in body, "Auditor debe ver badge 'Modo Fiscalización / Solo Lectura' en despachos"
    print("  ✓ Vista despachos: badge 'Modo Fiscalización / Solo Lectura' visible y cambio de centro habilitado")

    status, body, _ = c.get('/index.php?tab=usuarios')
    assert "Padrón de Operadores y Seguridad" in body, "Auditor debe poder auditar la nómina de usuarios"
    assert "Nuevo Operador Sanitario" not in body, "Auditor NO debe ver formulario para crear usuarios"
    print("  ✓ Vista usuarios: nómina visible en modo solo lectura (USR_VER)")

    status, body, _ = c.get('/index.php?tab=auditoria')
    assert "Exportar Eventos (CSV)" in body, "Auditor debe ver botón Exportar Eventos (AUDIT_EXPORTAR)"
    print("  ✓ Vista auditoría: exportación forense CSV autorizada")

    # Escudo Backend en procesar.php: auditor no puede dispensar ni crear pacientes
    status, body_centros, _ = c.get('/index.php?tab=centros')
    csrf = c.extract_csrf(body_centros)
    # Si no hay formulario en centros para auditor, obtenerlo de despachos con modal o cualquier form
    if not csrf:
        # Obtener un token CSRF válido desde el pie (si hay form) o solicitando una página con CSRF
        m = re.search(r'csrf_token["\']\s+value=["\']([a-f0-9]{64})["\']', body_centros)
        csrf = m.group(1) if m else None
    if not csrf:
        # En caso de que no haya forms visibles para auditor, consultar sesión PHP directamente
        status, body_disp, _ = c.get('/index.php?tab=despachos&q_paciente=14285714')
        csrf = c.extract_csrf(body_disp)
    assert csrf, "No se pudo extraer token CSRF"
    status, body, headers = c.post('/procesar.php', {
        'csrf_token': csrf,
        'accion': 'guardar_paciente',
        'cedula': '99999999',
        'nombre_completo': 'Paciente Ilegal'
    }, follow_redirects=False)
    loc = headers.get('Location', '')
    assert 'error=' in loc and 'Acceso+denegado' in loc, f"Backend no bloqueó guardar_paciente a auditor: {loc}"
    print("  ✓ Escudo Backend: procesar.php bloqueó 'guardar_paciente' a auditor con ACCESO_DENEGADO")

if __name__ == '__main__':
    print("INICIANDO SUITE DE PRUEBAS RBAC (SICM)...\n")
    try:
        test_admin()
        test_farmaceuta()
        test_auditor()
        print("\n" + "=" * 60)
        print("RESULTADO GLOBAL: TODAS LAS PRUEBAS RBAC PASARON EXITOSAMENTE (100%)")
        print("=" * 60)
    except AssertionError as e:
        print(f"\n❌ ERROR DE VERIFICACIÓN RBAC: {e}", file=sys.stderr)
        sys.exit(1)
    except Exception as e:
        print(f"\n❌ EXCEPCIÓN INESPERADA: {e}", file=sys.stderr)
        sys.exit(1)
