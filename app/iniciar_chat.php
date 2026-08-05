<?php
/**
 * CONTROLADOR: INICIAR/RETOMAR CHAT PREVIO CONTRATO (NUBIRA 2.0)
 * UBICACIÓN: public_html/app/iniciar_chat.php
 * CORRECCIÓN: Fix Error 500 (Mapeo correcto de columna 'alumno_id' + Blindaje Anti-Fatal Errors)
 */

// 1. CONFIGURACIÓN Y SESIÓN
// Evitamos que un error fatal lance un 500 ciego. Si hay error, lo loguea o muestra texto plano.
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

// 2. CONEXIÓN AL ESTILO NUBIRA (Buscador de rutas robusto)
$ruta_raiz = '';
if (file_exists(__DIR__ . '/conexion.php')) { $ruta_raiz = __DIR__; } 
elseif (file_exists(dirname(__DIR__) . '/app/conexion.php')) { $ruta_raiz = dirname(__DIR__) . '/app'; } 
elseif (file_exists(dirname(__DIR__) . '/conexion.php')) { $ruta_raiz = dirname(__DIR__); } 
else { die("Error Crítico: No se encuentra conexion.php."); }

require_once $ruta_raiz . '/conexion.php';
$conn->set_charset("utf8mb4");

// 3. SEGURIDAD EXTREMA
if (!isset($_SESSION['usuario_id'])) { 
    // Redirige al login, y tras loguearse, vuelve a intentar iniciar el chat
    header("Location: /login?redir=" . urlencode("/app/iniciar_chat.php?" . $_SERVER['QUERY_STRING'])); 
    exit; 
}

$comprador_id    = (int)$_SESSION['usuario_id'];
$servicio_id     = (int)($_POST['servicio_id'] ?? $_GET['servicio_id'] ?? 0);
$mensaje_inicial = mb_substr(trim($_POST['mensaje_inicial'] ?? ''), 0, 1000, 'UTF-8');

if ($servicio_id <= 0) {
    header("Location: /vitrina"); // Redirección silenciosa ante manipulación de URL
    exit;
}

// 4. VALIDAR SERVICIO Y OBTENER AL VENDEDOR
// [CORRECCIÓN APLICADA]: Usamos 'alumno_id' que es el estándar de la tabla servicios en Nubira.
$sql_servicio = "SELECT id, alumno_id AS vendedor_id FROM servicios WHERE id = ? LIMIT 1";
$stmt = $conn->prepare($sql_servicio);

if (!$stmt) {
    // Safety Net: Evita el Error 500 mostrando qué falló exactamente
    die("Error interno SQL (Servicios): " . $conn->error);
}

$stmt->bind_param("i", $servicio_id);
$stmt->execute();
$servicio = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$servicio) {
    die("El servicio solicitado no existe o fue eliminado.");
}

$vendedor_id = (int)$servicio['vendedor_id'];

// Regla de Negocio: Un usuario no puede iniciar un chat consigo mismo
if ($comprador_id === $vendedor_id) {
    header("Location: /detalle_servicio.php?id=" . $servicio_id);
    exit;
}

// 5. DETECCIÓN DE ESTADO (Idempotencia)
// Verificamos si ya existe un hilo de conversación previo para no duplicar chats.
$sql_check = "
    SELECT c.id,
           (SELECT MAX(m.enviado_en) FROM mensajes m WHERE m.conversacion_id = c.id) AS ultimo_mensaje
    FROM conversaciones c
    WHERE c.servicio_id = ? AND c.comprador_id = ? AND c.vendedor_id = ?
    LIMIT 1
";
$stmt_check = $conn->prepare($sql_check);

if (!$stmt_check) {
    die("Error interno SQL (Conversaciones): " . $conn->error);
}

$stmt_check->bind_param("iii", $servicio_id, $comprador_id, $vendedor_id);
$stmt_check->execute();
$chat_existente = $stmt_check->get_result()->fetch_assoc();
$stmt_check->close();

// Conversación vencida: mismo servicio/comprador/vendedor, pero el último mensaje real
// tiene 7 días o más. NO se toca ni se archiva la vieja — solo se deja de reutilizar.
$conversacion_vencida = false;
if ($chat_existente && !empty($chat_existente['ultimo_mensaje'])) {
    $dias_desde_ultimo = (time() - strtotime($chat_existente['ultimo_mensaje'])) / 86400;
    $conversacion_vencida = ($dias_desde_ultimo >= 7);
}

if ($chat_existente && !$conversacion_vencida) {
    $chat_id_final = (int)$chat_existente['id'];
    if (!empty($mensaje_inicial)) {
        $stmt_msg = $conn->prepare("INSERT INTO mensajes (conversacion_id, remitente_id, mensaje, enviado_en) VALUES (?, ?, ?, NOW())");
        $stmt_msg->bind_param("iis", $chat_id_final, $comprador_id, $mensaje_inicial);
        $stmt_msg->execute();
        $stmt_msg->close();
    }
    header("Location: /app/chat_previo_contrato.php?id=" . $chat_id_final);
    exit;
}

// 6. CREACIÓN DE NUEVA CONVERSACIÓN (Onboarding Transparente)
// Se llega acá tanto si no existía ninguna conversación como si la existente venció (7+ días).
$sql_insert = "INSERT INTO conversaciones (servicio_id, comprador_id, vendedor_id) VALUES (?, ?, ?)";
$stmt_insert = $conn->prepare($sql_insert);

if (!$stmt_insert) {
    die("Error interno SQL (Insert Chat): " . $conn->error);
}

$stmt_insert->bind_param("iii", $servicio_id, $comprador_id, $vendedor_id);

if ($stmt_insert->execute()) {
    $nuevo_chat_id = $stmt_insert->insert_id;
    $stmt_insert->close();
    if (!empty($mensaje_inicial)) {
        $stmt_msg = $conn->prepare("INSERT INTO mensajes (conversacion_id, remitente_id, mensaje, enviado_en) VALUES (?, ?, ?, NOW())");
        $stmt_msg->bind_param("iis", $nuevo_chat_id, $comprador_id, $mensaje_inicial);
        $stmt_msg->execute();
        $stmt_msg->close();
    }
    // Escenario B: Chat nuevo creado. Redirigimos al entorno UI/UX.
    header("Location: /app/chat_previo_contrato.php?id=" . $nuevo_chat_id);
    exit;
} else {
    die("Error de escritura al inicializar la sala de chat.");
}
?>