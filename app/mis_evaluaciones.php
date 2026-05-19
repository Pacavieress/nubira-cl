<?php
/**
 * VISTA: MIS EVALUACIONES
 * ESTADO: FIX DEFINITIVO - LÓGICA DE SUPERVIVENCIA SQL + UI Nativa (Flat Design)
 */

// 1. CONFIGURACIÓN
ini_set('display_errors', 0); // Producción: 0
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$base_path = __DIR__;

// Verificar dependencias
if (!file_exists($base_path . '/conexion.php')) {
    $base_path = dirname(__DIR__) . '/app'; // Intento de autoreparar ruta
}
if (!file_exists($base_path . '/conexion.php')) {
    die("Error crítico: Sistema de archivos no encontrado.");
}

require_once $base_path . '/conexion.php';
require_once $base_path . '/iconos.php';

// 2. SEGURIDAD
if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login");
    exit;
}

$uid = (int)$_SESSION['usuario_id'];

// 3. HELPER FECHA
function fecha_segura($fecha) {
    if (!$fecha) return '-';
    $ts = strtotime($fecha);
    $meses = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    return date('d', $ts) . ' ' . $meses[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

// 4. HELPER ESTRELLAS (Diseño Flat Nativo)
function estrellas_html($n) {
    $html = '<div class="flex text-amber-400 text-[10px] md:text-xs gap-0.5">';
    for($i=1; $i<=5; $i++) {
        $html .= ($i <= $n) ? '<i class="fa-solid fa-star"></i>' : '<i class="fa-solid fa-star text-slate-200"></i>';
    }
    $html .= '</div>';
    return $html;
}

// 5. OBTENER EVALUACIONES (LÓGICA DE SUPERVIVENCIA)
function ejecutarConsultaSegura($conn, $uid, $rol) {
    $resultados = [];
    
    // OPCIÓN A: Estructura Ideal (Nubira 2.0)
    $sql_A = "SELECT v.*, u.nombre, u.apellidos, u.foto_perfil, s.titulo as servicio_titulo
              FROM valoraciones v
              LEFT JOIN alumnos u ON v.id_evaluador = u.id
              LEFT JOIN servicios s ON v.servicio_id = s.id
              WHERE v.id_evaluado = ? AND v.rol_evaluado = ? ORDER BY v.fecha DESC";

    // OPCIÓN B: Estructura Legacy Común
    $sql_B = "SELECT v.*, u.nombre, u.apellidos, u.foto as foto_perfil, s.titulo as servicio_titulo
              FROM valoraciones v
              LEFT JOIN alumnos u ON v.id_evaluador = u.id
              LEFT JOIN servicios s ON v.servicio_id = s.id
              WHERE v.id_evaluado = ? AND v.rol_evaluado = ? ORDER BY v.fecha DESC";

    // OPCIÓN C: Modo Supervivencia (Solo Nombre)
    $sql_C = "SELECT v.*, u.nombre, '' as apellidos, '' as foto_perfil, s.titulo as servicio_titulo
              FROM valoraciones v
              LEFT JOIN alumnos u ON v.id_evaluador = u.id
              LEFT JOIN servicios s ON v.servicio_id = s.id
              WHERE v.id_evaluado = ? AND v.rol_evaluado = ? ORDER BY v.fecha DESC";

    // INTENTO 1
    try {
        $stmt = $conn->prepare($sql_A);
        $stmt->bind_param("is", $uid, $rol);
        $stmt->execute();
        $res = $stmt->get_result();
        while($row = $res->fetch_assoc()) $resultados[] = $row;
        return $resultados;
    } catch (Exception $e) {}

    // INTENTO 2
    try {
        $stmt = $conn->prepare($sql_B);
        $stmt->bind_param("is", $uid, $rol);
        $stmt->execute();
        $res = $stmt->get_result();
        while($row = $res->fetch_assoc()) $resultados[] = $row;
        return $resultados;
    } catch (Exception $e) {}

    // INTENTO 3
    try {
        $stmt = $conn->prepare($sql_C);
        $stmt->bind_param("is", $uid, $rol);
        $stmt->execute();
        $res = $stmt->get_result();
        while($row = $res->fetch_assoc()) $resultados[] = $row;
        return $resultados;
    } catch (Exception $e) {
        return []; 
    }
}

// Ejecutamos la función blindada
$reviews_vendedor  = ejecutarConsultaSegura($conn, $uid, 'vendedor');
$reviews_comprador = ejecutarConsultaSegura($conn, $uid, 'comprador');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
    <title>Mis Evaluaciones | Nubira</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #ffffff; -webkit-tap-highlight-color: transparent; }
        .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
        .force-no-shadow * { text-shadow: none !important; }
        /* Animación tab transition */
        .tab-transition { transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); }
    </style>
</head>
<body class="text-slate-800 antialiased overflow-x-hidden force-no-shadow">

    <?php if(file_exists($base_path . '/componentes/header.php')) include $base_path . '/componentes/header.php'; ?>
    <?php if(file_exists($base_path . '/componentes/sidebar.php')) include $base_path . '/componentes/sidebar.php'; ?>

   <main class="pt-16 pb-32 md:pb-16 md:ml-64 mx-auto max-w-[1000px]">
        <div class="w-full">
            
            <div class="sticky top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-slate-100 px-4 md:px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl md:text-2xl font-extrabold text-slate-900 tracking-tight">Mis Evaluaciones</h1>
                    <p class="text-slate-400 text-xs font-medium">Historial de reputación y comentarios.</p>
                </div>
            </div>

            <div class="sticky top-[104px] md:top-[90px] bg-white/95 backdrop-blur-sm z-20 border-b border-slate-100">
                <div class="flex">
                    <button onclick="showTab('vendedor')" id="btn-vendedor" class="flex-1 py-3.5 text-[10px] md:text-xs font-bold text-[#54A6D8] border-b-2 border-[#54A6D8] transition-all uppercase tracking-widest">
                        Tutor (<?= count($reviews_vendedor) ?>)
                    </button>
                    <button onclick="showTab('comprador')" id="btn-comprador" class="flex-1 py-3.5 text-[10px] md:text-xs font-bold text-slate-400 hover:text-slate-600 transition-all border-b-2 border-transparent uppercase tracking-widest">
                        Estudiante (<?= count($reviews_comprador) ?>)
                    </button>
                </div>
            </div>

            <div class="md:px-6 pt-2">
                
                <div id="list-vendedor" class="tab-transition opacity-100">
                    <?php if (count($reviews_vendedor) > 0): ?>
                        <ul class="divide-y divide-slate-100">
                            <?php foreach($reviews_vendedor as $r): ?>
                                <li class="flex items-start gap-4 p-4 md:px-2 hover:bg-slate-50 transition-colors">
                                    
                                    <div class="w-10 h-10 rounded-full bg-slate-100 flex-shrink-0 overflow-hidden border border-slate-100 relative mt-1">
                                        <?php 
                                            $foto = "";
                                            if (!empty($r['foto_perfil'])) $foto = "/app/perfil/fotos/".htmlspecialchars($r['foto_perfil']);
                                            $inicial = strtoupper(substr($r['nombre'] ?? 'U', 0, 1));
                                        ?>
                                        <?php if($foto): ?>
                                            <img src="<?= $foto ?>" class="w-full h-full object-cover z-10 relative" onerror="this.style.display='none';">
                                        <?php endif; ?>
                                        <div class="absolute inset-0 flex items-center justify-center text-slate-400 font-bold text-xs bg-slate-100 z-0">
                                            <?= $inicial ?>
                                        </div>
                                    </div>

                                    <div class="flex-1 min-w-0">
                                        <div class="flex justify-between items-center mb-0.5">
                                            <h4 class="font-bold text-sm text-slate-900 truncate pr-2">
                                                <?= htmlspecialchars($r['nombre'] . ' ' . ($r['apellidos'] ?? '')) ?>
                                            </h4>
                                            <span class="text-[10px] font-medium text-slate-400 shrink-0"><?= fecha_segura($r['fecha']) ?></span>
                                        </div>
                                        
                                        <div class="mb-1.5"><?= estrellas_html($r['calificacion']) ?></div>
                                        
                                        <p class="text-sm text-slate-700 leading-snug break-words">"<?= nl2br(htmlspecialchars($r['comentario'])) ?>"</p>
                                        
                                        <?php if(!empty($r['servicio_titulo'])): ?>
                                            <div class="mt-2 text-[9px] font-bold text-slate-500 bg-slate-100 inline-flex items-center gap-1.5 px-2 py-1 rounded-md uppercase tracking-wider">
                                                <i class="fa-solid fa-chalkboard-user text-[#54A6D8]"></i>
                                                <span class="truncate max-w-[200px]"><?= htmlspecialchars($r['servicio_titulo']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="text-center py-12 px-4">
                            <div class="w-12 h-12 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-3 text-slate-300">
                                <i class="fa-solid fa-store text-xl"></i>
                            </div>
                            <h3 class="text-sm font-bold text-slate-800">Sin evaluaciones</h3>
                            <p class="text-xs font-medium text-slate-400 mt-1">No tienes evaluaciones como tutor aún.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div id="list-comprador" class="tab-transition opacity-0 hidden">
                    <?php if (count($reviews_comprador) > 0): ?>
                        <ul class="divide-y divide-slate-100">
                            <?php foreach($reviews_comprador as $r): ?>
                                <li class="flex items-start gap-4 p-4 md:px-2 hover:bg-slate-50 transition-colors">
                                    
                                    <div class="w-10 h-10 rounded-full bg-slate-100 flex-shrink-0 overflow-hidden border border-slate-100 relative mt-1">
                                        <?php 
                                            $foto = "";
                                            if (!empty($r['foto_perfil'])) $foto = "/app/perfil/fotos/".htmlspecialchars($r['foto_perfil']);
                                            $inicial = strtoupper(substr($r['nombre'] ?? 'U', 0, 1));
                                        ?>
                                        <?php if($foto): ?>
                                            <img src="<?= $foto ?>" class="w-full h-full object-cover z-10 relative" onerror="this.style.display='none';">
                                        <?php endif; ?>
                                        <div class="absolute inset-0 flex items-center justify-center text-slate-400 font-bold text-xs bg-slate-100 z-0">
                                            <?= $inicial ?>
                                        </div>
                                    </div>

                                    <div class="flex-1 min-w-0">
                                        <div class="flex justify-between items-center mb-0.5">
                                            <h4 class="font-bold text-sm text-slate-900 truncate pr-2">
                                                <?= htmlspecialchars($r['nombre'] . ' ' . ($r['apellidos'] ?? '')) ?>
                                            </h4>
                                            <span class="text-[10px] font-medium text-slate-400 shrink-0"><?= fecha_segura($r['fecha']) ?></span>
                                        </div>
                                        
                                        <div class="mb-1.5"><?= estrellas_html($r['calificacion']) ?></div>
                                        
                                        <p class="text-sm text-slate-700 leading-snug break-words">"<?= nl2br(htmlspecialchars($r['comentario'])) ?>"</p>
                                        
                                        <?php if(!empty($r['servicio_titulo'])): ?>
                                            <div class="mt-2 text-[9px] font-bold text-slate-500 bg-slate-100 inline-flex items-center gap-1.5 px-2 py-1 rounded-md uppercase tracking-wider">
                                                <i class="fa-solid fa-chalkboard-user text-orange-500"></i>
                                                <span class="truncate max-w-[200px]"><?= htmlspecialchars($r['servicio_titulo']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="text-center py-12 px-4">
                            <div class="w-12 h-12 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-3 text-slate-300">
                                <i class="fa-solid fa-bag-shopping text-xl"></i>
                            </div>
                            <h3 class="text-sm font-bold text-slate-800">Sin evaluaciones</h3>
                            <p class="text-xs font-medium text-slate-400 mt-1">No tienes evaluaciones como estudiante aún.</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
   </main>

    <?php 
    // INYECCIÓN MODULAR OFICIAL DE NUBIRA 2.0
    require_once $base_path . '/componentes/nav_bottom.php'; 
    require_once $base_path . '/componentes/modal_publicar.php'; 
    require_once $base_path . '/componentes/modal_explora.php'; 
    ?>

    <?php 
    $rutas_footer = [
        $base_path . '/includes/footer.php',
        dirname($base_path) . '/includes/footer.php',
        $_SERVER['DOCUMENT_ROOT'] . '/app/includes/footer.php',
        $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'
    ];

    foreach ($rutas_footer as $ruta) {
        if (file_exists($ruta)) {
            require_once $ruta;
            break;
        }
    }
    ?>

    <script>
        function showTab(type) {
            const btnV = document.getElementById('btn-vendedor');
            const btnC = document.getElementById('btn-comprador');
            const listV = document.getElementById('list-vendedor');
            const listC = document.getElementById('list-comprador');

            if(type === 'vendedor') {
                // Activar Vendedor
                btnV.className = "flex-1 py-3.5 text-[10px] md:text-xs font-bold text-[#54A6D8] border-b-2 border-[#54A6D8] transition-all uppercase tracking-widest";
                btnC.className = "flex-1 py-3.5 text-[10px] md:text-xs font-bold text-slate-400 hover:text-slate-600 transition-all border-b-2 border-transparent uppercase tracking-widest";
                
                listC.classList.add('hidden', 'opacity-0');
                listV.classList.remove('hidden');
                // Pequeño delay para la transición de opacidad
                setTimeout(() => listV.classList.remove('opacity-0'), 10);

            } else {
                // Activar Comprador
                btnC.className = "flex-1 py-3.5 text-[10px] md:text-xs font-bold text-orange-500 border-b-2 border-orange-500 transition-all uppercase tracking-widest";
                btnV.className = "flex-1 py-3.5 text-[10px] md:text-xs font-bold text-slate-400 hover:text-slate-600 transition-all border-b-2 border-transparent uppercase tracking-widest";
                
                listV.classList.add('hidden', 'opacity-0');
                listC.classList.remove('hidden');
                setTimeout(() => listC.classList.remove('opacity-0'), 10);
            }
        }

        // --- LÓGICA DE MODALES NUBIRA 2.0 ---
        document.addEventListener('DOMContentLoaded', () => {
            function setupModal(triggerId, modalId, cardId, closeId) {
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

                btn.onclick = (e) => { e.preventDefault(); open(); }; 
                if(close) close.onclick = shut; 
                modal.onclick = (e) => { if(e.target === modal) shut(); };
            }

            // Inicializar modales
            setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
            setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');

            // Limpieza de notificaciones de valoraciones
            fetch('/app/limpiar_alertas_valoraciones.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-cache' },
                cache: 'no-store'
            }).catch(e => {});
        });
    </script>
</body>
</html>