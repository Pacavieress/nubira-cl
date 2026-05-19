<?php
// app/guardar_match.php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['usuario_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false, 'error'=>'no_auth']);
  exit;
}

$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf_descubre'] ?? '', $csrf)) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'csrf']);
  exit;
}

$usuario_id = (int)($_SESSION['usuario_id'] ?? 0);
$id_item    = (int)($_POST['id_item'] ?? 0);
$tipo       = $_POST['tipo']   ?? '';
$accion     = $_POST['accion'] ?? '';

if (!$usuario_id || !$id_item || !in_array($tipo, ['apunte','servicio','oportunidad'], true) ||
    !in_array($accion, ['like','dislike'], true)) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'bad_params']);
  exit;
}

try {
  // UPSERT
  $sql = "INSERT INTO interacciones_descubre (usuario_id, item_id, tipo, accion)
          VALUES (?,?,?,?)
          ON DUPLICATE KEY UPDATE accion=VALUES(accion), created_at=NOW()";
  $stmt = $conn->prepare($sql);
  if (!$stmt) { throw new Exception($conn->error); }
  $stmt->bind_param("iiss", $usuario_id, $id_item, $tipo, $accion);
  $stmt->execute();
  $stmt->close();

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'db', 'msg'=>$e->getMessage()]);
}
