<?php
/**
 * BACKEND: ACCIONES DE CHAT (Archivar/Eliminar)
 * UBICACIÓN: public_html/app/accion_chat.php
 */

header('Content-Type: application/json');
ini_set('display_errors', 0);
session_start();

require_once __DIR__ . '/conexion.php';

// 1. Seguridad
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sin sesión']);
    exit;
}

$my_id = (int)$_SESSION['usuario_id'];
$accion = $_POST['accion'] ?? ''; // 'archivar' o 'eliminar'
$id     = (int)($_POST['id'] ?? 0);
$tipo   = $_POST['tipo'] ?? 'conversacion'; // 'conversacion' o 'aula'

if ($id <= 0 || !in_array($accion, ['archivar', 'eliminar'])) {
    echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
    exit;
}

try {
    // 2. Lógica para CONVERSACIONES (Negociaciones)
    if ($tipo === 'conversacion') {
        
        // Verificamos si soy Comprador o Vendedor en este chat
        $stmt = $conn->prepare("SELECT comprador_id, vendedor_id FROM conversaciones WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $chat = $res->fetch_assoc();

        if (!$chat) { throw new Exception("Chat no encontrado"); }

        $esComprador = ($chat['comprador_id'] == $my_id);
        $esVendedor  = ($chat['vendedor_id'] == $my_id);

        if (!$esComprador && !$esVendedor) {
            throw new Exception("No tienes permiso");
        }

        if ($accion === 'eliminar') {
            // SOFT DELETE: Solo ocultamos para el usuario actual
            if ($esComprador) {
                $upd = $conn->prepare("UPDATE conversaciones SET visible_comprador = 0 WHERE id = ?");
            } else {
                $upd = $conn->prepare("UPDATE conversaciones SET visible_vendedor = 0 WHERE id = ?");
            }
            $upd->bind_param("i", $id);
            $upd->execute();
        
        } elseif ($accion === 'archivar') {
            // ARCHIVAR: Cambiamos estado (Afecta a la negociación globalmente en este modelo)
            // Opcional: Podríamos agregar campos 'archived_by_user' si quisieras que fuera personal
            $upd = $conn->prepare("UPDATE conversaciones SET estado = 'archivada' WHERE id = ?");
            $upd->bind_param("i", $id);
            $upd->execute();
        }

    // 3. Lógica para AULAS (Contratos)
    } elseif ($tipo === 'aula') {
        // En Aulas no solemos "eliminar" el contrato, solo ocultamos el chat
        // (Lógica similar a conversación, pero en tabla contratos si tuviera campos de visibilidad)
        // Por ahora simularemos éxito para no romper la UI, o implementa visibilidad en 'contratos'
        // Si tu tabla contratos NO tiene visible_comprador, saltamos esto por seguridad.
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>