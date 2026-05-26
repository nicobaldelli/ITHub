# Schema de base de datos — ITHub

Estado **real** de las migraciones en `api/db/migrations/` al 2026-05-26.
Versión de Phinx más alta aplicada: `20260201000004`.

Motor: **MySQL 8.0** · charset `utf8mb4` · collation `utf8mb4_unicode_ci` · TZ `-03:00`.
Convenciones generales:

- PKs `bigint unsigned auto_increment`.
- Timestamps `created_at` / `updated_at` `datetime` (no `timestamp`, para evitar el rango 2038).
- Soft delete con `deleted_at datetime NULL` en entidades de negocio.
- FKs con prefijo `fk_<tabla_corta>_<col>`.
- Índices con prefijo `idx_<tabla_corta>_<col>` o `uq_<tabla_corta>_<col>` si es único.

---

## 1. `users`

Operadores del sistema. Roles fijos: `admin`, `cobranzas`, `ventas`, `visualizador`.

| Columna | Tipo | Null | Default | Notas |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | auto | PK |
| `nombre` | `varchar(100)` | NO | | |
| `apellido` | `varchar(100)` | NO | | |
| `email` | `varchar(150)` | NO | | **único** (`uq_users_email`) |
| `password_hash` | `varchar(255)` | NO | | bcrypt cost 12 |
| `rol` | `enum(admin, cobranzas, ventas, visualizador)` | NO | | idx |
| `activo` | `boolean` | NO | `true` | idx |
| `must_change_password` | `boolean` | NO | `false` | |
| `failed_login_attempts` | `int` | NO | `0` | lockout 5 intentos |
| `locked_until` | `datetime` | SÍ | NULL | |
| `last_login` | `datetime` | SÍ | NULL | |
| `last_login_ip` | `varchar(45)` | SÍ | NULL | soporta IPv6 |
| `created_at` / `updated_at` | `datetime` | NO | | |

---

## 2. `clientes`

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| `id` | `bigint unsigned` | NO | PK |
| `razon_social` | `varchar(200)` | NO | idx |
| `cuit` | `varchar(13)` | NO | **único** (`uq_clientes_cuit`) — formato `XX-XXXXXXXX-X` |
| `cuit_pais` | `varchar(20)` | SÍ | para clientes del exterior |
| `tipo_default` | `enum(tiposFactura)` | SÍ | tipo sugerido al crear factura |
| `direccion` | `varchar(255)` | SÍ | |
| `banco` | `varchar(100)` | SÍ | |
| `cbu` | `varchar(22)` | SÍ | |
| `alias` | `varchar(30)` | SÍ | |
| `plazo_pago_default` | `int` | SÍ | días |
| `mail_envio_factura` | `varchar(150)` | SÍ | |
| `contacto_envio_factura` | `varchar(150)` | SÍ | |
| `telefono_contacto_proveedores` | `varchar(50)` | SÍ | |
| `mail_gestion_cobranza` | `varchar(150)` | SÍ | |
| `contacto_gestion_cobranza` | `varchar(150)` | SÍ | |
| `telefono_contacto_cobranza` | `varchar(50)` | SÍ | |
| `observaciones` | `longtext` | SÍ | |
| `activo` | `boolean` | NO `true` | idx |
| `created_at` / `updated_at` | `datetime` | NO | |
| `deleted_at` | `datetime` | SÍ | idx (soft delete) |

**Tipos de factura (enum compartido con `facturas_venta.tipo`):**
`A`, `B`, `E`, `CREDITO_MIPYME_A`, `CREDITO_MIPYME_B`, `NC_A`, `NC_B`, `NC_E`, `ND_A`, `ND_B`, `ND_E`.

---

## 3. `facturas_venta`

Tabla central del sistema. Soporta facturas sueltas o vinculadas a una cuota de servicio.

