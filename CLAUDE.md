# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Pendientes 03/06/2026

### Crítico — Deploy a Hostinger por FileZilla
Subir a /home/u516405553/domains/nubira.cl/public_html/app/:
- app/admin_chats.php (sistema DLP completo)
- app/enviar_mensaje.php (registro de intentos)
- app/contar_alertas_sistema.php (badge admin chats)
- app/admin_usuarios.php (badge usuarios nuevos)

Verificación post-deploy:
- Abrir nubira.cl/admin/chats logueado como admin → crea tabla dlp_intentos automáticamente
- Abrir nubira.cl/admin/usuarios → crea columna visto_admin + marca todos como vistos
- Confirmar pills nuevos visibles, badge usuarios baja a 0
- Probar end-to-end DLP en producción (enviar contacto en un chat de prueba)

### Pendiente desde 01/06: deploy 8-10 archivos de OFERTAS
- app/vitrina.php
- app/cargar_servicios.php
- app/busqueda.php
- app/cargar_vistos.php
- app/componentes/render_card.php
- app/componentes/panel_gestion.php
- app/detalle_servicio.php
- app/ver_apunte.php
- app/admin_ofertas.php
- app/contratar_servicio.php

### Producto (sin urgencia)
- Conversación con Nubira Producto (Cowork) sobre cómo atraer primeros compradores reales
- Identificar segmento concreto (USACH / PUC / AIEP / PAES)
- Plan concierge para cerrar primera transacción manual

### Técnico viejo
- Git filter-repo 2da pasada (keys viejas en commits antiguos)
- Resetear el otro PC cuando vuelva a él
- Copia segura del config.php de producción
- Cambiar contraseñas SMTP y key Gemini que pasaron por chat
- Apelar Google
- Actualizar XAMPP a PHP 8.2 (para poder testear flujo de pago en local)

## Project Overview

**Nubira** (nubira.cl) is a Chilean educational marketplace where university students buy and sell tutoring services (servicios) and study notes (apuntes). Currency is CLP. Solo founder operates all roles. Future: native iOS/Android app via Flutter (~March 2027), PWA as intermediate step. All code must be API-first ready.

## Development Setup

### Local (XAMPP)
- **URL:** http://nubira.local/ (VirtualHost configured)
- **Apache:** port 80, MySQL: port 3306
- **DocumentRoot:** `C:/nubira` (symlinked from `C:\xampp\htdocs\nubira`)
- **phpMyAdmin:** http://localhost/phpmyadmin/
- **Database:** `u516405553_u516405553_Nub`

### Production (Hostinger)
- Deploy via FTP to Hostinger/hPanel
- DB access via phpMyAdmin (no SSH, no local Node.js)
- Database: `u516405553_Nub` on host

### Credentials
`app/conexion.php` has hardcoded credentials. The `.env` file is NOT auto-loaded (no dotenv library). Update `conexion.php` for local dev.

## Tech Stack (STRICT — no deviations)

- **Backend:** PHP 8.x, procedural mysqli, prepared statements MANDATORY everywhere
- **Frontend:** HTML5 semantic, Tailwind CSS (CDN), NO custom CSS except minimal `<style>` adjustments
- **JS:** Vanilla ES6+ only, NO jQuery. Animations via `classList`, `transform`, `opacity`
- **Icons:** Custom SVG system via `icon('name')` in `app/iconos.php`. FontAwesome only if already present in file. Migrating to Heroicons-style outline SVGs (stroke-width 1.5)
- **Libraries:** MercadoPago SDK, PHPMailer, pdf.js, viewerjs (only if already in file)

## Design System — Nubira 2.0 (Airbnb-inspired)

### Colors
- Primary accent: `#54A6D8` (ONLY accent color — no yellow, purple, green accents)
- Gradients: `from-sky-400 to-[#54A6D8]` and `from-orange-300 to-orange-500`
- Backgrounds: `bg-gray-50` or `bg-white`
- No emoji icons

### Components
- Borders: `rounded-xl`, `rounded-2xl`, `rounded-3xl`
- Shadows: `shadow-sm`, `shadow-md`, `shadow-lg`
- Hover: `transition-all hover:shadow-md hover:scale-[1.01]`
- Cards: white container, `border border-gray-100`, images with `object-cover` (NEVER deform)
- Skeletons: `animate-pulse` before content loads
- Empty states: `bg-gray-50`, `border-dashed`, soft icons

### Typography
- `font-bold`, `tracking-tight`, `leading-tight`
- Headings: `text-xl` to `text-4xl` (Airbnb style)

### Layout
- **Header:** `fixed top-0`, white + `backdrop-blur-md`. Desktop: logo, breadcrumb, publish buttons, user. Mobile: user only
- **Sidebar:** `fixed left-0 w-64` (desktop). Uses `nav_class('/route')` for active state
- **Bottom nav:** `fixed bottom-0` (mobile), 5 icons, center "publish" button with blue Nubira bubble
- **Main content:** `pt-20` mobile, `md:ml-64` desktop. Max widths: `max-w-[1600px]` lists, `max-w-[1100px]` detail/forms
- **Components:** `app/componentes/` — header, sidebar, nav_bottom, modal

## Architecture

### Routing
Apache RewriteRules in `.htaccess`. Clean URLs: `/explorar` → `app/vitrina.php`. `RewriteBase /`. No framework router.

