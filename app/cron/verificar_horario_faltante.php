<?php
/**
 * CRON: VERIFICAR PLAZO DE HORARIO FALTANTE
 *
 * Frecuencia recomendada: diaria
 * Ubicación: /app/cron/verificar_horario_faltante.php
 *
 * Lógica:
 * - Servicios avisados hace >= 30 días, aún sin horario, aún visibles
 *   y no ocultos previamente por esta regla → se ocultan (visible=0).
 * - No se rechazan ni se marcan para revisión de admin, solo quedan ocultos
 *   hasta que el tutor cargue el horario (editar_horarios.php los restaura).
 */

if (php_sapi_name() !== 'cli' && !isset($_GET['cron_secret'])) {
    http_response_code(403);
    die('Forbidden');
}

require_once dirname(__DIR__) . '/env_loader.php';

$CRON_SECRET = getenv('HORARIO_CRON_SECRET') ?: '';
if (php_sapi_name() !== 'cli' && ($_GET['cron_secret'] ?? '') !== $CRON_SECRET) {
    http_response_code(403);
    die('Forbidden');
}

ini_set('display_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('America/Santiago');

$app_dir = dirname(__DIR__);
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/helpers/horarios.php';

$res = $conn->query("
    SELECT id, horarios_json
    FROM servicios
    WHERE (visible = 1 OR visible IS NULL)
      AND oculto_por_falta_horario = 0
      AND aviso_horario_enviado_en IS NOT NULL
      AND aviso_horario_enviado_en <= (NOW() - INTERVAL 30 DAY)
");

$afectados = 0;
$ids_ocultar = [];
while ($row = $res->fetch_assoc()) {
    if (!parsear_horarios_servicio($row['horarios_json'])['tiene_horarios']) {
        $ids_ocultar[] = (int)$row['id'];
    }
}

if ($ids_ocultar) {
    $placeholders = implode(',', array_fill(0, count($ids_ocultar), '?'));
    $tipos = str_repeat('i', count($ids_ocultar));
    $stmt = $conn->prepare("UPDATE servicios SET visible = 0, oculto_por_falta_horario = 1 WHERE id IN ($placeholders)");
    $stmt->bind_param($tipos, ...$ids_ocultar);
    $stmt->execute();
    $afectados = $stmt->affected_rows;
    $stmt->close();
}

echo "Servicios ocultos por plazo de horario vencido: $afectados\n";
