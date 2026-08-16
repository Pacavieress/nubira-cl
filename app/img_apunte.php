<?php
// Endpoint público: sirve la imagen compartible (POST/HISTORY) de un apunte.
// Mismo patrón que app/img_servicio.php — ver ese archivo para el razonamiento
// completo de las decisiones (sin shield general, rate-limit propio, placeholder
// en errores, tracking NO va acá).
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/seguridad_url.php';
require_once __DIR__ . '/helpers/imagen_compartir_apunte.php';

if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', '0');

function nb_servir_placeholder_apunte(int $code = 404): void {
    http_response_code($code);
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-store');
    $ph = ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)) . '/upload/compartir/placeholder.jpg';
    if (is_file($ph)) {
        readfile($ph);
    } else {
        $img = imagecreatetruecolor(1080, 1080);
        $bg = imagecolorallocate($img, 240, 246, 250);
        imagefilledrectangle($img, 0, 0, 1080, 1080, $bg);
        imagejpeg($img, null, 85);
        imagedestroy($img);
    }
    exit;
}

// Rate limit propio de este endpoint — mismo criterio que check_img_servicio_rate_limit
// (40 req/min por IP, bloqueo 5 min), tabla independiente para no cruzar contadores.
function check_img_apunte_rate_limit(mysqli $conn): void {
    if (($_SESSION['rol'] ?? '') === 'admin') return;

    $conn->query("CREATE TABLE IF NOT EXISTS img_apunte_rate_limit (
        ip VARCHAR(45) NOT NULL PRIMARY KEY,
        contador INT NOT NULL DEFAULT 1,
        ventana_inicio INT NOT NULL,
        bloqueado_hasta INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ahora = time();
    $limit_requests = 40;
    $limit_window = 60;
    $bloqueo_duracion = 300;

    $stmt = $conn->prepare("INSERT INTO img_apunte_rate_limit (ip, contador, ventana_inicio) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE ip = ip");
    $stmt->bind_param("si", $ip, $ahora);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT contador, ventana_inicio, bloqueado_hasta FROM img_apunte_rate_limit WHERE ip = ? LIMIT 1");
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!empty($row['bloqueado_hasta']) && $row['bloqueado_hasta'] > $ahora) {
        nb_servir_placeholder_apunte(429);
    }

    $tiempo_en_ventana = $ahora - (int)$row['ventana_inicio'];

    if ($tiempo_en_ventana < $limit_window) {
        $nuevo_contador = (int)$row['contador'] + 1;

        if ($nuevo_contador > $limit_requests) {
            $hasta = $ahora + $bloqueo_duracion;
            $stmt = $conn->prepare("UPDATE img_apunte_rate_limit SET contador = ?, bloqueado_hasta = ? WHERE ip = ?");
            $stmt->bind_param("iis", $nuevo_contador, $hasta, $ip);
            $stmt->execute();
            $stmt->close();
            nb_servir_placeholder_apunte(429);
        }

        $stmt = $conn->prepare("UPDATE img_apunte_rate_limit SET contador = ? WHERE ip = ?");
        $stmt->bind_param("is", $nuevo_contador, $ip);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("UPDATE img_apunte_rate_limit SET contador = 1, ventana_inicio = ?, bloqueado_hasta = NULL WHERE ip = ?");
        $stmt->bind_param("is", $ahora, $ip);
        $stmt->execute();
        $stmt->close();
    }
}
check_img_apunte_rate_limit($conn);

$formato = (($_GET['f'] ?? 'post') === 'history') ? 'history' : 'post';
$apunte_id = nubira_desencriptar_id($_GET['id'] ?? '');

if ($apunte_id <= 0) nb_servir_placeholder_apunte();

// Validar apunte aprobado
$st = $conn->prepare("SELECT estado FROM apuntes WHERE id = ? LIMIT 1");
$st->bind_param('i', $apunte_id);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();
if (!$row || $row['estado'] !== 'aprobado') nb_servir_placeholder_apunte();

$file = nb_obtener_imagen_apunte($apunte_id, $formato);
if ($file === '' || !is_file($file)) nb_servir_placeholder_apunte(500);

if (ob_get_level() > 0) ob_clean();
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400, immutable');
header('Content-Length: ' . filesize($file));
readfile($file);
exit;
