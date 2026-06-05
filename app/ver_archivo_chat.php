<?php
/**
 * NUBIRA 2.0 — ENDPOINT DE DESCARGA AUTORIZADA DE ARCHIVOS DE CHAT
 * Ubicación: /public_html/app/ver_archivo.php
 *
 * Sirve archivos adjuntos del chat solo a participantes autorizados.
 * Uso: /app/ver_archivo.php?m=123          (modo inline, default)
 *      /app/ver_archivo.php?m=123&dl=1     (forzar descarga)
 */

ini_set('display_errors', 0);
session_start();

require_once __DIR__ . '/conexion.php';
$conn->set_charset("utf8mb4");

// Helper: respuesta de error genérica (no damos pistas a atacantes)
function denegar($code = 403) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $code === 404 ? 'Archivo no encontrado.' : 'Acceso denegado.';
    exit;
}

// ----------------------------------------------------------------
// 1. SESIÓN
// ----------------------------------------------------------------
if (!isset($_SESSION['usuario_id'])) {
    denegar(403);
}
$my_id = (int)$_SESSION['usuario_id'];

// ----------------------------------------------------------------
// 2. INPUT
// ----------------------------------------------------------------
$mensaje_id = (int)($_GET['m'] ?? 0);
$forzar_descarga = !empty($_GET['dl']);

if ($mensaje_id <= 0) {
    denegar(404);
}

// ----------------------------------------------------------------
// 3. AUTORIZACIÓN — solo participantes de la conversación
// ----------------------------------------------------------------
// Verificar si el usuario es admin
$es_admin = (($_SESSION['rol'] ?? '') === 'admin');

if ($es_admin) {
    // Admin: acceso a cualquier archivo (auditoría)
    $stmt = $conn->prepare("
        SELECT 
            m.archivo_nombre,
            m.archivo_ruta,
            m.archivo_tipo,
            m.archivo_peso,
            c.comprador_id,
            c.vendedor_id
        FROM mensajes m
        JOIN conversaciones c ON c.id = m.conversacion_id
        WHERE m.id = ?
          AND m.archivo_ruta IS NOT NULL
        LIMIT 1
    ");
    $stmt->bind_param("i", $mensaje_id);
} else {
    // Usuario normal: solo si es participante de la conversación
    $stmt = $conn->prepare("
        SELECT 
            m.archivo_nombre,
            m.archivo_ruta,
            m.archivo_tipo,
            m.archivo_peso,
            c.comprador_id,
            c.vendedor_id
        FROM mensajes m
        JOIN conversaciones c ON c.id = m.conversacion_id
        WHERE m.id = ?
          AND m.archivo_ruta IS NOT NULL
          AND (c.comprador_id = ? OR c.vendedor_id = ?)
        LIMIT 1
    ");
    $stmt->bind_param("iii", $mensaje_id, $my_id, $my_id);
}
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    denegar(403);
}

// Cerramos la sesión cuanto antes para no bloquear otras peticiones del usuario
session_write_close();

// ----------------------------------------------------------------
// 4. VALIDAR ARCHIVO EN DISCO
// ----------------------------------------------------------------
$ruta_real = realpath(__DIR__ . '/chat_archivos/' . $row['archivo_ruta']);
$dir_base  = realpath(__DIR__ . '/chat_archivos');

// Defensa anti path-traversal: el archivo debe estar DENTRO de chat_archivos
if (!$ruta_real || !$dir_base || strpos($ruta_real, $dir_base) !== 0) {
    denegar(404);
}

if (!is_file($ruta_real) || !is_readable($ruta_real)) {
    denegar(404);
}

// ----------------------------------------------------------------
// 5. ENTREGAR ARCHIVO
// ----------------------------------------------------------------
$mime  = $row['archivo_tipo'] ?: 'application/octet-stream';
$nombre = $row['archivo_nombre'] ?: 'archivo';
$peso  = filesize($ruta_real);

// Whitelist de MIMEs que servimos como inline (resto se fuerza a descargar)
$inline_seguros = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
$disposition = ($forzar_descarga || !in_array($mime, $inline_seguros, true))
    ? 'attachment'
    : 'inline';

// Sanitizamos nombre para el header (evita inyección de headers)
$nombre_header = preg_replace('/[\r\n"]/', '', $nombre);

// Headers de seguridad y entrega
header('Content-Type: ' . $mime);
header('Content-Length: ' . $peso);
header('Content-Disposition: ' . $disposition . '; filename="' . $nombre_header . '"');
header('X-Content-Type-Options: nosniff');                      // navegador no adivina tipo
header('Content-Security-Policy: default-src \'none\'');        // no ejecuta nada
header('Cache-Control: private, max-age=3600');                 // cache solo en cliente, 1h
header('Pragma: private');

// Limpiar buffers antes de readfile para archivos grandes
while (ob_get_level() > 0) ob_end_clean();

readfile($ruta_real);
exit;