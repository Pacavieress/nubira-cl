<?php
/**
 * CRON: COPILOTO DE MARKETING — REFRESH DEL TOKEN DE INSTAGRAM (NUBIRA 2.0)
 *
 * Frecuencia recomendada: 1 vez por semana
 * Ubicación: /app/cron/copiloto_ig_refresh.php
 *
 * Renueva el token de larga duración de Instagram Login (graph.instagram.com)
 * antes de que expire (60 días desde la última emisión/refresh). Meta exige
 * que el token tenga al menos 24h de antigüedad desde el último
 * refresh/emisión para poder refrescarse de nuevo — con cadencia semanal
 * nunca se choca con esa regla.
 *
 * Guarda el resultado en copiloto_ig_token (ver el docblock de
 * helpers/instagram.php: esa tabla es la ÚNICA fuente de verdad del token
 * vigente desde el primer refresh exitoso, IG_ACCESS_TOKEN de .env queda
 * obsoleto). Si el refresh falla, NUNCA se borra ni se pisa el token
 * vigente — solo se registra el error y se suma un intento fallido; el
 * token anterior sigue siendo válido hasta que realmente expire.
 */

// Solo permitir ejecución por CLI o por Hostinger (no acceso web sin token)
if (php_sapi_name() !== 'cli' && !isset($_GET['token'])) {
    http_response_code(403);
    die('Forbidden');
}

// Mismo patrón que los otros crons del Copiloto: token hardcodeado acá (no
// vía .env) para disparo manual por URL/curl, comparado con hash_equals.
define('CRON_COPILOTO_IG_REFRESH_TOKEN', '8b3398229f8df8d9850d3d413eda9bb35ad6e6185db2fd35');

if (php_sapi_name() !== 'cli' && !hash_equals(CRON_COPILOTO_IG_REFRESH_TOKEN, $_GET['token'] ?? '')) {
    http_response_code(403);
    die('Forbidden');
}

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('memory_limit', '256M');
set_time_limit(60);

date_default_timezone_set('America/Santiago');

$app_dir = dirname(__DIR__); // sube de /app/cron/ a /app/
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/helpers/instagram.php';
require_once $app_dir . '/correo.php'; // enviarCorreo() — mismo require que usan otros crons (ej. enviar_despertar_dormidos.php)

// Logging
$log_file = __DIR__ . '/logs/copiloto_ig_refresh.log';
function log_cron($msg) {
    global $log_file;
    file_put_contents($log_file, date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND);
}

