<?php
/**
 * CRON: COPILOTO DE MARKETING — RECOLECTOR INSTAGRAM (NUBIRA 2.0)
 *
 * Frecuencia recomendada: 1 vez al día
 * Ubicación: /app/cron/copiloto_instagram.php
 *
 * Fase 2, Pieza 2B del Copiloto de Marketing: snapshot diario de la cuenta
 * oficial de Instagram (perfil + reach + últimas publicaciones). Best-effort:
 * un fallo parcial (ej. insights de un post puntual, o incluso del perfil)
 * NUNCA bota el cron completo — mismo criterio que copiloto_recolector.php.
 * NO se integra al brief de Gemini todavía — eso es la Pieza 2C.
 */

if (php_sapi_name() !== 'cli' && !isset($_GET['token'])) {
    http_response_code(403);
    die('Forbidden');
}

// Mismo patrón probado en producción que recalcular_tiempos_tutores.php:
// token hardcodeado acá (no vía .env) para disparo manual por URL/curl.
define('CRON_COPILOTO_IG_TOKEN', '9b17c2634ce219eec652bfd434bb6af53e33aa8b59d1ef0b');

if (php_sapi_name() !== 'cli' && !hash_equals(CRON_COPILOTO_IG_TOKEN, $_GET['token'] ?? '')) {
    http_response_code(403);
    die('Forbidden');
}

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('memory_limit', '256M');
set_time_limit(120);

date_default_timezone_set('America/Santiago');

$app_dir = dirname(__DIR__); // sube de /app/cron/ a /app/
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/helpers/instagram.php';

// Logging
$log_file = __DIR__ . '/logs/copiloto_instagram.log';
function log_cron($msg) {
    global $log_file;
    file_put_contents($log_file, date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND);
}

log_cron("=== INICIO cron copiloto_instagram ===");

