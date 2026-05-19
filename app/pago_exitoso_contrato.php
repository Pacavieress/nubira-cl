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

$mp_status = $_GET['collection_status'] ?? 'approved'; // Por defecto approved si no viene (ej: pago gratis)
$mp_ref    = $_GET['external_reference'] ?? '';
$mp_id     = $_GET['collection_id'] ?? '';

// Registrar retorno de Mercado Pago en log
file_put_contents(__DIR__ . '/../log_envio.txt',
    date("Y-m-d H:i:s") . " - [MP_RETURN] contrato_id={$id_contrato}, status={$mp_status}, ref={$mp_ref}, id={$mp_id}\n",
    FILE_APPEND
);

// Si el pago fue rechazado o está en revisión profunda, detenemos la activación
if ($mp_status !== 'approved' && $mp_status !== 'in_process') {
    // CORRECCIÓN: Usar la ruta oficial que definimos en iniciar_pago_servicio.php
    header("Location: /app/pago_error_contrato.php?contrato_id=" . $id_contrato);
    exit;
}

// 2. BUSCAR DATOS DEL CONTRATO (BLINDADO CONTRA IDOR)
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

// 4. SISTEMA DE SALDOS/RETIROS (Solo si no fue procesado)
if (!$yaProcesado) {
    try {
        $insert = $conn->prepare("
            INSERT INTO retiros (vendedor_id, contrato_id, monto)
            SELECT vendedor_id, id, monto
            FROM contratos
            WHERE id = ? 
              AND id NOT IN (SELECT contrato_id FROM retiros)
        ");
        $insert->bind_param("i", $id_contrato);
        $insert->execute();
        $insert->close();
    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/../log_envio.txt', date("Y-m-d H:i:s") . " - ❌ Error insertando retiro: " . $e->getMessage() . "\n", FILE_APPEND);
    }
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
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Pago exitoso | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    
    <?php if (!$yaProcesado): ?>
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
          value: <?= (int)$contrato['monto'] ?>,
          currency: 'CLP',
          content_ids: ['<?= (int)$contrato['servicio_id'] ?>'],
          content_type: 'product',
          content_name: 'Tutoría: <?= htmlspecialchars($contrato['servicio_titulo'], ENT_QUOTES, 'UTF-8') ?>'
      });
    </script>
    <noscript>
      <img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=949858788026352&ev=Purchase&noscript=1" />
    </noscript>
    <?php else: ?>
    <script>
      // Pixel base solo para no duplicar compras si recarga la página
      !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window, document,'script','https://connect.facebook.net/en_US/fbevents.js');
      fbq('init', '949858788026352'); fbq('track', 'PageView');
    </script>
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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