<?php
/**
 * ENDPOINT: APAGAR NOTIFICACIÓN DE SUGERENCIA
 * TIPO: Async (Fire-and-Forget)
 * ESTADO: Nubira 2.0 (Seguro y Rápido)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexion.php'; // Asegúrate de que esta ruta a conexion.php sea correcta

header('Content-Type: application/json');

// 1. Verificación de Seguridad Rápida (Solo usuarios logueados)
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

// Opcional: Si usas tokens CSRF en Nubira, puedes descomentar esto y asegurarte de enviarlo en el fetch del JS
// if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
//     echo json_encode(['success' => false, 'error' => 'Token inválido']);
//     exit;
// }

$uid = (int)$_SESSION['usuario_id'];

// 2. Ejecución SQL Blindada
$stmt = $conn->prepare("UPDATE alumnos SET notif_sugerencia_vista = 1 WHERE id = ?");
if ($stmt) {
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    
    // Si afectó una fila o si ya estaba en 1 (no hubo cambios reales pero la query fue exitosa)
    if ($stmt->affected_rows > 0 || $stmt->errno === 0) {
        // 3. Sincronizar Caché de Memoria
        $_SESSION['notif_sugerencia_vista'] = 1;
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No se actualizó']);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'error' => 'Error de BD']);
}