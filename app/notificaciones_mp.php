<?php
// /app/notificaciones_mp.php — versión con auditoría completa

http_response_code(200); // ⚡ Requisito MP: responder 200 inmediato

$raw = file_get_contents('php://input');
file_put_contents(__DIR__ . '/mp_webhook.log',
    date('c') . " RAW: " . ($raw ?: '{}') . PHP_EOL,
    FILE_APPEND
);

$body = json_decode($raw, true);
if (!$body) exit;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/config.php';

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;

MercadoPagoConfig::setAccessToken(MP_ACCESS_TOKEN);

$type        = $body['type']  ?? ($body['action'] ?? '');
$payment_id  = $body['data']['id'] ?? null;
if (!$payment_id) exit;

try {
    $client  = new PaymentClient();
    $payment = $client->get($payment_id);

    $status        = $payment->status ?? '';
    $status_detail = $payment->status_detail ?? '';
    $external_ref  = $payment->external_reference ?? 0;
    $amount        = $payment->transaction_amount ?? 0;
    $email         = $payment->payer?->email ?? '';

    $contrato_id = (int)$external_ref;

    // 🧾 Registrar en tabla de auditoría
    $payload_json = json_encode($body, JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare("
        INSERT INTO mp_eventos_log (payment_id, contrato_id, tipo, status, status_detail, payload)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iissss", $payment_id, $contrato_id, $type, $status, $status_detail, $payload_json);
    $stmt->execute();
    $stmt->close();

    // 📄 Registrar también en contrato_eventos
    $evento  = strtoupper("WEBHOOK_{$type}_{$status}");
    $detalle = "payment_id={$payment_id}; status_detail={$status_detail}; email={$email}; monto={$amount}";
    $ins = $conn->prepare("
        INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle)
        VALUES (?, 0, ?, ?)
    ");
    $ins->bind_param("iss", $contrato_id, $evento, $detalle);
    $ins->execute();
    $ins->close();

    // 💼 Actualizar estado del contrato
    if ($contrato_id > 0) {
        if ($status === 'approved') {
            $up = $conn->prepare("
                UPDATE contratos SET estado='en_progreso', fecha_pago=NOW()
                WHERE id=? AND estado<>'en_progreso'
            ");
        } elseif (in_array($status, ['pending', 'in_process'])) {
            $up = $conn->prepare("
                UPDATE contratos SET estado='pendiente_pago'
                WHERE id=? AND estado NOT IN ('en_progreso')
            ");
        } elseif ($status === 'rejected') {
            $up = $conn->prepare("
                UPDATE contratos SET estado='rechazado'
                WHERE id=? AND estado<>'en_progreso'
            ");
        } else {
            $up = null;
        }

        if ($up) {
            $up->bind_param("i", $contrato_id);
            $up->execute();
            $up->close();
        }
    }

    // ✅ Log final simple
    file_put_contents(
        __DIR__ . '/mp_webhook.log',
        date('c') . " OK: payment_id=$payment_id status=$status contrato_id=$contrato_id\n",
        FILE_APPEND
    );

} catch (Throwable $e) {
    file_put_contents(
        __DIR__ . '/mp_webhook.log',
        date('c') . " ERR: " . $e->getMessage() . "\n",
        FILE_APPEND
    );
}
