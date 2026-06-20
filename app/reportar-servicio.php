<?php
/**
 * VISTA: REPORTAR SERVICIO
 * ESTADO: Nubira 2.0 - Lógica Intacta + Ecosistema Completo Integrado
 */
session_start();

// 1. RUTAS ROBUSTAS (Igual a vitrina y mis_archivos)
$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists($app_dir . '/app/conexion.php')) $app_dir = $app_dir . '/app';
    elseif (file_exists(dirname($app_dir) . '/app')) $app_dir = dirname($app_dir) . '/app';
    elseif (file_exists(dirname($app_dir) . '/conexion.php')) $app_dir = dirname($app_dir);
}
require_once $app_dir . '/conexion.php';
if (file_exists($app_dir . '/iconos.php')) {
    require_once $app_dir . '/iconos.php';
}

// 2. SEGURIDAD
if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$usuario_id  = $_SESSION['usuario_id'];
$servicio_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$titulo_servicio = "";

// Validar que el ID sea válido
if ($servicio_id < 1) {
    header("Location: /servicios");
    exit;
}

// ==========================================
// 3. LÓGICA ORIGINAL (Manejo de Estados)
// ==========================================
$estado_vista = 'formulario'; // Estado por defecto
$feedback = "";
$exito = false;

// Verifica que el servicio exista y obtiene el título
$stmt = $conn->prepare("SELECT titulo FROM servicios WHERE id = ?");
$stmt->bind_param("i", $servicio_id);
$stmt->execute();
$stmt->bind_result($titulo_servicio);
$stmt->fetch();
$stmt->close();

