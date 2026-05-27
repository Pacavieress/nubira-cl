<?php
/**
 * VISTA: DATOS BANCARIOS Y BILLETERA (Wallet Principal)
 * UBICACIÓN: public_html/app/datos_bancarios.php
 * ESTADO: Nubira 2.0 - Diseño Financiero Nativo (Estilo Stripe/Apple, Flat Design)
 */
session_start();

if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit; }

$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists($app_dir . '/app/conexion.php')) $app_dir = $app_dir . '/app';
    elseif (file_exists(dirname($app_dir) . '/app')) $app_dir = dirname($app_dir) . '/app';
}
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

$usuario_id = (int)$_SESSION['usuario_id'];

// CSRF: Generar token si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 1. CONFIGURACIÓN Y CÁLCULOS FINANCIEROS
$minimo_retiro = 10000;
$comision_actual = 0;

$stmtConf = $conn->query("SELECT clave, valor FROM configuracion WHERE clave IN ('monto_minimo_retiro', 'comision_plataforma')");
while ($row = $stmtConf->fetch_assoc()) {
    if ($row['clave'] === 'monto_minimo_retiro') $minimo_retiro = intval($row['valor']);
    if ($row['clave'] === 'comision_plataforma') $comision_actual = intval($row['valor']);
}

// Ganancias Servicios [NUBIRA 2.0]
// Ajustamos para que acepte tanto 'liberado' como 'finalizado' y sume el subsidio
// [NUBIRA 2.0] Suma Monto + Subsidio - Comisión
$stmtResS = $conn->prepare("SELECT SUM(monto + COALESCE(monto_subsidio, 0) - COALESCE(monto_comision, 0)) AS total FROM contratos WHERE vendedor_id = ? AND estado IN ('liberado', 'finalizado', 'completado')");
$stmtResS->bind_param("i", $usuario_id);
$stmtResS->execute();
$resS = $stmtResS->get_result()->fetch_assoc();
$stmtResS->close();
$ganancias_servicios = $resS['total'] ?? 0;

// FIX PHP 8: Declarar la variable apuntes en 0 por defecto para evitar Error 500
$ganancias_apuntes = 0; 
// TODO: Si a futuro agregas ventas de apuntes, su consulta SQL iría aquí reemplazando el 0.

$total_ganancias = $ganancias_apuntes + $ganancias_servicios;
// Total Retirado
// [NUBIRA 2.0] Restamos pendientes, aprobados y ya pagados para evitar duplicidad
$stmtRet = $conn->prepare("SELECT SUM(monto) AS total_retirado FROM solicitudes_retiro WHERE usuario_id = ? AND estado IN ('aprobado', 'pendiente', 'pagado')");
$stmtRet->bind_param("i", $usuario_id);
$stmtRet->execute();
$retiroRes = $stmtRet->get_result()->fetch_assoc();
$stmtRet->close();
$total_retirado = $retiroRes['total_retirado'] ?? 0;

$saldo_disponible = $total_ganancias - $total_retirado;
$porcentaje_progreso = min(($saldo_disponible / $minimo_retiro) * 100, 100);

// 2. DATOS BANCARIOS REGISTRADOS
$datosBancarios = null;
$stmtDatos = $conn->prepare("SELECT * FROM datos_pago_usuario WHERE usuario_id = ?");
$stmtDatos->bind_param("i", $usuario_id);
$stmtDatos->execute();
$datosBancarios = $stmtDatos->get_result()->fetch_assoc();
$stmtDatos->close();

