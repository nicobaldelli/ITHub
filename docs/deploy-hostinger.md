# Deploy a Hostinger — ITHub

Guía paso a paso para deployar ITHub en Hostinger usando Business o Cloud.
Asume hosting compartido con SSH habilitado (Business plan o superior) y
dos subdominios separados.

---

## 0. Pre-requisitos

| Recurso | Valor |
|---|---|
| Plan Hostinger | Business o Cloud (necesario para SSH + cron) |
| Dominio | `intellihelp.tech` (o el que tengas) |
| Subdominio API | `api.ithub.intellihelp.tech` |
| Subdominio Web | `ithub.intellihelp.tech` |
| PHP | 8.2+ (configurable en hPanel) |
| MySQL | 8.0+ |
| Acceso | SSH habilitado, FTP de respaldo |

Antes de empezar tené a mano:
- Credenciales SSH (host, puerto, user, pass o llave)
- Credenciales MySQL (host, db, user, pass — Hostinger los muestra al crear la DB)
- `JWT_SECRET` y `CRON_TOKEN` ya generados (ver paso 2.4)
- `service-account.json` de Google Drive si vas a usar adjuntos (ver `google-drive-setup.md`)
- Credenciales SMTP si vas a usar recordatorios

---

## 1. DNS y subdominios

En el hPanel de Hostinger:

1. **Dominios → Subdominios**
2. Crear `ithub` apuntando a una nueva carpeta (`/public_html/ithub-web/`)
3. Crear `api.ithub` apuntando a otra (`/public_html/ithub-api/public/` — ojo: `/public` es el web root del backend)

Esperar 5–15 min a que se propague el DNS.

---

## 2. Backend (API)

### 2.1 Subir el código

Por SSH:

```bash
# Conectarse a Hostinger
ssh u123456789@<tu-host-hostinger.com>

cd ~/domains/intellihelp.tech/public_html/ithub-api
git clone https://github.com/nicobaldelli/ITHub.git .
# (o git pull si ya está clonado)
```

> **Importante:** el web root debe apuntar a `ithub-api/public/`, no a la raíz del repo. Esto se configura en hPanel cuando creás el subdominio `api.ithub`.

### 2.2 Instalar dependencias

Hostinger trae `composer` instalado:

```bash
cd ~/domains/intellihelp.tech/public_html/ithub-api/api
composer install --no-dev --optimize-autoloader
```

### 2.3 Crear las bases de datos

En hPanel → **Bases de datos → MySQL**:

1. Crear DB `u123456789_ithub` (o el prefijo que te asigna Hostinger)
2. Crear **dos** usuarios:
   - `u123456789_ithub_app` con permisos `SELECT, INSERT, UPDATE, DELETE` sobre la DB (este es el de runtime)
   - `u123456789_ithub_mig` con permisos `ALL` sobre la DB (este solo lo usamos para migraciones via SSH, no queda en el `.env`)
3. Anotar host de MySQL (suele ser `127.0.0.1` o `localhost` interno)

### 2.4 Configurar el `.env`

```bash
cd ~/domains/intellihelp.tech/public_html/ithub-api/api
cp .env.example .env
nano .env
```

Valores críticos:

```env
APP_ENV=production
APP_DEBUG=false

# DB de runtime (privilegios mínimos)
DB_HOST=localhost
DB_PORT=3306
DB_NAME=u123456789_ithub
DB_USER=u123456789_ithub_app
DB_PASSWORD=<password-fuerte>

# JWT — generar con: openssl rand -base64 32
JWT_SECRET=<32+ chars random>
JWT_ISSUER=https://api.ithub.intellihelp.tech
JWT_AUDIENCE=https://ithub.intellihelp.tech

# Cookies
COOKIE_DOMAIN=.ithub.intellihelp.tech
COOKIE_SECURE=true
COOKIE_SAMESITE=Strict

# CORS
FRONTEND_URL=https://ithub.intellihelp.tech

# Cron — generar con: openssl rand -hex 32
CRON_TOKEN=<64 chars hex>
CRON_ALLOWED_IPS=127.0.0.1,::1

# Google Drive (opcional, si vas a usar adjuntos)
GOOGLE_SERVICE_ACCOUNT_JSON_PATH=storage/credentials/service-account.json
GOOGLE_IMPERSONATE_USER=

# SMTP (opcional, si vas a usar recordatorios — preferí editar desde /configuracion en la UI)
SMTP_HOST=
SMTP_PORT=587
SMTP_USER=
SMTP_PASS=
SMTP_FROM=facturacion@intellihelp.tech
SMTP_FROM_NAME=ITHub Facturación
SMTP_ENCRYPTION=tls
```

Permisos:

```bash
chmod 0640 .env
```

### 2.5 Correr las migraciones

Con el usuario de migración (NO el de runtime):

