<?php
require_once '../app/conexion.php';
header('Content-Type: application/json');

// Cuenta los servicios pendientes
$q = $conn->query("SELECT COUNT(*) AS total FROM servicios WHERE estado = 'pendiente'");
$row = $q->fetch_assoc();

echo json_encode(['total' => (int)$row['total']]);
?>
