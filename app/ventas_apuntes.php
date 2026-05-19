<?php
/**
 * VISTA: VENTAS DE APUNTES
 * UBICACIÓN: public_html/app/ventas_apuntes.php
 * ESTADO: Nubira 2.0 - App Nativa (Acordeón Cerrado, Flat Design, Swipe actions)
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

// HELPER: Privacidad del Comprador
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

// CONSULTA DE APUNTES
$sqlVentas = "SELECT v.*, a.titulo, a.precio, a.portada, a.archivo, al.nombre AS comprador_nombre
              FROM ventas_apuntes v
              JOIN apuntes a ON v.apunte_id = a.id
              JOIN alumnos al ON v.comprador_id = al.id
              WHERE v.vendedor_id = ?
              ORDER BY v.fecha DESC";
$stmtV = $conn->prepare($sqlVentas);
$stmtV->bind_param("i", $usuario_id);
$stmtV->execute();
$resVentas = $stmtV->get_result();

// --- PROCESAMIENTO NUBIRA 2.0: AGRUPACIÓN POR DÍAS ---
$ventas_agrupadas = [];
$total_pagado = 0;
$total_pendiente = 0;
$total_ventas_count = 0;

$hoy = date('Y-m-d');
$ayer = date('Y-m-d', strtotime('-1 day'));
$meses_es = ['Jan'=>'Ene', 'Feb'=>'Feb', 'Mar'=>'Mar', 'Apr'=>'Abr', 'May'=>'May', 'Jun'=>'Jun', 'Jul'=>'Jul', 'Aug'=>'Ago', 'Sep'=>'Sep', 'Oct'=>'Oct', 'Nov'=>'Nov', 'Dec'=>'Dic'];

while ($v = $resVentas->fetch_assoc()) {
    $total_ventas_count++;
    if ($v['pagado_al_vendedor']) {
        $total_pagado += (int)$v['precio'];
    } else {
        $total_pendiente += (int)$v['precio'];
    }

    $fecha_obj = new DateTime($v['fecha']);
    $fecha_corta = $fecha_obj->format('Y-m-d');

    // Título de la agrupación
    if (!isset($ventas_agrupadas[$fecha_corta])) {
        if ($fecha_corta === $hoy) $titulo_dia = "Hoy";
        elseif ($fecha_corta === $ayer) $titulo_dia = "Ayer";
        else {
            $mes_en = $fecha_obj->format('M');
            $titulo_dia = $fecha_obj->format('d') . ' ' . $meses_es[$mes_en] . ', ' . $fecha_obj->format('Y');
        }
        $ventas_agrupadas[$fecha_corta] = [
            'titulo' => $titulo_dia,
            'ventas' => []
        ];
    }
    
    $ventas_agrupadas[$fecha_corta]['ventas'][] = $v;
}
$stmtV->close();

$page_title = "Ventas de Apuntes";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title><?= $page_title ?> | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=0" />
  <meta name="theme-color" content="#ffffff" />
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #ffffff; -webkit-tap-highlight-color: transparent; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    ::-webkit-scrollbar { width: 0px; background: transparent; }
    
    /* Animación Acordeón Nativo */
    .expand-content { transition: max-height 0.3s ease-in-out, opacity 0.3s ease-in-out; max-height: 2000px; opacity: 1; overflow: hidden; }
    .expand-content.collapsed { max-height: 0; opacity: 0; }
    .chevron-icon { transition: transform 0.3s ease; }
    .chevron-icon.rotated { transform: rotate(180deg); }
    
    /* Reglas para interacciones táctiles y Swipe */
    .row-item { user-select: none; -webkit-user-select: none; -webkit-touch-callout: none; }
    .swipe-content { will-change: transform; }
    .checkbox-ui { display: none; }
    .selection-mode-active .checkbox-ui { display: flex; }
    .row-checkbox:checked + .checkbox-box { background-color: #54A6D8; border-color: #54A6D8; color: white; }
    .row-selected .swipe-content { background-color: #f8fafc !important; }
  </style>
</head>

<body class="text-slate-800 antialiased overflow-x-hidden select-none md:select-auto bg-white">

<div id="loader" class="fixed inset-0 bg-white flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-7 w-7 border-4 border-slate-200 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
require_once $app_dir . '/componentes/header.php'; 
require_once $app_dir . '/componentes/sidebar.php'; 
?>

<div id="top-acciones" class="fixed top-0 left-0 w-full bg-white border-b border-slate-100 z-[70] transform -translate-y-full transition-transform duration-300 flex items-center justify-between px-4 py-3 md:pl-72 md:pr-8 h-[60px]">
    <div class="flex items-center gap-3">
        <button onclick="cancelarSeleccion()" class="w-8 h-8 flex items-center justify-center text-slate-500 hover:bg-slate-50 rounded-full transition-colors active:scale-95">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
        <span class="font-bold text-slate-900 text-lg tracking-tight">
            <span id="contador-seleccionados">0</span> selec.
        </span>
    </div>
    <button onclick="eliminarSeleccionados()" class="text-red-500 hover:text-red-600 font-bold text-sm px-3 py-1.5 rounded-full active:bg-red-50 transition-colors flex items-center gap-1.5 shadow-none">
        <i class="fa-regular fa-trash-can"></i> Ocultar
    </button>
</div>

<main class="pt-16 pb-32 md:pb-12 md:ml-64 mx-auto max-w-[1000px]">
  <div class="w-full">
    
    <div class="sticky top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-slate-100 px-4 md:px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl md:text-2xl font-extrabold text-slate-900 tracking-tight">Ventas de Apuntes</h1>
            <p class="text-slate-400 text-xs font-medium">Panel operativo de documentos.</p>
        </div>
        
        <div class="flex items-center gap-2">
            <?php if ($total_ventas_count > 0): ?>
            <button onclick="exportarCSV()" class="inline-flex items-center justify-center gap-1.5 bg-emerald-50 text-emerald-600 font-bold py-2 px-3.5 rounded-xl hover:bg-emerald-100 active:scale-95 transition text-xs tracking-wide shadow-none border border-transparent">
                <i class="fa-solid fa-file-csv"></i> Exportar
            </button>
            <?php endif; ?>
            <a href="/datos_bancarios" class="inline-flex items-center justify-center gap-1.5 bg-slate-100 text-slate-600 font-bold py-2 px-3.5 rounded-xl hover:bg-slate-200 active:scale-95 transition text-xs tracking-wide shadow-none border border-transparent">
                <i class="fa-solid fa-wallet text-[#54A6D8]"></i> Billetera
            </a>
        </div>
    </div>

    <div class="md:px-6 pt-2">
        <?php if ($total_ventas_count > 0): ?>
            <div id="lista-ventas" class="pb-10 space-y-2 mt-2">
                
                <?php foreach ($ventas_agrupadas as $fecha => $dia): 
                    $idGrupo = 'grupo-' . strtotime($fecha);
                ?>
                    <div class="group-dia space-y-1 mt-4" id="dia-<?= $fecha ?>">
                        
                        <button onclick="toggleGrupo('<?= $idGrupo ?>', 'icon-<?= $idGrupo ?>')" class="w-full px-4 md:px-2 pt-4 pb-2 flex items-center justify-between active:bg-slate-50 transition-colors cursor-pointer sticky top-[108px] sm:top-[115px] z-20 bg-white">
                            <div class="flex items-center gap-2">
                                <h2 class="text-xs font-bold text-slate-400 uppercase tracking-widest"><?= $dia['titulo'] ?> (<?= count($dia['ventas']) ?>)</h2>
                            </div>
                            <i id="icon-<?= $idGrupo ?>" class="fa-solid fa-chevron-down text-slate-400 text-[10px] chevron-icon"></i>
                        </button>
                        
                        <div id="<?= $idGrupo ?>" class="expand-content collapsed bg-white border-y md:border border-slate-100 md:rounded-2xl">
                            <ul class="divide-y divide-slate-100">
                                <?php foreach ($dia['ventas'] as $v): 
                                    $es_pagado = $v['pagado_al_vendedor'];
                                    $estado_color = $es_pagado ? 'text-emerald-500' : 'text-amber-500';
                                    $estado_texto = $es_pagado ? 'Pagado' : 'Pendiente';
                                    $precio_mostrar = (int)($v['precio'] ?? 0);
                                    $inicial = substr(formatearNombrePrivado($v['comprador_nombre']), 0, 1);
                                    
                                    // Dinámica de Iconos según Extensión
                                    $archivo_real = $v['archivo'] ?? '';
                                    $ext = strtolower(pathinfo($archivo_real, PATHINFO_EXTENSION));
                                    $esImagen = in_array($ext, ['jpg','jpeg','png','webp','bmp']);
                                    
                                    $iconClass = 'fa-file-lines'; $iconColor = 'text-slate-500'; $bgIcon = 'bg-slate-50'; $iconTxt = 'DOC';
                                    if ($ext === 'pdf') { $iconClass = 'fa-file-pdf'; $iconColor = 'text-red-500'; $bgIcon = 'bg-red-50'; $iconTxt = 'PDF'; } 
                                    elseif ($esImagen) { $iconClass = 'fa-image'; $iconColor = 'text-emerald-500'; $bgIcon = 'bg-emerald-50'; $iconTxt = strtoupper($ext); } 
                                    elseif (in_array($ext, ['doc', 'docx'])) { $iconClass = 'fa-file-word'; $iconColor = 'text-blue-600'; $bgIcon = 'bg-blue-50'; $iconTxt = 'DOC'; }
                                ?>
                                <li class="row-item relative bg-[#FF3B30] overflow-hidden" data-id="<?= $v['id'] ?>" data-url="/ver-apunte?id=<?= $v['apunte_id'] ?>">
                                    
                                    <div class="absolute inset-y-0 right-0 w-20 flex items-center justify-center text-white z-0 pointer-events-none font-medium text-xs flex-col">
                                        <i class="fa-solid fa-trash-can text-lg mb-0.5"></i>
                                        Ocultar
                                    </div>

                                    <div class="swipe-content relative flex items-center py-4 px-4 md:px-5 bg-white active:bg-slate-50 transition-colors w-full z-10 cursor-pointer">
                                        
                                        <div class="checkbox-ui shrink-0 mr-3">
                                            <input type="checkbox" value="<?= $v['id'] ?>" class="hidden row-checkbox" id="chk-<?= $v['id'] ?>">
                                            <div class="checkbox-box w-5 h-5 rounded-full border-2 border-slate-300 flex items-center justify-center text-transparent transition-colors bg-white shadow-none">
                                                <i class="fa-solid fa-check text-[10px]"></i>
                                            </div>
                                        </div>

                                        <div class="w-12 h-12 rounded-xl <?= $bgIcon ?> <?= $iconColor ?> flex flex-col items-center justify-center shrink-0 border border-slate-100">
                                            <i class="fa-solid <?= $iconClass ?> text-xl mb-px"></i>
                                            <span class="text-[7px] font-black uppercase tracking-widest opacity-70"><?= $iconTxt ?></span>
                                        </div>
                                        
                                        <div class="flex-1 min-w-0 mx-3.5">
                                            <h3 class="font-bold text-slate-800 text-[14px] line-clamp-1 leading-tight mb-0.5">
                                                <?= htmlspecialchars($v['titulo']) ?>
                                            </h3>
                                            <div class="flex items-center gap-1.5 text-[11px] text-slate-400 font-medium truncate">
                                                <div class="w-4 h-4 rounded-full bg-slate-200 text-slate-600 flex items-center justify-center text-[7px] font-bold shrink-0">
                                                    <?= htmlspecialchars($inicial) ?>
                                                </div>
                                                <span class="truncate"><?= formatearNombrePrivado($v['comprador_nombre']) ?></span>
                                                <span>•</span>
                                                <span class="font-mono text-slate-400"><?= date('H:i', strtotime($v['fecha'])) ?></span>
                                            </div>
                                        </div>

                                        <div class="shrink-0 text-right flex flex-col items-end">
                                            <span class="font-black text-slate-900 text-[15px] tabular-nums tracking-tight leading-none text-right">
                                                <?= ($precio_mostrar > 0) ? '+$'.number_format($precio_mostrar, 0, ',', '.') : '<span class="text-slate-400 font-medium text-xs">Gratis</span>' ?>
                                            </span>
                                            <p class="text-[9px] font-extrabold <?= $estado_color ?> uppercase tracking-wider mt-1.5">
                                                <?= $estado_texto ?>
                                            </p>
                                        </div>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <p class="text-center text-[10px] text-slate-400 font-medium mt-4">
                Mantén presionada una fila para seleccionar.
            </p>
        <?php else: ?>
            <div class="bg-white border-y md:border border-slate-200 md:rounded-3xl p-8 text-center mt-4 mx-4 md:mx-0">
                <div class="w-12 h-12 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-3 text-slate-300">
                    <i class="fa-solid fa-receipt text-xl"></i>
                </div>
                <h3 class="text-base font-bold text-slate-800">Aún no hay movimientos</h3>
                <p class="text-slate-400 text-sm mt-1 mb-5">Tus ventas y descargas aparecerán aquí agrupadas por día.</p>
                <a href="/formulario-subir-apunte" class="inline-flex items-center justify-center bg-slate-800 text-white text-xs font-bold px-5 py-2.5 rounded-full hover:bg-slate-700 active:scale-95 transition">
                    Publicar Apunte
                </a>
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
    if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } 
};

