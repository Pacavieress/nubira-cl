<?php
/**
 * BACKEND: FINALIZAR SERVICIO (VENDEDOR/TUTOR)
 * Acción: Marca el check del vendedor y cierra el contrato globalmente.
 */
session_start();
require_once __DIR__ . '/conexion.php';

// 1. SEGURIDAD BÁSICA
if (!isset($_SESSION['usuario_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /login"); 
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];
$contrato_id = (int)$_POST['contrato_id'];
$es_admin = ($_SESSION['rol'] ?? '') === 'admin';

if ($contrato_id <= 0) {
    header("Location: /dashboard");
    exit;
}

// 2. VERIFICACIÓN DEL CONTRATO
$stmt = $conn->prepare("SELECT id, estado, vendedor_id, finalizado_comprador FROM contratos WHERE id = ?");
$stmt->bind_param("i", $contrato_id);
$stmt->execute();
$contrato = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contrato) {
    die("Contrato no encontrado.");
}

// 3. SEGURIDAD ESTRICTA: Solo el vendedor real o Admin pueden ejecutar esto
if ($contrato['vendedor_id'] != $usuario_id && !$es_admin) {
    die("No tienes permiso para confirmar este contrato.");
}

// Validar que el comprador ya haya liberado su parte (si no es admin forzando)
if (empty($contrato['finalizado_comprador']) && !$es_admin) {
    die("Debes esperar a que el alumno libere el pago primero.");
}

// 4. EJECUCIÓN: Marcar como finalizado por el vendedor
// [NUBIRA 2.0] Estado unificado a 'liberado'
$stmt_up = $conn->prepare("UPDATE contratos SET estado = 'liberado', finalizado_vendedor = 1 WHERE id = ?");
$stmt_up->bind_param("i", $contrato_id);

if ($stmt_up->execute()) {
    // ÉXITO: Flujo Nubira 2.0 -> Lo forzamos a ir a evaluar al alumno
    header("Location: /app/evaluar_servicio.php?id=" . $contrato_id);
} else {
    die("Error al actualizar la base de datos: " . $stmt_up->error);
}

$stmt_up->close();
exit;
?>