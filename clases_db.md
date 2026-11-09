DB 02/11/2026
Esta es la última clase que hubo, él profesor nos insta a continuar el proyecto: Configurar permisos para otros roles (esa es otra tabla maestra?) a partir de un rol que pueda hacerlo, es decir, son como los roles que estemos haciendo más la capacidad de parametrización (se podría llamar roles custom o adaptados a la empresa sin que ellos necesiten nuestra ayuda)



A otras personas al normalizar tablas le salieron muchas otras tablas, el conteo total a más de 20 por ejemplo



Que todo esté adaptable, hasta el nombre



Sacar a tablas nuevas todo (por ejemplo si un usuario tiene varios teléfonos, esos varios van a una nueva tabla)



Triggers, función, procedimientos, preguntarle a la db



El profesor va a crear seeders para romper la tabla

Pruebas unitarias PHP

20% Esquema DB: Roles, usuarios, triggers, funciones, procedimientos almacenados, tablas virtuales, normalizado, usando llaves foráneas, constraints, que esté todo bien esquematizado.

10% Proyecto completo

10% Entregables



Account

Acc_num, amount



Create trigger tgr_sum before insert on account for each row

Set @sum=@sum+New.amount;



Propósitos: Mínimos de stock definido en una tienda, se puede hacer un before o after para chequear límites de stock y pedir restock automaticamente, o inserta el log

No se debe meter mucho negocio dentro de un trigger porque se pone pesada o bloquea la tabla.

Cuidado con no anidar triggers.

La db tampoco puede manejarlo todo, ahí se apoyaría del backend.

Cuidado con muchos triggers y a todas las tablas.



Generación de ordenes vs sistema de alerta.



Documentación precisa.

Condiciones de carrera

Aws y dymanodb, crud lambda y dynamodb. Cognito. Ux fastidiosa con código -> Enlace con tiempo de expiración y código cifrado. Se puede hacer una activación incluído en local.

Mailhog o docker con correo. Explicar el funcionamiento suma puntos.



La persona activando también puede pedir validación (mi caso), porque debe pedir un correo válido





El usuario debe poder autogestionarse

Olvidé mi contraseña debe pedir el correo registrado

Autenticación de 2 pasos

Pedir documentos de identificación

Cifrado vs encriptado

Sistema de notificaciones básico

Notificaciones en usuario y en admin

Alguien modificó tu usuario, etc

Sistema de estadísticas

Que el log muestre cambios completos each row si se modifican cosas de golpe

Logs útiles, todo junto a tiempo real puede ser un desastre

Reventar db: correo no unique

Guardar huecos y por qué ocurrieron en auditoria forense. Igual errores e intentos de romper.

Gestionar crecimiento enorme de logs. Identificar que cambió para no guardar todo el registro. Guardar SOLO lo necesario. Tabla campo valor, quien lo hizo, cuando, etc

Es muy delicado meterse con el identificador de la tabla, nunca hacer.

Es parte los logs de db que a nivel de backend y frontend. Con qué se conectaron, puedes detectar el hueco, por cuál conexión hicieron esa acción.



Traza completa de una acción, quién, donde, cuándo lo hizo.

Gráfico de qué fue lo que más se vendió. 

DB 26/10/2026:
¿Las vistas son consideradas tanlas virtuales?
Paginación
Proyecto que se exponga en público como producto
Tovarsat: Si yo pago en la mañana, hay un job que no ve los que pagaron en la mañana, no saca de la lista o no está consultando
Pasarela de pago
2 maquinas, cuando escriba en una que escriba en otra (backup server en tiempo real)
Lógico vs físico 
Se guardan páginas que tienen encabezados, mientras menos columnas más registros y viceversa, por eso normalizar es importante (hasta 3ra forma), para poder brindar rapidez. Cada vez que se busca un dato lo hace por página, hay un espacio para inserciones nueva, páginas de índices.

Tamaños de la página: POSTGRESQL 8k, MySQL 16K, SQLServer 8k. Información rápida: Normalicen. 
En este proyecto queremos mucha información pero no redundante (a menos que sea necesario)

MySQL tiene md5 o sha1

Más datos, más tablas que estén normalizadas

