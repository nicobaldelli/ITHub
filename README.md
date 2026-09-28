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
| [`docs/runbook-deploy-manual.md`](docs/runbook-deploy-manual.md) | **Deploy a Hostinger** (comandos paso a paso, manual) |
| [`docs/google-drive-setup.md`](docs/google-drive-setup.md) | Configuración del Service Account de Google Drive |
| [`docs/endpoints.md`](docs/endpoints.md) | Referencia de endpoints del API |
| [`docs/schema.md`](docs/schema.md) | Modelo de datos |
| [`docs/estructura.md`](docs/estructura.md) | Estructura del monorepo |
| [`web/README.md`](web/README.md) | Setup y arquitectura del frontend |
| [`docs/deploy-hostinger.md`](docs/deploy-hostinger.md) | Guía vieja de deploy (obsoleta, solo referencia) |

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
docker compose exec web pnpm install

# Correr migraciones y seed inicial
docker compose exec api vendor/bin/phinx migrate -c db/phinx.php
docker compose exec api vendor/bin/phinx seed:run -c db/phinx.php

# Levantar frontend en modo dev
docker compose exec web pnpm dev
```

## 🧪 Tests

Backend (PHPUnit 10, sin MySQL: la suite de integración usa SQLite en memoria
con el container real):

```bash
cd api
composer install            # incluye phpunit
composer test               # toda la suite
vendor/bin/phpunit --testsuite Unit          # solo validadores, helpers, servicios puros
vendor/bin/phpunit --testsuite Integration   # auth, facturación automática, etc.
```

Las variables de entorno de test están en `api/phpunit.xml`; no hace falta
`.env`. Si agregás una migración con una tabla nueva, reflejala en
`api/tests/Support/TestSchema.php` (hay un test que lo recuerda).

Frontend:

```bash
cd web
pnpm lint && pnpm typecheck
```

Ambos corren en GitHub Actions en cada push (`.github/workflows/ci.yml`).

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
