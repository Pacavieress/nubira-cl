<?php
/**
 * [NUBIRA 2.0] TYPING INDICATOR - Marcar "estoy escribiendo"
 * Ubicación: public_html/app/typing_set.php
 * 
 * Consumido por:
 *   - Web: JS del chat_previo_contrato.php (cada 2s mientras tipean)
 *   - Futuro App Nativa: mismo endpoint vía fetch()
 * 
 * Respuesta JSON: { "ok": true }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ini_set('display_errors', 0);
session_start();

// Solo aceptamos POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

// Sesión obligatoria
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

$my_id = (int)$_SESSION['usuario_id'];
$conv_id = (int)($_POST['conversacion_id'] ?? 0);

if ($conv_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Conversación inválida']);
    exit;
}

// Conexión (mismo patrón que chat_previo_contrato.php)
$app_path = __DIR__;
$conn_paths = [$app_path . '/conexion.php', dirname($app_path) . '/conexion.php'];
$conn_loaded = false;
foreach ($conn_paths as $cp) {
    if (file_exists($cp)) { require_once $cp; $conn_loaded = true; break; }
}
if (!$conn_loaded) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de sistema']);
    exit;
}
$conn->set_charset("utf8mb4");

// Liberar sesión: no bloquea otros requests paralelos del mismo usuario
session_write_close();

// VERIFICACIÓN DE SEGURIDAD: El usuario debe pertenecer a la conversación
$stmt_check = $conn->prepare("SELECT id FROM conversaciones WHERE id = ? AND (comprador_id = ? OR vendedor_id = ?) LIMIT 1");
$stmt_check->bind_param("iii", $conv_id, $my_id, $my_id);
$stmt_check->execute();
$valida = $stmt_check->get_result()->fetch_assoc();
$stmt_check->close();

if (!$valida) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Conversación no autorizada']);
    exit;
}

// INSERT o UPDATE: una sola query atómica
// MySQL actualiza ultima_actividad automáticamente (ON UPDATE CURRENT_TIMESTAMP)
$stmt = $conn->prepare("
    INSERT INTO chat_typing (conversacion_id, usuario_id) 
    VALUES (?, ?) 
    ON DUPLICATE KEY UPDATE ultima_actividad = CURRENT_TIMESTAMP
");
$stmt->bind_param("ii", $conv_id, $my_id);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(['ok' => (bool)$ok]);