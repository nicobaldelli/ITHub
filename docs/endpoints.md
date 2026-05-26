# Endpoints REST — ITHub API

Estado **real** a partir de `api/src/Bootstrap/Routes.php` al 2026-05-26.

**Base URL:** `/api/v1`
**Auth:** Bearer JWT en header `Authorization`. Refresh en cookie HttpOnly `refresh_token` con path `/api/v1/auth`.
**Formato:** JSON UTF-8.
**Rate limits:** `login` 5/15min email + 20/15min IP · `general` 120/min.

Convenciones de respuesta:

```jsonc
// éxito
{ "data": ..., "meta": { ... } }

// error
{ "error": { "code": "VALIDATION_ERROR", "message": "...", "details": [...] } }
```

Códigos de roles: `admin`, `cobranzas`, `ventas`, `visualizador`.
Salvo aclaración, todo lo no-auth requiere JWT válido y aplica `general` rate limit.

---

## Health

| Método | Path | Auth | Roles | Descripción |
|---|---|---|---|---|
| GET | `/health` | No | — | Ping de liveness (DB + versión) |

---

## Auth (`/auth`)

| Método | Path | Auth | Roles | Rate | Descripción |
|---|---|---|---|---|---|
| POST | `/auth/login` | No | — | `login` | Email + password → access JWT + cookie refresh |
| POST | `/auth/refresh` | Cookie | — | `refresh` | Rota refresh y devuelve nuevo access |
| POST | `/auth/logout` | Cookie | — | — | Revoca el refresh actual |
| GET | `/auth/me` | JWT | todos | — | Perfil del usuario |
| POST | `/auth/change-password` | JWT | todos | `change_password` | Cambia password (requiere actual) |
| POST | `/auth/logout-all` | JWT | todos | — | Revoca toda la familia de refresh tokens del user |

---

## Clientes (`/clientes`)

| Método | Path | Roles | Descripción |
|---|---|---|---|
| GET | `/clientes` | todos | Lista paginada · query: `search`, `activo`, `page`, `per_page` |
| GET | `/clientes/{id}` | todos | Detalle |
| GET | `/clientes/{id}/facturas` | todos | Facturas del cliente · query: `estado`, `from`, `to`, `page` |
| POST | `/clientes` | admin, ventas | Alta |
| PUT | `/clientes/{id}` | admin, ventas | Edición |
| DELETE | `/clientes/{id}` | admin | Soft delete |

---

## Facturas (`/facturas`)

| Método | Path | Roles | Descripción |
|---|---|---|---|
| GET | `/facturas` | todos | Lista paginada · query: `search`, `cliente_id`, `tipo`, `estado`, `moneda`, `from`, `to`, `vencidas`, `page`, `per_page`, `sort` |
| GET | `/facturas/{id}` | todos | Detalle (con cliente + adjuntos) |
| GET | `/facturas/{id}/historial` | todos | Auditoría de la factura |
| POST | `/facturas` | admin, ventas | Alta |
| PUT | `/facturas/{id}` | todos | Edición — permisos finos resueltos en service (cobranzas edita subset: `fecha_pago`, `total_cobrado`, `observaciones`) |
| PATCH | `/facturas/{id}/check-cobranza` | admin, cobranzas | Marcar/desmarcar check de cobranza |
| DELETE | `/facturas/{id}` | admin | Soft delete |

---

## Servicios (`/servicios`)

CRUD y acciones de estado.

| Método | Path | Roles | Descripción |
|---|---|---|---|
| GET | `/servicios` | todos | Lista paginada · query: `cliente_id`, `tipo`, `estado`, `search`, `page` |
| GET | `/servicios/{id}` | todos | Detalle (con cliente, cuotas y ajustes) |
| POST | `/servicios` | admin, ventas | Alta + cronograma inicial generado |
| PUT | `/servicios/{id}` | admin, ventas | Edición de cabecera |
| DELETE | `/servicios/{id}` | admin | Soft delete |
| PATCH | `/servicios/{id}/pausar` | admin, ventas | Pausa el servicio (las cuotas no se generan) |
| PATCH | `/servicios/{id}/reanudar` | admin, ventas | Reanuda · body: `{ "modo": "correr_cronograma" \| "cancelar_pendientes" }` |
| PATCH | `/servicios/{id}/cancelar` | admin, ventas | Cancela servicio (cuotas pendientes → `cancelada`) |
| POST | `/servicios/{id}/extender` | admin, ventas | Extiende `fecha_fin` y genera cuotas nuevas |

