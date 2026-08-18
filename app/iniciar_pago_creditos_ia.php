<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/creditos_ia.php';

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;

// 1. SESIÓN
if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login?redir=' . urlencode('/formulario-subir-apunte'));
    exit;
}
$usuario_id = (int)$_SESSION['usuario_id'];

// 2. WHITELIST ESTRICTA DEL PLAN
$PLANES_CREDITOS_IA = planesCreditosIA();
$plan = $_GET['plan'] ?? '';
if (!array_key_exists($plan, $PLANES_CREDITOS_IA)) {
    exit("❌ Plan inválido.");
}
// Bloqueo server-side de planes "Próximamente" — la UI ya no genera un link real
// hacia estos, pero eso no evita que alguien pegue el ?plan= directo en la URL.
if (empty($PLANES_CREDITOS_IA[$plan]['disponible'])) {
    exit("❌ Este plan todavía no está disponible para compra.");
}
$creditos = $PLANES_CREDITOS_IA[$plan]['creditos'];
$monto    = $PLANES_CREDITOS_IA[$plan]['monto'];

// 3. REGLA DE NEGOCIO: debe agotar el cupo actual antes de comprar otro plan
$cupo = verificarCupoIA($conn, $usuario_id);
if ($cupo['puede_generar']) {
    exit("❌ Todavía tienes generaciones disponibles — no puedes comprar otro plan hasta agotarlo.");
}

// 4. DATOS DEL COMPRADOR (re-consultados, no confiar solo en sesión)
$stmt = $conn->prepare("SELECT nombre, correo FROM alumnos WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$alumno = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$alumno) exit("❌ Usuario no encontrado.");

$correo_comprador = $alumno['correo'] ?? ($_SESSION['email'] ?? 'sin-correo@nubira.cl');
$rut              = $_SESSION['rut'] ?? '11111111-1';
$telefono         = $_SESSION['telefono'] ?? '912345678';

// 5. CONFIGURAR MERCADOPAGO
MercadoPagoConfig::setAccessToken(MP_ACCESS_TOKEN);
$client = new PreferenceClient();

$external_reference = "CREDITOS_IA_" . $usuario_id . "_" . time();

$payload = [
    "items" => [[
        "id"          => "CRED-" . $plan,
        "title"       => "Créditos IA Nubira: " . $creditos . " generacion" . ($creditos > 1 ? 'es' : ''),
        "description" => "Paquete de " . $creditos . " generaciones del asistente de IA para apuntes, válido por 1 mes.",
        "category_id" => "educational_services",
        "quantity"    => 1,
        "unit_price"  => (float)$monto,
        "currency_id" => "CLP"
    ]],
    "payer" => [
        "email" => $correo_comprador,
        "name"  => $alumno['nombre'] ?: 'Alumno Nubira',
        "identification" => [ "type" => "RUT", "number" => $rut ],
        "address" => [ "zip_code" => "8320000", "street_name" => "Avenida Libertador Bernardo O'Higgins 340" ],
        "phone" => [ "area_code" => "56", "number" => $telefono ]
    ],
    "back_urls" => [
        "success" => BASE_URL . "/app/pago_exitoso_creditos_ia.php",
        "failure" => BASE_URL . "/app/formulario_subir_apunte.php?error=pago_creditos",
        "pending" => BASE_URL . "/app/pago_exitoso_creditos_ia.php"
    ],
    "auto_return"          => "approved",
    "binary_mode"          => true,
    "notification_url"     => MP_WEBHOOK_URL,
    "external_reference"   => $external_reference,
    "statement_descriptor" => MP_STATEMENT_DESC,
    "metadata" => [
        "tipo"       => "creditos_ia",
        "usuario_id" => $usuario_id,
        "plan"       => $plan
    ]
];

// 6. CREAR PREFERENCIA Y REDIRIGIR
try {
    $pref = $client->create($payload);
    header("Location: " . $pref->init_point);
    exit;
} catch (MPApiException $e) {
    file_put_contents(
        __DIR__ . '/mp_error.log',
        date('c') . " Créditos IA (usuario {$usuario_id}, plan {$plan}) | Error: " . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    header("Location: /app/formulario_subir_apunte.php?error=pago_creditos");
    exit;
}
