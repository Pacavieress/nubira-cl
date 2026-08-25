<?php
/**
 * CONTROLADOR FANTASMA: PAGO EXITOSO DE APUNTES (Retorno de MP)
 * OBJETIVO: Registrar compra, rescatar sesión y redirigir silenciosamente.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/helpers/comprador_invitado.php';

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;

$payment_id = $_GET['payment_id'] ?? null;
$reference  = $_GET['external_reference'] ?? null;

if (!$payment_id) {
    header("Location: /vitrina-apuntes?error=pago_invalido");
    exit;
}

MercadoPagoConfig::setAccessToken(MP_ACCESS_TOKEN);
$paymentClient = new PaymentClient();

try {
    // 1. OBTENER DATOS DE MERCADO PAGO
    $payment = $paymentClient->get($payment_id);

    $monto  = $payment->transaction_amount;
    $estado_mp = $payment->status;
    $estado_nubira = ($estado_mp === 'approved') ? 'pagado' : $estado_mp;
    $apunte_id   = intval($reference);
    $archivo_apunte = null;

    // 2. IDENTIDAD CONOCIDA DE ANTEMANO (sesión o usuario logueado vía metadata)
    $usuario_id  = $_SESSION['usuario_id'] ?? null;
    $institucion = $_SESSION['institucion'] ?? null;

    if (!$usuario_id && isset($payment->metadata->usuario_id)) {
        $usuario_id = (int)$payment->metadata->usuario_id;
    }

    // Checkout de invitado (sin sesión, cero campos) — diseño revisado 24/08/2026. Si no hay
    // sesión NI metadata.usuario_id, es invitado por definición (iniciar_pago.php nunca manda
    // usuario_id para invitados). La fila fantasma se resuelve más abajo, coordinada con
    // notificaciones_mp.php a través de `compras.payment_id` — ver el bloque A.
    $es_invitado = ($usuario_id === null);

    // 3. LÓGICA DE BASE DE DATOS
    try {
        $conn->begin_transaction();

        // A) compras — además de evitar duplicados, es el punto de coordinación de identidad
        // para invitados: si notificaciones_mp.php (o esta misma página, en un reintento) ya
        // procesó este payment_id, reutilizamos SU comprador en vez de crear una fila fantasma
        // nueva — evita duplicar al comprador y, con eso, duplicar la venta en ventas_apuntes
        // (violaría el conteo real de ventas del tutor).
        $stmt = $conn->prepare("SELECT id, usuario_id FROM compras WHERE payment_id = ? LIMIT 1");
        $stmt->bind_param("s", $payment_id);
        $stmt->execute();
        $filaCompra = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($filaCompra) {
            $usuario_id = (int)$filaCompra['usuario_id'];
            $stmtUpdate = $conn->prepare("UPDATE compras SET estado_pago = ? WHERE payment_id = ?");
            $stmtUpdate->bind_param("ss", $estado_nubira, $payment_id);
            $stmtUpdate->execute();
            $stmtUpdate->close();
        } else {
            if ($usuario_id === null) {
                $usuario_id = crearCompradorInvitado($conn);
            }

            $monto_int = (int)$monto;
            $servicio_cero = 0;
            $insertOk = false;
            $errno = 0;
            try {
                $stmtInsert = $conn->prepare("INSERT INTO compras (id_apunte, usuario_id, servicio_id, monto, estado_pago, payment_id, fecha) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stmtInsert->bind_param("iiiiss", $apunte_id, $usuario_id, $servicio_cero, $monto_int, $estado_nubira, $payment_id);
                $insertOk = $stmtInsert->execute();
                $errno = $stmtInsert->errno;
                $stmtInsert->close();
            } catch (mysqli_sql_exception $dupEx) {
                $errno = $dupEx->getCode();
            }

            if (!$insertOk) {
                // Carrera de verdad con notificaciones_mp.php: ambos vimos "no existe" antes de
                // que cualquiera confirmara — el UNIQUE(payment_id) frenó a quien llegó
                // segundo acá. Nos rendimos y reutilizamos el comprador que sí ganó, en vez de
                // dejar a este comprador con un error pese a que el pago ya se cobró.
                if ($errno !== 1062) throw new RuntimeException("Error insertando compras (payment_id=$payment_id): errno=$errno");
                // LOCK IN SHARE MODE: bajo REPEATABLE READ (default de InnoDB), un SELECT
                // normal seguiría el snapshot de ESTA transacción (fijado en su primer SELECT,
                // antes de que la otra ruta confirmara) y no vería la fila recién comprometida
                // por notificaciones_mp.php — probado en vivo con dos procesos simultáneos
                // antes de este fix (devolvía usuario_id=0). Un locking read sí lee la última
                // versión comprometida.
                $stmtGanador = $conn->prepare("SELECT usuario_id FROM compras WHERE payment_id = ? LOCK IN SHARE MODE");
                $stmtGanador->bind_param("s", $payment_id);
                $stmtGanador->execute();
                $rowGanador = $stmtGanador->get_result()->fetch_assoc();
                $stmtGanador->close();
                if (!$rowGanador) throw new RuntimeException("Carrera en compras sin ganador visible (payment_id=$payment_id)");
                $usuario_id = (int)$rowGanador['usuario_id'];
                // La fila fantasma que creamos nosotros (si aplica) queda huérfana — invisible
                // (visible=0) y sin costo real, mismo trade-off aceptado que el resto del diseño.
            }
        }

        // B) Registrar la venta para el autor
        if ($estado_nubira === 'pagado' && $usuario_id && $apunte_id > 0) {
            $stmtCheck = $conn->prepare("SELECT id FROM ventas_apuntes WHERE apunte_id = ? AND comprador_id = ? LIMIT 1");
            $stmtCheck->bind_param("ii", $apunte_id, $usuario_id);
            $stmtCheck->execute();
            $stmtCheck->store_result();

            if ($stmtCheck->num_rows === 0) {
                $stmtCheck->close();

                $stmtApunte = $conn->prepare("SELECT id_alumno, precio, archivo FROM apuntes WHERE id = ? LIMIT 1");
                $stmtApunte->bind_param("i", $apunte_id);
                $stmtApunte->execute();
                $apunte = $stmtApunte->get_result()->fetch_assoc();
                $stmtApunte->close();

                if ($apunte) {
                    $vendedor_id = (int)$apunte['id_alumno'];
                    $precio      = (int)$apunte['precio'];
                    $archivo_apunte = $apunte['archivo'];
                    $pagado      = 1;

                    try {
                        $stmtVenta = $conn->prepare("INSERT INTO ventas_apuntes (apunte_id, comprador_id, vendedor_id, precio, pagado_al_vendedor) VALUES (?, ?, ?, ?, ?)");
                        $stmtVenta->bind_param("iiiii", $apunte_id, $usuario_id, $vendedor_id, $precio, $pagado);
                        $stmtVenta->execute();
                        $stmtVenta->close();
                    } catch (mysqli_sql_exception $dupEx) {
                        // Carrera con notificaciones_mp.php: la otra ruta ya registró esta venta
                        // primero. El UNIQUE(apunte_id, comprador_id) frenó el duplicado — no es un error real.
                        if ($dupEx->getCode() !== 1062) throw $dupEx;
                    }
                } else {
                    throw new RuntimeException(
                        "apunte_id=$apunte_id no encontrado al registrar venta para payment_id=$payment_id usuario=$usuario_id"
                    );
                }
            } else {
                $stmtCheck->close();
                // Recuperar nombre del archivo para la redirección/link
                $stmtRecovery = $conn->prepare("SELECT archivo FROM apuntes WHERE id = ? LIMIT 1");
                $stmtRecovery->bind_param("i", $apunte_id);
                $stmtRecovery->execute();
                $resRec = $stmtRecovery->get_result()->fetch_assoc();
                if ($resRec) $archivo_apunte = $resRec['archivo'];
                $stmtRecovery->close();
            }
        }

        $conn->commit();

        // ==========================================
        // 4. REDIRECCIÓN CON TRACKER (BRIDGE PAGE)
        // ==========================================
        // Invitado: el link se muestra ACÁ y solo acá — diseño revisado 24/08/2026, sin
        // mecanismo de reenvío. Si el usuario lo pierde, no hay forma de recuperarlo (aceptado
        // explícitamente): sin email, no hay ningún otro canal para volver a entregarlo.
        $link_invitado = ($es_invitado && $estado_nubira === 'pagado' && !empty($archivo_apunte))
            ? BASE_URL . enlaceDescargaApunte($apunte_id, $archivo_apunte, $usuario_id)
            : null;

        if ($estado_nubira === 'pagado' && !empty($archivo_apunte)) {
            $url_destino = "/app/ver_apunte.php?archivo=" . urlencode($archivo_apunte) . "&pago=exitoso";
        } else {
            $url_destino = "/vitrina-apuntes?pago=completado";
        }

        // Cerramos PHP para imprimir la página puente de medio segundo
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
            <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
            <title>Procesando Pago | Nubira</title>

            <script>
              if (typeof fbq === 'function') {
                  fbq('track', 'Purchase', {
                      value: <?= (int)$monto ?>,
                      currency: 'CLP',
                      content_ids: ['<?= (int)$apunte_id ?>'],
                      content_type: 'product'
                  });
              }
            </script>
            <noscript>
              <img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=2149832959284130&ev=Purchase&noscript=1" />
            </noscript>

            <style>
                body { margin: 0; background: #ffffff; display: flex; align-items: center; justify-content: center; height: 100vh; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; flex-direction: column; padding: 24px; box-sizing: border-box; }
                .loader { width: 40px; height: 40px; border: 4px solid #e5e7eb; border-top: 4px solid #54A6D8; border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 16px; }
                @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
                .text { color: #54A6D8; font-weight: bold; font-size: 14px; letter-spacing: 0.5px; }
                .card { max-width: 420px; text-align: center; }
                .btn-descarga { display: inline-block; background: #54A6D8; color: #fff; font-weight: bold; padding: 14px 28px; border-radius: 12px; text-decoration: none; margin-top: 16px; }
                .aviso { color: #B45309; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 10px; padding: 12px 14px; font-size: 12px; margin-top: 20px; line-height: 1.5; text-align: left; }
            </style>
        </head>
        <body>
            <?php if ($link_invitado): ?>
                <div class="card">
                    <div style="font-size:40px; margin-bottom:8px;">✅</div>
                    <h1 style="font-size:20px; color:#111827; margin:0 0 8px;">¡Compra exitosa!</h1>
                    <p style="color:#374151; font-size:14px; margin:0;">Tu apunte está listo para descargar.</p>
                    <a href="<?= htmlspecialchars($link_invitado, ENT_QUOTES, 'UTF-8') ?>" class="btn-descarga" download>Descargar mi apunte</a>
                    <div class="aviso">
                        <strong>Guarda este link ahora.</strong> No pediste registro ni correo, así que esta es tu única oportunidad de acceder al archivo — si cierras esta página sin guardarlo, no hay forma de recuperarlo.
                    </div>
                </div>
            <?php else: ?>
                <div class="loader"></div>
                <div class="text">Preparando tu apunte...</div>
                <script>
                    // Esperamos 600ms para asegurar que Meta reciba el evento, luego redirigimos al apunte
                    setTimeout(() => {
                        window.location.replace("<?= $url_destino ?>");
                    }, 600);
                </script>
            <?php endif; ?>
        </body>
        </html>
        <?php
        exit;

    } catch (Throwable $db_e) {
        $conn->rollback();
        error_log("NUBIRA PAGO APUNTE FALLIDO: payment_id=$payment_id apunte=$apunte_id usuario=$usuario_id error=" . $db_e->getMessage());
        die("❌ Error al registrar la compra. Contacta soporte indicando tu payment_id: $payment_id");
    }

} catch (Exception $e) {
    error_log("Error API MP en Pago Exitoso: " . $e->getMessage());
    header("Location: /vitrina-apuntes?error=mp");
    exit;
}
?>
