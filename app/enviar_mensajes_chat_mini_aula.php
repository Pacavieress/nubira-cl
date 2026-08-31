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
// CAPA DLP NUBIRA (DATA LOSS PREVENTION) - ESTRICTO Y EDUCATIVO
// Igualado a enviar_mensaje.php (chat pre-contrato) — mismas categorías, mismo registro
// en dlp_intentos (conversacion_id de esa tabla se reutiliza para guardar el id_contrato,
// igual que ya hace enviar_mensaje.php en su propio path contexto=aula).
// =========================================================================================
require_once __DIR__ . '/helpers/dlp.php';

$mensaje_lower = mb_strtolower($mensaje, 'UTF-8');

// Núcleo reutilizado del patrón 'telefono' (check 5b más abajo) — vive en helpers/dlp.php.
$nucleo_digitos_tel = nb_dlp_nucleo_digitos_tel();
$patrones_bloqueo   = nb_dlp_patrones();

// Bloquea el mensaje y registra el intento en dlp_intentos (no debe romper el flujo si falla)
function nb_dlp_bloquear_aula($conn, $id_contrato, $usuario_id, $mensaje, $categoria, $pattern_desc) {
    try {
        $stmt_dlp = $conn->prepare(
            "INSERT INTO dlp_intentos (conversacion_id, remitente_id, categoria, patron_matched, texto_intentado)
             VALUES (?, ?, ?, ?, ?)"
        );
        if ($stmt_dlp) {
            $patron_safe = mb_substr($pattern_desc, 0, 200);
            $stmt_dlp->bind_param("iisss", $id_contrato, $usuario_id, $categoria, $patron_safe, $mensaje);
            $stmt_dlp->execute();
            $stmt_dlp->close();
        }
    } catch (Exception $e) {}

    $mensajes_dlp = [
        'email'              => '⚠️ Detectamos que intentaste compartir un correo electrónico. Por tu seguridad y la garantía de pago protegido, todo contacto debe quedar dentro de Nubira. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.',
        'telefono'           => '⚠️ Detectamos que intentaste compartir un número de teléfono. Por tu seguridad y la garantía de pago protegido, todo contacto debe quedar dentro de Nubira. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.',
        'redes'              => '⚠️ Detectamos que intentaste compartir una red social o app de mensajería externa. Por tu seguridad y la garantía de pago protegido, todo contacto debe quedar dentro de Nubira. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.',
        'banco'              => '⚠️ Detectamos que intentaste compartir datos bancarios o coordinar un pago fuera de Nubira. Los pagos solo deben hacerse a través de la plataforma para mantener tu Garantía Nubira. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.',
        'intencion_contacto' => '⚠️ Detectamos que intentaste coordinar contacto o encuentros fuera de Nubira. Por tu seguridad y la garantía de pago protegido, todo debe quedar dentro de la plataforma. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.',
        'identidad'          => '⚠️ Detectamos que intentaste compartir datos que permitirían identificarte o ser encontrado fuera de Nubira. Por tu seguridad, mantén la conversación dentro de la plataforma. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.',
        'urls'               => '⚠️ Detectamos que intentaste compartir un enlace externo. Por tu seguridad y la garantía de pago protegido, todo contacto debe quedar dentro de Nubira. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.',
    ];
    $mensaje_error = $mensajes_dlp[$categoria]
        ?? '⚠️ Por tu seguridad y la garantía de Nubira, no permitimos compartir identidad, redes ni medios de pago en el chat. Reescribe tu mensaje sin información externa.';

    echo json_encode([
        'success' => false,
        'error' => $mensaje_error
    ]);
    exit;
}

$categoria_bloqueada = nb_dlp_evaluar_patrones($mensaje_lower);
if ($categoria_bloqueada !== null) {
    nb_dlp_bloquear_aula($conn, $id_contrato, $usuario_id, $mensaje, $categoria_bloqueada, $patrones_bloqueo[$categoria_bloqueada]);
}

// 5b. "celular" con contexto: solo bloquea si aparece junto a una frase explícita de compartir número
//     o cerca (±25 caracteres, en unidades de caracteres UTF-8) de una secuencia de 7+ dígitos.
$pos_celular = mb_stripos($mensaje_lower, 'celular', 0, 'UTF-8');
if ($pos_celular !== false) {
    $inicio_ventana = max(0, $pos_celular - 25);
    $ventana = mb_substr($mensaje_lower, $inicio_ventana, 25 + mb_strlen('celular', 'UTF-8') + 25, 'UTF-8');
    $celular_frase = '/\b(mi|tu|su)\s+celular\b|\bn[uú]mero\s+celular\b/i';

    if (preg_match($celular_frase, $ventana) || preg_match('/' . $nucleo_digitos_tel . '/', $ventana)) {
        nb_dlp_bloquear_aula($conn, $id_contrato, $usuario_id, $mensaje, 'intencion_contacto', 'celular (con contexto)');
    }
}

// 5c. "juntémonos"/"reunámonos" con contexto: solo bloquea si el mensaje también menciona una
//     plataforma externa (Zoom/Meet/Teams/Skype/WhatsApp/Telegram/Discord).
if (preg_match('/\b(junt[eé]monos|reun[aá]monos)\b/i', $mensaje_lower)) {
    $patron_plataformas = '/\b(zoom|meet|teams|skype|wh?a[ts]+s?[aá]pp?|wasap|watsap|whsatap|guatsap|wsp|wa\.me|telegram|tg|t\.me|discord)\b/i';
    if (preg_match($patron_plataformas, $mensaje_lower)) {
        nb_dlp_bloquear_aula($conn, $id_contrato, $usuario_id, $mensaje, 'intencion_contacto', 'juntemonos/reunamonos + plataforma externa');
    }
}
// =========================================================================================

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