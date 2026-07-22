
## Pendiente: badge de nombre del tutor puede desbordar el lienzo en cards de marketing

Pendiente: el badge de nombre del tutor en las cards de marketing (nb_generar_imagen_post()) puede desbordar el lienzo con nombres muy largos — colMaxW=590px hoy, sin manejo explícito de overflow/truncado. Riesgo detectado en varias rondas de rediseño del 21/07/2026, sin resolver por estar fuera de alcance de cada sesión. Evaluar truncado o reducción de tamaño de fuente dinámico para nombres largos.

## Pendiente: aplicar el rediseño de card de marketing a nb_generar_imagen_history()

Aplicar el mismo rediseño de card (foto grande, badge disponible, categoría+rating separados, título genérico, bio condicional, features fijas) a `nb_generar_imagen_history()` — hoy solo se rediseñó POST 1:1, HISTORY 9:16 sigue con el diseño viejo, generando inconsistencia visual entre ambos formatos hasta que se aplique el mismo trabajo ahí.

## Pendiente: definir relación entre notificar_alternativas_chat.php (cron) y enviar_cupon_alternativas.php (panel manual)

Definir la relación entre `notificar_alternativas_chat.php` (cron automático, 20 min sin respuesta, sin cupón) y `enviar_cupon_alternativas.php` (panel manual, con cupón). Duda pendiente: ¿el panel manual debería ampliar su criterio de "nunca respondió" a "no respondió el mismo día calendario" para casos como Maura (conversación 156, escribió 18/07 20:48, vendedor respondió 20/07 13:56 — casi 2 días después, pero técnicamente "sí respondió" así que hoy no calificaría)? Riesgo identificado: si se amplía el criterio, se generaría superposición con el cron automático (mismo estudiante recibiría 2 correos: el automático a los 20 min sin cupón, y después el manual con cupón si el admin decide enviarlo). Recomendación pendiente de decidir: mantener el panel manual enfocado en casos que el cron nunca cubrió (conversaciones de más de 30 días, o períodos donde el cron estuvo inactivo), no como refuerzo de casos ya atendidos por el automático. Pendiente desde el 20/07/2026.

## Mejora futura: restringir el checkbox "Prepara para la PAES" a categorías relevantes

Evaluar si el checkbox "Prepara para la PAES" debería estar restringido a ciertas categorías relevantes (Matemáticas, Lenguaje, Ciencias, Historia), en vez de estar disponible para cualquier categoría sin restricción. Detectado el 10/07/2026 al trabajar el SEO de PAES en detalle_servicio.php.

## Pendiente: subir imagen/ícono real en cards de novedades (admin_marketing_cards.php, tab Novedades)

`nb_generar_imagen_novedad_post()` y `nb_generar_imagen_novedad_history()` (`app/helpers/imagen_compartir.php`) ya reservan el espacio arriba del título con un círculo placeholder liso color acento (`$diamIcono=90`, sin lógica de carga). Falta la funcionalidad real: que el admin pueda subir una imagen/ícono al crear la novedad (nuevo input file en el formulario de `admin_marketing_cards.php?tab=novedades`, guardarla en disco/BD, y que el generador dibuje esa imagen recortada en círculo en vez del placeholder liso — mismo patrón que `nb_dibujar_avatar()` usa para la foto del tutor en servicios). Diseñado el 09/07/2026, sin implementar — mejora para otra sesión.

## Pendiente: bug de z-index en admin_leads_gmail.php (barra de selección tapada por nav_bottom en móvil)

Mismo bug que encontramos y arreglamos hoy en admin_marketing_cards.php: `#action-bar` (admin_leads_gmail.php:373-374, z-50) queda por debajo de `nav_bottom.php:152` (z-[60]) — ambos son `fixed bottom-0` de ancho completo en móvil, así que nav_bottom tapa la barra de selección "Ver como carrusel"/acción al seleccionar filas. admin_marketing_cards.php copió este patrón de admin_leads_gmail.php y heredó el problema (ahí lo arreglamos ocultando nav_bottom vía JS solo mientras la barra de selección esté visible, sincronizado con syncBar()). Aplicar el mismo fix acá cuando se retome este archivo — no se tocó ahora a pedido explícito del usuario, fuera de alcance de la sesión del 09/07/2026.

