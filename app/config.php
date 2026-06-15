

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

// =========================
// DAILY.CO (VIDEO LLAMADAS)
// =========================
define('DAILY_API_KEY', $_ENV['DAILY_API_KEY'] ?? '');
?>
