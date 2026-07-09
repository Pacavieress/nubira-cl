<?php
// Endpoint público: sirve la imagen compartible (POST/HISTORY) de un servicio.
// SIN el shield general (app/middleware/antibot.php) — ese bloquea por User-Agent
// (curl, python-requests, node-fetch, etc.), y los crawlers de preview de WhatsApp/
// Telegram/Slack/Discord suelen usar exactamente esas firmas. Bloquearlos rompería
// el propósito de este endpoint. Sí tiene su PROPIO rate-limit, sin bloqueo por UA
// (ver check_img_servicio_rate_limit), con tabla independiente de shield_rate_limit
// para no cruzar contadores con el shield general del sitio.
// Errores → imagen placeholder, NUNCA HTML.
// El tracking de shares NO va acá (los previews cargan estas URLs); se hace
// en app/track_share.php sobre la acción real del usuario (Opción B).
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/seguridad_url.php';
require_once __DIR__ . '/helpers/imagen_compartir.php';

if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', '0'); // que un warning no corrompa el binario

function nb_servir_placeholder(int $code = 404): void {
    http_response_code($code);
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-store');
    $ph = ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)) . '/upload/compartir/placeholder.jpg';
    if (is_file($ph)) {
        readfile($ph);
    } else {
        // Fallback en memoria si el estático no está en disco (ej. no se subió en un
        // deploy manual a Hostinger) — nunca responder con body vacío bajo
        // Content-Type: image/jpeg, eso rompe el <img> silenciosamente (recuadro gris).
        $img = imagecreatetruecolor(1080, 1080);
        $bg = imagecolorallocate($img, 240, 246, 250);
        imagefilledrectangle($img, 0, 0, 1080, 1080, $bg);
        imagejpeg($img, null, 85);
        imagedestroy($img);
    }
    exit;
}

// [NUBIRA 2.0] Rate limit propio de este endpoint — 40 req/min por IP, bloqueo 5 min.
// Sin lista de User-Agents (a propósito, ver comentario de arriba). Tabla auto-migrada
// (mismo patrón que video_thumb_path): CREATE TABLE IF NOT EXISTS embebido acá.
function check_img_servicio_rate_limit(mysqli $conn): void {
    // Excepción: admin autenticado no cuenta contra el rate-limit. Sin esto,
    // /admin/marketing-cards se autobloquea solo — carga N imágenes de golpe desde
    // la misma IP del admin, y el límite (pensado para tráfico público/scraping)
    // se dispara con la propia grilla del panel. El límite sigue igual para todo
    // el tráfico anónimo (incluidos los crawlers de preview de WhatsApp/Telegram).
    if (($_SESSION['rol'] ?? '') === 'admin') return;

    $conn->query("CREATE TABLE IF NOT EXISTS img_servicio_rate_limit (
        ip VARCHAR(45) NOT NULL PRIMARY KEY,
        contador INT NOT NULL DEFAULT 1,
        ventana_inicio INT NOT NULL,
        bloqueado_hasta INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ahora = time();
    $limit_requests = 40;
    $limit_window = 60;
    $bloqueo_duracion = 300; // 5 minutos

    // [ATÓMICO] Garantiza que la fila exista sin condición de carrera (ej. las 2 peticiones
    // paralelas de POST+HISTORY cargando al mismo tiempo desde la misma IP nueva).
    // "ON DUPLICATE KEY UPDATE ip = ip" es un no-op: si ya existe la fila, no la toca;
    // si no existe, la crea. Reemplaza el SELECT+INSERT que causaba "Duplicate entry".
    $stmt = $conn->prepare("INSERT INTO img_servicio_rate_limit (ip, contador, ventana_inicio) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE ip = ip");
    $stmt->bind_param("si", $ip, $ahora);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT contador, ventana_inicio, bloqueado_hasta FROM img_servicio_rate_limit WHERE ip = ? LIMIT 1");
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // La fila siempre existe acá (garantizada por el upsert de arriba).
    if (!empty($row['bloqueado_hasta']) && $row['bloqueado_hasta'] > $ahora) {
        nb_servir_placeholder(429);
    }

    $tiempo_en_ventana = $ahora - (int)$row['ventana_inicio'];

    if ($tiempo_en_ventana < $limit_window) {
        $nuevo_contador = (int)$row['contador'] + 1;

        if ($nuevo_contador > $limit_requests) {
            $hasta = $ahora + $bloqueo_duracion;
            $stmt = $conn->prepare("UPDATE img_servicio_rate_limit SET contador = ?, bloqueado_hasta = ? WHERE ip = ?");
            $stmt->bind_param("iis", $nuevo_contador, $hasta, $ip);
            $stmt->execute();
            $stmt->close();
            nb_servir_placeholder(429);
        }

        $stmt = $conn->prepare("UPDATE img_servicio_rate_limit SET contador = ? WHERE ip = ?");
        $stmt->bind_param("is", $nuevo_contador, $ip);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("UPDATE img_servicio_rate_limit SET contador = 1, ventana_inicio = ?, bloqueado_hasta = NULL WHERE ip = ?");
        $stmt->bind_param("is", $ahora, $ip);
        $stmt->execute();
        $stmt->close();
    }
}
check_img_servicio_rate_limit($conn);

$formato = (($_GET['f'] ?? 'post') === 'history') ? 'history' : 'post';
$servicio_id = nubira_desencriptar_id($_GET['id'] ?? '');

if ($servicio_id <= 0) nb_servir_placeholder();

// Validar servicio aprobado + visible
$st = $conn->prepare("SELECT estado, COALESCE(visible,1) AS v FROM servicios WHERE id = ? LIMIT 1");
$st->bind_param('i', $servicio_id);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();
if (!$row || $row['estado'] !== 'aprobado' || (int)$row['v'] !== 1) nb_servir_placeholder();

// Generar o servir desde cache (helper del Paso 3)
$file = nb_obtener_imagen_compartir($servicio_id, $formato);
if ($file === '' || !is_file($file)) nb_servir_placeholder(500);

// Servir el JPG con cache largo
if (ob_get_level() > 0) ob_clean(); // por si algún include emitió whitespace
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400, immutable');
header('Content-Length: ' . filesize($file));
readfile($file);
exit;
