# Despliegue de Gastos Ficon en Hostinger

Esta guía prepara una **instalación nueva**, sin importar los registros locales. En Hostinger Web/Cloud, el motor de base es MariaDB y el directorio público habitual es `public_html`. El código Laravel, `.env`, los comprobantes y la plantilla Excel deben quedar fuera de `public_html`. La copia del repositorio no contiene contraseñas ni datos reales.

Para mantener el sitio principal `ficon.pe`, crear `gastos.ficon.pe` como **sitio independiente** en hPanel: Websites → Create site → Custom PHP/HTML → Start empty → conectar el subdominio. Este sitio nuevo debe tener su propio directorio `/home/USUARIO/domains/gastos.ficon.pe/public_html`; verificar la ruta mostrada por hPanel antes de usar los comandos. Crear también su base MariaDB en hPanel y activar PHP 8.3 o superior. No copiar archivos de Gastos Ficon al `public_html` del sitio principal.

## 1. Preparar el alojamiento

En hPanel, activar PHP **8.3 o superior**, SSH y una base MariaDB/MySQL para el dominio. Anotar el nombre de base, usuario y contraseña asignados por hPanel; suelen llevar un prefijo de la cuenta. Confirmar que el dominio ya tiene HTTPS. En SSH, comprobar PHP y Composer 2:

```sh
php -v
composer2 --version
php -m
```

PHP debe tener las extensiones exigidas por `composer.lock`, en particular `pdo_mysql`, `mbstring`, `gd`, `zip` y `fileinfo`. Este proyecto usa CSS y JavaScript ya incluidos en `public/`; no necesita ejecutar npm en el servidor.

## 2. Estructura segura de carpetas

Ejemplo para `/home/USUARIO/domains/DOMINIO`:

```text
DOMINIO/
├── gastos-ficon/             # repositorio, .env, vendor/, storage/
└── public_html/              # solo archivos de public/ e index.php adaptado
```

Crear o clonar el repositorio en `gastos-ficon`, como carpeta **hermana** de `public_html`. El archivo `deploy/hostinger/index.php` asume exactamente ese nombre. Si se elige otro, cambiar su ruta `../gastos-ficon` antes de copiarlo. No ubicar el repositorio completo dentro de `public_html`.

Si todavía no hay un remoto Git, subir el ZIP de código `gastos-ficon-hostinger.zip` por SFTP al directorio del dominio y sustituir `git clone` del paso siguiente por:

```sh
mkdir gastos-ficon
unzip gastos-ficon-hostinger.zip -d gastos-ficon
```

El ZIP contiene solo archivos versionados; la plantilla, los comprobantes y las credenciales se transfieren por separado.

## 3. Instalar y configurar por SSH

Sustituir `DOMINIO` y `URL_DEL_REPOSITORIO`; ejecutar estos comandos desde SSH del alojamiento:

```sh
cd /home/USUARIO/domains/DOMINIO
git clone URL_DEL_REPOSITORIO gastos-ficon
cd gastos-ficon
cp .env.production.example .env
nano .env
composer2 install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer2 check-platform-reqs --no-dev
php artisan key:generate
mkdir -p storage/app/private/receipts storage/app/templates storage/framework/cache/data storage/framework/cache/locks storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
php artisan migrate --force
```

En `.env`, cambiar obligatoriamente `APP_URL`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` y `MAIL_FROM_ADDRESS`. Usar los datos reales de hPanel; normalmente `DB_HOST=localhost`, `DB_PORT=3306` y `DB_CONNECTION=mariadb`. Mantener `APP_ENV=production`, `APP_DEBUG=false` y `SESSION_SECURE_COOKIE=true`. Crear `APP_KEY` **una sola vez**; conservarla en actualizaciones posteriores.

## 4. Instalar la plantilla privada y publicar archivos web

Transferir por SFTP la plantilla Excel original a:

```text
gastos-ficon/storage/app/templates/caja-chica.xlsx
```

La plantilla no forma parte de Git; el exportador XLSX la necesita. Los comprobantes se guardan en `storage/app/private/receipts` y tampoco se copian a `public_html`.

Con `public_html` reservado para esta aplicación y después de respaldar cualquier sitio anterior:

```sh
cd /home/USUARIO/domains/DOMINIO/gastos-ficon
cp -a public/. ../public_html/
cp deploy/hostinger/index.php ../public_html/index.php
test -f storage/app/templates/caja-chica.xlsx
php artisan optimize
```

El segundo `cp` reemplaza el `index.php` normal de Laravel por el adaptador que carga el proyecto desde la carpeta hermana. Deben quedar `public_html/.htaccess`, CSS, JS, imagen FICON y el adaptador. Retirar la página predeterminada del dominio si existe un `index.html` que tome prioridad.

## 5. Crear el superadministrador

En la carpeta `gastos-ficon`, después de `migrate`:

```sh
php artisan app:create-super-admin --username=superadmin --email=TU_CORREO@DOMINIO --name="Administrador Ficon"
```

Artisan pedirá la contraseña de forma oculta: mínimo 12 caracteres con letras y números. No colocar la contraseña en el comando. La opción `--generate-local-password` está deshabilitada en producción.

## 6. Comprobar y actualizar

Comprobar `php artisan migrate:status`, `https://DOMINIO/up`, el acceso, un gasto con comprobante y las exportaciones XLSX/PDF. Si falla Excel, comprobar la ubicación y permisos de `caja-chica.xlsx`. Si falla una ruta, revisar `public_html/.htaccess` y la ruta de `deploy/hostinger/index.php`.

En actualizaciones, respaldar primero la base y `storage/app/private` más la plantilla. Mantener el mismo `.env` y `APP_KEY`. Desde el proyecto:

```sh
git pull --ff-only
composer2 install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan migrate --force
cp -a public/. ../public_html/
cp deploy/hostinger/index.php ../public_html/index.php
php artisan optimize
```

La validación local actual usa MySQL 8. Ejecutar las migraciones primero en una base de ensayo MariaDB del mismo alojamiento antes de activar datos de producción.

## Fuentes técnicas

- [Hostinger: motor MariaDB en Web/Cloud](https://www.hostinger.com/support/1583226-which-database-management-system-is-used-at-hostinger/).
- [Hostinger: subdominio como sitio independiente](https://www.hostinger.com/support/1583405-how-to-create-and-delete-subdomains-in-hostinger/).
- [Hostinger: Composer 2 por SSH](https://www.hostinger.com/support/5792078-how-to-use-composer-at-hostinger/).
- [Hostinger: directorio público y sus límites](https://www.hostinger.com/support/1583494-what-is-the-path-to-your-website-s-root-home-directory-and-how-to-change-it-in-hostinger/).
- [Hostinger: PHP disponible](https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/).
- [Laravel 13: recomendaciones de despliegue](https://laravel.com/framework/docs/deployment).
