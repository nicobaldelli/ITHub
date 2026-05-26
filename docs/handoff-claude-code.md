# Handoff para Claude Code

> **Cómo usar este archivo:** abrí Claude Code en la PC de casa, pegale el contenido de la sección **"Mensaje para pegar"** abajo. Va a leer `CLAUDE.md` solo y recuperar el contexto del proyecto.

---

## Estado del proyecto al pausar

**Repo:** https://github.com/nicobaldelli/ITHub
**Rama:** `master`
**Último commit:** `89079122` — `chore(scripts): db-dump.sh y db-restore.sh`

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
- Páginas: dashboard, facturas (lista con filtros), clientes (lista)
- **Pendiente del frontend:** página de Servicios (CRUD + lista de cuotas + ajustes), forms de facturas/clientes, integración del flow "facturar cuota"

### 🚧 Pendiente del backend
- **Chunk 8** — Cron job: rolling window de cuotas para indefinidos + recordatorios de ajustes próximos a vencer
- **Drive integration** (upload de archivos a Drive con Service Account)
- **Mail recordatorios** de vencimientos con PHPMailer
- **Export Excel/PDF/CSV** + **Import** masivo de facturas
- **CRUD usuarios** (solo admin)

### 🚧 Pendiente del frontend (mayor)
- Página `/servicios` (lista + detalle con tabs cronograma/ajustes/historial)
- Forms: crear/editar servicio (proyecto vs mantenimiento)
- Modal "Facturar cuota" desde el detalle del servicio
- Forms de facturas y clientes (actualmente solo hay listas read-only)
- Widgets de servicios en `/dashboard`

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

Chunk 7 (Dashboard de Servicios) ya está implementado — 4 endpoints separados bajo `/dashboard/*`. Próximas opciones:

**Opción A — Frontend de servicios (Chunk 9 del plan original):**
Implementar página `/servicios` con lista + detalle + forms + modales de acciones. Consume tanto el CRUD existente como los endpoints del dashboard.

**Opción B — Chunk 8: Cron jobs:**
Rolling window de cuotas para mantenimientos indefinidos + recordatorios por mail (requiere también la integración SMTP).

**Opción C — Empezar deploy en Hostinger:**
Documentar y scriptear el deploy del backend + frontend (Static Export).
