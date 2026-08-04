<?php
/**
 * VISTA: VENTAS DE CLASES / SERVICIOS (Panel Operativo)
 * UBICACIÓN: public_html/app/ventas_clases.php
 * ESTADO: Nubira 2.0 - App Nativa (Acordeón Cerrado por defecto, Sticky Header perfecto)
 */
session_start();

if (!isset($_SESSION['usuario_id'])) { 
    header("Location: /login"); 
    exit; 
}

$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists($app_dir . '/app/conexion.php')) $app_dir = $app_dir . '/app';
    elseif (file_exists(dirname($app_dir) . '/app')) $app_dir = dirname($app_dir) . '/app';
}
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

// HELPER: Lógica de Privacidad
if (!function_exists('formatearNombrePrivado')) {
    function formatearNombrePrivado($nombre_completo) {
        $partes = array_values(array_filter(explode(' ', trim($nombre_completo))));
        if (empty($partes[0])) return "Usuario";
        $p_nombre = ucwords(strtolower($partes[0]));
        $inicial = '';
        if (count($partes) >= 2) {
            $idx = (count($partes) >= 3) ? 2 : 1;
            $inicial = ' ' . strtoupper(substr($partes[$idx], 0, 1)) . '.';
        }
        return $p_nombre . $inicial;
    }
}

$usuario_id = (int)$_SESSION['usuario_id'];

// CONSULTA DE CONTRATOS (Clases)
$sqlServ = "SELECT c.id AS id_contrato, s.titulo, s.imagen, al.nombre AS comprador_nombre, al.correo AS comprador_email,
                   c.monto, c.monto_subsidio, c.monto_comision, c.fecha_creacion, c.fecha_pago, c.estado, c.calificacion_vendedor
            FROM contratos c
            JOIN servicios s ON s.id = c.servicio_id
            JOIN alumnos al ON al.id = c.comprador_id
            WHERE c.vendedor_id = ?
            ORDER BY c.fecha_creacion DESC";
$stmtS = $conn->prepare($sqlServ);
$stmtS->bind_param("i", $usuario_id);
$stmtS->execute();
$resServRaw = $stmtS->get_result();

// AGRUPACIÓN POR DÍA
$ventasAgrupadas = [];
$total_clases = 0;

while ($s = $resServRaw->fetch_assoc()) {
    $fechaBase = !empty($s['fecha_pago']) ? $s['fecha_pago'] : $s['fecha_creacion'];
    $fechaObj = new DateTime($fechaBase);
    $dia = $fechaObj->format('Y-m-d');
    
    $ventasAgrupadas[$dia][] = $s;
    $total_clases++;
}
$stmtS->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mis Ganancias | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=0" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #ffffff; -webkit-tap-highlight-color: transparent; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    ::-webkit-scrollbar { width: 0px; background: transparent; }
  </style>
</head>

<body class="text-gray-800 antialiased overflow-x-hidden select-none md:select-auto bg-white">

<div id="loader" class="fixed inset-0 bg-white flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-7 w-7 border-4 border-gray-200 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php
// [NUBIRA 2.0] Ocultar header global en móvil — mismo patrón que detalle_servicio.php/mis_servicios.php
echo '<div class="hidden md:block">';
require_once $app_dir . '/componentes/header.php';
echo '</div>';
require_once $app_dir . '/componentes/sidebar.php';
?>

<div id="top-acciones" class="fixed top-0 left-0 w-full bg-white border-b border-gray-200 z-[70] transform -translate-y-full transition-transform duration-300 flex items-center justify-between px-4 py-3 md:pl-72 md:pr-8 h-[60px]">
    <div class="flex items-center gap-3">
        <button onclick="cancelarSeleccion()" class="w-8 h-8 flex items-center justify-center text-gray-500 hover:bg-gray-100 rounded-full transition-colors active:scale-95 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
        <span class="font-medium text-[#222222] text-lg tracking-[-0.01em]">
            <span id="contador-seleccionados">0</span> selec.
        </span>
    </div>
    <button onclick="eliminarSeleccionados()" class="text-red-500 hover:text-red-700 font-bold text-sm px-3 py-1.5 rounded-full active:bg-red-50 transition-colors flex items-center gap-1.5 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">
        <i class="fa-regular fa-trash-can"></i> Ocultar
    </button>
