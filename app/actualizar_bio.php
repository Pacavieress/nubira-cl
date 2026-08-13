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

// 4.5 Filtro de contacto / redes (al final, más caro)
$pattern = "/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6})
            |(\+?\d{1,3}[- ]?)?\d{7,12}
            |(wa\.me|whatsapp|wsp|ig:|insta|fb:|facebook|@|celular|fono|llama|escribeme|contacto)/ix";

if (preg_match($pattern, $bio)) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'POR SEGURIDAD, NO PUBLIQUES CONTACTO O REDES'
    ]);
    exit;
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