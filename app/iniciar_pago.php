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

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;

// 1. SEGURIDAD Y SESIÓN
if (!isset($_SESSION['usuario_id'])) {
    // Redirección limpia al login de Nubira 2.0
    header('Location: /login?redir=' . urlencode($_SERVER['HTTP_REFERER'] ?? '/vitrina-apuntes'));
    exit;
}

$usuario_id  = (int)$_SESSION['usuario_id'];
$institucion = $_SESSION['institucion'] ?? 'Nubira';

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

if ($apunte['id_alumno'] === $usuario_id) {
    die("❌ Error: No puedes comprar tu propio apunte.");
}

$titulo = trim($apunte['titulo']);
$precio = (float)$apunte['precio'];

// Si el apunte es gratis, no debería llegar aquí, pero por si acaso lo protegemos
if ($precio <= 0 || empty($titulo)) {
    die("❌ Error: Este apunte no es procesable por la pasarela de pago.");
}

// 4. CONFIGURACIÓN MERCADOPAGO
MercadoPagoConfig::setAccessToken('APP_USR-2544127294491438-051314-46640e022ba544b7f641311738b0f6ab-703767907');

// URLs DE RETORNO CORREGIDAS (Apunta a la carpeta /app/)
$baseUrl    = 'https://nubira.cl';
$successUrl = $baseUrl . "/app/pago_exitoso.php";
$failureUrl = $baseUrl . "/app/pago_error.php";
$pendingUrl = $successUrl;

$client = new PreferenceClient();

try {
    // 5. CREAR PREFERENCIA
    $preference = $client->create([
        "items" => [[
            "title"       => "Apunte Nubira: " . mb_substr($titulo, 0, 50),
            "description" => "Acceso permanente al documento",
            "quantity"    => 1,
            "unit_price"  => $precio,
            "currency_id" => "CLP"
        ]],
        "back_urls" => [
            "success" => $successUrl,
            "failure" => $failureUrl,
            "pending" => $pendingUrl
        ],
        "auto_return"        => "approved",
        "external_reference" => (string)$id_apunte, // Vital para pago_exitoso.php
        "metadata" => [
            "usuario_id"  => $usuario_id,
            "institucion" => $institucion
        ]
    ]);

    // Guardar contexto en sesión (por si se necesita en el retorno)
    $_SESSION['pago'] = [
        'id_apunte'   => $id_apunte,
        'titulo'      => $titulo,
        'monto'       => $precio,
        'institucion' => $institucion
    ];

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