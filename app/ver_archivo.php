<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo "⛔ Acceso no autorizado.";
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$institucion = $_SESSION['institucion'] ?? null;
$archivo = $_GET['archivo'] ?? '';
$nombre_archivo = basename($archivo);

// Verificar si el apunte pertenece a la misma institución
$stmt = $conn->prepare("SELECT id FROM apuntes WHERE archivo = ? AND institucion = ?");
$stmt->bind_param("ss", $nombre_archivo, $institucion);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(403);
    echo "⛔ No tienes permiso para ver este archivo.";
    exit;
}

$ruta = __DIR__ . '/../public/uploads/preview/' . $nombre_archivo;
if (file_exists($ruta)) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $nombre_archivo . '"');
    readfile($ruta);
    exit;
} else {
    http_response_code(404);
    echo "❌ Archivo no encontrado.";
}
?>