Unificar: email, password, created_at, updated_at, deleted_at (si el usuario ll va a usar, sino gasta espacio innecesario)

Razón de extrema prioridad para agregar más columnas
1 solo email, 1 solo teléfono, 1 sola dirección, eso es cosa que falta, no podemos limitar así (y tampoco aumentar las columnas)
Country code y normalizar en backend es una opción
Ejemplo: Tipo de mensajería.

No hay que tocar el esquema en un período largo (no necesitar cambio en 6 meses) -> Negocio muy bien definido en un principio
Ejemplo: Agregar "pendiente de pago", es mejor que sea un sistema configurable y parametrizable, panel de control muy potente, tablas maestras, activar o desactivar ciertos datos, mejor UX. Si te llaman que lo hagan por nuevas features, no por una update. Que el cliente sea completamente autónomo "oye, yo lo puedo configurar", poder decir las bondades del sistema.

Miércoles: CRUD de al menos una tabla y su interfaz de usuario y decidir si se puede registrar solo o no.

Demos: Tener en cuenta roles (y qué hace cada rol) y seguridad (cuidado actualmente con PHP)
Los mensajes deben ser personalizables (¿Qué mensaje le quiere mostrar al cliente si pasa x?)
Responsive, versión móvil.
Todo debe ser trackeable, logs.
Guardar imagen dentro de DB (blob), pero se hace muy pesada. Ejemplo: logo vs logo_url y apunta a donde está (o debería) el archivo y el usuario no toca rutas.
Los datos raros (u ocasionales), se tiran en otra tabla.
Campos con valores promedios, cuando yo modifico algún número de ese promedio, este se afecte ("recalcular promedio", cron job de recalcular -> Ideas horribles). Debe ser a tiempo real y completamente real, sin matar a la CPU.

Foreign keys uniformes, todo lo que pueda ser uniforme, que lo sea.
El sistema debe estar corriendo.
Queremos saber cuándo fue creado, actualizó, quién lo hizo, ¿Por lo menos el último usuario?

Nada quemado (por ejemplo del sector venezolano, la parte fiscal), no permitan que vayan a escribir j,J,vV,venezolano,juridico. No permitir errores de usuario que sea dañinos al sistema, menos quemar. Parametrizado y normalizado, en base de datos, no en backend y menos en frontend.

Data de los que visitaron el sistema.
¿Dibujar a través de data?
Tabla colores si hace falta colores de producto.
Gestor de citas, barberías, talleres, un link
Neurólogo tiene un sistema: Acuity: Scheduling.

Gestión de carrito de compras.
Diseño gráfico: Buena UX, busca a alguien o IA.

Sistema de logs es obligatorio: Usuario, fecha, acción.

Manejo de múltiples idiomas: Varios JSON donde varíen el valor y no la clave?

Gestión de primera vez, inicio del sistema por primera vez. ¿Mucha info? Pide lo mínimo y el resto después.

Video de máximo 10 minutos, vendiendo el producto, máximo domingo 11:59pm.

Cláse 1 (tareas entregadas) Sql proyecto
Ahora: Frontend+ DB
No interfaces de clientes
Todo en papel por ahora
La interacción de la base de datos que no dependa del framework

Solo DB SQL 
¿Dónde lo manejas?
Manejador de base de datos
MySQL, Postgres, MariaDB, SQLite, Oracle, SQLserver

DynamoDB de AWS

¿Cómo crear los esquemas? Formas normales

Ofrecer datos (json), guardar datos, controlador, tablas sin ningún tipo de validación guarda datos y ya

Diseño de validación 
¿No hay escudo backend, frontend? Qué esté en la DB
Cuidarse las espaldas
Manejar seguridad de DB, por ejemplo de tabla y manejador

Crear esquemas sin IA, para aprender

Aplicación: PHP puro, Javascript puro, aquí puedes abrir una consola y ver que está pasando
ORM, elocuent, no queremos, sino hablar directamente con la DB
Nada de librerías, solo para conectarse
Bootstrap, Jquery (JavaScript puro mejor) para vistocida
Se expondrá la app al público a ver si es verdad que aprendimos 

2 exámenes -> 60%
Proyecto -> 40%

