# Gastos Ficon · Control de gastos

Aplicación local en español para registrar gastos, adjuntar boletas, consultar movimientos y generar reportes. Implementada con Laravel 13, PHP 8.4 y MySQL 8. Arquitectura de monolito modular, interfaz Blade con CSS y JavaScript locales, sin compilación frontend ni dependencias de CDN.

La interfaz usa el logo FICON y una paleta azul. El botón **Modo oscuro / Modo claro** aparece en el acceso y en las pantallas internas; la elección se guarda en este navegador. Las tablas de categorías, tipos de comprobante y sucursales mantienen sus columnas en móvil con filas compactas. La cuenta de superadministrador se excluye del directorio, su conteo y los selectores de personas, pero sigue disponible para autenticación y auditoría.

## Caja permanente, técnicos y contador (flujo actual)

Cada técnico o administrador puede tener **una caja permanente**. El saldo se conserva entre entregas: no hay que devolverlo ni cerrar la caja para recibir más dinero. Las rendiciones creadas antes de esta ampliación permanecen como historial con su flujo anterior.

| Rol | Permisos |
| --- | --- |
| Técnico | Registra sus gastos y comprobantes, consulta y descarga su caja. No entrega dinero. |
| Contador | Consulta y descarga todas las cajas, gastos, comprobantes y auditoría. Registra revisiones u observaciones. No modifica gastos, entregas ni devoluciones. |
| Administrador | Crea técnicos y contadores, entrega dinero, administra catálogos y gastos. Puede tener su propia caja. No crea superadministradores ni modifica cuentas de administradores. |
| Superadministrador | Gestión completa de cuentas, privilegios y operación. |

Las cuentas anteriores con rol `user` conservan el acceso a sus datos y se muestran como Técnico. Las cuentas nuevas de técnico usan `technician`; contador usa `accountant`.

**Ejemplo probado:** S/100 entregados − S/115 gastados = S/15 adelantados por el responsable. Otra entrega de S/100 se registra como S/15 de reembolso y S/85 agregados al fondo. El gasto sigue siendo S/115; el reembolso no genera otro gasto ni una categoría artificial de préstamo.

Si la reposición es menor que el adelanto, se aplica por completo al reembolso y el resto del adelanto queda pendiente. Si ya había saldo positivo, la nueva entrega se suma a ese saldo. Los cálculos usan decimales exactos en el servidor.

Flujo de uso:

1. El administrador crea las cuentas en **Usuarios**.
2. En **Caja chica → Abrir caja permanente**, selecciona al responsable (puede seleccionarse a sí mismo) y registra la primera entrega.
3. El técnico registra gastos y adjunta boletas. El panel separa **Disponible en caja** y **Adelanto personal pendiente**.
4. En esa misma caja, el administrador usa **Registrar entrega**. El formulario muestra cuánto reembolsa y cuánto agrega al fondo. No se abre otra caja para cada reposición.
5. El contador usa **Registrar revisión**. La revisión guarda una fotografía de saldos y versión, sin exigir devolución ni interrumpir la caja. Si después hay movimientos, el panel informa que la revisión es anterior. Una observación es obligatoria cuando se marca un problema; no se da por revisado un fondo con comprobantes pendientes.
6. El contador puede descargar Excel y PDF completos o consultas filtradas. El Excel completo conserva la plantilla y añade la hoja **ENTREGAS**, con administrador, importe, reembolso, dinero agregado y saldo anterior/posterior.

Las entregas no se sobrescriben ni se duplican al reenviar el mismo formulario. No se permiten entregas con fecha futura ni anterior a movimientos ya registrados. Una vez que un gasto participó en una reposición, su importe, fecha y caja quedan protegidos para conservar los reembolsos históricos; todavía se pueden completar comprobantes y corregir datos descriptivos. Las devoluciones permanecen como una opción excepcional, separada del flujo normal.

## Catálogos y rendiciones históricas

