# Ingreso con ITHub (sesión única de Intellihelp)

ITHub (`https://ithub.intellihelp.tech`) es el portal de Intellihelp y el
proveedor de identidad de las apps internas (OpenID Connect propio). Con ITHub
configurado, Facturación **deja de tener login propio**: la pantalla de
ingreso manda sola a ITHub, ITHub ya validó la cuenta de Google y el dominio,
decide si la persona tiene acceso a Facturación y con qué rol, y vuelve a la
API con un código. Si la sesión de ITHub ya estaba abierta, se entra sin ver
ninguna pantalla.

Sigue sin existir el login con password. **Sin la configuración de ITHub en el
`.env`, todo sigue como antes**: login con Google Workspace
(`docs/google-login-setup.md`).

## Qué cambia

| Antes (Google) | Con ITHub |
|---|---|
| Botón de Google en la pantalla de ingreso; la API verifica el ID token | La pantalla de ingreso manda a ITHub; la API canjea el código y valida el id_token |
| El usuario tiene que existir en `/usuarios`, con su rol | ITHub decide quién entra y con qué rol (claim `rol`); el usuario se crea en el primer ingreso y el rol se actualiza en cada ingreso |
| Salir de Facturación cierra solo Facturación | Salir cierra la sesión única (todas las apps). Y si la sesión se cierra en ITHub o le quitan Facturación a la persona, ITHub avisa y la sesión de Facturación cae |
| `POST /auth/google` | Apagado (`GOOGLE_LOGIN_DISABLED`). Las sesiones abiertas antes por Google no se renuevan: hay que entrar una vez por ITHub |

La API sigue emitiendo **su** access token (JWT HS256 en memoria) y **su**
refresh (cookie HttpOnly `ithub_refresh` + CSRF `ithub_csrf`), igual que con
Google. El frontend no ve ningún token de ITHub ni el secreto.

## Flujo

1. `/login` del frontend consulta `GET /api/v1/auth/metodos`. Con
   `{"ithub": true}` redirige a `GET /api/v1/auth/ithub/login?next=<ruta>`
   (salvo que haya vuelto con `?aviso=` o se abra con `?manual=1`, que muestra
   el botón *Ingresar con ITHub*).
2. La API genera `state`, `nonce` y el verificador PKCE, los guarda en la
   cookie `ithub_oidc` (HttpOnly, firmada con HMAC, `SameSite=Lax`, 10 min,
   solo para `/api/v1/auth/ithub`) y redirige a
   `https://ithub.intellihelp.tech/oauth/authorize` con
   `code_challenge_method=S256`.
3. ITHub vuelve a `GET /api/v1/auth/ithub/callback?code=...&state=...`. La API:
   - compara el `state` con el de la cookie (y la borra: es de un solo uso);
   - canjea el código en `/oauth/token` con el client secret
     (`client_secret_basic`) y el verificador PKCE;
   - valida el id_token: RS256 con la clave del `kid` publicada en
     `/oauth/jwks` (en caché una hora en `storage/cache/ithub-jwks.json`; si
     el kid no está, se vuelve a bajar), `iss`, `aud` = `facturacion`,
     vencimiento (60 s de tolerancia) y `nonce`;
   - toma el rol del claim `rol` (tiene que ser `admin`, `ventas`,
     `cobranzas` o `visualizador`), crea el usuario si no existe o le aplica
     el rol y lo reactiva si existe (queda en auditoría);
   - abre la sesión como siempre y asocia la familia de refresh al `sid` de
     ITHub (tabla `ithub_sesiones`, con el id_token para la salida);
   - setea las cookies y redirige al frontend (`next` o `/dashboard/`), que
     recupera el access con `POST /auth/refresh` al cargar.
   Si algo falla vuelve a `/login/?aviso=<motivo>` (`ithub_sin_acceso`,
   `ithub_rol`, `ithub_estado`, `ithub_error`) y la pantalla muestra el
   mensaje sin redirigir sola.
4. **Salir**: `POST /auth/logout` revoca el refresh y devuelve `logout_url`
   (`/oauth/logout` de ITHub con `id_token_hint`); el frontend la sigue, ITHub
   cierra la sesión única sin preguntar y vuelve a `/login/`.
5. **Aviso de cierre** (back-channel logout): ITHub hace
   `POST /api/v1/auth/ithub/logout` con `logout_token` (form-urlencoded,
   server-to-server, sin cookies). La API lo valida igual que el id_token
   (sin nonce, con el evento de back-channel logout), marca cerradas las
   sesiones con ese `sid`, revoca sus refresh y, como el access token lleva el
   `sid`, `JwtAuthMiddleware` lo rechaza en el pedido siguiente
   (`ITHUB_SESION_CERRADA`). Responde 200, o 400 si el token no es válido.

## Configuración

### En ITHub (Aplicaciones → Facturación)

| Campo | Valor |
|---|---|
| Clave (`client_id`) | `facturacion` |
| Roles | `admin, ventas, cobranzas, visualizador` (el primero es el de más jerarquía); rol de super admin `admin` |
| URIs de vuelta | `https://apithub.intellihelp.tech/api/v1/auth/ithub/callback` |
| URIs después de salir | `https://facturacion.intellihelp.tech/login/` |
| Aviso de cierre | `https://apithub.intellihelp.tech/api/v1/auth/ithub/logout` |
| URL de la tarjeta | `https://facturacion.intellihelp.tech` |

El secreto (`ith_...`) se genera en ITHub y se muestra una sola vez.

### En `api/.env` del servidor

```env
ITHUB_CLIENT_ID=facturacion
ITHUB_CLIENT_SECRET=<secreto generado en ITHub; nunca en git ni en el frontend>
# Opcionales (estos son los valores por defecto):
# ITHUB_ISSUER=https://ithub.intellihelp.tech
# ITHUB_REDIRECT_URI=<APP_URL>/api/v1/auth/ithub/callback
# ITHUB_POST_LOGOUT_URI=<FRONTEND_URL>/login/
```

Después de tocar el `.env` o `config/container.php` hay que borrar el
container compilado (`rm -f storage/cache/CompiledContainer.php`) y correr la
migración de `ithub_sesiones` (`vendor/bin/phinx migrate -c db/phinx.php`).

El frontend no necesita nada nuevo: el mismo build funciona con o sin ITHub.

### Usuarios

Antes de activar, cada usuario activo de Facturación tiene que tener permiso
en ITHub para la app `facturacion` con el mismo rol (si no, ITHub no lo deja
pasar). Los super admins de ITHub entran a todas las apps con el rol de super
admin (`admin`) salvo que tengan un permiso explícito.

En `/usuarios` el rol se sigue viendo, pero lo manda ITHub: se pisa con el de
ITHub en cada ingreso.

## Volver al login con Google

Borrar (o vaciar) `ITHUB_CLIENT_ID` / `ITHUB_CLIENT_SECRET` del `.env` y el
container compilado. `/auth/metodos` pasa a responder `{"ithub": false}` y la
pantalla de ingreso vuelve a mostrar el botón de Google. La tabla
`ithub_sesiones` puede quedar.
