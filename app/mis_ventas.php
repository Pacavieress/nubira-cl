<?php
/**
 * VISTA: MIS VENTAS (Panel de Gestión Financiera)
 * ACTUALIZACIÓN: Fix Error 500 - Restauración de tablas originales + Blindaje SQL
 * ESTADO UI: Nubira 2.0 - App Nativa (Acordeones Cerrados, Flat Design, Sin Sombras)
 */
session_start();

// 1. SEGURIDAD Y RUTAS
if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit; }

$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists($app_dir . '/app/conexion.php')) $app_dir = $app_dir . '/app';
    elseif (file_exists(dirname($app_dir) . '/app')) $app_dir = dirname($app_dir) . '/app';
}
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

// HELPER: Privacidad
if (!function_exists('formatearNombrePrivado')) {
    function formatearNombrePrivado($nombre_completo) {
        $partes = array_values(array_filter(explode(' ', trim($nombre_completo))));
        if (empty($partes[0])) return "Usuario";
        $p_nombre = ucwords(strtolower($partes[0]));
        $inicial = count($partes) >= 2 ? ' ' . strtoupper(substr($partes[(count($partes) >= 3 ? 2 : 1)], 0, 1)) . '.' : '';
        return $p_nombre . $inicial;
    }
}

$usuario_id = (int)$_SESSION['usuario_id'];

// 2. CONFIGURACIÓN RETIRO
$minimo_retiro = 10000;
$stmtConf = $conn->prepare("SELECT valor FROM configuracion WHERE clave = 'monto_minimo_retiro'");
if ($stmtConf) {
    $stmtConf->execute();
    $stmtConf->bind_result($val);
    if ($stmtConf->fetch() && !empty($val)) $minimo_retiro = $val;
    $stmtConf->close();
}

// 3. CÁLCULO DE GANANCIAS (Restaurado a ventas_apuntes original)
$total_ganancias_apuntes = 0;
$stmtRes = $conn->prepare("SELECT SUM(precio) AS total FROM ventas_apuntes WHERE vendedor_id = ? AND pagado_al_vendedor = 1");
if ($stmtRes) {
    $stmtRes->bind_param("i", $usuario_id);
    $stmtRes->execute();
    $resA = $stmtRes->get_result()->fetch_assoc();
    $total_ganancias_apuntes = $resA['total'] ?? 0;
    $stmtRes->close();
}

// [NUBIRA 2.0] Suma Monto + Subsidio - Comisión (Coherente con la Billetera)
$total_ganancias_servicios = 0;
$stmtResS = $conn->prepare("SELECT SUM(monto + COALESCE(monto_subsidio, 0) - COALESCE(monto_comision, 0)) AS total FROM contratos WHERE vendedor_id = ? AND estado IN ('liberado', 'finalizado', 'completado')");
if ($stmtResS) {
    $stmtResS->bind_param("i", $usuario_id);
    $stmtResS->execute();
    $resS = $stmtResS->get_result()->fetch_assoc();
    $total_ganancias_servicios = $resS['total'] ?? 0;
    $stmtResS->close();
}

$total_ganancias = $total_ganancias_apuntes + $total_ganancias_servicios;

// Retiros
$total_retirado = 0;
$stmtRet = $conn->prepare("SELECT SUM(monto) AS total_retirado FROM solicitudes_retiro WHERE usuario_id = ? AND estado = 'aprobado'");
if ($stmtRet) {
    $stmtRet->bind_param("i", $usuario_id);
    $stmtRet->execute();
    $retiroRes = $stmtRet->get_result()->fetch_assoc();
    $total_retirado = $retiroRes['total_retirado'] ?? 0;
    $stmtRet->close();
}

$saldo_disponible = $total_ganancias - $total_retirado;

// 4. CONSULTA SERVICIOS (AHORA SÍ CON FECHAS Y HORAS)
$resServ = null;
$sqlServ = "SELECT c.id AS id_contrato, s.titulo, al.nombre AS comprador_nombre, al.correo AS comprador_email, 
                   c.monto AS precio, c.monto_subsidio, c.fecha_pago AS fecha, c.estado, c.calificacion_vendedor,
                   c.fecha_estimada, c.hora_inicio, c.hora_termino
            FROM contratos c
            JOIN servicios s ON s.id = c.servicio_id
            JOIN alumnos al ON al.id = c.comprador_id
            WHERE c.vendedor_id = ?
            ORDER BY c.fecha_pago DESC, c.id DESC";
$stmtS = $conn->prepare($sqlServ);
if ($stmtS) {
    $stmtS->bind_param("i", $usuario_id);
    $stmtS->execute();
    $resServ = $stmtS->get_result();
}

$servicios = [];
if ($resServ) {
    while ($row = $resServ->fetch_assoc()) {
        $servicios[] = $row;
    }
}

