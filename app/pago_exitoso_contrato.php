<?php
/**
 * VISTA/PROCESO: PAGO EXITOSO (RETORNO DE MERCADOPAGO)
 * OBJETIVO: Conciliar pago, cambiar estado a 'en_progreso', notificar y mostrar UI de éxito.
 */
session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;

// 1. SEGURIDAD Y CAPTURA DE PARÁMETROS
if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login');
    exit;
}

// Soporte para la nueva nomenclatura de Nubira 2.0 (?contrato_id=XX)
$id_contrato = (int)($_GET['contrato_id'] ?? $_GET['id'] ?? 0);
if ($id_contrato <= 0) {
    exit("❌ Contrato inválido o no especificado.");
}

$mp_payment_id = $_GET['payment_id'] ?? $_GET['collection_id'] ?? '';
$mp_ref        = $_GET['external_reference'] ?? '';

// 2. BUSCAR DATOS DEL CONTRATO (BLINDADO CONTRA IDOR)
// Se hace ANTES de la verificación de MP: un contrato gratis (monto=0, cupón
// 100%) nunca pasa por MercadoPago y no trae payment_id — necesitamos saber
// esto antes de decidir si exigimos la llamada a la API.
$usuario_actual = (int)$_SESSION['usuario_id'];

$stmt = $conn->prepare("
    SELECT c.*, s.titulo AS servicio_titulo,
           a.nombre AS comprador_nombre, a.correo AS comprador_correo,
           b.nombre AS vendedor_nombre, b.correo AS vendedor_correo
    FROM contratos c
    JOIN servicios s ON c.servicio_id = s.id
    JOIN alumnos a ON c.comprador_id = a.id
    JOIN alumnos b ON c.vendedor_id = b.id
    WHERE c.id = ? AND c.comprador_id = ?
");
// Exigimos que el ID coincida Y que el usuario logueado sea el comprador
$stmt->bind_param("ii", $id_contrato, $usuario_actual);
$stmt->execute();
$contrato = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contrato) {
    // UX: Mensaje de error discreto para no dar pistas a posibles atacantes
    exit("❌ No se encontró el contrato o no tienes permisos para visualizar esta transacción.");
}

// [NUBIRA SHIELD] Verificación REAL contra la API de MercadoPago — nunca confiar
// en el query string que manda el navegador. Antes: si no venía collection_status
// se asumía 'approved' por defecto, y aunque viniera, lo controlaba el propio
// navegador del comprador (bastaba abrir esta URL a mano para "aprobarse solo").
//
// Excepción legítima: un contrato con monto=0 (beca/cupón 100%) se resuelve
// directo en crear_contrato.php / iniciar_pago_servicio.php sin pasar nunca por
// MercadoPago. Acá solo lo TOLERAMOS por idempotencia (si alguien llega a esta
// URL para un contrato que ya quedó gratis/activo por ese otro camino, no lo
// mandamos al error) — nunca es este archivo el que decide que es gratis.
$es_gratis_o_ya_activo = ((int)round((float)$contrato['monto']) === 0 || $contrato['estado'] === 'en_progreso');

$mp_status = null;
if ($es_gratis_o_ya_activo) {
    $mp_status = 'approved';
} elseif (!empty($mp_payment_id)) {
    MercadoPagoConfig::setAccessToken(MP_ACCESS_TOKEN);
    try {
        $client  = new PaymentClient();
        $payment = $client->get($mp_payment_id);

        // [NUBIRA SHIELD] Anti-suplantación: el payment_id debe pertenecer a ESTE
        // contrato. Sin esto, alguien podría reusar el payment_id de OTRO pago
        // aprobado suyo (ej. un apunte barato) para "aprobarse" un contrato caro.
        $ref_esperada = 'CONTRATO_' . $id_contrato;
        $ref_recibida = $payment->external_reference ?? null;
        if ($ref_recibida !== $ref_esperada) {
            error_log("Nubira SHIELD | pago_exitoso_contrato.php: external_reference no coincide. contrato_id={$id_contrato} esperado={$ref_esperada} recibido=" . ($ref_recibida ?? 'null') . " payment_id={$mp_payment_id}");
            $mp_status = null;
        } elseif ((int)round((float)($payment->transaction_amount ?? -1)) !== (int)round((float)$contrato['monto'])) {
            error_log("Nubira SHIELD | pago_exitoso_contrato.php: monto no coincide. contrato_id={$id_contrato} esperado={$contrato['monto']} recibido=" . ($payment->transaction_amount ?? 'null') . " payment_id={$mp_payment_id}");
            $mp_status = null;
        } else {
            $mp_status = $payment->status ?? null;
        }
    } catch (\Throwable $e) {
        error_log("Nubira | pago_exitoso_contrato.php: error consultando MP payment_id={$mp_payment_id}: " . $e->getMessage());
        $mp_status = null;
    }
}

