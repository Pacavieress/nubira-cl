<?php
/**
 * BACKEND: SENSOR DE NOTIFICACIONES
 * CUENTA: Mensajes en 'chat_mensajes' que no sean míos y no estén vistos.
 */
header('Content-Type: application/json');
ini_set('display_errors', 0);
session_start();

// 1. Conexión a la Base de Datos
$rutas = [__DIR__ . '/conexion.php', dirname(__DIR__) . '/conexion.php', __DIR__ . '/../conexion.php'];
$found = false; foreach ($rutas as $r) { if(file_exists($r)){ require_once $r; $found=true; break; } }

if (!$found || !isset($_SESSION['usuario_id'])) { echo json_encode(['unread' => 0]); exit; }

$usuario_id = (int)$_SESSION['usuario_id'];
$id_contrato = (int)($_GET['id'] ?? 0);

if ($id_contrato <= 0) { echo json_encode(['unread' => 0]); exit; }

// 2. Consulta SQL (Tabla chat_mensajes)
// "Cuéntame cuántos mensajes hay en este contrato, que NO escribí yo, y que visto = 0"
$sql = "SELECT COUNT(*) as total FROM chat_aula 
        WHERE contrato_id = ? AND remitente_id != ? AND visto = 0";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $id_contrato, $usuario_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

// 3. Responder al Frontend
echo json_encode(['unread' => (int)$res['total']]);
?>