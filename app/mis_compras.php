<?php
/**
 * VISTA: MIS COMPRAS
 * UBICACIÓN: public_html/app/mis_compras.php
 * ESTADO: Nubira 2.0 - App Nativa (Acordeón Cerrado, Flat Design)
 */
session_start();

if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit; }

/* Rutas */
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

/* Sesión */
$usuario_id      = (int)$_SESSION['usuario_id'];
$nombre_usuario = $_SESSION['usuario_nombre'] ?? 'Usuario';
$rol            = $_SESSION['rol'] ?? 'alumno';

/* ===============================
   CONSULTA APUNTES
================================ */
$sqlApuntes = "
SELECT 
    c.id, c.usuario_id, c.id_apunte, c.monto, c.fecha, c.estado_pago,
    a.titulo, a.asignatura, a.archivo, a.institucion
FROM compras c
JOIN apuntes a ON c.id_apunte = a.id
WHERE c.usuario_id = ?
ORDER BY c.fecha DESC
";
$stmt = $conn->prepare($sqlApuntes);
$stmt->bind_param('i', $usuario_id);
$stmt->execute();
$resApuntes = $stmt->get_result();
$totalApuntes = $resApuntes ? $resApuntes->num_rows : 0;

$apuntes = [];
while($row = $resApuntes->fetch_assoc()) {
    $apuntes[] = $row;
}
$stmt->close();

/* ===============================
   CONSULTA SERVICIOS
================================ */
$sqlServicios = "
SELECT c.id, s.titulo, al.nombre AS vendedor_nombre, c.monto, c.fecha_pago, c.estado
FROM contratos c
JOIN servicios s ON s.id = c.servicio_id
JOIN alumnos al ON al.id = c.vendedor_id
WHERE c.comprador_id = ?
ORDER BY c.fecha_pago DESC
";
$stmtServ = $conn->prepare($sqlServicios);
$stmtServ->bind_param("i", $usuario_id);
$stmtServ->execute();
$resServicios = $stmtServ->get_result();
$totalServicios = $resServicios ? $resServicios->num_rows : 0;

$servicios = [];
while($row = $resServicios->fetch_assoc()) {
    $servicios[] = $row;
}
$stmtServ->close();

$page_title = "Mis Compras";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Mis Compras | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=0" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
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
  </style>
</head>

<body class="text-gray-800 antialiased overflow-x-hidden select-none md:select-auto bg-white">

<div id="loader" class="fixed inset-0 bg-white flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-7 w-7 border-4 border-gray-200 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php
// [NUBIRA 2.0] Ocultar header global en móvil — mismo patrón que las demás páginas de gestión
echo '<div class="hidden md:block">';
require_once $app_dir . '/componentes/header.php';
echo '</div>';
require_once $app_dir . '/componentes/sidebar.php';
?>

