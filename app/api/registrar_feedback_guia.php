<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'No autorizado']));
}

require_once __DIR__ . '/../conexion.php';

$data = json_decode(file_get_contents("php://input"), true);
$usuario_id = (int)$_SESSION['usuario_id'];
$seccion = substr(preg_replace('/[^a-zA-Z0-9_]/', '', $data['seccion'] ?? ''), 0, 50);
$voto = isset($data['voto']) ? (int)$data['voto'] : null;

if (empty($seccion) || $voto === null) {
    http_response_code(400);
    exit(json_encode(['error' => 'Datos incompletos']));
}

// Inserción ignorando si ya existe gracias al UNIQUE KEY de la base de datos
$sql = "INSERT IGNORE INTO guia_feedback (usuario_id, seccion, voto) VALUES (?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("isi", $usuario_id, $seccion, $voto);
$stmt->execute();

echo json_encode(['success' => true]);
$stmt->close();
?>