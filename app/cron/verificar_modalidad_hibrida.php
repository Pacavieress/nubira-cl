<?php
/**
 * CRON: VERIFICAR PLAZO DE MODALIDAD HÍBRIDA (NUBIRA 100% ONLINE)
 *
 * Frecuencia recomendada: diaria
 * Ubicación: /app/cron/verificar_modalidad_hibrida.php
 *
 * Lógica:
 * - Servicios en modalidad Híbrido, avisados hace >= 7 días, aún visibles
 *   y no ocultos previamente por esta regla → se ocultan (visible=0).
 * - No se rechazan ni se marcan para revisión de admin, solo quedan ocultos
 *   hasta que el tutor edite el servicio (editar_servicio.php los restaura).
 */

if (php_sapi_name() !== 'cli' && !isset($_GET['cron_secret'])) {
    http_response_code(403);
    die('Forbidden');
}

require_once dirname(__DIR__) . '/env_loader.php';

$CRON_SECRET = getenv('MODALIDAD_HIBRIDA_CRON_SECRET') ?: '';
if (php_sapi_name() !== 'cli' && ($_GET['cron_secret'] ?? '') !== $CRON_SECRET) {
    http_response_code(403);
    die('Forbidden');
}

ini_set('display_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('America/Santiago');

$app_dir = dirname(__DIR__);
require_once $app_dir . '/conexion.php';

$stmt = $conn->prepare("
    UPDATE servicios
    SET visible = 0, oculto_por_modalidad = 1
    WHERE modalidad = 'Híbrido'
      AND (visible = 1 OR visible IS NULL)
      AND oculto_por_modalidad = 0
      AND aviso_modalidad_enviado_en IS NOT NULL
      AND aviso_modalidad_enviado_en <= (NOW() - INTERVAL 7 DAY)
");
$stmt->execute();
$afectados = $stmt->affected_rows;
$stmt->close();

echo "Servicios ocultos por plazo de modalidad vencido: $afectados\n";
