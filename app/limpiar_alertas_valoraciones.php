<?php
/** API: LIMPIAR ALERTAS VALORACIONES (NUBIRA 2.0) */
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'No session']);
    exit;
}

require_once __DIR__ . '/conexion.php';
$uid = (int)$_SESSION['usuario_id'];

if ($uid > 0) {
    // Blindaje con sentencias preparadas
    $stmt = $conn->prepare("UPDATE valoraciones SET revisado = 1 WHERE vendedor_id = ? AND revisado = 0");
    if ($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
    }
}
echo json_encode(['status' => 'success']);
?>