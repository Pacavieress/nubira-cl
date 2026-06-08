<?php
/**
 * PROCESO: INICIAR PAGO MERCADOPAGO (NUBIRA 2.0 - SISTEMA DE CONTRATOS)
 * OBJETIVO: Generar preferencia de pago basada en el contrato acordado.
 */
session_start();

// 1. CONFIGURACIÓN Y SEGURIDAD
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;

// Verificación de sesión
if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login');
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];

// 2. CAPTURAR EL ID DEL CONTRATO (Soporta POST del Auto-Submit o GET del Fallback)
$contrato_id = (int)($_POST['contrato_id'] ?? $_GET['id'] ?? 0);

if ($contrato_id <= 0) {
    header('Location: /vitrina');
    exit;
}

// 3. VALIDAR CONTRATO Y OBTENER PRECIO DINÁMICO
$stmt = $conn->prepare("
    SELECT c.monto, c.estado, c.servicio_id, s.titulo, s.alumno_id as vendedor_id 
    FROM contratos c 
    JOIN servicios s ON c.servicio_id = s.id 
    WHERE c.id = ? AND c.comprador_id = ? 
    LIMIT 1
");
$stmt->bind_param("ii", $contrato_id, $usuario_id);
$stmt->execute();
$contrato = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Validaciones de negocio
if (!$contrato) {
    die("Error: Contrato no encontrado o no tienes permisos para verlo.");
}

if ($contrato['vendedor_id'] == $usuario_id) {
    die("Error: No puedes auto-contratar tus propios servicios.");
}

if ($contrato['estado'] !== 'pendiente_pago') {
    // Si ya está pagado o en progreso, lo enviamos directo al aula virtual del contrato
    header("Location: /app/mini_aula.php?id=" . $contrato_id);
    exit;
}

$precio_a_pagar = (float)$contrato['monto'];

// 4. LÓGICA DE SERVICIOS GRATUITOS (Subsidio total o precio $0)
if ($precio_a_pagar == 0.0) {
    // Actualizamos el estado del contrato directamente
    $conn->query("UPDATE contratos SET estado = 'en_progreso' WHERE id = $contrato_id");
    header("Location: /app/mini_aula.php?id=$contrato_id&status=free_success");
    exit;
}

// 5. CONFIGURAR MERCADOPAGO
MercadoPagoConfig::setAccessToken(MP_ACCESS_TOKEN);

// 6. CREAR PREFERENCIA DE PAGO
$baseUrl    = 'https://nubira.cl';

$successUrl = $baseUrl . "/app/pago_exitoso_contrato.php?contrato_id=" . $contrato_id;
$failureUrl = $baseUrl . "/app/pago_error_contrato.php?contrato_id=" . $contrato_id;
$pendingUrl = $successUrl;

$client = new PreferenceClient();

try {
    $preference = $client->create([
        "items" => [[
            "title"       => "Servicio Nubira: " . mb_substr($contrato['titulo'], 0, 50),
            "description" => "Pago en custodia por contrato #" . $contrato_id,
            "quantity"    => 1,
            "unit_price"  => $precio_a_pagar,
            "currency_id" => "CLP"
        ]],
        "back_urls" => [
            "success" => $successUrl,
            "failure" => $failureUrl,
            "pending" => $pendingUrl
        ],
        "auto_return" => "approved",
        "external_reference" => "CONTRATO_" . $contrato_id,
        "metadata" => [
            "usuario_id"  => $usuario_id,
            "contrato_id" => $contrato_id,
            "servicio_id" => $contrato['servicio_id']
        ]
    ]);

    // 7. REDIRECCIÓN A LA PASARELA
    if (!empty($preference->init_point)) {
        header("Location: " . $preference->init_point);
        exit;
    } else {
        throw new Exception("MercadoPago no devolvió un link de pago válido.");
    }

} catch (Exception $e) {
    error_log("Error MP (Contrato $contrato_id): " . $e->getMessage());
    
    // UX: Pantalla de error al estilo Nubira 2.0
    echo '<!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Error de conexión | Nubira</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
        <style>@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"); body { font-family: "Inter", sans-serif; }</style>
    </head>
    <body class="bg-gray-50 flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-3xl border border-gray-200 shadow-xl max-w-md w-full p-8 text-center">
            <div class="w-16 h-16 bg-red-50 text-red-500 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <h2 class="text-2xl font-bold text-gray-900 mb-2">Error de conexión con el banco</h2>
            <p class="text-gray-500 text-sm mb-8 leading-relaxed">No pudimos generar el enlace de pago seguro en este momento. No te preocupes, tu solicitud de servicio está guardada y puedes intentar pagar más tarde.</p>
            <a href="/vitrina" class="block w-full bg-[#54A6D8] text-white font-bold py-3.5 rounded-xl hover:bg-blue-600 transition-all shadow-md hover:scale-[1.01]">
                Volver a la plataforma
            </a>
        </div>
    </body>
    </html>';
}
?>