<?php
session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/helpers/creditos_ia.php';

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;

if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login');
    exit;
}
$usuario_id = (int)$_SESSION['usuario_id'];

$payment_id = $_GET['payment_id'] ?? $_GET['collection_id'] ?? null;
if (!$payment_id) {
    header("Location: /app/formulario_subir_apunte.php?error=pago_invalido");
    exit;
}

$PLANES_CREDITOS_IA = planesCreditosIA();

MercadoPagoConfig::setAccessToken(MP_ACCESS_TOKEN);
$paymentClient = new PaymentClient();

$creditos_totales   = 0;
$fecha_venc_txt     = '';
$error_msg          = '';
$procesando_todavia = false;

try {
    // Re-verifica SIEMPRE con la API de MP — nunca confía en los parámetros de la URL
    $payment = $paymentClient->get($payment_id);
    $status  = $payment->status ?? '';
    $meta_tipo       = $payment->metadata->tipo ?? '';
    $meta_usuario_id = (int)($payment->metadata->usuario_id ?? 0);
    $meta_plan       = $payment->metadata->plan ?? '';

    // [NUBIRA 2.0] Retry: la API de MP a veces devuelve metadata vacío justo
    // después de aprobar (consistencia eventual del lado de MP) — reintentamos
    // dándole tiempo a que se propague, antes de asumir que no llegó.
    $intentos = 0;
    while ($status === 'approved' && $meta_usuario_id <= 0 && $intentos < 3) {
        sleep($intentos === 0 ? 1 : 2);
        $payment = $paymentClient->get($payment_id);
        $status  = $payment->status ?? '';
        $meta_tipo       = $payment->metadata->tipo ?? '';
        $meta_usuario_id = (int)($payment->metadata->usuario_id ?? 0);
        $meta_plan       = $payment->metadata->plan ?? '';
        $intentos++;
    }

    // [NUBIRA 2.0] Fallback: si tras los reintentos el metadata sigue vacío, el
    // external_reference (formato CREDITOS_IA_{usuario_id}_{timestamp}) sí llega
    // completo e inmediato — lo usamos solo para recuperar el usuario_id. El
    // plan, si tampoco vino en metadata, se infiere del monto REAL pagado
    // (nunca del cliente). Si no se puede determinar con seguridad, no se
    // inserta acá — el webhook lo resuelve con sus propios datos.
    if ($status === 'approved' && $meta_usuario_id <= 0) {
        $ext_ref = (string)($payment->external_reference ?? '');
        if (preg_match('/^CREDITOS_IA_(\d+)_\d+$/', $ext_ref, $m)) {
            $meta_usuario_id = (int)$m[1];
            $meta_tipo       = 'creditos_ia'; // el prefijo ya lo confirma
        }
        if ($meta_plan === '' || !array_key_exists($meta_plan, $PLANES_CREDITOS_IA)) {
            $monto_pagado = (int)round((float)($payment->transaction_amount ?? 0));
            foreach ($PLANES_CREDITOS_IA as $slug => $info) {
                if ((int)$info['monto'] === $monto_pagado) {
                    $meta_plan = $slug;
                    break;
                }
            }
        }
    }

    // Blindaje: el pago debe ser realmente de este usuario logueado — se aplica
    // sin importar si el usuario_id salió de metadata o del fallback de arriba.
    if ($status !== 'approved') {
        $error_msg = "El pago aún no está aprobado (estado: {$status}). Si ya pagaste, espera unos segundos y refresca.";
    } elseif ($meta_usuario_id > 0 && $meta_usuario_id !== $usuario_id) {
        $error_msg = "Este comprobante no corresponde a tu cuenta.";
    } elseif ($meta_tipo !== 'creditos_ia' || $meta_usuario_id <= 0 || !array_key_exists($meta_plan, $PLANES_CREDITOS_IA)) {
        // Aprobado, pero ni metadata ni el fallback lograron confirmar los datos
        // todavía — no es un error real, es timing de MP. El webhook activa el
        // crédito solo apenas le llegue la notificación; no asustamos al usuario.
        $procesando_todavia = true;
    } else {
        $creditos_totales = $PLANES_CREDITOS_IA[$meta_plan]['creditos'];
        $monto_real       = $PLANES_CREDITOS_IA[$meta_plan]['monto'];

        // Capa 1: fast-path anti-duplicado
        $chk = $conn->prepare("SELECT id, fecha_vencimiento FROM compras_creditos_ia WHERE payment_id = ? LIMIT 1");
        $chk->bind_param("s", $payment_id);
        $chk->execute();
        $res_chk = $chk->get_result();
        $fila_existente = $res_chk->fetch_assoc();
        $chk->close();

        if ($fila_existente) {
            // Ya insertado por el webhook — no duplicamos, solo mostramos el resultado
            $fecha_venc_txt = date('d/m/Y', strtotime($fila_existente['fecha_vencimiento']));
        } else {
            $stmt = $conn->prepare("
                INSERT INTO compras_creditos_ia (alumno_id, plan, creditos_totales, monto, payment_id, estado_pago, fecha_vencimiento, fecha_pago)
                VALUES (?, ?, ?, ?, ?, 'pagado', DATE_ADD(NOW(), INTERVAL 1 MONTH), NOW())
            ");
            $stmt->bind_param("isiis", $meta_usuario_id, $meta_plan, $creditos_totales, $monto_real, $payment_id);
            $insert_ok    = $stmt->execute();
            $insert_errno = $stmt->errno;
            $stmt->close();

            if ($insert_ok) {
                // Este proceso ganó la carrera (o no hubo carrera) — calculamos la fecha nosotros mismos
                $fecha_venc_txt = date('d/m/Y', strtotime('+1 month'));
            } elseif ($insert_errno === 1062) {
                // Capa 2: el webhook insertó entre nuestro SELECT y nuestro INSERT — no es un error,
                // recuperamos la fila real que quedó guardada para mostrar la fecha correcta.
                $stmtF = $conn->prepare("SELECT fecha_vencimiento FROM compras_creditos_ia WHERE payment_id = ? LIMIT 1");
                $stmtF->bind_param("s", $payment_id);
                $stmtF->execute();
                $row = $stmtF->get_result()->fetch_assoc();
                $stmtF->close();
                $fecha_venc_txt = $row ? date('d/m/Y', strtotime($row['fecha_vencimiento'])) : date('d/m/Y', strtotime('+1 month'));
            } else {
                error_log("pago_exitoso_creditos_ia INSERT error real (errno=$insert_errno): " . $conn->error);
                $error_msg = "No pudimos confirmar tu pago en este momento. Si el cobro se realizó, tus créditos se activarán en unos minutos.";
            }
        }
    }
} catch (Throwable $e) {
    error_log("pago_exitoso_creditos_ia error: " . $e->getMessage());
    $error_msg = "No pudimos confirmar tu pago en este momento. Si el cobro se realizó, tus créditos se activarán en unos minutos.";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Créditos IA | Nubira</title>
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
        <?php elseif ($procesando_todavia): ?>
            <div class="w-16 h-16 bg-sky-50 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-circle-check text-2xl text-[#54A6D8]"></i>
            </div>
            <h1 class="text-xl font-bold text-gray-900 mb-2">Tu pago fue aprobado</h1>
            <p class="text-sm text-gray-500 mb-6">Estamos activando tu crédito, puede tardar unos segundos — recarga esta página o vuelve a subir tu apunte en un momento.</p>
        <?php else: ?>
            <div class="w-16 h-16 bg-green-50 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-circle-check text-2xl text-green-500"></i>
            </div>
            <h1 class="text-xl font-bold text-gray-900 mb-2">¡Créditos activados!</h1>
            <p class="text-sm text-gray-500 mb-1">Recibiste <strong><?= (int)$creditos_totales ?> generacion<?= $creditos_totales != 1 ? 'es' : '' ?></strong> de IA para tus apuntes.</p>
            <p class="text-xs text-gray-400 mb-6">Válidas hasta el <strong><?= htmlspecialchars($fecha_venc_txt) ?></strong>. No se renuevan automáticamente.</p>
        <?php endif; ?>
        <a href="/formulario-subir-apunte" class="w-full inline-flex justify-center items-center gap-2 bg-[#54A6D8] hover:bg-blue-600 text-white font-bold py-3.5 rounded-xl shadow-md hover:shadow-lg transition-all active:scale-[0.98]">
            Volver a subir apunte <i class="fa-solid fa-arrow-right text-xs"></i>
        </a>
    </div>
</body>
</html>
