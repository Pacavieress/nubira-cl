<?php
session_start();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php'; // PHPMailer

// =====================================================
// ✅ Solo administradores pueden usar este archivo
// =====================================================
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header('Location: /');
    exit;
}

// =====================================================
// ✅ Validar correo recibido
// =====================================================
if (empty($_GET['correo'])) {
    die("❌ Correo no especificado.");
}

$correo = strtolower(trim($_GET['correo']));
$ip     = $_GET['ip'] ?? null;

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['mensaje_admin'] = "⚠️ Formato de correo inválido: $correo.";
    header("Location: /admin/login-fallos");
    exit;
}

// =====================================================
// ✅ Registrar interesado si no existe
// =====================================================
$stmt = $conn->prepare("
    INSERT IGNORE INTO interesados_registro (correo, ip, invitado, fecha)
    VALUES (?, ?, 1, NOW())
");
$stmt->bind_param("ss", $correo, $ip);
$stmt->execute();
$stmt->close();

// =====================================================
// ✅ Asunto y cuerpo del correo optimizado
// =====================================================
$asunto = "Invitación a registrarte en Nubira.cl";

$link_registro = "https://nubira.cl/register?email=" . urlencode($correo);

$mensaje = "
<div style='font-family:Segoe UI,Arial,sans-serif; color:#333; line-height:1.5'>
  <p>Estimado/a,</p>
  <p>Se detectó un intento de acceso a <strong>Nubira.cl</strong> utilizando este correo electrónico.</p>
  <p>Si deseas registrarte para acceder a materiales académicos, clases particulares y oportunidades universitarias, puedes hacerlo en el siguiente enlace:</p>
  <p style='text-align:center; margin:25px 0'>
    <a href='{$link_registro}'
       style='background-color:#1E88C9;color:white;padding:12px 24px;
       border-radius:6px;text-decoration:none;font-weight:600;'>
       Crear cuenta en Nubira.cl
    </a>
  </p>
  <p style='font-size:13px;color:#555;margin-top:20px'>
    Si no realizaste esta acción, ignora este mensaje.<br>
    <br>
    Atentamente,<br>
    <strong>Equipo Nubira</strong><br>
    <a href='https://nubira.cl' style='color:#1E88C9;text-decoration:none;'>https://nubira.cl</a><br>
    <span style='font-size:11px;color:#888;'>Este correo fue enviado automáticamente desde contacto@nubira.cl</span>
  </p>
</div>
";


// ✅ Versión texto plano (para filtros UC/Outlook)
$mensaje_texto = "Hola, has intentado ingresar a Nubira.cl con este correo, pero aún no tienes cuenta.
Crea tu cuenta gratis en: {$link_registro}";

// =====================================================
// ✅ Enviar desde contacto@nubira.cl para mejor reputación
// =====================================================
unset($_SESSION['usar_contacto']); // usa la cuenta no-reply@nubira.cl


// =====================================================
// 🧠 Log de diagnóstico (inicio)
// =====================================================
file_put_contents(__DIR__ . '/../log_envio.txt', date("Y-m-d H:i:s") . " - [INVITAR] Inicio envío a $correo\n", FILE_APPEND);

// =====================================================
// 🚀 Enviar correo
// =====================================================
$ok = enviarCorreo($correo, $asunto, $mensaje, $mensaje_texto);

// =====================================================
// 🧠 Log de diagnóstico (resultado)
// =====================================================
file_put_contents(__DIR__ . '/../log_envio.txt', date("Y-m-d H:i:s") . " - [INVITAR] Resultado: " . ($ok ? "OK" : "FALLÓ") . " para $correo\n", FILE_APPEND);

// =====================================================
// ✅ Mensaje en sesión para el panel admin
// =====================================================
$_SESSION['mensaje_admin'] = $ok
    ? "✅ Invitación enviada correctamente a $correo."
    : "⚠️ Error al enviar invitación a $correo. Revisa el log de envío.";

// =====================================================
// 🔁 Redirigir de vuelta al panel
// =====================================================
header("Location: /admin/login-fallos");
exit;
?>
