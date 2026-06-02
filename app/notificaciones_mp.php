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

    // ── Detectar si el pago corresponde a un apunte ───────────────────────────
    if ($status === 'approved' && $contrato_id > 0) {
        $stmtEs = $conn->prepare("SELECT id FROM apuntes WHERE id = ? LIMIT 1");
        $stmtEs->bind_param("i", $contrato_id);
        $stmtEs->execute();
        $stmtEs->store_result();
        $es_apunte = ($stmtEs->num_rows > 0);
        $stmtEs->close();

        if ($es_apunte) {
            $apunte_id  = $contrato_id;
            $usuario_id = (int)($payment->metadata->usuario_id ?? 0);

            if ($usuario_id <= 0) {
                file_put_contents(
                    __DIR__ . '/mp_webhook.log',
                    date('c') . " APUNTE SIN USUARIO: payment_id=$payment_id apunte=$apunte_id\n",
                    FILE_APPEND
                );
                exit;
            }

            $monto_int = (int)$amount;
            $conn->begin_transaction();
            try {
                // Anti-dup compras
                $chk = $conn->prepare("SELECT id FROM compras WHERE payment_id = ? LIMIT 1");
                $chk->bind_param("s", $payment_id);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows === 0) {
                    $chk->close();
                    $servicio_cero = 0;
                    $estado        = 'pagado';
                    $ins = $conn->prepare(
                        "INSERT INTO compras (id_apunte, usuario_id, servicio_id, monto, estado_pago, payment_id, fecha) VALUES (?, ?, ?, ?, ?, ?, NOW())"
                    );
                    $ins->bind_param("iiiiss", $apunte_id, $usuario_id, $servicio_cero, $monto_int, $estado, $payment_id);
                    $ins->execute();
                    $ins->close();
                } else {
                    $chk->close();
                }

                // Anti-dup ventas_apuntes
                $chk2 = $conn->prepare("SELECT id FROM ventas_apuntes WHERE apunte_id = ? AND comprador_id = ? LIMIT 1");
                $chk2->bind_param("ii", $apunte_id, $usuario_id);
                $chk2->execute();
                $chk2->store_result();
                if ($chk2->num_rows === 0) {
                    $chk2->close();
                    $stmtA = $conn->prepare("SELECT id_alumno, precio FROM apuntes WHERE id = ? LIMIT 1");
                    $stmtA->bind_param("i", $apunte_id);
                    $stmtA->execute();
                    $apunte_row = $stmtA->get_result()->fetch_assoc();
                    $stmtA->close();
                    if ($apunte_row) {
                        $vendedor_id = (int)$apunte_row['id_alumno'];
                        $precio      = (int)$apunte_row['precio'];
                        $pagado      = 1;
                        $stmtV = $conn->prepare(
                            "INSERT INTO ventas_apuntes (apunte_id, comprador_id, vendedor_id, precio, pagado_al_vendedor) VALUES (?, ?, ?, ?, ?)"
                        );
                        $stmtV->bind_param("iiiii", $apunte_id, $usuario_id, $vendedor_id, $precio, $pagado);
                        $stmtV->execute();
                        $stmtV->close();
                    }
                } else {
                    $chk2->close();
                }

                $conn->commit();
                file_put_contents(
                    __DIR__ . '/mp_webhook.log',
                    date('c') . " APUNTE OK: payment_id=$payment_id apunte=$apunte_id comprador=$usuario_id\n",
                    FILE_APPEND
                );
            } catch (Throwable $ea) {
                $conn->rollback();
                file_put_contents(
                    __DIR__ . '/mp_webhook.log',
                    date('c') . " APUNTE ERR: payment_id=$payment_id apunte=$apunte_id error=" . $ea->getMessage() . "\n",
                    FILE_APPEND
                );
            }
            exit;
        }
    }
    // ─────────────────────────────────────────────────────────────────────────

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
