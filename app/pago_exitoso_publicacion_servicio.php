<?php
session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/helpers/publicaciones_pago.php';

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;

if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login');
    exit;
}
$usuario_id = (int)$_SESSION['usuario_id'];

$payment_id = $_GET['payment_id'] ?? $_GET['collection_id'] ?? null;
if (!$payment_id) {
    header("Location: /app/mis_servicios.php?error=pago_invalido");
    exit;
}

MercadoPagoConfig::setAccessToken(MP_ACCESS_TOKEN);
$paymentClient = new PaymentClient();

$titulo_servicio = '';
$error_msg       = '';

try {
    // Re-verifica SIEMPRE con la API de MP — nunca confía en los parámetros de la URL
    $payment = $paymentClient->get($payment_id);
    $status  = $payment->status ?? '';
    $meta_usuario_id  = (int)($payment->metadata->usuario_id ?? 0);
    $meta_servicio_id = (int)($payment->metadata->servicio_id ?? 0);
    $meta_tipo        = $payment->metadata->tipo ?? '';

    // Blindaje: el pago debe ser realmente de este usuario logueado
    if ($meta_tipo !== 'publicacion_servicio' || $meta_usuario_id !== $usuario_id) {
        $error_msg = "Este comprobante no corresponde a tu cuenta.";
    } elseif ($status !== 'approved') {
        $error_msg = "El pago aún no está aprobado (estado: {$status}). Si ya pagaste, espera unos segundos y refresca.";
    } elseif ($meta_servicio_id <= 0) {
        $error_msg = "No pudimos identificar el servicio de este pago.";
    } else {
        // Capa 1: fast-path anti-duplicado
        $chk = $conn->prepare("SELECT id FROM compras_publicacion_servicio WHERE payment_id = ? LIMIT 1");
        $chk->bind_param("s", $payment_id);
        $chk->execute();
        $fila_existente = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$fila_existente) {
            $stmt = $conn->prepare("
                INSERT INTO compras_publicacion_servicio (alumno_id, servicio_id, monto, payment_id, estado_pago, fecha_pago)
                VALUES (?, ?, ?, ?, 'pagado', NOW())
            ");
            $monto_real = PRECIO_PUBLICACION_SERVICIO;
            $stmt->bind_param("iiis", $meta_usuario_id, $meta_servicio_id, $monto_real, $payment_id);
            $insert_ok    = $stmt->execute();
            $insert_errno = $stmt->errno;
            $stmt->close();

            if (!$insert_ok && $insert_errno !== 1062) {
                // Capa 2 (errno 1062 = el webhook ganó la carrera, no es un error real)
                error_log("pago_exitoso_publicacion_servicio INSERT error real (errno=$insert_errno): " . $conn->error);
                $error_msg = "No pudimos confirmar tu pago en este momento. Si el cobro se realizó, tu publicación se activará en unos minutos.";
            }
        }

        if (!$error_msg) {
            // Activa el servicio SOLO si sigue en 'pendiente_pago' — evita que
            // una segunda confirmación (webhook + esta página) lo mueva 2 veces
            // o pise un estado que el admin ya haya cambiado mientras tanto.
            $up = $conn->prepare("UPDATE servicios SET estado = 'pendiente' WHERE id = ? AND estado = 'pendiente_pago'");
            $up->bind_param("i", $meta_servicio_id);
            $up->execute();
            $up->close();

            $stmtT = $conn->prepare("SELECT titulo FROM servicios WHERE id = ? LIMIT 1");
            $stmtT->bind_param("i", $meta_servicio_id);
            $stmtT->execute();
            $stmtT->bind_result($titulo_servicio);
            $stmtT->fetch();
            $stmtT->close();
        }
    }
} catch (Throwable $e) {
    error_log("pago_exitoso_publicacion_servicio error: " . $e->getMessage());
    $error_msg = "No pudimos confirmar tu pago en este momento. Si el cobro se realizó, tu publicación se activará en unos minutos.";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Publicación de servicio | Nubira</title>
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap'); body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4 antialiased text-gray-800">
    <div class="bg-white p-8 md:p-10 rounded-[2rem] shadow-xl w-full max-w-md text-center border border-gray-100">
        <?php if ($error_msg): ?>
            <div class="w-16 h-16 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-triangle-exclamation text-2xl text-red-500"></i>
            </div>
            <h1 class="text-xl font-bold text-gray-900 mb-2">Algo no cuadró</h1>
            <p class="text-sm text-gray-500 mb-6"><?= htmlspecialchars($error_msg) ?></p>
        <?php else: ?>
            <div class="w-16 h-16 bg-green-50 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-circle-check text-2xl text-green-500"></i>
            </div>
            <h1 class="text-xl font-bold text-gray-900 mb-2">¡Pago confirmado!</h1>
            <p class="text-sm text-gray-500 mb-1">Tu servicio <strong><?= htmlspecialchars($titulo_servicio) ?></strong> quedó enviado a revisión.</p>
            <p class="text-xs text-gray-400 mb-6">Un admin lo revisa antes de que sea visible — igual que cualquier otra publicación.</p>
        <?php endif; ?>
        <a href="/mis-publicaciones" class="w-full inline-flex justify-center items-center gap-2 bg-[#54A6D8] hover:bg-blue-600 text-white font-bold py-3.5 rounded-xl shadow-md hover:shadow-lg transition-all active:scale-[0.98]">
            Ver mis publicaciones <i class="fa-solid fa-arrow-right text-xs"></i>
        </a>
    </div>
</body>
</html>
