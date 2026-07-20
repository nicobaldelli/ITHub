# Arquitectura de seguridad — ITHub

> Documento de referencia para todo el equipo de desarrollo. Cualquier cambio que altere las garantías acá descritas requiere revisión.

Este documento describe las medidas de seguridad aplicadas en ITHub y cómo se mapean a **OWASP Top 10 (2021)**. Está pensado para defensa en profundidad: ningún control depende de un único mecanismo.

---

## 1. Modelo de amenazas resumido

**Activos protegidos:**
- Datos de facturación (montos, CUIT, fechas, contactos de cobranza)
- Credenciales de usuarios de la plataforma
- Token de Service Account de Google Drive
- Credenciales SMTP
- Acceso a la base de datos

**Actores hostiles considerados:**
- Atacante externo no autenticado (escaneo, fuerza bruta, injection)
- Atacante autenticado con rol limitado (escalada de privilegios)
- Atacante con acceso de red en LAN (MITM, sniffing)
- Insider con acceso a hosting (exfiltración)
- Bots automatizados (scraping, scanning)

---

## 2. Autenticación

### 2.1 Almacenamiento de passwords
- **bcrypt cost 12** (`password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12])`)
- Rehash automático si baja el cost (`password_needs_rehash`)
- Nunca se loguea el password ni el hash

### 2.2 Política de complejidad
- Mínimo 12 caracteres
- Al menos 1 mayúscula, 1 minúscula, 1 dígito, 1 símbolo
- Bloqueo de los 1000 passwords más comunes (lista embebida)
- Validación tanto en frontend (UX) como en backend (autoridad)

### 2.3 JWT (access token)
- Algoritmo **HS256** (firma simétrica)
- Secret de **256 bits** generado con `random_bytes(32)`, almacenado en `.env`, rotable
- TTL **15 minutos**
- Claims: `sub` (user_id), `email`, `rol`, `iat`, `exp`, `jti`, `iss`, `aud`
- Validación obligatoria de `iss`, `aud`, `exp`, `nbf`
- Se transmite en header `Authorization: Bearer <jwt>` (nunca en URL ni en localStorage persistente)

### 2.4 Refresh token
- Token aleatorio de 256 bits (`random_bytes(32)`)
- **Guardado en DB como SHA-256** (`hash('sha256', $token)`)
- TTL **7 días**
- **Rotación obligatoria:** cada `/auth/refresh` invalida el refresh anterior y emite uno nuevo
- **Detección de reuso:** si llega un refresh ya marcado como `revoked_at`, se invalida toda la familia de tokens del usuario (posible robo) y se fuerza re-login
- Se transmite en **cookie HttpOnly + Secure + SameSite=Strict**, `Domain=apithub.intellihelp.tech`, `Path=/api/v1/auth`

### 2.5 Lockout / fuerza bruta
- **5 intentos fallidos** por email en 15 min → bloqueo temporal del email
- **20 intentos fallidos** por IP en 15 min → bloqueo de IP
- Registro de cada intento fallido en `auditoria` con acción `login_fallido`
- Respuestas genéricas: "Credenciales inválidas" (no se distingue entre email inexistente y password incorrecto)
- Delay constante de ~200ms en respuesta de login para mitigar timing attacks

### 2.6 Cambio forzado de password
- El usuario admin del seed inicial tiene flag `must_change_password = true`
- Al login: si flag activo, frontend redirige a `/cambiar-password` sin acceso al resto de la app

### 2.7 2FA TOTP (Fase 3)
- Tabla `users_2fa` opcional (no incluida en MVP)
- Algoritmo TOTP RFC 6238, 6 dígitos, ventana ±1
- Códigos de recuperación de un solo uso (10 códigos, hasheados)

---

## 3. Sesión en el cliente

### 3.1 Almacenamiento de tokens en navegador
| Token | Dónde | Por qué |
|---|---|---|
| Access JWT | Memoria (Zustand sin persistir) | Inmune a XSS persistente; se pierde al recargar pero se recupera automáticamente |
| Refresh | Cookie HttpOnly+Secure+SameSite=Strict | JavaScript no puede leerla; va sola en `/auth/refresh` |

> **No usamos `localStorage` ni `sessionStorage`** para tokens. La superficie de XSS en frontends modernos es demasiado grande para arriesgarse.

