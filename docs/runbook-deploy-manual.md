# Runbook — Deploy manual a Hostinger

> Modalidad **manual**: vos copiás y pegás cada comando en tu terminal.
> Ningún comando toca carpetas fuera de los dos sitios de ITHub.
> Reemplazá los placeholders `<...>` antes de ejecutar.

## Placeholders que vas a usar en todo el runbook

| Placeholder | Ejemplo | De dónde sale |
|---|---|---|
| `<SSH_USER>` | `u123456789` | hPanel → Avanzado → SSH |
| `<SSH_HOST>` | `185.xxx.xxx.xxx` | ídem |
| `<SSH_PORT>` | `65002` | ídem |
| `<DB_NAME>` | `u123456789_ithub` | hPanel → Bases de datos |
| `<DB_USER>` | `u123456789_ithubapp` | ídem |
| `<DB_PASS>` | — | la que creaste (no la escribas en ningún chat) |

Rutas fijas en el server (Hostinger crea una carpeta por sitio):

```
~/domains/apithub.intellihelp.tech/     ← sitio del API
~/domains/ithub.intellihelp.tech/       ← sitio del frontend
```

---

# PARTE A — Backend (API)

## A1. Conectarse y verificar el entorno

```bash
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>
```

Adentro del server:

```bash
php -v          # necesita 8.2+. Si muestra 8.1 o menos: hPanel → Avanzado → Configuración PHP → 8.2
which composer git
mysql --version
ls ~/domains/
```

**No sigas si `php -v` no es 8.2+.**

## A2. Clonar el repo (fuera del web root)

```bash
cd ~/domains/apithub.intellihelp.tech
git clone https://github.com/nicobaldelli/ITHub.git app
ls app/api    # debe listar composer.json, public/, src/, etc.
```

## A3. Instalar dependencias PHP

```bash
cd ~/domains/apithub.intellihelp.tech/app/api
composer install --no-dev --optimize-autoloader
```

Si `composer` no existe, probar `composer2` o `php /usr/bin/composer`.

## A4. Generar secretos y armar el `.env`

Generar (anotalos en un lugar seguro, se usan en el paso siguiente):

```bash
openssl rand -base64 32    # → JWT_SECRET
openssl rand -hex 32       # → CRON_TOKEN
```

Crear el `.env`:

```bash
cd ~/domains/apithub.intellihelp.tech/app/api
cp .env.example .env
nano .env
```

Editar SOLO estas líneas (el resto ya viene con los valores productivos):

```env
DB_NAME=<DB_NAME>
DB_USER=<DB_USER>
DB_PASS=<DB_PASS>
JWT_SECRET=<lo generado con openssl rand -base64 32>
CRON_TOKEN=<lo generado con openssl rand -hex 32>
```

> `DB_MIGRATE_USER` / `DB_MIGRATE_PASS` se dejan vacíos: Phinx cae al
> user principal. Si más adelante el panel permite un segundo usuario con
> permisos DDL separados, se agrega ahí.

Proteger el archivo:

```bash
chmod 0640 .env
```

## A5. Migraciones + seed

```bash
cd ~/domains/apithub.intellihelp.tech/app/api
vendor/bin/phinx migrate -c db/phinx.php
vendor/bin/phinx seed:run -c db/phinx.php
```

⚠️ El seed **imprime la password temporal del admin una sola vez**. Anotala.

Verificación:

```bash
vendor/bin/phinx status -c db/phinx.php | tail -5   # todas las migraciones con estado "up"
```

## A6. Permisos de storage

```bash
cd ~/domains/apithub.intellihelp.tech/app/api
mkdir -p storage/logs storage/exports storage/imports storage/credentials storage/ratelimit
chmod 0750 storage storage/logs storage/exports storage/imports storage/credentials storage/ratelimit
```

## A7. Apuntar el web root a `api/public`

El document root del sitio es `public_html`, pero el API se sirve desde
`app/api/public`. Truco estándar: reemplazar `public_html` por un symlink.

```bash
cd ~/domains/apithub.intellihelp.tech
mv public_html public_html_original      # backup de lo que haya
ln -s app/api/public public_html
ls -la                                    # public_html -> app/api/public
```

**Si el symlink no funciona** (error 403 al probar en A8): plan B —

```bash
cd ~/domains/apithub.intellihelp.tech
rm public_html && mv public_html_original public_html
cp app/api/public/.htaccess app/api/public/index.php public_html/
nano public_html/index.php
# cambiar la línea del require a:
#   require __DIR__ . '/../app/api/vendor/autoload.php';
# y la línea del bootstrap a:
#   $app = (new App(__DIR__ . '/../app/api'))->build();
```

