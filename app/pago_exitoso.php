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

    // 2. RESCATE DE SESIÓN (Soluciona el error "usuario_id cannot be null")
    $usuario_id  = $_SESSION['usuario_id'] ?? null;
    $institucion = $_SESSION['institucion'] ?? null;

    if (!$usuario_id && isset($payment->metadata->usuario_id)) {
        $usuario_id = (int)$payment->metadata->usuario_id;
    }
    
    if (!$usuario_id) {
        die("❌ ERROR CRÍTICO: Se perdió la sesión y MercadoPago no devolvió el ID del comprador.");
    }

    // 3. LÓGICA DE BASE DE DATOS
    try {
        $conn->begin_transaction();

        // A) Evitar duplicidad en tabla 'compras'
        $stmt = $conn->prepare("SELECT id FROM compras WHERE payment_id = ? LIMIT 1");
        $stmt->bind_param("s", $payment_id);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows === 0) {
            $stmt->close();
            // Inserción segura con servicio_id = 0
            $monto_int = (int)$monto; 
            $servicio_cero = 0;
            
            $stmtInsert = $conn->prepare("INSERT INTO compras (id_apunte, usuario_id, servicio_id, monto, estado_pago, payment_id, fecha) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmtInsert->bind_param("iiiiss", $apunte_id, $usuario_id, $servicio_cero, $monto_int, $estado_nubira, $payment_id);    
            $stmtInsert->execute();
            $stmtInsert->close();
        } else {
            $stmt->close();
            // Reparación de estado si quedó como 'approved'
            $stmtUpdate = $conn->prepare("UPDATE compras SET estado_pago = ? WHERE payment_id = ?");
            $stmtUpdate->bind_param("ss", $estado_nubira, $payment_id);
            $stmtUpdate->execute();
            $stmtUpdate->close();
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
                // Recuperar nombre del archivo para la redirección
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
              !function(f,b,e,v,n,t,s)
              {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
              n.callMethod.apply(n,arguments):n.queue.push(arguments)};
              if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
              n.queue=[];t=b.createElement(e);t.async=!0;
              t.src=v;s=b.getElementsByTagName(e)[0];
              s.parentNode.insertBefore(t,s)}(window, document,'script',
              'https://connect.facebook.net/en_US/fbevents.js');
              
              fbq('init', '949858788026352'); 
              fbq('track', 'Purchase', {
                  value: <?= (int)$monto ?>,
                  currency: 'CLP',
                  content_ids: ['<?= (int)$apunte_id ?>'],
                  content_type: 'product'
              });
            </script>
            <noscript>
              <img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=949858788026352&ev=Purchase&noscript=1" />
            </noscript>
            <style>
                body { margin: 0; background: #ffffff; display: flex; align-items: center; justify-content: center; height: 100vh; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; flex-direction: column; }
                .loader { width: 40px; height: 40px; border: 4px solid #e5e7eb; border-top: 4px solid #54A6D8; border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 16px; }
                @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
                .text { color: #54A6D8; font-weight: bold; font-size: 14px; letter-spacing: 0.5px; }
            </style>
        </head>
        <body>
            <div class="loader"></div>
            <div class="text">Preparando tu apunte...</div>

            <script>
                // Esperamos 600ms para asegurar que Meta reciba el evento, luego redirigimos al apunte
                setTimeout(() => {
                    window.location.replace("<?= $url_destino ?>");
                }, 600);
            </script>
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