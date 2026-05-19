<?php
header('Content-Type: application/json');
require_once 'conexion.php';
session_start();

function respuesta($ok, $extra = []) {
    echo json_encode(array_merge(['success' => $ok], $extra));
    exit;
}

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    http_response_code(401);
    respuesta(false, ['error' => 'No autorizado']);
}

$id = intval($_POST['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    respuesta(false, ['error' => 'ID inválido']);
}

if ($_SESSION['usuario_id'] == $id) {
    http_response_code(400);
    respuesta(false, ['error' => 'No puedes eliminar tu propia cuenta']);
}

if ($id === 1) {
    http_response_code(400);
    respuesta(false, ['error' => 'No puedes eliminar el usuario administrador principal']);
}

$conn->begin_transaction();

try {
    // Borra todo lo relacionado en orden
    $tablas = [
        ['tabla' => 'accesos_denegados', 'campo' => 'usuario_id'],
        ['tabla' => 'compras', 'campo' => 'usuario_id'],
        ['tabla' => 'datos_pago_usuario', 'campo' => 'usuario_id'],
        ['tabla' => 'emprendimientos', 'campo' => 'alumno_id'],
        ['tabla' => 'favoritos_oportunidades', 'campo' => 'usuario_id'],
        ['tabla' => 'oportunidades', 'campo' => 'usuario_id'],
        ['tabla' => 'reclamos_sugerencias', 'campo' => 'usuario_id'],
        ['tabla' => 'servicios', 'campo' => 'alumno_id'],
        ['tabla' => 'solicitudes_retiro', 'campo' => 'usuario_id'],
        ['tabla' => 'soporte', 'campo' => 'usuario_id']
    ];

    foreach ($tablas as $t) {
        $stmt = $conn->prepare("DELETE FROM {$t['tabla']} WHERE {$t['campo']} = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    // Finalmente, borra el usuario
    $stmt = $conn->prepare("DELETE FROM alumnos WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        $conn->commit();
        $conn->close();
        respuesta(true, ['msg' => 'Usuario y todos sus datos asociados eliminados.']);
    } else {
        $conn->rollback();
        $conn->close();
        respuesta(false, ['error' => 'No se pudo eliminar el usuario (no existe o error desconocido)']);
    }
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    respuesta(false, ['error' => 'Error al eliminar: ' . $e->getMessage()]);
}
?>
