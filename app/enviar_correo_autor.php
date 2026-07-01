<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php'; // tu PHPMailer ya configurado

/* Seguridad */
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    echo json_encode(["status" => "error", "msg" => "No autorizado"]);
    exit;
}

/* Datos admin */
$admin_id   = (int)$_SESSION['usuario_id'];
$admin_name = $_SESSION['usuario_nombre'] ?? 'Admin';

/* Datos recibidos */
$correo  = trim($_POST['correo']  ?? '');
$asunto  = trim($_POST['asunto']  ?? '');
$mensaje = trim($_POST['mensaje'] ?? '');
$firma   = trim($_POST['firma']   ?? '');

/* Validación */
if (!$correo || !$asunto || !$mensaje) {
    echo json_encode(["status" => "error", "msg" => "Faltan datos para enviar el correo"]);
    exit;
}

/* Construir mensaje final */
$mensaje_html = nl2br($mensaje);

if ($firma !== '') {
    $mensaje_html .= "<br><br>" . nl2br($firma);
}

/* Enviar correo */
$exito_envio = _enviarEmailBase($correo, $asunto, plantillaMaestra($asunto, $mensaje_html), '', true);

/* Guardar en BD */
$stmt = $conn->prepare("
    INSERT INTO correos_admin (admin_id, admin_nombre, destinatario, asunto, mensaje, exito)
    VALUES (?, ?, ?, ?, ?, ?)
");
$exito = $exito_envio ? 1 : 0;

$stmt->bind_param(
    "issssi",
    $admin_id,
    $admin_name,
    $correo,
    $asunto,
    $mensaje_html,
    $exito
);

$stmt->execute();
$stmt->close();

/* Respuesta a AJAX */
if ($exito_envio) {
    echo json_encode([
        "status" => "ok",
        "msg"    => "Correo enviado correctamente ✔️"
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "msg"    => "No se pudo enviar el correo ❌"
    ]);
}
?>
