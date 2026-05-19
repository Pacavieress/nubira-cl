<?php
/**
 * VISTA: ERROR DE PAGO
 * UBICACIÓN: public_html/pago_error.php
 * ESTÁNDAR: Nubira 2.0 (Clean & Professional)
 */

session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* ===============================
   1. RUTAS Y DEPENDENCIAS
================================ */
$base_path = __DIR__;
if (file_exists(__DIR__ . '/app/conexion.php')) {
    $base_path = __DIR__ . '/app';
} elseif (file_exists(__DIR__ . '/../app/conexion.php')) {
    $base_path = __DIR__ . '/../app';
}

require_once $base_path . '/conexion.php';
// Si tienes el archivo de iconos, lo usamos. Si no, usamos SVG directo.
if (file_exists($base_path . '/iconos.php')) require_once $base_path . '/iconos.php';

/* ===============================
   2. CAPTURA Y LÓGICA
================================ */
// Parámetros de Mercado Pago
$status             = $_GET['status']             ?? null;
$external_reference = $_GET['external_reference'] ?? null;
$payment_type       = $_GET['payment_type']       ?? null;
$collection_status  = $_GET['collection_status']  ?? null;

// Logging (Mantenemos tu lógica de debug, es útil)
error_log("PagoError [Nubira] - Ref: {$external_reference}, Status: {$status}, Type: {$payment_type}");

// 1. Detección del tipo de error
$titulo  = "El pago no se completó";
$mensaje = "El proceso fue interrumpido. No se ha realizado ningún cargo a tu cuenta.";
$icono   = "cancel"; // internal flag

if ($status === 'rejected') {
    $titulo  = "Pago rechazado";
    $mensaje = "La tarjeta o medio de pago declinó la transacción. Por favor, intenta con otro medio de pago.";
    $icono   = "error";
} elseif ($status === 'in_process' || $status === 'pending') {
    $titulo  = "Pago en revisión";
    $mensaje = "Tu pago se está procesando. Te avisaremos cuando se confirme.";
    $icono   = "pending";
}

// 2. Recuperar ID del apunte si es posible (Opcional, para redirigir mejor)
// Si external_reference es el ID de la compra, podrías hacer una query aquí para saber qué apunte era.
// Por ahora, redirigimos a la vitrina o al reintento genérico.

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= htmlspecialchars($titulo) ?> | Nubira</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .animate-fade-in { animation: fadeIn 0.6s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="bg-[#F7F7F9] min-h-screen flex items-center justify-center p-4">

    <div class="max-w-md w-full bg-white rounded-3xl shadow-xl border border-gray-100 overflow-hidden text-center p-8 animate-fade-in relative">
        
        <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-red-400 to-orange-400"></div>

        <div class="w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-6 <?= ($icono == 'pending') ? 'bg-yellow-50 text-yellow-500' : 'bg-red-50 text-red-500' ?>">
            <?php if ($icono === 'pending'): ?>
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <?php else: ?>
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            <?php endif; ?>
        </div>

        <h1 class="text-2xl font-bold text-gray-900 mb-3 tracking-tight">
            <?= htmlspecialchars($titulo) ?>
        </h1>
        <p class="text-gray-500 mb-8 leading-relaxed text-sm">
            <?= htmlspecialchars($mensaje) ?>
        </p>

        <div class="space-y-3">
            
            <?php if ($external_reference): ?>
            <a href="/iniciar-pago?reference=<?= urlencode($external_reference) ?>" 
               class="block w-full bg-[#54A6D8] hover:bg-[#4895c2] text-white font-bold py-3.5 rounded-2xl transition-all shadow-md shadow-blue-100 hover:shadow-lg hover:scale-[1.01]">
                Intentar nuevamente
            </a>
            <?php else: ?>
            <a href="/vitrina-apuntes" 
               class="block w-full bg-[#54A6D8] hover:bg-[#4895c2] text-white font-bold py-3.5 rounded-2xl transition-all shadow-md shadow-blue-100">
                Volver a la vitrina
            </a>
            <?php endif; ?>

            <a href="/vitrina-apuntes" class="block w-full bg-white border border-gray-200 text-gray-600 font-bold py-3.5 rounded-2xl hover:bg-gray-50 transition-all">
                Cancelar y volver
            </a>
        </div>

        <div class="mt-8 pt-6 border-t border-gray-50">
            <p class="text-[10px] text-gray-400 uppercase tracking-wider font-bold mb-1">Detalles para soporte</p>
            <p class="text-xs text-gray-400 font-mono">
                Ref: <?= htmlspecialchars($external_reference ?? 'N/A') ?>
                <?php if ($status): ?> | Est: <?= htmlspecialchars($status) ?> <?php endif; ?>
            </p>
        </div>

    </div>

</body>
</html>