<?php
/**
 * NUBIRA 2.0 — ENDPOINT DE SUBIDA DE ARCHIVOS EN CHAT
 * Ubicación: /public_html/app/enviar_archivo.php
 * 
 * Recibe un archivo vía POST multipart, lo valida con 7 capas de seguridad,
 * lo guarda en /app/chat_archivos/{conversacion_id}/ y registra el mensaje en BD.
 * 
 * Respuesta JSON: { success: bool, error?: string, mensaje_id?: int }
 */

// ----------------------------------------------------------------
// 0. CONFIGURACIÓN BASE
// ----------------------------------------------------------------
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/conexion.php';
$conn->set_charset("utf8mb4");

// Helper de respuesta uniforme (API-first ready)
function responder($success, $extra = []) {
    echo json_encode(array_merge(['success' => $success], $extra));
    exit;
}

// ----------------------------------------------------------------
// CAPA 1: SESIÓN
// ----------------------------------------------------------------
if (!isset($_SESSION['usuario_id'])) {
    responder(false, ['error' => 'Sesión expirada. Recarga la página.']);
}
$my_id = (int)$_SESSION['usuario_id'];

// Validar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(false, ['error' => 'Método no permitido.']);
}

// ----------------------------------------------------------------
// VALIDAR INPUTS BÁSICOS
// ----------------------------------------------------------------
$conversacion_id = (int)($_POST['conversacion_id'] ?? 0);
if ($conversacion_id <= 0) {
    responder(false, ['error' => 'Conversación no válida.']);
}

if (empty($_FILES['archivo']) || !isset($_FILES['archivo']['error'])) {
    responder(false, ['error' => 'No llegó ningún archivo.']);
}

$archivo = $_FILES['archivo'];

// Manejar errores de PHP en la subida
if ($archivo['error'] !== UPLOAD_ERR_OK) {
    $errores_php = [
        UPLOAD_ERR_INI_SIZE   => 'El archivo supera el límite del servidor.',
        UPLOAD_ERR_FORM_SIZE  => 'El archivo es demasiado grande.',
        UPLOAD_ERR_PARTIAL    => 'El archivo se subió incompleto. Reintenta.',
        UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo.',
        UPLOAD_ERR_NO_TMP_DIR => 'Error temporal del servidor.',
        UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo.',
        UPLOAD_ERR_EXTENSION  => 'Subida bloqueada por el servidor.',
    ];
    $msg = $errores_php[$archivo['error']] ?? 'Error desconocido al subir.';
    responder(false, ['error' => $msg]);
}

// Validación extra: que sea un archivo realmente subido vía HTTP
if (!is_uploaded_file($archivo['tmp_name'])) {
    responder(false, ['error' => 'Archivo inválido.']);
}

// ----------------------------------------------------------------
// CAPA 2: AUTORIZACIÓN — verificar que soy parte de esta conversación
// ----------------------------------------------------------------
$stmt = $conn->prepare("
    SELECT comprador_id, vendedor_id 
    FROM conversaciones 
    WHERE id = ? AND (comprador_id = ? OR vendedor_id = ?)
    LIMIT 1
");
$stmt->bind_param("iii", $conversacion_id, $my_id, $my_id);
$stmt->execute();
$conv = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$conv) {
    responder(false, ['error' => 'No tienes acceso a esta conversación.']);
}

// ----------------------------------------------------------------
// CAPA 3: TUTOR INACTIVO (>48h) — solo aplica si SOY comprador
// ----------------------------------------------------------------
$soy_comprador = ($conv['comprador_id'] == $my_id);

if ($soy_comprador) {
    $stmt = $conn->prepare("
        SELECT remitente_id, enviado_en 
        FROM mensajes 
        WHERE conversacion_id = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->bind_param("i", $conversacion_id);
    $stmt->execute();
    $ultimo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($ultimo && $ultimo['remitente_id'] == $my_id && $ultimo['enviado_en']) {
        $horas = (time() - strtotime($ultimo['enviado_en'])) / 3600;
        if ($horas >= 48) {
            responder(false, ['error' => 'Chat pausado por inactividad del tutor.']);
        }
    }
}

// ----------------------------------------------------------------
// CAPA 4: PESO (10 MB)
// ----------------------------------------------------------------
$PESO_MAX = 10 * 1024 * 1024; // 10 MB en bytes
if ($archivo['size'] > $PESO_MAX) {
    responder(false, ['error' => 'El archivo no debe superar los 10 MB.']);
}
if ($archivo['size'] <= 0) {
    responder(false, ['error' => 'El archivo está vacío.']);
}

// ----------------------------------------------------------------
// CAPA 5: EXTENSIÓN (whitelist)
// ----------------------------------------------------------------
$EXT_PERMITIDAS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

$nombre_original = $archivo['name'];
$extension = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));

