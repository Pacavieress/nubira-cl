<?php
require_once __DIR__ . '/conexion.php';
header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  echo json_encode([]);
  exit;
}

$stmt = $conn->prepare("
  SELECT p.id, p.pregunta, p.respuesta, 
         u.nombre AS nombre_preguntador,
         p.fecha_pregunta, p.fecha_respuesta
  FROM preguntas_servicios p
  JOIN alumnos u ON p.id_preguntador = u.id
  WHERE p.id_servicio = ?
  ORDER BY p.fecha_pregunta ASC
");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) {
  $data[] = [
    'id'        => (int)$row['id'],
    'pregunta'  => htmlspecialchars($row['pregunta']),
    'respuesta' => htmlspecialchars($row['respuesta'] ?? ''),
    'usuario'   => htmlspecialchars($row['nombre_preguntador']),
    'fecha_pregunta'  => $row['fecha_pregunta'],
    'fecha_respuesta' => $row['fecha_respuesta']
  ];
}

echo json_encode($data);
$stmt->close();
$conn->close();
