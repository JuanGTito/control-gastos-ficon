# Despliegue de Gastos Ficon en Hostinger

Esta guía prepara una **instalación nueva**, sin importar los registros locales. El código Laravel, `.env`, los comprobantes y la plantilla Excel quedan fuera de la carpeta pública. La copia del repositorio no contiene contraseñas ni datos reales.

## Estructura real de ficon.pe

En este alojamiento, hPanel ya creó `gastos.ficon.pe` con raíz `/home/u571915572/domains/ficon.pe/public_html/gastos`. `public_html` es un enlace simbólico a `ficon-app/public`, de modo que la raíz física del subdominio es `ficon-app/public/gastos`. El sitio principal `ficon.pe` sigue usando `ficon-app`.

```text
/home/u571915572/domains/ficon.pe/
├── ficon-app/                       # sitio principal; no modificar
│   └── public/
│       └── gastos/                  # archivos públicos del subdominio
├── gastos-ficon/                    # repositorio Laravel, .env, vendor, storage
└── public_html -> ficon-app/public
```

No cambiar `public_html` ni copiar archivos a su raíz. Todo archivo web de Gastos Ficon va únicamente a `public_html/gastos`. El adaptador específico es `deploy/hostinger/index-subdominio-ficon.php`; el adaptador `deploy/hostinger/index.php` corresponde a un sitio independiente cuya raíz `public_html` es hermana de `gastos-ficon`.

## 1. Comprobar rutas y requisitos

En hPanel, activar PHP **8.3 o superior**, SSH y una base MariaDB/MySQL para el dominio. Anotar el nombre de base, usuario y contraseña asignados por hPanel; suelen llevar un prefijo de la cuenta. Confirmar que el dominio ya tiene HTTPS. En SSH, comprobar PHP y Composer 2:

```sh
php -v
composer2 --version
php -m
```

PHP debe tener las extensiones exigidas por `composer.lock`, en particular `pdo_mysql`, `mbstring`, `gd`, `zip` y `fileinfo`. Este proyecto usa CSS y JavaScript ya incluidos en `public/`; no necesita ejecutar npm en el servidor.

Desde SSH:

```sh
cd /home/u571915572/domains/ficon.pe
readlink -f public_html
readlink -f public_html/gastos
ls -la gastos-ficon
ls -la public_html/gastos
```

Confirmar que `public_html/gastos` resuelve a `ficon-app/public/gastos`. Revisar los archivos existentes antes de instalar. Crear una base MariaDB/MySQL nueva para Gastos Ficon desde hPanel; no reutilizar la base del sitio principal.

## 2. Obtener el código y configurar Laravel

La carpeta `gastos-ficon` ya aparece en hPanel. Si contiene el repositorio Git correcto y no tiene cambios locales, actualizarlo:

```sh
cd /home/u571915572/domains/ficon.pe
git -C gastos-ficon remote -v
git -C gastos-ficon status --short
git -C gastos-ficon branch --show-current
git -C gastos-ficon pull --ff-only origin main
```

El remoto debe ser `https://github.com/JuanGTito/control-gastos-ficon.git`, la rama `main` y el estado limpio. Si la carpeta está vacía, en vez del bloque anterior:

```sh
cd /home/u571915572/domains/ficon.pe
git clone https://github.com/JuanGTito/control-gastos-ficon.git gastos-ficon
```

Si contiene otros archivos, no clonar ni sobrescribir; revisar primero su contenido. Para la primera instalación:

```sh
cd gastos-ficon
test -f .env || cp .env.production.example .env
nano .env
composer2 install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer2 check-platform-reqs --no-dev
grep -q '^APP_KEY=$' .env && php artisan key:generate
mkdir -p storage/app/private/receipts storage/app/templates storage/framework/cache/data storage/framework/cache/locks storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
php artisan migrate --force
```

En `.env`, usar `APP_URL=https://gastos.ficon.pe` y cambiar `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` y `MAIL_FROM_ADDRESS`. Usar los datos reales de hPanel; normalmente `DB_HOST=localhost`, `DB_PORT=3306` y `DB_CONNECTION=mariadb`. Mantener `APP_ENV=production`, `APP_DEBUG=false` y `SESSION_SECURE_COOKIE=true`. Crear `APP_KEY` **una sola vez**; conservarla en actualizaciones posteriores.

## 3. Instalar la plantilla privada y publicar archivos web

Transferir por SFTP la plantilla Excel original a:

```text
/home/u571915572/domains/ficon.pe/gastos-ficon/storage/app/templates/caja-chica.xlsx
```

La plantilla no forma parte de Git; el exportador XLSX la necesita. Los comprobantes se guardan en `storage/app/private/receipts` y tampoco se copian a `public_html`.

Respaldar primero el contenido actual de la carpeta del subdominio. Luego copiar solo `public/` y reemplazar su `index.php` con el adaptador específico. No usar `../public_html/` como destino porque eso afectaría al sitio principal:

```sh
cd /home/u571915572/domains/ficon.pe
cp -a public_html/gastos "gastos-public-backup-$(date +%Y%m%d-%H%M%S)"
cp -a gastos-ficon/public/. public_html/gastos/
cp gastos-ficon/deploy/hostinger/index-subdominio-ficon.php public_html/gastos/index.php
if [ -f public_html/gastos/index.html ]; then mv public_html/gastos/index.html public_html/gastos/index.html.hostinger-backup; fi
cd gastos-ficon
test -f storage/app/templates/caja-chica.xlsx
php artisan optimize
```

La carpeta `public_html/gastos` debe contener `.htaccess`, CSS, JS, imágenes y el adaptador. `.env`, `vendor/` y `storage/` permanecen dentro de `gastos-ficon`. No copiar ni mover el enlace `public_html`.

## 4. Crear el superadministrador

En la carpeta `gastos-ficon`, después de `migrate`:

```sh
php artisan app:create-super-admin --username=superadmin --email=TU_CORREO@DOMINIO --name="Administrador Ficon"
```

Artisan pedirá la contraseña de forma oculta: mínimo 12 caracteres con letras y números. No colocar la contraseña en el comando. La opción `--generate-local-password` está deshabilitada en producción.

## 5. Comprobar y actualizar

Comprobar `php artisan migrate:status`, `https://gastos.ficon.pe/up`, el acceso, un gasto con comprobante y las exportaciones XLSX/PDF. Si falla Excel, comprobar la ubicación y permisos de `caja-chica.xlsx`. Si falla una ruta, revisar `public_html/gastos/.htaccess` y el adaptador del subdominio.

En actualizaciones, respaldar primero la base y `storage/app/private` más la plantilla. Mantener el mismo `.env` y `APP_KEY`. Desde el proyecto:

```sh
git pull --ff-only
composer2 install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan migrate --force
cp -a public/. ../public_html/gastos/
cp deploy/hostinger/index-subdominio-ficon.php ../public_html/gastos/index.php
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
