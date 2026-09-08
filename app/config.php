

<?php
require_once __DIR__ . '/env_loader.php';

// =========================
// CONFIGURACIÓN GENERAL
// =========================
define('BASE_URL', 'https://nubira.cl');
define('CURRENCY_ID', 'CLP'); // Moneda oficial

// =========================
// BASE DE DATOS
// =========================
define('DB_HOST', 'localhost');
define('DB_USER', 'TU_USUARIO_DB');
define('DB_PASS', 'TU_PASS_DB');
define('DB_NAME', 'TU_NOMBRE_DB');

// =========================
// MERCADO PAGO (PRODUCCIÓN)
// =========================
define('MP_ACCESS_TOKEN',               $_ENV['MP_ACCESS_TOKEN']               ?? '');
define('MP_ACCESS_TOKEN_OPORTUNIDADES', $_ENV['MP_ACCESS_TOKEN_OPORTUNIDADES'] ?? '');

// Opcionales pero recomendados (mejoran el score y visibilidad de pagos)
define('MP_INTEGRATOR_ID', 'dev_001_NUBIRA'); // Puedes inventarlo tipo 'dev_001_NUBIRA'
define('MP_WEBHOOK_URL', BASE_URL . '/app/notificaciones_mp.php'); // Webhook oficial
define('MP_STATEMENT_DESC', 'NUBIRA.CL'); // Texto que verá el comprador en su banco

// =========================
// EMAIL GENERAL
// =========================
define('EMAIL_FROM', 'no-reply@nubira.cl');
define('EMAIL_NAME', 'Nubira');
define('EMAIL_SUPPORT', 'soporte@nubira.cl');

// Secret para tokens de baja de correos (List-Unsubscribe).
// Debe coincidir con app/unsubscribe.php y los scripts de campaña.
define('UNSUB_SECRET', getenv('UNSUB_SECRET') ?: '');

// =========================
// CONFIGURACIÓN EXTRA
// =========================
// Tiempo máximo para liberar fondos a vendedor (en días)
define('CONTRATO_LIBERACION_DIAS', 3);

// Zona horaria
date_default_timezone_set('America/Santiago');

// =========================
// SMTP (HOSTINGER)
// =========================
define('SMTP_PASS_NOREPLY', $_ENV['SMTP_PASS_NOREPLY'] ?? '');
define('SMTP_PASS_CONTACTO', $_ENV['SMTP_PASS_CONTACTO'] ?? '');

// =========================
// GOOGLE GEMINI
// =========================
define('GEMINI_API_KEY', $_ENV['GEMINI_API_KEY'] ?? '');
if (GEMINI_API_KEY === '') { error_log('[Nubira] GEMINI_API_KEY no configurada en .env'); }

// Límite total (no mensual) de generaciones de descripción con IA en el MVP
// gratuito — app/datos/ia_nubira.php lo valida contra alumnos.generaciones_ia_usadas.
define('LIMITE_GENERACIONES_IA_GRATIS', 1);

// =========================
// INSTAGRAM (Fase 2 Copiloto — cuenta oficial Nubira)
// =========================
define('IG_ACCESS_TOKEN', $_ENV['IG_ACCESS_TOKEN'] ?? '');
define('IG_ACCOUNT_ID', $_ENV['IG_ACCOUNT_ID'] ?? '');
if (IG_ACCESS_TOKEN === '') { error_log('[Nubira] IG_ACCESS_TOKEN no configurada en .env'); }

// =========================
// DAILY.CO (VIDEO LLAMADAS)
// =========================
define('DAILY_API_KEY', $_ENV['DAILY_API_KEY'] ?? '');

// =========================
// MIGRACIÓN NEXT.JS — orígenes confiados para redirección post-login
// =========================
// login.php normalmente solo acepta `redir` como ruta relativa al propio dominio (anti
// open-redirect). Algunas páginas ya viven EXCLUSIVAMENTE en Next.js (sin equivalente en
// .htaccess) — sin esta lista blanca, un `redir` absoluto hacia esas páginas se descarta
// igual que uno hacia un dominio malicioso, y el usuario termina en /vitrina o, peor, en un
// 404 si la ruta ni siquiera existe en PHP (bug real encontrado 26/08/2026: Mi Perfil).
// Por ahora son solo orígenes de DESARROLLO — no existe todavía un dominio de producción
// para Next.js (server/ y web/ corren solo local). El día que se despliegue de verdad,
// agregar ese dominio acá (vía NEXTJS_TRUSTED_ORIGINS en .env) es obligatorio o este mismo
// bug reaparece en producción.
define('NEXTJS_TRUSTED_ORIGINS', array_values(array_filter(array_map('trim', explode(',',
    $_ENV['NEXTJS_TRUSTED_ORIGINS'] ?? 'http://localhost:3000,http://nubira.local:3000'
)))));
?>
