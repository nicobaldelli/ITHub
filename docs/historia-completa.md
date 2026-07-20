# Historia completa del proyecto ITHub

> Documento para retomar el contexto desde cero en otra instancia de Claude Code (web o CLI). Incluye el prompt inicial, todas las decisiones funcionales tomadas durante el desarrollo, y la progresión de chunks.

---

## 0. Contexto general

**Owner:** Nico Baldelli (`nbaldelli@intellihelp.tech`)
**Repo:** https://github.com/nicobaldelli/ITHub
**Rama:** `master`
**Dominios productivos:** `ithub.intellihelp.tech` (web) + `apithub.intellihelp.tech` (API)
**Host:** Hostinger

---

## 1. PROMPT INICIAL (literal, lo que arrancó el proyecto)

> # Prompt para Claude Code — App de Gestión de Facturas de Venta
>
> ## 📋 OBJETIVO GENERAL
> Construí una aplicación web completa para gestionar facturas de venta de una empresa argentina, con arquitectura de dos servidores independientes (API y Web). Toda la persistencia debe estar centralizada en una base de datos accesible desde la API. La app se desplegará en **Hostinger** bajo un subdominio, con frontend y backend en subdominios separados.
>
> ## 🏗️ STACK TÉCNICO (OBLIGATORIO)
>
> ### Backend (API)
> - **Lenguaje:** PHP 8.2+
> - **Framework:** Slim Framework 4
> - **Base de datos:** MySQL 8.0
> - **ORM/Query Builder:** Eloquent ORM (vía `illuminate/database`)
> - **Auth:** JWT con refresh tokens
> - **Validación:** Respect/Validation
> - **Migrations:** Phinx
> - **CORS:** Configurado para aceptar requests del subdominio del frontend
> - **Logging:** Monolog
> - **Subir archivos a Drive:** google/apiclient (SDK oficial)
> - **Envío de emails:** PHPMailer o Symfony Mailer
> - **Exportar Excel:** PhpSpreadsheet
> - **Exportar PDF:** Dompdf o TCPDF
> - **Estructura:** PSR-4, controllers/services/repositories/middlewares separados
>
> ### Frontend (Web)
> - **Framework:** Next.js 14+ (App Router) con TypeScript
> - **UI:** Tailwind CSS + shadcn/ui
> - **Gráficos del dashboard:** Recharts
> - **Formularios:** React Hook Form + Zod
> - **Llamadas HTTP:** Axios con interceptors para JWT
> - **Estado global:** Zustand o Context API
> - **Tablas con filtros/paginación:** TanStack Table (React Table v8)
> - **Notificaciones UI:** Sonner o react-hot-toast
> - **Iconos:** Lucide React
> - **Fechas:** date-fns con locale español
>
> ### Base de datos
> - **Motor:** MySQL 8.0
> - **Charset:** utf8mb4_unicode_ci
> - **Timezone:** America/Argentina/Buenos_Aires
>
> ## 🎨 DISEÑO Y BRANDING
>
> ### Paleta de colores (estricta)
> - **Fondo principal:** `#FFFFFF`
> - **Color primario / marca:** `#663399` (violeta corporativo)
> - **Texto y elementos neutros:** `#161922`
> - **Color de acento:** `#9CC930` (verde corporativo) — usar con moderación
>
> ### Tipografía
> - **Fuente única:** Saira (Google Fonts)
>
> ### Lineamientos visuales
> - Diseño limpio, profesional, principalmente para desktop pero responsive
> - Sidebar de navegación con color primario (#663399)
> - Cards con sombras suaves, bordes redondeados
> - Botones primarios en #663399, hover más oscuro
> - Estados "pagado/cobrado" en verde, "pendiente" en gris, "vencido" en rojo
> - Sin emojis decorativos. Usar Lucide React para iconografía.
>
> ## 🗄️ MODELO DE DATOS
>
> ### Tabla `facturas_venta` (entidad principal)
> Campos clave: numero_factura, cliente_id, tipo (A/B/E/CREDITO_MIPYME_*/NC_*/ND_*), cuit, cuit_pais, moneda (ARS/USD), importe_sin_iva, importe_con_iva, importe_total_pesos, tdc, retenciones, total_cobrado, detalle_factura, numero_mes, mes_cubierto, fecha_factura, fecha_envio, banco, vencimiento, cbu, alias, plazo_pago, fecha_pago, direccion, mail_envio_factura, contacto_envio_factura, telefono_contacto_proveedores, mail_gestion_cobranza, contacto_gestion_cobranza, telefono_contacto_cobranza, observaciones, check_cobranza, check_cobranza_user_id, check_cobranza_fecha, drive_folder_id, created_by, updated_by, timestamps, deleted_at.
>
> ### Tabla `clientes`
> Campos clave: razon_social, cuit, cuit_pais, tipo_default, direccion, banco, cbu, alias, plazo_pago_default, mails y contactos de envío y cobranza, observaciones, activo, timestamps, deleted_at.
>
> ### Tabla `users`
> 4 roles: admin / cobranzas / ventas / visualizador. Campos: nombre, apellido, email único, password_hash, rol, activo, last_login, timestamps.
>
> ### Tabla `factura_archivos`
> Referencias a archivos en Google Drive: factura_id, drive_file_id, nombre_archivo, mime_type, tamanio_bytes, drive_view_url, drive_download_url, uploaded_by, created_at.
>
> ### Tabla `auditoria` (log de cambios)
> user_id, entidad, entidad_id, accion (crear/editar/eliminar/marcar_cobrada/login/export/import), campos_modificados (JSON before/after), ip, user_agent, created_at.
>
> ### Tabla `config_app`
> Key-value para config global (Drive root folder, SMTP, etc.).
>
> ## 🔐 AUTENTICACIÓN Y ROLES
>
> ### Matriz de permisos
> | Acción | Admin | Cobranzas | Ventas | Visualizador |
> |---|---|---|---|---|
> | Ver facturas | ✅ | ✅ | ✅ | ✅ |
> | Crear factura | ✅ | ❌ | ✅ | ❌ |
> | Editar factura | ✅ | Solo campos cobranza | Solo facturas propias** | ❌ |
> | Eliminar factura | ✅ | ❌ | ❌ | ❌ |
> | Marcar CHECK cobranza | ✅ | ✅ | ❌ | ❌ |
> | Ver/editar clientes | ✅ | ✅ (solo ver) | ✅ | ✅ (solo ver) |
> | Crear/editar clientes | ✅ | ❌ | ✅ | ❌ |
> | Ver dashboard | ✅ | ✅ | ✅ | ✅ |
> | Exportar datos | ✅ | ✅ | ✅ | ✅ |
> | Importar histórico | ✅ | ❌ | ❌ | ❌ |
> | Gestionar usuarios | ✅ | ❌ | ❌ | ❌ |
> | Ver auditoría | ✅ | ❌ | ❌ | ❌ |
>
> **Cobranzas edita:** total_cobrado, fecha_pago, check_cobranza, observaciones.
> **Ventas edita:** sus facturas (created_by = self.id), antes de cobradas.
>
> ### Auth flow
> - `POST /api/auth/login` → access (15 min) + refresh (7 días)
> - Middleware JWT en todas las rutas protegidas
> - Middleware de roles que valida permisos
> - `POST /api/auth/refresh`
> - `POST /api/auth/logout`
>
> ## 📡 ENDPOINTS API REST
> Prefijo: `/api/v1`
>
> Bloques:
> - Auth (login, refresh, logout, me)
> - Facturas (CRUD + check-cobranza + archivos + export Excel/PDF/CSV + import)
> - Clientes (CRUD)
> - Dashboard (KPIs, tendencias, aging, top-clientes)
> - Usuarios (solo admin)
> - Auditoría (solo admin)
> - Drive (helpers)
>
> ## 📊 DASHBOARD — MÉTRICAS
>
> ### Tarjetas KPI
> 1. Total facturado (con equivalente USD)
> 2. Total cobrado
> 3. Total pendiente
> 4. Facturas vencidas (cantidad + monto)
> 5. Tasa de recuperación mensual (ideal 90-100% con semáforo verde/amarillo/rojo)
> 6. DSO (Days Sales Outstanding) — Top performers <30 días
> 7. ADD (Average Days Delinquent)
>
> ### Gráficos
> - Tendencia mensual (facturado vs cobrado, 12 meses)
> - Comparativa año actual vs año anterior
> - Aging de cuentas por cobrar (0-30 / 31-60 / 61-90 / 91+)
> - Top 10 clientes por facturación
> - Distribución por tipo de factura
> - Distribución por moneda (ARS vs USD)
>
> ## 📁 INTEGRACIÓN GOOGLE DRIVE
>
> - Service Account con domain-wide delegation
> - Estructura: `RootFolder/Año/Mes/Cliente/Factura.pdf`
> - Creación lazy de carpetas (al subir primer archivo de una factura)
> - Persistir drive_file_id y URLs en `factura_archivos`
>
> ## 📧 NOTIFICACIONES
>
> - Cron job diario a las 9:00 AM
> - Recordatorios: 3 / 1 / 0 días antes del vencimiento + 1 / 7 / 15 / 30 días después
> - Mail al `mail_gestion_cobranza` con copia a admin + cobranzas
>
> ## 📥 IMPORTACIÓN HISTÓRICA
>
> - Endpoint `POST /api/v1/facturas/import` (.xlsx o .csv)
> - Wizard de 3 pasos (subir → revisar → confirmar)
> - Plantilla descargable
> - Auto-crear clientes nuevos por CUIT
>
> ## 🖥️ FRONTEND — PÁGINAS
>
> - `/login`
> - `/dashboard` (home tras login)
> - `/facturas` (listado con filtros + bulk actions)
> - `/facturas/nueva`
> - `/facturas/[id]`
> - `/facturas/importar`
> - `/clientes`
> - `/clientes/nuevo`
> - `/clientes/[id]`
> - `/usuarios` (solo admin)
> - `/auditoria` (solo admin)
> - `/configuracion` (solo admin)
> - `/mi-perfil`
>
> ## 🚀 DEPLOYMENT HOSTINGER
>
> - `facturas.tudominio.com` (Web — Next.js Static Export)
> - `api.facturas.tudominio.com` (API — PHP/Slim)
> - Variables sensibles en `.env`
> - Cron job desde hPanel
>
> ## 🌐 IDIOMA Y LOCALIZACIÓN
>
> - UI en español rioplatense (argentino)
> - Fechas DD/MM/YYYY
> - Números: separador miles `.`, decimales `,`
> - ARS: `$ 1.234,56`
> - USD: `US$ 1,234.56`
> - Timezone: America/Argentina/Buenos_Aires
>
> ## 🎯 PRIORIDADES (orden de fases si hay que entregar incremental)
>
> **Fase 1 (MVP):** Setup + Auth + CRUD clientes + CRUD facturas + listado con filtros + dashboard KPIs básicos.
> **Fase 2:** Drive + roles completos + auditoría + dashboard completo.
> **Fase 3:** Exportación + importación + notificaciones + configuración admin.
>
> ## ⚠️ INSTRUCCIONES
>
> 1. Empezar mostrando esquema migraciones + endpoints + estructura de carpetas para validar antes de codear.
> 2. Commits frecuentes con mensajes descriptivos en español.
> 3. Si algo no está claro, asumir lo estándar y documentar en README.
> 4. Después de cada fase, pausar y mostrar lo hecho.
> 5. Documentar funciones complejas (DSO, ADD).
> 6. Compatibilidad Hostinger: extensiones PHP estándar.
> 7. Nunca hardcodear credenciales.
> 8. Pensar como software de producción, no prototipo.

---

## 2. Decisiones iniciales tomadas (después del prompt)

| Decisión | Valor |
|---|---|
| Dominio | `ithub.intellihelp.tech` + `apithub.intellihelp.tech` |
| Admin inicial | `nbaldelli@intellihelp.tech` |
| Frontend deploy | **Static Export** (Hostinger compartido, sin Node) |
| Monorepo | `api/` + `web/` en un solo repo |
| Columna `estado` en facturas | ✅ agregada (borrador/emitida/cobrada/vencida/anulada) |
| Tabla `refresh_tokens` | ✅ separada con `family_id` para detección de reuso |
| Seguridad | **Máxima** — confirmado por el owner |
| Package manager web | **pnpm 9** (por seguridad — integridad de lockfile + no phantom deps) |
| Idioma | Español rioplatense (chat + UI + comentarios) |
| Emojis | No en código ni commits. Sí en chat para claridad. |

---

## 3. Working style del owner (Nico) — CRÍTICO

Guardado en `CLAUDE.md` raíz:

- **Idioma:** español rioplatense (argentino).
- **Preguntá antes de asumir.** Ante cualquier duda funcional o sugerencia, plantearla — no decidir unilateralmente. Esto incluye:
  - Reglas de negocio dudosas
  - Trade-offs UX (ej: "modal vs nueva página")
  - Performance vs simplicidad
  - Cambios de schema o renombres
- **Sugerencias bienvenidas.** Si veo una mejora razonable a algo ya implementado, proponerla.
- **Seguridad prioritaria.** Ante cualquier compromiso, default al lado más seguro y avisar.
- **Commits con mensaje descriptivo** en español, sin emojis en mensajes de commit.
- **No agregar emojis al código** salvo que se pidan explícitamente.

---

## 4. Arquitectura de seguridad implementada

Documentada en `docs/seguridad.md` (~600 líneas). Resumen:

### Autenticación
- **bcrypt cost 12** con rehash automático
- **Política de password:** ≥12 chars, mayúsculas + minúsculas + dígito + símbolo
- **JWT HS256** con secret 256 bits, validación de iss/aud/exp/nbf
- **Access TTL 15 min**, refresh TTL 7 días
- **Refresh token rotation:** cada refresh emite uno nuevo + invalida el anterior
- **Detección de reuso:** si llega un refresh ya revocado → invalida toda la familia
- **Lockout:** 5 fallos / 15 min por email + 20 / 15 min por IP
- **Cambio forzado de password** al primer login (`must_change_password`)
- **Delay constante 200ms** en login (anti-timing attacks)
- Respuestas genéricas (no revelan si el email existe)

### Sesión en cliente
- Access JWT solo en memoria (Zustand sin persistir) — inmune a XSS persistente
- Refresh en cookie HttpOnly + Secure + SameSite=Strict, scoped a `/api/v1/auth`
- Hydrate silencioso al recargar página (intenta `/auth/refresh`)
- Evento `auth:unauthorized` fuerza logout en frontend

### Transporte + headers
- HTTPS obligatorio + HSTS max-age=31536000 includeSubDomains preload
- TLS 1.2+ mínimo
- Security headers: X-Content-Type-Options nosniff, X-Frame-Options DENY, Referrer-Policy strict-origin-when-cross-origin, Permissions-Policy estricta, COOP/CORP, CSP estricto (`default-src 'none'; frame-ancestors 'none'`)
- `X-Powered-By` removido

### CORS
- Whitelist estricta: solo `FRONTEND_URL` (y `localhost:3000` en local)
- `credentials: true` para que viajen las cookies HttpOnly
- Preflight OPTIONS interceptado antes del routing

### Rate limiting
- Login: 5/15min por email, 20/15min por IP
- Refresh: 20/min por IP
- Change password: 5/15min
- Reset password: 10/h
- Import: 10/h
- General: 120/min por usuario
- Sliding window con Symfony Cache filesystem en `storage/ratelimit/`

### Autorización
- `RoleMiddleware` por ruta
- Resource-level checks server-side (no se confía en el cliente)
- Mass assignment con `$fillable` explícito en todos los modelos

### Datos
- Eloquent (PDO prepared) → SQL injection mitigado
- HTMLPurifier para campos con HTML
- Anti-script regex en `observaciones`, `detalle_factura`, `descripcion`
- Magic bytes validation en uploads (no solo extension)

### CSRF
- Endpoints con Bearer JWT son inmunes (no se envía Authorization automáticamente)
- Endpoints con cookie (`/auth/refresh`, `/auth/logout`): double-submit token (header `X-CSRF-Token` debe coincidir con cookie no-HttpOnly)

### Uploads (Drive)
- MIME whitelist: pdf, png, jpg, jpeg, xlsx, xls, csv, doc, docx
- Tamaño máx: 25 MB
- Sanitización de nombres
- Stream directo a Drive (no se persiste local)
- Service Account scope `drive.file` solamente

### Audit
- Tabla `auditoria` con user_id, entidad, accion, JSON before/after, IP, user_agent, request_id
- Monolog con rotación diaria + PII filter (enmascara emails, CUITs, tokens)
- Channel `security` separado para eventos críticos

### DB
- 2 usuarios MySQL: runtime (SELECT/INSERT/UPDATE/DELETE) + migrate (privilegios completos vía SSH)

### Hardening
- `.htaccess` con `Require all denied` en `src/`, `config/`, `db/`, `storage/`, raíz
- Apache DocumentRoot apuntando a `api/public/` solamente
- `expose_php=Off`, `display_errors=Off`, errores con stack trace solo en logs
- Cron endpoints con token estático en env (no JWT)

### OWASP Top 10
Todo el Top 10 (2021) cubierto explícitamente, mapping en `docs/seguridad.md`.

---

## 5. Modelo de datos final (10 tablas)

### `users`
`id` BIGINT UNSIGNED PK, `nombre`, `apellido`, `email` UNIQUE, `password_hash`, `rol` ENUM(admin/cobranzas/ventas/visualizador), `activo`, `must_change_password`, `failed_login_attempts`, `locked_until`, `last_login`, `last_login_ip`, timestamps.

### `clientes`
`id`, `razon_social`, `cuit` UNIQUE, `cuit_pais`, `tipo_default` (ENUM tipos factura), `direccion`, `banco`, `cbu`, `alias`, `plazo_pago_default`, 6 campos de contacto (envío + cobranza), `observaciones`, `activo`, timestamps, `deleted_at` (soft delete).

### `facturas_venta`
`id`, `numero_factura` UNIQUE, `cliente_id` FK, `tipo` ENUM(A/B/E/CREDITO_MIPYME_A/B/NC_A/B/E/ND_A/B/E), `cuit` (snapshot), `cuit_pais`, `moneda` ENUM(ARS/USD), `importe_sin_iva`, `importe_con_iva`, `importe_total_pesos`, `tdc`, `retenciones`, `total_cobrado`, `detalle_factura`, `numero_mes` TINYINT, `mes_cubierto`, fechas (factura/envio/vencimiento/pago), datos bancarios (banco, cbu, alias), `plazo_pago`, datos de contacto, `observaciones`, `check_cobranza` BOOL, `check_cobranza_user_id` FK, `check_cobranza_fecha`, `drive_folder_id`, `estado` ENUM(borrador/emitida/cobrada/vencida/anulada), **`servicio_cuota_id` FK** (agregado en chunk servicios), `created_by`, `updated_by`, timestamps, `deleted_at`.

### `factura_archivos`
`id`, `factura_id` FK CASCADE, `drive_file_id`, `nombre_archivo`, `mime_type`, `tamanio_bytes`, `drive_view_url`, `drive_download_url`, `uploaded_by` FK, `created_at`.

### `auditoria`
`id`, `user_id` FK SET NULL, `entidad`, `entidad_id`, `accion` ENUM, `campos_modificados` JSON, `ip`, `user_agent`, `request_id`, `created_at`.

### `config_app`
PK `clave` VARCHAR(100), `valor` TEXT, `tipo` ENUM(string/int/bool/json), `descripcion`, `updated_by` FK, `updated_at`.

### `refresh_tokens`
`id`, `user_id` FK CASCADE, `token_hash` CHAR(64) UNIQUE (SHA-256), `family_id` CHAR(36) (UUID), `expires_at`, `revoked_at`, `replaced_by_id`, `user_agent`, `ip`, `created_at`.

### `servicios`
`id`, `cliente_id` FK RESTRICT, `tipo` ENUM(proyecto/mantenimiento), `nombre`, `descripcion`, `moneda` ENUM(ARS/USD), `importe_base` (total para proyecto / por cuota para mantenimiento), `fecha_inicio`, `fecha_fin` (**NULL = indefinido**), `modo_facturacion` ENUM(mes_calendario/intervalo_dias), `dia_facturacion` TINYINT (1-31), `intervalo_dias`, `frecuencia_ajuste_meses`, `aviso_dias_previos`, `estado` ENUM(activo/pausado/completado/cancelado), `pausado_at`, `observaciones`, `created_by`, `updated_by`, timestamps, `deleted_at`.

### `servicio_cuotas`
`id`, `servicio_id` FK CASCADE, `numero_cuota`, `total_cuotas` (NULL si indefinido), `porcentaje` (proyectos), `importe`, `fecha_prevista`, `factura_id` FK SET NULL, `estado` ENUM(pendiente/facturada/omitida/cancelada), `etiqueta`, `es_proporcional` BOOL, `dias_cubiertos`, `observaciones`, timestamps. UNIQUE(`servicio_id`, `numero_cuota`).

### `servicio_ajustes`
`id`, `servicio_id` FK CASCADE, `tipo` ENUM(programado/espontaneo), `fecha_aplicacion`, `cuota_desde_id` FK SET NULL, `importe_anterior`, `importe_nuevo`, `porcentaje_variacion`, `aplicado` BOOL, `aplicado_at`, `aplicado_por` FK SET NULL, `observaciones`, `created_by` FK RESTRICT, timestamps.

---

## 6. Endpoints implementados

Prefijo: `/api/v1`. Todos requieren JWT salvo `/health`, `/auth/login`, `/auth/refresh`.

### Health
- `GET /health`

### Auth
- `POST /auth/login`
- `POST /auth/refresh` (con CSRF)
- `POST /auth/logout` (con CSRF)
- `POST /auth/logout-all`
- `GET /auth/me`
- `POST /auth/change-password`

### Clientes
- `GET /clientes` (paginado, filtros: search, activo)
- `GET /clientes/{id}`
- `GET /clientes/{id}/facturas`
- `POST /clientes` (admin/ventas)
- `PUT /clientes/{id}` (admin/ventas)
- `DELETE /clientes/{id}` (admin)

### Facturas
- `GET /facturas` (paginado, filtros: cliente_id, tipo, moneda, estado, fecha_*, vencimiento_*, cobrado, vencidas, por_vencer_dias, search, sort_*)
- `GET /facturas/{id}`
- `GET /facturas/{id}/historial`
- `POST /facturas` (admin/ventas)
- `PUT /facturas/{id}` (permisos finos por rol en service)
- `PATCH /facturas/{id}/check-cobranza` (admin/cobranzas)
- `DELETE /facturas/{id}` (admin)

### Dashboard
- `GET /dashboard/kpis` (con filtros periodo/cliente_id/tipo/moneda)
- `GET /dashboard/tendencias?meses=12`
- `GET /dashboard/aging`
- `GET /dashboard/top-clientes?limit=10`
- `GET /dashboard/distribucion-tipo`
- `GET /dashboard/distribucion-moneda`

### Servicios (CRUD)
- `GET /servicios` (paginado, filtros: cliente_id, tipo, estado, moneda, search)
- `GET /servicios/{id}` (con cuotas + ajustes)
- `POST /servicios` (admin/ventas)
- `PUT /servicios/{id}` (admin/ventas)
- `DELETE /servicios/{id}` (admin)

### Servicios — acciones de estado
- `PATCH /servicios/{id}/pausar` (admin/ventas)
- `PATCH /servicios/{id}/reanudar` (admin/ventas) — `modo`: cancelar_pasadas | correr_cronograma
- `PATCH /servicios/{id}/cancelar` (admin/ventas)
- `POST /servicios/{id}/extender` (admin/ventas) — `meses` o `nueva_fecha_fin` + opcional `nuevo_importe_base`

### Servicios — acciones sobre cuotas
- `PATCH /servicios/{id}/cuotas/{cid}` (admin) — edita fecha/importe/etiqueta/observaciones
- `PATCH /servicios/{id}/cuotas/{cid}/omitir` (admin)
- `PATCH /servicios/{id}/cuotas/{cid}/cancelar` (admin)
- `POST /servicios/{id}/cuotas/{cid}/facturar` (admin/ventas) — genera factura precargada y vincula

### Servicios — ajustes de tarifa
- `GET /servicios/{id}/ajustes`
- `POST /servicios/{id}/ajustes` (admin/ventas) — `tipo` (programado/espontaneo), `modo` (monto/porcentaje), `valor`, `fecha_aplicacion`, `cuota_desde_id` opcional, `observaciones`
- `POST /servicios/{id}/ajustes/{aid}/aplicar` (admin/ventas)
- `DELETE /servicios/{id}/ajustes/{aid}` (admin, solo no aplicados)

---

## 7. Preguntas funcionales hechas durante el desarrollo de Servicios

Todas con sus respuestas finales:

### Bloque 1 (modelo conceptual)
**P:** ¿1 cuota = 1 factura siempre, o múltiples cuotas en una factura?
**R:** 1 cuota = 1 factura por defecto. Cuota = un mes, pero también puede ser por X cantidad de días configurable.

**P:** Fecha de facturación mensual — ¿siempre día 1° o configurable?
**R:** Configurable por servicio.

**P:** Servicio en USD — ¿TDC fijo al crear o del día al facturar?
**R:** Al TDC del día.

**P:** Mantenimiento iniciado hace 6 meses sin facturar — ¿generamos las 6 cuotas retroactivas?
**R:** Sí, las 6 quedan pendientes.

**P:** ¿Admin puede editar cuotas individuales?
**R:** Sí.

**P:** ¿Servicios disparan recordatorios de cron?
**R:** Sí.

### Bloque 2 (cronograma + ajustes)
**P:** Cronograma de cuotas en proyectos — ¿cómo cargar fechas?
**R:** **Opción A:** cargar fechas una por una al crear (editables después).

**P:** Ajuste de precio por inflación.
**R:** Llevar duración del acuerdo de ajuste (ej: cada 4 meses). También permitir ajustes espontáneos.

**P:** Recordatorios cron — ¿global o por servicio?
**R:** **Opción C:** las dos (por servicio sobreescribe el global).

**P:** Pausa de servicio — cuotas pendientes quedan ¿canceladas o se reprograman?
**R:** **Opción B:** automáticamente canceladas.

**P:** Auto-completar servicio cuando todas resuelven.
**R:** Sí, automático.

### Bloque 3 (detalles del ajuste)
**P:** Cómo se carga el monto del ajuste.
**R:** **Opción C:** cualquiera de los dos (monto absoluto o %), el sistema calcula el otro.

**P:** Pausa de servicio + reanudación.
**R:** **Opción C:** el usuario decide en el modal de "Reanudar" (cancelar cuotas o correr cronograma).

**P:** Renovación al vencer.
**R:** **Opción B:** botón "Extender". Pueden haber mantenimientos indefinidos también.

**P:** Ajustes en USD.
**R:** **Opción A:** se cargan en USD, TDC se aplica al facturar.

**P:** Frecuencia de ajuste editable.
**R:** Sí, puede cambiar durante la vida del servicio.

### Bloque 4 (mantenimientos indefinidos + más)
**P:** Pre-generación de cuotas para indefinidos.
**R:** **Opción A:** rolling window de 12 meses con cron mensual de extensión.

**P:** Etiqueta de cuota en mantenimientos indefinidos.
**R:** **Opción B:** solo mes calendario ("Junio 2026", "Julio 2026"...).

**P:** Aplicación del ajuste.
**R:** **Opción B:** el admin elige la cuota específica desde la cual aplica.

**P:** Proyectos.
**R:** **Opción A:** siempre alcance cerrado con porcentajes que suman 100%.

### Bloque 5 (workflow operativo)
**P:** Form de factura desde cuota — ¿campos editables?
**R:** Depende del rol. **Admin → A** (todo editable, queda en auditoría). **Ventas → B** (cliente/importe/moneda read-only, solo accesorios editables). Si ventas quiere cambiar el importe, edita la cuota primero.

**P:** Cuotas que no dan número entero (ej: 100 días con intervalo 30).
**R:** Se cobra por mes y el último mes son los días proporcionales. Redondeo hacia arriba con la última proporcional automática.

**P:** Dashboard de servicios.
**R:** **Opción A:** página dedicada `/servicios` con KPIs propios + 2 widgets en dashboard principal.

### Decisiones colaterales tomadas (asumidas con confirmación)
- Primera cuota de mantenimiento con `fecha_inicio != dia_facturacion`: la primera cuota cae en la próxima ocurrencia del día_facturacion ≥ fecha_inicio. Sin prorrateo inicial.
- `dia_facturacion=31` en meses cortos: se usa el último día del mes.
- 1 cuota = 1 factura, **estricto** en este MVP.
- `numero_factura` lo carga el usuario al facturar (no auto-generado, correlatividad AFIP).
- Cliente desactivado con servicio activo: el servicio se mantiene, al intentar facturar cuota se rechaza.
- Ajuste no cambia moneda, solo importe.
- Múltiples ajustes futuros permitidos.
- Cuotas vencidas sin facturar aparecen destacadas en el dashboard.
- Mantenimientos con `fecha_inicio` futura permitidos.
- Indefinido nunca autocompleta (solo se completa manualmente).
- Quién crea/edita servicios: admin + ventas. Cobranzas solo lectura.
- Quién marca cuotas como facturadas: automático al emitir factura.
- Mover servicio entre clientes: NO.
- Renovación automática: NO (manual con "Extender").
- Tabla de facturas muestra cuota asociada en una columna.
- Porcentajes de proyecto suman 100% ± 0.01 tolerancia.
- Ajustes aplicados no se pueden eliminar (auditoría).
- Avisos del cron van a equipo interno (admin + cobranzas), no al cliente.

---

## 8. Progresión de chunks (cronológica)

### Fase 1 — Foundation
**Commit `64e50b6` — feat: scaffold del monorepo + foundation de seguridad**
- Estructura monorepo (`api/` + `web/` + `docs/`)
- `.gitignore`, `.editorconfig`, README raíz
- `docker-compose.yml` con MySQL 8 + PHP-Apache custom + Node + MailHog + Adminer
- `docker/php/Dockerfile` con extensiones que matchean Hostinger
- API composer.json + .env.example
- Bootstrap: App.php, Routes.php, Middleware.php
- 8 middlewares de seguridad: ErrorHandler, RequestId, SecurityHeaders, CORS, JsonBody, JwtAuth, Role, RateLimit
- Exception hierarchy: AppException + 6 variantes
- PiiFilter, ResponseFactory, ContainerProvider
- 7 migraciones iniciales (users, clientes, facturas_venta, factura_archivos, auditoria, config_app, refresh_tokens)
- 2 seeds (AdminUserSeeder con password random + ConfigAppSeeder)
- `.htaccess` con `Require all denied` en src/, config/, db/, storage/, raíz
- `docs/seguridad.md` extenso (mapping OWASP completo)

**Commit `e1af8ca` — fix: ambiente local funcionando + HealthController + correcciones**
- HealthController nuevo en `GET /health`
- Fix migraciones: PKs `biginteger UNSIGNED` explícito (Phinx creaba INT)
- Fix config_app: PK `clave` NOT NULL explícito
- firebase/php-jwt actualizado a ^7.0 (CVE-2025-45769)
- google/apiclient con `onlyBuiltDependencies` para Drive solamente
- web service movido a profile 'frontend' (no arranca por default)
- `.htaccess`: redirect HTTP→HTTPS omite localhost
- Container DI: bind PSR-17 ResponseFactoryInterface

**Commit `1cbeba2` — feat: autenticación JWT completa**
- 7 modelos Eloquent (User, RefreshToken, Auditoria, Cliente, FacturaVenta, FacturaArchivo, ConfigApp)
- JwtService, AuditoriaService, AuthService
- AuthController con login/refresh/logout/logout-all/me/change-password
- Refresh rotation + detección de reuso
- Lockout, audit completo, must_change_password flow
- Cookies: refresh HttpOnly + Secure + Strict, CSRF token double-submit
- Fix Monolog 3 LogRecord signature en PiiFilter processor
- Fix RateLimit: PSR-6 cache keys con caracteres válidos
- App::build: bootea Capsule eagerly

**Commit `70a208c` — feat: CRUD Clientes + Facturas + Dashboard KPIs**
- ClienteValidator con CUIT checksum AR (helper CuitValidator)
- ClienteRepository + Service + Controller
- FacturaValidator con 13 reglas (tipo ENUM, moneda, TDC obligatorio si USD, fechas YYYY-MM-DD rango 1900-2100, importes ≥0, coherencia total_cobrado ≤ total)
- FacturaRepository con 13 filtros (cliente_id, tipo, moneda, estado, fechas, vencimiento, cobrado, vencidas, por_vencer_dias, search, sort)
- FacturaService con resource-level permissions enforcadas server-side por rol
- Toggle check_cobranza autocompleta fecha_pago y total_cobrado
- DashboardService con DSO, ADD, aging, top clientes, tendencias, distribuciones
- DashboardController con 6 endpoints
- Agregado illuminate/pagination ^10.40
- App::build: compilación DI solo en producción
- DashboardService: queries califican `facturas_venta.*` (evita ambigüedad de deleted_at en JOIN)
- `docs/postman-collection.json` con 11 requests de auth

**Commit `3dfb7a64` — feat: frontend Next.js 14 (scaffold + auth + dashboard + listados)**
- Next.js 14.2 App Router + TypeScript strict + Static Export
- Tailwind con paleta #663399 / #9CC930 / #161922 / #FFFFFF
- Fuente Saira via next/font/google
- **pnpm 9.12.3** con `onlyBuiltDependencies` allow-list por seguridad
- Axios con interceptors (refresh automático en 401, dedup)
- Zustand para auth (solo memoria, no localStorage)
- Componentes UI base: Button, Input, Label, Card, Badge (shadcn-style locales)
- Layout: Sidebar (permisos por rol), Topbar, AppShell con guard
- Dashboard: KpiCard, TendenciaChart, AgingChart, TopClientesChart
- Recharts para gráficos, Sonner para toasts
- Páginas: /login, /cambiar-password, /dashboard, /facturas, /clientes
- Hooks: useAuth, useDashboard, useClientes, useFacturas
- Fix CORS: middleware reorganizado como outermost (aplica headers también en errores)

### Fase 2 — Servicios (6 chunks)

**Commit `a22e0bd0` — feat(servicios) chunk 1: migraciones**
- 3 tablas nuevas: `servicios`, `servicio_cuotas`, `servicio_ajustes`
- ALTER en `facturas_venta`: agregar `servicio_cuota_id` con FK SET NULL
- 19 FKs en total en la DB

**Commit `08f98425` — feat(servicios) chunk 2: modelos Eloquent + relaciones**
- 3 modelos nuevos: Servicio (con SoftDeletes + esProyecto/esMantenimiento/esIndefinido/estaActivo helpers), ServicioCuota (con ESTADOS_RESUELTOS + esEditable), ServicioAjuste (con tipos PROGRAMADO/ESPONTANEO)
- Cliente actualizado con relación `servicios()`
- FacturaVenta actualizado con `servicioCuota()` + cast

**Commit `02592add` — feat(servicios) chunk 3: CronogramaGenerator**
- Helper puro estático sin DB
- 5 escenarios cubiertos:
  1. PROYECTO: porcentajes ingresados por usuario
  2. MANTENIMIENTO mes_calendario DEFINIDO con última cuota proporcional
  3. MANTENIMIENTO mes_calendario INDEFINIDO con rolling window de 12 cuotas
  4. MANTENIMIENTO intervalo_dias DEFINIDO con última proporcional
  5. MANTENIMIENTO intervalo_dias INDEFINIDO
- Helper `proximoDiaDelMes()` que ajusta día 31 a último día disponible (28/29/30)
- Etiquetas: "1 de 12 — Junio 2026" / "Junio 2026" (indefinido) / "Cuota N de M (proporcional X días)"
- Script de smoke test en `scripts/test_cronograma.php`

**Commit `76ad1dce` — feat(servicios) chunk 4: CRUD básico**
- ServicioValidator con reglas por tipo (proyecto vs mantenimiento)
- Tolerancia 0.01 en suma de porcentajes
- ServicioRepository con paginate + tieneCuotasFacturadas + todasCuotasResueltas
- ServicioService: create en transacción, update con regeneración inteligente de cronograma
- ServiciosController + 5 rutas CRUD

**Commit `57927cdf` — feat(servicios) chunk 5: acciones cuotas + estado servicio**
- ServicioCuotaService: editar/omitir/cancelar/facturar
- Facturar desde cuota: precarga + permisos por rol (admin A / ventas B)
- TDC obligatorio si USD
- Autocompleta servicio si todas las cuotas resueltas (excepto indefinidos)
- ServicioService extendido: pausar/reanudar (2 modos)/cancelar/extender
- 8 endpoints nuevos
- FacturaValidator: ahora acepta `servicio_cuota_id`

**Commit `f1148dcb` — feat(servicios) chunk 6: ajustes de tarifa**
- ServicioAjusteValidator con modo monto o porcentaje
- ServicioAjusteService: crear + aplicar + listar + eliminar
- Cálculo bidireccional (si vino monto calcula %, si vino % calcula nuevo importe)
- Espontáneo con fecha ≤ hoy aplica automáticamente
- Aplicación actualiza importe_base del servicio + cuotas pendientes desde cuota_desde_id
- Cuotas proporcionales escalan correctamente
- 4 endpoints nuevos

### Otros commits
**Commit `8907912` — chore(scripts): db-dump.sh y db-restore.sh**
- Scripts para portabilidad de DB local entre máquinas
- Lee credenciales de .env
- Output gzip en backups/ (gitignored)
- Restore con confirmación + verificación

**Commit `8417b23` — docs: handoff para Claude Code en otra máquina**

---

## 9. Estado actual de los datos en la DB local

(Si se restaura el dump `backups/ithub-dump-20260522-1816.sql.gz`)

### Usuarios
- 1 admin: `nbaldelli@intellihelp.tech` / `MiPwSegura2026!` (must_change_password=false)

### Clientes
- ACME S.A. — CUIT 30-12345678-1 (id=1)
- Mercado Libre S.R.L. — CUIT 30-70308853-4 (id=2)

### Facturas (4)
- 0001-00000001 ARS 121.000 cobrada (id=1)
- 0001-00000002 USD vencida (id=2)
- 0001-00000003 ARS vencida hace +1 año (id=3)
- 0001-00000004 ARS 100.000 (id=4, generada desde cuota del servicio 3)

### Servicios (3)
- id=1: Proyecto "Implementación CRM ACME" 30/40/30 = $1M ARS — **cancelado** (cuotas todas canceladas)
- id=2: Mantenimiento USD indefinido "Soporte mensual ML", día 15, **+40% aplicado** (importe_base ahora USD 700) — extendido hasta 2026-11-21
- id=3: Mantenimiento ARS "Soporte 3 meses ACME" jun-sep, día 5 — primera cuota (id=16) facturada

### Ajustes
- 1 ajuste espontáneo aplicado a servicio 2: USD 500 → USD 700 (+40%)

---

## 10. Pendientes

### Backend
- ~~**Chunk 7:** Dashboard de Servicios~~ — **DONE** (4 endpoints separados: `/dashboard/servicios-activos`, `/cuotas-mes`, `/ajustes-proximos`, `/mrr`). MRR sin conversión consolidada — devuelto por moneda separada. `ServiciosMetricsService` expone constantes públicas para ajustar definiciones (estados que cuentan, ventanas, normalización a mensual).
- **Chunk 8:** Cron jobs
  - Rolling window de cuotas para mantenimientos indefinidos (extender mes a mes)
  - Recordatorios de cuotas próximas a vencer (configurable global + override por servicio)
  - Recordatorios de ajustes programados próximos a aplicarse
  - Recalcular estados de facturas (vencidas)
- Drive integration: upload de archivos a Drive con Service Account, estructura `Año/Mes/Cliente/`
- Mail recordatorios con PHPMailer + templates HTML
- Export Excel (PhpSpreadsheet) + PDF (Dompdf) + CSV de facturas
- Import masivo de facturas histórico (.xlsx/.csv) con wizard de 3 pasos
- CRUD usuarios (solo admin)
- Endpoint de auditoría (solo admin)
- Configuración admin (`/config` endpoint)
- Plantilla Excel para importación
- Postman collection completa (actualmente solo tiene auth)

### Frontend
- Página `/servicios` (lista + detalle con tabs cronograma/ajustes/historial)
- Forms de creación de servicio (proyecto vs mantenimiento)
- Modal "Facturar cuota" desde el detalle del servicio
- Forms de creación/edición de facturas (actualmente la lista es read-only)
- Forms de creación/edición de clientes
- Widgets de servicios en `/dashboard`
- Página `/usuarios` (solo admin)
- Página `/auditoria` (solo admin)
- Página `/configuracion` (solo admin)
- Página `/mi-perfil`
- Página `/facturas/importar` (wizard)
- Vista detalle de factura con archivos y archivos uploader
- Botón export Excel/PDF/CSV

### Deploy + docs
- Deploy Hostinger (doc paso a paso en `docs/deploy-hostinger.md`)
- Configuración SSL + HSTS en hPanel
- Setup del cron job en Hostinger
- README detallado por componente (api, web, raíz)
- Manual de usuario final (PDF)
- Setup de Google Drive (`docs/google-drive-setup.md`)

---

## 11. Cómo retomar en una máquina nueva

```bash
# 1. Clonar
git clone https://github.com/nicobaldelli/ITHub.git
cd ITHub

# 2. Copiar .env raíz y api/.env desde otra máquina (NO están en git)

# 3. Copiar el último dump a backups/

# 4. Levantar stack
docker compose up -d

# 5. Instalar deps PHP
docker compose exec api composer install

# 6. Restaurar DB
./scripts/db-restore.sh backups/ithub-dump-*.sql.gz

# 7. Verificar
curl http://localhost:8080/api/v1/health

# 8. (Opcional) Frontend
docker compose --profile frontend up -d web
```

---

## 12. Stack final

| Componente | Tech |
|---|---|
| Backend lang | PHP 8.2 |
| Backend framework | Slim 4.15 |
| Backend ORM | Eloquent 10 (illuminate/database + pagination) |
| JWT | firebase/php-jwt v7 (HS256) |
| Migrations | Phinx 0.16 |
| Cache (rate limit) | Symfony Cache filesystem |
| Mail | PHPMailer 6.9 |
| Excel | PhpSpreadsheet 2.0 |
| PDF | Dompdf 2.0 |
| Drive | google/apiclient 2.x (solo servicio Drive) |
| Logging | Monolog 3 |
| DB | MySQL 8.0 utf8mb4 timezone AR |
| Frontend | Next.js 14.2 App Router |
| Frontend lang | TypeScript strict |
| Package manager | **pnpm 9.12.3** |
| UI | Tailwind CSS 3.4 + componentes shadcn-style locales |
| Charts | Recharts 2.13 |
| State | Zustand 4.5 (solo memoria) |
| HTTP | Axios 1.7 |
| Forms | React Hook Form 7.53 + Zod 3.23 |
| Tables | TanStack Table 8.20 |
| Notifications | Sonner 1.5 |
| Icons | Lucide React |
| Dates | date-fns 3.6 (locale es) |
| Auth output | Static Export (`output: 'export'`) |

---

## 13. Identidad visual

| Token | Color | Uso |
|---|---|---|
| Fondo | `#FFFFFF` | Background global |
| Primario | `#663399` | Botones, sidebar, énfasis |
| Texto/Neutros | `#161922` | Texto principal, iconos, bordes sutiles |
| Acento | `#9CC930` | Solo indicadores positivos (cobrado, OK) — uso moderado |

Fuente única: **Saira** (Google Fonts), pesos 400/500/600/700.

---

## 14. Archivos clave del repo

```
ITHub/
├── CLAUDE.md                         # memoria del proyecto (working style)
├── README.md                         # quick start
├── docker-compose.yml                # stack local: db + api + web + adminer + mailhog
├── docker/php/Dockerfile             # PHP 8.2 + Apache custom (réplica Hostinger)
├── .env                              # local (gitignored)
├── .env.example                      # plantilla
├── docs/
│   ├── seguridad.md                  # arquitectura de seguridad completa (~600 líneas)
│   ├── postman-collection.json       # endpoints de auth para Postman
│   ├── handoff-claude-code.md        # handoff para retomar en otra máquina
│   └── historia-completa.md          # ESTE DOCUMENTO
├── scripts/
│   ├── db-dump.sh                    # backup completo de DB
│   └── db-restore.sh                 # restaurar dump
├── api/
│   ├── composer.json                 # PHP deps (Slim 4, Eloquent 10, JWT v7, etc)
│   ├── .env                          # local (gitignored)
│   ├── .env.example
│   ├── public/
│   │   ├── index.php
│   │   └── .htaccess                 # security headers + front controller
│   ├── config/
│   │   ├── settings.php              # config con validación de env
│   │   ├── container.php             # PHP-DI definitions
│   │   └── .htaccess                 # Require all denied
│   ├── db/
│   │   ├── phinx.php
│   │   ├── migrations/               # 11 migraciones
│   │   └── seeds/                    # AdminUserSeeder + ConfigAppSeeder
│   ├── src/
│   │   ├── Bootstrap/                # App, Middleware, Routes
│   │   ├── Controllers/              # Auth, Clientes, Facturas, Servicios, Dashboard, Health
│   │   ├── Services/                 # Auth, Cliente, Factura, Dashboard, Servicio*, Ajuste, Auditoria, Jwt, CronogramaGenerator
│   │   ├── Repositories/             # Cliente, Factura, Servicio
│   │   ├── Models/                   # 10 modelos Eloquent
│   │   ├── Middleware/               # 8 middlewares de seguridad
│   │   ├── Validators/               # Cliente, Factura, Servicio, ServicioAjuste
│   │   ├── Exceptions/               # AppException + 6 variantes
│   │   ├── Helpers/                  # CuitValidator
│   │   └── Support/                  # PiiFilter, ResponseFactory, ContainerProvider
│   ├── scripts/
│   │   └── test_cronograma.php       # smoke test del generador
│   └── storage/
│       ├── logs/                     # Monolog
│       ├── cache/                    # DI compilado (prod)
│       ├── ratelimit/                # cache symfony
│       ├── exports/                  # PDFs/Excel temporales
│       ├── imports/                  # uploads
│       └── credentials/              # service-account.json (chmod 0600)
└── web/
    ├── package.json                  # pnpm 9
    ├── .npmrc                        # pnpm config
    ├── tailwind.config.ts            # paleta corporativa
    ├── next.config.js                # output: 'export'
    ├── tsconfig.json                 # strict
    ├── .env.local                    # NEXT_PUBLIC_API_URL
    └── src/
        ├── app/                      # App Router
        │   ├── layout.tsx            # Saira + Toaster
        │   ├── page.tsx              # redirector
        │   ├── login/page.tsx
        │   ├── cambiar-password/page.tsx
        │   ├── dashboard/page.tsx
        │   ├── facturas/page.tsx
        │   └── clientes/page.tsx
        ├── components/
        │   ├── ui/                   # Button, Input, Label, Card, Badge
        │   ├── layout/               # Sidebar, Topbar, AppShell
        │   ├── dashboard/            # KpiCard + 3 charts
        │   └── facturas/             # EstadoBadge
        ├── hooks/                    # useAuth, useDashboard, useClientes, useFacturas
        ├── lib/                      # api (axios), format (locale AR), utils
        ├── stores/                   # auth.ts (Zustand memoria)
        └── types/                    # api, factura, cliente, dashboard
```

---

## 15. Comandos comunes (cheat sheet)

```powershell
# Levantar stack
docker compose up -d                          # sin frontend
docker compose --profile frontend up -d       # con frontend

# Ver estado
docker compose ps

# Ver logs
docker compose logs -f api
docker compose logs -f web

# Apagar todo
docker compose down                           # mantiene volúmenes
docker compose down -v                        # borra DB (reset total)

# Migraciones / seeds
docker compose exec api vendor/bin/phinx migrate -c db/phinx.php
docker compose exec api vendor/bin/phinx seed:run -c db/phinx.php

# Limpiar rate limit (si te bloqueaste haciendo pruebas)
docker compose exec api find /var/www/html/storage/ratelimit -type f -delete

# Console MySQL
docker compose exec db mysql -uithub -p<DB_PASSWORD> ithub

# Composer
docker compose exec api composer install
docker compose exec api composer dump-autoload --optimize

# Smoke test del generador de cronograma
docker compose exec api php scripts/test_cronograma.php

# DB dump/restore
./scripts/db-dump.sh
./scripts/db-restore.sh backups/ithub-dump-*.sql.gz
```

---

## 16. URLs locales

| Servicio | URL |
|---|---|
| API | http://localhost:8080/api/v1 |
| Frontend (web) | http://localhost:3000 |
| Adminer (UI DB) | http://localhost:8081 |
| MailHog (mails) | http://localhost:8025 |
| MySQL | localhost:3306 |
