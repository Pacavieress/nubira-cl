<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/publicaciones_pago.php';

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;

// 1. SESIÓN
if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login?redir=' . urlencode('/publicar-servicio'));
    exit;
}
$usuario_id = (int)$_SESSION['usuario_id'];

// 2. VALIDAR EL SERVICIO: debe existir, ser del usuario logueado, y estar
// realmente esperando pago — evita que alguien pague por un servicio ajeno
// o uno que ya se activó por otra vía.
$servicio_id = (int)($_GET['servicio_id'] ?? 0);
if ($servicio_id <= 0) {
    exit("❌ Servicio inválido.");
}

$stmt = $conn->prepare("SELECT id, titulo, alumno_id, estado FROM servicios WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $servicio_id);
$stmt->execute();
$servicio = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$servicio || (int)$servicio['alumno_id'] !== $usuario_id) {
    exit("❌ Servicio no encontrado.");
}
if ($servicio['estado'] !== 'pendiente_pago') {
    exit("❌ Este servicio no está esperando pago.");
}

// 3. DATOS DEL COMPRADOR (re-consultados, no confiar solo en sesión)
$stmt = $conn->prepare("SELECT nombre, correo FROM alumnos WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$alumno = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$alumno) exit("❌ Usuario no encontrado.");

$correo_comprador = $alumno['correo'] ?? ($_SESSION['email'] ?? 'sin-correo@nubira.cl');
$rut              = $_SESSION['rut'] ?? '11111111-1';
$telefono         = $_SESSION['telefono'] ?? '912345678';

// 4. CONFIGURAR MERCADOPAGO
MercadoPagoConfig::setAccessToken(MP_ACCESS_TOKEN);
$client = new PreferenceClient();

$external_reference = "PUBLICACION_SERVICIO_" . $usuario_id . "_" . $servicio_id . "_" . time();

$payload = [
    "items" => [[
        "id"          => "PUB-" . $servicio_id,
        "title"       => "Publicación de servicio en Nubira",
        "description" => "Publicación: \"" . mb_substr($servicio['titulo'], 0, 80) . "\"",
        "category_id" => "educational_services",
        "quantity"    => 1,
        "unit_price"  => (float)PRECIO_PUBLICACION_SERVICIO,
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
        "success" => BASE_URL . "/app/pago_exitoso_publicacion_servicio.php",
        "failure" => BASE_URL . "/app/mis_servicios.php?error=pago_publicacion",
        "pending" => BASE_URL . "/app/pago_exitoso_publicacion_servicio.php"
    ],
    "auto_return"          => "approved",
    "binary_mode"          => true,
    "notification_url"     => MP_WEBHOOK_URL,
    "external_reference"   => $external_reference,
    "statement_descriptor" => MP_STATEMENT_DESC,
    "metadata" => [
        "tipo"        => "publicacion_servicio",
        "usuario_id"  => $usuario_id,
        "servicio_id" => $servicio_id
    ]
];

// 5. CREAR PREFERENCIA Y REDIRIGIR
try {
    $pref = $client->create($payload);
    header("Location: " . $pref->init_point);
    exit;
} catch (MPApiException $e) {
    file_put_contents(
        __DIR__ . '/mp_error.log',
        date('c') . " Publicación servicio (usuario {$usuario_id}, servicio {$servicio_id}) | Error: " . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    header("Location: /app/mis_servicios.php?error=pago_publicacion");
    exit;
}