### Cuotas (`/servicios/{id}/cuotas/{cid}`)

| Método | Path | Roles | Descripción |
|---|---|---|---|
| PATCH | `…/cuotas/{cid}` | admin | Edita importe/fecha de una cuota pendiente |
| PATCH | `…/cuotas/{cid}/omitir` | admin | Marca como `omitida` |
| PATCH | `…/cuotas/{cid}/cancelar` | admin | Marca como `cancelada` |
| POST | `…/cuotas/{cid}/facturar` | admin, ventas | Crea factura desde la cuota → `factura_id` y estado `facturada` |

### Ajustes de tarifa (`/servicios/{id}/ajustes`)

| Método | Path | Roles | Descripción |
|---|---|---|---|
| GET | `…/ajustes` | todos | Lista ajustes del servicio |
| POST | `…/ajustes` | admin, ventas | Crea ajuste · body: `{ tipo, fecha_aplicacion, cuota_desde_id, importe_nuevo \| porcentaje, observaciones }` |
| POST | `…/ajustes/{aid}/aplicar` | admin, ventas | Aplica el ajuste (recalcula cuotas pendientes ≥ `cuota_desde_id`) |
| DELETE | `…/ajustes/{aid}` | admin | Elimina un ajuste no aplicado |

---

## Dashboard (`/dashboard`)

| Método | Path | Roles | Descripción |
|---|---|---|---|
| GET | `/dashboard/kpis` | todos | DSO, ADD, tasa recuperación, total emitido, total cobrado, etc. · query: `from`, `to`, `moneda` |
| GET | `/dashboard/tendencias` | todos | Serie temporal facturado vs cobrado · query: `granularidad` (`mes` / `trimestre`), `from`, `to` |
| GET | `/dashboard/aging` | todos | Buckets de vencimiento: corriente, 1–30, 31–60, 61–90, >90 |
| GET | `/dashboard/top-clientes` | todos | Top N por facturación · query: `limit`, `from`, `to` |
| GET | `/dashboard/distribucion-tipo` | todos | Reparto por tipo de factura |
| GET | `/dashboard/distribucion-moneda` | todos | Reparto ARS vs USD |

---

## Fallback

| Método | Path | Respuesta |
|---|---|---|
| `*` | `/{routes:.+}` | `404 { "error": { "code": "NOT_FOUND" } }` |

---

## Pendientes (no implementados aún)

Endpoints del spec original que **todavía no existen** en el router:

### Adjuntos (Google Drive)
- `GET /facturas/{id}/archivos` — listar adjuntos
- `POST /facturas/{id}/archivos` — subir multipart, crea estructura `Año/Mes/Cliente/` en Drive
- `GET /facturas/{id}/archivos/{aid}` — redirige a `drive_view_url`
- `DELETE /facturas/{id}/archivos/{aid}` — borra de Drive + tabla

### Exportes
- `GET /facturas/export?formato=xlsx|pdf|csv` — bajada del listado filtrado
- `GET /clientes/export?formato=xlsx|csv`
- `GET /servicios/{id}/cronograma/export?formato=xlsx|pdf`
- `GET /dashboard/export?formato=xlsx|pdf` — reporte ejecutivo

### Importación histórica
- `POST /facturas/import` — multipart Excel/CSV → preview + validación
- `POST /facturas/import/confirm` — confirma el batch validado
- `GET /imports/{id}` — estado e informe de errores

### Config y administración
- `GET /config` — todas las claves (admin)
- `PUT /config/{clave}` — actualizar (admin)
- `GET /users` — listado (admin)
- `POST /users` — alta (admin)
- `PUT /users/{id}` — edición (admin)
- `POST /users/{id}/reset-password` — fuerza must_change_password (admin)
- `DELETE /users/{id}` — desactivar (admin)

### Auditoría
- `GET /auditoria` — listado filtrable (admin) · query: `entidad`, `accion`, `user_id`, `from`, `to`

### Notificaciones (cron + endpoint manual)
- `POST /notificaciones/disparar` — endpoint protegido para correr cron manualmente desde panel admin
- Job CLI `scripts/cron_notificaciones.php` invocado por crontab Hostinger

### Servicios — mantenimientos indefinidos
- Job CLI `scripts/cron_rolling_window.php` — extiende cuotas mensualmente

Confirmar prioridad antes de implementar.