</div>

<main class="pt-4 md:pt-16 pb-32 md:pb-12 lg:ml-64 mx-auto max-w-[1000px]">
  <div class="w-full">

    <div class="sticky top-0 md:top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-gray-100 px-4 md:px-6 py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <button type="button" onclick="navegacionSeguraNubira()"
                    class="lg:hidden shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-gray-50 hover:bg-gray-100 border border-gray-200/60 shadow-sm active:scale-95 transition-all focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2"
                    aria-label="Volver">
                <i class="fa-solid fa-arrow-left text-gray-700 text-[17px]"></i>
            </button>
            <h1 class="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em]">Mis Ganancias</h1>
        </div>

        <div class="flex items-center gap-2">
            <?php if ($total_clases > 0): ?>
            <button onclick="exportarCSV()" class="inline-flex items-center justify-center gap-1.5 bg-emerald-50 text-emerald-700 font-bold py-1.5 px-3 rounded-xl hover:bg-emerald-100 active:scale-95 transition text-[11px] tracking-wide focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">
                <i class="fa-solid fa-file-csv"></i> Exportar
            </button>
            <?php endif; ?>
            <a href="/datos_bancarios" class="inline-flex items-center justify-center gap-1.5 bg-gray-100 text-gray-700 font-bold py-1.5 px-3 rounded-xl hover:bg-gray-200 active:scale-95 transition text-[11px] tracking-wide focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">
                <i class="fa-solid fa-wallet text-[#54A6D8]"></i> Billetera
            </a>
        </div>
    </div>

   <!-- Info-box: esta vista es de dinero; la agenda vive en Mis Contratos -->
   <a href="/mis-contratos" class="flex items-center gap-3 mx-4 md:mx-6 mt-3 bg-sky-50 border border-sky-100 rounded-2xl px-4 py-3 hover:bg-sky-100 transition-colors group focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">
       <span class="w-9 h-9 rounded-xl bg-white text-[#54A6D8] flex items-center justify-center shrink-0 border border-sky-100">
           <i class="fa-regular fa-calendar"></i>
       </span>
       <div class="flex-1 min-w-0">
           <p class="text-[13px] font-medium text-[#222222] leading-tight">¿Buscas tus próximas clases?</p>
           <p class="text-[11px] text-gray-400 leading-tight">Esta vista es solo de dinero. Revisa tu agenda en Mis Contratos.</p>
       </div>
       <span class="text-[#54A6D8] text-[11px] font-bold flex items-center gap-1 shrink-0">
           Ver mi agenda <i class="fa-solid fa-arrow-right text-[10px] group-hover:translate-x-0.5 transition-transform"></i>
       </span>
   </a>

   <div class="md:px-6 pt-2">
        <?php if (!empty($ventasAgrupadas)): ?>
            <div id="lista-ventas" class="pb-10 space-y-4 mt-2">
                <?php 
                foreach ($ventasAgrupadas as $dia => $clasesDelDia): 
                    $timestamp = strtotime($dia);
                    if ($dia === date('Y-m-d')) {
                        $etiquetaDia = 'Hoy';
                    } elseif ($dia === date('Y-m-d', strtotime('-1 day'))) {
                        $etiquetaDia = 'Ayer';
                    } else {
                        $etiquetaDia = date('d M Y', $timestamp);
                    }
                    $cantidadDia = count($clasesDelDia);
                    $idGrupo = 'grupo-' . $timestamp;
                ?>
                    
                <div class="group-dia" id="dia-<?= $dia ?>">
                    
                    <button onclick="toggleGrupo('<?= $idGrupo ?>', 'icono-<?= $idGrupo ?>')" class="w-full px-4 md:px-2 py-2 flex items-center justify-between bg-white transition-colors cursor-pointer sticky top-16 md:top-32 z-20 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-gray-400 text-[12px] uppercase tracking-widest"><?= $etiquetaDia ?></span>
                            <span class="bg-gray-100 text-gray-600 text-[9px] font-medium px-1.5 py-0.5 rounded-md"><?= $cantidadDia ?></span>
                        </div>
                        <i id="icono-<?= $idGrupo ?>" class="fa-solid fa-chevron-down text-gray-400 text-[10px] transition-transform duration-200"></i>
                    </button>

                    <div id="<?= $idGrupo ?>" class="hidden bg-white border-y md:border border-[#f0f0f0] md:rounded-2xl md:shadow-[0_1px_3px_rgba(0,0,0,0.04)] overflow-hidden">
                        <ul class="divide-y divide-gray-100">
                            <?php foreach ($clasesDelDia as $s): 
                                $estado_raw = $s['estado'];
                                $ya_calificado = ((int)$s['calificacion_vendedor'] > 0);
                                $is_liberado = ($estado_raw === 'liberado');
                                $is_en_curso = ($estado_raw === 'en_progreso' || $estado_raw === 'finalizado_comprador' || $estado_raw === 'finalizado_vendedor');
                                $is_cancelado = ($estado_raw === 'cancelado');

                                if ($is_liberado) {
                                    $estado_color = 'text-emerald-500 bg-emerald-50 border border-emerald-100';
                                    $texto_estado = 'Terminada';
                                } elseif ($is_en_curso) {
                                    $estado_color = 'text-blue-500 bg-blue-50 border border-blue-100';
                                    $texto_estado = 'En Curso';
                                } elseif ($is_cancelado) {
                                    $estado_color = 'text-red-500 bg-red-50 border border-red-100';
                                    $texto_estado = 'Cancelada';
                                } else {
                                    $estado_color = 'text-amber-500 bg-amber-50 border border-amber-100';
                                    $texto_estado = 'Pendiente';
                                }

                                $rutaImg = !empty($s['imagen']) ? '/upload/servicios/'.basename($s['imagen']) : '/img/portadas/servicios/clases.webp';
                                $inicial_alumno = strtoupper(substr($s['comprador_nombre'], 0, 1));

                                // Neto que recibe el tutor = lo que paga el alumno + subsidio Nubira - comisión Nubira
                                $bruto    = (int)$s['monto'];
                                $subsidio = (int)($s['monto_subsidio'] ?? 0);
                                $comision = (int)($s['monto_comision'] ?? 0);
                                $neto     = $bruto + $subsidio - $comision;

                                // Subtexto solo si hay subsidio o comisión que desglosar
                                $subtexto = '';
                                if ($subsidio > 0 && $comision > 0) {
                                    $subtexto = 'Alumno $' . number_format($bruto, 0, ',', '.')
                                              . ' + Subsidio $' . number_format($subsidio, 0, ',', '.')
                                              . ' − Comisión $' . number_format($comision, 0, ',', '.');
                                } elseif ($subsidio > 0) {
                                    $subtexto = 'Alumno $' . number_format($bruto, 0, ',', '.')
                                              . ' + Subsidio $' . number_format($subsidio, 0, ',', '.');
                                } elseif ($comision > 0) {
                                    $subtexto = 'Bruto $' . number_format($bruto, 0, ',', '.')
                                              . ' − Comisión $' . number_format($comision, 0, ',', '.');
                                }
                            ?>
                            
                            <li class="relative group row-item" id="venta-<?= $s['id_contrato'] ?>" data-id="<?= $s['id_contrato'] ?>">
                                <div id="overlay-<?= $s['id_contrato'] ?>" class="absolute inset-0 bg-blue-50/50 opacity-0 pointer-events-none transition-opacity z-10"></div>
                                
                                <div class="flex items-center justify-between p-4 md:px-4 hover:bg-gray-50 transition-colors gap-3 cursor-pointer"
                                     ontouchstart="handleTouchStart(event, <?= $s['id_contrato'] ?>)" 
                                     ontouchend="handleTouchEnd(event)" 
                                     onmousedown="handleMouseDown(event, <?= $s['id_contrato'] ?>)" 
                                     onmouseup="handleMouseUp(event)" 
                                     onmouseleave="handleMouseUp(event)">
                                    
                                    <div class="flex items-center gap-3 flex-1 min-w-0 z-20">
                                        <div class="chk-ui-container hidden shrink-0" id="chk-ui-<?= $s['id_contrato'] ?>" onclick="event.stopPropagation(); toggleSeleccion(<?= $s['id_contrato'] ?>)">
                                            <div class="w-5 h-5 rounded-full border-2 border-gray-300 flex items-center justify-center transition-colors bg-white">
                                                <i class="fa-solid fa-check text-[10px] text-white opacity-0 transition-opacity"></i>
                                            </div>
                                        </div>

                                        <div class="w-12 h-12 rounded-xl bg-gray-100 overflow-hidden shrink-0 border border-gray-100 relative">
                                            <img src="<?= htmlspecialchars($rutaImg) ?>" onerror="this.src='/img/portadas/servicios/clases.webp';" class="w-full h-full object-cover">
                                        </div>

                                        <div class="flex-1 min-w-0">
                                            <h3 class="font-medium text-[#222222] text-[14px] line-clamp-1 leading-tight mb-0.5"><?= htmlspecialchars($s['titulo']) ?></h3>
                                            <div class="flex items-center gap-1.5 text-[11px] text-gray-400 font-medium truncate">
                                                <div class="w-4 h-4 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-[7px] font-bold shrink-0"><?= $inicial_alumno ?></div>
                                                <span class="truncate"><?= formatearNombrePrivado($s['comprador_nombre']) ?></span>
                                                <span>•</span>
                                                <span class="font-mono text-gray-400"><?= date('H:i', strtotime($s['fecha_creacion'])) ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex flex-col items-end gap-1.5 shrink-0 pl-2 z-20">
                                        <div class="flex flex-col items-end gap-0.5">
                                            <span class="font-medium text-[#222222] text-[15px] tabular-nums tracking-[-0.01em] leading-none text-right">
                                                $<?= number_format($neto, 0, ',', '.') ?>
                                            </span>
                                            <?php if ($subtexto !== ''): ?>
                                            <span class="text-[10px] text-gray-400 font-medium tabular-nums text-right leading-tight">
                                                <?= $subtexto ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="flex justify-end" onclick="event.stopPropagation()">
                                            <?php if ($is_en_curso): ?>
                                                <a href="mailto:<?= htmlspecialchars($s['comprador_email']) ?>" class="inline-flex items-center justify-center gap-1.5 bg-gray-100 text-gray-600 hover:bg-gray-200 text-[10px] font-bold px-2 py-1 rounded-md transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">
                                                    <i class="fa-regular fa-envelope"></i> Chat
                                                </a>
                                            <?php elseif ($is_liberado && !$ya_calificado): ?>
                                                <a href="/app/evaluar_servicio.php?id=<?= $s['id_contrato'] ?>" class="inline-flex items-center justify-center gap-1.5 bg-[#54A6D8]/10 text-[#54A6D8] hover:bg-[#54A6D8]/20 text-[10px] font-bold px-2 py-1 rounded-md transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">
                                                    <i class="fa-solid fa-star"></i> Nota
                                                </a>
                                            <?php elseif ($ya_calificado): ?>
                                                <span class="text-emerald-500 bg-emerald-50 border border-emerald-100 px-1.5 py-0.5 rounded text-[9px] font-medium uppercase tracking-wider">
                                                    Listo
                                                </span>
                                            <?php else: ?>
                                                <span class="<?= $estado_color ?> px-1.5 py-0.5 rounded text-[9px] font-medium uppercase tracking-wider">
                                                    <?= $texto_estado ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <p class="text-center text-[10px] text-gray-400 mt-4 font-medium">
                Mantén presionada una fila para seleccionar.
            </p>
        <?php else: ?>
            <div class="bg-gray-50 p-8 text-center border border-dashed border-gray-200 rounded-2xl mt-4 mx-4 md:mx-0">
                <div class="w-12 h-12 bg-white border border-gray-100 rounded-full flex items-center justify-center text-gray-300 mx-auto mb-3"><i class="fa-solid fa-receipt text-xl"></i></div>
                <h3 class="text-base font-medium text-[#222222] tracking-[-0.01em]">Sin ventas operativas</h3>
                <p class="text-gray-400 text-sm mt-1">Cuando recibas compras, aparecerán aquí agrupadas por fecha.</p>
            </div>
        <?php endif; ?>
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
    if(l){ l.classList.add('opacity-0'); setTimeout(() => l.classList.add('hidden'), 300); }
};

