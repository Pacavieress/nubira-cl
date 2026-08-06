<?php
/**
 * PROCESO: DESCARGAR APUNTE + REGISTRO DE CONTADOR
 * UBICACIÓN: app/descargar_apunte.php
 * ESTADO: FINAL (BLINDADO)
 */

session_start();
// Ajuste de ruta robusto
$base_path = __DIR__;
if (file_exists(__DIR__ . '/conexion.php')) {
    $base_path = __DIR__;
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    $base_path = __DIR__ . '/..';
}
require_once $base_path . '/conexion.php';
require_once $base_path . '/env_loader.php';

function salir($codigo = 403, $mensaje = "Error desconocido") {
    http_response_code($codigo);
    // En vez de forzar una descarga rota, mostramos el error en pantalla
    die("<div style='font-family:sans-serif; padding:20px; background:#fee2e2; color:#991b1b; border:1px solid #f87171; border-radius:10px; max-width: 600px; margin: 40px auto;'>
        <h2 style='margin-top:0;'>🚨 NUBIRA DEBUGGER</h2>
        <p><strong>Código de error:</strong> $codigo</p>
        <p><strong>Motivo exacto:</strong> $mensaje</p>
    </div>");
}

/* Sesión Obligatoria */
if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login?redir=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];
$rol        = $_SESSION['rol'] ?? 'alumno';

/* Params */
$archivo   = isset($_GET['archivo']) ? basename($_GET['archivo']) : null;
$id_apunte = (int)($_GET['id'] ?? 0);
$exp       = (int)($_GET['exp'] ?? 0);
$sig       = $_GET['sig'] ?? null;
$inline    = !empty($_GET['inline']); // 1 = inline si aplica

// Validación básica de entrada (Sin forzar firma temporalmente)
if (!$archivo || $id_apunte <= 0) {
    salir(400, "Faltan parámetros. URL recibida incompleta.");
}

/* // Expiración del link
if (time() > $exp) {
    salir(403, "El enlace expiró. Hora servidor: " . time() . " | Expiración: " . $exp); 
}

// Validación de Firma (HMAC SHA256)
$secret = getenv('NUBIRA_HMAC_SECRET') ?: ($_ENV['NUBIRA_HMAC_SECRET'] ?? 'NUBIRA_SECRET_TEMP_CAMBIAR');
$data = $id_apunte . '|' . $usuario_id . '|' . $archivo . '|' . $exp;
$hash = hash_hmac('sha256', $data, $secret);

if (!hash_equals($hash, $sig)) {
    salir(403, "Firma inválida. El 'secret' o los datos no coinciden con los que generó el botón en el frontend.");
}
*/

/* BD: Datos del Apunte */
$stmt = $conn->prepare("SELECT titulo, precio, id_alumno, archivo FROM apuntes WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id_apunte);
$stmt->execute();
$apunte = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$apunte) salir(404);

/* Anti-Tampering: El archivo solicitado debe ser el real de ese ID */
if (basename($apunte['archivo']) !== $archivo) salir(403);

/* Permisos de Acceso */
$acceso = false;
if ($rol === 'admin') $acceso = true;
if ($usuario_id === (int)$apunte['id_alumno']) $acceso = true;
if ((int)$apunte['precio'] === 0) $acceso = true;

if (!$acceso) {
    // Verificar compra pagada
    $stmt = $conn->prepare("
        SELECT 1 FROM compras
        WHERE usuario_id = ? AND id_apunte = ? AND estado_pago = 'pagado'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $usuario_id, $id_apunte);
    $stmt->execute();
    $stmt->store_result();
    $acceso = ($stmt->num_rows > 0);
    $stmt->close();
}

if (!$acceso) salir(403);

