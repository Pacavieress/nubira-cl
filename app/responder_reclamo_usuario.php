<?php
/**
 * API: GUARDAR RESPUESTA DEL USUARIO EN TICKET (NUBIRA 2.0)
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/conexion.php';

// Seguridad estricta
if (!isset($_SESSION['usuario_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Acceso denegado']);
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];
$reclamo_id = isset($_POST['reclamo_id']) ? (int)$_POST['reclamo_id'] : 0;
$mensaje = trim($_POST['mensaje'] ?? '');

if ($reclamo_id === 0 || empty($mensaje)) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

// 1. Verificar que el reclamo le pertenece a este usuario (Seguridad Anti-Hacking)
$stmt = $conn->prepare("SELECT id FROM reclamos_sugerencias WHERE id = ? AND usuario_id = ?");
$stmt->bind_param("ii", $reclamo_id, $usuario_id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Ticket no válido o no autorizado']);
    exit;
}
$stmt->close();

// 2. Guardar el nuevo mensaje en el hilo
$stmt = $conn->prepare("INSERT INTO reclamos_mensajes (reclamo_id, remitente, mensaje, fecha) VALUES (?, 'usuario', ?, NOW())");
$stmt->bind_param("is", $reclamo_id, $mensaje);
if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'error' => 'Error al guardar el mensaje']);
    exit;
}
$stmt->close();

// 3. ACTUALIZACIÓN CRÍTICA: Reabrir el ticket y encender la notificación del Admin
$stmt = $conn->prepare("UPDATE reclamos_sugerencias SET estado = 'pendiente', notificado_admin = 0 WHERE id = ?");
$stmt->bind_param("i", $reclamo_id);
$stmt->execute();
$stmt->close();

// Devolvemos el mensaje formateado para inyectarlo en el HTML al instante
echo json_encode([
    'success' => true,
    'mensaje' => htmlspecialchars($mensaje),
    'fecha' => date('d M Y, H:i')
]);
?>