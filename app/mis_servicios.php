<?php
/**
 * VISTA: MIS PUBLICACIONES (Servicios + Apuntes Unificados)
 * UBICACIÓN: public_html/app/mis_servicios_publicados.php
 * ESTADO: Nubira 2.0 - App Nativa (Acordeón Cerrado por defecto, Flechas Corregidas)
 */
session_start();

if (!isset($_SESSION['usuario_id'])) { header('Location: /login?redir=' . urlencode($_SERVER['REQUEST_URI'])); exit; }

$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists($app_dir . '/app/conexion.php')) $app_dir = $app_dir . '/app';
    elseif (file_exists(dirname($app_dir) . '/app')) $app_dir = dirname($app_dir) . '/app';
}

require_once $app_dir . '/conexion.php';
$usuario_id = (int)$_SESSION['usuario_id'];

// --- LÓGICA DE BORRADO LÓGICO (SOFT DELETE BLINDADO) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    
    // Eliminar Servicio
    if ($_POST['accion'] === 'eliminar_servicio' && !empty($_POST['id'])) {
        try {
            $id_del = (int)$_POST['id'];
            $sqlDelS = "UPDATE servicios SET visible = 0 WHERE id = ? AND alumno_id = ?";
            $stmtDel = $conn->prepare($sqlDelS);
            if ($stmtDel) {
                $stmtDel->bind_param("ii", $id_del, $usuario_id);
                $stmtDel->execute();
                $stmtDel->close();
            }
        } catch (Exception $e) { /* Silenciamos el error para no dar 500 */ }
    }
    // Reactivar Servicio Pausado
    if ($_POST['accion'] === 'reactivar_servicio' && !empty($_POST['id'])) {
        try {
            $id_react = (int)$_POST['id'];
            // Lo devolvemos al estado 'aprobado'
            $sqlReac = "UPDATE servicios SET estado = 'aprobado' WHERE id = ? AND alumno_id = ?";
            $stmtReac = $conn->prepare($sqlReac);
            if ($stmtReac) {
                $stmtReac->bind_param("ii", $id_react, $usuario_id);
                $stmtReac->execute();
                $stmtReac->close();
            }
        } catch (Exception $e) { /* Silenciamos */ }
    }

    // Eliminar Apunte (Probando ambas columnas de ID posibles)
    if ($_POST['accion'] === 'eliminar_apunte' && !empty($_POST['id'])) {
        $id_del = (int)$_POST['id'];
        try {
            // Intento 1: Asumiendo que se llama id_alumno
            $sqlDelA = "UPDATE apuntes SET visible = 0 WHERE id = ? AND id_alumno = ?";
            $stmtDel = $conn->prepare($sqlDelA);
            if ($stmtDel) {
                $stmtDel->bind_param("ii", $id_del, $usuario_id);
                $stmtDel->execute();
                $stmtDel->close();
            }
        } catch (Exception $e) {
            try {
                // Intento 2: Asumiendo que se llama alumno_id
                $sqlDelA2 = "UPDATE apuntes SET visible = 0 WHERE id = ? AND alumno_id = ?";
                $stmtDel2 = $conn->prepare($sqlDelA2);
                if ($stmtDel2) {
                    $stmtDel2->bind_param("ii", $id_del, $usuario_id);
                    $stmtDel2->execute();
                    $stmtDel2->close();
                }
            } catch (Exception $e2) { /* Silenciar */ }
        }
    }

    // Redirección Segura (Evita el choque de headers_sent)
    $ruta_limpia = strtok($_SERVER["REQUEST_URI"], '?');
    if (!headers_sent()) {
        header("Location: " . $ruta_limpia);
    } else {
        echo "<script>window.location.href='" . htmlspecialchars($ruta_limpia, ENT_QUOTES, 'UTF-8') . "';</script>";
    }
    exit;
}
// -------------------------------------------------------

require_once $app_dir . '/iconos.php';
require_once $app_dir . '/helpers/seo.php';

