# Login con Google (Workspace, solo @intellihelp.tech)

El **único** acceso a ITHub es con la cuenta de Google Workspace del dominio.
No existe login con email + password (se sacaron el endpoint, el formulario,
el cambio de password y el reset). Sin client id configurado **nadie puede
entrar**: la pantalla de login muestra que el acceso no está configurado.

## Cómo funciona

1. El front carga el botón oficial de Google ("Sign in with Google", Google
   Identity Services). El usuario elige su cuenta en un popup de Google.
2. Google le devuelve al navegador un **ID token** firmado (JWT) con el
   email, si está verificado y el dominio de Workspace (`hd`).
3. El front lo manda a `POST /api/v1/auth/google`.
4. La API verifica el token contra las claves públicas de Google (firma,
   vencimiento y que fue emitido para **nuestro** client id) y aplica las
   reglas de ITHub:
   - email verificado y cuenta de Workspace del dominio configurado
     (`GOOGLE_OAUTH_HOSTED_DOMAIN`, default `intellihelp.tech`). Un Gmail
     personal o una cuenta de otra empresa se rechaza.
   - el email **tiene que existir en ITHub como usuario activo**. No se crean
     usuarios solos: el admin los da de alta en `/usuarios` con su rol, igual
     que siempre. Si no existe, el mensaje le dice que pida el alta.
   - respeta el lockout: una cuenta bloqueada por intentos fallidos tampoco
     entra por Google.
5. Si pasa, abre la sesión (access token en memoria + refresh en cookie
   HttpOnly + CSRF), igual que siempre.

No se usa client secret ni se pide ningún permiso sobre Drive, Gmail, etc.:
solo identidad (email + nombre). No hay costo: Google Identity Services es
gratis para cualquier cantidad de usuarios.

Los usuarios se dan de alta en `/usuarios` solo con email `@intellihelp.tech`
(la API rechaza otros dominios). No se genera ni se muestra password: la
columna `password_hash` queda con un hash de un valor aleatorio solo porque es
NOT NULL.

**Sin camino de emergencia por password.** Si Google no responde o el client id
se rompe, no se puede entrar hasta resolverlo. Para recuperar el acceso de un
admin alcanza con que su usuario esté activo en la tabla `users` con el email
de Workspace correcto (y `locked_until` en NULL).

## Configuración en Google Cloud (una vez, ~10 minutos)

Usar el **mismo proyecto** de Google Cloud que la service account de Drive
(ver `docs/google-drive-setup.md`); si todavía no existe, crearlo con la
cuenta admin de Workspace.

### 1. Pantalla de consentimiento

Google Cloud Console → **APIs y servicios → Pantalla de consentimiento de
OAuth** (o "Google Auth Platform → Branding/Audience" en la consola nueva):

- Tipo de usuario: **Interno**. Esto solo está disponible en proyectos de
  una organización de Workspace y restringe el login a cuentas de la
  organización. Es una segunda barrera además del chequeo de dominio de la
  API; no hace falta verificación de app ni publicarla.
- Nombre de la app: `ITHub`. Email de soporte: el del admin.
- Dominio autorizado: `intellihelp.tech`.
- Scopes: no agregar ninguno (con el flujo de ID token alcanza con los
  básicos `openid`, `email`, `profile`, que se piden solos).

### 2. ID de cliente OAuth

**APIs y servicios → Credenciales → Crear credenciales → ID de cliente de
OAuth**:

- Tipo: **Aplicación web**.
- Nombre: `ITHub web`.
- **Orígenes de JavaScript autorizados**:
  - `https://ithub.intellihelp.tech`
  - `http://localhost:3000` (solo si se quiere probar en desarrollo)
- URIs de redirección autorizados: **ninguno** (el flujo es por popup, no
  hay redirect).

Guardar el **ID de cliente** (termina en `.apps.googleusercontent.com`). El
client secret que muestra la consola **no se usa**: no copiarlo a ningún
lado.

### 3. Variables de entorno

API (`api/.env`):

```ini
GOOGLE_OAUTH_CLIENT_ID=xxxxxxxx.apps.googleusercontent.com
GOOGLE_OAUTH_HOSTED_DOMAIN=intellihelp.tech
```

Web (`web/.env.production`, se compila dentro del bundle):

```ini
NEXT_PUBLIC_GOOGLE_CLIENT_ID=xxxxxxxx.apps.googleusercontent.com
NEXT_PUBLIC_GOOGLE_HOSTED_DOMAIN=intellihelp.tech
```

Es el **mismo** valor en los dos lados. El client id no es secreto (viaja en
el HTML de cualquier app que use Google Sign-In); lo que lo protege es la
lista de orígenes autorizados del paso 2 y la verificación de `aud` que hace
la API.

Después: rebuild del front (`pnpm build`) y subir `out/`. La API no necesita
nada más que el `.env` (no hay migración).

### 4. CSP

El `web/public/.htaccess` ya permite `https://accounts.google.com` en
`script-src`, `style-src`, `frame-src` y `connect-src`. Si en algún momento
se endurece la CSP, mantener esas entradas o el botón deja de renderizar
(aparece en blanco y la consola del browser muestra el bloqueo).

## Prueba

1. Abrir `https://ithub.intellihelp.tech/login`: debe aparecer solo el botón
   "Iniciar sesión con Google" (no hay formulario de email y password).
2. Con un usuario que **existe** en ITHub con su email de Workspace: entra y
   cae en el dashboard.
3. Con una cuenta @gmail.com: Google ya filtra por `hd` en el selector, y si
   igual llega un token, la API responde `GOOGLE_DOMAIN`.
4. Con una cuenta de Workspace que **no** está en `/usuarios`: mensaje
   "Tu cuenta de Google no está dada de alta en ITHub...". Crear el usuario
   con ese email exacto y reintentar.

En `/auditoria` cada login queda con `via: google`, y los rechazos como
`login_fallido` con el motivo (`google_dominio`, `google_usuario_inexistente`,
`google_email_no_verificado`).

## Problemas frecuentes

| Síntoma | Causa probable |
|---|---|
| No aparece el botón | `NEXT_PUBLIC_GOOGLE_CLIENT_ID` vacío al momento del `pnpm build` |
| Botón en blanco / error en consola `Refused to load` | CSP sin `accounts.google.com` |
| Popup de Google dice "Error 400: origin_mismatch" | Falta el origen `https://ithub.intellihelp.tech` en el client id |
| Popup dice "Acceso bloqueado: esta app no está verificada" | La pantalla de consentimiento quedó como "Externo"; tiene que ser "Interno" |
| API responde `GOOGLE_LOGIN_DISABLED` | `GOOGLE_OAUTH_CLIENT_ID` vacío en `api/.env` |
| API responde `GOOGLE_TOKEN_INVALID` | Client id distinto entre front y API, o el servidor no puede llegar a `www.googleapis.com` para bajar las claves públicas |
| `GOOGLE_USER_NOT_FOUND` | El email de Google no coincide exactamente con el del usuario en ITHub |

## Cortar el acceso

Vaciar `GOOGLE_OAUTH_CLIENT_ID` en la API corta el acceso de **todos** al
instante (`GOOGLE_LOGIN_DISABLED`). Para sacar a una sola persona, desactivarla
en `/usuarios`.