El siguiente apartado describe los catálogos y el flujo conservado para las rendiciones anteriores. Las cajas nuevas siguen el flujo permanente indicado arriba.

1. En **Configuración**, un administrador agrega, renombra, ordena o desactiva categorías, tipos de comprobante y sucursales. Los identificadores son estables y los movimientos históricos se conservan. Las opciones desactivadas se pueden consultar y exportar, pero no asignar a movimientos nuevos.
2. En **Caja chica → Entregar dinero**, un administrador selecciona al responsable y registra fecha, importe, motivo y datos opcionales de DNI, área y sucursal. El sistema genera un número `RC-000001` y registra quién entregó el dinero.
3. El responsable o un administrador registra los gastos seleccionando esa entrega. El formulario admite proveedor, RUC/DNI, tipo de comprobante, serie, número y observación. El responsable y el usuario que captura o modifica se conservan por separado en la auditoría.
4. Las devoluciones se registran desde la rendición, se descuentan del saldo y no se suman como gastos. No se puede devolver más que el saldo pendiente. Son movimientos auditados sin borrado desde la interfaz.
5. El responsable presenta la rendición. Los estados son **Abierta, Presentada, Observada y Cerrada**. Una presentada o cerrada no admite edición de gastos ni devoluciones nuevas.
6. El administrador puede observar una presentada o reabrir una cerrada con comentario obligatorio. Para cerrar una presentada, la diferencia de dinero debe ser cero y deben estar adjuntos todos los comprobantes.

La conciliación utiliza aritmética decimal: `recibido − gastos − devoluciones = diferencia pendiente`. Si el gasto supera lo recibido se muestra el importe por reembolsar al responsable. El responsable de una entrega no se puede cambiar. Sus importes y datos solo se editan en estado abierto u observado, con auditoría. La fecha de entrega no puede ser posterior a gastos ni devoluciones existentes.

### Exportar con la plantilla

- **Mis gastos → Excel/PDF** exporta los filtros de fechas, categoría, responsable y caja chica. Es una **consulta filtrada**, no un cierre: el Excel deja vacíos recibido/devuelto y no presenta un saldo de conciliación falso.
- **Caja chica → una rendición → Excel completo/PDF completo** exporta todos los gastos de la entrega, con recibido, gastado, devuelto, diferencia, estado de revisión y comprobantes.
- El Excel conserva secciones, colores, columnas, firmas y declaración. Una consulta con varias cajas o gastos personales genera formularios separados por entrega/responsable y una base consolidada sin duplicados.
- Al superar veinte gastos se amplían las filas, fórmulas, controles y área de impresión. Los catálogos exportados crecen por encima de treinta elementos. DNI, RUC, series y números conservan sus ceros iniciales.
- Generar el documento no lo firma automáticamente: quedan los espacios para firmas. El estado monetario CONFORME, el control de documentos y el estado de revisión de la aplicación se muestran por separado.
- La empresa del formato se configura con `REPORT_COMPANY`, inicialmente FICON SAC.

La copia de la plantilla está en `storage/app/templates/caja-chica.xlsx`, excluida de Git. Conservarla en las copias de seguridad y copiarla a la misma ubicación si se traslada el proyecto de forma autorizada. El generador limpia los datos de ejemplo de cada exportación; no los importa como movimientos reales. El original recibido en Descargas permanece intacto.

## Funciones

- Login por usuario o correo y contraseña, cierre de sesión y bloqueo temporal de intentos fallidos.
- Roles de usuario, administrador y superadministrador; creación, edición y desactivación de cuentas desde modales.
- Registro y edición de gastos: fecha, descripción, categoría, importe y comprobante opcional.
- Resumen de hoy, de la semana (lunes a domingo) y de la consulta filtrada.
- Filtros por fechas inclusivas, categoría y descripción; accesos rápidos a hoy, semana y mes; paginación.
- Exportaciones XLSX y PDF con los mismos filtros y permisos de la consulta.
- Comprobantes PDF, JPG o PNG de hasta 5 MB, almacenados de forma privada y descargados con autorización.
- Auditoría con actor, fecha, valores anteriores y nuevos; historial por gasto y auditoría general para superadministradores.
- Protección contra sobrescritura cuando dos personas editan la misma versión de un gasto.
- Interfaz responsive: escritorio, tabletas y móviles desde 320 px, con formularios en modales.

