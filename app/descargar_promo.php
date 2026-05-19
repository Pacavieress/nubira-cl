<?php
/**
 * CONTROLADOR: DESCARGA PROMO FLASH (FOMO) - NUBIRA 2.0
 * Archivo independiente. Desencripta el ID y maneja la concurrencia.
 */
session_start();

// 1. CONEXIÓN ESTÁNDAR NUBIRA
$base_path = __DIR__;
if (file_exists(__DIR__ . '/conexion.php')) {
    $base_path = __DIR__;
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    $base_path = __DIR__ . '/..';
}
require_once $base_path . '/conexion.php';

// [NUBIRA SHIELD] Cargar enmascarador de URLs
$rutas_shield = [$base_path . '/seguridad_url.php', dirname($base_path) . '/app/seguridad_url.php', $_SERVER['DOCUMENT_ROOT'] . '/app/seguridad_url.php'];
foreach ($rutas_shield as $rs) {
    if (file_exists($rs)) {
        require_once $rs;
        break;
    }
}

// 2. DESCIFRAR EL ID DEL APUNTE
$param_id = $_GET['id'] ?? '';
$id_apunte = 0;

if (is_numeric($param_id)) {
    $id_apunte = (int)$param_id;
} else {
    if (function_exists('nubira_desencriptar_id')) {
        $id_apunte = (int)nubira_desencriptar_id($param_id);
    }
}

if ($id_apunte <= 0) {
    header("Location: /?error=link_invalido");
    exit;
}

// 3. BUSCAR EL APUNTE Y SU ESTADO DE PROMO
$stmt = $conn->prepare("SELECT titulo, archivo, promo_gratis, promo_limite, promo_contador FROM apuntes WHERE id = ? AND estado = 'aprobado' LIMIT 1");
$stmt->bind_param("i", $id_apunte);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    header("Location: /?error=no_encontrado");
    exit;
}

$apunte = $res->fetch_assoc();
$stmt->close();

// 4. MOTOR DE CONCURRENCIA (El Guardia de Seguridad)
$acceso_concedido = false;

// Casting seguro para evitar warnings en PHP
$contador_actual = (int)($apunte['promo_contador'] ?? 0);
$limite = (int)($apunte['promo_limite'] ?? 0);

if (isset($apunte['promo_gratis']) && $apunte['promo_gratis'] == 1 && $contador_actual < $limite) {
    
    // FIX CRÍTICO: COALESCE(campo, 0) previene que valores NULL arruinen la aritmética de MySQL
   $stmt_upd = $conn->prepare("UPDATE apuntes SET promo_contador = COALESCE(promo_contador, 0) + 1, descargas = descargas + 1 WHERE id = ? AND promo_gratis = 1 AND COALESCE(promo_contador, 0) < promo_limite");
    $stmt_upd->bind_param("i", $id_apunte);
    $stmt_upd->execute();
    
    // Si affected_rows es 1, ganamos la carrera de concurrencia
    if ($stmt_upd->affected_rows === 1) {
        $acceso_concedido = true;
        
        // Auto-apagado: Usar Prepared Statement por seguridad, no concatenación directa
        if (($contador_actual + 1) >= $limite) {
            $stmt_off = $conn->prepare("UPDATE apuntes SET promo_gratis = 0 WHERE id = ?");
            $stmt_off->bind_param("i", $id_apunte);
            $stmt_off->execute();
            $stmt_off->close();
        }
    }
    $stmt_upd->close();
}

// 5. RECHAZO (Promo agotada o intento fallido)
if (!$acceso_concedido) {
    $token_seguro = function_exists('nubira_encriptar_id') ? nubira_encriptar_id($id_apunte) : $id_apunte;
    $_SESSION['redirigir_despues_login'] = '/apunte/' . $token_seguro;
    header("Location: /login?mensaje=promo_agotada");
    exit;
}

// 6. ENTREGA DEL ARCHIVO
$archivo_nombre = basename($apunte['archivo']);
$ruta_fisica = $_SERVER['DOCUMENT_ROOT'] . "/upload/apuntes/" . $archivo_nombre;

if (!file_exists($ruta_fisica)) {
    error_log("Error crítico Promo: Archivo físico no encontrado en $ruta_fisica");
    die("Error 404: El documento no se encuentra en el servidor.");
}

/* Detección MIME */
$ext = strtolower(pathinfo($archivo_nombre, PATHINFO_EXTENSION));
$mime_types = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$content_type = $mime_types[$ext] ?? 'application/octet-stream';

/* Nombre amigable para el estudiante */
$baseName = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', (string)$apunte['titulo']);
$baseName = trim($baseName);
if (empty($baseName)) $baseName = 'Regalo_Nubira';
$nombre_descarga = str_replace(' ', '_', $baseName) . '_Gratis.' . $ext;

/* Limpiar buffers DE FORMA SEGURA (evita errores en logs que corrompen el archivo) */
if (ob_get_length()) {
    ob_end_clean();
}

/* Cabeceras estrictas para forzar descarga física en el dispositivo */
header('Content-Description: File Transfer');
header('Content-Type: ' . $content_type);
header('Content-Disposition: attachment; filename="' . $nombre_descarga . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . filesize($ruta_fisica));

readfile($ruta_fisica);
exit;
?>