| Columna | Tipo | Null | Default | Notas |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | auto | PK |
| `numero_factura` | `varchar(50)` | NO | | **único** (`uq_fv_numero`) |
| `cliente_id` | `bigint unsigned` | NO | | FK → `clientes.id` RESTRICT |
| `tipo` | `enum(tiposFactura)` | NO | | idx |
| `cuit` | `varchar(13)` | NO | | snapshot al emitir |
| `cuit_pais` | `varchar(20)` | SÍ | | |
| `moneda` | `enum(ARS, USD)` | NO | `ARS` | idx |
| `importe_sin_iva` | `decimal(15,2)` | NO | `0` | |
| `importe_con_iva` | `decimal(15,2)` | NO | `0` | |
| `importe_total_pesos` | `decimal(15,2)` | NO | `0` | conversión USD→ARS al TDC |
| `tdc` | `decimal(10,4)` | SÍ | | tipo de cambio si moneda=USD |
| `retenciones` | `decimal(15,2)` | NO | `0` | |
| `total_cobrado` | `decimal(15,2)` | NO | `0` | |
| `detalle_factura` | `longtext` | SÍ | | |
| `numero_mes` | `tinyint unsigned` | SÍ | | 1–12, mes cubierto (mantenimientos) |
| `mes_cubierto` | `varchar(50)` | SÍ | | etiqueta libre ("Junio 2026") |
| `fecha_factura` | `date` | NO | | idx |
| `fecha_envio` | `date` | SÍ | | |
| `banco` | `varchar(100)` | SÍ | | snapshot |
| `vencimiento` | `date` | SÍ | | idx |
| `cbu` | `varchar(22)` | SÍ | | snapshot |
| `alias` | `varchar(30)` | SÍ | | snapshot |
| `plazo_pago` | `int` | SÍ | | |
| `fecha_pago` | `date` | SÍ | | idx |
| `direccion` / contactos | `varchar` | SÍ | | snapshot del cliente |
| `observaciones` | `longtext` | SÍ | | |
| `check_cobranza` | `boolean` | NO | `false` | idx — flag manual de cobranza |
| `check_cobranza_user_id` | `bigint unsigned` | SÍ | | FK → `users.id` SET NULL |
| `check_cobranza_fecha` | `datetime` | SÍ | | |
| `drive_folder_id` | `varchar(100)` | SÍ | | ID de la carpeta del cliente/factura en Drive |
| `estado` | `enum(borrador, emitida, cobrada, vencida, anulada)` | NO | `emitida` | idx |
| `servicio_cuota_id` | `bigint unsigned` | SÍ | | FK → `servicio_cuotas.id` SET NULL (mig. 20260201000004) |
| `created_by` / `updated_by` | `bigint unsigned` | NO | | FK → `users.id` RESTRICT |
| `created_at` / `updated_at` | `datetime` | NO | | |
| `deleted_at` | `datetime` | SÍ | | idx |

---

## 4. `factura_archivos`

Adjuntos físicos almacenados en Google Drive; acá guardamos sólo metadatos + IDs.

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| `id` | `bigint unsigned` | NO | PK |
| `factura_id` | `bigint unsigned` | NO | FK → `facturas_venta.id` CASCADE |
| `drive_file_id` | `varchar(100)` | NO | ID del file en Drive (idx) |
| `nombre_archivo` | `varchar(255)` | NO | |
| `mime_type` | `varchar(100)` | SÍ | |
| `tamanio_bytes` | `bigint unsigned` | SÍ | |
| `drive_view_url` | `varchar(500)` | SÍ | webViewLink |
| `drive_download_url` | `varchar(500)` | SÍ | webContentLink |
| `uploaded_by` | `bigint unsigned` | NO | FK → `users.id` RESTRICT |
| `created_at` | `datetime` | NO | |

---

## 5. `auditoria`

Bitácora inmutable de toda acción sensible.

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| `id` | `bigint unsigned` | NO | PK |
| `user_id` | `bigint unsigned` | SÍ | FK → `users.id` SET NULL (idx) |
| `entidad` | `varchar(50)` | NO | `clientes`, `facturas_venta`, `servicios`, etc. |
| `entidad_id` | `bigint unsigned` | SÍ | (`idx_aud_entidad` compuesto con `entidad`) |
| `accion` | `enum(...)` | NO | ver listado abajo (idx) |
| `campos_modificados` | `json` | SÍ | `{ campo: [antes, despues] }` |
| `ip` | `varchar(45)` | SÍ | |
| `user_agent` | `varchar(255)` | SÍ | |
| `request_id` | `varchar(64)` | SÍ | mismo que en logs |
| `created_at` | `datetime` | NO | idx |