// 1. Obtener Servicios (Clases) - Excluyendo los eliminados
$servicios = [];
try {
    $sqlServicios = "SELECT * FROM servicios WHERE alumno_id = ? AND COALESCE(visible, 1) = 1 ORDER BY fecha_publicacion DESC";
    $stmtS = $conn->prepare($sqlServicios);
    if ($stmtS) {
        $stmtS->bind_param("i", $usuario_id);
        $stmtS->execute();
        $res = $stmtS->get_result();
        while ($row = $res->fetch_assoc()) $servicios[] = $row;
        $stmtS->close();
    }
} catch (Exception $e) { }

// 2. Obtener Apuntes - Excluyendo los eliminados
$apuntes = [];
try {
    $sqlApuntes = "SELECT * FROM apuntes WHERE id_alumno = ? AND COALESCE(visible, 1) = 1 ORDER BY fecha_subida DESC";
    $stmtA = $conn->prepare($sqlApuntes);
    if (!$stmtA) {
        $sqlApuntes = "SELECT * FROM apuntes WHERE alumno_id = ? AND COALESCE(visible, 1) = 1 ORDER BY fecha_subida DESC";
        $stmtA = $conn->prepare($sqlApuntes);
    }
    if ($stmtA) {
        $stmtA->bind_param("i", $usuario_id);
        $stmtA->execute();
        $resA = $stmtA->get_result();
        while ($row = $resA->fetch_assoc()) $apuntes[] = $row;
        $stmtA->close();
    }
} catch (Exception $e) { }

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mis Publicaciones | Nubira</title>
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

<body class="text-slate-800 antialiased overflow-x-hidden select-none md:select-auto bg-white">

