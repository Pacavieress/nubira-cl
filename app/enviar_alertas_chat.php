<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 📂 Log
$logDir  = __DIR__;
$logFile = $logDir . '/error_alertas_chat.log';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
if (!file_exists($logFile)) {
    file_put_contents($logFile, "📘 Archivo de log creado: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    chmod($logFile, 0664);
}
ini_set('error_log', $logFile);
error_log("────────────────────────────── " . date('Y-m-d H:i:s') . " ──────────────────────────────");
error_log("✅ Log inicial activo desde " . __FILE__);

// 📨 Alertas por inactividad
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php'; // enviarCorreo($correo, $asunto, $html, $texto)

// 1️⃣ Buscar alertas pendientes (vencidas y sin enviar en los últimos 15 min)
$sql = "
SELECT a.id AS alerta_id, a.conversacion_id, a.mensaje_id, a.comprador_id, a.vendedor_id,
       m.mensaje, m.enviado_en
FROM chat_alertas a
JOIN mensajes m ON a.mensaje_id = m.id
WHERE a.enviado = 0
  AND a.disparo_en <= NOW()
  AND (a.fecha_envio IS NULL OR a.fecha_envio < NOW() - INTERVAL 15 MINUTE)
LIMIT 100
";
$res = $conn->query($sql);
if (!$res) {
    error_log('❌ Error SQL (listar alertas): ' . $conn->error);
    exit;
}

while ($a = $res->fetch_assoc()) {
    $alerta_id       = (int)$a['alerta_id'];
    $conversacion_id = (int)$a['conversacion_id'];
    $mensaje_id      = (int)$a['mensaje_id'];
    $comprador_id    = (int)$a['comprador_id'];
    $vendedor_id     = (int)$a['vendedor_id'];
    $fecha_mensaje   = $conn->real_escape_string($a['enviado_en']);

    // 2️⃣ ¿Vendedor respondió después?
    $sqlResp = "
        SELECT 1
        FROM mensajes
        WHERE conversacion_id = $conversacion_id
          AND remitente_id    = $vendedor_id
          AND enviado_en     > '$fecha_mensaje'
        LIMIT 1
    ";
    $resp = $conn->query($sqlResp);
    $respondido = $resp && $resp->num_rows > 0;

    if ($respondido) {
        $conn->query("UPDATE chat_alertas SET enviado = 1, fecha_envio = NOW() WHERE id = $alerta_id");
        error_log("💬 Vendedor $vendedor_id respondió antes del aviso (alerta $alerta_id).");
        continue;
    }

    // 3️⃣ Datos del vendedor
    $r = $conn->query("SELECT correo, nombre FROM alumnos WHERE id = $vendedor_id LIMIT 1");
    if (!$r || $r->num_rows === 0) {
        error_log("⚠️ Vendedor $vendedor_id no encontrado (alerta $alerta_id).");
        $conn->query("UPDATE chat_alertas SET enviado = 1, fecha_envio = NOW() WHERE id = $alerta_id");
        continue;
    }
    $v = $r->fetch_assoc();
    $correo = $v['correo'] ?? '';
    $nombre = $v['nombre'] ?? 'Usuario';

    // 4️⃣ Enviar correo
    $asunto = "Tienes un mensaje pendiente en Nubira.cl 💬";
    $texto  = "Hola $nombre, tienes un mensaje pendiente de un comprador. Responde pronto desde tu chat en Nubira.cl.";

    $html = "
    <p>Hola <b>$nombre</b>,</p>
    <p>Un comprador te ha escrito y aún no has respondido.</p>
    <p style='text-align:center; margin:20px 0;'>
      <a href='https://nubira.cl/app/chat.php?id=$conversacion_id'
         style='background-color:#54A6D8;color:white;padding:10px 20px;border-radius:8px;
                text-decoration:none;font-weight:600;display:inline-block;'>
         💬 Responder ahora
      </a>
    </p>
    <p style='font-size:13px;color:#666;text-align:center;'>
      No compartas datos personales fuera del chat para tu seguridad.
    </p>";

    $ok = enviarCorreo($correo, $asunto, $html, $texto);

    // 5️⃣ Marcar alerta como procesada
    $conn->query("UPDATE chat_alertas 
                  SET enviado = 1, fecha_envio = NOW() 
                  WHERE id = $alerta_id");

    if ($ok) {
        error_log("📨 Alerta enviada a vendedor $vendedor_id ($correo) por mensaje $mensaje_id.");
    } else {
        error_log("⚠️ Falló envío de correo a $correo (alerta $alerta_id).");
    }
}

error_log("✅ Finalizó ejecución enviar_alertas_chat.php (" . date('Y-m-d H:i:s') . ")");
