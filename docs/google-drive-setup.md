# Configuración de Google Drive — ITHub

ITHub usa una **Service Account** de Google Cloud para subir y gestionar
los adjuntos de facturas en Drive. La elección de Service Account (vs OAuth
de usuario) es para que el upload sea unattended y no dependa de que un
humano esté logueado.

---

## 1. Crear proyecto en Google Cloud

1. Ir a [console.cloud.google.com](https://console.cloud.google.com/)
2. Arriba a la izquierda → "Seleccionar proyecto" → "**Proyecto nuevo**"
3. Nombre sugerido: `ithub-facturacion`
4. Esperar 30s a que se cree

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
5. **Rol:** dejar vacío (no necesitamos roles a nivel proyecto, todo va por sharing de Drive)
6. **Listo**

### 3.1 Generar la clave JSON

1. En el listado de cuentas de servicio, click sobre `ithub-drive@...`
2. Pestaña **Claves → Agregar clave → Crear clave nueva → JSON**
3. Se descarga un archivo `ithub-drive-XXXXX.json`. **Guardalo seguro**, es el único momento que lo podés descargar.
4. Renombralo a `service-account.json`

---

## 4. Crear la carpeta raíz en Drive

1. Ir a [drive.google.com](https://drive.google.com)
2. Crear una carpeta `ITHub Facturación` (donde te resulte cómodo, puede estar dentro de un Shared Drive si trabajás en Workspace)
3. **Compartir** la carpeta:
   - Click derecho → **Compartir → Compartir**
   - Pegar el email de la Service Account (algo como `ithub-drive@ithub-facturacion.iam.gserviceaccount.com`)
   - Permisos: **Editor**
   - **NO** marques "notificar"
   - Enviar
4. Obtener el **ID de la carpeta**:
   - Abrir la carpeta
   - La URL es `https://drive.google.com/drive/folders/<ESTO_ES_EL_ID>`
   - Copiar ese ID

---

## 5. Configurar ITHub

### 5.1 Subir el JSON al servidor

```bash
scp service-account.json u123456789@<host>:~/domains/intellihelp.tech/public_html/ithub-api/api/storage/credentials/

# En el servidor:
chmod 0600 storage/credentials/service-account.json
```

> El path por defecto es `storage/credentials/service-account.json`.
> Si lo querés en otro lado, ajustá `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` en `.env`.

### 5.2 Setear el folder ID en config

Desde la UI:
1. Loguearte como admin
2. Ir a `/configuracion`
3. En el grupo **Google Drive** → setear `drive_root_folder_id` con el ID copiado en el paso 4
4. Guardar

O desde la DB directamente:

```sql
UPDATE config_app SET valor = '<EL_ID_DE_LA_CARPETA>'
WHERE clave = 'drive_root_folder_id';
```

### 5.3 (Opcional) Impersonar a un usuario del Workspace

Si tu organización usa Google Workspace y querés que los archivos aparezcan
como creados por un usuario específico (en vez de la service account), podés
habilitar **Domain-wide delegation**:

1. En la consola de Workspace Admin → **Seguridad → Controles de API → Delegación a nivel de dominio**
2. Agregar el client ID de la service account con el scope `https://www.googleapis.com/auth/drive.file`
3. En `.env` setear `GOOGLE_IMPERSONATE_USER=usuario@tudominio.com`

---

## 6. Probar

1. Reiniciar el servicio si hace falta (en Hostinger no es necesario, los cambios de config se leen runtime)
2. Loguearte como admin o ventas
3. Ir a `/facturas/[id]` de cualquier factura existente
4. En la card "Adjuntos" → "Subir archivo"
5. El archivo debería:
   - Aparecer en la lista con su tamaño y fecha
   - Existir en Drive en `<carpeta-raíz>/<año>/<mes>/<cliente>/<archivo>`

---

## 7. Estructura de carpetas creada en Drive

```
ITHub Facturación  (raíz)
├── 2026
│   ├── 06-Junio
│   │   ├── Cliente Razón Social SA
│   │   │   ├── factura-001.pdf
│   │   │   └── recibo.pdf
│   │   └── Otro Cliente SRL
│   │       └── factura-099.pdf
│   └── 07-Julio
│       └── ...
└── 2027
    └── ...
```

Las carpetas se crean automáticamente la primera vez que se sube un archivo
con esa combinación año/mes/cliente. No las crees a mano (excepto la raíz).

---

## 8. Troubleshooting

### "Google Drive no configurado" al subir
- Verificar que `service-account.json` existe y es legible por el user de PHP
- Verificar `drive_root_folder_id` en `/configuracion`
- Verificar permisos del JSON: `ls -l storage/credentials/service-account.json` debería mostrar `0600`

### "File not found: The user does not have sufficient permissions"
- Verificar que **compartiste la carpeta raíz** con el email de la service account (paso 4.3)
- El email aparece en el JSON, campo `client_email`

### "Insufficient OAuth Scopes"
- El scope hardcodeado es `drive.file` — eso significa que la service account solo puede manipular archivos creados por ella misma (más seguro)
- Si necesitás más permisos (poco probable), modificá `GoogleDriveService::getDriveService()` y cambiá el scope a `DRIVE` (acceso total)

### Los archivos no aparecen en Drive
- Si usaste un Shared Drive, asegurate de que la service account esté agregada como miembro del Shared Drive
- Si no, deberías ver los archivos en `Compartido conmigo` desde tu cuenta personal de Google

---

## 9. Seguridad

- El JSON contiene una **private key**. Tratarlo como un secreto:
  - Permisos `0600` en el servidor
  - Fuera del web root (`storage/` está bloqueado por `.htaccess`)
  - No commitear a git (`.gitignore` ya lo bloquea con `*.json` en credentials/)
  - Rotar cada 6–12 meses (generar nueva clave en GCP, subir, eliminar la vieja)

- El scope `drive.file` limita el daño en caso de fuga: el atacante solo podría
  manipular archivos creados por esta service account (no toda tu cuenta de Drive).

- La service account NO tiene UI propia; cualquier acceso humano se hace
  desde tu cuenta personal viendo "Compartido conmigo".
