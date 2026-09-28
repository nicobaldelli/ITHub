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
| `<DB_NAME>` | `u123456789_ithub` | hPanel → Bases de datos (paso A0) |
| `<DB_USER>` | `u123456789_ithubapp` | ídem |
| `<DB_PASS>` | — | la que creaste (no la escribas en ningún chat) |
| `<PHP_BIN>` | `/opt/alt/php82/usr/bin/php` | lo devuelve `which php` en A1 |

Rutas fijas en el server (Hostinger crea una carpeta por sitio):

```
~/domains/apithub.intellihelp.tech/     ← sitio del API
~/domains/ithub.intellihelp.tech/       ← sitio del frontend
```

---

# PARTE A — Backend (API)

## A0. Prerrequisitos en hPanel (una sola vez, antes de tocar la terminal)

1. **Base de datos**: hPanel → Bases de datos → MySQL → crear base + usuario.
   Anotá `<DB_NAME>`, `<DB_USER>`, `<DB_PASS>`. Hostinger da al usuario todos
   los privilegios sobre esa base (alcanza para migrar y para runtime).
2. **PHP 8.2**: hPanel → Avanzado → Configuración PHP → versión **8.2** para
   ambos subdominios. En la misma pantalla, pestaña de opciones/`php.ini`:
   - `upload_max_filesize = 30M`
   - `post_max_size = 30M`
   - `memory_limit = 256M`
   (el PDF de "marcar enviada" admite hasta 25 MB; los exports a Excel usan memoria).
3. **SSL**: hPanel → Seguridad → SSL → emitir Let's Encrypt para
   `apithub.intellihelp.tech` **y** `ithub.intellihelp.tech`, con "Forzar HTTPS".
   Sin esto el login no funciona (`COOKIE_SECURE=true` + `SameSite=Strict`).
4. **SSH**: hPanel → Avanzado → SSH → habilitar y cargar tu llave pública.

## A1. Conectarse y verificar el entorno

```bash
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>
```

Adentro del server:

```bash
php -v          # necesita 8.2+. Si muestra otra: repasar A0 punto 2
which php       # ← este es tu <PHP_BIN>, lo vas a usar en el cron (C1)
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

> Si el repo ya es privado cuando hagas esto, usá un
> [token de acceso personal](https://github.com/settings/tokens) (scope `repo`)
> como password en el clone, o cargá una deploy key en el repo.

## A3. Instalar dependencias PHP

```bash
cd ~/domains/apithub.intellihelp.tech/app/api
composer install --no-dev --optimize-autoloader
```

Si `composer` no existe, probar `composer2` o `php /usr/bin/composer`.

El `post-install-cmd` limpia `google/apiclient-services` dejando solo Drive
(evita ~20.000 archivos que cuentan contra el límite de inodos del hosting).

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
DB_MIGRATE_USER=
DB_MIGRATE_PASS=
JWT_SECRET=<lo generado con openssl rand -base64 32>
CRON_TOKEN=<lo generado con openssl rand -hex 32>
```

> `DB_MIGRATE_USER` / `DB_MIGRATE_PASS` **tienen que quedar vacíos** (el
> `.env.example` trae `ithub_migrate` como ejemplo): Phinx cae al user
> principal cuando están vacíos. Si más adelante el panel permite un segundo
> usuario con permisos DDL separados, se cargan ahí.

Proteger el archivo:

```bash
chmod 0640 .env
```

## A5. Migraciones + seed

```bash
cd ~/domains/apithub.intellihelp.tech/app/api
vendor/bin/phinx migrate -c db/phinx.php
SEED_ADMIN_EMAIL=nbaldelli@intellihelp.tech SEED_ADMIN_NOMBRE=Nicolas SEED_ADMIN_APELLIDO=Baldelli \
  vendor/bin/phinx seed:run -c db/phinx.php
```

