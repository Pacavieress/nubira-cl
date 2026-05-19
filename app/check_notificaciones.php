<?php
/**
 * API: SENSOR DE NOTIFICACIONES (STRICT SESSION)
 * UBICACIÓN: public_html/app/check_notificaciones.php
 */

ini_set('display_errors', 0);
header('Content-Type: application/json');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

session_start();

// CAMBIO CRÍTICO: Si no hay sesión, devolvemos error 401 (No autorizado)
// Esto evita que el JS piense que "hay 0 mensajes" si falla la cookie.
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); 
    echo json_encode(['error' => 'Sesión perdida o no enviada']);
    exit;
}

$my_id = (int)$_SESSION['usuario_id'];
session_write_close(); // Liberamos sesión para no bloquear

// Conexión DB
$dirs = [__DIR__, dirname(__DIR__)];
$conn_ok = false;
foreach($dirs as $d) { if(file_exists($d.'/conexion.php')) { require_once $d.'/conexion.php'; $conn_ok = true; break; } }

if (!$conn_ok) { http_response_code(500); echo json_encode(['error' => 'DB Error']); exit; }

// Consultas
$sql_chat = "SELECT COUNT(*) as total FROM mensajes m JOIN conversaciones c ON m.conversacion_id = c.id WHERE (c.comprador_id = $my_id OR c.vendedor_id = $my_id) AND m.remitente_id != $my_id AND m.leido = 0";

$sql_aula = "SELECT COUNT(*) as total FROM chat_aula ch JOIN contratos k ON ch.contrato_id = k.id WHERE (k.comprador_id = $my_id OR k.vendedor_id = $my_id) AND ch.remitente_id != $my_id AND ch.visto = 0";

$total = 0;
$res1 = $conn->query($sql_chat);
$res2 = $conn->query($sql_aula);

if ($res1 && $res2) {
    $row1 = $res1->fetch_assoc();
    $row2 = $res2->fetch_assoc();
    $total = (int)$row1['total'] + (int)$row2['total'];
    
    // Devolvemos el total y el ID para confirmar que la sesión está viva
    echo json_encode(['total' => $total, 'uid' => $my_id]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'SQL Error']);
}
?>