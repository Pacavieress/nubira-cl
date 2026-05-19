<?php
session_start();
require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$id_alumno = (int)$_SESSION['usuario_id'];
$hoy = date('Y-m-d');

$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM servicios 
    WHERE alumno_id = ? 
    AND DATE(fecha_publicacion) = ?
");
$stmt->bind_param("is", $id_alumno, $hoy);
$stmt->execute();
$stmt->bind_result($publicaciones_hoy);
$stmt->fetch();
$stmt->close();

echo json_encode(['publicaciones_hoy' => (int)$publicaciones_hoy]);