if (!in_array($extension, $EXT_PERMITIDAS, true)) {
    responder(false, ['error' => 'Solo se permiten imágenes (JPG, PNG, WebP) y PDF.']);
}

// ----------------------------------------------------------------
// CAPA 6: MIME REAL (lectura de bytes reales con finfo)
// ----------------------------------------------------------------
$MIME_PERMITIDOS = [
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'webp' => ['image/webp'],
    'pdf'  => ['application/pdf'],
];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_real = finfo_file($finfo, $archivo['tmp_name']);
finfo_close($finfo);

if (!$mime_real || !in_array($mime_real, $MIME_PERMITIDOS[$extension], true)) {
    responder(false, ['error' => 'El contenido del archivo no coincide con su extensión.']);
}

// ----------------------------------------------------------------
// CAPA 7: NOMBRE SANITIZADO (anti path traversal)
// ----------------------------------------------------------------
// Generamos un nombre nuevo aleatorio. Nunca confiamos en el nombre del usuario.
try {
    $nombre_seguro = bin2hex(random_bytes(16)) . '.' . $extension;
} catch (Exception $e) {
    responder(false, ['error' => 'Error de servidor. Reintenta.']);
}

// ----------------------------------------------------------------
// GUARDAR ARCHIVO EN DISCO
// ----------------------------------------------------------------
$dir_base = __DIR__ . '/chat_archivos';
$dir_chat = $dir_base . '/' . $conversacion_id;

if (!is_dir($dir_chat)) {
    if (!mkdir($dir_chat, 0755, true)) {
        responder(false, ['error' => 'No se pudo preparar el directorio.']);
    }
}

$ruta_destino = $dir_chat . '/' . $nombre_seguro;

if (!move_uploaded_file($archivo['tmp_name'], $ruta_destino)) {
    responder(false, ['error' => 'No se pudo guardar el archivo.']);
}

// Permisos restrictivos al archivo recién creado
@chmod($ruta_destino, 0644);

// ----------------------------------------------------------------
// GUARDAR EN BASE DE DATOS
// ----------------------------------------------------------------
// Sanitizamos el nombre original SOLO para mostrarlo, nunca para usarlo en disco
$nombre_mostrar = mb_substr(
    preg_replace('/[^\p{L}\p{N}\s\-_.()]/u', '', $nombre_original), 
    0, 255
);
if (empty($nombre_mostrar)) {
    $nombre_mostrar = 'archivo.' . $extension;
}

// Ruta relativa que guardamos en BD (no es URL pública, solo referencia interna)
$ruta_relativa = $conversacion_id . '/' . $nombre_seguro;

$stmt = $conn->prepare("
    INSERT INTO mensajes 
        (conversacion_id, remitente_id, mensaje, archivo_nombre, archivo_ruta, archivo_tipo, archivo_peso, enviado_en)
    VALUES 
        (?, ?, '', ?, ?, ?, ?, NOW())
");

$peso_bytes = (int)$archivo['size'];
$stmt->bind_param(
    "iisssi",
    $conversacion_id,
    $my_id,
    $nombre_mostrar,
    $ruta_relativa,
    $mime_real,
    $peso_bytes
);

if (!$stmt->execute()) {
    // Si falla la BD, borramos el archivo para no dejar huérfanos
    @unlink($ruta_destino);
    responder(false, ['error' => 'No se pudo registrar el mensaje.']);
}

$mensaje_id = $stmt->insert_id;
$stmt->close();

// ----------------------------------------------------------------
// RESPUESTA OK
// ----------------------------------------------------------------
responder(true, [
    'mensaje_id'   => $mensaje_id,
    'archivo_tipo' => $mime_real,
    'archivo_nombre' => $nombre_mostrar,
]);