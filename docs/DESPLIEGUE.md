# Despliegue en producción (VPS)

Guía para un VPS Ubuntu 24.04 con Nginx, PHP-FPM y MySQL 8. Las rutas y los nombres son de ejemplo: ajústalos a tu servidor.

> **No uses hosting compartido.** Este sistema necesita un worker de cola permanente y un cron que corra cada minuto. Además, la raíz web tiene que apuntar a `public/`. En un hosting compartido suele faltar alguna de las tres cosas, y el sistema falla sin avisar.

## 1. Paquetes

```bash
sudo apt install nginx mysql-server supervisor unzip git \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-gd php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-intl php8.4-bcmath
```

`pcntl` viene incluido en PHP CLI y lo usa el worker para cortar validaciones que exceden su tiempo.

## 2. Base de datos

```sql
CREATE DATABASE validador CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'validador'@'localhost' IDENTIFIED BY '<clave-larga>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, TRIGGER
  ON validador.* TO 'validador'@'localhost';
```

- **No uses `root`.** La aplicación solo necesita permisos sobre su propia base.
- **Triggers de inmutabilidad y binlog.** La migración de triggers requiere el permiso `TRIGGER`. Si el binlog está activo (en MySQL 8 lo está por defecto), MySQL además exige el privilegio `SUPER` o esta variable:

```sql
SET PERSIST log_bin_trust_function_creators = 1;
```

## 3. Código y `.env`

```bash
cd /var/www && git clone <repo> validador && cd validador
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
```

Valores obligatorios en producción:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://validador.tudominio.com
APP_LOCALE=es
APP_DISPLAY_TIMEZONE=America/Lima

DB_CONNECTION=mysql
DB_DATABASE=validador
DB_USERNAME=validador
DB_PASSWORD=<clave-larga>

QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=480

FILESYSTEM_DISK=local
PIEZAS_DISK=local

AI_DRIVER=anthropic
ANTHROPIC_API_KEY=<clave>
AI_MAX_TOKENS=16000

LOG_STACK=daily
LOG_LEVEL=warning
SESSION_ENCRYPT=true

MAIL_MAILER=smtp
# ... datos del servidor de correo
```

Después:

```bash
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan db:seed --class=PromptTemplateSeeder --force
php artisan optimize
php artisan entorno:verificar        # no debe marcar ningún [CRITICO]
```

El envío al director avisa por correo, así que `MAIL_*` tiene que apuntar a un SMTP real. Los correos salen por la cola: si el worker no corre, solo llega el aviso del panel.

Crea el primer super_admin con tinker (`User::create(...)` y luego `->assignRole('super_admin')`), con una contraseña de 12 caracteres o más.

## 4. Permisos

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
```

No hace falta `php artisan storage:link`. Las piezas se sirven desde el disco privado a través de una ruta con sesión.

## 5. Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name validador.tudominio.com;
    root /var/www/validador/public;          # siempre public/, nunca la raíz del proyecto
    index index.php;
    client_max_body_size 25M;                 # piezas de hasta 20 MB

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

Usa HTTPS con Let's Encrypt (`certbot --nginx`). En PHP-FPM, fija `upload_max_filesize = 25M` y `post_max_size = 25M`.

## 6. Worker de la cola (supervisor)

`/etc/supervisor/conf.d/validador-worker.conf`:

```ini
[program:validador-worker]
command=php /var/www/validador/artisan queue:work database --sleep=3 --tries=1 --timeout=420 --max-time=3600
user=www-data
numprocs=2
autostart=true
autorestart=true
stopwaitsecs=450
redirect_stderr=true
stdout_logfile=/var/www/validador/storage/logs/worker.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
```

Después de cada despliegue, corre `php artisan queue:restart` para que el worker cargue el código nuevo.

## 7. Programador de tareas (cron)

```bash
sudo crontab -u www-data -e
* * * * * cd /var/www/validador && php artisan schedule:run >> /dev/null 2>&1
```

Corre estas tareas:

- Cierre de validaciones colgadas, cada 10 minutos.
- Verificación de integridad, diaria a las 06:45 hora de Lima, con aviso en el panel.
- Respaldos, si `BACKUP_ENABLED=true`.

Para comprobarlo: `php artisan schedule:list`.

## 8. Respaldos

Ver [RUNBOOK-respaldos.md](RUNBOOK-respaldos.md). En resumen:

```dotenv
BACKUP_ENABLED=true
BACKUP_DISK=respaldos
BACKUP_NOTIFY_EMAIL=alertas@tudominio.com
BACKUP_ARCHIVE_PASSWORD=<clave-larga>
RESPALDOS_KEY=... RESPALDOS_SECRET=... RESPALDOS_BUCKET=... RESPALDOS_ENDPOINT=...
```

El destino `respaldos` es un bucket S3 compatible (Backblaze B2, Cloudflare R2, DigitalOcean Spaces), fuera del VPS. Una vez configurado, corre `php artisan respaldo:probar` para verificar que el respaldo se puede restaurar.

## 9. Despliegues siguientes

```bash
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force   # si la versión trae permisos nuevos
php artisan optimize
php artisan queue:restart
php artisan up
```
