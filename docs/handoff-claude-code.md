# Handoff para Claude Code

> **Cómo usar este archivo:** abrí Claude Code en la PC de casa, pegale el contenido de la sección **"Mensaje para pegar"** abajo. Va a leer `CLAUDE.md` solo y recuperar el contexto del proyecto.

---

## Estado del proyecto al pausar

**Repo:** https://github.com/nicobaldelli/ITHub
**Rama:** `master`
**Último commit:** `8de1b02` — `docs: deploy a Hostinger + setup de Google Drive`

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

### ✅ Backend completo (todo lo de la fase MVP)
- ABM Usuarios (admin)
- Configuración (admin) — lectura/edición runtime de config_app
- Auditoría (visor admin)
- SMTP + notificaciones por mail con idempotencia (`notificaciones_enviadas`)
- Cron diario unificado: recalcular vencidas + rolling window de
  mantenimientos indefinidos + recordatorios. Soporta CLI y HTTP con
  CRON_TOKEN o admin manual desde UI
- Google Drive integration: upload/listado/borrado de adjuntos en
  facturas. Service Account con scope drive.file, estructura
  año/mes/cliente creada automáticamente
- Exportes de facturas a Excel (PhpSpreadsheet), CSV (con BOM y
  anti-CSV-injection) y PDF (Dompdf, A4 landscape)

### 🚧 Pendiente del backend (no críticos)
- **Import histórico**: levantar Excel/CSV masivo de facturas viejas
  con wizard de 3 pasos (upload → preview con errores → confirm)

### ✅ Facturas CRUD UI
- Listado + filtros + 3 botones de exportar (Excel/CSV/PDF)
- Detalle `/facturas/[id]` con cards organizadas
- FacturaActions: marcar/desmarcar cobrada, eliminar
- Alta + edición con autofill desde cliente
- AdjuntosCard con upload/borrado a Google Drive

### ✅ Páginas de admin
- `/usuarios` ABM completo con reset-password y password temporal
- `/configuracion` editor de config_app con botones de cron manual
- `/auditoria` visor de bitácora con filtros y detalle JSON

### 🚧 Pendiente del frontend (no críticos)
- Wizard de import histórico (cuando se haga el backend)

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

El MVP está cerrado end-to-end. El único pendiente "feature" es el
**import histórico** de facturas viejas (Excel/CSV masivo) — útil sólo
si vas a migrar datos preexistentes. No es crítico para arrancar.

Las dos opciones recomendadas para la próxima sesión son:

**Opción A — Probar todo en local y deployar:**
Seguir `docs/deploy-hostinger.md` para publicar. Configurar Google Drive
con `docs/google-drive-setup.md`. Después de validar productivo, ahí sí
tiene sentido agregar el import histórico.

**Opción B — Import histórico de facturas:**
Wizard de 3 pasos: upload CSV → preview con errores por fila → confirmar.
Útil para cargar el histórico que existe en Excel.