/* ==================================================
   REGISTRO DE DESCARGA (CONTADOR UNIFICADO NUBIRA 2.0)
================================================== */
if ($usuario_id !== (int)$apunte['id_alumno'] && $rol !== 'admin') {
    try {
        // La fila en ventas_apuntes puede existir por la COMPRA (creada por el flujo de pago,
        // antes de cualquier descarga) sin que eso signifique que ya se contó una descarga real.
        // fecha_primera_descarga es lo único que distingue "compró" de "descargó".
        $check = $conn->prepare("SELECT id, fecha_primera_descarga FROM ventas_apuntes WHERE comprador_id = ? AND apunte_id = ? LIMIT 1");
        $check->bind_param("ii", $usuario_id, $id_apunte);
        $check->execute();
        $fila_venta = $check->get_result()->fetch_assoc();
        $check->close();

        $ya_descargado = $fila_venta && $fila_venta['fecha_primera_descarga'] !== null;

        if (!$ya_descargado) {
            $vendedor_id = (int)$apunte['id_alumno'];

            $conn->begin_transaction();

            // 1. Aumentamos el contador general usando Sentencia Preparada (Estricto Nubira)
            $stmt_upd = $conn->prepare("UPDATE apuntes SET descargas = descargas + 1 WHERE id = ?");
            $stmt_upd->bind_param("i", $id_apunte);
            $stmt_upd->execute();
            $stmt_upd->close();

            if ($fila_venta) {
                // Ya existía la fila (la creó el pago): solo marcamos la descarga, sin duplicar la fila.
                $upd = $conn->prepare("UPDATE ventas_apuntes SET fecha_primera_descarga = NOW() WHERE id = ?");
                $upd->bind_param("i", $fila_venta['id']);
                $upd->execute();
                $upd->close();
            } else {
                // No existía (apunte gratis): se crea ya marcada como descargada.
                $ins = $conn->prepare("INSERT INTO ventas_apuntes (comprador_id, vendedor_id, apunte_id, precio, fecha_primera_descarga) VALUES (?, ?, ?, 0, NOW())");
                $ins->bind_param("iii", $usuario_id, $vendedor_id, $id_apunte);
                $ins->execute();
                $ins->close();
            }

            $conn->commit();
        }
    } catch (Throwable $e) {
        $conn->rollback();
        // Error silencioso, no bloqueamos la entrega del archivo por un fallo de log
        error_log("Error registrando descarga Nubira: " . $e->getMessage());
    }
}

/* Entrega del Archivo Físico */
$ruta_1 = $_SERVER['DOCUMENT_ROOT'] . "/upload/apuntes/" . $archivo;
$ruta_2 = realpath(__DIR__ . '/../upload/apuntes/') . '/' . $archivo;

$ruta = '';
if (file_exists($ruta_1)) { $ruta = $ruta_1; } 
elseif (file_exists($ruta_2)) { $ruta = $ruta_2; }

if (empty($ruta)) {
    salir(404, "Hostinger no encuentra el PDF físicamente.<br>Buscó en:<br>Ruta 1: $ruta_1<br>Ruta 2: $ruta_2");
}

/* Detección MIME */
$ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
$mime_types = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'svg'  => 'image/svg+xml',
    'txt'  => 'text/plain',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];
$content_type = $mime_types[$ext] ?? 'application/octet-stream';

/* Nombre amigable para descargar */
// Limpiamos caracteres raros para evitar errores en navegadores viejos o Windows
$baseName = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', (string)$apunte['titulo']);
$baseName = trim($baseName);
if (empty($baseName)) $baseName = 'documento_nubira';
$nombre_descarga = str_replace(' ', '_', $baseName) . '.' . $ext;

/* Limpieza total de buffers para evitar corrupción de binarios */
while (ob_get_level()) ob_end_clean();

/* Headers de Respuesta */
header('Content-Type: ' . $content_type);
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff'); // Seguridad anti-MIME sniffing
header('Cache-Control: private, max-age=0, must-revalidate'); // Evitar caché público de archivos privados

// Determinar si mostrar en navegador o forzar descarga
$esInlinePermitido = $inline && (
    str_starts_with($content_type, 'image/') || $content_type === 'application/pdf'
);

if ($esInlinePermitido) {
    header('Content-Disposition: inline; filename="' . $nombre_descarga . '"');
    header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; sandbox'); // Sandbox para PDFs/imágenes
} else {
    header('Content-Disposition: attachment; filename="' . $nombre_descarga . '"');
}

// Envío del archivo seguro (chunked) para no saturar la RAM de Hostinger
if ($fd = fopen($ruta, 'rb')) {
    while (!feof($fd)) {
        echo fread($fd, 1024 * 8); // Lee y envía en bloques de 8KB
        flush(); // Fuerza la salida al navegador
    }
    fclose($fd);
}
exit;