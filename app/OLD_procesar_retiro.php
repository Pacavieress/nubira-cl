<?php
session_start();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php';

if (($_SESSION['rol'] ?? '') !== 'admin') {
  header('Location: /login');
  exit;
}

$id = (int)($_POST['id'] ?? 0);
$accion = $_POST['accion'] ?? '';

if ($id <= 0 || !in_array($accion, ['aprobar', 'rechazar'])) {
  die("Solicitud inválida");
}

$retiro = $conn->query("
  SELECT r.*, a.nombre AS vendedor_nombre, a.correo AS vendedor_correo
  FROM retiros r
  JOIN alumnos a ON a.id = r.vendedor_id
  WHERE r.id = $id
")->fetch_assoc();

if (!$retiro) die("Retiro no encontrado.");

if ($accion === 'aprobar') {
  $conn->query("UPDATE retiros SET estado='pagado', fecha_pago=NOW() WHERE id=$id");

  // 💌 Notificación
  $monto_format = '$' . number_format($retiro['monto'], 0, ',', '.');
  $mensaje = "
  <p>Hola <b>{$retiro['vendedor_nombre']}</b>,</p>
  <p>Tu retiro correspondiente al contrato #{$retiro['contrato_id']} ha sido aprobado.</p>
  <p><b>Monto transferido:</b> {$monto_format}</p>
  <p>Gracias por usar <b>Nubira.cl</b>.</p>
  ";
  enviarCorreo($retiro['vendedor_correo'], "✅ Retiro aprobado - Nubira.cl", $mensaje);

} elseif ($accion === 'rechazar') {
  $conn->query("UPDATE retiros SET estado='rechazado' WHERE id=$id");

  $mensaje = "
  <p>Hola <b>{$retiro['vendedor_nombre']}</b>,</p>
  <p>Tu solicitud de retiro no pudo ser aprobada por el momento.</p>
  <p>Por favor revisa tus datos bancarios o contacta a soporte.</p>
  ";
  enviarCorreo($retiro['vendedor_correo'], "❌ Retiro rechazado - Nubira.cl", $mensaje);
}

header("Location: /app/admin_retiros.php?estado=pendiente");
exit;
