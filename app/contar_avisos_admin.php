<?php
require_once __DIR__ . '/init_sesion.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['total' => 0]);
    exit;
}

$uid = (int)$_SESSION['usuario_id'];
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM avisos_admin WHERE destino_id = ? AND leido = 0");
$stmt->bind_param("i", $uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo json_encode(['total' => (int)($row['total'] ?? 0)]);