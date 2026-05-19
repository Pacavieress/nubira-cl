<?php
/**
 * BACKEND: CARGAR MENSAJES (RENDERIZADO HTML - NUBIRA 2.0)
 * UBICACIÓN: public_html/app/cargar_mensajes.php
 */

ini_set('display_errors', 0);
session_start();

// [NUBIRA 2.0] Exponer header custom al JS del cliente (necesario para typing indicator)
header('Access-Control-Expose-Headers: X-Typing-Otro');

// 1. CONEXIÓN ROBUSTA
$dirs = [__DIR__, dirname(__DIR__)];
$found = false;
foreach ($dirs as $dir) {
    if (file_exists($dir . '/conexion.php')) {
        require_once $dir . '/conexion.php';
        $found = true;
        break;
    }
}
if (!$found) exit;

// 2. SEGURIDAD
if (!isset($_SESSION['usuario_id'])) exit;

$usuario_id = (int)$_SESSION['usuario_id'];
$id_ref     = (int)($_GET['id'] ?? $_GET['chat_id'] ?? 0);
$contexto   = $_GET['contexto'] ?? 'conversacion';
if ($id_ref <= 0) exit;

// [NUBIRA 2.0 SHIELD] Validación estricta de contexto — previene cualquier manipulación
$contextos_permitidos = ['conversacion', 'aula'];
if (!in_array($contexto, $contextos_permitidos, true)) exit;

// 3. CONFIGURACIÓN (AULA vs CHAT)
if ($contexto === 'aula') {
    $tabla     = 'chat_aula';
    $col_id    = 'contrato_id';
    $col_fecha = 'fecha';
    $col_visto = 'visto';
} else {
    $tabla     = 'mensajes';
    $col_id    = 'conversacion_id';
    $col_fecha = 'enviado_en';
    $col_visto = 'leido';
}

// 4. VERIFICAR PERMISOS (con sentencia preparada)
$tabla_permisos = ($contexto === 'aula') ? 'contratos' : 'conversaciones';
$sql_check = "SELECT id FROM {$tabla_permisos} WHERE id = ? AND (comprador_id = ? OR vendedor_id = ?) LIMIT 1";
$stmt_check = $conn->prepare($sql_check);
$stmt_check->bind_param("iii", $id_ref, $usuario_id, $usuario_id);
$stmt_check->execute();
$autorizado = $stmt_check->get_result()->num_rows > 0;
$stmt_check->close();
if (!$autorizado) exit;

// 5. MARCAR COMO LEÍDOS (Mensajes ajenos) - con sentencia preparada
$sql_update = "UPDATE {$tabla} SET {$col_visto} = 1 WHERE {$col_id} = ? AND remitente_id != ?";
$stmt_update = $conn->prepare($sql_update);
$stmt_update->bind_param("ii", $id_ref, $usuario_id);
$stmt_update->execute();
$stmt_update->close();

// 5.5 [NUBIRA 2.0 TYPING INDICATOR] Detectar si el otro está escribiendo
$otro_escribiendo = 0;
if ($contexto !== 'aula') {
    $stmt_typing = $conn->prepare("
        SELECT 1 FROM chat_typing 
        WHERE conversacion_id = ? 
          AND usuario_id != ? 
          AND ultima_actividad > (NOW() - INTERVAL 4 SECOND)
        LIMIT 1
    ");
    if ($stmt_typing) {
        $stmt_typing->bind_param("ii", $id_ref, $usuario_id);
        $stmt_typing->execute();
        $otro_escribiendo = $stmt_typing->get_result()->num_rows > 0 ? 1 : 0;
        $stmt_typing->close();
    }
}

header('X-Typing-Otro: ' . $otro_escribiendo);

// 6. RENDERIZAR HTML — Delegamos en el componente render_mensajes.php
$sql = "SELECT *, {$col_fecha} AS fecha_real, {$col_visto} AS estado_visto 
        FROM {$tabla} 
        WHERE {$col_id} = ? 
        ORDER BY {$col_fecha} ASC";
$stmt_msg = $conn->prepare($sql);
$stmt_msg->bind_param("i", $id_ref);
$stmt_msg->execute();
$res = $stmt_msg->get_result();

// Convertir resultado a array (lo que espera render_mensajes.php)
$mensajes = [];
while ($msg = $res->fetch_assoc()) {
    $mensajes[] = $msg;
}
$stmt_msg->close();

// [NUBIRA 2.0] Renderizado delegado al componente reutilizable
// Variables disponibles en el componente: $mensajes, $usuario_id
require __DIR__ . '/render_mensajes.php';
