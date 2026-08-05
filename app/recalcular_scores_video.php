<?php
/**
 * Recálculo masivo de score_nubira — corre la función REAL y actual (actualizar_score_servicio())
 * para todos los servicios existentes, para que el nuevo casillero de video (+20) se refleje
 * de inmediato sin esperar a que el tutor edite algo.
 *
 * Ejecutar UNA vez, logueado como admin. Borrar después de correrlo (igual que migrar_scores.php).
 */
session_start();

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /vitrina'); exit;
}

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/helpers/usuario_helper.php';

echo "<h3>Recalculando score_nubira (incluye el nuevo casillero de video)...</h3>";

$res = $conn->query("SELECT id FROM servicios");
$actualizados = 0;
$fallidos = 0;

while ($row = $res->fetch_assoc()) {
    if (actualizar_score_servicio($conn, (int)$row['id'])) {
        $actualizados++;
    } else {
        $fallidos++;
    }
}

echo "<p>Listo. Actualizados: <b>$actualizados</b>, Fallidos: <b>$fallidos</b>.</p>";
echo "<p>Ya puedes borrar este archivo (recalcular_scores_video.php).</p>";
