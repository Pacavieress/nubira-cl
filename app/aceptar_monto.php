<?php
session_start();
require_once __DIR__ . '/conexion.php';
header('Content-Type: application/json');

$usuario_id = $_SESSION['usuario_id'] ?? 0;
$id_conversacion = (int)($_POST['id_conversacion'] ?? 0);

if ($usuario_id <= 0 || $id_conversacion <= 0) {
  echo json_encode(['exito'=>false, 'error'=>'Datos inválidos']);
  exit;
}

$stmt = $conn->prepare("SELECT comprador_id, monto_propuesto FROM conversaciones WHERE id=? LIMIT 1");
$stmt->bind_param("i", $id_conversacion);
$stmt->execute();
$c = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$c) {
  echo json_encode(['exito'=>false, 'error'=>'Chat no encontrado']);
  exit;
}

if ((int)$c['comprador_id'] !== (int)$usuario_id) {
  echo json_encode(['exito'=>false, 'error'=>'No autorizado']);
  exit;
}

// 💾 Actualizar como aceptado
$stmt = $conn->prepare("UPDATE conversaciones SET monto_aceptado = monto_propuesto WHERE id=?");
$stmt->bind_param("i", $id_conversacion);
$ok = $stmt->execute();
$stmt->close();

// 💬 Mensaje automático
if ($ok) {
  $texto = "✅ El comprador aceptó el monto propuesto: $" . number_format($c['monto_propuesto'], 0, ',', '.');
  $stmt = $conn->prepare("INSERT INTO mensajes (conversacion_id, remitente_id, mensaje, enviado_en, leido)
                          VALUES (?, ?, ?, NOW(), 0)");
  $stmt->bind_param("iis", $id_conversacion, $usuario_id, $texto);
  $stmt->execute();
  $stmt->close();
}

echo json_encode(['exito'=>$ok]);