**Acciones:** `crear`, `editar`, `eliminar`, `marcar_cobrada`, `login`, `login_fallido`, `logout`, `export`, `import`, `archivo_subido`, `archivo_eliminado`, `config_actualizada`, `cambio_password`, `reset_password`.

---

## 6. `config_app`

Configuración runtime editable desde panel admin. PK string.

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| `clave` | `varchar(100)` | NO | **PK** |
| `valor` | `longtext` | SÍ | serializado según `tipo` |
| `tipo` | `enum(string, int, bool, json)` | NO `string` | guía de deserialización |
| `descripcion` | `varchar(255)` | SÍ | |
| `updated_by` | `bigint unsigned` | SÍ | FK → `users.id` SET NULL |
| `updated_at` | `datetime` | NO | |

**Claves precargadas (`ConfigAppSeeder`):**
`drive_root_folder_id`, `smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass`, `smtp_from`, `smtp_from_name`, `notif_dias_previos`, `notif_dias_vencida`, `notif_cc_emails`, `cron_hora_notif`.

---

## 7. `refresh_tokens`

Refresh JWT rotativos con detección de reuso (familia de tokens).

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| `id` | `bigint unsigned` | NO | PK |
| `user_id` | `bigint unsigned` | NO | FK → `users.id` CASCADE |
| `token_hash` | `char(64)` | NO | SHA-256 hex (**único**) |
| `family_id` | `char(36)` | NO | UUID v4, agrupa la familia |
| `expires_at` | `datetime` | NO | idx |
| `revoked_at` | `datetime` | SÍ | |
| `replaced_by_id` | `bigint unsigned` | SÍ | apunta al siguiente token de la cadena |
| `user_agent` | `varchar(255)` | SÍ | |
| `ip` | `varchar(45)` | SÍ | |
| `created_at` | `datetime` | NO | |

---

## 8. `servicios`

Acuerdo comercial con un cliente. Dos tipos: `proyecto` o `mantenimiento`.

| Columna | Tipo | Null | Default | Notas |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | auto | PK |
| `cliente_id` | `bigint unsigned` | NO | | FK → `clientes.id` RESTRICT |
| `tipo` | `enum(proyecto, mantenimiento)` | NO | | idx |
| `nombre` | `varchar(200)` | NO | | |
| `descripcion` | `longtext` | SÍ | | |
| `moneda` | `enum(ARS, USD)` | NO | `ARS` | |
| `importe_base` | `decimal(15,2)` | NO | | proyecto: total · mantenimiento: por cuota vigente |
| `fecha_inicio` | `date` | NO | | idx |
| `fecha_fin` | `date` | SÍ | | `NULL` = mantenimiento indefinido (idx) |
| `modo_facturacion` | `enum(mes_calendario, intervalo_dias)` | SÍ | | solo mantenimiento |
| `dia_facturacion` | `tinyint unsigned` | SÍ | | 1–31, modo `mes_calendario` |
| `intervalo_dias` | `int` | SÍ | | modo `intervalo_dias` |
| `frecuencia_ajuste_meses` | `int` | SÍ | | `NULL` = sin ajustes programados |
| `aviso_dias_previos` | `int` | SÍ | | override del default global de avisos |
| `estado` | `enum(activo, pausado, completado, cancelado)` | NO `activo` | idx |
| `pausado_at` | `datetime` | SÍ | | usado para correr cronograma al reanudar |
| `observaciones` | `longtext` | SÍ | | |
| `created_by` / `updated_by` | `bigint unsigned` | NO | | FK → `users.id` RESTRICT |
| `created_at` / `updated_at` | `datetime` | NO | | |
| `deleted_at` | `datetime` | SÍ | | idx |

---

## 9. `servicio_cuotas`