<div id="loader" class="fixed inset-0 bg-white flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-7 w-7 border-4 border-slate-200 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
require_once $app_dir . '/componentes/header.php'; 
require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-16 pb-32 md:pb-12 lg:ml-64 mx-auto max-w-[1000px]">
  <div class="w-full">
    
    <div class="sticky top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-slate-100 px-4 md:px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl md:text-2xl font-extrabold text-slate-900 tracking-tight">Mis Publicaciones</h1>
            <p class="text-slate-400 text-xs font-medium">Gestiona tus contenidos ofrecidos.</p>
        </div>
    </div>

    <div class="md:px-6 pt-2">
        <div id="lista-publicaciones" class="pb-10 space-y-2 mt-2">
            
            <div class="group-dia space-y-1" id="seccion-clases">
                <button onclick="toggleGrupo('content-clases', 'icon-clases')" class="w-full px-4 md:px-2 pt-4 pb-2 flex items-center justify-between active:bg-slate-50 transition-colors cursor-pointer sticky top-[108px] sm:top-[115px] z-20 bg-white">
                    <div class="flex items-center gap-2">
                        <h2 class="text-xs font-bold text-slate-400 uppercase tracking-widest">Clases o Servicios (<?= count($servicios) ?>)</h2>
                    </div>
                    <i id="icon-clases" class="fa-solid fa-chevron-down text-slate-400 text-[10px] chevron-icon"></i>
                </button>

                <div id="content-clases" class="expand-content collapsed bg-white border-y md:border border-slate-100 md:rounded-2xl">
                    <?php if (count($servicios) > 0): ?>
                        <ul class="divide-y divide-slate-100">
                            <?php foreach($servicios as $s): 
                                $rutaImg = !empty($s['imagen']) ? '/upload/servicios/'.basename($s['imagen']) : '/img/portadas/servicios/clases.webp';
                                $estadoColor = match($s['estado'] ?? 'pendiente') {
    'aprobado' => 'text-emerald-500 bg-emerald-50',
    'pendiente' => 'text-amber-500 bg-amber-50',
    'rechazado' => 'text-red-500 bg-red-50',
    'pausado' => 'text-orange-600 bg-orange-100', // NUEVO: Feedback visual claro
    default => 'text-slate-500 bg-slate-50'
};
                            ?>
                            <li class="flex items-center justify-between p-4 md:px-4 hover:bg-slate-50 transition-colors gap-3 active:bg-slate-100">
                                <div class="flex items-center gap-3 flex-1 min-w-0 z-20">
                                    <div class="w-12 h-12 rounded-xl bg-slate-100 overflow-hidden shrink-0 border border-slate-100 relative">
                                        <img src="<?= htmlspecialchars($rutaImg, ENT_QUOTES, 'UTF-8') ?>" class="w-full h-full object-cover">
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-bold text-slate-800 text-[14px] line-clamp-1 leading-tight mb-0.5"><?= htmlspecialchars($s['titulo'] ?? 'Sin título', ENT_QUOTES, 'UTF-8') ?></h3>
                                        <div class="flex items-center gap-1.5 text-[11px] text-slate-400 font-medium truncate">
                                            <span class="<?= $estadoColor ?> px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide"><?= htmlspecialchars($s['estado'] ?? 'Pendiente', ENT_QUOTES, 'UTF-8') ?></span>
                                            <span>•</span>
                                            <span class="truncate"><?= htmlspecialchars($s['modalidad'] ?? 'Online', ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex flex-col items-end gap-1.5 shrink-0 pl-2 z-20">
                                    <span class="font-black text-slate-900 text-[15px] tabular-nums tracking-tight leading-none text-right">
                                        <?= (!empty($s['precio']) && $s['precio'] > 0) ? '$'.number_format($s['precio'], 0, ',', '.') : 'Gratis' ?>
                                    </span>
                                    
                                   <div class="flex justify-end items-center gap-1 bg-slate-100 p-1 rounded-full border border-transparent">
    
    <?php if (($s['estado'] ?? '') === 'pausado'): ?>
        <form action="" method="POST" class="m-0">
            <input type="hidden" name="accion" value="reactivar_servicio">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button type="submit" class="flex items-center gap-1.5 bg-[#54A6D8] text-white px-3 py-1 rounded-full text-[11px] font-bold shadow-sm hover:bg-blue-600 transition-colors active:scale-95" title="Reactivar publicación">
                <i class="fa-solid fa-play text-[9px]"></i> Reactivar
            </button>
        </form>
    <?php else: ?>
        <a href="<?= url_servicio((int)$s['id'], $s['slug'] ?? null) ?>" class="w-7 h-7 flex items-center justify-center rounded-full text-slate-500 hover:bg-white active:bg-white transition" title="Ver publicación">
            <i class="fa-regular fa-eye text-xs"></i>
        </a>
        <a href="/app/editar_servicio.php?id=<?= $s['id'] ?>" class="w-7 h-7 flex items-center justify-center rounded-full text-slate-500 hover:bg-white hover:text-[#54A6D8] active:bg-white transition" title="Editar">
            <i class="fa-solid fa-pen text-xs"></i>
        </a>
        <form action="" method="POST" onsubmit="return confirm('¿Eliminar esta clase definitivamente?');" class="m-0">
            <input type="hidden" name="accion" value="eliminar_servicio">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button type="submit" class="w-7 h-7 flex items-center justify-center rounded-full text-red-400 hover:bg-white hover:text-red-600 active:bg-white transition" title="Eliminar">
                <i class="fa-solid fa-trash-can text-xs"></i>
            </button>
        </form>
    <?php endif; ?>
    
</div>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="bg-white p-6 text-center border-b border-slate-100 rounded-2xl">
                            <p class="text-sm font-medium text-slate-500">No ofreces clases aún.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="group-dia space-y-1 mt-4" id="seccion-apuntes">
                <button onclick="toggleGrupo('content-apuntes', 'icon-apuntes')" class="w-full px-4 md:px-2 pt-4 pb-2 flex items-center justify-between active:bg-slate-50 transition-colors cursor-pointer sticky top-[108px] sm:top-[115px] z-20 bg-white">
                    <div class="flex items-center gap-2">
                        <h2 class="text-xs font-bold text-slate-400 uppercase tracking-widest">Apuntes (<?= count($apuntes) ?>)</h2>
                    </div>
                    <i id="icon-apuntes" class="fa-solid fa-chevron-down text-slate-400 text-[10px] chevron-icon"></i>
                </button>

                <div id="content-apuntes" class="expand-content collapsed bg-white border-y md:border border-slate-100 md:rounded-2xl">
                    <?php if (count($apuntes) > 0): ?>
                        <ul class="divide-y divide-slate-100">
                            <?php foreach($apuntes as $a): 
                                $es_publico = isset($a['publico']) ? $a['publico'] : ($a['estado'] == 'aprobado' ? 1 : 0);
                                $estadoTxt = $es_publico ? 'Visible' : 'Pendiente';
                                $estadoColor = $es_publico ? 'text-emerald-500 bg-emerald-50' : 'text-amber-500 bg-amber-50';
                                $archivo_nombre = $a['archivo'] ?? $a['archivo_pdf'] ?? '';
                            ?>
                            <li class="flex items-center justify-between p-4 md:px-4 hover:bg-slate-50 transition-colors gap-3 active:bg-slate-100">
                                <div class="flex items-center gap-3 flex-1 min-w-0 z-20">
                                    <div class="w-12 h-12 rounded-xl bg-red-50 text-red-500 flex flex-col items-center justify-center shrink-0 border border-red-100 relative">
                                        <i class="fa-solid fa-file-pdf text-xl mb-px"></i>
                                        <span class="text-[7px] font-black uppercase tracking-widest opacity-60">PDF</span>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-bold text-slate-800 text-[14px] line-clamp-1 leading-tight mb-0.5"><?= htmlspecialchars($a['titulo'] ?? 'Sin título', ENT_QUOTES, 'UTF-8') ?></h3>
                                        <div class="flex items-center gap-1.5 text-[11px] text-slate-400 font-medium truncate">
                                            <span class="<?= $estadoColor ?> px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide"><?= $estadoTxt ?></span>
                                            <span>•</span>
                                            <span class="truncate"><?= htmlspecialchars($a['universidad'] ?? 'General', ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex flex-col items-end gap-1.5 shrink-0 pl-2 z-20">
                                    <span class="font-black text-slate-900 text-[15px] tabular-nums tracking-tight leading-none text-right">
                                        <?= (!empty($a['precio']) && $a['precio'] > 0) ? '$'.number_format($a['precio'], 0, ',', '.') : 'Gratis' ?>
                                    </span>
                                    
                                    <div class="flex justify-end items-center gap-1 bg-slate-100 p-1 rounded-full border border-transparent">
                                        <a href="/ver-apunte?archivo=<?= urlencode($archivo_nombre) ?>" target="_blank" class="w-7 h-7 flex items-center justify-center rounded-full text-slate-500 hover:bg-white active:bg-white transition" title="Ver documento">
                                            <i class="fa-regular fa-eye text-xs"></i>
                                        </a>
                                        <a href="/app/editar_apunte.php?id=<?= $a['id'] ?>" class="w-7 h-7 flex items-center justify-center rounded-full text-slate-500 hover:bg-white hover:text-[#54A6D8] active:bg-white transition" title="Editar">
                                            <i class="fa-solid fa-pen text-xs"></i>
                                        </a>
                                        <form action="" method="POST" onsubmit="return confirm('¿Eliminar este apunte definitivamente?');" class="m-0">
                                            <input type="hidden" name="accion" value="eliminar_apunte">
                                            <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                            <button type="submit" class="w-7 h-7 flex items-center justify-center rounded-full text-red-400 hover:bg-white hover:text-red-600 active:bg-white transition" title="Eliminar">
                                                <i class="fa-solid fa-trash-can text-xs"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="bg-white p-6 text-center border-b border-slate-100 rounded-2xl">
                            <p class="text-sm font-medium text-slate-500">No tienes apuntes subidos.</p>
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
    if(l){ l.classList.add('opacity-0'); setTimeout(() => l.classList.add('hidden'), 300); } 
};

// 🔥 FIX: JS Simplificado para solo rotar (sin cambiar la clase base del ícono)
function toggleGrupo(idGrupo, idIcono) {
    const contenedor = document.getElementById(idGrupo);
    const icono = document.getElementById(idIcono);
    
    if (contenedor.classList.contains('collapsed')) {
        // Abre
        contenedor.classList.remove('collapsed');
        icono.classList.add('rotated'); // Rota a mirar hacia arriba
    } else {
        // Cierra
        contenedor.classList.add('collapsed');
        icono.classList.remove('rotated'); // Vuelve a su estado original (hacia abajo)
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
