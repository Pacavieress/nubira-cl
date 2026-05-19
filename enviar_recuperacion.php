<?php
session_start();
require_once __DIR__ . '/app/conexion.php';
require_once __DIR__ . '/app/correo.php';

$correo = trim($_POST['correo'] ?? '');
$_SESSION['mensaje_recuperacion'] = '';

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['mensaje_recuperacion'] = "❌ Ingresa un correo válido.";
    header("Location: recuperar.php");
    exit;
}

// Verificar si el correo está registrado
$stmt = $conn->prepare("SELECT id, nombre FROM alumnos WHERE correo = ?");
$stmt->bind_param("s", $correo);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $usuario = $result->fetch_assoc();
    $token = bin2hex(random_bytes(32));
    $expiracion = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // Guardar token en DB
    $stmt = $conn->prepare("UPDATE alumnos SET token_recuperacion = ?, expiracion_token = ? WHERE correo = ?");
    $stmt->bind_param("sss", $token, $expiracion, $correo);
    $stmt->execute();

    // Enviar correo de recuperación
    if (enviarCorreoRecuperacion($correo, $usuario['nombre'], $token)) {
        $_SESSION['mensaje_recuperacion'] = "📧 Te hemos enviado un enlace para restablecer tu contraseña.";
    } else {
        $_SESSION['mensaje_recuperacion'] = "❌ No se pudo enviar el correo. Intenta más tarde.";
    }
} else {
    $_SESSION['mensaje_recuperacion'] = "❌ No encontramos una cuenta con ese correo.";
}

header("Location: recuperar.php");
exit;