document.addEventListener('DOMContentLoaded', function() {
    fetch('/app/limpiar_alertas_ventas.php', { method: 'POST' }).catch(e => {});
});

function exportarCSV() {
    let csvContent = "data:text/csv;charset=utf-8,";
    csvContent += "ID de Venta,Fecha,Hora,Titulo del Apunte,Comprador,Monto (CLP),Estado\n";
    
    <?php if ($total_ventas_count > 0): foreach ($ventas_agrupadas as $fecha => $dia): foreach ($dia['ventas'] as $v): ?>
        csvContent += `"${<?= $v['id'] ?>}","<?= date('Y-m-d', strtotime($v['fecha'])) ?>","<?= date('H:i:s', strtotime($v['fecha'])) ?>","<?= str_replace('"', '""', $v['titulo']) ?>","<?= str_replace('"', '""', formatearNombrePrivado($v['comprador_nombre'])) ?>","<?= (int)$v['precio'] ?>","<?= $v['pagado_al_vendedor'] ? 'Pagado' : 'Pendiente' ?>"\n`;
    <?php endforeach; endforeach; endif; ?>
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "Mis_Ventas_Apuntes.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// ACORDEÓN LÓGICA (Gira la flecha)
function toggleGrupo(idGrupo, idIcono) {
    if (selectionMode) return; 
    const contenedor = document.getElementById(idGrupo);
    const icono = document.getElementById(idIcono);
    
    if (contenedor.classList.contains('collapsed')) {
        contenedor.classList.remove('collapsed');
        icono.classList.add('rotated'); 
    } else {
        contenedor.classList.add('collapsed');
        icono.classList.remove('rotated'); 
    }
}

// SELECCIÓN MÚLTIPLE Y SWIPE
let selectionMode = false;
let seleccionados = new Set();
let pressTimer;
let isScrolling = false;
const LONG_PRESS_DURATION = 400; 

const listaVentas = document.getElementById('lista-ventas');
const topAcciones = document.getElementById('top-acciones');
const contador = document.getElementById('contador-seleccionados');

if (listaVentas) {
    const rows = document.querySelectorAll('.row-item');

    rows.forEach(row => {
        const swipeContent = row.querySelector('.swipe-content');
        let startX = 0, startY = 0, currentX = 0;
        let isSwiping = false;

        row.addEventListener('touchstart', (e) => {
            if (e.touches.length > 1 || selectionMode) return;
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            isSwiping = false;
            isScrolling = false;
            if (swipeContent) swipeContent.style.transition = 'none';
            
            pressTimer = setTimeout(() => {
                if (!isScrolling && !isSwiping) iniciarSeleccion(row);
            }, LONG_PRESS_DURATION);
        }, { passive: true });

        row.addEventListener('touchmove', (e) => {
            if (selectionMode || !startX) return;
            currentX = e.touches[0].clientX;
            let currentY = e.touches[0].clientY;
            let diffX = currentX - startX;
            let diffY = currentY - startY;

            if (Math.abs(diffX) > 10 || Math.abs(diffY) > 10) {
                isScrolling = true;
                clearTimeout(pressTimer);
            }

            if (Math.abs(diffX) > Math.abs(diffY) && diffX < 0) { 
                isSwiping = true;
                if(e.cancelable) e.preventDefault(); 
                let tx = Math.max(diffX, -80); 
                if (swipeContent) swipeContent.style.transform = `translateX(${tx}px)`;
            }
        }, { passive: false });

        row.addEventListener('touchend', (e) => {
            clearTimeout(pressTimer);
            if (isSwiping && swipeContent) {
                swipeContent.style.transition = 'transform 0.3s cubic-bezier(0.16, 1, 0.3, 1)';
                let diffX = currentX - startX;
                if (diffX < -50) { 
                    swipeContent.style.transform = `translateX(-100%)`;
                    setTimeout(() => eliminarVentaSwipe(row.getAttribute('data-id'), row), 250);
                } else { 
                    swipeContent.style.transform = `translateX(0)`;
                }
            } else if (!isScrolling && !isSwiping) {
                manejarClic(e, row);
            }
            startX = 0;
            isSwiping = false;
            isScrolling = false;
        });

        row.addEventListener('mousedown', (e) => {
            if (e.button !== 0) return; 
            pressTimer = setTimeout(() => { iniciarSeleccion(row); }, LONG_PRESS_DURATION);
        });

        row.addEventListener('mouseup', (e) => {
            clearTimeout(pressTimer);
            manejarClic(e, row);
        });

        row.addEventListener('mousemove', () => { clearTimeout(pressTimer); });
        row.addEventListener('contextmenu', (e) => { if(window.innerWidth < 768) e.preventDefault(); });
    });
}

function iniciarSeleccion(row) {
    if (selectionMode) return;
    selectionMode = true;
    listaVentas.classList.add('selection-mode-active');
    topAcciones.classList.remove('-translate-y-full'); 
    if (navigator.vibrate) navigator.vibrate(50);
    toggleFila(row);
}

function manejarClic(e, row) {
    if (selectionMode) {
        if (e.cancelable) e.preventDefault(); 
        toggleFila(row);
    } else {
        const url = row.getAttribute('data-url');
        if (url) window.open(url, '_blank');
    }
}

function toggleFila(row) {
    const id = row.getAttribute('data-id');
    const checkbox = document.getElementById('chk-' + id);
    checkbox.checked = !checkbox.checked;

    if (checkbox.checked) {
        seleccionados.add(id);
        row.classList.add('row-selected');
    } else {
        seleccionados.delete(id);
        row.classList.remove('row-selected');
    }
    actualizarBarra();
}

function actualizarBarra() {
    contador.innerText = seleccionados.size;
    if (seleccionados.size === 0) cancelarSeleccion();
}

function cancelarSeleccion() {
    selectionMode = false;
    seleccionados.clear();
    listaVentas.classList.remove('selection-mode-active');
    topAcciones.classList.add('-translate-y-full'); 
    
    document.querySelectorAll('.row-item').forEach(r => {
        r.classList.remove('row-selected');
        const chk = r.querySelector('.row-checkbox');
        if(chk) chk.checked = false;
    });
}

function eliminarVentaSwipe(id, row) {
    if (!confirm('¿Deseas ocultar este registro de tu historial?')) {
        row.querySelector('.swipe-content').style.transform = `translateX(0)`;
        return;
    }
    
    row.style.transition = "all 0.3s ease";
    row.style.opacity = '0';
    row.style.height = '0px';
    
    setTimeout(() => { 
        row.remove(); 
        verificarBloqueVacio();
    }, 300);
    
    fetch('/app/eliminar_ventas_apuntes.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids: [id] })
    }).catch(err => {});
}

function eliminarSeleccionados() {
    if (seleccionados.size === 0) return;
    if (!confirm(`¿Ocultar ${seleccionados.size} registro(s)?`)) return;

    const idsArray = Array.from(seleccionados);

    idsArray.forEach(id => {
        const row = document.querySelector(`.row-item[data-id="${id}"]`);
        if (row) {
            row.style.transition = "all 0.3s ease";
            row.style.opacity = '0';
            row.style.transform = 'translateX(-20px)';
            setTimeout(() => { 
                row.remove(); 
                verificarBloqueVacio();
            }, 300);
        }
    });

    cancelarSeleccion(); 

    fetch('/app/eliminar_ventas_apuntes.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids: idsArray })
    }).catch(err => {});
}

function verificarBloqueVacio() {
    document.querySelectorAll('.group-dia').forEach(bloque => {
        const ul = bloque.querySelector('ul');
        if (ul && ul.children.length === 0) {
            bloque.style.display = 'none';
        }
    });
    
    const visibles = Array.from(document.querySelectorAll('.group-dia')).filter(b => b.style.display !== 'none');
    if (visibles.length === 0) location.reload(); 
}

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
</script>

</body>
</html>