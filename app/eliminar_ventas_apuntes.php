<?php
/**
 * BACKEND: ELIMINAR VENTAS DE APUNTES
 * UBICACIÓN: /app/eliminar_ventas_apuntes.php
 */
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sesión expirada']); exit;
}

require_once __DIR__ . '/conexion.php';
$data = json_decode(file_get_contents('php://input'), true);
$ids = $data['ids'] ?? [];
$usuario_id = (int)$_SESSION['usuario_id'];

if (empty($ids) || !is_array($ids)) {
    echo json_encode(['success' => false, 'error' => 'No hay datos']); exit;
}

try {
    $conn->begin_transaction();
    $stmt = $conn->prepare("DELETE FROM ventas_apuntes WHERE id = ? AND vendedor_id = ?");
    $afectados = 0;

    foreach ($ids as $id_raw) {
        $id = (int)$id_raw;
        if ($id > 0) {
            $stmt->bind_param("ii", $id, $usuario_id);
            $stmt->execute();
            $afectados += $stmt->affected_rows;
        }
    }

    $stmt->close();
    $conn->commit();
    echo json_encode(['success' => true, 'afectados' => $afectados]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>