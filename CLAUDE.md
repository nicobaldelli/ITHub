# CLAUDE.md — Memoria del proyecto ITHub

> Este archivo lo lee Claude Code en cada sesión para no perder contexto entre conversaciones. Ediciones manuales bienvenidas.

## 🧭 Working style del owner (Nico)

- **Idioma:** español rioplatense (argentino) en toda la comunicación y código user-facing.
- **Preguntá antes de asumir.** Ante cualquier duda funcional o sugerencia, plantearla — no decidir unilateralmente. Esto incluye:
  - Reglas de negocio dudosas
  - Trade-offs UX (ej: "modal vs nueva página")
  - Performance vs simplicidad
  - Cambios de schema o renombres
- **Sugerencias bienvenidas.** Si veo una mejora razonable a algo ya implementado, proponerla.
- **Seguridad prioritaria.** Ante cualquier compromiso, default al lado más seguro y avisar.
- **Commits con mensaje descriptivo** en español, sin emojis en mensajes de commit.
- **No agregar emojis al código** salvo que se pidan explícitamente. Sí en el chat para claridad.

## 🏗️ Stack confirmado

- **Backend:** PHP 8.2 + Slim 4 + Eloquent + MySQL 8 + Phinx + firebase/php-jwt v7
- **Frontend:** Next.js 14 (App Router) + TypeScript strict + Tailwind + Saira + Recharts + Zustand + Axios
- **Package manager web:** **pnpm 9** (no npm). `onlyBuiltDependencies` whitelist en package.json.
- **Deploy:** Hostinger. Frontend como Static Export (`output: 'export'`).
- **Dominios:** `ithub.intellihelp.tech` (web) + `apithub.intellihelp.tech` (api)
- **Admin inicial:** `nbaldelli@intellihelp.tech` — password actual: `MiPwSegura2026!`

## 🎨 Branding

- Fondo `#FFFFFF`, primario `#663399`, texto `#161922`, acento `#9CC930`
- Fuente única: **Saira**
- Verde `#9CC930` solo para indicadores positivos, NO grandes áreas
- Sin emojis en la UI

## 🔒 Seguridad — decisiones tomadas

- bcrypt cost 12, JWT HS256 con refresh rotation y detección de reuso
- Access token solo en memoria (no localStorage)
- Refresh en cookie HttpOnly + SameSite=Strict, scoped a `/api/v1/auth`
- CORS con whitelist estricta, headers Strict-Transport-Security + CSP + COOP
- Rate limit: login 5/15min email + 20/15min IP, general 120/min
- Mass assignment whitelist con `$fillable` en todos los modelos
- Auditoría de toda acción sensible (con IP + request_id + diff before/after)
- 2 usuarios MySQL: runtime con privilegios mínimos + migrate solo via SSH
- Ver `docs/seguridad.md` para detalle completo

## 📋 Modelo de datos — confirmado

### Entidades base
- `users` (4 roles: admin / cobranzas / ventas / visualizador)
- `clientes`, `facturas_venta`, `factura_archivos`
- `auditoria`, `config_app`, `refresh_tokens`

### Servicios (en desarrollo)
- `servicios`: tipo (proyecto/mantenimiento), moneda, importe_total, frecuencia_ajuste_meses, **fecha_fin nullable** (mantenimientos indefinidos permitidos)
- `servicio_cuotas`: cronograma de cobro, una cuota → una factura por defecto
- `servicio_ajustes`: historial de cambios de precio (programado o espontáneo)

### Decisiones funcionales clave sobre Servicios
- **Proyectos**: alcance cerrado, cuotas con porcentajes que suman 100%, fechas previstas se cargan una por una al crear (editables después)
- **Mantenimientos**: modo `mes_calendario` (default, día configurable) o `intervalo_dias`
- **Mantenimientos indefinidos**: rolling window de 12 cuotas hacia adelante, extendido por cron mensualmente
- **Etiqueta de cuota** en mantenimientos indefinidos: solo mes calendario ("Junio 2026")
- **Ajuste de precio**: cualquiera de los dos modos (monto absoluto o %), el sistema calcula el otro. El admin **elige la cuota específica desde la cual aplica**
- **Frecuencia de ajuste** es editable durante la vida del servicio
- **TDC en USD**: ajustes se cargan en USD, conversión al TDC del día de la factura
- **Pausa de servicio**: el admin decide en el modal de "Reanudar" si cancela cuotas o corre el cronograma
- **Renovación**: botón "Extender" en el servicio existente (no se cierra y crea nuevo)
- **Auto-completar**: cuando todas las cuotas resolvieron (facturada/omitida/cancelada), el servicio pasa a `completado`. Indefinidos no se autocompletan.
- **Cancelación de servicio** → cuotas pendientes pasan a `cancelada` automáticamente
- **Cuotas facturadas o canceladas u omitidas no se modifican** por ajustes futuros
- **Cron de recordatorios**: configurable global + override por servicio. También avisa cuando hay un ajuste programado próximo
- **Roles**: admin + ventas crean/editan servicios y ajustes; cobranzas solo lectura

## 📦 Importante para tareas futuras

- Ante cualquier feature, primero **leer este archivo** y los `docs/seguridad.md` correspondientes
- Antes de modificar schema, asegurarse de no romper FKs existentes
- Todo cambio que afecte autenticación debe revalidarse contra OWASP Top 10
