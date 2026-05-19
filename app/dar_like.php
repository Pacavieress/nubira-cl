<?php
session_start();
require_once __DIR__ . '/conexion.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
    exit;
}

$id_alumno = (int)$_SESSION['usuario_id'];
$id_apunte = isset($_POST['id_apunte']) ? (int)$_POST['id_apunte'] : 0;

if ($id_apunte <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
    exit;
}

/* 1) Asegura índice único para evitar duplicados */
$conn->query("ALTER TABLE likes ADD UNIQUE KEY uniq_like (id_apunte, id_alumno)");

/* 2) ¿Ya existe el like? */
$stmt = $conn->prepare("SELECT 1 FROM likes WHERE id_apunte = ? AND id_alumno = ? LIMIT 1");
$stmt->bind_param("ii", $id_apunte, $id_alumno);
$stmt->execute();
$stmt->store_result();
$exists = $stmt->num_rows > 0;
$stmt->close();

/* 3) Toggle: si existe, borra; si no, inserta */
if ($exists) {
    $stmt = $conn->prepare("DELETE FROM likes WHERE id_apunte = ? AND id_alumno = ? LIMIT 1");
    $stmt->bind_param("ii", $id_apunte, $id_alumno);
    $stmt->execute();
    $stmt->close();
    $liked = false;
} else {
    $stmt = $conn->prepare("INSERT INTO likes (id_apunte, id_alumno) VALUES (?, ?)");
    $stmt->bind_param("ii", $id_apunte, $id_alumno);
    $stmt->execute();
    $stmt->close();
    $liked = true;
}

/* 4) Nuevo total */
$stmt = $conn->prepare("SELECT COUNT(*) FROM likes WHERE id_apunte = ?");
$stmt->bind_param("i", $id_apunte);
$stmt->execute();
$stmt->bind_result($total);
$stmt->fetch();
$stmt->close();

echo json_encode(['ok' => true, 'liked' => $liked, 'total' => (int)$total]);
