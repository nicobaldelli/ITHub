# Configuración de Google Drive — ITHub

ITHub sube a Drive los adjuntos de las facturas y el PDF obligatorio de
"marcar enviada". Lo hace con una **Service Account** de Google Cloud, para que
el upload sea desatendido (el cron también factura) y no dependa de que un
humano esté logueado en Google.

> **Importante — por qué hace falta una Unidad compartida.** Google ya no
> permite que una service account sea dueña de archivos en "Mi unidad" de una
> persona. Si compartís una carpeta común de tu Drive con la service account,
> el primer upload falla con
> `403 Service Accounts do not have storage quota. Leverage shared drives or use OAuth delegation`.
> Por eso la carpeta raíz de ITHub **tiene que vivir en una Unidad compartida**
> (Shared Drive) de Google Workspace, con la service account como miembro.
> Los archivos quedan como propiedad de la unidad, no de nadie en particular:
> si cambia la gente, no hay nada que migrar.
>
> La alternativa (delegación a nivel de dominio, sección 7) sirve si no podés
> crear unidades compartidas, pero ata los archivos al Drive de un usuario.

Lo que hace ITHub con la raíz es crear sola la estructura
`<raíz>/<cliente>/<año>/<mes>/` y subir ahí.

---

## 1. Crear proyecto en Google Cloud

