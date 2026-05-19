<?php
/**
 * [NUBIRA 2.0] CRON JOB: Limpieza tabla chat_typing
 * Ubicación: public_html/app/cron/limpiar_typing.php
 * 
 * Frecuencia recomendada: 1 vez al día (ej: 4:00 AM)
 * 
 * La lógica de typing solo considera los últimos 4 segundos.
 * Cualquier fila con más de 1 hora de antigüedad es 100% descartable.
 * 
 * Patrón App Nativa: endpoint también invocable vía HTTP con token.
 */

// Seguridad: solo CLI o con token secreto vía HTTP
$es_cli = (php_sapi_name() === 'cli');
$token_valido = isset($_GET['token']) && $_GET['token'] === 'nbr_7k4xP9wQ2mRvT8nHsA3cZ5bYfL6dK1jE';

if (!$es_cli && !$token_valido) {
    http_response_code(403);
    exit('Acceso denegado');
}

// Conexión
$app_path = dirname(__DIR__);
require_once $app_path . '/conexion.php';
$conn->set_charset("utf8mb4");

// Borrado con prepared statement (respeta estándar Nubira 2.0)
$stmt = $conn->prepare("DELETE FROM chat_typing WHERE ultima_actividad < (NOW() - INTERVAL 1 HOUR)");
$stmt->execute();
$filas_borradas = $stmt->affected_rows;
$stmt->close();

// Log opcional (útil para verificar que el cron corre)
$mensaje = "[" . date('Y-m-d H:i:s') . "] Limpieza chat_typing: {$filas_borradas} filas borradas\n";

if ($es_cli) {
    echo $mensaje;
} else {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'filas_borradas' => $filas_borradas]);
}