⚠️ El seed **imprime la password temporal del admin una sola vez**. Anotala.
Si no pasás `SEED_ADMIN_EMAIL`, el admin se crea como `admin@intellihelp.tech`.

Verificación:

```bash
vendor/bin/phinx status -c db/phinx.php | tail -5   # todas las migraciones con estado "up"
```

## A6. Permisos de storage

```bash
cd ~/domains/apithub.intellihelp.tech/app/api
mkdir -p storage/logs storage/cache storage/exports storage/imports storage/credentials storage/ratelimit
chmod 0750 storage storage/logs storage/cache storage/exports storage/imports storage/credentials storage/ratelimit
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

**Si da 500** al probar en A8: lo más probable es la línea `Options ...` del
`.htaccess` (algunos planes no permiten `Options` en override). Comentarla:

```bash
sed -i 's/^Options -Indexes -MultiViews +FollowSymLinks/# &/' ~/domains/apithub.intellihelp.tech/app/api/public/.htaccess
```

## A8. Probar

Desde tu PC (no desde el server):

```bash
curl -s https://apithub.intellihelp.tech/api/v1/health
# Esperado: {"data":{"status":"ok","db":"up",...}}

# Un 401 acá confirma que el header Authorization llega a PHP:
curl -s -o /dev/null -w "%{http_code}\n" -H "Authorization: Bearer x" https://apithub.intellihelp.tech/api/v1/clientes
# Esperado: 401 (si diera 401 también sin header, igual está bien; lo que NO debe dar es 500)
```

Y verificar que nada sensible quede expuesto (todo debe dar 403 o 404):

```bash
for p in .env composer.json vendor/autoload.php storage/exports/ ../app/api/.env; do
  printf "%-22s " "$p"; curl -s -o /dev/null -w "%{http_code}\n" "https://apithub.intellihelp.tech/$p"
done
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
ls -a out/   # debe existir index.html, 404.html, _next/ y .htaccess
```

> El build **falla a propósito** si `NEXT_PUBLIC_API_URL` no está definida,
> para no subir un bundle apuntando a localhost.
> El `.htaccess` sale de `web/public/.htaccess` (versionado): Next lo copia a
> `out/` en cada build.

## B2. Subir el build

El `out/.` (con punto) incluye los dotfiles como `.htaccess`:

```bash
scp -P <SSH_PORT> -r out/. <SSH_USER>@<SSH_HOST>:~/domains/ithub.intellihelp.tech/public_html/
```

## B3. Verificar en el server

```bash
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST> 'ls -a ~/domains/ithub.intellihelp.tech/public_html/ | head'
# debe listar .htaccess, index.html, 404.html, _next
```

## B4. Probar

Browser: `https://ithub.intellihelp.tech` → pantalla de login.
Login con el email del seed (`nbaldelli@intellihelp.tech` si pasaste
`SEED_ADMIN_EMAIL` en A5) + password temporal → te va a forzar cambio de
password → dashboard.

Probar también `https://ithub.intellihelp.tech/loquesea/` → debe mostrar la
página 404 de la app (no la de Apache/LiteSpeed).

---

# PARTE C — Post-deploy

## C1. Cron diario

hPanel → Avanzado → Cron Jobs → crear. `<PHP_BIN>` es lo que devolvió
`which php` en A1 (**no** `/usr/bin/php`, que puede ser otra versión):

```
Frecuencia: 0 9 * * *
Comando:
<PHP_BIN> /home/<SSH_USER>/domains/apithub.intellihelp.tech/app/api/scripts/cron_diario.php >> /home/<SSH_USER>/domains/apithub.intellihelp.tech/cron.log 2>&1
```

Probar a mano una vez desde SSH y mirar la salida JSON:

```bash
<PHP_BIN> ~/domains/apithub.intellihelp.tech/app/api/scripts/cron_diario.php
```

El script usa un lock (`storage/cache/cron_diario.lock`): si se solapa con otra
corrida, la segunda se salta sola.

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

