<?php
/**
 * SCRIPT: VISOR DE APUNTES SEGURO (NUBIRA 2.0)
 * PROTECCIÓN: Bloquea descargas no autorizadas y sirve el archivo optimizado.
 */
session_start();

// 1. Buscador de conexión robusto (Path Finder)
$base_path = __DIR__;
if (!file_exists($base_path . '/conexion.php')) $base_path = dirname(__DIR__) . '/app';
if (!file_exists($base_path . '/conexion.php')) $base_path = dirname(__DIR__);
require_once $base_path . '/conexion.php';

$id_apunte = (int)($_GET['id'] ?? 0);
if ($id_apunte <= 0) { http_response_code(400); exit('ID inválido'); }

$usuario_id = (int)($_SESSION['usuario_id'] ?? 0);
$rol        = $_SESSION['rol'] ?? 'alumno';

/* Traer apunte */
$stmt = $conn->prepare("SELECT archivo, precio, id_alumno FROM apuntes WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id_apunte);
$stmt->execute();
$ap = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ap) { http_response_code(404); exit('Apunte no encontrado'); }

$archivo  = basename($ap['archivo']);
$precio   = (int)$ap['precio'];
$id_dueno = (int)$ap['id_alumno'];

/* Permisos (Lógica Intacta) */
$acceso = false;
if ($rol === 'admin') $acceso = true;
if ($usuario_id === $id_dueno) $acceso = true;
if ($precio === 0) $acceso = true;

if (!$acceso && $usuario_id > 0) {
    $stmt = $conn->prepare("
        SELECT 1 FROM compras
        WHERE usuario_id = ? AND id_apunte = ? AND estado_pago = 'pagado'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $usuario_id, $id_apunte);
    $stmt->execute();
    $stmt->store_result();
    $acceso = ($stmt->num_rows > 0);
    $stmt->close();
}

if (!$acceso) { http_response_code(403); exit('Acceso denegado'); }

/* Validación Física */
$ruta = $_SERVER['DOCUMENT_ROOT'] . "/upload/apuntes/" . $archivo;
if (!file_exists($ruta)) { http_response_code(404); exit('Archivo físico no encontrado'); }

// 2. Detección Inteligente de MIME Type (Soporte Multi-formato)
$ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';

if ($ext === 'pdf') {
    $mime = 'application/pdf';
} elseif (in_array($ext, ['jpg', 'jpeg'])) {
    $mime = 'image/jpeg';
} elseif ($ext === 'png') {
    $mime = 'image/png';
} elseif ($ext === 'webp') {
    $mime = 'image/webp';
}

// 3. Limpieza y Cabeceras NUBIRA 2.0
if (ob_get_level()) ob_end_clean();

header("Content-Type: " . $mime);
header("Content-Disposition: inline; filename=\"" . htmlspecialchars($archivo) . "\"");
header("Content-Length: " . filesize($ruta)); // Fundamental para barras de carga
header("X-Content-Type-Options: nosniff");

// 4. Anti-Caché (Para que el Admin siempre vea la versión más reciente si el alumno re-subió el archivo)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Servir archivo directamente
readfile($ruta);
exit;