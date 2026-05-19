<?php
session_start();
require_once __DIR__ . '/conexion.php';

// 🛑 Desactivar compresión automática para evitar corrupción en binarios grandes
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 'Off');

/**
 * 🔒 1. Sanitización estricta
 * Usamos basename() para asegurar que solo lean archivos dentro de la carpeta,
 * sin rutas relativas raras.
 */
$archivo = isset($_GET['archivo']) ? basename($_GET['archivo']) : '';

if (!$archivo || !preg_match('/^[\w\-.]+\.pdf$/i', $archivo)) {
    http_response_code(400); // Bad Request
    exit;
}

$usuario_id = (int)($_SESSION['usuario_id'] ?? 0);
$rol        = $_SESSION['rol'] ?? 'alumno';

/* 🔍 2. Validar Existencia en BD */
$stmt = $conn->prepare("SELECT id, precio, id_alumno FROM apuntes WHERE archivo = ? LIMIT 1");
$stmt->bind_param("s", $archivo);
$stmt->execute();
$ap = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ap) {
    http_response_code(404);
    exit;
}

$id_apunte = (int)$ap['id'];
$precio    = (int)$ap['precio'];
$id_dueno  = (int)$ap['id_alumno'];

/* 🔐 3. Validar Permisos (Lógica de Negocio) */
$es_dueno  = ($usuario_id === $id_dueno);
$es_admin  = ($rol === 'admin');
$es_gratis = ($precio === 0);

$acceso = $es_dueno || $es_admin || $es_gratis;

if (!$acceso && $usuario_id > 0) {
    // Verificar si compró el apunte
    $stmt2 = $conn->prepare("
        SELECT 1 FROM compras 
        WHERE usuario_id = ? AND id_apunte = ? 
        AND estado_pago IN ('pagado','approved','paid') 
        LIMIT 1
    ");
    $stmt2->bind_param("ii", $usuario_id, $id_apunte);
    $stmt2->execute();
    $stmt2->store_result();
    $acceso = ($stmt2->num_rows > 0);
    $stmt2->close();
}

if (!$acceso) {
    http_response_code(403); // Forbidden
    exit;
}

/* 📂 4. Servir el Archivo Físico */
$ruta = $_SERVER['DOCUMENT_ROOT'] . "/upload/apuntes/" . $archivo;

if (!file_exists($ruta)) {
    http_response_code(404);
    exit;
}

// Datos para caché
$fsize = filesize($ruta);
$mtime = filemtime($ruta);
$etag  = md5($ruta . $mtime);

// 🚀 CACHÉ INTELIGENTE (Si el navegador ya lo tiene, no lo enviamos de nuevo)
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    header("HTTP/1.1 304 Not Modified");
    exit;
}

// Cabeceras para PDF
header("Content-Type: application/pdf");
header("Content-Disposition: inline; filename=\"apunte-nubira.pdf\""); // 'inline' permite verlo en el navegador
header("Content-Length: " . $fsize);
header("Last-Modified: " . gmdate("D, d M Y H:i:s", $mtime) . " GMT");
header("ETag: " . $etag);
header("Cache-Control: private, max-age=3600"); // Caché privada por 1 hora
header("X-Content-Type-Options: nosniff"); // Seguridad
header("Accept-Ranges: bytes");

// Limpiar buffers de salida para evitar que se mezclen espacios en blanco con el PDF
if (ob_get_level()) ob_end_clean();

readfile($ruta);
exit;