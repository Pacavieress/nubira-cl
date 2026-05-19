<?php
session_start();
require_once __DIR__ . '/conexion.php';

$id   = intval($_GET['id'] ?? 0);
$user = $_SESSION['usuario_id'] ?? 0;
header('Content-Type: application/json');

if ($id <= 0 || !$user) {
    echo json_encode(['ok'=>false,'error'=>'No autorizado']);
    exit;
}

// 1) Intentamos insertar registro de visita hoy
$stmt = $conn->prepare("
  INSERT IGNORE INTO servicio_visitas (servicio_id, user_id)
  VALUES (?, ?)
");
$stmt->bind_param("ii", $id, $user);
$stmt->execute();

// 2) Si no se insertó (ya existía), salimos sin sumar
if ($stmt->affected_rows === 0) {
    echo json_encode(['ok'=>false,'error'=>'Visita ya contada hoy']);
    exit;
}
$stmt->close();

// 3) Sumar en la tabla principal
$stmt2 = $conn->prepare("
  UPDATE servicios 
     SET visitas = visitas + 1 
   WHERE id = ?
");
$stmt2->bind_param("i", $id);
$stmt2->execute();
$stmt2->close();

echo json_encode(['ok'=>true]);
