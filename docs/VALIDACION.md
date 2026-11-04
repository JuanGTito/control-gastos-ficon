# Validación de la entrega local

Fecha: 4 de noviembre de 2026. Proyecto: Gastos Ficon / control-gastos.

## Entorno comprobado

- PHP 8.4.27.
- Laravel Framework 13.40.0.
- MySQL Community Server 8.0.46, en instancia aislada del proyecto con acceso por loopback.
- Chrome 156, automatizado mediante DevTools con perfil separado.
- Node 24.22.1 para las pruebas de navegador.
- Dompdf 3.1.6 y PhpSpreadsheet 5.10.0, fijados en composer.lock.

## Resultados

| Verificación | Resultado |
| --- | --- |
| PHPUnit con SQLite en memoria | 52 pruebas y 347 aserciones, aprobadas antes del cambio de identidad |
| PHPUnit con MySQL / control_gastos_test | 54 pruebas y 366 aserciones, aprobadas |
| Navegador con MySQL / control_gastos_e2e | 37 comprobaciones, aprobadas |
| Laravel Pint | Aprobado |
| Sintaxis JavaScript de la aplicación y la prueba de navegador | Aprobada |
| Composer audit | Sin avisos de vulnerabilidades conocidos |

## Preparación de Git y Hostinger

La rama local `preparacion-produccion-hostinger` añade una plantilla de entorno sin secretos, un adaptador para `public_html` y la guía de despliegue. El repositorio excluye `.env`, `.runtime`, comprobantes, plantilla Excel, cachés y resultados de prueba. El seeder ya no crea el usuario de demostración.

En esta preparación se repitieron las 54 pruebas y 366 aserciones en MySQL, `composer check-platform-reqs --no-dev`, Laravel Pint y la validación de sintaxis PHP. Se montó una estructura local equivalente a `gastos-ficon/` junto a `public_html/`: el adaptador respondió HTTP 200 en `/login`, `/up`, CSS y logo. El servidor local habitual se restauró en `localhost:8000`.

## Migración de PostgreSQL a MySQL

Se respaldó la base original en `.runtime/backups/antes-mysql-20261104-170608.dump` y su configuración en el mismo directorio privado. Se creó una instancia MySQL propia en el puerto de base 3307, sin modificar el servicio MySQL preexistente del equipo. El migrador de una sola ejecución, archivado en `.runtime/backups/migrate-postgres-to-mysql.php`, transfirió usuarios, sesiones, caché, catálogos, gastos, caja, entregas y auditoría, preservando identificadores y relaciones. Comparó cada campo de los registros de origen y destino antes de confirmar la transacción: 3 usuarios, 2 gastos, 27 catálogos, 1 caja, 1 entrega y 7 entradas de auditoría, entre otras tablas. Se guardó además `.runtime/backups/gastos-ficon-mysql-20261104-171905.sql`. La instancia PostgreSQL anterior está detenida; sus archivos y respaldo permanecen disponibles como recuperación. La aplicación usa MySQL.

El índice de caja permanente se adaptó a MySQL/MariaDB con una columna generada y una restricción única, conservando varias rendiciones históricas por persona. La suite MySQL cubrió el reparto de reembolsos, permisos, exportaciones y auditoría. El navegador completó el flujo integral en otra base MySQL aislada.

## Caja permanente y roles: verificación actual

La identidad **Gastos Ficon** y el logo proporcionado se comprobaron en acceso y panel. Se retiraron los textos del espacio de trabajo y la etiqueta Espacio privado. Se validó la alternancia claro/oscuro, su persistencia entre pantallas, las tablas compactas de las tres clases de catálogo a 390 px en ambos temas y los formularios modales en tema oscuro. Las cuentas superadministradoras se excluyen antes de paginar el directorio y tampoco aparecen en selectores de responsables; la prueba conserva dos de esas cuentas para verificar el conteo.

Se verificó la caja permanente por persona sin exigir devolución del saldo entre entregas. El caso principal fue: primera entrega S/100, gasto S/115, adelanto personal pendiente S/15 y reposición S/100, distribuida como reembolso S/15 y agregado al fondo S/85. No se duplica el gasto ni se crea una devolución ficticia.

Se comprobaron reposiciones parciales del adelanto, arrastre de saldo positivo, reenvío del formulario sin doble entrega, rechazo de una segunda caja permanente para la misma persona, protección de entregas contra sobrescritura y protección de importes/fechas/caja de los gastos conciliados en una reposición posterior. Sigue siendo posible completar comprobantes y corregir descripciones.

Las pruebas de permisos cubren: administrador con caja propia; creación de técnicos y contadores por el administrador sin poder promoverse ni modificar administradores; técnico sin permiso de entregar dinero; contador con consulta global, revisión, auditoría y descarga, sin permiso de cambiar gastos, dinero o usuarios. Las revisiones no exigen saldo cero ni interrumpen el fondo; advierten si existen movimientos posteriores.