### 3.2 Flujo de recarga
1. Browser carga la app
2. `authStore` está vacío → llamada silenciosa `POST /auth/refresh` (cookie va sola)
3. Si 200 → access en memoria, continúa
4. Si 401 → redirige a `/login`

### 3.3 Logout
- `POST /auth/logout` → server marca `revoked_at` en el refresh actual y `Set-Cookie` con `Max-Age=0`
- Frontend limpia el `authStore` y redirige a `/login`
- `POST /auth/logout-all` revoca todos los refresh del usuario (panel de admin: "cerrar todas mis sesiones")

---

## 4. Transporte (HTTPS)

- **HTTPS obligatorio.** El backend redirige `http://` → `https://` con 301.
- **HSTS:** `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`
- TLS 1.2+ mínimo (configurar en Hostinger Panel → SSL)
- Certificados Let's Encrypt automáticos (Hostinger los provee)
- Renovación automática verificada mensualmente

---

## 5. Cabeceras de seguridad

Aplicadas globalmente por `SecurityHeadersMiddleware`:

```
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=()
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self' https://apithub.intellihelp.tech; frame-ancestors 'none'; base-uri 'self'; form-action 'self'
X-Permitted-Cross-Domain-Policies: none
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-site
```

Cabeceras removidas:
- `X-Powered-By` (PHP)
- `Server` (Apache, dejamos solo "Apache")

---

## 6. CORS

```
Access-Control-Allow-Origin: https://ithub.intellihelp.tech
Access-Control-Allow-Credentials: true
Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS
Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token
Access-Control-Max-Age: 86400
```

- Whitelist explícita por env (`FRONTEND_URL`)
- En desarrollo: `http://localhost:3000` también permitido si `APP_ENV=local`

---

## 7. Rate limiting

Implementado con `RateLimitMiddleware` (cache en `storage/ratelimit/` con tokens bucket por clave compuesta).

| Endpoint | Clave | Límite |
|---|---|---|
| `POST /auth/login` | IP + email | 5 / 15 min por email, 20 / 15 min por IP |
| `POST /auth/refresh` | IP | 20 / min |
| `POST /auth/change-password` | user_id | 5 / 15 min |
| `POST /usuarios/{id}/reset-password` | user_id (caller) | 10 / h |
| `POST /facturas/import/*` | user_id | 10 / h |
| Resto autenticado | user_id | 120 / min |

Respuesta cuando se excede: HTTP 429 con `Retry-After`.

---

## 8. Autorización

### 8.1 Middleware de roles
`RoleMiddleware(['admin', 'cobranzas'])` se aplica por ruta. Sin token o sin rol válido → 401/403.

### 8.2 Resource-level checks
Ejemplo: `PUT /facturas/{id}`.

```php
// FacturaService::update
if ($user->rol === 'ventas') {
    if ($factura->created_by !== $user->id) throw new ForbiddenException();
    if ($factura->check_cobranza) throw new ForbiddenException(); // ya cobrada
}
if ($user->rol === 'cobranzas') {
    // Filtrar el DTO a solo campos permitidos
    $dto = $dto->only(['total_cobrado', 'fecha_pago', 'check_cobranza', 'observaciones']);
}
```

El frontend oculta acciones, pero **el backend nunca confía en el cliente**.

### 8.3 Mass assignment
Todos los modelos Eloquent definen `$fillable` explícito (lista blanca). Nunca usamos `$guarded = []`.

---

## 9. Inyección y validación de entrada

### 9.1 SQL Injection
- **Eloquent ORM** (PDO con prepared statements debajo)
- Si se usa SQL raw para reports complejos: SIEMPRE con `DB::select('... ?', [$param])`
- Nunca concatenamos strings de usuario en SQL

### 9.2 XSS
- Frontend renderiza con React (auto-escape de `{}`)
- Campos que pueden contener HTML enriquecido (`observaciones`, `detalle_factura`): se renderizan como texto plano por defecto. Si en el futuro queremos formato, se sanitiza con DOMPurify
- API valida que `observaciones` no contenga etiquetas script/iframe/object/embed antes de guardar (defensa en profundidad)

