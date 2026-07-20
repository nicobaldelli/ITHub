# ITHub — Gestión de Facturas de Venta

Aplicación web para administración de facturas de venta de una empresa argentina.
Monorepo con dos servidores independientes:

- **`api/`** — Backend PHP 8.2 + Slim Framework 4 + MySQL 8
- **`web/`** — Frontend Next.js 14 (App Router, Static Export) + TypeScript + Tailwind + shadcn/ui

Deploy productivo en Hostinger:

| Servicio | Subdominio |
|---|---|
| Frontend | `https://ithub.intellihelp.tech` |
| API REST | `https://apithub.intellihelp.tech` |

---

## 📚 Documentación

| Documento | Contenido |
|---|---|
| [`docs/seguridad.md`](docs/seguridad.md) | Arquitectura de seguridad, OWASP, hardening |
| [`docs/deploy-hostinger.md`](docs/deploy-hostinger.md) | Paso a paso de deploy en Hostinger |
| [`docs/google-drive-setup.md`](docs/google-drive-setup.md) | Configuración del Service Account de Google Drive |
| [`docs/manual-usuario.md`](docs/manual-usuario.md) | Manual de usuario final |
| [`api/README.md`](api/README.md) | Setup, comandos y arquitectura del backend |
| [`web/README.md`](web/README.md) | Setup y arquitectura del frontend |

---

## 🚀 Quick start (desarrollo local con Docker)

Requisitos: Docker Desktop, Git.

```bash
git clone https://github.com/nicobaldelli/ITHub.git
cd ITHub
cp api/.env.example api/.env
cp web/.env.example web/.env.local
docker compose up -d
```

Luego:

```bash
# Instalar dependencias
docker compose exec api composer install
docker compose exec web npm install

# Correr migraciones y seed inicial
docker compose exec api vendor/bin/phinx migrate
docker compose exec api vendor/bin/phinx seed:run

# Levantar frontend en modo dev
docker compose exec web npm run dev
```

Accesos por defecto:

- API: <http://localhost:8080>
- Web: <http://localhost:3000>
- MySQL: `localhost:3306` (user: `ithub`, pass: ver `.env`)
- Adminer (gestor DB): <http://localhost:8081>

Credenciales iniciales (cambiar en primer login):
- email: `admin@intellihelp.tech`
- password: ver salida del seed (random + bcrypt; se muestra una sola vez)

---

## 🎨 Identidad visual

- Fuente: **Saira** (Google Fonts)
- Colores:
  - Fondo `#FFFFFF`
  - Primario `#663399`
  - Texto/Neutros `#161922`
  - Acento (solo positivos) `#9CC930`

---

## 🛠️ Stack resumido

**Backend:** Slim 4, Eloquent ORM, Phinx, firebase/php-jwt, PHPMailer, PhpSpreadsheet, Dompdf, Monolog, google/apiclient.

**Frontend:** Next.js 14, TypeScript strict, Tailwind, shadcn/ui, TanStack Table, React Hook Form + Zod, Recharts, Zustand, Axios, date-fns.

---

## 📝 Licencia

Propietario — Uso interno IntelliHelp.
