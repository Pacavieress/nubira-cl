<?php
session_start();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php'; // ✅ Para enviar notificaciones por email

if (!isset($_SESSION['usuario_id'])) {
  header('Location: /login');
  exit;
}

// 🛡️ Verificación CSRF
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
  http_response_code(403);
  echo "CSRF inválido";
  exit;
}

$contrato_id = (int)($_POST['contrato_id'] ?? 0);
$usuario_id  = (int)$_SESSION['usuario_id'];
if ($contrato_id <= 0) {
  echo "Contrato inválido";
  exit;
}

// 🔍 Obtener contrato y participantes
$stmt = $conn->prepare("
  SELECT c.id, c.comprador_id, c.vendedor_id,
         c.confirmado_comprador, c.confirmado_vendedor, c.estado, c.monto,
         s.titulo AS servicio_titulo,
         ac.nombre AS comprador_nombre, ac.correo AS comprador_correo,
         av.nombre AS vendedor_nombre, av.correo AS vendedor_correo
  FROM contratos c
  JOIN servicios s ON c.servicio_id = s.id
  JOIN alumnos ac ON ac.id = c.comprador_id
  JOIN alumnos av ON av.id = c.vendedor_id
  WHERE c.id = ?
");
$stmt->bind_param("i", $contrato_id);
$stmt->execute();
$c = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$c) {
  echo "Contrato no encontrado";
  exit;
}
if ($c['estado'] !== 'en_progreso') {
  echo "Este contrato no está activo.";
  exit;
}

// 🧩 Determinar quién confirma
$columna = '';
$evento  = '';

if ($usuario_id == $c['comprador_id']) {
  $columna = 'confirmado_comprador';
  $evento  = 'FINALIZA_COMPRADOR';
} elseif ($usuario_id == $c['vendedor_id']) {
  $columna = 'confirmado_vendedor';
  $evento  = 'FINALIZA_VENDEDOR';
} else {
  echo "No tienes permiso para modificar este contrato.";
  exit;
}

// ✅ Marcar confirmación
$stmt = $conn->prepare("UPDATE contratos SET $columna = 1 WHERE id = ?");
$stmt->bind_param("i", $contrato_id);
$stmt->execute();
$stmt->close();

// 🧾 Registrar evento
$ev = $conn->prepare("
  INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle)
  VALUES (?, ?, ?, 'Parte marcó como finalizado')
");
$ev->bind_param("iis", $contrato_id, $usuario_id, $evento);
$ev->execute();
$ev->close();

// 🔎 Revisar si ambos confirmaron
$stmt = $conn->prepare("
  SELECT confirmado_comprador, confirmado_vendedor
  FROM contratos WHERE id = ?
");
$stmt->bind_param("i", $contrato_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 🚀 Si ambos confirmaron → liberar pago y enviar correos
if ($res['confirmado_comprador'] && $res['confirmado_vendedor']) {
  $stmt = $conn->prepare("
    UPDATE contratos SET estado='liberado', fecha_cierre=NOW()
    WHERE id = ?
  ");
  $stmt->bind_param("i", $contrato_id);
  $stmt->execute();
  $stmt->close();

  // Registrar evento final
  $lib = $conn->prepare("
    INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle)
    VALUES (?, ?, 'LIBERADO', 'Ambas partes confirmaron; pago liberado')
  ");
  $lib->bind_param("ii", $contrato_id, $usuario_id);
  $lib->execute();
  $lib->close();

  // 💌 Envío de correos automáticos
  $monto_format = '$' . number_format($c['monto'], 0, ',', '.');

  // Vendedor
  $asunto_v = "💰 Pago liberado - Nubira.cl";
  $mensaje_v = "
  <p>Hola <b>{$c['vendedor_nombre']}</b>,</p>
  <p>El pago por tu servicio <b>{$c['servicio_titulo']}</b> ha sido liberado correctamente.</p>
  <p><b>Monto liberado:</b> {$monto_format}</p>
  <p>Ya puedes disponer del dinero desde tu cuenta Nubira.</p>
  <p>Gracias por confiar en <b>Nubira.cl</b>.</p>
  ";

  // Comprador
  $asunto_c = "✅ Servicio finalizado - Nubira.cl";
  $mensaje_c = "
  <p>Hola <b>{$c['comprador_nombre']}</b>,</p>
  <p>El servicio <b>{$c['servicio_titulo']}</b> ha sido marcado como finalizado y el pago de {$monto_format} fue liberado al vendedor <b>{$c['vendedor_nombre']}</b>.</p>
  <p>Gracias por usar <b>Nubira.cl</b>.</p>
  ";

  enviarCorreo($c['vendedor_correo'], $asunto_v, $mensaje_v);
  enviarCorreo($c['comprador_correo'], $asunto_c, $mensaje_c);

  require_once __DIR__ . '/enviar_push_nubira.php';
  enviar_push_nubira((int)$c['vendedor_id'],  '🎓 Clase finalizada', 'El pago fue liberado. Recuerda valorar al estudiante', '/mis-evaluaciones');
  enviar_push_nubira((int)$c['comprador_id'], '🎓 Clase finalizada', 'Recuerda dejar tu valoración para mejorar la comunidad', '/mis-evaluaciones');
}

// 🔁 Redirigir de vuelta al detalle
header("Location: /app/contrato_detalle.php?id=$contrato_id");
exit;
