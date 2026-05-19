<?php
// /app/apagar_notif_admin_reclamos.php
session_start();
require_once __DIR__ . '/conexion.php';

// Verificamos que al menos haya un usuario logueado (puedes ajustar tu seguridad admin real después)
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No hay sesión de usuario activa']);
    exit;
}

// Ejecutamos la orden
$sql = "UPDATE reclamos_sugerencias SET notificado_admin = 1 WHERE notificado_admin = 0";

if ($stmt = $conn->prepare($sql)) {
    $stmt->execute();
    $filas_afectadas = $stmt->affected_rows;
    $stmt->close();
    
    echo json_encode(['success' => true, 'filas_apagadas' => $filas_afectadas]);
} else {
    echo json_encode(['success' => false, 'error' => 'Error SQL: ' . $conn->error]);
}
?>