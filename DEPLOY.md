# Desplegar Whitewater en el VPS (con HTTPS + Push)

Guía para montar la app en el VPS de Joppa. El **HTTPS es obligatorio**: sin él no funcionan el service worker ni las notificaciones push de iOS.

> Corre estos comandos **tú** en el servidor (yo no manejo credenciales). Ajusta rutas, dominio y usuario.

## 0. Requisitos en el VPS
- PHP 8.2+ con extensiones: `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `gd`, `curl`, `xml`
- Composer, Node 18+ y npm
- MySQL/MariaDB
- Nginx + Certbot (Let's Encrypt)

```bash
sudo apt update
sudo apt install -y php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-bcmath php8.2-curl php8.2-xml php8.2-gd \
  nginx mysql-server certbot python3-certbot-nginx unzip
# Composer y Node si no están:
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt install -y nodejs
```

## 1. Base de datos
```bash
sudo mysql -e "CREATE DATABASE whitewater CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'whitewater'@'localhost' IDENTIFIED BY 'UNA_CLAVE_FUERTE';"
sudo mysql -e "GRANT ALL ON whitewater.* TO 'whitewater'@'localhost'; FLUSH PRIVILEGES;"
```

## 2. Subir el código
Por git (recomendado) o `scp`. Destino sugerido: `/var/www/whitewater`.
```bash
sudo mkdir -p /var/www/whitewater && sudo chown -R $USER:$USER /var/www/whitewater
# git clone <tu-repo> /var/www/whitewater   (o sube los archivos con scp/rsync)
cd /var/www/whitewater
```

## 3. Dependencias y build
```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

## 4. Configurar `.env`
```bash
cp .env.example .env
php artisan key:generate
php artisan webpush:vapid   # genera VAPID_PUBLIC_KEY y VAPID_PRIVATE_KEY -> pégalas en .env
nano .env
```
Valores clave en `.env`:
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://whitewater.tudominio.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=whitewater
DB_USERNAME=whitewater
DB_PASSWORD=UNA_CLAVE_FUERTE

VAPID_PUBLIC_KEY=...(de webpush:vapid)
VAPID_PRIVATE_KEY=...(de webpush:vapid)
VAPID_SUBJECT="mailto:tu-correo@ejemplo.com"
ROUTINE_REMINDER_TIME=20:00
```

## 5. Migrar, sembrar y cachear
```bash
php artisan migrate --force
php artisan db:seed --force   # opcional: crea 2 usuarios demo, tasa inicial, etc. Cambia las claves luego.
php artisan storage:link      # necesario para las fotos de perfil
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## 6. Permisos
```bash
sudo chown -R www-data:www-data /var/www/whitewater/storage /var/www/whitewater/bootstrap/cache
sudo chmod -R 775 /var/www/whitewater/storage /var/www/whitewater/bootstrap/cache
```

## 7. Nginx + HTTPS
`/etc/nginx/sites-available/whitewater`:
```nginx
server {
    listen 80;
    server_name whitewater.tudominio.com;
    root /var/www/whitewater/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ /\.(?!well-known).* { deny all; }
    client_max_body_size 20M;
}
```
```bash
sudo ln -s /etc/nginx/sites-available/whitewater /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d whitewater.tudominio.com   # instala el certificado HTTPS
```
> Apunta el (sub)dominio a la IP del VPS en tu DNS antes de correr certbot.

## 8. Scheduler (dispara tasas y recordatorios)

Algo tiene que llamar a `php artisan schedule:run` **cada minuto**. Eso es lo que
ejecuta `rates:sync` (tasas 2x/día) y `routines:remind` (recordatorio de rutinas
a la hora de `ROUTINE_REMINDER_TIME`). Sin esto las notificaciones no salen nunca.

Hay dos formas; usa la que aplique a tu servidor.

### Opción A: cron

Muchas imágenes de VPS vienen **sin cron** y `crontab` responde
`command not found`. Instálalo primero:
```bash
sudo apt update && sudo apt install -y cron && sudo systemctl enable --now cron
```

La tarea va en el crontab de **`www-data`**, no en el de root: `www-data` es
quien es dueño de `storage/`, y un archivo que cree root ahí (un log rotado, por
ejemplo) deja de ser escribible para la web.

```bash
( crontab -u www-data -l 2>/dev/null; \
  echo '* * * * * cd /var/www/whitewater && /usr/bin/php artisan schedule:run >> /dev/null 2>&1' ) \
  | crontab -u www-data -
crontab -u www-data -l   # comprueba que quedó
```

