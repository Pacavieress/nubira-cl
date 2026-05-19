<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
ini_set('display_errors', 0);
session_start();

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['total' => 0]);
    exit;
}

require_once __DIR__ . '/conexion.php';
$my_id = (int)$_SESSION['usuario_id'];

try {
    // Versión robusta que considera visibilidad y eliminación
    $sql = "SELECT COUNT(m.id) as total 
            FROM mensajes m 
            INNER JOIN conversaciones c ON m.conversacion_id = c.id 
            WHERE (m.leido = 0 OR m.leido IS NULL) 
            AND m.remitente_id != ? 
            AND c.eliminado = 0
            AND (
                (c.comprador_id = ? AND (c.visible_comprador = 1 OR c.visible_comprador IS NULL))
                OR
                (c.vendedor_id = ? AND (c.visible_vendedor = 1 OR c.visible_vendedor IS NULL))
            )";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $my_id, $my_id, $my_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    
    echo json_encode(['total' => (int)($row['total'] ?? 0)]);
    $stmt->close();

} catch (Exception $e) {
    echo json_encode(['total' => 0]);
}