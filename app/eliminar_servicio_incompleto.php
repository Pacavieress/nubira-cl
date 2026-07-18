<?php
/**
 * BACKEND: rollback — elimina un servicio recién creado si el guardado del
 * horario obligatorio falló justo después de publicar_servicio.php.
 */
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sesión expirada.']);
    exit;
}

$usuario_id  = (int)$_SESSION['usuario_id'];
$servicio_id = (int)($_POST['servicio_id'] ?? 0);

if ($servicio_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID inválido.']);
    exit;
}

$stmt = $conn->prepare("
    DELETE FROM servicios
    WHERE id = ?
      AND alumno_id = ?
      AND estado = 'pendiente'
      AND (horarios_json IS NULL OR horarios_json = '')
      AND fecha_publicacion >= (NOW() - INTERVAL 10 MINUTE)
");
$stmt->bind_param("ii", $servicio_id, $usuario_id);
$ok = $stmt->execute();
$borrado = $ok && $stmt->affected_rows > 0;
$stmt->close();

echo json_encode(['success' => $borrado]);
