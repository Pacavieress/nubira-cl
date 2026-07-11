<?php
/**
 * BACKEND: ENVIAR MENSAJE (V3.0 - SEGURIDAD ESTRICTA NUBIRA)
 */
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0); // Ocultar errores HTML
error_reporting(E_ALL);

// 1. BUSCADOR ROBUSTO DE CONEXIÓN
$rutas = [
    __DIR__ . '/conexion.php',
    dirname(__DIR__) . '/conexion.php',
    __DIR__ . '/../conexion.php'
];

$conectado = false;
foreach ($rutas as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        $conectado = true;
        break;
    }
}

if (!$conectado) {
    echo json_encode(['success' => false, 'error' => 'No se encuentra el archivo conexion.php']);
    exit;
}

session_start();

// 2. VALIDACIONES BÁSICAS
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sesión expirada']);
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];
$id_contrato = (int)($_POST['id_contrato'] ?? 0); 
$mensaje = trim($_POST['mensaje'] ?? '');

if ($id_contrato <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID Contrato inválido']);
    exit;
}
if (empty($mensaje)) {
    echo json_encode(['success' => false, 'error' => 'Mensaje vacío']);
    exit;
}

// BLOQUEO POR SUSPENSIÓN DEL REMITENTE (asimétrico: no bloquea si el OTRO participante está suspendido)
$stmt_susp = $conn->prepare("SELECT bloqueado FROM alumnos WHERE id = ? LIMIT 1");
$stmt_susp->bind_param("i", $usuario_id);
$stmt_susp->execute();
$fila_susp = $stmt_susp->get_result()->fetch_assoc();
$stmt_susp->close();
if (!empty($fila_susp['bloqueado'])) {
    echo json_encode(['success' => false, 'error' => 'Tu cuenta está suspendida temporalmente y no puede enviar mensajes.']);
    exit;
}

// =========================================================================================
// MODIFICACIÓN 1: FILTRO DE CONTACTOS Y REDES SOCIALES
// =========================================================================================
$mensaje_lower = mb_strtolower($mensaje, 'UTF-8');
$bloqueado = false;

// Regex para emails, teléfonos (8+ dígitos) y arrobas reales/redes
if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $mensaje_lower) ||
    preg_match('/(?:\d[\s\-\.]*){8,}/', $mensaje_lower) ||
    preg_match('/\b(whatsapp|wsp|wa\.me|instagram|insta|tiktok|facebook|telegram|t\.me|gmail|hotmail|outlook|yahoo|celular|fono|llámame|llamame|correo|email|zoom|meet|teams)\b/i', $mensaje_lower) ||
    preg_match('/(^|\s)@\w{3,}/', $mensaje_lower)) {
    $bloqueado = true;
}

if ($bloqueado) {
    echo json_encode([
        'success' => false, 
        'error' => 'Seguridad Nubira: No permitimos enviar redes sociales ni datos de contacto externo.'
    ]);
    exit; 
}

// =========================================================================================
// MODIFICACIÓN 2: VERIFICAR PERMISOS Y ESTADO DEL CONTRATO
// =========================================================================================
$stmt = $conn->prepare("SELECT estado FROM contratos WHERE id = ? AND (comprador_id = ? OR vendedor_id = ?) LIMIT 1");
$stmt->bind_param("iii", $id_contrato, $usuario_id, $usuario_id);
$stmt->execute();
$resultado = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$resultado) {
    echo json_encode(['success' => false, 'error' => 'Sin permiso en este chat']);
    exit;
}

// Evitar inyección de mensajes en contratos terminados
if (in_array($resultado['estado'], ['cancelado', 'finalizado', 'disputa'])) {
    echo json_encode(['success' => false, 'error' => 'El aula está cerrada.']);
    exit;
}

// =========================================================================================
// 4. INSERTAR EN BASE DE DATOS
// =========================================================================================
$sql = "INSERT INTO chat_aula (contrato_id, remitente_id, mensaje, fecha, visto) VALUES (?, ?, ?, NOW(), 0)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Error SQL temporal']);
    exit;
}

$stmt->bind_param("iis", $id_contrato, $usuario_id, $mensaje);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    error_log("Error de chat: " . $stmt->error);
    echo json_encode(['success' => false, 'error' => 'Fallo al procesar tu mensaje.']);
}
$stmt->close();
?>