Proguntas que siempre salen en el examen: Bd, datos, información 
Los exámenes son escribir código SQL que resuelva lo planteado y justificar por qué es la solución (temático de La Liga)

Integración de pasarelas de pago

Pensar (incluído casos de borde): ¿Cómo está trabajando esa base de datos? Ejemplo: Cajero
Retraso: Interés y/o falta de mantenimiento
Triggers (y más términos), qué ocurre cuando la db falla

Escenarios de borde para el proyecto, por ahí van las preguntas de defensa
Github no es necesario 

El proyecto se presenta en la laptop del profesor
Siempre pide correo por seguridad y prueba en tu trabajo

Profemogo@gmail.com
El énfasis es web app con db

Podemos usar Docker (por materia), o en su defecto Virtualbox, VMWare, Promox
Recomendar no embasurar máquinas

Ficha bibliográfica qué es

Postgres es más robusto que MySQL
Índice, qué pasa si no hay (consultas de más de un minuto)

Guardar, eliminar, buscar en la bd (y parecidos)  es lo que más se evalúa 

Escribir en una hoja de examen un sistema de biblioteca manual, cómo funciona.

El señor Pedro ha ganado la loteria y no quiere irse a la ciudad, ha comprado más de 2000 libros y está construyendo una biblioteca, no tiene PC, solo dispone de fichas y cuadernos, cómo hace que funcione esta biblioteca, el pueblo tiene más de 200hab. Qué ocurre cuando alguien va a buscar un libro, si es único, qué se hace, es rápido,, se anota en algún lado, si hay 2 versiones dellibro, cómo se identifica, qué pasa si no vuelve, como se organizan esos 2000 libros, cómo hago para no revisar 2000 libros para buscar uno en específico

En un buen diseño No hay que hacer pensar ni dependencia del creador

Contexto extra:
Evitar flash con toolkita y map
Con php puedes manipular imágenes 

¿Dónde guardar un archivo tanto físico como bd? Cómo era antes?

La tarea es el próximo miércoles

BD: Clase 2 (tareas entregadas)
PROYECTO:
MySQL (no ORM, ustedes deberían poder crearla)
PHP puro (no framework, se une con MySQL y viceversa)
JS puro (validar formulario, no va para PHP)
CSS+Bootstrap

Imagina que puedes ofrecer con el sistema, con tranquilidad
Índices y árboles binarios

Miércoles 29/07/26
Tema Proyecto, qué esperamos de él

Entrevista con quién lo usa o con quién contrata, a su vez una espiral de entrevistas, no asumir nada.

Imagina un ejemplo: La estructura donde se guardan los datos de una biblioteca, si te vas de una vez, vas a asumir por no entrevistar, sin encontrar casos de uso

Excel puede relacionar (appSheet), haciendo una db rapidísima para resolver rápido 

Análisis para el modelado de datos es importante para definir como se estructurará y cómo va a escalar la base de datos. Por ejemplo, no saber que los libros tenían categorías, luego que no sabía que tenían ediciones, luego dimensiones, colores... Cuándo se creó este registro (de forma automática, la columna "creada" que no se toque en ningún lado) en el sistema es diferente a guardar cuando prestas un libro. Para evitar esto hay que entrevistar. Qué quieres que haga el sistema, como un niño de 5 años

Si una consulta va a tardar mucho, no sirve
Si es algo muy grande, una forma de manejarlo es pasarlo a segundo plano con una tarea programada: Enviar al correo.

¿Cómo crear tareas programadas en Windows? Cron

Vamos a tener que crear un script para crear un daemon para crear un respaldo cada cierto tiempo

Sniffer, ver paquetes no cifrados de extremo a extremo (redes de computadores), monitorear redes

Libro:
 id
 titulo
 anio

Visualízalo como un excel (id, titulo, anio, 1,2,3)
Nuestra llave primaria es id (la idea es que no cambie?), es autoincrement (investigar que es llave primaria, y uuid)

No puedes modificar el valor de la llave primaria para que hayan repetidos, da error.
El autoincrement puedes modificarlo a que valor comienza, sabe que viene después. Si viene el 4 pero modificamos antes para que haya un 4 antes, da un error. Se trata validando y mejor no modificando llaves primarias