## Implementado: sello "Verificado" automático por dominio institucional (simplificado 10/07/2026)

Ya NO es un plan pendiente — está implementado y funcionando, y hoy se simplificó: se eliminó el paso de revisión manual del admin (confirmado que nunca discriminaba — aprobaba a todos en la cola), así que el sello depende 100% del cálculo automático en `register.php`, sin intervención humana.

CASO DETONANTE (histórico, 03/06/2026): Paulina (licenciada en matemáticas, profesora PAES) se registró con Gmail y su perfil mostraba "Estudiante Universitario" sin serlo. Motivó este sistema.

Flujo real, con archivo:línea:
- `register.php:58-72` — al registrarse, 3 ramas deciden `tipo`/`verificacion_estado` en el INSERT mismo: dominio institucional → `aprobado`; excepción VIP (`excepciones_email`) → `aprobado`; cualquier otro caso (default) → `particular` + `pendiente`. No depende de ninguna acción posterior del usuario.
- `login.php:92,211-213` — si `verificacion_estado='pendiente'` y no hay `bio` cargada, redirige una vez a `/completar_perfil`. Con `bio` ya cargada, entra normal a `/vitrina` (con `?aviso=verificacion_pendiente`, ver nota de limpieza abajo).
- `app/completar_perfil.php` — formulario real (tipo + carrera/universidad/año egreso/años experiencia + bio ≥100 caracteres), existe y funciona.
- `app/admin_usuarios.php` — **ya NO tiene** pestaña de revisión manual (eliminada 10/07/2026: tab "Pendientes", query, botones aprobar/rechazar, badge de alerta en `contar_alertas_sistema.php`, y las funciones `enviarCorreoVerificacionAprobada`/`Rechazada` en `correo.php`). En su lugar, la tabla "Todos" tiene un filtro de inspección "Verificado: Sí/No" (`?verificado=si|no`), sin ninguna acción de aprobar/rechazar — solo lectura.
- `perfil.php:186-187,524-526`, `ver_apunte.php:242-243,763`, `detalle_servicio.php:523` — badge ✓ "Verificado" condicionado a `verificacion_estado='aprobado'`.

**Diferencia importante respecto al plan original**: el punto "Restricción: si pendiente/rechazado, NO puede publicar servicios ni apuntes" **no se implementó así**. Confirmado por grep: `publicar_servicio.php` y `formulario_subir_apunte.php` no tienen ninguna referencia a `verificacion_estado` — un usuario pendiente puede publicar y comprar/vender sin restricción. El único efecto real de quedar "pendiente" sin revisar es que no aparece el badge ✓. Si en algún momento se quiere bloquear publicación de verdad, es trabajo nuevo, no algo que ya esté y haya que destrabar.

DOMINIOS INSTITUCIONALES: se gestionan en tabla `dominios_permitidos`. Lista actual via:
`SELECT dominio, institucion FROM dominios_permitidos ORDER BY institucion ASC;`

### Limpieza menor pendiente (candidato para sesión de limpieza futura)
`login.php:212` genera la URL `/vitrina?aviso=verificacion_pendiente` para mostrar un banner de aviso — pero ningún archivo en `app/` lee ese parámetro `aviso`. Código muerto sin efecto visible; candidato a eliminar (o a implementar el banner, si se decide que vale la pena) en una sesión dedicada.

