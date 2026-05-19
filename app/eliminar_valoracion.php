<?php
/**
 * ACCIÓN: ELIMINAR VALORACIÓN DIRECTA (SOLO ADMIN) - NUBIRA 2.0
 * Borra una reseña fantasma directamente desde la tabla valoraciones.
 */
session_start();
require_once __DIR__ . '/conexion.php';

// Seguridad: Solo Admin
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);

if ($id > 0) {
    $stmt = $conn->prepare("DELETE FROM valoraciones WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Error al borrar de la base de datos']);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'error' => 'ID de valoración inválido']);
}
?>