Configurable desde la UI: `/configuracion` → grupo SMTP (`smtp_pass` no se
vuelve a mostrar una vez guardada, solo se puede reemplazar). Probar con el
botón "Enviar recordatorios" (pide confirmación).

## C4. Smoke test final

| # | Test |
|---|---|
| 1 | `curl https://apithub.intellihelp.tech/api/v1/health` → ok |
| 2 | Login + cambio de password forzado |
| 3 | F5 estando logueado → sesión persiste (y navegar atrás/adelante también) |
| 4 | Crear cliente (CUIT válido) |
| 5 | Crear servicio mantenimiento con template |
| 6 | `/configuracion` → "Facturar cuotas vencidas" → confirma → aparece factura AUTO |
| 7 | Marcar enviada con PDF → aparece en Drive |
| 8 | Exportar facturas a Excel |
| 9 | `https://apithub.intellihelp.tech/.env` → 403/404 |
| 10 | `https://ithub.intellihelp.tech/loquesea/` → 404 de la app |
| 11 | securityheaders.com → grado A en ambos subdominios |

## C5. Después del deploy

- Hacer **privado** el repo en GitHub (Settings → General → Danger Zone).
  Ojo: si el clone de A2 se hizo por HTTPS sin token, los `git pull` futuros
  van a pedir credenciales; ver nota en A2.
- Rotar cualquier password que se haya reutilizado desde el historial del repo.

---

# Updates posteriores (ciclo normal)

## Backend

```bash
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>
cd ~/domains/apithub.intellihelp.tech/app/api

# 1. Backup de la base ANTES de tocar nada (queda fuera del web root)
mkdir -p ~/backups
mysqldump -u <DB_USER> -p --single-transaction <DB_NAME> | gzip > ~/backups/ithub-$(date +%F-%H%M).sql.gz
ls -lh ~/backups | tail -3

# 2. Código + dependencias
cd ~/domains/apithub.intellihelp.tech/app
git pull origin master
cd api && composer install --no-dev --optimize-autoloader

# 3. Invalidar el container compilado (PHP-DI cachea config/container.php en prod)
rm -rf storage/cache/CompiledContainer.php storage/cache/*.php

# 4. Migraciones y seed (idempotentes: solo aplican lo nuevo)
vendor/bin/phinx migrate -c db/phinx.php
vendor/bin/phinx seed:run -c db/phinx.php -s ConfigAppSeeder   # solo si se agregaron claves de config

# 5. Verificar
curl -s https://apithub.intellihelp.tech/api/v1/health
```

## Frontend (desde tu PC)

```bash
cd /ruta/a/ITHub/web
git pull origin master
pnpm install          # por si cambió package.json
pnpm build            # usa .env.production que ya creaste en B1

# Limpiar los chunks viejos de _next antes de subir (los hashes cambian en cada build)
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST> 'rm -rf ~/domains/ithub.intellihelp.tech/public_html/_next'
scp -P <SSH_PORT> -r out/. <SSH_USER>@<SSH_HOST>:~/domains/ithub.intellihelp.tech/public_html/
```

## Rollback

```bash
# Backend: volver al commit anterior
cd ~/domains/apithub.intellihelp.tech/app
git log --oneline -5                    # elegir el commit
git checkout <commit>
cd api && composer install --no-dev --optimize-autoloader && rm -rf storage/cache/*.php

# Si la migración nueva rompió algo: deshacer la última
vendor/bin/phinx rollback -c db/phinx.php

# Si hay que restaurar la base completa desde el backup del paso 1
gunzip < ~/backups/ithub-<fecha>.sql.gz | mysql -u <DB_USER> -p <DB_NAME>
```

> Alternativa de datos (no de esquema): la app tiene en `/configuracion` una
> copia de seguridad JSON (export/import) para mover los datos entre entornos
> con la misma versión de migraciones.
