<?php
/**
 * CRON: recordatorio de mensaje sin responder (comprador → vendedor, ≥15 min)
 */
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_secret'])) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/env_loader.php';

$CRON_SECRET = getenv('INACTIVIDAD_CHAT_CRON_SECRET') ?: '';
if (php_sapi_name() !== 'cli' && ($_GET['cron_secret'] ?? '') !== $CRON_SECRET) {
    http_response_code(403);
    die('Forbidden');
}

ini_set('display_errors', 0);

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php'; // ya tienes PHPMailer aquí

// Buscar mensajes sin respuesta del vendedor por más de 15 minutos
$sql = "
SELECT m.id, m.conversacion_id, m.remitente_id, c.vendedor_id, c.comprador_id, c.id AS chat_id,
       a_vendedor.correo AS correo_vendedor, a_vendedor.nombre AS nombre_vendedor,
       a_comprador.nombre AS nombre_comprador, s.titulo AS servicio_titulo, m.mensaje, m.enviado_en
FROM mensajes m
JOIN conversaciones c ON c.id = m.conversacion_id
JOIN servicios s ON s.id = c.servicio_id
JOIN alumnos a_vendedor ON a_vendedor.id = c.vendedor_id
JOIN alumnos a_comprador ON a_comprador.id = c.comprador_id
WHERE m.notificado = 0
  AND m.remitente_id = c.comprador_id
  AND TIMESTAMPDIFF(MINUTE, m.enviado_en, NOW()) >= 15
  AND a_vendedor.bloqueado = 0
  AND NOT EXISTS (
      SELECT 1 FROM mensajes r
      WHERE r.conversacion_id = m.conversacion_id
        AND r.remitente_id = c.vendedor_id
        AND r.enviado_en > m.enviado_en
  )
LIMIT 10;
";

require_once __DIR__ . '/helpers/notificaciones_chat.php';

$res = $conn->query($sql);

while ($row = $res->fetch_assoc()) {
    nb_notificar_nuevo_mensaje(
        $conn,
        $row['conversacion_id'],
        $row['comprador_id'],
        $row['nombre_comprador'],
        $row['mensaje'],
        'conversaciones',
        'mensajes',
        'conversacion_id',
        'enviado_en',
        'Recordatorio: tienes un mensaje sin responder'
    );

    // Marcar como notificado
    $conn->query("UPDATE mensajes SET notificado = 1 WHERE id = {$row['id']}");
}