$page_title = "Mi Billetera";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Mi Billetera | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=0" />
  <meta name="theme-color" content="#ffffff" />
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
      @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');
      body { font-family: 'Inter', sans-serif; background-color: #ffffff; -webkit-tap-highlight-color: transparent; }
      .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
      .animate-fade-in-up { animation: fadeInUp 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
      @keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
      /* Reset text-shadow */
      .force-no-shadow * { text-shadow: none !important; }
  </style>
</head>
<body class="text-slate-800 antialiased overflow-x-hidden">

<div id="loader" class="fixed inset-0 bg-white flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-8 w-8 border-4 border-slate-100 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
require_once $app_dir . '/componentes/header.php'; 
require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-16 pb-32 md:pb-12 lg:ml-64 mx-auto max-w-[1000px] animate-fade-in-up force-no-shadow">
  <div class="w-full">
    
    <div class="sticky top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-slate-100 px-4 md:px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl md:text-2xl font-extrabold text-slate-900 tracking-tight">Mi Billetera</h1>
            <p class="text-slate-400 text-xs font-medium">Administra tus ganancias y retiros.</p>
        </div>
    </div>

    <div class="md:px-6 pt-4 space-y-6">

        <div class="px-4 md:px-0">
            <div class="bg-slate-900 rounded-2xl md:rounded-3xl p-5 md:p-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 overflow-hidden relative border border-slate-800">
                <div class="absolute -right-8 -top-8 w-32 h-32 rounded-full bg-white/5 pointer-events-none"></div>
                
                <div class="relative z-10">
                    <p class="text-[10px] md:text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Saldo Disponible</p>
                    <p class="text-3xl md:text-4xl font-black text-white tracking-tight">$<?= number_format(max(0, $saldo_disponible), 0, ',', '.') ?></p>
                    <div class="mt-2 inline-flex items-center gap-1.5 <?= $comision_actual == 0 ? 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20' : 'bg-sky-500/10 text-sky-600 border-sky-500/20' ?> px-2.5 py-1 rounded-md text-[10px] font-medium border">
    <i class="fa-solid <?= $comision_actual == 0 ? 'fa-gift' : 'fa-circle-info' ?>"></i>
    <span>
        <?php if ($comision_actual == 0): ?>
            Comisión de plataforma: <b>0%</b>. ¡Aprovecha, estás recibiendo el 100% de tus ventas!
        <?php else: ?>
            Tus ganancias ya reflejan la comisión actual de la plataforma (<b><?= $comision_actual ?>%</b>).
        <?php endif; ?>
    </span>
</div>
                    
                    <?php if ($total_ganancias > 0): ?>
                    <div class="flex flex-wrap items-center gap-3 mt-3 text-[11px] text-slate-400 font-medium">
                        <?php if ($ganancias_apuntes > 0): ?>
                        <span class="flex items-center gap-1.5 bg-slate-800 px-2 py-1 rounded-md">
                            <i class="fa-solid fa-file-lines"></i>
                            Apuntes: $<?= number_format($ganancias_apuntes, 0, ',', '.') ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($ganancias_servicios > 0): ?>
                        <span class="flex items-center gap-1.5 bg-slate-800 px-2 py-1 rounded-md">
                            <i class="fa-solid fa-handshake"></i>
                            Servicios: $<?= number_format($ganancias_servicios, 0, ',', '.') ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="relative z-10 w-full sm:w-1/2 lg:w-1/3 flex flex-col gap-3">
                    <?php if ($saldo_disponible >= $minimo_retiro): ?>
                        <div class="w-full">
                            <?php if ($datosBancarios): ?>
                               <form action="/solicitar-retiro" method="POST" class="w-full">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="monto" value="<?= floor($saldo_disponible) ?>">
    <button type="submit" class="w-full bg-white text-slate-900 active:bg-slate-200 md:hover:bg-slate-100 py-3 rounded-xl font-bold transition-colors text-sm flex items-center justify-center gap-2 shadow-none border border-transparent">
                                        <i class="fa-solid fa-building-columns"></i> Solicitar Retiro
                                    </button>
                                </form>
                            <?php else: ?>
                                <a href="/editar-datos-bancarios" class="w-full bg-white text-slate-900 active:bg-slate-200 md:hover:bg-slate-100 py-3 rounded-xl font-bold transition-colors text-sm flex items-center justify-center gap-2 shadow-none border border-transparent">
                                    <i class="fa-solid fa-triangle-exclamation text-amber-500"></i> Configurar Banco
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="w-full">
                            <div class="flex justify-between text-[10px] font-bold uppercase tracking-wider mb-1">
                                <span class="text-slate-400">Progreso para retiro</span>
                                <span class="text-[#54A6D8]"><?= round($porcentaje_progreso) ?>%</span>
                            </div>
                            <div class="w-full bg-slate-800 h-1.5 rounded-full overflow-hidden">
                                <div class="bg-[#54A6D8] h-full transition-all duration-1000 ease-out" style="width: <?= $porcentaje_progreso ?>%"></div>
                            </div>
                            <p class="text-[10px] text-slate-400 font-medium text-right mt-1.5">Mínimo: $<?= number_format($minimo_retiro, 0, ',', '.') ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="px-4 md:px-0">
            <a href="/editar-datos-bancarios" class="flex items-center justify-between bg-white p-4 rounded-2xl border border-slate-100 hover:bg-slate-50 active:bg-slate-100 transition-colors group">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center shrink-0 <?= $datosBancarios ? 'bg-slate-50 text-[#54A6D8] border border-slate-100' : 'bg-red-50 text-red-500 border border-red-100' ?>">
                        <i class="fa-solid <?= $datosBancarios ? 'fa-building-columns' : 'fa-triangle-exclamation' ?>"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-slate-800 text-sm md:text-base">Cuenta Bancaria de Destino</h3>
                        <p class="text-xs md:text-sm font-medium mt-0.5 <?= $datosBancarios ? 'text-slate-500' : 'text-red-400' ?>">
                            <?php if ($datosBancarios): ?>
                                <?= htmlspecialchars($datosBancarios['banco']) ?> • Cta. terminada en <?= strlen($datosBancarios['numero_cuenta'] ?? '') >= 4 ? substr(htmlspecialchars($datosBancarios['numero_cuenta']), -4) : '••••' ?>
                            <?php else: ?>
                                Atención: Configura tu cuenta para poder retirar
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <i class="fa-solid fa-chevron-right text-slate-300 group-hover:text-slate-600 transition-colors"></i>
            </a>
        </div>

        <div class="px-4 md:px-0 pt-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-xs font-bold text-slate-400 uppercase tracking-widest">Historial de Retiros</h2>
            </div>

            <?php 
            $stmtH = $conn->prepare("SELECT monto, fecha_solicitud, estado FROM solicitudes_retiro WHERE usuario_id = ? ORDER BY fecha_solicitud DESC LIMIT 15");
            $stmtH->bind_param("i", $usuario_id);
            $stmtH->execute();
            $resRetiros = $stmtH->get_result();
            
            if ($resRetiros->num_rows > 0): 
            ?>
                <div class="bg-white md:rounded-2xl border-y md:border border-slate-100 overflow-hidden">
                    <ul class="divide-y divide-slate-50">
                    <?php while ($r = $resRetiros->fetch_assoc()): 
                        $estado = strtolower($r['estado']);
                        $fecha = date('d M Y, H:i', strtotime($r['fecha_solicitud']));
                        
                        if ($estado === 'aprobado') {
                            $icono = '<i class="fa-solid fa-check text-emerald-500"></i>';
                            $bg_icono = 'bg-emerald-50';
                            $color_estado = 'text-emerald-500';
                            $txt_estado = 'Transferencia Exitosa';
                        } elseif ($estado === 'rechazado') {
                            $icono = '<i class="fa-solid fa-xmark text-red-500"></i>';
                            $bg_icono = 'bg-red-50';
                            $color_estado = 'text-red-500 line-through';
                            $txt_estado = 'Rechazada / Devuelta';
                        } else {
                            $icono = '<i class="fa-solid fa-clock text-amber-500"></i>';
                            $bg_icono = 'bg-amber-50';
                            $color_estado = 'text-slate-800';
                            $txt_estado = 'En proceso';
                        }
                    ?>
                        <li class="flex items-center justify-between p-4 hover:bg-slate-50 active:bg-slate-100 transition-colors cursor-default">
                            <div class="flex items-center gap-4 flex-1 min-w-0">
                                <div class="w-10 h-10 rounded-full <?= $bg_icono ?> flex items-center justify-center shrink-0 border border-transparent">
                                    <?= $icono ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-bold text-slate-800 text-[13px] md:text-sm truncate">Retiro a Banco</h3>
                                    <p class="text-[11px] text-slate-400 font-medium mt-0.5 truncate">
                                        <?= $fecha ?> • <?= $txt_estado ?>
                                    </p>
                                </div>
                            </div>
                            <div class="text-right pl-4 shrink-0">
                                <span class="font-bold <?= $color_estado ?> text-sm tracking-tight">
                                    -$<?= number_format($r['monto'], 0, ',', '.') ?>
                                </span>
                            </div>
                        </li>
                    <?php endwhile; ?>
                    </ul>
                </div>
            <?php else: ?>
                <div class="flex flex-col items-center justify-center py-12 px-4 text-center bg-white md:rounded-2xl border-y md:border border-slate-100">
                    <div class="w-12 h-12 bg-slate-50 rounded-full flex items-center justify-center mb-3 text-slate-300 border border-slate-100">
                        <i class="fa-solid fa-clock-rotate-left text-xl"></i>
                    </div>
                    <h3 class="text-sm font-bold text-slate-800">No hay retiros registrados</h3>
                    <p class="text-slate-400 text-xs mt-1 max-w-xs mx-auto font-medium">Tus transferencias hacia el banco aparecerán aquí.</p>
                </div>
            <?php endif; $stmtH->close(); ?>
        </div>

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
    if(l) { 
        l.classList.add('opacity-0'); 
        setTimeout(() => l.classList.add('hidden'), 300); 
    } 
};

// Modales
function setupModal(triggerId, modalId, cardId, closeId) {
    const btn = document.getElementById(triggerId), modal = document.getElementById(modalId), card = document.getElementById(cardId), close = document.getElementById(closeId);
    if(!btn || !modal) return;
    const open = () => { modal.classList.remove('hidden'); requestAnimationFrame(() => card.classList.remove('translate-y-full','opacity-0')); document.body.style.overflow='hidden'; };
    const shut = () => { card.classList.add('translate-y-full','opacity-0'); setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow=''; }, 300); };
    btn.onclick = (e) => { e.preventDefault(); open(); }; 
    if(close) close.onclick = shut; 
    modal.onclick = (e) => { if(e.target === modal) shut(); };
}
setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
</script>
</body>
</html>