```bash
cd ~/domains/intellihelp.tech/public_html/ithub-api/api
# Phinx requiere una conexión con privilegios DDL — usamos el mig user temporalmente.
# Opcion A (mas simple): editar .env y poner el user mig solo para esta corrida.
# Opcion B (mas prolija): export PHINX_DB_USER + PHINX_DB_PASSWORD y leer en phinx.php

vendor/bin/phinx migrate
vendor/bin/phinx seed:run
```

> El seed muestra una password aleatoria para el admin inicial. **Anotala** — es la que vas a usar en el primer login.

Después de migrar, **restaurar** el user runtime en el `.env`.

### 2.6 Permisos de storage

```bash
mkdir -p storage/logs storage/exports storage/imports storage/credentials storage/ratelimit
chmod 0750 storage storage/*
```

Si vas a usar Google Drive, subí el `service-account.json`:

```bash
# Desde tu máquina:
scp service-account.json u123456789@<host>:~/domains/intellihelp.tech/public_html/ithub-api/api/storage/credentials/

# En Hostinger:
chmod 0600 storage/credentials/service-account.json
```

### 2.7 `.htaccess` y `php.ini`

El repo ya trae `api/.htaccess` con bloqueos básicos. Verificá que tu hosting respete `AllowOverride All`.

En hPanel → **Avanzado → PHP** ajustar (si no están así por defecto):

```ini
display_errors = Off
expose_php = Off
upload_max_filesize = 30M
post_max_size = 30M
memory_limit = 256M
max_execution_time = 60
```

### 2.8 Probar

```bash
curl https://api.ithub.intellihelp.tech/api/v1/health
# Esperado: {"data":{"status":"ok","db":"up","time":"..."}}
```

---

## 3. Frontend (Web)

### 3.1 Build del Static Export

En tu **máquina local** (no en Hostinger):

```bash
cd web
pnpm install
echo 'NEXT_PUBLIC_API_URL=https://api.ithub.intellihelp.tech/api/v1' > .env.production
pnpm build
```

Esto deja `out/` con HTML + JS + CSS estáticos.

### 3.2 Subir `out/` a Hostinger

Por FTP, scp, o el File Manager:

```bash
# Por scp:
scp -r out/* u123456789@<host>:~/domains/intellihelp.tech/public_html/ithub-web/
```

### 3.3 `.htaccess` del frontend

Crear `~/domains/intellihelp.tech/public_html/ithub-web/.htaccess`:

```apache
# Forzar HTTPS
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# SPA fallback: rutas que no son archivos físicos van a index.html
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.html [L]

# Cache estática agresiva para los assets de Next
<FilesMatch "\.(js|css|woff2|webp|png|jpg|svg|ico)$">
  Header set Cache-Control "public, max-age=31536000, immutable"
</FilesMatch>

# HSTS
<IfModule mod_headers.c>
  Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
  Header always set X-Content-Type-Options "nosniff"
  Header always set X-Frame-Options "DENY"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
```

### 3.4 SSL

En hPanel → **SSL** → emitir certificados Let's Encrypt para ambos subdominios (`ithub.intellihelp.tech` y `api.ithub.intellihelp.tech`). Marcar "redirigir HTTP a HTTPS".

### 3.5 Probar

Visitar `https://ithub.intellihelp.tech` — debería cargar la pantalla de login.

---

## 4. Cron

En hPanel → **Avanzado → Cron Jobs**:

| Frecuencia | Comando | Descripción |
|---|---|---|
| `0 9 * * *` | `/usr/bin/php /home/u123456789/domains/intellihelp.tech/public_html/ithub-api/api/scripts/cron_diario.php >> ~/logs/ithub-cron.log 2>&1` | Cron diario unificado: recalcular vencidas, rolling window, recordatorios |

Alternativa HTTP (si Hostinger no te deja PHP CLI):

```bash
0 9 * * * curl -X POST https://api.ithub.intellihelp.tech/api/v1/cron/diario \
  -H "X-Cron-Token: <TU_CRON_TOKEN>" >> ~/logs/ithub-cron.log 2>&1
```

> Si usás HTTP, agregá la IP saliente de Hostinger a `CRON_ALLOWED_IPS` en `.env`. La conseguís con:
> ```bash
> curl https://ifconfig.me
> ```

---

## 5. Primer login y cambio de password

1. Ir a `https://ithub.intellihelp.tech/login`
2. User: `nbaldelli@intellihelp.tech` (el del seed)
3. Password: la que generó el seed en el paso 2.5
4. La app redirige a `/cambiar-password` (porque el seed deja `must_change_password=true`)
5. Cambiar a una password fuerte propia
6. A partir de ahí, login normal

---

## 6. Crear los primeros usuarios