### Bug menor preexistente: `dominio`/`verificacion_estado` no se recalculan si un admin cambia el correo de un usuario
`admin_usuarios.php:146-156` (acción `editar_usuario`, modal "Editar Usuario") permite a un admin cambiar el `correo` de cualquier usuario, pero el `UPDATE` solo toca `nombre`/`correo` — no recalcula `dominio` ni `verificacion_estado` contra `dominios_permitidos`. Si un admin le pone un correo institucional a alguien ahí, no queda verificado automáticamente. Nota: **no** existe este caso vía autoservicio — `editar_datos.php` tiene el campo `correo` en `readonly` (línea 238), un usuario no puede cambiar su propio correo. Candidato a fix en otra sesión (recalcular `dominio`/`verificacion_estado` en ese mismo `UPDATE`, igual que hace `register.php`).

## Pendiente: Contratación de múltiples horas consecutivas con precio proporcional

Nueva funcionalidad: contratación de múltiples horas consecutivas con precio proporcional (precio pasa a ser precio/hora). Investigación de alcance completa hecha el 09/07/2026 — toca: contratos.duracion_horas (columna nueva), contratar_servicio.php (selector de duración), crear_contrato.php (extender lock anti-race a N bloques consecutivos, todo-o-nada), iniciar_pago_servicio.php (corregir monto fijo en preapproval de MercadoPago — usar precio dinámico), UI de precio en tarjetas/detalle (aclarar "/hora"). Requiere sesión dedicada, no es un fix rápido.

## Pendiente: Fase 2 de admin_marketing_cards.php — cards de novedades/anuncios de plataforma

Fase 2 de admin_marketing_cards.php: agregar generador de cards de NOVEDADES/anuncios de plataforma (no solo servicios existentes) — nuevo generador de imagen tipo GD con plantilla propia (título, ícono, branding), a diferencia de img_servicio.php que dibuja datos de un servicio real. Fuente del contenido: admin redacta manualmente el texto de la novedad (Claude Code no tiene memoria propia entre sesiones; puede ayudar a redactar leyendo el historial de commits recientes o este mismo CLAUDE.md si se le pide explícitamente). Mantener los mismos 2 formatos que ya existen (POST 1:1 e HISTORY 9:16) — cubren Instagram Feed/Facebook (post) e Instagram Story/TikTok (history) sin necesitar un formato por cada red social, que multiplicaría el trabajo sin agregar valor proporcional. Diseñado el 09/07/2026, tras completar la v1 de Marketing/Cards para servicios.

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Pendiente: Funcionalidad "Soporte Nubira" (chat admin→usuario)

Plan auditado y aprobado. NO implementar hasta cerrar caso Javiera Vásquez (PUCV).

### Contexto
Admin necesita enviar mensajes directos a usuarios desde Nubira (sin correo manual). El usuario recibe aviso por correo + ve el hilo en su bandeja de mensajes con badge "Soporte Nubira".

### Ajustes confirmados
- Nombre unificado: **"Soporte Nubira"** en toda la UI (badge, chat, lista). SMTP puede seguir como "Equipo Nubira".
- Ocultar usuario fantasma en admin_usuarios.php: `WHERE id != SOPORTE_USER_ID`
- Filtro "Soporte" en admin_chats.php: opcional, dejar para después

### Pasos de implementación
**Paso 0 — phpMyAdmin (manual):**
- INSERT alumnos: `nombre='Soporte Nubira'`, `correo='soporte@nubira.cl'`, `rol='admin'`, `visible=0`, `confirmado=1` → anotar ID como SOPORTE_USER_ID
- INSERT servicios: `titulo='Soporte Nubira'`, `alumno_id=SOPORTE_USER_ID`, `estado='aprobado'`, `visible=1`, `precio=0` → anotar ID como SOPORTE_SERVICE_ID

**Paso 1 — `app/config.php` (+2 líneas):**
```php
define('SOPORTE_USER_ID', <id>);
define('SOPORTE_SERVICE_ID', <id>);
```

