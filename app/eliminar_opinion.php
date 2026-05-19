<?php
/**
 * ACCIÓN: ELIMINAR OPINIÓN (SOLO ADMIN) - NUBIRA 2.0
 * Destruye la reseña en cascada: limpia el contrato y borra la valoración del perfil.
 */
session_start();
require_once __DIR__ . '/conexion.php';

// 1. Seguridad: Solo Admin
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// 2. Recepción de Datos
$id   = (int)($_POST['id'] ?? 0);
$tipo = $_POST['tipo'] ?? ''; // 'contrato' o 'antiguo'

if ($id <= 0 || empty($tipo)) {
    echo json_encode(['error' => 'Datos inválidos']);
    exit;
}

try {
    if ($tipo === 'contrato') {
        
        // PASO A: Buscar quiénes son los involucrados en este contrato
        $stmt_info = $conn->prepare("SELECT comprador_id, vendedor_id FROM contratos WHERE id = ?");
        $stmt_info->bind_param("i", $id);
        $stmt_info->execute();
        $contrato = $stmt_info->get_result()->fetch_assoc();
        $stmt_info->close();

        if ($contrato) {
            // PASO B: Borrar la réplica exacta en la tabla 'valoraciones' (La que sale en el perfil)
            // Borramos la que el comprador le dejó al vendedor
            $stmt_val = $conn->prepare("DELETE FROM valoraciones WHERE id_evaluador = ? AND id_evaluado = ?");
            $stmt_val->bind_param("ii", $contrato['comprador_id'], $contrato['vendedor_id']);
            $stmt_val->execute();
            $stmt_val->close();
        }

        // PASO C: Limpiar el contrato (La que sale en el detalle del servicio)
        $stmt = $conn->prepare("UPDATE contratos SET calificacion_comprador = 0, comentario_comprador = NULL WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

    } elseif ($tipo === 'antiguo') {
        // En el sistema antiguo, borramos el registro directo
        $stmt = $conn->prepare("DELETE FROM servicio_comentarios WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error en base de datos al eliminar en cascada']);
}
?>