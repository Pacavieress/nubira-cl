<?php
/**
 * PROCESADOR: ACTUALIZAR BIOGRAFÍA
 * UBICACIÓN: public_html/app/actualizar_bio.php
 * ESTADO: PRODUCCIÓN – VALIDADO, BLINDADO Y LIMPIO (NUBIRA 2.0)
 */
ob_start(); // [NUBIRA FIX] Iniciar buffer para atrapar basura
session_start();
error_reporting(0); // [NUBIRA FIX] Silenciar warnings

header('Content-Type: application/json');

// 1. CONEXIÓN
require_once __DIR__ . '/conexion.php';

// 2. SEGURIDAD BÁSICA
if (!isset($_SESSION['usuario_id'])) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'SESIÓN EXPIRADA'
    ]);
    exit;
}

// 3. VALIDAR CONEXIÓN DB
if (!isset($conn)) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'ERROR DE CONEXIÓN A LA BASE DE DATOS'
    ]);
    exit;
}

// 3.5 VALIDACIÓN CSRF (NUBIRA SHIELD)
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'TOKEN DE SEGURIDAD INVÁLIDO'
    ]);
    exit;
}

// 4. DATOS
$usuario_id = (int) $_SESSION['usuario_id'];
$bio = isset($_POST['bio']) ? trim($_POST['bio']) : '';

// ─────────────────────────────
// VALIDACIONES
// ─────────────────────────────

// 4.1 Bio vacía
if ($bio === '') {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'LA BIO NO PUEDE ESTAR VACÍA'
    ]);
    exit;
}

// 4.2 Largo máximo
if (strlen($bio) > 500) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'MÁXIMO 500 CARACTERES PERMITIDOS'
    ]);
    exit;
}

// 4.3 Bio demasiado corta (Filtro de Calidad Nubira 2.0)
if (mb_strlen(trim($bio), 'UTF-8') < 60) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'PARA DESTACAR TU PERFIL, TU BIO DEBE TENER AL MENOS 60 CARACTERES'
    ]);
    exit;
}

// 4.4 Filtro de lenguaje ofensivo
$odio = [
    'weon','ctm','puta','culiao','ql','perra',
    'maricon','odio','muerte','pendejo','estafa'
];
foreach ($odio as $p) {
    if (stripos($bio, $p) !== false) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'LENGUAJE NO PERMITIDO'
        ]);
        exit;
    }
}

// 4.5 Filtro de contacto / redes (mismo patrón DLP del chat, ver app/enviar_mensaje.php —
// mensaje específico por categoría, sin loggear a dlp_intentos porque esa tabla exige
// conversacion_id NOT NULL, que la bio no tiene)
$nucleo_digitos_tel = '(?:\d[\s\-\.]*){7,}';

$patrones_bloqueo = [
    'email'    => ['/[a-z0-9._%+-]+(?:@|\s+arroba\s+|\[arroba\]|\(arroba\))[a-z0-9.-]+(?:\.|\s+punto\s+|\[punto\])[a-z]{2,}/i',
                   'Tu biografía no puede incluir un correo electrónico. Bórralo para poder guardar.'],
    'telefono' => ['/(?:\+?56\s*9|9)?[\s\-\.]*' . $nucleo_digitos_tel . '/',
                   'Tu biografía no puede incluir un número de teléfono. Bórralo para poder guardar.'],
    'redes'    => ['/\b(wh?a[ts]+s?[aá]pp?|wasap|watsap|whsatap|guatsap|wsp|wa\.me|instagram|insta|ig|face|fb|tiktok|tk|telegram|tg|t\.me|discord|dc|linktree|x\.com|twitter|tw|linkedin|in)\b/i',
                   'Tu biografía no puede mencionar redes sociales o apps de mensajería. Bórralo para poder guardar.'],
    'urls'     => ['/(http|https|www\.)/i',
                   'Tu biografía no puede incluir enlaces. Bórralo para poder guardar.'],
];

foreach ($patrones_bloqueo as $categoria => [$pattern, $mensaje_error]) {
    if (preg_match($pattern, $bio)) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => $mensaje_error
        ]);
        exit;
    }
}

// ─────────────────────────────
// UPDATE BLINDADO
// ─────────────────────────────

try {
    $stmt = $conn->prepare(
        "UPDATE alumnos SET bio = ? WHERE id = ?"
    );

    if (!$stmt) {
        throw new Exception('PREPARE FAIL');
    }

    $stmt->bind_param("si", $bio, $usuario_id);
    $stmt->execute();
    $stmt->close(); // Cerramos el primer statement

    // =========================================================================
    // [NUBIRA 2.0] RECALCULAR GAMIFICACIÓN EN TIEMPO REAL
    // Si la bio mejoró, actualizamos la nota de TODAS las clases de este tutor
    // =========================================================================
    require_once __DIR__ . '/helpers/usuario_helper.php';
    
    $q_serv = $conn->prepare("SELECT id FROM servicios WHERE alumno_id = ?");
    if ($q_serv) {
        $q_serv->bind_param("i", $usuario_id);
        $q_serv->execute();
        $res_s = $q_serv->get_result();
        while($sv = $res_s->fetch_assoc()){
            actualizar_score_servicio($conn, $sv['id']);
        }
        $q_serv->close();
    }
    // =========================================================================

    ob_clean(); // Limpiamos la memoria de cualquier output generado por el helper
    echo json_encode([
        'success' => true,
        'newBio'  => nl2br(htmlspecialchars($bio, ENT_QUOTES, 'UTF-8'))
    ]);
    exit;

} catch (Throwable $e) {

    // Log interno (NO visible al usuario)
    error_log('[BIO UPDATE] ' . $e->getMessage());

    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'ERROR AL GUARDAR CAMBIOS'
    ]);
    exit;
}