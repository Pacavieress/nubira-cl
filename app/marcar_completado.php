<?php
session_start();
require_once __DIR__ . '/conexion.php';

// 🧩 Validar sesión
if (!isset($_SESSION['usuario_id'])) {
  header("Location: /login");
  exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];
$contrato_id = (int)($_POST['contrato_id'] ?? 0);

if ($contrato_id <= 0) {
  die("<p style='font-family:sans-serif;color:red'>❌ Contrato inválido.</p>");
}

// 🔒 Verificar que el usuario sea el comprador y que el contrato esté en "entregado"
$stmt = $conn->prepare("
  SELECT comprador_id, vendedor_id, estado, servicio_id
  FROM contratos
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param("i", $contrato_id);
$stmt->execute();
$contrato = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contrato) {
  die("<p style='font-family:sans-serif;color:gray'>🚫 Contrato no encontrado.</p>");
}

if ($contrato['comprador_id'] != $usuario_id) {
  die("<p style='font-family:sans-serif;color:gray'>🚫 No tienes permiso para finalizar este contrato.</p>");
}

if ($contrato['estado'] !== 'entregado') {
  die("<p style='font-family:sans-serif;color:gray'>⚠️ Solo puedes finalizar contratos que ya fueron entregados.</p>");
}

// ✅ Actualizar estado a "completado"
$upd = $conn->prepare("
  UPDATE contratos 
  SET estado = 'completado', fecha_finalizacion = NOW()
  WHERE id = ?
");
$upd->bind_param("i", $contrato_id);
$upd->execute();
$upd->close();

// 💰 (futuro) Aquí podrías liberar el pago al vendedor
/*
$liberarPago = $conn->prepare("UPDATE pagos SET estado='liberado' WHERE contrato_id=?");
$liberarPago->bind_param("i", $contrato_id);
$liberarPago->execute();
$liberarPago->close();
*/

// 📧 (opcional) Notificar al vendedor
/*
include_once __DIR__ . '/correo.php';
enviarCorreoCambioEstado($contrato_id, 'completado');
*/

require_once __DIR__ . '/enviar_push_nubira.php';
enviar_push_nubira((int)$contrato['comprador_id'], '🌟 Contrato completado', 'El contrato se cerró exitosamente', '/mis-contratos');
enviar_push_nubira((int)$contrato['vendedor_id'], '🌟 Contrato completado', 'El contrato se cerró exitosamente', '/mis-ventas');

echo "<script>alert('🎉 Contrato finalizado correctamente. ¡Gracias por usar Nubira!');window.location.href='/app/mini_aula.php?id=$contrato_id';</script>";
exit;
?>
