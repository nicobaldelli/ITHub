# ITHub Web — Frontend

Next.js 14 (App Router) + TypeScript + Tailwind + Saira + Recharts.

## Stack
- **Framework:** Next.js 14 — modo Static Export (`output: 'export'`) para deploy en Hostinger compartido
- **UI:** Tailwind CSS + componentes shadcn-style locales
- **Fuente:** Saira (Google Fonts) cargada vía `next/font/google`
- **HTTP:** Axios con interceptors (refresh automático en 401)
- **Estado:** Zustand (auth solo en memoria — no se persiste)
- **Gráficos:** Recharts
- **Notificaciones:** Sonner

## Identidad visual

| Token | Color |
|---|---|
| Fondo | `#FFFFFF` |
| Texto / neutros | `#161922` |
| Primario | `#663399` |
| Acento (positivos) | `#9CC930` |

## Estructura

```
src/
├── app/
│   ├── layout.tsx            # root layout con fuente Saira y Toaster
│   ├── page.tsx              # redirector inicial (hydrate + ruta)
│   ├── login/page.tsx
│   ├── cambiar-password/page.tsx
│   ├── dashboard/page.tsx
│   ├── facturas/page.tsx
│   └── clientes/page.tsx
├── components/
│   ├── ui/                   # botones, inputs, cards, badges
│   ├── layout/               # Sidebar, Topbar, AppShell (guard)
│   ├── dashboard/            # KpiCard, charts
│   └── facturas/             # EstadoBadge
├── hooks/                    # useAuth, useDashboard, useClientes, useFacturas
├── lib/                      # api (axios), format (AR locale), utils
├── stores/                   # auth.ts (Zustand, memoria)
└── types/                    # api, factura, cliente, dashboard
```

## Setup local

Ya viene en el `docker-compose.yml` del repo raíz como servicio `web` con profile `frontend`.

```bash
# Desde la raíz del monorepo:
docker compose --profile frontend up -d web
docker compose logs -f web
```

O sin docker, con Node 20+:
```bash
cp .env.example .env.local
corepack enable           # habilita pnpm (viene con Node 20)
pnpm install
pnpm dev
```

App en <http://localhost:3000>.

## Variables de entorno

- `NEXT_PUBLIC_API_URL` — URL base de la API (incluye `/api/v1`)

## Build de producción (Static Export)

```bash
pnpm build
```

Genera `out/` listo para subir a Hostinger por FTP/Git. Ver `docs/deploy-hostinger.md`.

## Seguridad — almacenamiento de tokens

- **Access JWT:** vive solo en memoria (Zustand sin persistir). Inmune a XSS persistente.
- **Refresh token:** cookie `HttpOnly + Secure + SameSite=Strict` scoped a `/api/v1/auth`, manejada por el server.
- Al recargar la página, `AppShell` llama silenciosamente a `/auth/refresh` para reobtener el access.
- Si el refresh falla → redirige a `/login` automáticamente.
