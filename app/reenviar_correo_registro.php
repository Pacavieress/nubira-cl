<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php';

// Validar que haya un registro pendiente en sesión
if (!isset($_SESSION['registro_pendiente'])) {
    echo json_encode(['ok' => false, 'error' => 'Sesión expirada']);
    exit;
}

$datos = $_SESSION['registro_pendiente'];
$correo = $datos['correo'];
$nombre = $datos['nombre'];

// Cooldown server-side (60s)
$ultimo_reenvio_sesion = $_SESSION['registro_pendiente']['ultimo_reenvio'] ?? $datos['timestamp'];
if (time() - $ultimo_reenvio_sesion < 60) {
    $espera = 60 - (time() - $ultimo_reenvio_sesion);
    echo json_encode(['ok' => false, 'error' => "Espera {$espera}s antes de reenviar"]);
    exit;
}

// Traer el token actual del usuario (puede haber cambiado)
$stmt = $conn->prepare("SELECT id, token, confirmado FROM alumnos WHERE correo = ? AND visible = 1");
$stmt->bind_param("s", $correo);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'Usuario no encontrado']);
    exit;
}

if (!empty($user['confirmado'])) {
    echo json_encode(['ok' => false, 'error' => 'Esta cuenta ya está confirmada']);
    exit;
}

// Regenerar token si está vacío
$token = $user['token'];
if (empty($token)) {
    $token = bin2hex(random_bytes(32));
    $stmt_tok = $conn->prepare("UPDATE alumnos SET token = ? WHERE id = ?");
    $stmt_tok->bind_param("si", $token, $user['id']);
    $stmt_tok->execute();
    $stmt_tok->close();
}

// Enviar correo
if (enviarCorreoConfirmacion($correo, $nombre, $token)) {
    // Actualizar timestamps
    $_SESSION['registro_pendiente']['ultimo_reenvio'] = time();
    $stmt_upd = $conn->prepare("UPDATE alumnos SET ultimo_reenvio = NOW() WHERE id = ?");
    $stmt_upd->bind_param("i", $user['id']);
    $stmt_upd->execute();
    $stmt_upd->close();
    
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => 'No se pudo enviar el correo']);
}