if (!$titulo_servicio) {
    $estado_vista = 'no_existe';
} else {
    // ¿Ya reportó este servicio?
    $stmt = $conn->prepare("SELECT 1 FROM reportes_servicio WHERE servicio_id=? AND usuario_id=?");
    $stmt->bind_param("ii", $servicio_id, $usuario_id);
    $stmt->execute();
    $stmt->store_result();
    $ya_reporto = $stmt->num_rows > 0;
    $stmt->close();

    if ($ya_reporto) {
        $estado_vista = 'ya_reporto';
    } else {
        // Formulario de reporte
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $motivo  = trim($_POST['motivo'] ?? '');
            $mensaje = trim($_POST['mensaje'] ?? '');

            if (empty($motivo)) {
                $feedback = "Debes seleccionar un motivo para el reporte.";
            } else {
                $stmt = $conn->prepare("INSERT INTO reportes_servicio (servicio_id, usuario_id, motivo, mensaje) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iiss", $servicio_id, $usuario_id, $motivo, $mensaje);
                if ($stmt->execute()) {
                    $estado_vista = 'exito';
                } else {
                    $feedback = "Ocurrió un error al guardar tu reporte. Inténtalo de nuevo.";
                }
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Reportar servicio | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    .animate-fade-in-up { animation: fadeInUp 0.4s ease-out forwards; }
    @keyframes fadeInUp { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
  </style>
</head>
<body class="bg-gray-50 text-slate-800 antialiased overflow-x-hidden">

<div id="loader" class="fixed inset-0 bg-white/95 flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-10 w-10 border-4 border-blue-100 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
// COMPONENTES PRINCIPALES
require_once $app_dir . '/componentes/header.php'; 
require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-24 pb-32 md:pb-12 lg:ml-64 px-4 md:px-8 max-w-[1600px] mx-auto min-h-[85vh] flex items-center justify-center animate-fade-in-up relative">

    <?php if ($estado_vista === 'no_existe'): ?>
        <div class="bg-white max-w-md w-full p-8 md:p-10 rounded-3xl shadow-lg text-center border border-slate-100 relative overflow-hidden">
            <div class="w-16 h-16 bg-red-50 text-red-500 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-triangle-exclamation text-2xl"></i>
            </div>
            <h1 class="text-xl font-bold text-slate-900 mb-2 tracking-tight">Servicio no encontrado</h1>
            <p class="text-slate-500 text-sm mb-6">El servicio que intentas reportar ya no existe o fue eliminado.</p>
            <a href="/servicios" class="block w-full bg-slate-800 text-white rounded-xl font-bold py-3.5 hover:bg-slate-700 transition shadow-sm">
                Volver a servicios
            </a>
        </div>

    <?php elseif ($estado_vista === 'ya_reporto'): ?>
        <div class="bg-white max-w-md w-full p-8 md:p-10 rounded-3xl shadow-lg text-center border border-slate-100 relative overflow-hidden">
            <div class="w-16 h-16 bg-amber-50 text-amber-500 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-clock-rotate-left text-2xl"></i>
            </div>
            <h1 class="text-xl font-extrabold text-slate-900 mb-2 tracking-tight">Ya reportaste este servicio</h1>
            <p class="text-slate-500 text-sm mb-6 leading-relaxed">Nuestro equipo de moderación ya tiene tu reporte en revisión. Tomaremos las acciones necesarias si corresponde.</p>
            <a href="/detalle-servicio/<?= (int)$servicio_id ?>" class="inline-flex items-center justify-center gap-2 w-full bg-[#54A6D8] text-white rounded-xl font-bold py-3.5 hover:bg-blue-600 transition shadow-sm">
              <i class="fa-solid fa-arrow-left"></i> Volver al servicio
            </a>
        </div>

    <?php elseif ($estado_vista === 'exito'): ?>
        <div class="bg-white max-w-md w-full p-8 md:p-10 rounded-3xl shadow-lg border border-slate-100 text-center relative overflow-hidden">
            <div class="absolute -top-10 -right-10 w-32 h-32 bg-green-50 rounded-full blur-2xl pointer-events-none"></div>
            
            <div class="w-20 h-20 bg-green-50 text-green-500 rounded-full flex items-center justify-center mx-auto mb-5 relative z-10">
                <i class="fa-solid fa-check text-4xl"></i>
            </div>
            <h1 class="text-2xl font-extrabold text-slate-900 mb-2 tracking-tight relative z-10">¡Reporte enviado!</h1>
            <p class="text-slate-500 text-sm mb-8 leading-relaxed relative z-10">Gracias por ayudarnos a mantener la comunidad segura. Nuestro equipo revisará la publicación en breve.</p>
            
            <a href="/detalle-servicio/<?= (int)$servicio_id ?>" class="inline-flex items-center justify-center gap-2 w-full bg-slate-800 text-white rounded-xl font-bold py-3.5 hover:bg-slate-700 transition shadow-sm relative z-10 hover:-translate-y-0.5">
                <i class="fa-solid fa-arrow-left text-sm"></i> Volver al servicio
            </a>
        </div>

    <?php else: ?>
        <form method="POST" class="bg-white max-w-md w-full p-6 md:p-8 rounded-3xl shadow-lg border border-slate-100 relative">
            
            <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-100">
                <div class="w-10 h-10 bg-red-50 text-red-500 rounded-full flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-flag text-lg"></i>
                </div>
                <div>
                    <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">Reportar servicio</h1>
                    <p class="text-xs text-slate-500 font-medium mt-0.5">Ayúdanos a revisar esta publicación.</p>
                </div>
            </div>

            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100 mb-6">
                <span class="block text-[10px] text-slate-400 font-bold uppercase tracking-wider mb-1">Publicación a reportar:</span>
                <span class="block text-sm text-slate-800 font-bold line-clamp-2"><?= htmlspecialchars($titulo_servicio) ?></span>
            </div>

            <?php if ($feedback): ?>
                <div class="mb-5 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm font-semibold flex items-center gap-2">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?= htmlspecialchars($feedback) ?>
                </div>
            <?php endif; ?>

            <div class="space-y-5">
                <div>
                    <label for="motivo" class="block text-sm font-bold text-slate-700 mb-1.5">¿Por qué estás reportando esto? <span class="text-red-500">*</span></label>
                    <select name="motivo" id="motivo" class="w-full bg-gray-50 border border-slate-200 text-slate-800 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent transition-all text-sm font-medium appearance-none cursor-pointer" required>
                        <option value="" disabled selected>Selecciona una opción...</option>
                        <option value="Contenido inapropiado" <?= (($_POST['motivo'] ?? '')=='Contenido inapropiado') ? 'selected' : '' ?>>Contenido inapropiado</option>
                        <option value="Publicidad/Spam" <?= (($_POST['motivo'] ?? '')=='Publicidad/Spam') ? 'selected' : '' ?>>Publicidad/Spam</option>
                        <option value="Datos de contacto en descripción" <?= (($_POST['motivo'] ?? '')=='Datos de contacto en descripción') ? 'selected' : '' ?>>Datos de contacto en la descripción</option>
                        <option value="Es una estafa o fraude" <?= (($_POST['motivo'] ?? '')=='Es una estafa o fraude') ? 'selected' : '' ?>>Es una estafa o fraude</option>
                        <option value="Otro" <?= (($_POST['motivo'] ?? '')=='Otro') ? 'selected' : '' ?>>Otro motivo</option>
                    </select>
                </div>

                <div>
                    <label for="mensaje" class="block text-sm font-bold text-slate-700 mb-1.5">Detalles adicionales <span class="text-slate-400 font-normal text-xs">(Opcional)</span></label>
                    <textarea name="mensaje" id="mensaje" rows="4" maxlength="400" placeholder="Bríndanos más contexto para que podamos revisar tu reporte..." class="w-full bg-gray-50 border border-slate-200 text-slate-800 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent transition-all text-sm resize-none"><?= htmlspecialchars($_POST['mensaje'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="mt-8 flex flex-col gap-3">
                <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white px-6 py-3.5 rounded-xl font-bold shadow-md hover:shadow-lg transition-all hover:-translate-y-0.5 text-sm flex items-center justify-center gap-2">
                    <i class="fa-solid fa-paper-plane"></i> Enviar reporte a moderación
                </button>
                
                <a href="/detalle-servicio/<?= (int)$servicio_id ?>" class="w-full text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition-colors text-sm font-bold text-center py-3 rounded-xl">
                    Cancelar y volver
                </a>
            </div>
        </form>
    <?php endif; ?>

</main>

<?php 
// NAVEGACIÓN INFERIOR Y MODALES
require_once $app_dir . '/componentes/nav_bottom.php'; 
require_once $app_dir . '/componentes/modal_publicar.php'; 
require_once $app_dir . '/componentes/modal_explora.php'; 
?>

<script>
// Eliminar Loader
window.onload = () => { 
    const l = document.getElementById('loader'); 
    if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } 
};

// Sistema de Modales Nubira
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