// [NUBIRA 2.0] Volver — mismo patrón que mis_servicios.php/detalle_servicio.php, con
// fallback a /perfil (mismo tile de origen: "Clases Vendidas" en panel_gestion.php).
window.navegacionSeguraNubira = function() {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        window.location.href = '/perfil';
    }
};

// ACORDEÓN LÓGICA (Despliegue natural hacia abajo sin saltos)
function toggleGrupo(idGrupo, idIcono) {
    if (modoSeleccion) return; 
    const contenedor = document.getElementById(idGrupo);
    const icono = document.getElementById(idIcono);
    
    if (contenedor.classList.contains('hidden')) {
        // Abre hacia abajo
        contenedor.classList.remove('hidden');
        icono.classList.remove('fa-chevron-down');
        icono.classList.add('fa-chevron-up');
    } else {
        // Cierra hacia arriba
        contenedor.classList.add('hidden');
        icono.classList.remove('fa-chevron-up');
        icono.classList.add('fa-chevron-down');
    }
}

// SELECCIÓN MÚLTIPLE (Long Press Nativo)
let seleccionados = new Set();
let modoSeleccion = false;
let pressTimer;

function handleTouchStart(e, id) { if(!modoSeleccion) pressTimer = window.setTimeout(() => activarModoSeleccion(id), 400); }
function handleTouchEnd(e) { if(pressTimer) clearTimeout(pressTimer); }
function handleMouseDown(e, id) { if(!modoSeleccion && e.button === 0) pressTimer = window.setTimeout(() => activarModoSeleccion(id), 400); }
function handleMouseUp(e) { if(pressTimer) clearTimeout(pressTimer); }