> Ruta absoluta a `php` a propósito: el `PATH` de cron es mínimo y no siempre
> coincide con el de tu sesión. Confírmala con `which php`.

### Opción B: temporizador de systemd

No necesita paquetes extra y deja el registro de cada ejecución en `journalctl`.

`/etc/systemd/system/whitewater-scheduler.service`:
```ini
[Unit]
Description=Scheduler de Whitewater
After=network.target

[Service]
Type=oneshot
User=www-data
WorkingDirectory=/var/www/whitewater
ExecStart=/usr/bin/php /var/www/whitewater/artisan schedule:run
```

`/etc/systemd/system/whitewater-scheduler.timer`:
```ini
[Unit]
Description=Ejecuta el scheduler de Whitewater cada minuto

[Timer]
OnCalendar=*:0/1
AccuracySec=1s

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now whitewater-scheduler.timer
systemctl list-timers whitewater-scheduler.timer   # comprueba que tiene próxima ejecución
journalctl -u whitewater-scheduler.service -n 20   # qué hizo la última vez
```

> Ajusta `/usr/bin/php` a lo que devuelva `which php`, y `User=` a quien sea
> dueño de `storage/` (normalmente `www-data`).

### Comprobar que quedó funcionando
Espera un minuto tras activarlo y corre:
```bash
php artisan push:doctor
```
Tiene que decir **«✓ El scheduler está corriendo (último latido hace N segundos)»**.
El latido lo escribe una tarea programada cada minuto, así que confirma el cron
al momento, sin esperar a la hora del recordatorio.

Dos líneas de la salida que **no** significan lo mismo:

| Línea | Qué significa |
|---|---|
| `✓ El scheduler está corriendo` | Algo llama a `schedule:run` cada minuto. Esto es lo que hay que conseguir. |
| `Todavía no se ha enviado ningún recordatorio` | Normal hasta las 20:00. **No es un fallo**: el recordatorio sale una vez al día. |

Para no esperar a la noche, dispáralo a mano (manda push de verdad a los teléfonos):
```bash
php artisan routines:remind
```

> **`php artisan schedule:list` no prueba nada.** Solo enseña lo que *estaría*
> programado, y lo lista igual aunque no haya cron ni temporizador instalado.

## 9. Instalar en el iPhone y activar notificaciones
1. Abre `https://whitewater.tudominio.com` en **Safari**.
2. Toca **Compartir** → **Añadir a inicio**.
3. Abre la app **desde el ícono** de la pantalla de inicio (modo app).
4. Ve a **Hogar** → activa **Recordatorios de tareas** → concede el permiso.
5. Toca **Enviar prueba** para confirmar que llega la notificación.

Repite en el iPhone de cada miembro del hogar.

### Si no llegan las notificaciones
```bash
php artisan push:doctor          # revisa claves VAPID, HTTPS, dispositivos y cron
php artisan push:doctor --send   # además intenta un envío real y muestra el error exacto
```
`push:doctor` dice **cuándo se ejecutó por última vez** el recordatorio. Si responde
«no se ha ejecutado nunca», el problema es el cron del paso 8 y no las claves.

Los fallos habituales, en orden:
1. **No hay scheduler** (paso 8): sin él `routines:remind` no se ejecuta nunca.
   Ojo: si `crontab` responde `command not found`, el paquete `cron` no está
   instalado y el cron que creías puesto no existe.
2. **APP_URL no es HTTPS**: el iPhone no registra el service worker.
3. **La app no se abrió desde el ícono** de la pantalla de inicio: en Safari normal iOS no deja activar push.

> Si `push:doctor` responde `There are no commands defined in the "push" namespace`,
> el servidor está corriendo una versión vieja del código: falta hacer `git pull`
> (o estás en otra rama) y `composer install`.

## Actualizar la app más adelante
```bash
cd /var/www/whitewater
git pull            # o sube los archivos nuevos
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan storage:link        # si aún no existe public/storage
php artisan config:clear && php artisan config:cache
php artisan route:cache && php artisan view:cache
```

## Notas de esta versión

