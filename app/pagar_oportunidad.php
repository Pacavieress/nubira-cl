<?php
session_start();
require_once '../vendor/autoload.php'; // Ajusta la ruta si lo tienes diferente
require_once 'conexion.php';
require_once 'config.php'; // Aquí puedes definir BASE_URL y tu Access Token

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;

// 1. Validar sesión activa
if (!isset($_SESSION['usuario_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$institucion = $_SESSION['institucion'] ?? '';

// 2. Validar parámetro
$id_oportunidad = intval($_GET['id'] ?? 0);
if ($id_oportunidad <= 0) {
    exit("❌ Oportunidad no especificada.");
}

// 3. Obtener oportunidad pendiente de pago
$stmt = $conn->prepare("SELECT * FROM oportunidades WHERE id = ? AND usuario_id = ? AND pagado = 0");
$stmt->bind_param("ii", $id_oportunidad, $usuario_id);
$stmt->execute();
$resultado = $stmt->get_result();
if ($resultado->num_rows === 0) {
    exit("❌ Oportunidad no encontrada o ya pagada.");
}
$oportunidad = $resultado->fetch_assoc();
$stmt->close();
$conn->close();

// 4. Validar datos de la oportunidad
$titulo = trim($oportunidad['titulo']);
$precio = 10; // 💲 Cambia esto si tienes lógica de precios distintos por tipo de oportunidad

if (empty($titulo)) {
    exit("❌ La oportunidad no tiene título válido.");
}

// 5. Configurar token MercadoPago
MercadoPagoConfig::setAccessToken(MP_ACCESS_TOKEN_OPORTUNIDADES);

// URLs de retorno
$successUrl = BASE_URL . "/pago-oportunidad-exitoso?id=" . $id_oportunidad;
$failureUrl = BASE_URL . "/crear_oportunidad?id=" . $id_oportunidad . "&error=1";
$pendingUrl = BASE_URL . "/crear_oportunidad?id=" . $id_oportunidad . "&pendiente=1";

// 6. Crear preferencia de pago
$client = new PreferenceClient();

try {
    $preference = $client->create([
        "items" => [[
            "title" => "Publicación: " . $titulo,
            "quantity" => 1,
            "unit_price" => $precio,
            "currency_id" => "CLP"
        ]],
        "back_urls" => [
            "success" => $successUrl,
            "failure" => $failureUrl,
            "pending" => $pendingUrl
        ],
        "auto_return" => "approved",
        "external_reference" => (string)$id_oportunidad,
        "metadata" => [
            "usuario_id" => $usuario_id,
            "institucion" => $institucion
        ]
    ]);

    // Guardar datos en sesión por si quieres usarlos después
    $_SESSION['pago_oportunidad'] = [
        'id_oportunidad' => $id_oportunidad,
        'titulo' => $titulo,
        'monto' => $precio,
        'institucion' => $institucion
    ];

    // 🔵 Redirige al link real de pago (NO sandbox)
    if (!empty($preference->init_point)) {
        header("Location: " . $preference->init_point);
        exit;
    } else {
        throw new Exception("❌ No se pudo generar el link de pago.");
    }

} catch (MPApiException $e) {
    echo "<h2>❌ Error al iniciar el pago</h2>";
    echo "<h3>Mensaje:</h3><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "<h3>Respuesta API (raw):</h3><pre>";
    $apiContent = $e->getApiResponse()->getContent();
    if (empty($apiContent)) {
        echo "Contenido vacío o nulo.\n";
    } else {
        var_dump($apiContent);
    }
    echo "</pre>";
    echo "<h3>Status code:</h3><pre>" . $e->getApiResponse()->getStatusCode() . "</pre>";
}