// Registrar retorno de Mercado Pago en log
file_put_contents(__DIR__ . '/../log_envio.txt',
    date("Y-m-d H:i:s") . " - [MP_RETURN] contrato_id={$id_contrato}, payment_id={$mp_payment_id}, status_api=" . ($mp_status ?? 'null') . ", ref={$mp_ref}\n",
    FILE_APPEND
);

// Si la API de MercadoPago no confirma un pago aprobado (o en proceso), no activamos nada.
if ($mp_status !== 'approved' && $mp_status !== 'in_process') {
    header("Location: /app/pago_error_contrato.php?contrato_id=" . $id_contrato);
    exit;
}

// 3. LÓGICA DE ESTADOS Y CONCILIACIÓN
$yaProcesado = false;

if ($contrato['estado'] === 'en_progreso') {
    // Ya fue procesado (quizás por el Webhook de MP que llegó antes que la redirección del usuario)
    $yaProcesado = true;
} elseif ($contrato['estado'] === 'pendiente_pago') {
    // 1. Actualizamos a 'en_progreso'
    $update = $conn->prepare("UPDATE contratos SET estado = 'en_progreso', fecha_pago = NOW() WHERE id = ?");
    $update->bind_param("i", $id_contrato);
    $update->execute();
    $update->close();
    
    // 2. [FIX NUBIRA] DESCUENTO DE CUPO ATÓMICO 
    // Solo si el servicio de este contrato tenía un subsidio de oferta
    if (isset($contrato['servicio_id'])) {
        $stmt_cupos = $conn->prepare("
            UPDATE servicios 
            SET cupos_oferta = cupos_oferta - 1 
            WHERE id = ? AND is_subvencionado = 1 AND cupos_oferta > 0
        ");
        $stmt_cupos->bind_param("i", $contrato['servicio_id']);
        $stmt_cupos->execute();
        $stmt_cupos->close();
    }
    
    $yaProcesado = false;
} else {
    // Si está completado, cancelado, etc.
    exit("⚠️ El contrato no puede cambiar de estado (actual: " . htmlspecialchars($contrato['estado']) . ").");
}

// 4. MARCAR SLOT DE EXCEPCIÓN (Solo si no fue procesado)
if (!$yaProcesado) {
    // Marcar slot de excepción como pagado (0 filas afectadas en contratos normales)
    $stmt_slot = $conn->prepare("UPDATE slots_excepcion SET estado = 'pagado' WHERE contrato_id = ?");
    $stmt_slot->bind_param("i", $id_contrato);
    $stmt_slot->execute();
    $stmt_slot->close();
}

// 5. REGISTRAR EVENTO EN LOG DEL CONTRATO
$evento = $yaProcesado ? 'PAGO_DUPLICADO_WEB' : 'PAGO_CONFIRMADO';
$detalle = "Confirmado desde página de éxito. Monto $" . number_format((float)$contrato['monto'], 0, ',', '.');
$log = $conn->prepare("INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle) VALUES (?, ?, ?, ?)");
$log->bind_param("iiss", $id_contrato, $_SESSION['usuario_id'], $evento, $detalle);
$log->execute();
$log->close();

// 6. ENVIAR CORREOS AUTOMÁTICOS
if (!$yaProcesado) {
    // Lógica de privacidad de nombres Nubira
    $formatearNombre = function($nombreCompleto) {
        $partes = explode(' ', trim($nombreCompleto));
        return htmlspecialchars($partes[0] . (isset($partes[1]) ? ' ' . substr($partes[1], 0, 1) . '.' : ''), ENT_QUOTES, 'UTF-8');
    };

    $compradorPrivado = $formatearNombre($contrato['comprador_nombre']);
    $vendedorPrivado  = $formatearNombre($contrato['vendedor_nombre']);
    $tituloServicio   = htmlspecialchars($contrato['servicio_titulo'], ENT_QUOTES, 'UTF-8');
    $montoFmt         = number_format((float)$contrato['monto'], 0, ',', '.');

    // --- Correo Comprador ---
    $asuntoC = "✅ Pago en custodia confirmado - Nubira";
    $bodyC = '
      <div style="max-width:520px;margin:auto;padding:24px;background:#fff;border-radius:12px;font-family:sans-serif;border:1px solid #e5e7eb;">
        <h2 style="color:#54A6D8;margin:0 0 12px">¡Pago asegurado!</h2>
        <p>Tu pago por <b>' . $tituloServicio . '</b> ha sido recibido y está protegido en nuestra bóveda virtual.</p>
        <p>Monto: <b>$' . $montoFmt . '</b></p>
        <p>Tu tutor <b>' . $vendedorPrivado . '</b> ya ha sido notificado para comenzar el servicio.</p>
        <p style="font-size:12px;color:#6b7280;margin-top:20px;">Recuerda: El pago solo se liberará al tutor cuando confirmes que recibiste el servicio correctamente.</p>
      </div>';
    $txtC = "Pago confirmado. Servicio: {$tituloServicio}. Monto: \${$montoFmt}.";
    enviarCorreo($contrato['comprador_correo'], $asuntoC, $bodyC, $txtC);

    // --- Correo Vendedor ---
    $asuntoV = "💼 Nuevo servicio contratado - Nubira";
    $bodyV = '
      <div style="max-width:520px;margin:auto;padding:24px;background:#fff;border-radius:12px;font-family:sans-serif;border:1px solid #e5e7eb;">
        <h2 style="color:#54A6D8;margin:0 0 12px">¡Tienes un nuevo contrato!</h2>
        <p>El estudiante <b>' . $compradorPrivado . '</b> ha realizado el pago en custodia por tu servicio <b>' . $tituloServicio . '</b>.</p>
        <p>Monto acordado: <b>$' . $montoFmt . '</b></p>
        <p>Dirígete al Aula Virtual de Nubira para coordinar los detalles del servicio.</p>
        <p style="font-size:12px;color:#6b7280;margin-top:20px;">El dinero será liberado a tu cuenta de retiro una vez que se finalice el contrato.</p>
      </div>';
    $txtV = "Pago recibido por {$tituloServicio}. Monto: \${$montoFmt}. Comprador: {$compradorPrivado}.";
    enviarCorreo($contrato['vendedor_correo'], $asuntoV, $bodyV, $txtV);
    require_once __DIR__ . '/enviar_push_nubira.php';
    $t = mb_substr($tituloServicio, 0, 50);
    enviar_push_nubira((int)$contrato['comprador_id'], '✅ Pago confirmado', 'Tu pago por "' . $t . '" fue procesado', '/mis-contratos');
    enviar_push_nubira((int)$contrato['vendedor_id'], '💰 Pago recibido', 'Recibiste pago por "' . $t . '"', '/clases-vendidas');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Pago exitoso | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>

    <?php if (!$yaProcesado): ?>
    <script>
      if (typeof fbq === 'function') {
          fbq('track', 'Purchase', {
              value: <?= (int)$contrato['monto'] ?>,
              currency: 'CLP',
              content_ids: ['<?= (int)$contrato['servicio_id'] ?>'],
              content_type: 'product',
              content_name: 'Tutoría: <?= htmlspecialchars($contrato['servicio_titulo'], ENT_QUOTES, 'UTF-8') ?>'
          });
      }
    </script>
    <noscript>
      <img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=2149832959284130&ev=Purchase&noscript=1" />
    </noscript>
    <?php endif; ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-screen p-4 antialiased">
    
    <div class="bg-white rounded-3xl shadow-xl shadow-gray-200/50 p-8 text-center max-w-[480px] w-full border border-gray-100 transform transition-all">
        
        <div class="w-20 h-20 bg-green-50 rounded-full flex items-center justify-center mx-auto mb-6">
            <i class="fa-solid fa-check text-4xl text-green-500"></i>
        </div>

        <h1 class="text-2xl font-bold text-gray-900 mb-3">¡Pago asegurado!</h1>
        
        <p class="text-gray-500 text-sm leading-relaxed mb-8">
            Tu pago en custodia por <span class="font-semibold text-gray-900"><?= htmlspecialchars($contrato['servicio_titulo'], ENT_QUOTES, 'UTF-8') ?></span> se ha procesado con éxito. El tutor ya fue notificado.
        </p>
        
        <div class="bg-gray-50 rounded-2xl p-4 mb-8 text-left flex items-start gap-3 border border-gray-100">
            <i class="fa-solid fa-shield-halved text-[#54A6D8] mt-1"></i>
            <div>
                <p class="text-xs font-bold text-gray-900">Dinero protegido por Nubira</p>
                <p class="text-[11px] text-gray-500 mt-0.5">El pago no se entregará al tutor hasta que el servicio sea realizado por completo y tú estés conforme.</p>
            </div>
        </div>

        <a href="/app/mini_aula.php?id=<?= $id_contrato ?>" 
           class="block w-full bg-[#54A6D8] hover:bg-blue-600 text-white px-6 py-4 rounded-xl font-bold transition-colors shadow-lg shadow-blue-200">
            Ir al Aula Virtual <i class="fa-solid fa-arrow-right ml-2"></i>
        </a>
        
        <a href="/vitrina" class="block w-full text-gray-400 hover:text-gray-600 font-medium text-sm mt-4 transition-colors">
            Volver a la vitrina
        </a>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <script>
        window.onload = function() {
            var duration = 3 * 1000;
            var end = Date.now() + duration;
            (function frame() {
                confetti({ particleCount: 5, angle: 60, spread: 55, origin: { x: 0 }, colors: ['#54A6D8', '#10B981'] });
                confetti({ particleCount: 5, angle: 120, spread: 55, origin: { x: 1 }, colors: ['#54A6D8', '#10B981'] });
                if (Date.now() < end) { requestAnimationFrame(frame); }
            }());
        };
    </script>
</body>
</html>