Comprobación rápida del `.env` de producción antes de nada:
```bash
grep -n '^APP_URL\|^APP_ENV\|^APP_DEBUG\|^APP_TIMEZONE' /var/www/whitewater/.env
```
`APP_URL` tiene que ser el dominio real con `https://`. Si se quedó en
`http://localhost`, los enlaces absolutos que genera Laravel (recuperación de
contraseña, correos) apuntan a ninguna parte. `APP_TIMEZONE=America/Caracas` es
lo que hace que las 20:00 del recordatorio sean las de Venezuela y no UTC.
Tras tocar el `.env`: `php artisan config:clear && php artisan config:cache`.

Lo que hay que revisar al desplegarla:

- **`php artisan migrate --force` es obligatorio.** Añade `receipt_path` a gastos,
  abonos, aportes y compras, y `theme` a los usuarios.
- **`composer install` también es obligatorio**: hay una dependencia nueva
  (`anthropic-ai/sdk`, para el escaneo de facturas).
- **`php artisan storage:link` tiene que existir.** Sin ese enlace las fotos de
  perfil y los comprobantes salen rotos (error 404 en `/storage/...`).
- **La extensión `gd` de PHP** es la que reduce y endereza las fotos. Sin ella la
  app sigue funcionando, pero guarda los originales tal cual (varios MB por
  recibo). Comprueba con `php -m | grep gd`.
- **`client_max_body_size`** en Nginx debe ser al menos `20M` (paso 7): las fotos
  del iPhone pasan de los 8 MB antes de reducirse.
- **Permisos de `storage/app/public`**: ahí van los recibos, y `www-data` tiene
  que poder escribir.
  ```bash
  sudo chown -R www-data:www-data /var/www/whitewater/storage
  sudo chmod -R 775 /var/www/whitewater/storage
  ```
- **`config:clear` antes de `config:cache`**: las URLs de las imágenes cambiaron a
  rutas relativas; con la config vieja cacheada seguirían apuntando a `APP_URL`.

## Escaneo de facturas (opcional)

Permite crear una compra completa con sus productos a partir de la foto de la
factura. Es **opcional**: sin ninguna clave configurada el botón no aparece y
el resto de la app funciona igual.

Hay dos proveedores intercambiables. Basta con configurar **uno**.

| | Gemini | Anthropic |
|---|---|---|
| Coste | Capa gratuita con límites | ~3–4 céntimos por factura |
| Lectura de fotos malas | Aceptable | Mejor |
| Clave | <https://aistudio.google.com/apikey> | <https://console.anthropic.com> → API Keys |

### Gemini (gratis para empezar)
```
INVOICE_SCANNER=auto
GEMINI_API_KEY=AIza...
GEMINI_MODEL=gemini-3.6-flash
```
Si al escanear responde que el modelo no existe, cambia `GEMINI_MODEL` por uno
que aparezca en tu cuenta de AI Studio (`gemini-3.6-flash-lite` gasta menos
cuota). El mensaje de error te lo dice tal cual.

### Anthropic (de pago, lee mejor)
```
INVOICE_SCANNER=auto
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-opus-5
```

Después de tocar el `.env`, siempre:
```bash
php artisan config:clear && php artisan config:cache
```

Con `INVOICE_SCANNER=auto` se usa el proveedor que tenga clave; si están los
dos, gana Anthropic. Para forzar uno concreto, pon `anthropic` o `gemini` en
vez de `auto` — útil para volver a la capa gratuita a fin de mes sin borrar
ninguna clave.

**Tiempo de ejecución:** leer una factura tarda entre 5 y 20 segundos. Si PHP
tiene `max_execution_time=30`, una foto complicada puede cortarse:
```
sudo sed -i 's/^max_execution_time = .*/max_execution_time = 60/' /etc/php/8.2/fpm/php.ini
sudo systemctl reload php8.2-fpm
```
Nginx aguanta 60 s por defecto (`fastcgi_read_timeout`), así que con eso basta.

**Qué hace y qué no:** lee la factura y deja un borrador para revisar. Nunca
guarda nada solo — los precios se corrigen a mano y se elige con qué tasa
(BCV o paralelo) valorar los bolívares antes de crear la compra.

### Copia de seguridad de los comprobantes
Los recibos son evidencia de pagos y viven fuera de la base de datos. Para
respaldarlos hay que llevarse las dos cosas:
```bash
mysqldump whitewater > /ruta/backup/whitewater-$(date +%F).sql
tar czf /ruta/backup/whitewater-storage-$(date +%F).tar.gz \
  -C /var/www/whitewater/storage/app/public avatars receipts
```