### Page Pattern
```php
session_start();
require_once __DIR__ . '/conexion.php';
if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit; }
```
Admin pages check `$_SESSION['rol'] === 'admin'`.

### Database Schema
- DB: `u516405553_Nub` (~87 tables)
- `alumnos` key columns: `id`, `nombre`, `bio`, `carrera`, `correo`, `rol`, `tipo`, `institucion`, `calificacion_promedio`, `cantidad_votos`, `vistas_perfil`, `tiempo_respuesta_promedio`, `bloqueado`, `visible`, `confirmado`, `foto_perfil` (NOT `foto`)
- Soft delete: `visible = 1` on `alumnos`
- Active services: `estado = 'aprobado'` + `COALESCE(visible, 1) = 1`
- `alumno_id` in `servicios` (NOT `usuario_id`)
- Profile URL: `/perfil/[base64_encode($id . '-nubira_secreto')]` with padding stripped via `rtrim(..., '=')`
- Ratings: `valoraciones` table with `rol_evaluado = 'vendedor'` (NOT `contratos.calificacion_comprador`)
- Service tiers: 5 levels — leyenda / élite / pro / top / nuevo (inline SVG stars)

### Session Variables
| Key | Value |
|-----|-------|
| `$_SESSION['usuario_id']` | User PK |
| `$_SESSION['usuario_nombre']` | Display name |
| `$_SESSION['rol']` | `'alumno'` or `'admin'` |
| `$_SESSION['institucion']` | University name |
| `$_SESSION['dominio']` | Email domain |

### Key Shared Files
| File | Purpose |
|------|---------|
| `app/conexion.php` | mysqli connection + timezone Chile |
| `app/config.php` | Constants (BASE_URL, MP tokens, email) |
| `app/correo.php` | PHPMailer wrapper (SMTP Hostinger) |
| `app/iconos.php` | `icon($name, $classes)` → inline SVG |
| `app/seguridad_url.php` | `nubira_encriptar_id()` / `nubira_desencriptar_id()` |
| `app/logger.php` | Activity logging with bot detection (`es_bot`, `user_agent`) |
| `app/init_sesion.php` | Session initialization |
| `app/env_loader.php` | Env file reader |

### Modals
Standard animation: `translate-y-full → translate-y-0`, `opacity-0 → opacity-100`. Blurred backdrop (Airbnb style). Use `setupModal(trigger, modal, card, close)`.

### Chat System
Badge via `contar_mensajes_nuevos.php`. Sidebar and bottom nav must show it. Polling with `document.hidden` guard + `visibilitychange` refresh. Intervals: ~30-45s.

### Caching
- Nav alerts: session-based, 5-min TTL (`$_SESSION['nav_cache_invalidar']`)
- AI recommendations: file-based, 30-min TTL (`app/cache_ia/`)
- OPCache warning: can serve stale PHP after edits — use `opcache_reset()` or sonda technique

## Payments
MercadoPago SDK. Two tokens: `MP_ACCESS_TOKEN` (apuntes/servicios), `MP_ACCESS_TOKEN_OPORTUNIDADES`. Webhook: `app/notificaciones_mp.php` → responds 200 immediately, then processes.

## AI
Google Gemini 2.0 Flash via `app/datos/ia_nubira.php`. Cached in `app/cache_ia/` as JSON (MD5 key). Admin panel: `/admin/ia`.

## Email
`app/correo.php` wraps PHPMailer. SMTP: `no-reply@nubira.cl`, `contacto@nubira.cl`. Logs: `app/log_correos.txt`.

## Module Map
| Module | Key files |
|--------|-----------|
| Services vitrina | `app/vitrina.php`, `app/cargar_servicios.php`, `app/detalle_servicio.php` |
| Notes vitrina | `app/vitrina_apuntes.php`, `app/cargar_apuntes.php`, `app/ver_apunte.php` |
| Search | `app/busqueda.php` (multi-word Spanish tokenizer with plural stripping) |
| Publish | `app/publicar_servicio.php`, `app/formulario_subir_apunte.php` |
| Contracts/Chat | `app/chat_previo_contrato.php`, `app/chat_mini_aula.php`, `app/contratar_servicio.php` |
| User finances | `app/mis_ventas.php`, `app/mis_compras.php`, `app/datos_bancarios.php` |
| Support | `app/reclamos_sugerencias.php` (7-category slug system), `app/admin_reclamos.php` |
| Admin | `app/admin_panel.php`, `app/admin_*.php` |
| Auth | `login.php`, `register.php`, `app/logout.php` |
| Profile | `app/perfil.php`, `app/editar_datos.php`, `app/actualizar_foto.php` |

## Known Technical Debt (explicitly deferred)
- SQL injection via string interpolation in `motor_ia.php` and `render_card.php`
- Hardcoded Gemini API key in source
- `CURLOPT_SSL_VERIFYPEER = false` in production
- `$es_admin = true` hardcoded in `contar_alertas_sistema.php`
- Auto-migrations on every page load in `admin_usuarios.php`

## Key Patterns
- PRG (Post-Redirect-Get) enforced
- Spanish plural-stripping for search: remove trailing `s` or `es` when word length permits
- Bot isolation at INSERT time (not query-time filtering)
- Ticket state: admin response → `en_proceso` (not `resuelto`) so notifications fire
- Always use `require_once __DIR__ . '/file.php'` for includes
- Image pipeline: 3-size WebP (thumb 240px/q78, card 480px/q80, main 1200px/q82)