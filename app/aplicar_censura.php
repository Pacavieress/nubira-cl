<?php
/**
 * ENDPOINT: APLICAR CENSURA A IMAGEN DE CHAT — NUBIRA 2.0
 * Recibe msg_id + array JSON de rectángulos en porcentajes.
 * Dibuja rectángulos negros sólidos sobre el archivo físico con GD y lo sobrescribe.
 */
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/conexion.php';

function responder_censura(bool $ok, string $msg = '') {
    echo json_encode(['ok' => $ok, 'msg' => $msg]);
    exit;
}

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    responder_censura(false, 'Sin permisos.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder_censura(false, 'Método no permitido.');
}

$msg_id     = (int)($_POST['msg_id'] ?? 0);
$rects_json = trim($_POST['rects'] ?? '');

if ($msg_id <= 0 || $rects_json === '') {
    responder_censura(false, 'Parámetros inválidos.');
}

$rects = json_decode($rects_json, true);
if (!is_array($rects) || empty($rects)) {
    responder_censura(false, 'Sin regiones marcadas.');
}
foreach ($rects as $r) {
    if (!isset($r['x_pct'], $r['y_pct'], $r['w_pct'], $r['h_pct'])) {
        responder_censura(false, 'Formato de rectángulos inválido.');
    }
}

// Cargar mensaje — solo visible=0 puede censurarse
$stmt = $conn->prepare("
    SELECT id, archivo_ruta, archivo_tipo, archivo_ruta_original
    FROM mensajes
    WHERE id = ? AND visible = 0 AND archivo_ruta IS NOT NULL
    LIMIT 1
");
$stmt->bind_param("i", $msg_id);
$stmt->execute();
$msg = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$msg) {
    responder_censura(false, 'Mensaje no encontrado o ya procesado.');
}

$mime = $msg['archivo_tipo'] ?? '';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    responder_censura(false, 'Tipo de archivo no soportado para censura.');
}

$ruta_fisica = __DIR__ . '/chat_archivos/' . $msg['archivo_ruta'];
if (!file_exists($ruta_fisica)) {
    responder_censura(false, 'Archivo físico no encontrado.');
}

// Guardar backup del original si todavía no existe
if (empty($msg['archivo_ruta_original'])) {
    $info        = pathinfo($ruta_fisica);
    $ruta_backup = $info['dirname'] . '/' . $info['filename'] . '_orig.' . $info['extension'];
    if (!copy($ruta_fisica, $ruta_backup)) {
        responder_censura(false, 'No se pudo crear la copia de seguridad.');
    }
    $ruta_backup_rel = pathinfo($msg['archivo_ruta'], PATHINFO_DIRNAME) . '/'
                     . pathinfo($msg['archivo_ruta'], PATHINFO_FILENAME) . '_orig.'
                     . pathinfo($msg['archivo_ruta'], PATHINFO_EXTENSION);
    $stmt_bk = $conn->prepare("UPDATE mensajes SET archivo_ruta_original = ? WHERE id = ?");
    $stmt_bk->bind_param("si", $ruta_backup_rel, $msg_id);
    $stmt_bk->execute();
    $stmt_bk->close();
}

// Cargar imagen con GD
$imagen = null;
switch ($mime) {
    case 'image/jpeg': $imagen = imagecreatefromjpeg($ruta_fisica); break;
    case 'image/png':
        $imagen = imagecreatefrompng($ruta_fisica);
        if ($imagen) {
            imagealphablending($imagen, true);
            imagesavealpha($imagen, true);
        }
        break;
    case 'image/webp': $imagen = imagecreatefromwebp($ruta_fisica); break;
}

if (!$imagen) {
    responder_censura(false, 'No se pudo cargar la imagen con GD.');
}

$ancho = imagesx($imagen);
$alto  = imagesy($imagen);
$negro = imagecolorallocate($imagen, 0, 0, 0);

// Dibujar rectángulos negros sólidos, coordenadas en píxeles reales
foreach ($rects as $r) {
    $x1 = (int)round((float)$r['x_pct'] * $ancho);
    $y1 = (int)round((float)$r['y_pct'] * $alto);
    $x2 = (int)round(((float)$r['x_pct'] + (float)$r['w_pct']) * $ancho);
    $y2 = (int)round(((float)$r['y_pct'] + (float)$r['h_pct']) * $alto);
    $x1 = max(0, min($x1, $ancho - 1));
    $y1 = max(0, min($y1, $alto - 1));
    $x2 = max(0, min($x2, $ancho - 1));
    $y2 = max(0, min($y2, $alto - 1));
    imagefilledrectangle($imagen, $x1, $y1, $x2, $y2, $negro);
}

// Sobrescribir archivo físico (misma ruta, misma URL)
$guardado = false;
switch ($mime) {
    case 'image/jpeg': $guardado = imagejpeg($imagen, $ruta_fisica, 90); break;
    case 'image/png':  $guardado = imagepng($imagen,  $ruta_fisica, 6);  break;
    case 'image/webp': $guardado = imagewebp($imagen, $ruta_fisica, 90); break;
}
imagedestroy($imagen);

if (!$guardado) {
    responder_censura(false, 'No se pudo guardar la imagen censurada.');
}

// Aprobar el mensaje (visible=1)
$stmt_v = $conn->prepare("UPDATE mensajes SET visible = 1 WHERE id = ?");
$stmt_v->bind_param("i", $msg_id);
$stmt_v->execute();
$stmt_v->close();

responder_censura(true);