function activarModoSeleccion(id) {
    if ("vibrate" in navigator) navigator.vibrate(50);
    modoSeleccion = true;
    document.querySelectorAll('.chk-ui-container').forEach(el => {
        el.classList.remove('hidden');
        el.classList.add('flex');
    });
    // Hacemos aparecer la barra superior superior suavemente
    const topAcciones = document.getElementById('top-acciones');
    topAcciones.classList.remove('-translate-y-full');
    toggleSeleccion(id);
}

function cancelarSeleccion() {
    modoSeleccion = false;
    seleccionados.clear();
    document.querySelectorAll('.chk-ui-container').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('[id^="overlay-"]').forEach(el => el.classList.remove('opacity-100'));
    document.querySelectorAll('.chk-ui-container > div').forEach(el => {
        el.classList.remove('bg-[#54A6D8]', 'border-[#54A6D8]');
        el.classList.add('border-gray-300');
        el.querySelector('i').classList.remove('opacity-100');
    });
    actualizarBarraAcciones();
}

function toggleSeleccion(id) {
    if (!modoSeleccion) return; 
    
    const uiCircle = document.querySelector(`#chk-ui-${id} > div`);
    const uiIcon = uiCircle.querySelector('i');
    const overlay = document.getElementById(`overlay-${id}`);
    
    if (seleccionados.has(id)) {
        seleccionados.delete(id);
        uiCircle.classList.remove('bg-[#54A6D8]', 'border-[#54A6D8]');
        uiCircle.classList.add('border-gray-300');
        uiIcon.classList.remove('opacity-100');
        overlay.classList.remove('opacity-100');
    } else {
        seleccionados.add(id);
        uiCircle.classList.remove('border-gray-300');
        uiCircle.classList.add('bg-[#54A6D8]', 'border-[#54A6D8]');
        uiIcon.classList.add('opacity-100');
        overlay.classList.add('opacity-100');
    }
    actualizarBarraAcciones();
    if(seleccionados.size === 0) cancelarSeleccion();
}

