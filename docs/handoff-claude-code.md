# Handoff para Claude Code

> **Cómo usar este archivo:** abrí Claude Code en la PC de casa, pegale el contenido de la sección **"Mensaje para pegar"** abajo. Va a leer `CLAUDE.md` solo y recuperar el contexto del proyecto.

---

## Estado del proyecto al pausar

**Repo:** https://github.com/nicobaldelli/ITHub
**Rama:** `master`
**Último commit:** `6beaf29` — `feat(servicios-web): chunk 9.5 - widgets de servicios en /dashboard`

### ✅ Backend completo
- Auth JWT con refresh rotation, lockout, audit (chunk 1)
- CRUD Clientes (chunk 2)
- CRUD Facturas con filtros + check_cobranza (chunk 3)
- Dashboard KPIs (DSO/ADD/aging/top/distribuciones) (chunk 4)
- **Servicios (6 chunks completos):**
  1. Migraciones (`servicios`, `servicio_cuotas`, `servicio_ajustes`, FK en `facturas_venta`)
  2. Modelos Eloquent con relaciones
  3. CronogramaGenerator (helper puro testeado con 5 escenarios)
  4. CRUD básico (validator + repo + service + controller + rutas)
  5. Acciones de cuotas (editar/omitir/cancelar/facturar) + acciones de estado del servicio (pausar/reanudar/cancelar/extender)
  6. Ajustes de tarifa (programados + espontáneos, modo monto o %, audit completo)

### ✅ Frontend
- Next.js 14 + pnpm + Saira + Tailwind con colores corporativos
- Login + cambiar-password con flow `must_change_password`
- AppShell con guard de rutas, sidebar y topbar
- **Auth con sesión persistente** (fix `4feeccd`): el csrf token se lee de cookie no-HttpOnly cuando el store está vacío al reload
- **Páginas listadas:** dashboard (incluye sección de servicios), facturas (lista con filtros), clientes (lista)
- **Clientes CRUD UI completo** (chunk 10): listado + detalle + alta + edición + borrado con confirmación
- **Servicios CRUD UI completo** (chunks 9.1–9.5):
  - 9.1 Listado con filtros (tipo/estado/moneda/búsqueda)
  - 9.2 Detalle con tabs Resumen/Cronograma/Ajustes
  - 9.3 Alta con form dinámico (proyecto con `useFieldArray` de cuotas / mantenimiento con modo de facturación)
  - 9.4 Modales de acciones (pausar/reanudar/cancelar/extender/eliminar/editar el servicio · facturar/omitir/cancelar cuotas · crear/aplicar/eliminar ajustes)
  - 9.5 Sección de servicios en /dashboard con 4 cards de resumen + 2 listas

### 🚧 Pendiente del backend
- **Chunk 8** — Cron job: rolling window de cuotas para indefinidos + recordatorios de ajustes próximos a vencer
- **Drive integration** (upload de archivos a Drive con Service Account)
- **Mail recordatorios** de vencimientos con PHPMailer
- **Export Excel/PDF/CSV** + **Import** masivo de facturas
- **CRUD usuarios** (solo admin)

### 🚧 Pendiente del frontend (mayor)
- **Facturas CRUD UI**: forms de alta/edición, vista detalle, modal de check_cobranza, borrado
- **Vista detalle de Factura**: para que el link "Ver factura" desde una cuota facturada no quede roto
- Página/UI para Usuarios (admin)
- Página/UI para Configuración (`/configuracion`)
- Página/UI para Auditoría (visor)

### 🚧 Otros pendientes
- Deploy Hostinger (doc paso a paso)
- READMEs detallados por componente

---

## Cómo arrancar en casa

```powershell
# 1. Clonar
git clone https://github.com/nicobaldelli/ITHub.git
cd ITHub

# 2. Copiar los .env que trajiste por pendrive/Drive a:
#    .env                  (raíz)
#    api\.env

# 3. Copiar el dump de DB a:
#    backups\ithub-dump-YYYYMMDD-HHMM.sql.gz

# 4. Levantar el stack
docker compose up -d

# 5. Instalar deps de PHP
docker compose exec api composer install

# 6. Restaurar DB
# Si tenés Git Bash / WSL:
./scripts/db-restore.sh backups/ithub-dump-YYYYMMDD-HHMM.sql.gz

# Si solo PowerShell, corré los comandos del script a mano:
# (mismas instrucciones que en docs/handoff-claude-code.md)

# 7. Verificar
curl http://localhost:8080/api/v1/health
# Esperado: {"data":{"status":"ok","db":"up","time":"..."}}

# 8. (Opcional) Frontend
docker compose --profile frontend up -d web
# http://localhost:3000
```

---

## Credenciales

- **Admin app:** `nbaldelli@intellihelp.tech` / `MiPwSegura2026!` (`must_change_password=false` ya rotado)
- **MySQL:** ver `.env` raíz (DB_ROOT_PASSWORD y DB_PASSWORD)

---

## Working style importante

Está en `CLAUDE.md` raíz, pero recordá:

- **Español rioplatense en todo** (chat + UI + comentarios user-facing)
- **Preguntar antes de asumir** ante cualquier duda funcional, UX o de schema
- **Seguridad como prioridad** — defaultear al lado más seguro y avisar
- **Commits descriptivos en español, sin emojis** en mensajes de commit
- **Sin emojis en el código** salvo que se pidan
- **Sugerencias bienvenidas** — si veo mejoras razonables, proponerlas

---

## Próximo paso recomendado al volver

El módulo Servicios está cerrado end-to-end y Clientes tiene CRUD UI completo. Próximas opciones:

**Opción A — Facturas CRUD UI:**
Forms de alta/edición, vista detalle con adjuntos, modal de check_cobranza, borrado. Es el segundo módulo más usado a diario y deja el flujo "facturar una cuota → ver la factura" funcionando completo.

**Opción B — Chunk 8: Cron jobs + SMTP:**
Rolling window de cuotas para indefinidos + recordatorios por mail. Requiere también la integración PHPMailer.

**Opción C — Empezar deploy en Hostinger:**
Documentar y scriptear el deploy del backend + frontend (Static Export). Tiene sentido si querés validar end-to-end en un entorno real.

**Opción D — ABM Usuarios / Configuración / Auditoría:**
Las 3 páginas de admin que faltan en el frontend.
