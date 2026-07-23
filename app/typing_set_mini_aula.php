<?php
/**
 * [NUBIRA 2.0] TYPING INDICATOR (AULA) - Marcar "estoy escribiendo"
 * Ubicación: public_html/app/typing_set_mini_aula.php
 * Calcado de typing_set.php, adaptado a contrato_id (chat_aula) en vez de
 * conversacion_id (mensajes) — usa tabla propia chat_typing_aula para no
 * compartir espacio de IDs con chat_typing (conversacion_id y contrato_id
 * son numeraciones independientes).
 *
 * Consumido por:
 *   - Web: JS de chat_mini_aula.php (cada 2s mientras tipean)
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
$id_contrato = (int)($_POST['id_contrato'] ?? 0);

if ($id_contrato <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Contrato inválido']);
    exit;
}

// Conexión (mismo patrón que chat_mini_aula.php)
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

// VERIFICACIÓN DE SEGURIDAD: El usuario debe pertenecer al contrato (aula)
$stmt_check = $conn->prepare("SELECT id FROM contratos WHERE id = ? AND (comprador_id = ? OR vendedor_id = ?) LIMIT 1");
$stmt_check->bind_param("iii", $id_contrato, $my_id, $my_id);
$stmt_check->execute();
$valida = $stmt_check->get_result()->fetch_assoc();
$stmt_check->close();

if (!$valida) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Contrato no autorizado']);
    exit;
}

// Auto-migración: nunca asumir que otro archivo ya se ejecutó antes y creó la tabla.
$conn->query("CREATE TABLE IF NOT EXISTS chat_typing_aula (
    contrato_id INT NOT NULL,
    usuario_id INT NOT NULL,
    ultima_actividad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (contrato_id, usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// INSERT o UPDATE: una sola query atómica
$stmt = $conn->prepare("
    INSERT INTO chat_typing_aula (contrato_id, usuario_id)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE ultima_actividad = CURRENT_TIMESTAMP
");
$stmt->bind_param("ii", $id_contrato, $my_id);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(['ok' => (bool)$ok]);
