<?php
/** API: LIMPIAR ALERTAS SOPORTE */
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/conexion.php';
$uid = (int)($_SESSION['usuario_id'] ?? 0);

if ($uid > 0) {
    $conn->query("UPDATE soporte SET revisado_usuario = 1 WHERE usuario_id = $uid AND revisado_usuario = 0 AND respuesta IS NOT NULL");
}
echo json_encode(['status' => 'success']);