La moneda inicial es **PEN**, configurable mediante `EXPENSE_CURRENCY` antes de ingresar gastos. Se usa una moneda única por instalación; no hay conversión entre monedas. La zona horaria de presentación es `America/Bogota`, configurable con `EXPENSE_TIMEZONE`; los instantes se guardan en UTC.

## Ejecutar en este equipo

Desde PowerShell, dentro de esta carpeta:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/start-local.ps1
```

Abrir **http://localhost:8000**. Este proyecto utiliza únicamente ese origen para su servidor web local. Si el puerto está ocupado, detener primero la instancia correspondiente; no iniciar automáticamente en otro puerto.

El usuario inicial es `superadmin`. Su contraseña aleatoria está en `.runtime/local-access.txt`, excluido de Git. No hay contraseñas predeterminadas dentro del código.

Para detener el servidor, presionar `Ctrl+C` en su terminal. MySQL puede permanecer activo; para detener solo la instancia de este proyecto:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/stop-database.ps1
```

## Instalación local reproducible en Windows

Requisitos: PHP 8.3 o superior, Composer, PowerShell, MySQL Community Server 8.0 y las extensiones de Laravel más `bcmath`, `gd`, `zip` y `pdo_mysql`. El ejecutable `php` debe estar en PATH.

```powershell
composer install
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/setup-local.ps1
php -c .runtime/php.ini artisan app:create-super-admin
```

El instalador crea una instancia MySQL propia en `.runtime/mysql-data`, con credenciales aleatorias en `.runtime/mysql-credentials.json`, excluidas de Git. No modifica el servicio MySQL del equipo ni el PHP INI global. La instancia escucha solo en `127.0.0.1:3307` porque el puerto 3306 ya está ocupado en este equipo. En una instalación nueva, se puede indicar otro **puerto de base de datos** con `setup-local.ps1 -DatabasePort 3308` y otra ruta del ejecutable con `-MySqlBin`. El servidor web sigue usando únicamente `localhost:8000`.

Se crean tres bases independientes: `control_gastos` para uso local, `control_gastos_test` para PHPUnit y `control_gastos_e2e` para navegador. El rol de aplicación no es superusuario y no tiene permisos para crear bases ni roles.

El comando de superadministrador solicita una contraseña oculta de al menos 12 caracteres con letras y números. También admite opciones de identidad, sin contraseña como argumento:

```powershell
php -c .runtime/php.ini artisan app:create-super-admin --username=superadmin --email=admin@example.com --name="Administrador"
```

Solo en `APP_ENV=local`, `--generate-local-password` genera una contraseña aleatoria y guarda los datos de acceso en `.runtime/local-access.txt`. Esa opción se utilizó para preparar esta instalación.

## Permisos

| Acción | Usuario | Administrador | Superadministrador |
| --- | --- | --- | --- |
| Registrar gastos propios | Sí | Sí | Sí |
| Consultar, editar, exportar e inspeccionar historial/comprobantes | Propios | Todos | Todos |
| Crear, editar y desactivar usuarios | No | No | Sí |
| Consultar auditoría general | No | No | Sí |

No hay registro público. Un superadministrador no puede desactivar su propia cuenta ni quitarse su rol. No se permite eliminar gastos ni historial desde la aplicación. Al cambiar una contraseña o desactivar una cuenta se invalidan sus sesiones guardadas.

## Módulos

