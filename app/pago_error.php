<?php
/**
 * VISTA: ERROR DE PAGO (Callback de MercadoPago)
 * UBICACIÓN: public_html/app/pago_error.php
 * ESTADO: Nubira 2.0 - UX Empática y Segura
 */
session_start();

if (!isset($_SESSION['usuario_id'])) { 
    header("Location: /login"); 
    exit; 
}

$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists(dirname($app_dir) . '/conexion.php')) {
        $app_dir = dirname($app_dir);
    }
}
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

// Capturamos los datos que envía MercadoPago por la URL (GET) de forma segura
$status = htmlspecialchars($_GET['status'] ?? 'rejected');
$payment_id = htmlspecialchars($_GET['payment_id'] ?? 'Desconocido');
$external_reference = (int)($_GET['external_reference'] ?? 0);
$payment_type = htmlspecialchars($_GET['payment_type'] ?? 'N/A');

// Lógica de mensajes según el estado de MercadoPago
$titulo_error = "Pago no procesado";
$mensaje_error = "Tu pago no pudo ser completado. Tu tarjeta fue rechazada o hubo un problema de conexión con el banco. <strong>No te preocupes, no se te ha cobrado nada.</strong>";

if ($status === 'null' || $status === 'cancelled') {
    $titulo_error = "Pago cancelado";
    $mensaje_error = "Cancelaste el proceso de pago antes de finalizar. Si fue un error, puedes volver a intentarlo cuando estés listo.";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Error en el Pago | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    .animate-fade-in-up { animation: fadeInUp 0.5s ease-out forwards; }
    @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
  </style>
</head>

<body class="bg-gray-50 text-slate-800 antialiased overflow-x-hidden">

<div id="loader" class="fixed inset-0 bg-white/95 flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-10 w-10 border-4 border-blue-100 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
require_once $app_dir . '/componentes/header.php'; 
require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-24 pb-32 md:pb-12 lg:ml-64 px-4 md:px-8 max-w-[1600px] mx-auto min-h-[85vh] flex items-center justify-center animate-fade-in-up">
    
    <div class="bg-white p-8 md:p-10 rounded-3xl border border-red-100 shadow-lg max-w-md w-full text-center relative overflow-hidden">
        
        <div class="absolute -top-10 -right-10 w-32 h-32 bg-red-50 rounded-full blur-2xl pointer-events-none"></div>

        <div class="w-20 h-20 bg-red-50 border-4 border-white shadow-sm text-red-500 rounded-full flex items-center justify-center mx-auto mb-6 relative z-10">
            <i class="fa-solid fa-triangle-exclamation text-3xl"></i>
        </div>

        <div class="relative z-10">
            <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight mb-3"><?= $titulo_error ?></h1>
            <p class="text-slate-500 text-sm leading-relaxed mb-6">
                <?= $mensaje_error ?>
            </p>
        </div>

        <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100 mb-8 text-left relative z-10">
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider mb-2">Detalles de la transacción</p>
            <div class="flex items-center justify-between mb-1">
                <span class="text-xs font-medium text-slate-500">Ref. Operación:</span>
                <span class="text-xs font-bold text-slate-700">#<?= $external_reference ?: 'N/A' ?></span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-slate-500">ID MercadoPago:</span>
                <span class="text-xs font-bold text-slate-700 truncate max-w-[120px]" title="<?= $payment_id ?>"><?= $payment_id ?></span>
            </div>
        </div>

        <div class="flex flex-col gap-3 relative z-10">
            <button onclick="window.history.back()" class="w-full bg-[#54A6D8] text-white font-bold py-3.5 px-4 rounded-xl hover:bg-blue-600 transition-all hover:shadow-md hover:scale-[1.01] text-sm flex items-center justify-center gap-2">
                <i class="fa-solid fa-arrow-rotate-left"></i> Intentar de nuevo
            </button>
            <a href="/vitrina" class="w-full bg-white text-slate-600 border border-slate-200 font-bold py-3.5 px-4 rounded-xl hover:bg-slate-50 transition-all text-sm flex items-center justify-center gap-2">
                <i class="fa-solid fa-house"></i> Volver al inicio
            </a>
        </div>
        
    </div>

</main>

<?php 
require_once $app_dir . '/componentes/nav_bottom.php'; 
require_once $app_dir . '/componentes/modal_publicar.php'; 
require_once $app_dir . '/componentes/modal_explora.php'; 
?>

<script>
window.onload = () => { 
    const l = document.getElementById('loader'); 
    if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } 
};

// Modales del ecosistema
function setupModal(triggerId, modalId, cardId, closeId) {
    const btn=document.getElementById(triggerId), modal=document.getElementById(modalId), card=document.getElementById(cardId), close=document.getElementById(closeId);
    if(!btn||!modal) return;
    const open=()=>{modal.classList.remove('hidden'); requestAnimationFrame(()=>card.classList.remove('translate-y-full','opacity-0')); document.body.style.overflow='hidden';};
    const shut=()=>{card.classList.add('translate-y-full','opacity-0'); setTimeout(()=>{modal.classList.add('hidden');document.body.style.overflow='';},300);};
    btn.onclick=(e)=>{e.preventDefault();open();}; 
    if(close) close.onclick=shut; 
    modal.onclick=(e)=>{if(e.target===modal)shut();};
}
setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
</script>

</body>
</html>