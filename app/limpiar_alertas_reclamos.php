<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/conexion.php';
$uid = (int)($_SESSION['usuario_id'] ?? 0);

if ($uid > 0) {
    // Actualizamos usando 'respuesta_admin' y 'revisado_usuario'
    $conn->query("UPDATE reclamos_sugerencias SET revisado_usuario = 1 WHERE usuario_id = $uid AND revisado_usuario = 0 AND respuesta_admin IS NOT NULL");
}
echo json_encode(['status' => 'success']);