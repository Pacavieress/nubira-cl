<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['registro_pendiente'])) {
    echo json_encode(['confirmado' => false]);
    exit;
}

$correo = $_SESSION['registro_pendiente']['correo'];

$stmt = $conn->prepare("SELECT confirmado FROM alumnos WHERE correo = ? AND visible = 1");
$stmt->bind_param("s", $correo);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

$confirmado = !empty($user['confirmado']);

// Si ya confirmó, limpiamos la sesión de registro pendiente
if ($confirmado) {
    unset($_SESSION['registro_pendiente']);
}

echo json_encode(['confirmado' => $confirmado]);