// Correo de alerta al admin — SOLO se llama tras un fallo (nunca tras un
// refresh exitoso). Umbrales aprobados: 2+ intentos fallidos consecutivos
// (~14 días sin refresh exitoso con cadencia semanal) O menos de 10 días
// reales de margen antes de que el token vigente expire — lo que ocurra
// primero. Sin deduplicación de envío a propósito: con cadencia semanal,
// mientras la condición se mantenga, se reenvía como máximo 1 vez por
// semana — no es spam, y evita la complejidad de un flag "ya se avisó".
function nb_ig_evaluar_alerta_correo(mysqli $conn): void {
    $res = $conn->query("SELECT ultimo_error, intentos_fallidos, expira_estimado_en FROM copiloto_ig_token WHERE id = 1 LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) return; // no debería pasar — ya se hizo el upsert antes de llamar esta función

    $intentos_fallidos = (int)$row['intentos_fallidos'];
    $expira_ts         = strtotime($row['expira_estimado_en']);
    $dias_restantes     = ($expira_ts - time()) / 86400;

    $por_fallos       = $intentos_fallidos >= 2;
    $por_vencimiento  = $dias_restantes <= 10;

    if (!$por_fallos && !$por_vencimiento) {
        log_cron(sprintf(
            'Alerta: sin correo (intentos_fallidos=%d, dias_restantes=%.1f, ningún umbral cruzado todavía)',
            $intentos_fallidos,
            $dias_restantes
        ));
        return;
    }

    $motivo = $por_fallos
        ? "{$intentos_fallidos} intentos de refresh fallidos seguidos"
        : 'quedan aprox. ' . max(0, round($dias_restantes)) . ' día(s) antes de que expire';

    $error_html = htmlspecialchars((string)($row['ultimo_error'] ?? 'sin detalle'), ENT_QUOTES, 'UTF-8');
    $expira_fmt = date('d/m/Y H:i', $expira_ts);

    $html = "
        <p>El token de Instagram del Copiloto de Marketing tiene un problema: <strong>{$motivo}</strong>.</p>
        <p style='margin:0; font-size:14px; color:#6B7280;'>Último error</p>
        <p style='margin:0 0 10px 0;'>{$error_html}</p>
        <p style='margin:0; font-size:14px; color:#6B7280;'>Intentos fallidos consecutivos</p>
        <p style='margin:0 0 10px 0;'>{$intentos_fallidos}</p>
        <p style='margin:0; font-size:14px; color:#6B7280;'>Vence (estimado)</p>
        <p style='margin:0 0 10px 0;'>{$expira_fmt}</p>
        <p>El token vigente NO se ha borrado ni sobrescrito — sigue siendo válido hasta esa fecha. Si el problema persiste, puede requerir regenerar el token a mano desde el flujo de autorización de Meta.</p>
    ";

    $enviado = enviarCorreo('pablocavieressagredo@gmail.com', 'Nubira — Token de Instagram del Copiloto con problemas', $html);
    log_cron("Alerta enviada por correo (motivo: {$motivo}) — resultado: " . ($enviado ? 'OK' : 'FALLÓ EL ENVÍO'));
}

log_cron("=== INICIO cron copiloto_ig_refresh ===");

// -----------------------------------------------------------------------
// 0. TABLA (auto-migración, mismo criterio que el resto del Copiloto: nunca
//    asumir que helpers/instagram.php ya corrió antes en este proceso).
// -----------------------------------------------------------------------
$conn->query("CREATE TABLE IF NOT EXISTS copiloto_ig_token (
    id INT PRIMARY KEY,
    token VARCHAR(255) NOT NULL,
    actualizado_en DATETIME NOT NULL,
    expira_estimado_en DATETIME NOT NULL,
    ultimo_error TEXT NULL,
    intentos_fallidos INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// -----------------------------------------------------------------------
// 1. TOKEN VIGENTE — misma resolución que usa el resto del Copiloto
//    (tabla primero, semilla de .env solo si la tabla está vacía).
// -----------------------------------------------------------------------
$token_actual = nb_ig_obtener_token();

if ($token_actual === '') {
    $msg = 'Sin token disponible para refrescar (ni en copiloto_ig_token ni en IG_ACCESS_TOKEN/.env).';
    log_cron("ERROR: {$msg}");

    // Deja constancia igual en la tabla (con token vacío) para que el
    // badge/correo de alerta lo detecten — no hay token previo que proteger.
    $stmt = $conn->prepare("
        INSERT INTO copiloto_ig_token (id, token, actualizado_en, expira_estimado_en, ultimo_error, intentos_fallidos)
        VALUES (1, '', NOW(), NOW(), ?, 1)
        ON DUPLICATE KEY UPDATE
            ultimo_error = VALUES(ultimo_error),
            intentos_fallidos = intentos_fallidos + 1
    ");
    $stmt->bind_param('s', $msg);
    $stmt->execute();
    $stmt->close();

    nb_ig_evaluar_alerta_correo($conn);

    log_cron("=== FIN cron copiloto_ig_refresh (sin token) ===");
    log_cron("");

    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/plain');
        echo "ERROR | {$msg}\n";
    } else {
        echo "ERROR | {$msg}" . PHP_EOL;
    }
    exit;
}

// -----------------------------------------------------------------------
// 2. LLAMADA AL ENDPOINT DE REFRESH
// -----------------------------------------------------------------------
$resultado = nb_ig_refrescar_token($token_actual);

if ($resultado['ok']) {
    // -------------------------------------------------------------------
    // 3a. ÉXITO — guarda el token nuevo, resetea el contador de fallos.
    // -------------------------------------------------------------------
    $token_nuevo = $resultado['access_token'];
    $expira_en   = date('Y-m-d H:i:s', time() + $resultado['expires_in']);

    $stmt = $conn->prepare("
        INSERT INTO copiloto_ig_token (id, token, actualizado_en, expira_estimado_en, ultimo_error, intentos_fallidos)
        VALUES (1, ?, NOW(), ?, NULL, 0)
        ON DUPLICATE KEY UPDATE
            token = VALUES(token),
            actualizado_en = VALUES(actualizado_en),
            expira_estimado_en = VALUES(expira_estimado_en),
            ultimo_error = NULL,
            intentos_fallidos = 0
    ");
    $stmt->bind_param('ss', $token_nuevo, $expira_en);
    $stmt->execute();
    $stmt->close();

    $resumen = "Refresh OK | expira_estimado_en={$expira_en}";
    log_cron($resumen);
} else {
    // -------------------------------------------------------------------
    // 3b. FALLO — NUNCA se toca la columna `token` ni `expira_estimado_en`
    //    existentes (el ON DUPLICATE de abajo no las incluye a propósito).
    //    El token vigente sigue siendo válido hasta que realmente expire.
    // -------------------------------------------------------------------
    $error_msg = $resultado['error'] ?? 'Fallo desconocido refrescando el token';

    $stmt = $conn->prepare("
        INSERT INTO copiloto_ig_token (id, token, actualizado_en, expira_estimado_en, ultimo_error, intentos_fallidos)
        VALUES (1, ?, NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY), ?, 1)
        ON DUPLICATE KEY UPDATE
            ultimo_error = VALUES(ultimo_error),
            intentos_fallidos = intentos_fallidos + 1
    ");
    // El INSERT (primera vez, tabla vacía) usa el token actual como semilla —
    // el UPDATE (caso normal, ya hay fila) IGNORA por completo esos valores.
    $stmt->bind_param('ss', $token_actual, $error_msg);
    $stmt->execute();
    $stmt->close();

    nb_ig_evaluar_alerta_correo($conn);

    $resumen = "Refresh FALLÓ (token vigente NO se tocó): {$error_msg}";
    log_cron($resumen);
}

// -----------------------------------------------------------------------
// 4. RESUMEN
// -----------------------------------------------------------------------
log_cron("=== FIN cron copiloto_ig_refresh ===");
log_cron("");

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain');
    echo "OK | $resumen\n";
} else {
    echo $resumen . PHP_EOL;
}
