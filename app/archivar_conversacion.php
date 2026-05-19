<?php
session_start();
require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['usuario_id'])) {
  http_response_code(403);
  exit('No autorizado.');
}

$usuario_id = (int)$_SESSION['usuario_id'];
$id_servicio = (int)($_POST['id_servicio'] ?? 0);
$id_preguntador = (int)($_POST['id_preguntador'] ?? 0);

if ($id_servicio <= 0 || $id_preguntador <= 0) {
  http_response_code(400);
  exit('Datos inválidos.');
}

/* Validar que el servicio pertenece al usuario actual */
$check = $conn->prepare("SELECT 1 FROM servicios WHERE id = ? AND alumno_id = ?");
$check->bind_param("ii", $id_servicio, $usuario_id);
$check->execute();
$check->store_result();

if ($check->num_rows === 0) {
  http_response_code(403);
  exit('No autorizado para modificar esta conversación.');
}
$check->close();

/* Marcar como archivado */
$stmt = $conn->prepare("
  UPDATE preguntas_servicios 
  SET archivado = 1 
  WHERE id_servicio = ? AND id_preguntador = ?
");
$stmt->bind_param("ii", $id_servicio, $id_preguntador);
$stmt->execute();
$stmt->close();

header("Location: /mis-preguntas?ok=1");
exit;
?>
