<?php
/**
 * ENDPOINT: CREAR CUENTA EXPRESS (NUBIRA 2.0)
 * Permite que un visitante no logueado cree una cuenta mínima para abrir
 * un chat con un tutor, sin pasar por el registro normal.
 *
 * Recibe: POST JSON { nombre, email, acepta_terminos, servicio_id? }
 * Devuelve: JSON { ok: true, redirect: "..." } | { error: "..." }
 */

ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
session_start();

// Clave sintética usada en login_fallos para rate limiting de este endpoint
define('RATE_KEY', '__express_account');
define('RATE_MAX',  3);    // máx intentos por IP en la ventana
define('RATE_MIN',  60);   // ventana en minutos

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/helpers/ip_real.php'; // IP real detrás del proxy (carga también el .env)

// ─── Utilidad: salir con error JSON ───────────────────────────────────────────
function express_error(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

// ─── 1. Solo POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    express_error('Método no permitido.', 405);
}

// ─── 2. Visitante sin sesión ──────────────────────────────────────────────────
if (isset($_SESSION['usuario_id'])) {
    express_error('Ya estás logueado.');
}

// ─── 3. Parsear JSON del body ─────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    express_error('JSON inválido.');
}

$nombre        = trim((string)($body['nombre'] ?? ''));
$email         = strtolower(trim((string)($body['email'] ?? '')));
$acepta        = $body['acepta_terminos'] ?? false;
$servicio_id   = (int)($body['servicio_id'] ?? 0);

// ─── 4. Validaciones de entrada ───────────────────────────────────────────────
if (!$acepta) {
    express_error('Debes aceptar los términos.');
}

$nombre_limpio = htmlspecialchars(strip_tags($nombre), ENT_QUOTES, 'UTF-8');
if (mb_strlen($nombre_limpio, 'UTF-8') < 2 || mb_strlen($nombre_limpio, 'UTF-8') > 100) {
    express_error('El nombre debe tener entre 2 y 100 caracteres.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    express_error('El formato del email no es válido.');
}

// ─── 5. Rate limit por IP (tabla login_fallos, clave sintética) ───────────────
// La IP real del visitante la resuelve ip_real() (app/helpers/ip_real.php): detrás del proxy de
// Next / Nginx, REMOTE_ADDR es la IP del proxy y todos compartirían el mismo contador. La regla
// de confianza (lista de proxies + PROXY_SHARED_SECRET) vive en ese helper.
$ip = ip_real();

// bind_param recibe sus argumentos por referencia: no acepta constantes directas (lanza
// Error, que el catch de abajo tragaba en silencio y dejaba el límite sin efecto).
$rate_key = RATE_KEY;
$rate_min = RATE_MIN;

try {
    $stmt_rate = $conn->prepare(
        "SELECT COUNT(*) AS intentos FROM login_fallos
         WHERE ip = ? AND correo = ? AND fecha > (NOW() - INTERVAL ? MINUTE)"
    );
    $stmt_rate->bind_param("ssi", $ip, $rate_key, $rate_min);
    $stmt_rate->execute();
    $intentos = (int)$stmt_rate->get_result()->fetch_assoc()['intentos'];
    $stmt_rate->close();
} catch (Throwable $e) {
    // Si la tabla falla, dejamos pasar (no bloqueamos por fallo técnico)
    $intentos = 0;
}

if ($intentos >= RATE_MAX) {
    express_error('Demasiados intentos. Espera un momento e intenta de nuevo.', 429);
}

// Registrar este intento (antes del resultado, para contar éxitos y fallos)
try {
    $stmt_log = $conn->prepare("INSERT INTO login_fallos (correo, ip) VALUES (?, ?)");
    $stmt_log->bind_param("ss", $rate_key, $ip);
    $stmt_log->execute();
    $stmt_log->close();
} catch (Throwable $e) { /* silencioso */ }

// ─── 6. Verificar email único ─────────────────────────────────────────────────
try {
    $stmt_check = $conn->prepare("SELECT id, visible FROM alumnos WHERE correo = ? LIMIT 1");
    $stmt_check->bind_param("s", $email);
    $stmt_check->execute();
    $existente = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();
} catch (Throwable $e) {
    express_error('Error interno al verificar el email.', 500);
}

if ($existente) {
    if ((int)$existente['visible'] === 1) {
        express_error('Ya tienes una cuenta con ese email. Inicia sesión.');
    }
    express_error('Esa cuenta está desactivada. Contacta a soporte.');
}

// ─── 7. Crear cuenta express ──────────────────────────────────────────────────
$dominio             = substr(strrchr($email, '@'), 1);
$password_aleatoria  = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
$token               = bin2hex(random_bytes(32));
$tipo                = 'particular';
$verificacion_estado = 'pendiente';

try {
    $stmt_insert = $conn->prepare(
        "INSERT INTO alumnos
            (nombre, correo, password, carrera, dominio,
             confirmado, token, tipo, verificacion_estado, cuenta_express)
         VALUES (?, ?, ?, '', ?, 1, ?, ?, ?, 1)"
    );
    $stmt_insert->bind_param(
        "sssssss",
        $nombre_limpio,
        $email,
        $password_aleatoria,
        $dominio,
        $token,
        $tipo,
        $verificacion_estado
    );

    if (!$stmt_insert->execute()) {
        throw new RuntimeException('Error al crear la cuenta.');
    }

    $nuevo_id = (int)$stmt_insert->insert_id;
    $stmt_insert->close();
} catch (Throwable $e) {
    express_error('No se pudo crear la cuenta. Intenta de nuevo.', 500);
}

// ─── 8. Establecer sesión (idéntico a login.php para compatibilidad total) ────
$_SESSION['usuario_id']            = $nuevo_id;
$_SESSION['usuario_nombre']        = $nombre_limpio;
$_SESSION['rol']                   = 'alumno';
$_SESSION['email']                 = $email;
$_SESSION['dominio']               = $dominio;
$_SESSION['institucion']           = '';
$_SESSION['verificacion_estado']   = $verificacion_estado;
$_SESSION['perfil_completo']       = false;
$_SESSION['es_tutor_activo']       = false;
$_SESSION['notif_sugerencia_vista'] = 0;
$_SESSION['cuenta_express']        = true; // usado por el banner "Completa tu registro"

// ─── 9. Redirect destino ──────────────────────────────────────────────────────
$redirect = '/vitrina';
if ($servicio_id > 0) {
    $redirect = '/app/iniciar_chat.php?servicio_id=' . $servicio_id;
}

echo json_encode(['ok' => true, 'redirect' => $redirect]);