Definir usuarios y roles: Qué puede hacer cada usuario y cada rol - protegerse, no des el usuario root. Validen el usuario con el que se está conectando a la base de datos, el 80% de las veces es root y es delicado

Esto sirve para análisis forense de datos, ¿Mi sistema hace eso o no? Guardar quién borró, cuando, de dónde y un por qué si es posible
Bases de datos vs manejadores de bases de datos

La experiencia de recuperación de datos y cómo protegerse. Evita sufrir con una buena estructura de datos

Soft delete -> deleted-at vs hard delete vs crear una tabla papelera (qué modelos están trabajando con papelera, a partir de ahí creo interfaz con backend y frontend, sin duplicar data, sin crear una nueva columna)

Si una tabla crece a los lados, no está bien modelada, debe crecer en registros, no en columnas, si no es así no está bien pensada

Postgres serial, primary keys no acepte nulos que sean enteros
Estilo de programación 

Ver la proyección de las tablas, cómo hacer para optimizar una tabla (por ejemplo una tabla pesada de 5gb), particionado de tablas

título define un string 
anio define una fecha

A partir de aquí hay que validar los tipos de datos

La BD debe tener capacidad de adaptarse a lo moderno como JSON para APIs

Es recomendable no guardar listas (pero por qué si Json?)

API-> DynamoDB

Para el nombre de los modelos se usa singular (la tabla es la definición del modelo), caso de id ue sea número de cédula y se prestó para fraude
Valores que cambien en el futuro no como promary key

Persona
 id
 nombre
 apellido
 cedula_identidad
 genero varchar(256)

1 Pedro Pérez 12 masculino 
2 María Pérez 13 femenino
3 Ana Pérez 14 femenino

Normalizar: Femenino, mujer, femenino.
Se puede modificar el formulario de manera local  y el servidor acepta peticiones de todos lados y envías muchas peticiones, e hizo un loop vía Curl o Postman metiendo en un arreglo los posibles valores: Masculino, varón, padrote, femenino, FEMENINO, cualquier cosa. Y si piden un reporte de todos los masculinos del sistema.

Se puede formalizar en el frontend, pero si lo rompen? Las relaciones (también masculino y femenino podrían ser números y ahorrar espacio)

Registros php?

Proyecto que transmita algo por lo menos
Tiempo de expiración de un script y uso de cron para no depender del navegador
Y si el cliente dice que quiere nuevas cosas que necesiten expandir horizontalmente? Nuevas entidades

BD-3: (tareas y examen ya fueron hechas) Proyecto 
IA, usarla lo estrictamente necesario 
Creación de producto
Tablas en plural, convenciones:
Nombre de BD:
 Claros, sin espacios, ni caracteres especiales, descriptivos, snake_case

No empeñarse en productos complejos, sino simple, DRY