Chrome completó 37 comprobaciones, incluido el flujo real técnico → administrador → contador con reparto 15/85, revisión y descarga de Excel/PDF. Se revisaron las pantallas de escritorio y móvil. El Excel completo conserva el formato de rendición y añade ENTREGAS con el reparto de cada reposición. Las rendiciones anteriores se probaron como historial separado, conservando su funcionamiento previo.

Registros actuales: `.runtime/tests-mysql-first.log`, `test-results/browser-report.json`. Capturas de tema y catálogos: `test-results/ficon-*.png`. Respaldo previo a la ampliación de caja: `.runtime/backups/antes-caja-permanente.dump`.

## Cobertura funcional previa y conservada

- Acceso por usuario y correo, cierre de sesión, cuentas desactivadas e intentos fallidos.
- Permisos de usuario, administrador y superadministrador; rechazo de acceso a gastos ajenos.
- Creación del superadministrador por comando, rechazo de duplicados y prohibición del archivo de contraseña fuera de local.
- Creación y edición de usuarios; protección de la propia cuenta de superadministrador.
- Importes decimales, fechas válidas, límites de rango inclusivos, filtros abiertos, búsqueda, categoría, paginación y totales de día/semana.
- Registro de gastos y edición con actor y diferencias de auditoría; rechazo de versiones desactualizadas.
- Reversión de gasto y archivo nuevo cuando falla el registro de auditoría.
- Comprobantes privados, tipos y tamaños permitidos, descarga autorizada y acceso ajeno denegado.
- XLSX válido, texto de usuario tratado como texto para evitar fórmulas, filtros y permisos de exportación.
- PDF válido, reportes vacíos y límites de exportación.
- Escape de contenido HTML y protección CSRF desde otro origen sin token (HTTP 419).
- Registro y edición reales mediante modales, adjunto PNG, historial y descarga real de XLSX/PDF en Chrome.
- Revisión de pantallas de 1440, 768, 390 y 320 px: sin desbordamiento horizontal; modales dentro de la pantalla. Sin excepciones JavaScript durante los flujos.

Laravel 13 permite peticiones con `Sec-Fetch-Site: same-origin` mediante su protección de origen. Por ello la prueba CSRF usa una petición de otro origen sin token, conservando una sesión real, para verificar el rechazo efectivo.

## Evidencia local

La ampliación de caja chica incluye pruebas de administración y desactivación de catálogos, nombres duplicados, conservación de históricos, permisos por responsable, captura por un administrador distinto del responsable, devoluciones sin duplicar gastos, presentación, observación, cierre, versiones desactualizadas y validación independiente de dinero y comprobantes.

Las pruebas de exportación verifican documentos con más de veinte gastos, catálogos de más de treinta elementos, formularios separados por entrega/persona, base consolidada sin duplicados, datos reales en lugar de los ejemplos, fórmulas recalculables y DNI/números con ceros iniciales tanto en su valor como en su formato visible.

En Chrome se completó una entrega de S/100, un gasto de S/31.25 con adjunto, una devolución de S/68.75, presentación, bloqueo de edición y cierre por un administrador. Se descargaron Excel y PDF completos y filtrados, se renderizaron los Excel y se compararon visualmente con la estructura de la plantilla recibida. Las pantallas nuevas se revisaron también a 390 px; el flujo de gastos se verificó a 320, 390, 768 y 1440 px.

- `.runtime/tests-sqlite.log` y `.runtime/tests-mysql-first.log`.
- `test-results/browser-report.json`.
- `test-results/login-desktop.png`, `gastos-desktop.png` y `historial-desktop.png`.
- `test-results/gastos-768px.png`, `gastos-390px.png` y `gastos-320px.png`.
- `test-results/modal-desktop.png`, `modal-768px.png`, `modal-390px.png` y `modal-320px.png`.
- `test-results/reporte-navegador.xlsx` y `reporte-navegador.pdf`.
- `test-results/rendicion-completa.xlsx` y `rendicion-completa.pdf`.
- `test-results/caja-chica-desktop.png`, `caja-chica-mobile.png`, `catalogos-desktop.png` y `catalogos-mobile.png`.

Los datos de navegador y los comprobantes de las ejecuciones finales se aislaron de la base y del directorio de uso normal. La base `control_gastos` conserva los datos de uso previos a esta ampliación; no se importaron los ejemplos de la plantilla ni se cargaron gastos de demostración. Antes de migrar se guardó una copia en `.runtime/backups/antes-caja-chica.dump`.

## Límites de la validación

Verificación local en Windows y Chrome, con tamaños de pantalla emulados; no equivale a una prueba en dispositivos físicos ni en todos los navegadores. No se desplegó en Hostinger y no se hicieron pruebas de carga masiva ni de recuperación de copias de seguridad. El bloqueo de versiones se verificó mediante envíos con una versión desactualizada; no se hizo una prueba de carga concurrente.
