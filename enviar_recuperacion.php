<?php
session_start();
require_once __DIR__ . '/app/conexion.php';
require_once __DIR__ . '/app/correo.php';

// [FIX] Activar reporte de errores mysqli en este flujo crítico —
// solo warnings (no excepciones), para no cambiar el control de flujo existente.
mysqli_report(MYSQLI_REPORT_ERROR);

$correo = strtolower(trim($_POST['correo'] ?? ''));
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

    // [FIX] Verificar explícitamente que el UPDATE realmente escribió el token
    // antes de enviar el correo — si falla, no debe mandarse un link roto.
    if (!$stmt->execute() || $stmt->affected_rows < 1) {
        error_log("[RECUPERACION] Fallo al guardar token_recuperacion para '$correo'. mysqli error: " . $conn->error);
        $_SESSION['mensaje_recuperacion'] = "❌ No pudimos procesar tu solicitud. Intenta de nuevo en unos minutos o escríbenos a contacto@nubira.cl.";
        header("Location: recuperar.php");
        exit;
    }

    // Enviar correo de recuperación (solo si el token quedó guardado)
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