1. Ir a [console.cloud.google.com](https://console.cloud.google.com/) **con tu usuario de Workspace** (`@intellihelp.tech`), no con un Gmail personal: así el proyecto queda en la organización.
2. Arriba a la izquierda → "Seleccionar proyecto" → "**Proyecto nuevo**"
3. Nombre sugerido: `ithub-facturacion`
4. Esperar 30 s a que se cree

---

## 2. Habilitar la Google Drive API

1. Menú lateral → **APIs y servicios → Biblioteca**
2. Buscar "Google Drive API"
3. Click → **Habilitar**

---

## 3. Crear la Service Account

1. Menú lateral → **IAM y administración → Cuentas de servicio**
2. **+ Crear cuenta de servicio**
3. Detalles:
   - **Nombre:** `ithub-drive`
   - **ID:** se autocompleta como `ithub-drive`
   - **Descripción:** "Service account para upload de adjuntos a Drive"
4. Click **Crear y continuar**
5. **Rol:** dejar vacío (no necesita roles a nivel proyecto, todo va por membresía en la unidad compartida)
6. **Listo**
7. Anotá el email de la cuenta: `ithub-drive@ithub-facturacion.iam.gserviceaccount.com` (también está en el JSON, campo `client_email`)

### 3.1 Generar la clave JSON

1. En el listado de cuentas de servicio, click sobre `ithub-drive@...`
2. Pestaña **Claves → Agregar clave → Crear clave nueva → JSON**
3. Se descarga un archivo `ithub-facturacion-XXXXX.json`. **Guardalo seguro**, es el único momento que lo podés descargar.
4. Renombralo a `service-account.json`

> Si la organización tiene bloqueada la creación de claves de service account
> (política `iam.disableServiceAccountKeyCreation`, común en Workspace nuevos),
> un administrador tiene que desactivarla para este proyecto desde
> **IAM → Políticas de la organización**.

---

## 4. Crear la Unidad compartida y la carpeta raíz

1. Ir a [drive.google.com](https://drive.google.com) → menú lateral **Unidades compartidas** → **+ Nueva**
   - Nombre: `ITHub Facturación`
   - Si no ves "Unidades compartidas", tu edición de Workspace no las incluye o el admin las tiene desactivadas (Consola de administración → Apps → Google Workspace → Drive y Documentos → Configuración de uso compartido → Creación de unidades compartidas).
2. Dentro de la unidad, **Administrar miembros** → agregar el email de la service account (paso 3.7) con el rol **Administrador de contenido** (puede crear carpetas y subir/borrar archivos; no puede cambiar miembros ni borrar la unidad). **No** marques "notificar".
3. Agregá también a las personas que tienen que ver los archivos (ventas, cobranzas), con **Lector** o **Colaborador** según corresponda. Los links "Ver en Drive" de ITHub solo abren para miembros de la unidad.
4. Elegí la raíz de ITHub. Puede ser la unidad misma o una carpeta adentro (recomendado, por si la unidad se usa para otras cosas): creá la carpeta `Facturas`.
5. Obtener el **ID**: abrí la carpeta (o la unidad) y copiá el último tramo de la URL:
   - carpeta: `https://drive.google.com/drive/folders/<ESTE_ES_EL_ID>`
   - unidad directamente: `https://drive.google.com/drive/u/0/folders/<ESTE_ES_EL_ID>` (el ID de una unidad compartida empieza con `0A`)

---

## 5. Configurar ITHub

### 5.1 Subir el JSON al servidor

```bash
# Desde tu PC:
scp -P <SSH_PORT> service-account.json <SSH_USER>@<SSH_HOST>:~/domains/apithub.intellihelp.tech/app/api/storage/credentials/

# En el servidor:
chmod 0600 ~/domains/apithub.intellihelp.tech/app/api/storage/credentials/service-account.json
```

> El path por defecto es `storage/credentials/service-account.json`.
> Si lo querés en otro lado, ajustá `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` en `.env`.

### 5.2 Setear el folder ID en config

Desde la UI:
1. Loguearte como admin
2. Ir a `/configuracion`
3. En el grupo **Drive** → `drive_root_folder_id` = el ID copiado en el paso 4.5
4. Guardar

> `GOOGLE_DRIVE_ROOT_FOLDER_ID` del `.env` no se usa: el ID vive en
> `config_app` para poder cambiarlo desde la UI sin tocar el servidor.

---

## 6. Probar

1. Loguearte como admin o ventas
2. Abrir el detalle de cualquier factura
3. En la card "Adjuntos" → "Subir archivo" (un PDF chico)
4. El archivo debería:
   - Aparecer en la lista con su tamaño y fecha, con los botones "Ver en Drive" y "Descargar"
   - Existir en la unidad compartida en `Facturas/<cliente>/<año>/<mm-Mes>/<archivo>`
5. Borrarlo desde ITHub y verificar que desaparece de Drive (va a la papelera de la unidad)

Si el upload falla, el mensaje de ITHub trae el error de Google tal cual; ver
Troubleshooting.

---

## 7. Alternativa: delegación a nivel de dominio (sin unidad compartida)

Solo si no podés usar unidades compartidas. La service account actúa **como
un usuario real** del Workspace y los archivos quedan en el Drive de ese
usuario (y son suyos: si la persona se va, hay que transferirlos).

1. Consola de administración de Workspace → **Seguridad → Control de acceso y datos → Controles de API → Delegación de todo el dominio → Agregar nuevo**
2. **ID de cliente**: el `client_id` numérico del JSON de la service account
3. **Permisos de OAuth**: `https://www.googleapis.com/auth/drive.file`
4. En el `.env` del servidor: `GOOGLE_IMPERSONATE_USER=usuario@intellihelp.tech`
5. La carpeta raíz es una carpeta común en "Mi unidad" de **ese** usuario (no hace falta compartirla con la service account) y su ID va en `drive_root_folder_id`

Con delegación activa, el soporte de unidades compartidas sigue funcionando:
podés usar cualquiera de las dos raíces.

---

## 8. Estructura de carpetas creada en Drive

```
Facturas  (raíz, dentro de la unidad compartida)
├── Cliente Razón Social SA
│   ├── 2026
│   │   ├── 06-Junio
│   │   │   ├── 0001-00000123.pdf      <- PDF de "marcar enviada"
│   │   │   └── recibo.pdf             <- adjunto manual
│   │   └── 07-Julio
│   │       └── ...
│   └── 2027
│       └── ...
└── Otro Cliente SRL
    └── 2026
        └── ...
```

Las carpetas se crean automáticamente la primera vez que se sube un archivo
con esa combinación cliente/año/mes. No las crees a mano (excepto la raíz).
Lo que muevas, renombres o borres a mano en Drive **no se refleja en ITHub**:
la integración es de subida, no de sincronización.

---

## 9. Troubleshooting

### "Google Drive no configurado" al subir
- Verificar que `service-account.json` existe y es legible por el user de PHP
- Verificar `drive_root_folder_id` en `/configuracion`
- Permisos del JSON: `ls -l storage/credentials/service-account.json` debería mostrar `-rw-------` (0600)

### "Service Accounts do not have storage quota"
- La raíz está en "Mi unidad" de alguien, no en una unidad compartida. Ver el recuadro del inicio y la sección 4 (o la 7 si vas por delegación).

### "File not found" al crear carpetas o subir
- La service account no es miembro de la unidad compartida (sección 4.2), o el ID de `drive_root_folder_id` está mal.
- Si el ID es correcto y la membresía también, verificá que el código mande `supportsAllDrives` (está en `GoogleDriveService`, no lo saques).

### "The caller does not have permission" / 403 al subir
- El rol de la service account en la unidad es Lector o Comentarista. Tiene que ser **Administrador de contenido** (o Colaborador como mínimo).

### "Insufficient OAuth Scopes"
- El scope es `drive.file`: la service account solo ve y toca archivos creados por ella (más seguro). Alcanza para todo lo que hace ITHub. No lo cambies a `drive` salvo que sepas por qué.

### Los links "Ver en Drive" dan "Solicitar acceso"
- El usuario que hace click no es miembro de la unidad compartida (sección 4.3). Los archivos no se comparten uno por uno: la membresía en la unidad es lo que da acceso.

### Los archivos no aparecen donde los busco
- Están en **Unidades compartidas → ITHub Facturación**, no en "Mi unidad" ni en "Compartido conmigo".

---

## 10. Seguridad

- El JSON contiene una **private key**. Tratarlo como un secreto:
  - Permisos `0600` en el servidor
  - Fuera del web root (`storage/` está bloqueado por `.htaccess`)
  - No commitear a git (`.gitignore` ya lo bloquea con `service-account*.json` y `credentials/`)
  - Rotar cada 6–12 meses (generar nueva clave en GCP, subir, eliminar la vieja)

- El scope `drive.file` limita el daño en caso de fuga: el atacante solo podría
  manipular archivos creados por esta service account, y solo dentro de las
  unidades donde sea miembro (no toda tu organización).

- La service account NO tiene UI propia; el acceso humano es por la membresía
  de cada persona en la unidad compartida, que administrás desde Drive.
