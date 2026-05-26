# Estructura de carpetas — ITHub

Foto **real** del repo al 2026-05-26 + cambios proyectados para los módulos faltantes.

---

## Árbol actual

```
ITHub/
├── CLAUDE.md                       Memoria del proyecto (working style + decisiones)
├── README.md                       Doc general
├── .editorconfig
├── .env.example                    Variables del docker-compose
├── .gitignore
├── docker-compose.yml              db (mysql8) + adminer + api (php8.2+apache) + web (node20+pnpm) + mailhog
│
├── docker/
│   └── php/
│       └── Dockerfile              Imagen PHP 8.2 + Apache con extensiones
│
├── docs/
│   ├── seguridad.md                Arquitectura de seguridad, OWASP, hardening
│   ├── postman-collection.json     Collection con endpoints existentes
│   ├── schema.md                   (nuevo) Schema completo
│   ├── endpoints.md                (nuevo) Lista de endpoints REST
│   └── estructura.md               (nuevo) Este archivo
│
├── api/                            ── BACKEND PHP/Slim
│   ├── .env.example
│   ├── .htaccess                   Bloquea acceso al raíz, sirve sólo public/
│   ├── composer.json
│   ├── composer.lock
│   │
│   ├── config/
│   │   ├── container.php           DI bindings PHP-DI
│   │   └── settings.php            Settings tipados desde .env
│   │
│   ├── db/
│   │   ├── migrations/             11 migraciones Phinx
│   │   │   ├── 20260101000001_create_users_table.php
│   │   │   ├── 20260101000002_create_clientes_table.php
│   │   │   ├── 20260101000003_create_facturas_venta_table.php
│   │   │   ├── 20260101000004_create_factura_archivos_table.php
│   │   │   ├── 20260101000005_create_auditoria_table.php
│   │   │   ├── 20260101000006_create_config_app_table.php
│   │   │   ├── 20260101000007_create_refresh_tokens_table.php
│   │   │   ├── 20260201000001_create_servicios_table.php
│   │   │   ├── 20260201000002_create_servicio_cuotas_table.php
│   │   │   ├── 20260201000003_create_servicio_ajustes_table.php
│   │   │   └── 20260201000004_add_servicio_cuota_id_to_facturas_venta.php
│   │   └── seeds/
│   │       ├── AdminUserSeeder.php
│   │       └── ConfigAppSeeder.php
│   │
│   ├── public/
│   │   └── index.php               Front controller (único entrypoint web)
│   │
│   ├── scripts/
│   │   └── test_cronograma.php     Sanity check del CronogramaGenerator
│   │
│   ├── src/
│   │   ├── Bootstrap/
│   │   │   ├── App.php             Wiring Slim + container + base path /api/v1
│   │   │   ├── Middleware.php      Stack global (CORS, security headers, etc.)
│   │   │   └── Routes.php          Registro de todas las rutas
│   │   │
│   │   ├── Controllers/            Capa HTTP — recibe req, valida shape, delega a Service
│   │   │   ├── AuthController.php
│   │   │   ├── ClientesController.php
│   │   │   ├── DashboardController.php
│   │   │   ├── FacturasController.php
│   │   │   ├── HealthController.php
│   │   │   └── ServiciosController.php
│   │   │
│   │   ├── Exceptions/             Custom exceptions (404, 422, 403, 409)
│   │   │
│   │   ├── Helpers/
│   │   │   └── CuitValidator.php   Algoritmo de validación de dígito verificador
│   │   │
│   │   ├── Middleware/
│   │   │   ├── CorsMiddleware.php
│   │   │   ├── ErrorHandlerMiddleware.php
│   │   │   ├── JsonBodyMiddleware.php
│   │   │   ├── JwtAuthMiddleware.php
│   │   │   ├── RateLimitMiddleware.php
│   │   │   ├── RequestIdMiddleware.php
│   │   │   ├── RoleMiddleware.php
│   │   │   └── SecurityHeadersMiddleware.php
│   │   │
│   │   ├── Models/                 Eloquent — sólo definición de tabla, fillable y relaciones
│   │   │   ├── Auditoria.php
│   │   │   ├── Cliente.php
│   │   │   ├── ConfigApp.php
│   │   │   ├── FacturaArchivo.php
│   │   │   ├── FacturaVenta.php
│   │   │   ├── RefreshToken.php
│   │   │   ├── Servicio.php
│   │   │   ├── ServicioAjuste.php
│   │   │   ├── ServicioCuota.php
│   │   │   └── User.php
│   │   │
│   │   ├── Repositories/           Queries complejas (filtros, búsqueda, paginación)
│   │   │   ├── ClienteRepository.php
│   │   │   ├── FacturaRepository.php
│   │   │   └── ServicioRepository.php
│   │   │
│   │   ├── Services/               Lógica de negocio
│   │   │   ├── AuditoriaService.php
│   │   │   ├── AuthService.php
│   │   │   ├── ClienteService.php
│   │   │   ├── CronogramaGenerator.php   Helper puro: genera cuotas según tipo de servicio
│   │   │   ├── DashboardService.php
│   │   │   ├── FacturaService.php
│   │   │   ├── JwtService.php
│   │   │   ├── ServicioAjusteService.php
│   │   │   ├── ServicioCuotaService.php
│   │   │   └── ServicioService.php
│   │   │
│   │   ├── Support/
│   │   │   ├── ContainerProvider.php     Helper para tests
│   │   │   ├── PiiFilter.php             Scrub de PII en logs
│   │   │   └── ResponseFactory.php       JSON OK/Error helpers
│   │   │
│   │   └── Validators/                   Schemas Respect/Validation
│   │       ├── ClienteValidator.php
│   │       ├── FacturaValidator.php
│   │       ├── ServicioAjusteValidator.php
│   │       └── ServicioValidator.php
│   │
│   └── storage/
│       ├── exports/                Output temp de exportes (gitignored)
│       └── imports/                Uploads temp de import histórico (gitignored)
│
└── web/                            ── FRONTEND Next.js 14 (App Router, Static Export)
    ├── .env.example
    ├── .eslintrc.json
    ├── .npmrc                      pnpm config (strict-peer-deps, etc.)
    ├── README.md
    ├── next.config.js              output: 'export'
    ├── next-env.d.ts
    ├── package.json                onlyBuiltDependencies whitelist
    ├── pnpm-lock.yaml
    ├── postcss.config.js
    ├── tailwind.config.ts          Tokens: primario #663399, acento #9CC930, Saira
    ├── tsconfig.json               strict
    │
    └── src/
        ├── app/                    App Router
        │   ├── layout.tsx          Root layout (Saira + AuthProvider)
        │   ├── globals.css
        │   ├── page.tsx            Landing/redirect
        │   ├── login/
        │   ├── cambiar-password/
        │   ├── dashboard/
        │   ├── clientes/
        │   └── facturas/
        │
        ├── components/
        │   ├── ui/                 Primitivos shadcn/ui (button, dialog, table, etc.)
        │   ├── layout/             Sidebar, Topbar, AuthGuard
        │   ├── dashboard/          KPI cards, gráficos Recharts
        │   └── facturas/           Forms, tabla, filtros
        │
        ├── hooks/                  Hooks compartidos (useAuth, useDebounce, etc.)
        │
        ├── lib/
        │   ├── api.ts              Axios instance + interceptor refresh + manejo de errores
        │   ├── format.ts           Currency, fecha, CUIT
        │   └── utils.ts            cn() + helpers
        │
        ├── stores/
        │   └── auth.ts             Zustand store del access token (memoria, no localStorage)
        │
        └── types/                  Tipos compartidos (Cliente, Factura, Servicio, etc.)
```

