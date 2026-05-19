<?php
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
  AND NOT EXISTS (
      SELECT 1 FROM mensajes r
      WHERE r.conversacion_id = m.conversacion_id
        AND r.remitente_id = c.vendedor_id
        AND r.enviado_en > m.enviado_en
  )
LIMIT 10;
";

$res = $conn->query($sql);

while ($row = $res->fetch_assoc()) {
    $mensaje = "
    Hola {$row['nombre_vendedor']}, 👋<br><br>
    Tienes un mensaje sin responder en tu servicio <b>“{$row['servicio_titulo']}”</b>.<br>
    <blockquote style='border-left:3px solid #54A6D8;padding-left:8px;color:#555'>
      {$row['nombre_comprador']} te escribió:<br><i>“{$row['mensaje']}”</i>
    </blockquote>
    <br>
    Responde cuanto antes desde <a href='https://nubira.cl/chat.php?id={$row['chat_id']}'>este chat</a> para no perder el interés del estudiante.
    <br><br>💙 El equipo Nubira.cl
    ";

    enviarCorreo(
        $row['correo_vendedor'],
        "Tienes un mensaje pendiente en Nubira.cl",
        $mensaje
    );

    // Marcar como notificado
    $conn->query("UPDATE mensajes SET notificado = 1 WHERE id = {$row['id']}");
}
