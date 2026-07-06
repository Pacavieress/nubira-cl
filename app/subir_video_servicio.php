<?php
/**
 * ENDPOINT: Subir video de presentación de servicio
 * POST /subir-video-servicio
 * Responde JSON: {"ok": true, "video_path": "..."} | {"ok": false, "error": "..."}
 */

@set_time_limit(120);
@ini_set('max_execution_time', '120');

header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();

// ── Auth ─────────────────────────────────────────────────────────
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión expirada. Recarga la página.']);
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];

require_once __DIR__ . '/conexion.php';

// ── Auto-migración: columna video_thumb_path (miniatura del video) ──
try {
    $chk_thumb_col = $conn->query("SHOW COLUMNS FROM servicios LIKE 'video_thumb_path'");
    if ($chk_thumb_col && $chk_thumb_col->num_rows === 0) {
        $conn->query("ALTER TABLE servicios ADD COLUMN video_thumb_path VARCHAR(255) NULL DEFAULT NULL");
    }
} catch (Exception $e) {
    // Migración opcional — continúa si falla
}

// ── CSRF ─────────────────────────────────────────────────────────
$token_recibido = $_POST['csrf_token'] ?? '';
$token_sesion   = $_SESSION['csrf_token_editar'] ?? '';
if (empty($token_recibido) || !hash_equals($token_sesion, $token_recibido)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token de seguridad inválido. Recarga la página.']);
    exit;
}

// ── Consentimiento RRSS obligatorio ──────────────────────────────
if (($_POST['consentimiento_rrss'] ?? '') !== '1') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Debes aceptar el consentimiento de redes sociales.']);
    exit;
}

// ── Servicio: existe y pertenece al usuario ───────────────────────
$id_servicio = (int)($_POST['servicio_id'] ?? 0);
if ($id_servicio <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Servicio no especificado.']);
    exit;
}

$stmt_svc = $conn->prepare(
    "SELECT id, video_path, video_thumb_path FROM servicios WHERE id = ? AND alumno_id = ? LIMIT 1"
);
$stmt_svc->bind_param("ii", $id_servicio, $usuario_id);
$stmt_svc->execute();
$stmt_svc->bind_result($svc_id, $old_video_path, $old_video_thumb_path);
$found = $stmt_svc->fetch();
$stmt_svc->close();

if (!$found) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No tienes permiso para editar este servicio.']);
    exit;
}

// ── Archivo recibido ─────────────────────────────────────────────
if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
    $upload_errors = [
        UPLOAD_ERR_INI_SIZE  => 'El archivo supera el límite del servidor.',
        UPLOAD_ERR_FORM_SIZE => 'El archivo supera el límite del formulario.',
        UPLOAD_ERR_PARTIAL   => 'El archivo se subió parcialmente. Intenta de nuevo.',
        UPLOAD_ERR_NO_FILE   => 'No se recibió ningún archivo.',
    ];
    $code = $_FILES['video']['error'] ?? UPLOAD_ERR_NO_FILE;
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $upload_errors[$code] ?? 'Error al recibir el archivo.']);
    exit;
}

// ── Tamaño ───────────────────────────────────────────────────────
if ($_FILES['video']['size'] > 30 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El video supera 30 MB.']);
    exit;
}