function actualizarBarraAcciones() {
    const topAcciones = document.getElementById('top-acciones');
    const contador = document.getElementById('contador-seleccionados');
    contador.innerText = seleccionados.size;

    if (seleccionados.size === 0) {
        topAcciones.classList.add('-translate-y-full');
    }
}

function eliminarSeleccionados() {
    if (seleccionados.size === 0) return;
    if (!confirm(`¿Ocultar ${seleccionados.size} registro(s) de tu vista operativa?`)) return;

    const idsArray = Array.from(seleccionados);

    idsArray.forEach(id => {
        const row = document.getElementById('venta-' + id);
        if (row) {
            row.style.transition = "all 0.3s ease";
            row.style.transform = "translateX(-100%)";
            row.style.opacity = '0';
            setTimeout(() => { row.remove(); }, 300);
        }
    });

    seleccionados.clear();
    cancelarSeleccion();

    fetch('/app/eliminar_ventas.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids: idsArray })
    }).catch(err => {});
}

// Lógica de Modales del Nav Inferior
function setupModalNav(triggerId, modalId, cardId, closeId) {
    const btn = document.getElementById(triggerId);
    const modal = document.getElementById(modalId);
    const card = document.getElementById(cardId);
    const close = document.getElementById(closeId);
    
    if(!btn || !modal) return;
    
    const open = () => {
        modal.classList.remove('hidden'); 
        requestAnimationFrame(() => card.classList.remove('translate-y-full','opacity-0')); 
        document.body.style.overflow = 'hidden';
    };
    
    const shut = () => {
        card.classList.add('translate-y-full','opacity-0'); 
        setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300);
    };
    
    btn.onclick = (e) => { e.preventDefault(); open(); }; 
    if(close) close.onclick = shut; 
    modal.onclick = (e) => { if(e.target === modal) shut(); };
}