**Paso 2 — `app/admin_enviar_mensaje.php` (reescritura ~60 líneas):**
- Buscar/crear conversación con `servicio_id=SOPORTE_SERVICE_ID`, `comprador_id=destino_id`, `vendedor_id=SOPORTE_USER_ID`
- INSERT en `mensajes` con `remitente_id=SOPORTE_USER_ID` (bypass DLP, inserción directa)
- UPDATE `conversaciones` SET `ultima_interaccion=NOW()`, `oculto_comprador=0`
- `enviarCorreoNuevoMensaje()` con `$nombreEmisor='Soporte Nubira'` — enviar siempre (no aplicar condición offline)

**Paso 3 — `app/mis_chats.php` (~15 líneas):**
- Agregar `(c.servicio_id = SOPORTE_SERVICE_ID) AS es_soporte` al SELECT
- En render: avatar rose, etiqueta "Soporte", nombre forzado a "Soporte Nubira"

**Paso 4 — `app/chat_previo_contrato.php` (~10 líneas):**
- `$es_soporte = ((int)$chat['servicio_id'] === SOPORTE_SERVICE_ID)`
- Ocultar botones "Contratar"/"Ver Aviso" si `$es_soporte`
- Mostrar nombre "Soporte Nubira" fijo (sin truncar apellido)

**Paso 5 — `app/admin_usuarios.php`:**
- Agregar `AND id != SOPORTE_USER_ID` en el WHERE del query de listado de usuarios

### Riesgos a recordar
- Servicio fantasma NUNCA eliminar/ocultar (rompe INNER JOIN en mis_chats y chat_previo_contrato)
- `notificar_inactividad_chat.php`: agregar `AND c.vendedor_id != SOPORTE_USER_ID` para que el cron no envíe alerts de inactividad al usuario fantasma

---

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

### Pendiente desde 07/07/2026: eliminar 5 archivos huérfanos en Hostinger
Ya eliminados y commiteados en local (commit 9c96d96). Hay que borrarlos también del servidor vía FileZilla en /home/u516405553/domains/nubira.cl/public_html/:
- app/panel_tutor_guia.php
- app/centro_tutores.php
- app/admin/gestionar_guia.php
- app/api/registrar_feedback_guia.php
- app/mercadopago_webhook.php

Contexto: ninguno tenía ruta activa en .htaccess ni links reales en el repo (diagnóstico completo antes de eliminar). No se tocaron las tablas guia_tutores_contenido ni guia_feedback — quedan vacías en la BD por si se necesita recuperar el contenido a futuro (mismo criterio que con Oportunidades).

No requiere verificación post-deploy especial (nada los enlazaba).

### Producto (sin urgencia)
- Conversación con Nubira Producto (Cowork) sobre cómo atraer primeros compradores reales
- Identificar segmento concreto (USACH / PUC / AIEP / PAES)
- Plan concierge para cerrar primera transacción manual

### Técnico viejo
- BUG conocido panel admin banco_imagenes: subir imagen nueva crea registro adicional en lugar de reemplazar. Workaround manual: tras subir/cambiar imagen del banco en producción, ejecutar el UPDATE general que reasigna imagen_banco_id a la imagen activa más reciente por categoría (con COLLATE utf8mb4_unicode_ci si las tablas tienen collations distintas). Refactor pendiente: agregar botón 'Reemplazar' explícito al panel admin que sobrescriba el registro existente.
- Git filter-repo 2da pasada (keys viejas en commits antiguos)
- Resetear el otro PC cuando vuelva a él
- Copia segura del config.php de producción
- Cambiar contraseñas SMTP y key Gemini que pasaron por chat
- Apelar Google
- Actualizar XAMPP a PHP 8.2 (para poder testear flujo de pago en local)

## Mejoras de UX/Growth pendientes

## Secciones de descubrimiento estilo Airbnb (idea pendiente)

Implementar carruseles contextuales en vitrina.php similar a 'Alojamientos en X' de Airbnb. La arquitectura actual ya lo permite (cada carrusel es una query SQL + render_card).

