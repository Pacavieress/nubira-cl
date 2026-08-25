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
require_once __DIR__ . '/helpers/comprador_invitado.php';
require_once __DIR__ . '/correo.php';

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

    // ── Detectar si el pago corresponde a una compra de créditos IA ────────────
    // Prefijo único, chequeado ANTES de la detección de apunte/contrato — cero
    // riesgo de colisión con IDs numéricos reales.
    if (strpos((string)$external_ref, 'CREDITOS_IA_') === 0) {
        require_once __DIR__ . '/helpers/creditos_ia.php';

        if ($status !== 'approved') {
            file_put_contents(
                __DIR__ . '/mp_webhook.log',
                date('c') . " CREDITOS_IA NO-APROBADO: payment_id=$payment_id status=$status ref=$external_ref\n",
                FILE_APPEND
            );
            exit;
        }

        $PLANES_CREDITOS_IA = planesCreditosIA();
        $meta_usuario_id    = (int)($payment->metadata->usuario_id ?? 0);
        $meta_plan          = $payment->metadata->plan ?? '';

        if ($meta_usuario_id <= 0 || !array_key_exists($meta_plan, $PLANES_CREDITOS_IA)) {
            file_put_contents(
                __DIR__ . '/mp_webhook.log',
                date('c') . " CREDITOS_IA METADATA INVÁLIDA: payment_id=$payment_id usuario=$meta_usuario_id plan=$meta_plan\n",
                FILE_APPEND
            );
            exit;
        }

        $creditos_totales = $PLANES_CREDITOS_IA[$meta_plan]['creditos'];
        $monto_real       = $PLANES_CREDITOS_IA[$meta_plan]['monto']; // NUNCA $amount de MP — precio server-side siempre

        // Capa 1: fast-path anti-duplicado
        $chk = $conn->prepare("SELECT id FROM compras_creditos_ia WHERE payment_id = ? LIMIT 1");
        $chk->bind_param("s", $payment_id);
        $chk->execute();
        $chk->store_result();
        $ya_existe = ($chk->num_rows > 0);
        $chk->close();

        if ($ya_existe) {
            file_put_contents(
                __DIR__ . '/mp_webhook.log',
                date('c') . " CREDITOS_IA YA EXISTÍA: payment_id=$payment_id usuario=$meta_usuario_id (insertado por el otro camino)\n",
                FILE_APPEND
            );
            exit;
        }

        $stmt = $conn->prepare("
            INSERT INTO compras_creditos_ia (alumno_id, plan, creditos_totales, monto, payment_id, estado_pago, fecha_vencimiento, fecha_pago)
            VALUES (?, ?, ?, ?, ?, 'pagado', DATE_ADD(NOW(), INTERVAL 1 MONTH), NOW())
        ");
        $stmt->bind_param("isiis", $meta_usuario_id, $meta_plan, $creditos_totales, $monto_real, $payment_id);
        $insert_ok    = $stmt->execute();
        $insert_errno = $stmt->errno;
        $stmt->close();

        // Capa 2: si el fast-path no detectó la carrera, el UNIQUE(payment_id) sí la frena acá —
        // execute() devuelve false SIN lanzar excepción (mysqli_report no está activo global).
        if ($insert_ok) {
            file_put_contents(
                __DIR__ . '/mp_webhook.log',
                date('c') . " CREDITOS_IA OK: payment_id=$payment_id usuario=$meta_usuario_id plan=$meta_plan\n",
                FILE_APPEND
            );
        } elseif ($insert_errno === 1062) {
            file_put_contents(
                __DIR__ . '/mp_webhook.log',
                date('c') . " CREDITOS_IA CARRERA DETECTADA (UNIQUE): payment_id=$payment_id usuario=$meta_usuario_id — insertado por el otro camino entre el SELECT y el INSERT\n",
                FILE_APPEND
            );
        } else {
            file_put_contents(
                __DIR__ . '/mp_webhook.log',
                date('c') . " CREDITOS_IA ERROR REAL DE BD: payment_id=$payment_id usuario=$meta_usuario_id errno=$insert_errno error=" . $conn->error . "\n",
                FILE_APPEND
            );
        }
        exit;
    }
    // ─────────────────────────────────────────────────────────────────────────

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
            $usuario_id = $usuario_id > 0 ? $usuario_id : null;

            // Checkout de invitado (sin sesión) — diseño revisado 25/08/2026 (email opcional
            // de respaldo). Sin metadata.usuario_id es invitado por definición
            // (iniciar_pago.php nunca la manda para invitados). El webhook es el único camino
            // que puede confirmar la compra si el invitado nunca vuelve al navegador — la
            // venta se registra igual; si dejó email, este es el único lugar que puede
            // avisarle en ese caso (no hay pantalla que mostrar desde un webhook).
            $es_invitado = ($usuario_id === null);
            $emailInvitado = $es_invitado ? ($payment->metadata->email ?? null) : null;

            $monto_int = (int)$amount;
            $conn->begin_transaction();
            try {
                // Anti-dup compras — además coordina identidad con pago_exitoso.php: si esa
                // página ya procesó este payment_id (o esta es una reentrega del webhook),
                // reutilizamos SU comprador en vez de crear una fila fantasma nueva. Mismo
                // criterio que pago_exitoso.php — ver la nota ahí.
                $chk = $conn->prepare("SELECT id, usuario_id FROM compras WHERE payment_id = ? LIMIT 1");
                $chk->bind_param("s", $payment_id);
                $chk->execute();
                $filaCompra = $chk->get_result()->fetch_assoc();
                $chk->close();

                if ($filaCompra) {
                    $usuario_id = (int)$filaCompra['usuario_id'];
                } else {
                    if ($usuario_id === null) {
                        if ($emailInvitado) {
                            $resultado = obtenerOCrearCompradorInvitado($conn, $emailInvitado);
                            if ($resultado['ok']) {
                                $usuario_id = $resultado['id'];
                            } else {
                                // Mismo criterio que pago_exitoso.php: ese email ya es una
                                // cuenta real (conflicto recién detectado acá) — seguimos con
                                // un fantasma genérico para no perder el registro de la venta.
                                $usuario_id = crearCompradorInvitado($conn);
                                $emailInvitado = null;
                            }
                        } else {
                            $usuario_id = crearCompradorInvitado($conn);
                        }
                    }
                    $servicio_cero = 0;
                    $estado        = 'pagado';
                    $insertOk = false;
                    $errno = 0;
                    try {
                        $ins = $conn->prepare(
                            "INSERT INTO compras (id_apunte, usuario_id, servicio_id, monto, estado_pago, payment_id, fecha) VALUES (?, ?, ?, ?, ?, ?, NOW())"
                        );
                        $ins->bind_param("iiiiss", $apunte_id, $usuario_id, $servicio_cero, $monto_int, $estado, $payment_id);
                        $insertOk = $ins->execute();
                        $errno = $ins->errno;
                        $ins->close();
                    } catch (mysqli_sql_exception $dupEx) {
                        $errno = $dupEx->getCode();
                    }

                    if (!$insertOk) {
                        // Carrera de verdad con pago_exitoso.php — mismo criterio que ahí: nos
                        // rendimos y reutilizamos al comprador que ganó en vez de duplicar.
                        if ($errno !== 1062) throw new RuntimeException("Error insertando compras (payment_id=$payment_id): errno=$errno");
                        // LOCK IN SHARE MODE — ver la misma nota en pago_exitoso.php: un SELECT
                        // normal no vería la fila que pago_exitoso.php acaba de confirmar bajo
                        // REPEATABLE READ (probado en vivo, devolvía usuario_id=0 sin esto).
                        $stmtGanador = $conn->prepare("SELECT usuario_id FROM compras WHERE payment_id = ? LOCK IN SHARE MODE");
                        $stmtGanador->bind_param("s", $payment_id);
                        $stmtGanador->execute();
                        $rowGanador = $stmtGanador->get_result()->fetch_assoc();
                        $stmtGanador->close();
                        if (!$rowGanador) throw new RuntimeException("Carrera en compras sin ganador visible (payment_id=$payment_id)");
                        $usuario_id = (int)$rowGanador['usuario_id'];
                    }
                }

                // Anti-dup ventas_apuntes
                $venta_id = null; // id en ventas_apuntes — usado por el invitado-con-email para el guard de correo_enviado
                $archivo_apunte = null;
                $titulo_apunte = null;
                $chk2 = $conn->prepare("SELECT id FROM ventas_apuntes WHERE apunte_id = ? AND comprador_id = ? LIMIT 1");
                $chk2->bind_param("ii", $apunte_id, $usuario_id);
                $chk2->execute();
                $chk2->store_result();
                if ($chk2->num_rows === 0) {
                    $chk2->close();
                    $stmtA = $conn->prepare("SELECT id_alumno, precio, archivo, titulo FROM apuntes WHERE id = ? LIMIT 1");
                    $stmtA->bind_param("i", $apunte_id);
                    $stmtA->execute();
                    $apunte_row = $stmtA->get_result()->fetch_assoc();
                    $stmtA->close();
                    if ($apunte_row) {
                        $vendedor_id    = (int)$apunte_row['id_alumno'];
                        $precio         = (int)$apunte_row['precio'];
                        $archivo_apunte = $apunte_row['archivo'];
                        $titulo_apunte  = $apunte_row['titulo'];
                        $pagado         = 1;
                        try {
                            $stmtV = $conn->prepare(
                                "INSERT INTO ventas_apuntes (apunte_id, comprador_id, vendedor_id, precio, pagado_al_vendedor) VALUES (?, ?, ?, ?, ?)"
                            );
                            $stmtV->bind_param("iiiii", $apunte_id, $usuario_id, $vendedor_id, $precio, $pagado);
                            $stmtV->execute();
                            $venta_id = $stmtV->insert_id ?: null;
                            $stmtV->close();
                        } catch (mysqli_sql_exception $dupEx) {
                            // Carrera con pago_exitoso.php: la otra ruta ya registró esta venta
                            // primero. El UNIQUE(apunte_id, comprador_id) frenó el duplicado — no es un error real.
                            if ($dupEx->getCode() !== 1062) throw $dupEx;
                        }
                        if (!$venta_id) {
                            $stmtVid = $conn->prepare("SELECT id FROM ventas_apuntes WHERE apunte_id = ? AND comprador_id = ? LOCK IN SHARE MODE");
                            $stmtVid->bind_param("ii", $apunte_id, $usuario_id);
                            $stmtVid->execute();
                            $rowVid = $stmtVid->get_result()->fetch_assoc();
                            if ($rowVid) $venta_id = (int)$rowVid['id'];
                            $stmtVid->close();
                        }
                    }
                } else {
                    $stmtRec = $conn->prepare("SELECT id FROM ventas_apuntes WHERE apunte_id = ? AND comprador_id = ? LIMIT 1");
                    $stmtRec->bind_param("ii", $apunte_id, $usuario_id);
                    $stmtRec->execute();
                    $resRec = $stmtRec->get_result()->fetch_assoc();
                    if ($resRec) $venta_id = (int)$resRec['id'];
                    $stmtRec->close();
                    $chk2->close();

                    $stmtA = $conn->prepare("SELECT archivo, titulo FROM apuntes WHERE id = ? LIMIT 1");
                    $stmtA->bind_param("i", $apunte_id);
                    $stmtA->execute();
                    $apunte_row = $stmtA->get_result()->fetch_assoc();
                    $stmtA->close();
                    if ($apunte_row) {
                        $archivo_apunte = $apunte_row['archivo'];
                        $titulo_apunte  = $apunte_row['titulo'];
                    }
                }

                $conn->commit();

                // Invitado con email: mismo guard atómico de correo_enviado que
                // pago_exitoso.php — el webhook puede ser el único camino que confirme la
                // compra si el invitado nunca vuelve al navegador, así que el correo tiene
                // que poder salir de acá.
                if ($es_invitado && $emailInvitado && !empty($archivo_apunte) && $venta_id) {
                    $stmtGuard = $conn->prepare("UPDATE ventas_apuntes SET correo_enviado = 1 WHERE id = ? AND correo_enviado = 0");
                    $stmtGuard->bind_param("i", $venta_id);
                    $stmtGuard->execute();
                    $ganamos_el_envio = $stmtGuard->affected_rows > 0;
                    $stmtGuard->close();

                    if ($ganamos_el_envio && $titulo_apunte) {
                        $link = BASE_URL . enlaceDescargaApunte($apunte_id, $archivo_apunte, $usuario_id);
                        try {
                            enviarCorreoAccesoApuntesInvitado($emailInvitado, [['titulo' => $titulo_apunte, 'link' => $link]]);
                        } catch (Throwable $mailEx) {
                            file_put_contents(
                                __DIR__ . '/mp_webhook.log',
                                date('c') . " APUNTE CORREO ERR: payment_id=$payment_id apunte=$apunte_id error=" . $mailEx->getMessage() . "\n",
                                FILE_APPEND
                            );
                        }
                    }
                }

                file_put_contents(
                    __DIR__ . '/mp_webhook.log',
                    date('c') . " APUNTE OK: payment_id=$payment_id apunte=$apunte_id comprador=$usuario_id" . ($es_invitado ? " (invitado)" : "") . "\n",
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
