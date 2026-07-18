<?php
/**
 * BACKEND: guarda horarios_json de un servicio vía AJAX (JSON in/out).
 * Se usa desde publicar_servicio.php inmediatamente después de crear el servicio.
 */
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/helpers/horarios.php';

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sesión expirada.']);
    exit;
}

$usuario_id    = (int)$_SESSION['usuario_id'];
$servicio_id   = (int)($_POST['servicio_id'] ?? 0);
$horarios_json = trim($_POST['horarios_json'] ?? '');

if ($servicio_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID de servicio inválido.']);
    exit;
}

$stmt = $conn->prepare("SELECT alumno_id FROM servicios WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $servicio_id);
$stmt->execute();
$servicio = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$servicio || (int)$servicio['alumno_id'] !== $usuario_id) {
    echo json_encode(['success' => false, 'error' => 'No tienes permiso sobre este servicio.']);
    exit;
}

$error_validacion = validar_horarios_json($horarios_json);
if ($error_validacion !== null) {
    echo json_encode(['success' => false, 'error' => $error_validacion]);
    exit;
}

if (!parsear_horarios_servicio($horarios_json)['tiene_horarios']) {
    echo json_encode(['success' => false, 'error' => 'Debes marcar al menos un bloque de disponibilidad.']);
    exit;
}

$upd = $conn->prepare("UPDATE servicios SET horarios_json = ? WHERE id = ? AND alumno_id = ?");
$upd->bind_param("sii", $horarios_json, $servicio_id, $usuario_id);
$ok = $upd->execute();
$upd->close();

echo json_encode(['success' => $ok]);