// ── Extensión: whitelist ──────────────────────────────────────────
$ext_original    = strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
$exts_permitidas = ['mp4', 'webm', 'mov'];
if (!in_array($ext_original, $exts_permitidas, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Extensión no permitida. Usa .mp4, .webm o .mov.']);
    exit;
}

// ── MIME real con finfo ───────────────────────────────────────────
$mimes_permitidos = ['video/mp4', 'video/webm', 'video/quicktime'];
$finfo     = finfo_open(FILEINFO_MIME_TYPE);
$mime_real = finfo_file($finfo, $_FILES['video']['tmp_name']);
finfo_close($finfo);

if (!in_array($mime_real, $mimes_permitidos, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El contenido del archivo no es un video válido.']);
    exit;
}

// ── Preparar destino ─────────────────────────────────────────────
$dir_videos = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/upload/videos_servicios/';
if (!is_dir($dir_videos)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Directorio de videos no disponible.']);
    exit;
}

$nuevo_nombre = hash('sha256', $id_servicio . bin2hex(random_bytes(16)) . time()) . '.' . $ext_original;
$ruta_destino = $dir_videos . $nuevo_nombre;

// ── Mover archivo ────────────────────────────────────────────────
if (!move_uploaded_file($_FILES['video']['tmp_name'], $ruta_destino)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al guardar el video. Intenta de nuevo.']);
    exit;
}
@chmod($ruta_destino, 0644);

// ── Thumbnail opcional (miniatura capturada en el navegador) ─────
// Best-effort: cualquier fallo deja $thumb_guardado en null sin
// interrumpir la subida del video.
$thumb_guardado = null;
if (isset($_FILES['thumb']) && $_FILES['thumb']['error'] === UPLOAD_ERR_OK) {
    $thumb_ok = ($_FILES['thumb']['size'] <= 2 * 1024 * 1024);

    if ($thumb_ok) {
        $finfo_thumb = finfo_open(FILEINFO_MIME_TYPE);
        $mime_thumb  = finfo_file($finfo_thumb, $_FILES['thumb']['tmp_name']);
        finfo_close($finfo_thumb);
        $thumb_ok = ($mime_thumb === 'image/jpeg');
    }

    if ($thumb_ok) {
        $dir_servicios = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/upload/servicios/';
        $nombre_thumb  = pathinfo($nuevo_nombre, PATHINFO_FILENAME) . '_thumb.jpg';
        $ruta_thumb    = $dir_servicios . $nombre_thumb;

        if (is_dir($dir_servicios) && move_uploaded_file($_FILES['thumb']['tmp_name'], $ruta_thumb)) {
            @chmod($ruta_thumb, 0644);
            $thumb_guardado = $nombre_thumb;
        }
    }
}

// ── Borrar video anterior (solo tras mover el nuevo con éxito) ────
if (!empty($old_video_path)) {
    $ruta_vieja = $dir_videos . $old_video_path;
    if (is_file($ruta_vieja)) @unlink($ruta_vieja);
}
if (!empty($old_video_thumb_path)) {
    $ruta_thumb_vieja = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/upload/servicios/' . $old_video_thumb_path;
    if (is_file($ruta_thumb_vieja)) @unlink($ruta_thumb_vieja);
}

// ── UPDATE BD ────────────────────────────────────────────────────
$stmt_upd = $conn->prepare(
    "UPDATE servicios
        SET video_path                = ?,
            video_thumb_path          = ?,
            video_estado              = 'pendiente',
            video_subido_en           = NOW(),
            video_consentimiento_rrss   = 1,
            video_consentimiento_fecha  = NOW(),
            video_motivo_rechazo      = NULL
      WHERE id = ? AND alumno_id = ?"
);
$stmt_upd->bind_param("ssii", $nuevo_nombre, $thumb_guardado, $id_servicio, $usuario_id);

if (!$stmt_upd->execute()) {
    @unlink($ruta_destino); // rollback: borrar archivo si BD falla
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al registrar el video. Intenta de nuevo.']);
    exit;
}
$stmt_upd->close();

// ── Push al admin ────────────────────────────────────────────────
require_once __DIR__ . '/enviar_push_nubira.php';
$nombre_tutor = explode(' ', trim($_SESSION['usuario_nombre'] ?? 'Un tutor'))[0];
enviar_push_nubira(1, '🎬 Video pendiente', $nombre_tutor . ' subió un video de presentación. Revisar.', '/admin/videos');

// ── Éxito ────────────────────────────────────────────────────────
echo json_encode(['ok' => true, 'video_path' => $nuevo_nombre]);
