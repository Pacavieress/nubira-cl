<?php
session_start();
require_once __DIR__ . '/conexion.php';
header('Content-Type: application/json');

// ⚙️ Validaciones básicas
$usuario_id = $_SESSION['usuario_id'] ?? 0;
$id_conversacion = (int)($_POST['id_conversacion'] ?? 0);
$monto = (int)($_POST['monto'] ?? 0);

if ($usuario_id <= 0 || $id_conversacion <= 0 || $monto < 1000) {
  echo json_encode(['exito' => false, 'error' => 'Datos inválidos']);
  exit;
}

// 🔍 Buscar la conversación y confirmar que el usuario es el vendedor
$stmt = $conn->prepare("
  SELECT vendedor_id, servicio_id 
  FROM conversaciones 
  WHERE id = ? LIMIT 1
");
$stmt->bind_param("i", $id_conversacion);
$stmt->execute();
$chat = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$chat) {
  echo json_encode(['exito' => false, 'error' => 'Chat no encontrado']);
  exit;
}

if ((int)$chat['vendedor_id'] !== (int)$usuario_id) {
  echo json_encode(['exito' => false, 'error' => 'No autorizado']);
  exit;
}

// 💾 Guardar la propuesta en la tabla conversaciones (puedes cambiar si prefieres guardarlo en otra)
$stmt = $conn->prepare("
  UPDATE conversaciones 
  SET monto_propuesto = ?, fecha_monto = NOW()
  WHERE id = ?
");
$stmt->bind_param("ii", $monto, $id_conversacion);
$ok = $stmt->execute();
$stmt->close();

// 💬 Enviar mensaje automático al chat si se guardó correctamente
if ($ok) {
  $texto = "💰 El vendedor propuso un nuevo monto: $" . number_format($monto, 0, ',', '.');

  $stmt = $conn->prepare("
    INSERT INTO mensajes (conversacion_id, remitente_id, mensaje, enviado_en, leido)
    VALUES (?, ?, ?, NOW(), 0)
  ");
  if (!$stmt) {
    echo json_encode(['exito' => false, 'error' => $conn->error]);
    exit;
  }
  $stmt->bind_param("iis", $id_conversacion, $usuario_id, $texto);
  $stmt->execute();
  $stmt->close();
}

echo json_encode(['exito' => $ok]);
