<?php
session_start();
require_once __DIR__ . '/conexion.php';

// ID del servicio que viene por GET
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit(json_encode([]));
}

// Buscar todas las preguntas y respuestas del servicio
$stmt = $conn->prepare("
  SELECT 
    p.id AS id_pregunta,
    p.pregunta,
    p.respuesta,
    u.nombre AS nombre_preguntador
  FROM preguntas_servicios p
  JOIN alumnos u ON u.id = p.id_preguntador
  WHERE p.id_servicio = ?
  ORDER BY p.fecha_pregunta ASC
");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();

header('Content-Type: application/json; charset=utf-8');
echo json_encode($res->fetch_all(MYSQLI_ASSOC));