// -----------------------------------------------------------------------
// 0. TABLA DE SNAPSHOTS
// -----------------------------------------------------------------------
$conn->query("CREATE TABLE IF NOT EXISTS copiloto_instagram_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    followers_count INT NOT NULL DEFAULT 0,
    media_count INT NOT NULL DEFAULT 0,
    reach_dia INT NULL,
    top_posts JSON NULL,
    datos_perfil JSON NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Columnas de clics de perfil (Quick-win 1) — ALTER separado del CREATE
// porque la tabla ya existe desde antes en instalaciones que corrieron
// este cron previamente. Mismo patrón idempotente que copiloto_recolector.php
// usa para brief_texto/brief_generado_en/brief_error.
try { $conn->query("ALTER TABLE copiloto_instagram_snapshots ADD COLUMN profile_views INT NULL DEFAULT NULL"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE copiloto_instagram_snapshots ADD COLUMN website_clicks INT NULL DEFAULT NULL"); } catch (Throwable $e) {}

// -----------------------------------------------------------------------
// 1. PERFIL — followers_count, media_count, username, name, biography
// -----------------------------------------------------------------------
$followers_count = 0;
$media_count     = 0;
$datos_perfil    = ['username' => null, 'name' => null, 'biography' => null];

$perfil = nb_ig_perfil();
if ($perfil['ok']) {
    $d = $perfil['data'];
    $followers_count = (int)($d['followers_count'] ?? 0);
    $media_count     = (int)($d['media_count'] ?? 0);
    $datos_perfil    = [
        'username'  => $d['username'] ?? null,
        'name'      => $d['name'] ?? null,
        'biography' => $d['biography'] ?? null,
    ];
} else {
    log_cron("PERFIL falló (snapshot sigue igual, con ceros): " . $perfil['error']);
}

// -----------------------------------------------------------------------
// 2. INSIGHTS DE CUENTA — reach del día
// -----------------------------------------------------------------------
$reach_dia = null;
$insights = nb_ig_insights_cuenta();
if ($insights['ok']) {
    foreach (($insights['data']['data'] ?? []) as $metrica) {
        if (($metrica['name'] ?? '') === 'reach') {
            $valores = $metrica['values'] ?? [];
            $ultimo  = end($valores);
            if ($ultimo !== false) {
                $reach_dia = (int)($ultimo['value'] ?? 0);
            }
        }
    }
} else {
    log_cron("INSIGHTS DE CUENTA fallaron: " . $insights['error']);
}

// -----------------------------------------------------------------------
// 2B. INSIGHTS DE CUENTA — profile_views y website_clicks (metric_type=
//     total_value, llamada aparte de reach por ser un tipo de métrica
//     distinto — ver helpers/instagram.php).
// -----------------------------------------------------------------------
$profile_views  = null;
$website_clicks = null;

$clicks = nb_ig_insights_clicks_perfil();
if ($clicks['ok']) {
    $profile_views  = $clicks['profile_views'];
    $website_clicks = $clicks['website_clicks'];
} else {
    log_cron("INSIGHTS DE CLICS DE PERFIL fallaron: " . $clicks['error']);
}

// -----------------------------------------------------------------------
// 3. ÚLTIMAS PUBLICACIONES + insights por post (best-effort real: si falla
//    el insight de UN post puntual, ese post igual se guarda sin reach/saved)
// -----------------------------------------------------------------------
$top_posts = [];
$posts_con_reach = 0;

$media = nb_ig_media_reciente(12);
if ($media['ok']) {
    foreach (($media['data']['data'] ?? []) as $post) {
        $post_reach = null;
        $post_saved = null;

        $insights_post = nb_ig_insights_media($post['id'] ?? '');
        if ($insights_post['ok']) {
            foreach (($insights_post['data']['data'] ?? []) as $m) {
                if (($m['name'] ?? '') === 'reach') {
                    $post_reach = (int)($m['values'][0]['value'] ?? 0);
                    $posts_con_reach++;
                }
                if (($m['name'] ?? '') === 'saved') {
                    $post_saved = (int)($m['values'][0]['value'] ?? 0);
                }
            }
        }
        // Si $insights_post falló (media que no soporta esas métricas), el
        // post se guarda igual, solo sin reach/saved — nunca rompe el loop.

        $top_posts[] = [
            'id'             => $post['id'] ?? null,
            'caption'        => mb_substr((string)($post['caption'] ?? ''), 0, 140),
            'media_type'     => $post['media_type'] ?? null,
            'timestamp'      => $post['timestamp'] ?? null,
            'permalink'      => $post['permalink'] ?? null,
            'like_count'     => (int)($post['like_count'] ?? 0),
            'comments_count' => (int)($post['comments_count'] ?? 0),
            'reach'          => $post_reach,
            'saved'          => $post_saved,
        ];
    }
} else {
    log_cron("MEDIA RECIENTE falló: " . $media['error']);
}

// -----------------------------------------------------------------------
// 4. UPSERT DEL SNAPSHOT DEL DÍA
// -----------------------------------------------------------------------
$fecha_hoy = date('Y-m-d');
$top_posts_json    = json_encode($top_posts, JSON_UNESCAPED_UNICODE);
$datos_perfil_json = json_encode($datos_perfil, JSON_UNESCAPED_UNICODE);

$stmt = $conn->prepare("
    INSERT INTO copiloto_instagram_snapshots
        (fecha, followers_count, media_count, reach_dia, profile_views, website_clicks, top_posts, datos_perfil)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        followers_count = VALUES(followers_count),
        media_count = VALUES(media_count),
        reach_dia = VALUES(reach_dia),
        profile_views = VALUES(profile_views),
        website_clicks = VALUES(website_clicks),
        top_posts = VALUES(top_posts),
        datos_perfil = VALUES(datos_perfil)
");
$stmt->bind_param(
    'siiiiiss',
    $fecha_hoy,
    $followers_count,
    $media_count,
    $reach_dia,
    $profile_views,
    $website_clicks,
    $top_posts_json,
    $datos_perfil_json
);
$stmt->execute();
$stmt->close();

// -----------------------------------------------------------------------
// 5. RESUMEN
// -----------------------------------------------------------------------
$resumen = sprintf(
    "Instagram snapshot %s | followers=%d | media_count=%d | reach_dia=%s | profile_views=%s | website_clicks=%s | posts_recolectados=%d | posts_con_reach=%d",
    $fecha_hoy,
    $followers_count,
    $media_count,
    $reach_dia === null ? 'N/D' : $reach_dia,
    $profile_views === null ? 'N/D' : $profile_views,
    $website_clicks === null ? 'N/D' : $website_clicks,
    count($top_posts),
    $posts_con_reach
);

log_cron($resumen);
log_cron("=== FIN cron copiloto_instagram ===");
log_cron("");

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain');
    echo "OK | $resumen\n";
} else {
    echo $resumen . PHP_EOL;
}
