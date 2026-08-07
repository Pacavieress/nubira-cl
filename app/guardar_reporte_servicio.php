<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login');
    exit;
}

$usuario_id  = $_SESSION['usuario_id'];
$servicio_id = intval($_POST['servicio_id'] ?? 0);
$motivo      = trim($_POST['motivo'] ?? '');
$mensaje     = trim($_POST['mensaje'] ?? '');

if (!$servicio_id || $motivo === '') {
    header("Location: /reportar-servicio?id=$servicio_id&error=Faltan campos obligatorios.");
    exit;
}

// Evita reportes duplicados por mismo usuario al mismo servicio
$stmt = $conn->prepare("SELECT id FROM reportes_servicio WHERE servicio_id = ? AND usuario_id = ?");
$stmt->bind_param("ii", $servicio_id, $usuario_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->close();
    header("Location: /reportar-servicio?id=$servicio_id&error=Ya reportaste este servicio antes.");
    exit;
}
$stmt->close();

// Guardar reporte
$stmt = $conn->prepare("INSERT INTO reportes_servicio (servicio_id, usuario_id, motivo, mensaje) VALUES (?, ?, ?, ?)");
$stmt->bind_param("iiss", $servicio_id, $usuario_id, $motivo, $mensaje);
if (!$stmt->execute()) {
    $stmt->close();
    header("Location: /reportar-servicio?id=$servicio_id&error=Error al guardar el reporte.");
    exit;
}
$stmt->close();
$conn->close();

header("Location: /servicios/$servicio_id?ok=Tu reporte fue enviado. ¡Gracias por ayudarnos a mantener la comunidad segura!");
exit;
?>
