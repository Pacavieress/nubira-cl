<?php
/**
 * BACKEND: FINALIZAR SERVICIO (Estilo Nubira 2.0)
 * Acción: Cambia estado a 'finalizado', marca check del comprador y redirige a evaluación.
 */
session_start();
require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['usuario_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /login"); 
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];
$contrato_id = (int)$_POST['contrato_id'];
$es_admin = ($_SESSION['rol'] ?? '') === 'admin';

// 1. Verificamos el contrato de forma más abierta para permitir el Modo QA del Admin
$stmt = $conn->prepare("SELECT id, estado, comprador_id FROM contratos WHERE id = ?");
$stmt->bind_param("i", $contrato_id);
$stmt->execute();
$contrato = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contrato) {
    die("Contrato no encontrado.");
}

// 2. Seguridad estricta: Solo el comprador real o el Admin pueden ejecutar esto
if ($contrato['comprador_id'] != $usuario_id && !$es_admin) {
    die("No tienes permiso para finalizar este contrato.");
}

// 3. Ejecución principal
if (in_array($contrato['estado'], ['activo', 'en_progreso'])) {
    
    // Actualizar estado a 'finalizado' y marcar check
// [NUBIRA 2.0] Estado unificado a 'liberado'
$stmt = $conn->prepare("UPDATE contratos SET estado = 'liberado', finalizado_comprador = 1 WHERE id = ?");
    $stmt->bind_param("i", $contrato_id);
    
    if ($stmt->execute()) {
        // ÉXITO: Flujo Nubira 2.0 -> Lo forzamos a ir a evaluar al tutor, rompiendo la sensación de "no pasó nada"
        header("Location: /app/evaluar_servicio.php?id=" . $contrato_id);
    } else {
        die("Error al actualizar: " . $stmt->error);
    }
    $stmt->close();

} else {
    // Si recargó por error y ya estaba finalizado, lo empujamos igual a la evaluación
    header("Location: /app/evaluar_servicio.php?id=" . $contrato_id);
}
exit;
?>