<?php
session_start();
require_once __DIR__ . '/app/conexion.php';

$token = $_POST['token'] ?? '';
$nueva = $_POST['nueva'] ?? '';
$confirmar = $_POST['confirmar'] ?? '';

if (!$token || !$nueva || !$confirmar) {
    $_SESSION['mensaje_recuperacion'] = "❌ Faltan campos obligatorios.";
    header("Location: recuperar.php");
    exit;
}

if ($nueva !== $confirmar) {
    $_SESSION['mensaje_recuperacion'] = "❌ Las contraseñas no coinciden.";
    header("Location: nueva_contrasena.php?token=" . urlencode($token));
    exit;
}

// Verificar token válido y vigente
$stmt = $conn->prepare("SELECT id FROM alumnos WHERE token_recuperacion = ? AND expiracion_token > NOW()");
$stmt->bind_param("s", $token);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows === 1) {
    $usuario = $resultado->fetch_assoc();
    $hash = password_hash($nueva, PASSWORD_DEFAULT);

    // Actualizar contraseña y eliminar token
    $stmt = $conn->prepare("UPDATE alumnos SET password = ?, token_recuperacion = NULL, expiracion_token = NULL WHERE id = ?");
    $stmt->bind_param("si", $hash, $usuario['id']);
    $stmt->execute();

    $_SESSION['mensaje_login'] = "✅ Tu contraseña fue actualizada correctamente.";
    header("Location: login.php");
    exit;
} else {
    $_SESSION['mensaje_recuperacion'] = "❌ El enlace ya no es válido.";
    header("Location: recuperar.php");
    exit;
}
