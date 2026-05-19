<?php
/**
 * CRON: Auto-cerrar contratos después de X horas
 * 
 * Regla:
 *  - estado = 'finalizado_vendedor'
 *  - fecha_cierre (cuando el profe marcó entregado) + 48h < ahora
 *  => pasa a 'finalizado_comprador'
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/Santiago');

require_once __DIR__ . '/conexion.php';

$horas = 48; // 🔧 cámbialo si quieres otro plazo

$sql = $conn->prepare("
    SELECT id
    FROM contratos
    WHERE estado = 'finalizado_vendedor'
      AND fecha_cierre IS NOT NULL
      AND fecha_cierre < (NOW() - INTERVAL ? HOUR)
");
$sql->bind_param("i", $horas);
$sql->execute();
$res = $sql->get_result();

$ids = [];
while ($row = $res->fetch_assoc()) {
    $ids[] = (int)$row['id'];
}
$sql->close();

if (empty($ids)) {
    // Nada que procesar
    exit;
}

$id_list = implode(',', $ids);

// 1) Actualizar estado masivo
$conn->query("
    UPDATE contratos
    SET estado = 'finalizado_comprador'
    WHERE id IN ($id_list)
");

// 2) Registrar eventos
if ($ev = $conn->prepare("
    INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle)
    VALUES (?, NULL, 'AUTO_CERRADO', 'Contrato auto-finalizado por tiempo de espera')
")) {
    foreach ($ids as $cid) {
        $ev->bind_param("i", $cid);
        $ev->execute();
    }
    $ev->close();
}

// (Opcional) aquí podrías disparar correos de “servicio finalizado automáticamente”