---

## Carpetas/archivos proyectados (a crear)

### Backend

```
api/
├── db/
│   └── migrations/
│       ├── 20260301000001_create_notificaciones_enviadas_table.php       (si SMTP)
│       ├── 20260301000002_create_imports_historicos_table.php            (si import)
│       └── 20260301000003_create_password_resets_table.php               (si reset)
│
├── scripts/
│   ├── cron_notificaciones.php          Job de vencimientos + ajustes próximos
│   └── cron_rolling_window.php          Extiende cuotas en mantenimientos indefinidos
│
└── src/
    ├── Controllers/
    │   ├── ArchivosController.php       Adjuntos de facturas (Drive)
    │   ├── ExportController.php
    │   ├── ImportController.php
    │   ├── UsuariosController.php       (admin)
    │   ├── ConfigController.php         (admin)
    │   └── AuditoriaController.php      (admin)
    │
    ├── Services/
    │   ├── GoogleDriveService.php       Wrapper sobre google/apiclient
    │   ├── MailerService.php            Wrapper sobre PHPMailer + templates
    │   ├── NotificacionService.php      Decide qué notif mandar y a quién
    │   ├── ExportService.php            Excel (PhpSpreadsheet), PDF (Dompdf), CSV
    │   └── ImportService.php            Parser Excel + validación + commit
    │
    └── Validators/
        ├── UsuarioValidator.php
        └── ConfigValidator.php
```

### Frontend

```
web/src/
├── app/
│   ├── servicios/                       Pantallas pendientes del MVP
│   │   ├── page.tsx                     Listado
│   │   ├── nuevo/page.tsx               Wizard alta (paso 1: tipo · paso 2: datos · paso 3: cronograma)
│   │   └── [id]/
│   │       ├── page.tsx                 Detalle con tabs (Resumen, Cronograma, Ajustes, Historial)
│   │       └── editar/page.tsx
│   ├── config/                          Panel admin de config_app
│   ├── usuarios/                        ABM de usuarios (admin)
│   └── auditoria/                       Visor de bitácora (admin)
│
└── components/
    ├── servicios/
    │   ├── ServicioForm.tsx
    │   ├── CronogramaTable.tsx
    │   ├── AjusteModal.tsx
    │   ├── PausarModal.tsx
    │   ├── ReanudarModal.tsx
    │   └── FacturarCuotaModal.tsx
    └── adjuntos/
        └── DriveUploader.tsx
```

---

## Convenciones a mantener

- **PSR-12** en PHP, ESLint+Prettier en TS.
- **PHP namespace:** `ITHub\Api\{Controllers,Services,Models,...}` (composer PSR-4 ya configurado).
- **Imports en TS:** alias `@/*` apuntando a `web/src/*`.
- **No emojis** en código ni en mensajes de commit (sí en chat).
- **i18n:** todo user-facing en español rioplatense.
- **Estados visuales:** colores semánticos siguiendo paleta — verde `#9CC930` SOLO para indicadores positivos, nunca para grandes áreas.