Cronograma de facturación. Una cuota → una factura por defecto.

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| `id` | `bigint unsigned` | NO | PK |
| `servicio_id` | `bigint unsigned` | NO | FK → `servicios.id` CASCADE (idx) |
| `numero_cuota` | `int unsigned` | NO | (`uq_sc_servicio_numero` con `servicio_id`) |
| `total_cuotas` | `int unsigned` | SÍ | NULL en mantenimientos indefinidos |
| `porcentaje` | `decimal(5,2)` | SÍ | solo proyectos |
| `importe` | `decimal(15,2)` | NO | en la moneda del servicio |
| `fecha_prevista` | `date` | NO | idx |
| `factura_id` | `bigint unsigned` | SÍ | FK → `facturas_venta.id` SET NULL (idx) |
| `estado` | `enum(pendiente, facturada, omitida, cancelada)` | NO `pendiente` | idx |
| `etiqueta` | `varchar(100)` | SÍ | "Anticipo", "Hito 1", "Junio 2026" |
| `es_proporcional` | `boolean` | NO `false` | última cuota corta |
| `dias_cubiertos` | `int unsigned` | SÍ | para proporcionales en modo `intervalo_dias` |
| `observaciones` | `longtext` | SÍ | |
| `created_at` / `updated_at` | `datetime` | NO | |

Regla: cuotas en estado `facturada`, `omitida` o `cancelada` **nunca se modifican** por ajustes futuros.

---

## 10. `servicio_ajustes`

Historial de cambios de tarifa. Tipos: `programado` (por ciclo) o `espontaneo` (ad-hoc).

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| `id` | `bigint unsigned` | NO | PK |
| `servicio_id` | `bigint unsigned` | NO | FK → `servicios.id` CASCADE (idx) |
| `tipo` | `enum(programado, espontaneo)` | NO | |
| `fecha_aplicacion` | `date` | NO | desde cuándo rige (idx) |
| `cuota_desde_id` | `bigint unsigned` | SÍ | FK → `servicio_cuotas.id` SET NULL |
| `importe_anterior` | `decimal(15,2)` | NO | en moneda del servicio |
| `importe_nuevo` | `decimal(15,2)` | NO | |
| `porcentaje_variacion` | `decimal(8,4)` | SÍ | `(nuevo - anterior) / anterior * 100` |
| `aplicado` | `boolean` | NO `false` | idx |
| `aplicado_at` | `datetime` | SÍ | |
| `aplicado_por` | `bigint unsigned` | SÍ | FK → `users.id` SET NULL |
| `observaciones` | `longtext` | SÍ | |
| `created_by` | `bigint unsigned` | NO | FK → `users.id` RESTRICT |
| `created_at` / `updated_at` | `datetime` | NO | |

---

## Diagrama de relaciones (resumen)

```
users ─┬─< facturas_venta.created_by / updated_by / check_cobranza_user_id
       ├─< servicios.created_by / updated_by
       ├─< servicio_ajustes.created_by / aplicado_por
       ├─< factura_archivos.uploaded_by
       ├─< auditoria.user_id
       ├─< config_app.updated_by
       └─< refresh_tokens.user_id

clientes ──< facturas_venta.cliente_id
         └──< servicios.cliente_id

servicios ──< servicio_cuotas.servicio_id
          └──< servicio_ajustes.servicio_id

servicio_cuotas ──< facturas_venta.servicio_cuota_id   (1-1 lógico)
                └──< servicio_ajustes.cuota_desde_id

facturas_venta ──< factura_archivos.factura_id
```

---

## Pendientes de schema (no migrados aún)

Para los módulos que faltan del MVP probablemente necesitemos:

- `notificaciones_enviadas` — para no duplicar avisos por SMTP (idempotencia del cron). Campos sugeridos: `id`, `tipo` (enum `vencimiento_proximo` / `vencida` / `ajuste_proximo`), `entidad`, `entidad_id`, `enviada_at`, `destinatarios`, `ok`, `error_msg`.
- `imports_historicos` — para trazar imports masivos de Excel. Campos sugeridos: `id`, `archivo_nombre`, `filas_total`, `filas_ok`, `filas_error`, `errores_json`, `user_id`, `created_at`.
- `password_resets` (si se implementa reset por email) — `id`, `user_id`, `token_hash`, `expires_at`, `used_at`.

Confirmar antes de crear migración.