## A8. Probar

Desde tu PC (no desde el server):

```bash
curl -s https://apithub.intellihelp.tech/api/v1/health
# Esperado: {"data":{"status":"ok","db":"up",...}}
```

Y verificar que nada sensible quede expuesto:

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://apithub.intellihelp.tech/.env          # 403/404
curl -s -o /dev/null -w "%{http_code}\n" https://apithub.intellihelp.tech/composer.json # 403/404
```

---

# PARTE B — Frontend (Web)

## B1. Build local (en tu PC, no en el server)

```bash
cd /ruta/a/ITHub/web
git pull origin master
echo 'NEXT_PUBLIC_API_URL=https://apithub.intellihelp.tech/api/v1' > .env.production
pnpm install
pnpm build
ls out/   # debe existir index.html + _next/
```

## B2. Subir el build

```bash
scp -P <SSH_PORT> -r out/* out/.htaccess <SSH_USER>@<SSH_HOST>:~/domains/ithub.intellihelp.tech/public_html/ 2>/dev/null || \
scp -P <SSH_PORT> -r out/* <SSH_USER>@<SSH_HOST>:~/domains/ithub.intellihelp.tech/public_html/
```

## B3. `.htaccess` del frontend

En el server (`ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>`):

```bash
cat > ~/domains/ithub.intellihelp.tech/public_html/.htaccess << 'EOF'
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# SPA fallback
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.html [L]

<FilesMatch "\.(js|css|woff2|webp|png|jpg|svg|ico)$">
  Header set Cache-Control "public, max-age=31536000, immutable"
</FilesMatch>

<IfModule mod_headers.c>
  Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
  Header always set X-Content-Type-Options "nosniff"
  Header always set X-Frame-Options "DENY"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
Options -Indexes
EOF
```

## B4. Probar

Browser: `https://ithub.intellihelp.tech` → pantalla de login.
Login con `nbaldelli@intellihelp.tech` + password del seed (paso A5) →
te va a forzar cambio de password → dashboard.

---

# PARTE C — Post-deploy

## C1. Cron diario

hPanel → Avanzado → Cron Jobs → crear:

```
Frecuencia: 0 9 * * *
Comando:
/usr/bin/php /home/<SSH_USER>/domains/apithub.intellihelp.tech/app/api/scripts/cron_diario.php >> /home/<SSH_USER>/domains/apithub.intellihelp.tech/cron.log 2>&1
```

## C2. Google Drive (para adjuntos y "marcar enviada")

Seguir `docs/google-drive-setup.md`. En resumen:

```bash
# Desde tu PC:
scp -P <SSH_PORT> service-account.json <SSH_USER>@<SSH_HOST>:~/domains/apithub.intellihelp.tech/app/api/storage/credentials/
# En el server:
chmod 0600 ~/domains/apithub.intellihelp.tech/app/api/storage/credentials/service-account.json
```

Y en la app: `/configuracion` → `drive_root_folder_id`.

## C3. SMTP

Configurable desde la UI: `/configuracion` → grupo SMTP. Probar con el
botón "Enviar recordatorios".

## C4. Smoke test final

| # | Test |
|---|---|
| 1 | `curl https://apithub.intellihelp.tech/api/v1/health` → ok |
| 2 | Login + cambio de password forzado |
| 3 | F5 estando logueado → sesión persiste |
| 4 | Crear cliente (CUIT válido) |
| 5 | Crear servicio mantenimiento con template |
| 6 | `/configuracion` → "Facturar cuotas vencidas" → aparece factura AUTO |
| 7 | Marcar enviada con PDF → aparece en Drive |
| 8 | Exportar facturas a Excel |
| 9 | `https://apithub.intellihelp.tech/.env` → 403/404 |
| 10 | securityheaders.com → grado A |

## Updates posteriores (ciclo normal)

```bash
# Backend:
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>
cd ~/domains/apithub.intellihelp.tech/app
git pull origin master
cd api && composer install --no-dev --optimize-autoloader
vendor/bin/phinx migrate -c db/phinx.php   # solo si hay migraciones nuevas

# Frontend (desde tu PC):
cd /ruta/a/ITHub/web && git pull && pnpm build
scp -P <SSH_PORT> -r out/* <SSH_USER>@<SSH_HOST>:~/domains/ithub.intellihelp.tech/public_html/
```
