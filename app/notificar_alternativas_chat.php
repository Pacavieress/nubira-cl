<?php
/**
 * CRON: sugerir tutores alternativos al comprador (mensaje sin responder, ≥20 min)
 */
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_secret'])) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/env_loader.php';

$CRON_SECRET = getenv('ALTERNATIVAS_CHAT_CRON_SECRET') ?: '';
if (php_sapi_name() !== 'cli' && ($_GET['cron_secret'] ?? '') !== $CRON_SECRET) {
    http_response_code(403);
    die('Forbidden');
}

ini_set('display_errors', 0);

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/enviar_push_nubira.php';
require_once __DIR__ . '/helpers/tutores_alternativos.php';

// Migración: bandera de control independiente de mensajes.notificado
$check_col = $conn->query("SHOW COLUMNS FROM mensajes LIKE 'alternativas_enviadas'");
if ($check_col && $check_col->num_rows === 0) {
    $conn->query("ALTER TABLE mensajes ADD COLUMN alternativas_enviadas TINYINT(1) DEFAULT 0");
}

// Buscar mensajes sin respuesta del vendedor por más de 20 minutos
$sql = "
SELECT m.id, m.conversacion_id, c.vendedor_id AS tutor_original_id, c.comprador_id, s.categoria,
       a_comprador.nombre AS nombre_comprador, a_comprador.correo AS correo_comprador,
       a_comprador.ultima_sesion AS ultima_sesion_comprador
FROM mensajes m
JOIN conversaciones c ON c.id = m.conversacion_id
JOIN servicios s ON s.id = c.servicio_id
JOIN alumnos a_vendedor ON a_vendedor.id = c.vendedor_id
JOIN alumnos a_comprador ON a_comprador.id = c.comprador_id
WHERE m.alternativas_enviadas = 0
  AND m.remitente_id = c.comprador_id
  AND TIMESTAMPDIFF(MINUTE, m.enviado_en, NOW()) >= 20
  AND a_vendedor.bloqueado = 0
  AND c.contrato_id IS NULL
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
    try {
        $alternativas = buscar_tutores_alternativos($conn, $row['categoria'], (int)$row['tutor_original_id']);

        if (empty($alternativas)) {
            // No hay ningún otro tutor en esta categoría todavía.
            // No se marca alternativas_enviadas: se reintenta solo si aparece uno nuevo.
            continue;
        }

        $primera = $alternativas[0];
        $url_destino = "https://nubira.cl/servicios/" . $primera['slug'] . "-" . (int)$primera['id'];

        $segundos_offline = time() - strtotime($row['ultima_sesion_comprador'] ?? '2000-01-01');
        $push_enviado = false;

        if ($segundos_offline > 30) {
            $res_push = enviar_push_nubira(
                (int)$row['comprador_id'],
                "Mientras esperas 👀",
                "Tu tutor puede estar ocupado. Te dejamos otras opciones de {$row['categoria']} que responden rápido.",
                $url_destino
            );
            if (!empty($res_push['success'])) $push_enviado = true;
        }

        if (!$push_enviado && $segundos_offline > 180 && !empty($row['correo_comprador'])) {
            $nombreDestino = explode(' ', trim($row['nombre_comprador']))[0];
            enviarCorreoAlternativasTutor(
                $row['correo_comprador'],
                $nombreDestino,
                $row['categoria'],
                $alternativas,
                $row['conversacion_id']
            );
        }

        $conn->query("UPDATE mensajes SET alternativas_enviadas = 1 WHERE id = {$row['id']}");
    } catch (\Throwable $e) {
        @file_put_contents(__DIR__ . '/../logs/push.log',
            "[" . date('Y-m-d H:i:s') . "] ERROR alternativas tutor msg {$row['id']}: " . $e->getMessage() . "\n",
            FILE_APPEND);
    }
}
