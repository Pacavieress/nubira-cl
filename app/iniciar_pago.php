<?php
/**
 * PROCESO: INICIAR PAGO DE APUNTES
 * OBJETIVO: Puente seguro entre Nubira y MercadoPago para venta de documentos.
 */
session_start();

// Ocultar errores en producción para evitar romper la UI
ini_set('display_errors', 0);
error_reporting(E_ALL);

// RUTAS ABSOLUTAS (Evita el Error 500)
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/helpers/pago_apunte.php';
require_once __DIR__ . '/helpers/comprador_invitado.php';

use MercadoPago\Exceptions\MPApiException;

// 1. IDENTIDAD (opcional) — checkout de invitado sin fricción, diseño revisado 25/08/2026:
// sin sesión no se pide NADA obligatorio, se va directo a MercadoPago igual que un usuario
// logueado. El único dato opcional es un email de respaldo (para recibir el link por correo)
// — si viene, se valida acá que no pertenezca a una cuenta real (mejor avisar antes de pagar
// que después); si no viene o el formato es inválido, sigue el camino 100% anónimo. La fila
// fantasma del comprador recién se crea al confirmarse el pago (pago_exitoso.php/
// notificaciones_mp.php) en cualquiera de los dos casos — acá no hay ninguna identidad que crear.
$usuario_id  = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : null;
$institucion = $_SESSION['institucion'] ?? 'Nubira';

$email_invitado = null;
if ($usuario_id === null) {
    $emailCrudo = trim((string)($_GET['email'] ?? ''));
    if ($emailCrudo !== '' && filter_var($emailCrudo, FILTER_VALIDATE_EMAIL)) {
        $emailLower = strtolower($emailCrudo);
        $stmtChk = $conn->prepare("SELECT es_comprador_invitado FROM alumnos WHERE correo = ? LIMIT 1");
        $stmtChk->bind_param("s", $emailLower);
        $stmtChk->execute();
        $rowChk = $stmtChk->get_result()->fetch_assoc();
        $stmtChk->close();

        if ($rowChk && (int)$rowChk['es_comprador_invitado'] === 0) {
            $login_redir = '/login?redir=' . urlencode($_SERVER['REQUEST_URI']);
            ?>
            <!DOCTYPE html>
            <html lang="es">
            <head>
                <meta charset="UTF-8">
                <title>Ya tienes cuenta | Nubira</title>
                <script src="https://cdn.tailwindcss.com"></script>
                <style>@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap'); body { font-family: 'Inter', sans-serif; }</style>
            </head>
            <body class="bg-gray-50 h-screen flex items-center justify-center p-4">
                <div class="bg-white p-8 rounded-3xl shadow-xl max-w-sm text-center border border-gray-100">
                    <h2 class="text-xl font-bold text-gray-900 mb-2">Ese correo ya tiene cuenta</h2>
                    <p class="text-sm text-gray-500 mb-6">Inicia sesión para continuar con la compra — así tu historial de compras queda todo junto.</p>
                    <a href="<?= htmlspecialchars($login_redir, ENT_QUOTES, 'UTF-8') ?>" class="block w-full bg-[#54A6D8] text-white font-bold py-3 rounded-xl hover:bg-blue-600 transition shadow-md">
                        Iniciar sesión
                    </a>
                </div>
            </body>
            </html>
            <?php
            exit;
        }

        $email_invitado = $emailLower;
    }
    // Formato inválido: se ignora en silencio (el campo es opcional, no bloqueamos la compra
    // por un typo en un dato que ni siquiera es obligatorio) y sigue el camino anónimo.
}

// 2. CAPTURA DE DATOS
$id_apunte = (int)($_GET['id_apunte'] ?? $_GET['reference'] ?? 0);

if ($id_apunte <= 0) {
    header("Location: /vitrina-apuntes?error=invalido");
    exit;
}

// 3. VALIDACIÓN EN BASE DE DATOS
$stmt = $conn->prepare("SELECT id_alumno, titulo, precio FROM apuntes WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id_apunte);
$stmt->execute();
$apunte = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$apunte) {
    die("❌ Error: Apunte no encontrado.");
}

if ($usuario_id !== null && $apunte['id_alumno'] === $usuario_id) {
    die("❌ Error: No puedes comprar tu propio apunte.");
}

$titulo = trim($apunte['titulo']);
$precio = (float)$apunte['precio'];

// Si el apunte es gratis, no debería llegar aquí, pero por si acaso lo protegemos
if ($precio <= 0 || empty($titulo)) {
    die("❌ Error: Este apunte no es procesable por la pasarela de pago.");
}

try {
    // 4-5. CREAR PREFERENCIA (helper compartido con app/helpers/pago_apunte.php)
    $identidad = $usuario_id !== null
        ? ['tipo' => 'usuario', 'usuario_id' => $usuario_id, 'institucion' => $institucion]
        : ['tipo' => 'invitado', 'email' => $email_invitado];

    $preference = crearPreferenciaApunte($id_apunte, ['id_alumno' => $apunte['id_alumno'], 'titulo' => $titulo, 'precio' => $precio], $identidad);

    // Guardar contexto en sesión (por si se necesita en el retorno) — solo aplica al camino
    // logueado, que sí tiene sesión donde guardarlo.
    if ($usuario_id !== null) {
        $_SESSION['pago'] = [
            'id_apunte'   => $id_apunte,
            'titulo'      => $titulo,
            'monto'       => $precio,
            'institucion' => $institucion
        ];
    }

    // 6. REDIRECCIÓN A LA PASARELA
    if (!empty($preference->init_point)) {
        header("Location: " . $preference->init_point);
        exit;
    } else {
        throw new Exception("MercadoPago no devolvió un link válido.");
    }

} catch (Exception $e) {
    // 7. MANEJO DE ERRORES UI NUBIRA 2.0 (No más var_dump)
    error_log("Error iniciando pago MP: " . $e->getMessage());
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Error de Pago | Nubira</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
        <style>@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap'); body { font-family: 'Inter', sans-serif; }</style>
    </head>
    <body class="bg-gray-50 h-screen flex items-center justify-center p-4">
        <div class="bg-white p-8 rounded-3xl shadow-xl max-w-sm text-center border border-gray-100">
            <div class="w-16 h-16 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-triangle-exclamation text-2xl text-red-500"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-900 mb-2">Conexión interrumpida</h2>
            <p class="text-sm text-gray-500 mb-6">No pudimos conectar con el banco en este momento. Por favor, inténtalo de nuevo en unos minutos.</p>
            <button onclick="history.back()" class="w-full bg-[#54A6D8] text-white font-bold py-3 rounded-xl hover:bg-blue-600 transition shadow-md">
                Volver al Apunte
            </button>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>