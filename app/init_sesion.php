<?php
// ARCHIVO: app/init_sesion.php
// OBJETIVO: Sistema central de seguridad.
// NO MODIFICA VISTAS. SOLO GESTIONA EL ACCESO.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSRF Token Global (disponible en todas las vistas)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/conexion.php'; // Asegura la conexión siempre disponible

// 1. Lógica de "Recordarme" (Token de Cookie)
// Esto asegura que si el usuario vuelve, recupera su sesión automáticamente.
if (!isset($_SESSION['usuario_id']) && !empty($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $stmt = $conn->prepare("SELECT id, nombre, rol, correo, institucion FROM alumnos WHERE remember_token = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows === 1) {
            $u = $res->fetch_assoc();
            $_SESSION['usuario_id']     = $u['id'];
            session_regenerate_id(true);
            // [Fase 5] Mismo espejo hacia sesiones_api — este es el 3er punto donde PHP
            // establece identidad de sesión (corre en CADA carga de página que incluya
            // init_sesion.php, no solo en /login). Va después de session_regenerate_id().
            $sid = session_id();
            $stmt_sesion_api = $conn->prepare(
                "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 24 HOUR)
                 ON DUPLICATE KEY UPDATE usuario_id = VALUES(usuario_id), expira_en = VALUES(expira_en)"
            );
            $stmt_sesion_api->bind_param("si", $sid, $u['id']);
            $stmt_sesion_api->execute();
            $stmt_sesion_api->close();
            $_SESSION['usuario_nombre'] = $u['nombre'];
            $_SESSION['rol']            = $u['rol'] ?? 'alumno';
            $_SESSION['email']          = $u['correo'];
            $_SESSION['institucion']    = $u['institucion'];
        } else {
            setcookie('remember_token', '', time() - 3600, '/', '', true, true);
        }
        $stmt->close();
    }
}

// 2. Definir quién es el usuario
$usuario_id = $_SESSION['usuario_id'] ?? null;
$es_visitante = ($usuario_id === null);

// 2.5. ONBOARDING "Cómo funciona Nubira" (carga perezosa + caché de sesión)
$debe_ver_onboarding = false;
if (!$es_visitante) {
    if (!isset($_SESSION['onboarding_visto'])) {
        $stmt_onb = $conn->prepare("SELECT onboarding_visto FROM alumnos WHERE id = ? LIMIT 1");
        if ($stmt_onb) {
            $stmt_onb->bind_param("i", $usuario_id);
            $stmt_onb->execute();
            $stmt_onb->bind_result($onb_flag);
            $stmt_onb->fetch();
            $stmt_onb->close();
            $_SESSION['onboarding_visto'] = (int)($onb_flag ?? 0);
        }
    }
    $debe_ver_onboarding = empty($_SESSION['onboarding_visto']);
}
// Visitante: $debe_ver_onboarding queda false; el frontend decide vía localStorage.

// 3. CANDADO DE SEGURIDAD (Función Crítica)
// Esta función se pondrá al inicio de CADA archivo privado.
if (!function_exists('proteger_ruta')) {
    function proteger_ruta() {
        global $es_visitante;
        if ($es_visitante) {
            // Guardamos dónde quería ir el intruso para devolverlo después de loguearse
            $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
            header("Location: /login.php");
            exit;
        }
    }
}
?>