(Materia sistemas de información. Modelo que no dependa de IA ni internet, instalar modelos en las consolas), diagrama de clases, UML
Tabla:
 Plural (evita palabras reservadas), snake case, sin espacios, descriptivo/claro (don't make me think).

Ingeniería de datos
Importante para el examen (buscar diferentes definiciones): Dato, información, base de datos
Dato: Unidad atómica de lo que es información 

Información (tiene datos): Una colección de datos con coherencia nos da información

Base de datos (tiene información): Es un conjunto organizado de información que se almacena y gestiona de manera eficiente y coherente

4 sentencias?

create database seccion_b2026;
palabra_reservada espacio palabra_reservada espacio nombre delimitador
¿Qué pasa si no hay delimitador?

La idea es poder usar consola y papel para modelar nuestras bases de datos

En MySQL: use seccion_b2026;

Conectarse a la base de datos: Si no configuras la variable de entorno entonces te va a obligar a poner la ruta completa?

BD vs manejador de BD

ssh vs ftp

Limitar quién y dónde se conectan a la base de datos "este usuario solo se podrá conectar desde esa máquina en específico", no todos manejan la seguridad, somos los garantes y responsables de los datos, de su organización y seguridad (corromper, perderse)

Cron
Tareas programadas
Para respaldo de bases de datos, crear daemon, es cubrirse las espaldas
Luego usaremos mysqldump

Tabla:
 usuarios
  id (pk) auto_increment (análisis forense, de donde se conecta, cuando, su usuario, qué está haciendo, todo eso se puede guardar, se puede monitorear hasta el puntero, hay que estar preparado para eso)
  nombre_usuario (unique)
  clave 
  email (unique)
  activo (boolean)

La idea es modelar bases de datos como misión de la materia, poderlo crear en papel (saber UML)

Inserción: 
insert into usuarios (nombre_usuario, clave, email, activo), values('pepe','123',pepe@gmail.com,true);

Pentium 4, 192mb, qué SO se puede instalar? Antix

.cnf, .config, .env, se puede decir en qué punto comienza el auto incremento 

Se puede cambiar la clave de root tocando la tabla, puedes hacer que comience en 1 de nuevo y se sobreescriba, qué costo a nivel de cpu tienen los respaldos incrementales 

Correo + grabación cuando la base de datos ya funcione en local y luego ir a producción 

1 pepe 123 pepe@gmail.com true

Inicialmente no distingue entre mayúsculas y minúsculas 

¿Y si no pongo las comillas? Y si pongo el orden distinto (clave, email), y si no pongo el ;?, si no pongo paréntesis, si no escribo los campos sino solo los valores a insertar?

SMA sistemas manejadores de archivos

Update:

update usuarios set
 clave='12345';
 activo=false; // Estamos cambiando toda la tabla, es un query mortal porque no hay where.

Select:

select * from usuarios; // Lectura secuencial, * se trae todas las columnas, pero tal vez no las necesitamos todas, se carga todo en memoria (posiblemente hackeable, ralentiza la conexión), se puede usar where aquí.

Evitar repetir consultas con vistas

Delete:

delete from usuarios; // Borra toda la tabla, (borra registros, no columnas)

truncate

drop  database seccion_b2026; // Borra la DB

Precavido de las cosas que se pueden ejecutar, permisos
Revisa archivos de configuración del trabajo y qué usuarios estoy usando. No instalen modelos con usuarios con superpoderes. Roles, permisos, usuarios.

Miércoles: Proyecto: Qué hace y una pequeña descripción, como si tuviese 5 años (medianoche)

Para el próximo miércoles 5 de agosto: Hacer a un script PHP corriendo y conectándose a Mysql a una db
No librerías, ni ORM, solo la librería de conexión (Windows, directamente PHP>8, MySQL o MariaDB, apache (instalar por separado para administrar servidor web, pero si se complica hay otras opciones)
Empaquetados
 Xamp, Wamp, Lamp. Traen phpmyadmin y no la vamos a usar, solo consola (deberíamos estar en capacidad de hacer un phpmyadmin, ej, reactmyadmin o nodejsmyadmin)

localhost, ahí se puede ser organizado: carpetas css, js, páginas (tal vez controladores,etc), estamos más o menos creando un pequeño framework? A lo último se entenderá completamente lo que hace un ORM.

Sentencia Join
Docker: Para mysql, otro para php+apache2, que interactúen entre ellos

El examen tendrá un motivo, por ejemplo, "liga española"

BD-4: (tareas y examen hechos) SMA (Sistemas manejadores de archivos)
Encima de eso se montan los SMBD (Sistemas manejadores de bases de datos, nosotros podríamos crear ambos)
Encima está MySQL, Postgres, Dynamo DB... etc organizan relaciones ¿Schema?
SQLServer
Excepto Oracle 

A nivel de SO: ext4, fat32, NTFS
¿Qué es el servidor? Qué haces si se cae?
Frontend-backend separado vs junto

Base de datos en servidor a parte (servidor de datos) vs junto. La BD puede afectar todo el servidor. ¿Si se quema el servidor? Qué tan frecuentes eran los respaldos. Piensa como arquitecto de datos

SMA eficiente (es lo mejor, opción es linux)
Entregar productos que no dependan de nosotros completamente, evitar demandas, estás pagando liciencias por DBs y SOs

insert, *update, *delete, select

delete from usuarios;
   where id=2;

Joins (consultas cruzadas, ¿Cómo se hacía antes? Cruzar tablas)

Modelos entidad-relación

¿Qué es un modelo (con respecto a BD, modelo de datos)? Es la abstracción de un objeto para su representación. Atributos de objetos y cómo interactúan entre ellos (metadatos cuando hay pequeñas variaciones?)
Solo hace falta un modelo para todos los tipos de carpetas
"La carpeta está en el pupitre"-> relación 
Hay relaciones uno a uno. Atributo unique (por ejemplo CI o número de identificación)
Uno a muchos. Muchos a muchos
Las relaciones se indican con una palabra clave (lo haremos de manera estándar)
En vez de "azul" "azules" -> normalizamos con una relación con una nueva tabla colores
Reglas básicas de normalización, hay varias 

¿Qué tantas tablas puedo hacer para no tener datos repetidos, manipulables, que generen errores por susceptibilidad?

¿Cuáles son los atributos del modelo y sus distintos tipos, incluyendo JSON (recomendado para metadatos)?
Primera forma normal 1FN, cuándo se inventó y cuándo el JSON?
¿Una entidad?

Cliente que dibuja un monstruo vs arquitecto 
Arquitecto/analista podrá hacer 3 preguntas (del dibujo), y hacer algo con ese monstruo 
El cliente asume y el analista asume. La importancia de la entrevista 
Validación según el cliente y construcción del modelo

No hay que asumir ni imaginar. ¿Cuáles son los actores de esa DB?
¿Que quiero que haga, quién lo va a usar, cuáles son sus limitaciones, cual es el alcance?
Clínica completa vs oftalmología 
Modelos dinámicos

Título, descripción, alcance, que hace, quién lo va a usar, que datos guardar, de cada modelo/entidad posible qué se va a crear (versión inicial, puede ir cambiando)? Empezar a modelar el sistema
Red,nube,local,con o sin registro, interactores, quienes son los distintos actores del sistema, qué, para qué, por qué, quién va a tener ese sistema.
No responder (es para después):
Permisologia en BD o en framework/backend, va a tener que iniciar, el usuario tendrá un rol y se asignará por db? Roles, usuarios, vistas
Ej: puede crear empresas, proyectos, eventos
Dispositivo donde se usará
Entregar escrito a mano

BD-5: (tareas y examen ya hechos)
Life: No usar WordPress con base de datos principal

Las 3 formas normales: 1FN, 2FN, 3FN. Compatibilidad de estas formas con JSON 

Llave foránea hace referencia a llave primaria, hay que decirle que es llave foránea para atar esas dependencias

Construir foreign key, borrado en cascada, qué borrados permite la db, borrado estricto vs otros tipos, ¿Cuáles?

La primera forma normal dice que eliminemos listas.

De la primera tabla el registro 1: 1 Pepe 123,555,678
2 Maria 333,999,666

Pasa a ser en otra tabla teléfonos

1 1 123
2 1 555
3 1 678
4 2 333
5 2 999
6 2 666

¿Qué es metadato?
Podría haber un JSON en la columna teléfono y habría que extraerlo

Segunda forma normal: Debe cumplir la primera forma + todos los campos que no son clave dependen completamente de la llave primaria (llave compuesta completa vs incompleta)

Generar respaldos con mysqldump, crear respaldos periódicos 
¿Qué es appsheet?
Hay varias formas para todo

Llave compuesta (¿Primary key compuesta?)

Evitar inconsistencias, repetir información -> normalizar (por ejemplo si se consiguen datos repetidos en una columna), ejemplo: tablas alumno_curso, alumnos, cursos, alumnos_cursos. Where curso_id=1;

Keep it simple
Nunca depender de registro, frontend, backend
Hay que entender esto porque después vienen tablas cruzadas y cosas más complejas
Escaneo secuencial de la tabla si no existen índices, cuesta bastante tiempo, los índices son árboles binarios 
En el estudio se determina si se van por ejemplo a usar muchas búsquedas de un tipo, y los índices hay que actualizarlos 

Tercera forma normal: Las dos primeras + no debemos tener campos con dependencias transitivas (ejemplos de algo de columnas con llave primaria). Evitar repetir datos e inconsistencias. RRHH, rrhh, Recursos Humanos. Lo que es posible colocar en la DB si no se normaliza. Somos el último escudo en la protección de información. Tabla empleados: id nombre departamento_id nombre_departamento. Creamos una nueva tabla departamentos. El usuario no es gente, hay cosas que solo se pueden resolver administrativamente

Las 3 formas normales parece que consisten en crear nuevas tablas, parece que no hay costo o en muchos casos es mejor crear nuevas tablas, aunque las nuevas sean muy simples.

Consultas cruzadas, a veces son obligatorias
Cubrirse las espaldas siempre
Adelantar CRUD de una tabla
Esquema avanzado del proyecto 
Examen:
Normalización 
Create tablas
Foreign keys
Dato, información, base de datos
El ; 
Drop vs delete database

Php-> backend
JavaScript -> frontend (prohibido JQuery)
Css (+bootstrap opcional) -> frontend

BD-6 Openspec
Claude md
Decisions.md
Trazas web
Joins-subquerys
Esquema inicial de bases de datos, qué significa cada columna, cada campo, usar IA + criterio 

Not null default
Enum ejemplo masculino -femenino, pero no es recomendado, es mejor la configuración + soft delete
Wizard: Un paso a paso de inicialización del sistema
No quemar tablas con enums

Crear, crear tablar, crud y buscar/seleccionar, clausulas WHERE, normalización de la 1 a la 3, relaciones tablas (1a1, 1amuchos) llave primaria, crear 2 tablas, crear tabla una con llave primaria y otra con llave foránea que apunta a la primera 

Drop vs delete base de datos

Para el proyecto manejo de usuario y trazabilidad de los usuarios del sistema (que es diferente de los usuarios de la db), roles en fr, entregado y expuesto en 10min

Triggers, noseque almacenado, funciones y roles

Expo: que hace sistema, las tablas principales, dominio del tema, pulcritud

BD 14/10/26
Índices, Join
¿Cómo funcionan los índices compuestos? (Explicar la regla, de izquierda a derecha)
2) Árboles binarios 
¿Si no hubiese índice? Cómo busco algún tema en un libro? Paso página por página. En una tabla sería hacer un Full Scan. Los índices deben cumplir con ciertas normas/recomendaciones, puede ser beneficioso o perjudicial

Create indez on Tabla(columna1,columna2...);

El primary key ya es un Indice, los unique también son índice 
¿Cuándo se crea el árbol? Y se actualiza cada vez qué...? Aquí estamos hablando de modificaciones de update, delete, insert. Estos actualizan el índice y aumentan el tiempo de operación (actualizar/ordenar el nuevo árbol y es una operación que ocupa tiempo)
¿Dónde está el cuello de botella?
Tabla: Tipo, id, log, ip, agent, client; -> demasiados logs con árbol que se actualiza mucho
Evaluar cardinalidad y selectividad
Drop INDEX
Ante congestión->¿Qué es lo que quiero?
Saber cuándo se lee mucho, se modifica mucho, los índices más necesarios, o pasa todo junto 
Renglones de tabla (no necesario en este momento sino al final de la materia)

Cardinalidad (registros diferentes?) y selectividad (diferentes entre total de registros?)

Cardinalidad: Select count(distinct, email) from usuarios;


Selectividad: % = (cardenalidad/count(*))x100;
Mientras más próximo al 100% es más postulante a ser indexado (criterio del 60% cuando ya es mucha data, si el server es potente y 1000 o 2000 registros no le crees índices)

¿Qué pasa al hacer insert, delete, update? -> objetivo de aprender, afectan los índices y pueden ralentizar 
Enums ventajas y desventajas 
Email unique por backend, qué significa, hay una lectura secuencial ahí 
Manejar problema de emails repetidos con o sin dats
¿Las llaves foráneas hay que indexarlas?
Saber el comportamiento del usuario, ¿Con logs?
¿Cuántos select tengo?

Select id from Personas where nombre like '%Pedro';

Personas
id, nombre
1, Pedro
2, Ana Pedro
3, Pedro María 

¿Por qué es importante esto en un Indice? Se usa el like si el comodín está al final, y cambia si no, pero qué significa eso.

(Miércoles 28: Tener esquema del proyecto hecho, carpetas, como está organizado el PHP + mostrando algo en frontend y como está organizado y el esquema SQL que funcione (pudiendo generar el .zip entregable), con seeders. Es la estructura lista (cómo va a quedar), no el proyecto como tal, con un CRUD de una tabla vamos bien (fullstack)), hacerlo a pie, sin IA
Discusión:
Exponer el proyecto usando visualgo
Entregar comprimible con todo
El password tiene que estar cifrado en db.

BD 21/10/26
From
Where
Group by
Having
Select
Order by
Select * from pais where id = 1 order by id asc;

Tickets:
#1 El CEO "quiero el ranking cada mañana sin escribir SQL"
#2 RRHH "La regla de niveles está repetida rm 47 consultas. Cuando cambia, explota todo"

Jugadores
id,nick,pais
1,osito,venezuela
2,pelusa,México 
3,sexy,Argentina

Partidas
id,jugador_id,juego_id,puntos
1,1,2,200
2,2,2,620
3,2,2,800
4,1,2,100 

Las vistas son una consulta, ya sea con join, querys anidados. No se puede guardar información en las vistas (no por defecto), esta vista va a tener x campos, para backend es una tabla, para bd más que eso.
No es materializada (en POSTGRES si son, pero no es buena práctica).
¿Por qué la vista?

usuario->SO
sudo->root

En SMBD->usuario o root, es otro ecosistema a parte de los usuarios del SO, aunque tengan los mismos nombres.

Ciertos o todos los permisos sobre las tablas o sobre las vistas, limitar la base de datos, la vista del SMBD es diferente a la vista de frontend. Modelo,vista,controlador

"Las migraciones son muy humanas", es una capa de abstracción con los modelos, las vistas (de la base de datos) son la capa de abstracción de la tabla para el backend. Se pueden ocultar los campos, muchas cosas.

Tablas virtuales.

Ranking para: pelusa,1420
osito,300 con SQL

Select j.nick, sum(p.puntos) as total from jugadores j inner join partidas p oo j.id=p.jugador_id group by j.nick order by total desc;

nick,total
pelusa,1420
osito,300

Triggers
Luego: create view vw_ranking as ...Todo el SQL

SELECT * FFOM mw_ranking; 
Nosotros delimitamos lo que aparecerá en esa lista, es una capa de abstracción hacia la tabla.

¿Puedo guardar en una lista? Si es materializada, no es recomendable, ¿Por qué?
Vete por lo sencillo, la definición de vista

¿Qué pasa si cambio de nick a username? Una query fallaría? Imagina un cambio en n tablas, si cambia toda la estructura solo cambia la estructura de la vista, una buena parametrización.
Con esto está listo el ticket #1.

Funciones
sum(),count(),avg(),max(),min(),now();
No necesariamente parámetro de entrada pero si que retorne algo.

Determinístico en el ámbito de las funciones. Cuando no hay una transformación de datos como tal, sino una toma de decisiones, no va a haber una modificación o transformación. Aunque el parámetro de entrada cambia, adentro será siempre lo mismo y es por ejemplo el caso calculadora.
¿Rafael Rangel?
≥1000 Leyenda
≥500<1000 pro
<500 novato

Delimiter $$ creater Function nivel_jugadores(puntos int)
return varchar(200)
Deterministic
Begin
if puntos ≥ 1000 then return 'Leyenda';
else if puntos ≥ 500 then return 'Pro';
else if return 'New';
Delimiter ;

¿Begin end?

Select j.nick, sum(p.puntos) as total nivel_jugadores(sum(p.puntos)) as nivel
from jugadores
inner join partidos p on p.jugador_id = j.id
Group by j.nick;

No se puede usar algo que no escuché porque el alias todavía no se ha creado 

With totales as (
Select j.nicm, sum(p.puntos) as total
From jugadores;
Inner join partidas p on 
P.jugador_id = j.id
Group by j.nick
)
Select nick, total, nivel_jugadores(total) as nivel from totales;

Esto último es una especie de tabla ¿Tabla virtual que se borra en ejecución?

Las vistas no se borran en ejecución, si no tiene prefijo se pueden confundir con las tablas. Más allá sería crear esa estructura y de allí se hace la ¿Extracción? Y queda un SELECT más sencillo.

Investigar sobre las tablas virtuales, se borran en la ejecución, cuidado con dónde y cuándo usarlas.

Las vistas se usan con mas confianza y abstrae cosas importantes.