setupModalNav('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
setupModalNav('btn-explora', 'modal-explora', 'explora-card', 'explora-close');

// EXPORTAR CSV
function exportarCSV() {
    let csvContent = "data:text/csv;charset=utf-8,";
    csvContent += "ID de Venta,Fecha,Titulo,Comprador,Neto,Estado\n";

    <?php if ($total_clases > 0): foreach ($ventasAgrupadas as $dia => $clases): foreach ($clases as $c):
        $neto_csv = (int)$c['monto'] + (int)($c['monto_subsidio'] ?? 0) - (int)($c['monto_comision'] ?? 0);
    ?>
        csvContent += `"${<?= $c['id_contrato'] ?>}","<?= date('Y-m-d', strtotime($c['fecha_creacion'])) ?>","<?= str_replace('"', '""', $c['titulo']) ?>","<?= str_replace('"', '""', formatearNombrePrivado($c['comprador_nombre'])) ?>","<?= $neto_csv ?>","<?= $c['estado'] ?>"\n`;
    <?php endforeach; endforeach; endif; ?>
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "Mis_Ganancias.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

document.addEventListener('DOMContentLoaded', () => {
    fetch('/app/limpiar_alertas_ventas.php', { method: 'POST' }).catch(e => {});
});
</script>

</body>
</html>
