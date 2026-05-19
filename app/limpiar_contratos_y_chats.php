<?php
/**
 * CRON: Limpieza de contratos vencidos y conversaciones expiradas
 * Ejecución automática cada 15 minutos
 * 
 * - Expira contratos en estado 'borrador' que pasaron su límite.
 * - Archiva conversaciones vencidas y oculta sus mensajes.
 * - Borra conversaciones archivadas con más de 30 días.
 * - Registra cada ejecución en cron_log.txt.
 */

error_reporting(0);
date_default_timezone_set('America/Santiago');

// 🚫 Protección: solo ejecución por CLI (cron)
if (php_sapi_name() !== 'cli') {
    exit('Acceso restringido.');
}

require_once __DIR__ . '/conexion.php';
$conn->query("SET time_zone = '-03:00'"); // ← nuevo


// 🕒 Archivo de log
$log_file = __DIR__ . '/cron_log.txt';
$log_date = date('[Y-m-d H:i:s] ');

// Función auxiliar
function log_line($msg) {
    global $log_file, $log_date;
    file_put_contents($log_file, $log_date . $msg . PHP_EOL, FILE_APPEND);
}

// -------------------------------------------
// 1️⃣ Expirar contratos “borrador” vencidos
// -------------------------------------------
$sql = "
    SELECT id, conversacion_id
    FROM contratos
    WHERE estado = 'borrador'
      AND expires_at IS NOT NULL
      AND expires_at <= NOW()
";
$res = $conn->query($sql);
$expirados = 0;

while ($row = $res->fetch_assoc()) {
    $contrato_id = (int)$row['id'];
    $conv_id     = (int)$row['conversacion_id'];

    $conn->query("UPDATE contratos SET estado = 'expirado' WHERE id = $contrato_id");
    if ($conv_id > 0) {
        $conn->query("UPDATE conversaciones SET estado = 'archivada' WHERE id = $conv_id");
        $conn->query("UPDATE mensajes SET visible = 0 WHERE conversacion_id = $conv_id");
    }
    $expirados++;
}

// -------------------------------------------
// 2️⃣ Archivar conversaciones vencidas sin contrato
// -------------------------------------------
$sql2 = "
    SELECT c.id
    FROM conversaciones c
    LEFT JOIN contratos t 
           ON t.conversacion_id = c.id 
           AND t.estado IN ('borrador','pendiente_pago','pagado')
    WHERE c.expira_en IS NOT NULL
      AND c.expira_en <= NOW()
      AND t.id IS NULL
      AND c.estado = 'activa'
";
$res2 = $conn->query($sql2);
$archivadas = 0;

while ($row = $res2->fetch_assoc()) {
    $cid = (int)$row['id'];
    $conn->query("UPDATE conversaciones SET estado = 'archivada' WHERE id = $cid");
    $conn->query("UPDATE mensajes SET visible = 0 WHERE conversacion_id = $cid");
    $archivadas++;
}

// -------------------------------------------
// 3️⃣ Borrar definitivamente conversaciones archivadas viejas (30 días)
// -------------------------------------------
$conn->query("
    DELETE m FROM mensajes m
    JOIN conversaciones c ON c.id = m.conversacion_id
    WHERE c.estado = 'archivada'
      AND (c.ultima_interaccion IS NULL OR c.ultima_interaccion < NOW() - INTERVAL 30 DAY)
");

$del_conv = $conn->query("
    DELETE FROM conversaciones 
    WHERE estado = 'archivada'
      AND (ultima_interaccion IS NULL OR ultima_interaccion < NOW() - INTERVAL 30 DAY)
");

// -------------------------------------------
// 📄 Registro final
// -------------------------------------------
$msg = "✅ Limpieza completada: contratos expirados=$expirados, conversaciones archivadas=$archivadas";
echo $msg . PHP_EOL;
log_line($msg);