- `app/Modules/Identity`: autenticación, estado de las cuentas, administración de usuarios y comando de superadministrador.
- `app/Modules/Expenses`: modelo, consultas compartidas, validación, políticas y acción transaccional de guardado.
- `app/Modules/Audit`: registro de cambios y consultas del historial.
- `app/Modules/Reports`: exportadores XLSX con PhpSpreadsheet y PDF con Dompdf.
- `app/Modules/Catalogs`: categorías, tipos de comprobante y sucursales con versiones y auditoría.
- `app/Modules/PettyCash`: entregas, devoluciones, conciliación y revisión de rendiciones.

Una aplicación y una base de datos, con código separado por responsabilidad. Rutas, registro de políticas y vistas actúan como puntos de integración. Reportes reutiliza la consulta autorizada de Gastos; Gastos e Identidad usan la acción de Auditoría en la misma transacción. El modelo de usuario y la configuración son infraestructura compartida. No se añaden servicios externos ni procesos de cola para estos flujos.

## Pruebas

Suite funcional rápida en SQLite en memoria:

```powershell
php -c .runtime/php.ini vendor/phpunit/phpunit/phpunit
```

Suite completa contra MySQL, usando exclusivamente `control_gastos_test`:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/test-mysql.ps1
```

Prueba de navegador mediante Chrome DevTools y Node 24 o superior:

1. Detener el servidor de uso normal en `localhost:8000`.
2. Iniciar Chrome de pruebas sin ventana con un perfil aislado y depuración en `127.0.0.1:9225`:

```powershell
$profilePath = Join-Path (Get-Location) '.runtime/browser-profile'
Start-Process -FilePath 'C:\Program Files\Google\Chrome\Application\chrome.exe' -ArgumentList @('--headless=new', '--no-first-run', '--remote-debugging-port=9225', "--user-data-dir=$profilePath", 'about:blank') -WindowStyle Hidden
node scripts/browser-test.mjs
```

3. Reiniciar el servidor normal con `scripts/start-local.ps1`.

El script de navegador comprueba login, permisos, creación de usuario por modal, registro con comprobante, edición, auditoría, filtros, descarga XLSX/PDF, protección CSRF y pantallas de 1440, 768, 390 y 320 px. Usa solo `http://localhost:8000`, una base exclusiva y comprobantes en `.runtime/e2e-receipts`. El comando de fixtures se niega a ejecutar si la base no se llama exactamente `control_gastos_e2e`. Cada ejecución reconstruye únicamente esa base.

Capturas, reportes y exportaciones de verificación se guardan en `test-results/`, excluido de Git. Ver `docs/VALIDACION.md` para los resultados de la entrega.

## Alcance y alojamiento

- Implementación y datos exclusivamente locales; sin despliegue ni publicación.
- Hostinger ofrece bases MySQL en sus planes y usa MariaDB en Web/Cloud según su [documentación](https://www.hostinger.com/support/1583226-which-database-management-system-is-used-at-hostinger/). Al publicar, configurar la conexión `mariadb` con los datos de hPanel, HTTPS, `APP_DEBUG=false`, secretos propios, copias de base/adjuntos y raíz web en `public/`. No trasladar `.runtime/` al servidor. La validación local actual se hizo con MySQL 8; queda pendiente una prueba en el MariaDB del alojamiento.
- Exportaciones acotadas a 5.000 gastos en XLSX y 500 en PDF por descarga; reducir el rango de fechas si se supera el límite.
- Auditoría sin contraseñas ni tokens y protegida contra edición desde los modelos de la aplicación. No sustituye un registro externo resistente a modificaciones de un administrador de la base de datos.
- Al reemplazar un comprobante se conserva el archivo previo para mantener la trazabilidad; la descarga del gasto entrega la versión actual.
- La herramienta mencionada inicialmente como «elasticfila» sigue sin identificar. La interfaz actual usa Blade y JavaScript nativo; no se asumió Filament ni Livewire.
- Una migración a Java queda para una etapa posterior.
