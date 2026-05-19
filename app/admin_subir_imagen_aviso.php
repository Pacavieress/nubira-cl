<?php
/**
 * ENDPOINT: SUBIR IMAGEN TEMPORAL PARA CAMPAÑA DE AVISOS
 * Estrategia: La imagen se sube ANTES de crear la campaña.
 * Se guarda en /upload/avisos/temp/{admin_id}/ y al enviar la campaña
 * se mueve a /upload/avisos/{campana_id}/
 */
require_once __DIR__ . '/init_sesion.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['rol'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Acceso denegado.']);
    exit;
}

$csrf_post = $_POST['csrf_token'] ?? '';
if (empty($csrf_post) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_post)) {
    echo json_encode(['success' => false, 'error' => 'Token inválido.']);
    exit;
}

if (!isset($_FILES['imagen']) || $_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No se recibió ninguna imagen válida.']);
    exit;
}

$admin_id = (int)$_SESSION['usuario_id'];
$file = $_FILES['imagen'];

// 1. Validar peso (máx 3 MB)
$peso_max = 3 * 1024 * 1024;
if ($file['size'] > $peso_max) {
    echo json_encode(['success' => false, 'error' => 'La imagen supera 3 MB.']);
    exit;
}

// 2. Validar MIME real (no confiar en la extensión)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$mimes_validos = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp'
];

if (!isset($mimes_validos[$mime])) {
    echo json_encode(['success' => false, 'error' => 'Formato no permitido. Usa JPG, PNG o WebP.']);
    exit;
}

$extension = $mimes_validos[$mime];

// 3. Crear carpeta temporal por admin si no existe
$carpeta_temp = $_SERVER['DOCUMENT_ROOT'] . '/upload/avisos/temp/' . $admin_id . '/';
if (!is_dir($carpeta_temp)) {
    if (!mkdir($carpeta_temp, 0755, true)) {
        echo json_encode(['success' => false, 'error' => 'No se pudo crear la carpeta temporal.']);
        exit;
    }
}

// 4. Generar nombre único
$nombre_archivo = uniqid('img_', true) . '.' . $extension;
$ruta_destino = $carpeta_temp . $nombre_archivo;

// 5. Mover archivo
if (!move_uploaded_file($file['tmp_name'], $ruta_destino)) {
    echo json_encode(['success' => false, 'error' => 'No se pudo guardar la imagen.']);
    exit;
}

// 6. Responder con la ruta relativa (para preview en el panel)
echo json_encode([
    'success' => true,
    'archivo' => $nombre_archivo,
    'url_preview' => '/upload/avisos/temp/' . $admin_id . '/' . $nombre_archivo
]);
?>