### 9.3 Validación
- **Respect/Validation** en backend para todos los inputs
- **Zod** en frontend (mejor UX, no es la autoridad)
- Tipos enumerados se validan contra lista cerrada
- CUIT: validador de checksum argentino
- Fechas: formato ISO 8601 y rango razonable (1900–2100)
- Montos: regex `^\d{1,13}(\.\d{1,2})?$`

### 9.4 JSON only
- Todos los endpoints excepto uploads aceptan **solo `application/json`**
- El middleware `JsonBodyMiddleware` rechaza con 415 cualquier otro content-type
- Esto bloquea CSRF clásico que usa form-urlencoded

---

## 10. CSRF

| Endpoint | Mecanismo |
|---|---|
| Endpoints con Bearer JWT | Inmunes a CSRF: el browser no envía Authorization automáticamente |
| `POST /auth/refresh` (cookie) | Double-submit token (`X-CSRF-Token` debe coincidir con `csrf_token` cookie no-HttpOnly) |
| `POST /auth/logout` (cookie) | Mismo mecanismo |
| Cookies | `SameSite=Strict` como segunda capa |

---

## 11. Uploads de archivos

### 11.1 Validación
| Control | Valor |
|---|---|
| Tamaño máximo | 25 MB |
| MIME whitelist | `application/pdf`, `image/png`, `image/jpeg`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `application/vnd.ms-excel`, `text/csv`, `application/msword`, `application/vnd.openxmlformats-officedocument.wordprocessingml.document` |
| Verificación | Validar tanto Content-Type como **magic bytes** (`finfo`), no solo la extensión |
| Nombre | Regex `[^A-Za-z0-9._-]` → `_`, máximo 200 chars, sin `..`, sin paths |
| Almacenamiento | Stream directo a Google Drive, **no se persiste localmente** |

### 11.2 Service Account
- JSON en `storage/credentials/service-account.json`, **chmod 0600**, fuera del web root
- Scope mínimo: `drive.file` (solo archivos creados por la app, no acceso global a Drive)
- Domain-wide delegation con `impersonate user` específico (no admin general)

---

## 12. Secretos y configuración

### 12.1 `.env`
- Nunca commiteado (gitignore lo bloquea)
- Permisos `0640`, owner del usuario PHP
- Cargado con vlucas/phpdotenv, validado al boot (claves obligatorias)

### 12.2 Rotación
| Secreto | Frecuencia |
|---|---|
| `JWT_SECRET` | Cada 6 meses o ante sospecha |
| `CRON_TOKEN` | Cada 6 meses |
| `SMTP_PASS` | Según política IT |
| Service Account JSON | Cuando rote en Google Cloud |

Rotación de `JWT_SECRET` **invalida todas las sesiones activas** (deseable como kill switch).

### 12.3 Base de datos
Dos usuarios MySQL:

| Usuario | Privilegios | Uso |
|---|---|---|
| `ithub_app` | `SELECT, INSERT, UPDATE, DELETE` en DB de la app | Runtime |
| `ithub_migrate` | `ALL` en DB de la app | Solo migraciones (vía SSH) |

Ningún usuario tiene `SUPER`, `FILE`, ni acceso a `mysql` schema.

---

## 13. Auditoría y logging

### 13.1 Tabla `auditoria`
Cada acción sensible registra:
- `user_id` (NULL para cron / sistema)
- `entidad` + `entidad_id`
- `accion`
- `campos_modificados` (JSON con diff antes/después)
- `ip`, `user_agent`
- `created_at`

**Acciones registradas:** crear/editar/eliminar entidades, login, login fallido, logout, export, import, marcar cobrada, subir/eliminar archivo, cambios de config, cambio de password, reset password.

### 13.2 Monolog
- Channel `app`: errores y warnings, archivo rotativo diario en `storage/logs/app-YYYY-MM-DD.log`
- Channel `security`: eventos de seguridad (login fallido, lockout, rate-limit excedido)
- Retención: 90 días
- **Filtros de PII:** se enmascaran emails (`u***@dominio.com`), CUITs (`20-***-***-9`) y passwords/secrets antes de serializar

### 13.3 Errores
- En producción, las respuestas NUNCA exponen stack trace
- Cada error tiene un `request_id` (UUID v4) devuelto al cliente
- El stack trace queda en `storage/logs/` correlacionado por `request_id`