Desde `/usuarios` (admin):
- "Nuevo usuario" → completar datos
- El sistema genera una password temporal y la muestra UNA SOLA VEZ
- Compartirla con el usuario por canal seguro
- El usuario al loguearse va a estar obligado a cambiarla

---

## 7. Verificación post-deploy (smoke test)

| # | Test | URL |
|---|---|---|
| 1 | Health del API | `curl https://api.ithub.intellihelp.tech/api/v1/health` |
| 2 | Login funciona | login en `https://ithub.intellihelp.tech/login` |
| 3 | Sesión persiste al reload | F5 estando logueado, no te tira al login |
| 4 | Crear cliente | `/clientes/nuevo` |
| 5 | Crear factura | `/facturas/nueva` |
| 6 | Crear servicio (proyecto) | `/servicios/nuevo` |
| 7 | Crear servicio (mantenimiento) | `/servicios/nuevo` |
| 8 | Facturar cuota de servicio | desde el detalle del servicio → tab Cronograma → menú "Facturar" |
| 9 | Exportar facturas Excel | botón "Excel" en `/facturas` |
| 10 | Probar cron manual | `/configuracion` → botón "Cron diario" |
| 11 | (Opcional) Subir adjunto | `/facturas/[id]` → "Subir archivo" |
| 12 | SSL Labs grado A+ | https://www.ssllabs.com/ssltest/ |
| 13 | Security headers grado A | https://securityheaders.com/ |

---

## 8. Backups

Configurar backup diario:

```bash
# En crontab:
0 3 * * * /home/u123456789/scripts/ithub-backup.sh
```

Script sugerido `~/scripts/ithub-backup.sh`:

```bash
#!/bin/bash
set -e
DATE=$(date +%Y%m%d-%H%M)
BACKUP_DIR=~/backups/ithub
mkdir -p $BACKUP_DIR

# Dump DB
mysqldump -u u123456789_ithub_app -p"$DB_PASSWORD" u123456789_ithub \
  --single-transaction --routines --triggers \
  | gzip > $BACKUP_DIR/ithub-$DATE.sql.gz

# Borrar backups > 7 días
find $BACKUP_DIR -name "ithub-*.sql.gz" -mtime +7 -delete
```

Recomendado: copiar los backups a Google Drive o S3 con `rclone` (también via cron).

---

## 9. Troubleshooting

### "500 Internal Server Error" al cargar el frontend
- Verificar que `out/index.html` esté en `~/domains/.../public_html/ithub-web/`
- Verificar `.htaccess` con el SPA fallback

### "CORS error" en la consola del browser
- Verificar `FRONTEND_URL` en `api/.env`
- Verificar que el subdominio del API tenga SSL (Cookies con `SameSite=Strict` requieren HTTPS)

### "CSRF_INVALID" después de login
- Verificar `COOKIE_DOMAIN=.ithub.intellihelp.tech` (con el punto adelante)
- Verificar que ambos subdominios estén en HTTPS

### "PHP version 8.0 detected" al instalar
- En hPanel → Avanzado → PHP elegir 8.2

### El cron no manda mails
- Verificar `smtp_host` y `smtp_from` en `/configuracion`
- Probar manualmente desde `/configuracion` → "Cron diario"
- Revisar `~/logs/ithub-cron.log`

### Drive da "no disponible"
- Verificar que `storage/credentials/service-account.json` esté presente y con permisos `0600`
- Verificar `drive_root_folder_id` en `/configuracion` (ver `google-drive-setup.md`)

---

## 10. Updates posteriores

Para deployar cambios nuevos:

```bash
# Backend
ssh u123456789@<host>
cd ~/domains/intellihelp.tech/public_html/ithub-api
git pull origin master
cd api
composer install --no-dev --optimize-autoloader
# Si hay migraciones nuevas:
vendor/bin/phinx migrate

# Frontend (en tu máquina local)
cd web
pnpm install
pnpm build
scp -r out/* u123456789@<host>:~/domains/intellihelp.tech/public_html/ithub-web/
```

---

## 11. Checklist de seguridad post-deploy

- [ ] `.env` con permisos 0640
- [ ] `storage/credentials/` con permisos 0700, archivos 0600
- [ ] `APP_DEBUG=false`
- [ ] `display_errors = Off` en PHP
- [ ] SSL forzado (HSTS activo)
- [ ] Carpetas `vendor/`, `db/`, `config/`, `src/`, `storage/` NO accesibles por HTTP
   (verificar con `curl https://api.ithub.intellihelp.tech/vendor/autoload.php` → debe dar 403/404)
- [ ] Adminer/phpMyAdmin no expuesto al público
- [ ] Test de SSL Labs grado A+
- [ ] Test de securityheaders.com grado A o mejor
- [ ] Backup diario verificado (que el archivo se generó)
- [ ] Restauración de backup probada en un staging o local
