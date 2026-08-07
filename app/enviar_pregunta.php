<?php
session_start();
require_once __DIR__ . '/conexion.php';

// 🔒 Verifica sesión
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    exit('Debes iniciar sesión para enviar una pregunta.');
}

$id_servicio    = (int)($_POST['id_servicio'] ?? 0);
$id_preguntador = (int)$_SESSION['usuario_id'];
$pregunta       = trim($_POST['pregunta'] ?? '');

if ($id_servicio <= 0 || $pregunta === '') {
    http_response_code(400);
    exit('Faltan datos o la pregunta está vacía.');
}

// 🧩 Verifica que el servicio exista y esté aprobado
$check = $conn->prepare("SELECT COUNT(*) FROM servicios WHERE id = ? AND estado = 'aprobado'");
$check->bind_param("i", $id_servicio);
$check->execute();
$check->bind_result($exists);
$check->fetch();
$check->close();

if ($exists === 0) {
    http_response_code(404);
    exit('El servicio no existe o no está disponible.');
}

// 💬 Guarda la pregunta
$stmt = $conn->prepare("
    INSERT INTO preguntas_servicios (id_servicio, id_preguntador, pregunta, fecha_pregunta)
    VALUES (?, ?, ?, NOW())
");
$stmt->bind_param("iis", $id_servicio, $id_preguntador, $pregunta);
$stmt->execute();
$stmt->close();
$conn->close();

// ✅ Redirección segura con parámetro de éxito
header("Location: /servicios/$id_servicio?ok=1");
exit;