---

## 14. Cron

- Endpoint `/cron/check-vencimientos` autenticado con `X-Cron-Token` (token aleatorio de 64 chars en env)
- Comparación con `hash_equals` (constant time)
- Validación adicional: solo acepta requests con `REMOTE_ADDR` en `127.0.0.1` / `::1`
- Si Hostinger ejecuta el cron via HTTP, se permite también el IP saliente del hosting (configurable)

---

## 15. Backups

- Script `api/scripts/backup.sh` que:
  1. `mysqldump` de la DB
  2. `gzip` del dump
  3. Cifrado con `gpg --symmetric --cipher-algo AES256` (passphrase en env separado)
  4. Sube a Drive en carpeta `backups/` con timestamp
  5. Elimina backups locales > 7 días
- Cron diario a las 03:00 AM
- Restauración documentada en `docs/deploy-hostinger.md`

---

## 16. Dependencias

- `composer audit` corre en pre-commit (Husky no aplica, lo dejamos como Make target)
- `npm audit --production` antes de cada deploy
- Pin de versiones exactas en `composer.json` y `package.json`
- Dependabot configurado en `.github/dependabot.yml`

---

## 17. Inputs de import (Excel/CSV)

Datos externos = untrusted:
- Validación de encabezados estricta
- Cada celda pasa por el validador del campo correspondiente
- CUIT: checksum
- Fechas: rango 1900–2100
- Montos: regex
- Formato CSV: el parser ignora fórmulas (`=`, `+`, `-`, `@` al inicio) → previene **CSV injection** cuando se exporta de vuelta
- Excel: PhpSpreadsheet con `setReadDataOnly(true)` y desactivando cálculos

---

## 18. Información expuesta

- `phpinfo()` deshabilitado en producción
- Endpoint `/api/v1/health` solo devuelve `{ "ok": true }`, nunca versiones
- `Server` y `X-Powered-By` removidos
- Errores 500 genéricos en prod

---

## 19. Defensa en profundidad — checklist de deploy

Antes de pasar a producción:

- [ ] `.env` con secretos rotados (no los del ejemplo)
- [ ] `JWT_SECRET` generado con `openssl rand -base64 32`
- [ ] `CRON_TOKEN` generado con `openssl rand -hex 32`
- [ ] DB user de runtime con privilegios mínimos
- [ ] Service Account JSON con chmod 600 fuera del web root
- [ ] HTTPS forzado en hPanel
- [ ] HSTS activo
- [ ] `APP_DEBUG=false`
- [ ] `display_errors = Off`
- [ ] Backup automático configurado y probado restauración
- [ ] Cron jobs configurados
- [ ] Adminer / phpMyAdmin no accesibles públicamente
- [ ] Carpeta `/storage/`, `/db/`, `/config/`, `/src/`, `/vendor/` no servidas por Apache (verificable con curl)
- [ ] Test de SSL Labs grado A o superior
- [ ] Test de securityheaders.com grado A o superior

---

## 20. Mapping OWASP Top 10 (2021)

| Riesgo | Mitigación |
|---|---|
| A01 Broken Access Control | RoleMiddleware + resource-level checks server-side, mass assignment con fillable, soft deletes |
| A02 Cryptographic Failures | bcrypt cost 12, JWT HS256 256-bit, SHA-256 para refresh tokens, HTTPS+HSTS, TLS 1.2+ |
| A03 Injection | Eloquent ORM (PDO prepared), Respect/Validation, CSV injection prevention, magic-bytes en uploads |
| A04 Insecure Design | Threat model documentado, separation of concerns, validación doble |
| A05 Security Misconfiguration | Headers de seguridad, X-Powered-By off, errores genéricos, .htaccess de bloqueo, two DB users |
| A06 Vulnerable Components | composer audit + npm audit en CI, pin de versiones, Dependabot |
| A07 Auth Failures | bcrypt, política de password, lockout, rate limit, refresh rotation con reuse detection |
| A08 Software & Data Integrity | JWT firmado, refresh hasheado, validación de magic bytes, pin de deps |
| A09 Logging Failures | Monolog + tabla auditoria, retención 90d, filtros de PII |
| A10 SSRF | Whitelist de URLs en imports, sin fetch arbitrario de URLs del usuario |
