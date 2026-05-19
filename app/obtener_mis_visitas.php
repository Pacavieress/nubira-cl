<?php
/**
 * MICRO-ENDPOINT: NUBIRA 2.0
 * Propósito: Devolver las visitas en tiempo real solo al dueño del perfil.
 */
session_start();
require_once __DIR__ . '/init_sesion.php';

header('Content-Type: application/json');

// Si no hay sesión o es visitante, bloqueamos (Seguridad Nubira)
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['vistas' => 0]);
    exit;
}

$mi_id = (int)$_SESSION['usuario_id'];

// Consulta súper ligera
$stmt = $conn->prepare("SELECT vistas_perfil FROM alumnos WHERE id = ?");
if ($stmt) {
    $stmt->bind_param("i", $mi_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    echo json_encode(['vistas' => (int)($res['vistas_perfil'] ?? 0)]);
} else {
    echo json_encode(['vistas' => 0]);
}