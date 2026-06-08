<?php
/**
 * BACKEND: ELIMINAR VENTAS - MODO DEBUG ESTRICTO
 * UBICACIÓN: /app/eliminar_ventas.php
 */
session_start();

// 1. ESTO ES CLAVE: Obliga a MySQL a reportar errores de Foreign Key como Excepciones
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sesión expirada']);
    exit;
}

require_once __DIR__ . '/conexion.php';

$data = json_decode(file_get_contents('php://input'), true);
$ids = $data['ids'] ?? [];
$usuario_id = (int)$_SESSION['usuario_id'];

if (empty($ids) || !is_array($ids)) {
    echo json_encode(['success' => false, 'error' => 'No hay datos para eliminar']);
    exit;
}

try {
    $conn->begin_transaction();

    $stmt_check = $conn->prepare("SELECT id FROM contratos WHERE id = ? AND vendedor_id = ?");

    // Si hay otras tablas vinculadas (como mensajes), fallará aquí y el catch atrapará el error exacto
    $stmt_contratos = $conn->prepare("DELETE FROM contratos WHERE id = ?");

    $afectados = 0;

    foreach ($ids as $id_raw) {
        $id = (int)$id_raw;
        if ($id <= 0) continue;

        // Validar dueño
        $stmt_check->bind_param("ii", $id, $usuario_id);
        $stmt_check->execute();
        $res = $stmt_check->get_result();

        if ($res->num_rows > 0) {
            // Eliminar contrato
            $stmt_contratos->bind_param("i", $id);
            $stmt_contratos->execute();

            $afectados += $stmt_contratos->affected_rows;
        }
    }

    $conn->commit();
    echo json_encode(['success' => true, 'afectados' => $afectados]);

} catch (Exception $e) {
    // Si la BD se niega, revertimos todo y mandamos el error exacto de MySQL
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>