Ideas iniciales en orden de prioridad:
1. 'Tutores top esta semana' (más contratos cerrados últimos 7 días)
2. 'Empieza mañana' (servicios con próximo cupo dentro de 48h)
3. 'Bajo $10.000' (servicios económicos)
4. 'Para tu PAES' (filtro por keyword en titulo/descripcion)
5. 'Materias críticas primer año' (Cálculo, Álgebra, Química)
6. 'Tutores que responden en menos de 1 hora' (requiere métrica de respuesta)

Requisitos previos:
- Recategorización de los 55 servicios completada
- 12 imágenes IA del banco completas
- Definir métricas de comportamiento del tutor (tiempo respuesta promedio)

Implementación: cada carrusel = nueva query SQL en vitrina.php + componente render_card existente. Sin trabajo nuevo de diseño.

## Estrategia SEO Nubira (pendiente)

## Estrategia SEO para vencer competencia (Superprof, Studocu, Wuolah)

### Mes 1 - SEO técnico
- Schema.org markup en cada servicio (tipo: Service + Person + Review)
- Sitemap.xml dinámico generado desde la BD
- robots.txt: permitir /, bloquear /app/, /admin/
- Meta tags dinámicos por servicio: title, description, Open Graph, Twitter Cards
- Lighthouse 90+ móvil

### Mes 2 - Contenido (blog en /blog)
- 2 artículos/semana enfocados en long-tail Chile:
  - 'Cómo prepararse para PAES Matemática 2026'
  - 'Mejores tutores de Cálculo en USACH'
  - 'Ramos críticos primer año de Ingeniería Chile'
  - 'Cuánto cuesta una clase particular en Santiago 2026'
- Cada artículo apunta a servicios reales de Nubira (links internos)

### Mes 3 - Autoridad (backlinks)
- Contactar 10 centros de alumnos de universidades chilenas
- Inscripción en Google My Business
- Tutores comparten perfil en LinkedIn

### Diferenciador clave
Contenido chileno específico que la competencia internacional no puede hacer:
- PAES, universidades chilenas, ramos específicos
- Pesos chilenos, modalidad híbrida
- Comisión transparente
- Pago protegido en CLP

## Deploy banco de imágenes - notas críticas
- Al ejecutar CREATE TABLE banco_imagenes en producción (Hostinger phpMyAdmin), USAR COLLATE utf8mb4_unicode_ci en la columna categoria (NO utf8mb4_general_ci como quedó en local).
- Después de subir imágenes IA al banco en producción, ejecutar el UPDATE general que asigna imagen_banco_id a todos los servicios por categoría.
- El UPDATE necesita COLLATE explícito si las tablas tienen collations distintas.

## Onboarding 'Cómo funciona Nubira' (decisión técnica)

Slides hardcodeados en componente PHP. Cuando se valide que el onboarding funciona y se necesite iterar copy/imágenes sin deploy, migrar a tabla onboarding_slides editable desde nuevo panel admin (patrón similar a avisos_campanas pero sin fan-out a usuarios).

Validación clave: ¿cuántos usuarios COMPLETAN los 5 slides vs cuántos saltan en slide 1? Medir con la columna onboarding_visto + opcionalmente un onboarding_slide_max si se quiere granularidad.

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
- `app/config.php` has 2 blank lines BEFORE the `<?php` tag (lines 1-2) → emits output before PHP starts; latent "headers already sent" risk. Fix in a later iteration (strip the leading blank lines).

## Key Patterns
- PRG (Post-Redirect-Get) enforced
- Spanish plural-stripping for search: remove trailing `s` or `es` when word length permits
- Bot isolation at INSERT time (not query-time filtering)
- Ticket state: admin response → `en_proceso` (not `resuelto`) so notifications fire
- Always use `require_once __DIR__ . '/file.php'` for includes
- Image pipeline: 3-size WebP (thumb 240px/q78, card 480px/q80, main 1200px/q82)