<main class="pt-4 md:pt-16 pb-32 md:pb-12 lg:ml-64 mx-auto max-w-[1000px]">
  <div class="w-full">

    <div class="sticky top-0 md:top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-gray-100 px-4 md:px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <button type="button" onclick="navegacionSeguraNubira()"
                    class="lg:hidden shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-gray-50 hover:bg-gray-100 border border-gray-200/60 shadow-sm active:scale-95 transition-all focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2"
                    aria-label="Volver">
                <i class="fa-solid fa-arrow-left text-gray-700 text-[17px]"></i>
            </button>
            <div>
                <h1 class="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Mis Compras</h1>
                <p class="text-gray-400 text-xs font-medium">Historial de tus adquisiciones en Nubira.</p>
            </div>
        </div>
    </div>

    <div class="md:px-6 pt-2">
        <div id="lista-compras" class="pb-10 space-y-2 mt-2">
            
            <div class="group-dia space-y-1" id="seccion-apuntes">
                <button onclick="toggleGrupo('content-apuntes', 'icon-apuntes')" class="w-full px-4 md:px-2 pt-4 pb-2 flex items-center justify-between active:bg-gray-50 transition-colors cursor-pointer sticky top-[77px] md:top-[141px] z-20 bg-white focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">
                    <div class="flex items-center gap-2">
                        <h2 class="text-xs font-bold text-gray-400 uppercase tracking-widest">Apuntes Comprados (<?= $totalApuntes ?>)</h2>
                    </div>
                    <i id="icon-apuntes" class="fa-solid fa-chevron-down text-gray-400 text-[10px] chevron-icon"></i>
                </button>

                <div id="content-apuntes" class="expand-content collapsed bg-white border-y md:border border-[#f0f0f0] md:rounded-2xl md:shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                    <?php if ($totalApuntes > 0): ?>
                        <ul class="divide-y divide-gray-100">
                            <?php foreach($apuntes as $c):
                                $aprobado = ($c['estado_pago'] === 'pagado');
                                $estadoColor = $aprobado ? 'text-emerald-500 bg-emerald-50 border border-emerald-100' : 'text-amber-500 bg-amber-50 border border-amber-100';
                                $estadoTxt = $aprobado ? 'Pagado' : 'Pendiente';

                                $archivo = $c['archivo'] ?? '';
                                $ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
                                $iconClass = 'fa-file-lines'; $iconColor = 'text-gray-500'; $bgIcon = 'bg-gray-50'; $iconTxt = 'DOC';
                                if ($ext === 'pdf') { $iconClass = 'fa-file-pdf'; $iconColor = 'text-red-500'; $bgIcon = 'bg-red-50'; $iconTxt = 'PDF'; }
                                elseif (in_array($ext, ['jpg','jpeg','png','webp'])) { $iconClass = 'fa-image'; $iconColor = 'text-emerald-500'; $bgIcon = 'bg-emerald-50'; $iconTxt = strtoupper($ext); }
                                elseif (in_array($ext, ['doc', 'docx'])) { $iconClass = 'fa-file-word'; $iconColor = 'text-blue-600'; $bgIcon = 'bg-blue-50'; $iconTxt = 'DOC'; }
                            ?>
                            <li class="flex items-center justify-between p-4 md:px-4 hover:bg-gray-50 transition-colors gap-3 active:bg-gray-100">
                                <div class="flex items-center gap-3 flex-1 min-w-0 z-20">
                                    <div class="w-12 h-12 rounded-xl <?= $bgIcon ?> <?= $iconColor ?> flex flex-col items-center justify-center shrink-0 border border-gray-100">
                                        <i class="fa-solid <?= $iconClass ?> text-xl mb-px"></i>
                                        <span class="text-[7px] font-black uppercase tracking-widest opacity-70"><?= $iconTxt ?></span>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-medium text-[#222222] text-[14px] line-clamp-1 leading-tight mb-0.5"><?= htmlspecialchars($c['titulo']) ?></h3>
                                        <div class="flex items-center gap-1.5 text-[11px] text-gray-400 font-medium truncate">
                                            <span class="<?= $estadoColor ?> px-1.5 py-0.5 rounded text-[10px] font-medium uppercase tracking-wide"><?= $estadoTxt ?></span>
                                            <span>•</span>
                                            <span class="truncate"><?= htmlspecialchars($c['asignatura'] ?? '') ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex flex-col items-end gap-1.5 shrink-0 pl-2 z-20">
                                    <span class="font-medium text-[#222222] text-[15px] tabular-nums tracking-[-0.01em] leading-none text-right">
                                        $<?= number_format($c['monto'], 0, ',', '.') ?>
                                    </span>

                                    <div class="flex justify-end items-center gap-1 bg-gray-100 p-1 rounded-full border border-transparent">
                                        <?php if ($aprobado): ?>
                                            <a href="/ver-apunte?archivo=<?= urlencode($archivo) ?>" target="_blank" class="w-7 h-7 flex items-center justify-center rounded-full text-gray-500 hover:bg-white hover:text-[#54A6D8] active:bg-white transition focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2" title="Descargar/Ver Apunte">
                                                <i class="fa-solid fa-download text-xs"></i>
                                            </a>
                                        <?php else: ?>
                                            <button disabled class="w-7 h-7 flex items-center justify-center rounded-full text-gray-300 cursor-not-allowed" title="Pago Pendiente">
                                                <i class="fa-solid fa-lock text-xs"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-6 text-center">
                            <div class="w-10 h-10 bg-white border border-gray-100 text-gray-300 rounded-full flex items-center justify-center mx-auto mb-2">
                                <i class="fa-solid fa-file-invoice text-lg"></i>
                            </div>
                            <p class="text-sm font-medium text-gray-400">No has comprado apuntes aún.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="group-dia space-y-1 mt-4" id="seccion-servicios">
                <button onclick="toggleGrupo('content-servicios', 'icon-servicios')" class="w-full px-4 md:px-2 pt-4 pb-2 flex items-center justify-between active:bg-gray-50 transition-colors cursor-pointer sticky top-[77px] md:top-[141px] z-20 bg-white focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">
                    <div class="flex items-center gap-2">
                        <h2 class="text-xs font-bold text-gray-400 uppercase tracking-widest">Servicios Contratados (<?= $totalServicios ?>)</h2>
                    </div>
                    <i id="icon-servicios" class="fa-solid fa-chevron-down text-gray-400 text-[10px] chevron-icon"></i>
                </button>

                <div id="content-servicios" class="expand-content collapsed bg-white border-y md:border border-[#f0f0f0] md:rounded-2xl md:shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                    <?php if ($totalServicios > 0): ?>
                        <ul class="divide-y divide-gray-100">
                            <?php foreach($servicios as $s):
                                $estado = $s['estado'] ?? '';
                                $estilos = [
                                    'pendiente_pago' => 'bg-amber-50 text-amber-500 border border-amber-100',
                                    'en_progreso' => 'bg-blue-50 text-[#54A6D8] border border-blue-100',
                                    'finalizado_vendedor' => 'bg-purple-50 text-purple-500 border border-purple-100',
                                    'finalizado_comprador' => 'bg-purple-50 text-purple-500 border border-purple-100',
                                    'liberado' => 'bg-emerald-50 text-emerald-500 border border-emerald-100',
                                    'cancelado' => 'bg-red-50 text-red-500 border border-red-100'
                                ];
                                $txt = [
                                    'pendiente_pago' => 'Pendiente', 'en_progreso' => 'En Curso',
                                    'finalizado_vendedor' => 'Finalizado', 'finalizado_comprador' => 'Finalizado',
                                    'liberado' => 'Completado', 'cancelado' => 'Cancelado'
                                ];
                                $clase = $estilos[$estado] ?? 'bg-gray-50 text-gray-500 border border-gray-100';
                                $texto = $txt[$estado] ?? 'Revisión';
                                $is_cerrado = in_array($estado, ['finalizado_vendedor', 'finalizado_comprador', 'liberado', 'cancelado']);

                                $inicial_tutor = strtoupper(substr(formatearNombrePrivado($s['vendedor_nombre']), 0, 1));
                            ?>
                            <li class="flex items-center justify-between p-4 md:px-4 hover:bg-gray-50 transition-colors gap-3 active:bg-gray-100">
                                <div class="flex items-center gap-3 flex-1 min-w-0 z-20">
                                    <div class="w-12 h-12 rounded-xl bg-gray-50 flex items-center justify-center shrink-0 border border-gray-100 relative">
                                        <i class="fa-solid fa-chalkboard-user text-xl text-gray-400"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-medium text-[#222222] text-[14px] line-clamp-1 leading-tight mb-0.5"><?= htmlspecialchars($s['titulo'] ?? '') ?></h3>
                                        <div class="flex items-center gap-1.5 text-[11px] text-gray-400 font-medium truncate">
                                            <div class="w-4 h-4 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-[7px] font-bold shrink-0">
                                                <?= $inicial_tutor ?>
                                            </div>
                                            <span class="truncate"><?= formatearNombrePrivado($s['vendedor_nombre'] ?? '') ?></span>
                                            <span>•</span>
                                            <span class="<?= $clase ?> px-1.5 py-0.5 rounded text-[10px] font-medium uppercase tracking-wide"><?= htmlspecialchars($texto) ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex flex-col items-end gap-1.5 shrink-0 pl-2 z-20">
                                    <span class="font-medium text-[#222222] text-[15px] tabular-nums tracking-[-0.01em] leading-none text-right">
                                        $<?= number_format((int)($s['monto'] ?? 0), 0, ',', '.') ?>
                                    </span>

                                    <div class="flex justify-end items-center gap-1 bg-gray-100 p-1 rounded-full border border-transparent">
                                        <?php if (!$is_cerrado && $estado !== ''): ?>
                                            <a href="/app/mini_aula.php?id=<?= (int)($s['id'] ?? 0) ?>" class="w-7 h-7 flex items-center justify-center rounded-full text-gray-500 hover:bg-white hover:text-emerald-500 active:bg-white transition focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2" title="Ir al Aula">
                                                <i class="fa-solid fa-door-open text-xs"></i>
                                            </a>
                                        <?php else: ?>
                                            <button disabled class="w-7 h-7 flex items-center justify-center rounded-full text-gray-300 cursor-not-allowed" title="Servicio Cerrado">
                                                <i class="fa-solid fa-lock text-xs"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-6 text-center">
                            <div class="w-10 h-10 bg-white border border-gray-100 text-gray-300 rounded-full flex items-center justify-center mx-auto mb-2">
                                <i class="fa-solid fa-graduation-cap text-lg"></i>
                            </div>
                            <p class="text-sm font-medium text-gray-400">No has contratado servicios aún.</p>
                        </div>
                    <?php endif; ?>
                </div>
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

// [NUBIRA 2.0] Volver — mismo patrón que las demás páginas de gestión, con fallback
// a /perfil (mismo tile de origen: "Mis Compras" en panel_gestion.php).
window.navegacionSeguraNubira = function() {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        window.location.href = '/perfil';
    }
};

// ACORDEÓN LÓGICA (Gira la flecha)
function toggleGrupo(idGrupo, idIcono) {
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
</script>

</body>
</html>
