<?php
// Endpoint público: sirve la imagen compartible (POST/HISTORY) de un servicio.
// SIN shield/antibot. Errores → imagen placeholder, NUNCA HTML.
// El tracking de shares NO va acá (los previews cargan estas URLs); se hace
// en app/track_share.php sobre la acción real del usuario (Opción B).
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/seguridad_url.php';
require_once __DIR__ . '/helpers/imagen_compartir.php';

ini_set('display_errors', '0'); // que un warning no corrompa el binario

$formato = (($_GET['f'] ?? 'post') === 'history') ? 'history' : 'post';
$servicio_id = nubira_desencriptar_id($_GET['id'] ?? '');

function nb_servir_placeholder(int $code = 404): void {
    http_response_code($code);
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-store');
    $ph = ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)) . '/upload/compartir/placeholder.jpg';
    if (is_file($ph)) readfile($ph);
    exit;
}

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