// 5. CONSULTA APUNTES (Restaurado a ventas_apuntes original)
$apuntesAgrupados = [];
$total_ventas_apuntes = 0;
$sqlVentas = "SELECT v.*, a.titulo, al.nombre AS comprador_nombre 
              FROM ventas_apuntes v
              JOIN apuntes a ON v.apunte_id = a.id
              JOIN alumnos al ON v.comprador_id = al.id
              WHERE v.vendedor_id = ?
              ORDER BY v.fecha DESC";
$stmtV = $conn->prepare($sqlVentas);
if ($stmtV) {
    $stmtV->bind_param("i", $usuario_id);
    $stmtV->execute();
    $resVentasRaw = $stmtV->get_result();

    if ($resVentasRaw) {
        while ($v = $resVentasRaw->fetch_assoc()) {
            $fechaObj = new DateTime($v['fecha']);
            $dia = $fechaObj->format('Y-m-d'); 
            $apuntesAgrupados[$dia][] = $v;
            $total_ventas_apuntes++;
        }
    }
    $stmtV->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>Mis Ventas | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=0" />
    <script src="https://cdn.tailwindcss.com"></script>
     <link rel="icon" type="image/webp" href="/img/logo2.webp">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #ffffff; -webkit-tap-highlight-color: transparent; }
        .animate-fade-in { animation: fadeIn 0.4s ease-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        /* Animación Acordeón Nativo */
        .expand-content { transition: max-height 0.3s ease-in-out, opacity 0.3s ease-in-out; max-height: 2000px; opacity: 1; overflow: hidden; }
        .expand-content.collapsed { max-height: 0; opacity: 0; }
        .chevron-icon { transition: transform 0.3s ease; }
        .chevron-icon.rotated { transform: rotate(180deg); }
    </style>
</head>
<body class="text-slate-800 antialiased overflow-x-hidden bg-white">

<div id="loader" class="fixed inset-0 bg-white/95 flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-10 w-10 border-4 border-slate-100 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
require_once $app_dir . '/componentes/header.php'; 
require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-16 pb-32 md:pb-12 md:ml-64 mx-auto max-w-[1000px] animate-fade-in">
  <div class="w-full">
    
    <div class="sticky top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-slate-100 px-4 md:px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl md:text-2xl font-extrabold text-slate-900 tracking-tight">Mis Ventas</h1>
            <p class="text-slate-400 text-xs font-medium">Panel de Gestión Financiera.</p>
        </div>
    </div>

    <div class="md:px-6 pt-4 space-y-6">

        <div class="px-4 md:px-0">
            <div class="bg-slate-900 rounded-2xl md:rounded-3xl p-5 md:p-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 overflow-hidden relative border border-slate-800">
                <div class="absolute -right-8 -top-8 w-32 h-32 rounded-full bg-white/5 pointer-events-none"></div>
                
                <div class="relative z-10">
                    <p class="text-[10px] md:text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Saldo Disponible</p>
                    <p class="text-3xl md:text-4xl font-black text-white tracking-tight">$<?= number_format(max(0, $saldo_disponible), 0, ',', '.') ?></p>
                </div>

                <div class="relative z-10 w-full sm:w-1/2 lg:w-1/3 flex flex-col gap-3">
                    <?php if ($saldo_disponible >= $minimo_retiro): ?>
                        <form action="/solicitar-retiro" method="POST" class="w-full">
                            <input type="hidden" name="monto" value="<?= (int)$saldo_disponible ?>">
                            <button class="w-full bg-emerald-500 active:bg-emerald-400 md:hover:bg-emerald-400 text-white py-2.5 rounded-xl font-bold transition-colors text-sm flex items-center justify-center gap-2 shadow-none border border-transparent">
                                Retirar Saldo
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="flex justify-between text-[10px] font-bold uppercase tracking-wider mb-1">
                            <span class="text-slate-400">Progreso para retiro</span>
                            <span class="text-[#54A6D8]"><?= round(min(($saldo_disponible / $minimo_retiro) * 100, 100)) ?>%</span>
                        </div>
                        <div class="w-full bg-slate-800 h-2 rounded-full overflow-hidden">
                            <div class="bg-[#54A6D8] h-full transition-all" style="width: <?= min(($saldo_disponible / $minimo_retiro) * 100, 100) ?>%"></div>
                        </div>
                        <p class="text-[10px] text-slate-400 font-medium text-right">Mínimo: $<?= number_format($minimo_retiro, 0, ',', '.') ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="space-y-1" id="seccion-servicios">
            <button onclick="toggleGrupo('content-servicios', 'icon-servicios')" class="w-full px-4 md:px-2 pt-4 pb-2 flex items-center justify-between active:bg-slate-50 transition-colors cursor-pointer sticky top-[108px] sm:top-[115px] z-20 bg-white">
                <div class="flex items-center gap-2">
                    <h2 class="text-xs font-bold text-slate-400 uppercase tracking-widest">Servicios Vendidos (<?= count($servicios) ?>)</h2>
                </div>
                <i id="icon-servicios" class="fa-solid fa-chevron-down text-slate-400 text-[10px] chevron-icon rotated"></i>
            </button>

            <div id="content-servicios" class="expand-content collapsed bg-white border-y md:border border-slate-100 md:rounded-2xl">
                <?php if (count($servicios) > 0): ?>
                    <ul class="divide-y divide-slate-100">
                        <?php foreach($servicios as $s): 
                            $finalizado = ($s['estado'] === 'finalizado' || $s['estado'] === 'liberado');
                            $ya_calificado = ($s['calificacion_vendedor'] > 0);
                            $inicial = strtoupper(substr($s['comprador_nombre'], 0, 1));
                            
                            $f_fecha = !empty($s['fecha_estimada']) ? date('d M', strtotime($s['fecha_estimada'])) : 'Por definir';
                            $h_inicio = !empty($s['hora_inicio']) ? date('H:i', strtotime($s['hora_inicio'])) : '--:--';
                            $h_termino = !empty($s['hora_termino']) ? date('H:i', strtotime($s['hora_termino'])) : '--:--';
                        ?>
                        <li class="flex items-center justify-between p-4 md:px-4 hover:bg-slate-50 transition-colors gap-3 active:bg-slate-100">
                            <div class="flex items-start gap-3 flex-1 min-w-0">
                                <div class="w-10 h-10 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center text-[11px] font-bold border border-slate-200 shrink-0">
                                    <?= $inicial ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-bold text-slate-800 text-[13px] md:text-sm line-clamp-1 leading-tight"><?= htmlspecialchars($s['titulo'] ?? 'Servicio') ?></p>
                                    <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                        <span class="text-[11px] text-slate-500 font-medium truncate max-w-[100px]"><?= formatearNombrePrivado($s['comprador_nombre'] ?? '') ?></span>
                                        <span class="text-slate-300 shrink-0 text-[10px]">•</span>
                                        <span class="inline-flex items-center gap-1 text-slate-500 text-[10px] font-medium tracking-wide">
                                            <i class="fa-regular fa-calendar-days text-[#54A6D8]"></i> <?= $f_fecha ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <?php 
                                $subsidio = (int)($s['monto_subsidio'] ?? 0);
                                $precio_alumno = (int)($s['precio'] ?? 0);
                                $precio_total_tutor = $precio_alumno + $subsidio;
                            ?>
                            <div class="flex flex-col items-end gap-1.5 shrink-0 pl-2">
                                <span class="font-black text-slate-900 text-[14px] tabular-nums tracking-tight leading-none text-right">
                                    $<?= number_format($precio_total_tutor, 0, ',', '.') ?>
                                </span>
                                
                                <div class="flex flex-col items-end gap-1">
                                    <?php if ($subsidio > 0): ?>
                                        <span class="inline-flex items-center gap-1 text-emerald-500 text-[9px] font-bold tracking-wider" title="Nubira cubrió $<?= number_format($subsidio, 0, ',', '.') ?> del costo de esta clase.">
                                            <i class="fa-solid fa-ticket"></i> Cupón Aplicado
                                        </span>
                                    <?php endif; ?>

                                    <div class="flex justify-end items-center gap-2 mt-0.5">
                                        <?php if ($s['estado'] === 'liberado'): ?>
                                            <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-600 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider">
                                                <i class="fa-solid fa-check"></i> Pagado
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 bg-amber-50 text-amber-600 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider">
                                                <i class="fa-solid fa-clock"></i> Pendiente
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="bg-white p-6 text-center border-b border-slate-100 rounded-2xl">
                        <p class="text-sm font-medium text-slate-500">Aún no registras ventas de servicios.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="space-y-1" id="seccion-apuntes">
            <button onclick="toggleGrupo('content-apuntes', 'icon-apuntes')" class="w-full px-4 md:px-2 pt-4 pb-2 flex items-center justify-between active:bg-slate-50 transition-colors cursor-pointer sticky top-[108px] sm:top-[115px] z-20 bg-white">
                <div class="flex items-center gap-2">
                    <h2 class="text-xs font-bold text-slate-400 uppercase tracking-widest">Apuntes Vendidos (<?= $total_ventas_apuntes ?>)</h2>
                </div>
                <i id="icon-apuntes" class="fa-solid fa-chevron-down text-slate-400 text-[10px] chevron-icon rotated"></i>
            </button>

            <div id="content-apuntes" class="expand-content collapsed bg-white border-y md:border border-slate-100 md:rounded-2xl">
                <?php if (!empty($apuntesAgrupados)): ?>
                    <div class="divide-y divide-slate-100">
                        <?php foreach ($apuntesAgrupados as $dia => $ventasDelDia): 
                            $timestamp = strtotime($dia);
                            if ($dia === date('Y-m-d')) { $etiquetaDia = 'Hoy'; } 
                            elseif ($dia === date('Y-m-d', strtotime('-1 day'))) { $etiquetaDia = 'Ayer'; } 
                            else { $etiquetaDia = date('d M Y', $timestamp); }

                            $totalDia = array_sum(array_column($ventasDelDia, isset($ventasDelDia[0]['monto']) ? 'monto' : 'precio'));
                            $cantidadDia = count($ventasDelDia);
                        ?>
                            <div class="px-4 py-2.5 flex items-center justify-between bg-slate-50 border-b border-slate-100">
                                <div class="flex items-center gap-2">
                                    <p class="font-bold text-slate-600 text-[11px] uppercase tracking-widest"><?= $etiquetaDia ?></p>
                                    <span class="text-[9px] text-slate-400 font-medium"><?= $cantidadDia ?> archivo(s)</span>
                                </div>
                                <p class="font-black text-emerald-600 text-[11px]">+$<?= number_format($totalDia, 0, ',', '.') ?></p>
                            </div>
                            
                            <ul class="divide-y divide-slate-100 bg-white">
                                <?php foreach ($ventasDelDia as $v): 
                                    $inicial = strtoupper(substr($v['comprador_nombre'] ?? 'U', 0, 1));
                                    $montoVenta = $v['monto'] ?? $v['precio'] ?? 0;
                                    $pagado = isset($v['pagado_al_vendedor']) ? $v['pagado_al_vendedor'] : (isset($v['estado_pago']) && $v['estado_pago'] === 'pagado');
                                ?>
                                <li class="flex items-center justify-between p-3 md:px-4 hover:bg-slate-50 transition-colors gap-3 active:bg-slate-100">
                                    <div class="flex items-center gap-3 flex-1 min-w-0">
                                        <div class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center text-[10px] font-bold shrink-0">
                                            <?= $inicial ?>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="font-semibold text-slate-800 text-[12px] truncate leading-tight"><?= htmlspecialchars($v['titulo'] ?? 'Apunte') ?></p>
                                            <p class="text-[10px] text-slate-400 truncate mt-0.5"><?= formatearNombrePrivado($v['comprador_nombre'] ?? '') ?> • <?= date('H:i', strtotime($v['fecha'])) ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="text-right shrink-0 pl-2">
                                        <p class="font-bold text-slate-800 text-[13px] leading-tight">$<?= number_format($montoVenta, 0, ',', '.') ?></p>
                                        <?php if ($pagado): ?>
                                            <span class="text-emerald-500 text-[9px] font-bold uppercase tracking-wider">Pagado</span>
                                        <?php else: ?>
                                            <span class="text-amber-500 text-[9px] font-bold uppercase tracking-wider">Pendiente</span>
                                        <?php endif; ?>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="bg-white p-6 text-center border-b border-slate-100 rounded-2xl">
                        <p class="text-sm font-medium text-slate-500">Aún no registras ventas de apuntes.</p>
                    </div>
                <?php endif; ?>
            </div>
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
    if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } 
};

