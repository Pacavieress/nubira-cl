<?php
session_start();
require_once '../vendor/autoload.php';
require_once 'conexion.php';
require_once 'config.php';

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;

// 1️⃣ Validaciones básicas
if (!isset($_SESSION['usuario_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

$usuario_id  = (int)$_SESSION['usuario_id'];
$id_contrato = (int)($_GET['id_contrato'] ?? 0);
if ($id_contrato <= 0) exit("❌ Contrato no especificado.");

// 2️⃣ Obtener datos del contrato
$stmt = $conn->prepare("
    SELECT 
        c.id, c.monto, c.estado, s.titulo, 
        c.vendedor_id, 
        a.nombre AS comprador_nombre, a.correo AS comprador_correo,
        b.nombre AS vendedor_nombre
    FROM contratos c
    JOIN servicios s ON c.servicio_id = s.id
    JOIN alumnos a ON c.comprador_id = a.id
    JOIN alumnos b ON c.vendedor_id = b.id
    WHERE c.id = ? AND c.comprador_id = ?
");
$stmt->bind_param("ii", $id_contrato, $usuario_id);
$stmt->execute();
$c = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$c) exit("❌ Contrato no encontrado o no autorizado.");

$precio = floatval($c['monto']);

// 🚨 REGLA CRÍTICA NUBIRA 2.0: Manejo de Contratos Gratuitos y Pagados
if ($precio <= 0) {
    // Si el monto es CERO, asumimos que el contrato es GRATUITO y ya está activo.
    // Redirigimos al aula virtual.
    header("Location: " . BASE_URL . "/app/mini_aula.php?id={$id_contrato}");
    exit;
}

if ($c['estado'] !== 'pendiente_pago') {
    // Si el estado no es pendiente (ej. ya está en_progreso o liberado), redirigimos al aula.
    header("Location: " . BASE_URL . "/app/mini_aula.php?id={$id_contrato}");
    exit;
}


// 3️⃣ Preparar variables
$titulo             = $c['titulo'] ?? 'Sin título';
$vendedor_id        = (int)$c['vendedor_id'];
$correo_comprador   = $c['comprador_correo'] ?? ($_SESSION['email'] ?? 'sin-correo@nubira.cl');
$rut                = $_SESSION['rut'] ?? '11111111-1';
$telefono           = $_SESSION['telefono'] ?? '912345678';


// 4️⃣ Configurar Mercado Pago
MercadoPagoConfig::setAccessToken(MP_ACCESS_TOKEN);
$client = new PreferenceClient();

// 5️⃣ Construir la preferencia (SOLO PARA PRECIOS > 0)
$payload = [
    "items" => [[
        "id" => "CT-" . $id_contrato,
        "title" => $titulo,
        "description" => "Pago seguro por servicio \"" . $titulo . "\" en Nubira.cl",
        "category_id" => "educational_services",
        "quantity" => 1,
        "unit_price" => $precio, // Ya sabemos que es > 0
        "currency_id" => "CLP"
    ]],
    "payer" => [
        "email" => $correo_comprador,
        "name"  => $c['comprador_nombre'] ?: 'Alumno Nubira',
        "identification" => [
            "type"   => "RUT",
            "number" => $rut
        ],
        "address" => [
            "zip_code"    => "8320000",
            "street_name" => "Avenida Libertador Bernardo O’Higgins 340"
        ],
        "phone" => [
            "area_code" => "56",
            "number"    => $telefono
        ]
    ],
    "back_urls" => [
        "success" => BASE_URL . "/app/pago_exitoso_contrato.php?id={$id_contrato}",
        "failure" => BASE_URL . "/app/pago_error_contrato.php?id={$id_contrato}",
        "pending" => BASE_URL . "/app/pago_pendiente_contrato.php?id={$id_contrato}"
    ],
    "auto_return"        => "approved",
    "binary_mode"        => true,
    "notification_url"   => MP_WEBHOOK_URL,
    "external_reference" => (string)$id_contrato,
    "statement_descriptor" => MP_STATEMENT_DESC,
    "metadata" => [
        "usuario_id"  => $usuario_id,
        "vendedor_id" => $vendedor_id,
        "tipo"        => "contrato"
    ]
];

// 6️⃣ Crear preferencia
try {
    $pref = $client->create($payload);

    // Registrar evento interno
    $log = $conn->prepare("
        INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle)
        VALUES (?, ?, 'INTENTO_PAGO', 'Checkout creado en Mercado Pago')
    ");
    $log->bind_param("ii", $id_contrato, $usuario_id);
    $log->execute();
    $log->close();

    // Redirigir al checkout
    header("Location: " . $pref->init_point);
    exit;

} catch (MPApiException $e) {
    file_put_contents(
        __DIR__ . '/mp_error.log',
        date('c') . " Contrato {$id_contrato} | Error: " . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    header("Location: " . BASE_URL . "/app/pago_error_contrato.php?id={$id_contrato}&motivo=api");
    exit;
}
?>