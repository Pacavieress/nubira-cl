<?php
session_start();
require_once __DIR__ . '/conexion.php';

// Respuesta siempre en JSON
header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Validar sesión
    if (!isset($_SESSION['usuario_id'])) {
        throw new Exception('No autenticado');
    }

    $usuario_id = (int)$_SESSION['usuario_id'];
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        throw new Exception('ID inválido');
    }

    // 2. Obtener dueños del chat
    $stmt = $conn->prepare("SELECT comprador_id, vendedor_id FROM conversaciones WHERE id = ?");
    if (!$stmt) throw new Exception('Error en consulta SQL');
    
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($comprador_id, $vendedor_id);
    
    if (!$stmt->fetch()) {
        $stmt->close();
        throw new Exception('El chat no existe');
    }
    $stmt->close();

    // 3. Determinar qué columna ocultar según quién sea el usuario
    $columna_a_ocultar = '';

    if ($usuario_id === $comprador_id) {
        $columna_a_ocultar = 'visible_comprador';
    } elseif ($usuario_id === $vendedor_id) {
        $columna_a_ocultar = 'visible_vendedor';
    } else {
        throw new Exception('No tienes permiso para eliminar este chat');
    }

    // 4. Aplicar "Soft Delete" (Ocultar)
    $update = $conn->prepare("UPDATE conversaciones SET $columna_a_ocultar = 0 WHERE id = ?");
    $update->bind_param("i", $id);
    $update->execute();
    $update->close();

    // 5. Limpieza (Opcional): Si AMBOS lo borraron (ambos son 0), borrar físicamente
    // Esto ahorra espacio en la base de datos a largo plazo
    $check = $conn->query("SELECT visible_comprador, visible_vendedor FROM conversaciones WHERE id = $id");
    if ($check) {
        $row = $check->fetch_assoc();
        // Si ambos están en 0 (false)
        if ($row['visible_comprador'] == 0 && $row['visible_vendedor'] == 0) {
            $conn->query("DELETE FROM mensajes WHERE conversacion_id = $id");
            $conn->query("DELETE FROM conversaciones WHERE id = $id");
        }
    }

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
?>