// ACORDEÓN LÓGICA (Gira la flecha)
function toggleGrupo(idGrupo, idIcono) {
    const contenedor = document.getElementById(idGrupo);
    const icono = document.getElementById(idIcono);
    
    if (contenedor.classList.contains('collapsed')) {
        contenedor.classList.remove('collapsed');
        icono.classList.remove('rotated'); 
    } else {
        contenedor.classList.add('collapsed');
        icono.classList.add('rotated'); 
    }
}

// SISTEMA DE MODALES NUBIRA 2.0
const NubiraModales = {
    setup(triggerId, modalId, cardId, closeId) {
        const btn = document.getElementById(triggerId);
        const modal = document.getElementById(modalId);
        const card = document.getElementById(cardId);
        const close = document.getElementById(closeId);
        if(!btn || !modal) return;
        
        const open = () => { 
            modal.classList.remove('hidden'); 
            requestAnimationFrame(() => { card.classList.remove('translate-y-full', 'opacity-0'); });
            document.body.style.overflow = 'hidden'; 
        };
        const shut = () => { 
            card.classList.add('translate-y-full', 'opacity-0'); 
            setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); 
        };
        
        btn.onclick = (e) => { 
            e.preventDefault(); 
            open(); 
        }; 
        if(close) close.onclick = shut; 
        modal.onclick = (e) => { if(e.target === modal) shut(); };
    }
};

document.addEventListener('DOMContentLoaded', () => {
    NubiraModales.setup('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
    NubiraModales.setup('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
});
</script>
</body>
</html>