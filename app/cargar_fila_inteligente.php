<?php
/**
 * CARGADOR FILA INTELIGENTE - SELECCIÓN PREMIUM
 * - Sincronizado 100% con vitrina.php y cargar_servicios.php.
 * - Diseño Nubira 2.0: Cards estilo Airbnb, compact-root.
 * - YouTube Edition Badges
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();
if (!isset($_SESSION['usuario_id'])) exit;

$rutas = [__DIR__.'/conexion.php', __DIR__.'/../conexion.php', $_SERVER['DOCUMENT_ROOT'].'/conexion.php'];
foreach($rutas as $r) if(file_exists($r)){ require_once $r; break; }
if(!isset($conn)) exit;

// Asegurarnos de tener los iconos oficiales
$rutas_iconos = [__DIR__.'/iconos.php', __DIR__.'/../iconos.php', $_SERVER['DOCUMENT_ROOT'].'/app/iconos.php'];
foreach($rutas_iconos as $ri) if(file_exists($ri)){ require_once $ri; break; }

$rutas_img = [__DIR__.'/helpers/imagen_servicio.php', $_SERVER['DOCUMENT_ROOT'].'/app/helpers/imagen_servicio.php'];
foreach($rutas_img as $rim) if(file_exists($rim)){ require_once $rim; break; }

// 1. CONFIGURACIÓN DE SECCIÓN
$titulo = "Selección Premium";
$default_img = 'https://nubira.cl/upload/servicios/default_clases.webp';

// 2. CONSULTA SQL MAESTRA
$sql = "SELECT s.*, 
        a.nombre as tutor_nombre,
        a.foto_perfil as tutor_foto,
        COALESCE(dp.institucion, a.institucion) as institucion_maestra,
        COALESCE(stats.rating_promedio, 0) as rating_promedio,
        COALESCE(stats.total_votos, 0) as total_votos,
        bi.archivo as banco_archivo
        FROM servicios s
        LEFT JOIN alumnos a ON s.alumno_id = a.id
        LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
        LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
        LEFT JOIN (
            SELECT servicio_id, AVG(calificacion_comprador) as rating_promedio, COUNT(*) as total_votos
            FROM contratos WHERE calificacion_comprador > 0 GROUP BY servicio_id
        ) stats ON stats.servicio_id = s.id
        WHERE s.estado = 'aprobado' AND COALESCE(s.visible, 1) = 1
        ORDER BY s.precio DESC, s.score_nubira DESC, rating_promedio DESC 
        LIMIT 8";

$items = [];
$res = $conn->query($sql);
if($res) while($r = $res->fetch_assoc()) $items[] = $r;

if (empty($items)) exit;

// 3. FUNCIONES HELPER (Idénticas a vitrina.php)
require_once __DIR__ . '/helpers/institucion.php';

if (!function_exists('render_rating_html')) {
    function render_rating_html(float $rating_val, int $total_votos, string $fallback_label = 'Nuevo'): string {
        if ($total_votos > 0) {
            return '<div class="flex items-center gap-1 bg-gray-50 px-1.5 py-0.5 rounded border border-gray-100">
                        <svg class="w-3 h-3 text-gray-900 pb-[1px]" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
                        <span class="text-[10px] font-bold text-gray-800 leading-none">'.number_format($rating_val, 1).'</span>
                    </div>';
        }
        return '<span class="text-[10px] font-medium text-gray-400">' . htmlspecialchars($fallback_label) . '</span>';
    }
}
?>

<div class="flex items-end justify-between mb-3 px-1 animate-fade-in-up">
    <div>
        <h2 class="text-lg md:text-xl font-bold text-gray-900 tracking-tight leading-none">
            <?= htmlspecialchars($titulo) ?>
        </h2>
    </div>
</div>

<div class="relative group animate-fade-in-up delay-100">
    <button onclick="scrollCarrusel('sec-inteligente', -1)" class="hidden md:flex absolute -left-5 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-lg items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-gray-200 transition"><i class="fa-solid fa-chevron-left text-xs"></i></button>
    
    <div id="sec-inteligente" class="flex gap-4 md:gap-5 overflow-x-auto snap-x snap-mandatory pb-3 no-scrollbar scroll-smooth compact-root px-1">
        
        <?php foreach ($items as $row): 
            
            // [NUBIRA SHIELD] Enmascarar ID
            $link_hash = function_exists('nubira_encriptar_id') ? nubira_encriptar_id($row['id']) : (int)$row['id'];

            // 1. Imagen del servicio (banco → legacy → placeholder, vía helper unificado)
            $img_url = url_portada($row);

            // 2. Lógica de Tutor
            $nombre_completo = !empty($row['tutor_nombre']) ? $row['tutor_nombre'] : 'Profesor';
            $partes_nombre = array_values(array_filter(explode(' ', trim((string)$nombre_completo))));
            $tutor_nombre = "Profesor";
            if (!empty($partes_nombre[0])) {
                $tutor_nombre = ucwords(strtolower($partes_nombre[0]));
                if (count($partes_nombre) >= 2) {
                    $tutor_nombre .= ' ' . strtoupper(substr($partes_nombre[count($partes_nombre)-1], 0, 1)) . '.';
                }
            }
            $foto_tutor = !empty($row['tutor_foto']) ? '/app/perfil/fotos/' . $row['tutor_foto'] : "https://ui-avatars.com/api/?name=".urlencode($tutor_nombre)."&background=f1f5f9&color=64748b";

            // 3. Precios
            $precio_val = $row['precio'] ?? 0;
            if (is_numeric($precio_val) && $precio_val > 0) {
                $precio = "$" . number_format($precio_val, 0, ',', '.') . ""; 
                $precio_class = "text-gray-900"; 
            } else {
                $precio = "Gratis"; 
                $precio_class = "text-green-600";
            }
            
            // 4. Lógica de Tiers (YouTube Edition)
            $score = (int)($row['score_nubira'] ?? 0);
            $total_v = (int)$row['total_votos'];
            $rating_val = (float)$row['rating_promedio'];
            
            $nivel_tutor = '';
            $es_basico = ($score < 60);

            if ($score >= 100 && $total_v >= 10 && $rating_val >= 4.7) $nivel_tutor = 'leyenda';
            elseif ($score >= 80 && $total_v >= 3 && $rating_val >= 4.0) $nivel_tutor = 'elite';
            elseif ($score >= 80) $nivel_tutor = 'pro';
            elseif ($score >= 60) $nivel_tutor = 'top';

            // 5. Inyección del Bloque EXACTO (Modalidad, Rating, Institución)
            $mod = strtolower($row['modalidad'] ?? '');
            $icon_mod = '<i class="fa-solid fa-laptop text-[10px]"></i>';
            if (strpos($mod,'online')!==false) $icon_mod = '<i class="fa-solid fa-wifi text-[10px]"></i>';
            elseif (strpos($mod,'presencial')!==false) $icon_mod = '<i class="fa-solid fa-user-group text-[10px]"></i>';
            
            $html_stars = render_rating_html($rating_val, $total_v);
            $inst_text = institucion_tutor($row['institucion_maestra'] ?? ($row['institucion'] ?? ''));
        ?>

        <a href="/detalle-servicio/<?= $link_hash ?>" 
           onclick="registrarClick(<?= (int)$row['id'] ?>, 'servicio')" 
           class="block flex flex-col cursor-pointer group snap-center w-[220px] md:w-[240px] flex-shrink-0 bg-transparent h-full <?= $es_basico ? 'opacity-90 grayscale-[15%]' : '' ?>">

            <div class="relative w-full aspect-[4/3] bg-gray-100 overflow-hidden rounded-2xl">
               <img src="<?= htmlspecialchars((string)$img_url) ?>" 
     alt="<?= htmlspecialchars((string)$row['titulo']) ?>" 
     class="w-full h-full object-cover" 
     loading="lazy" 
     onerror="this.onerror=null;this.src='<?= $default_img ?>';">
               <div class="absolute top-2 right-2 z-10 shrink-0">
    <img src="<?= htmlspecialchars((string)$foto_tutor, ENT_QUOTES, 'UTF-8') ?>" 
         class="w-8 h-8 rounded-full object-cover shadow-md border-[1.5px] border-white/95 bg-gray-50 transform-gpu"
         style="image-rendering: -webkit-optimize-contrast; backface-visibility: hidden;"
         onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($tutor_nombre) ?>&background=f1f5f9&color=64748b'">
</div>
                <div class="absolute top-2.5 left-2.5 flex flex-wrap gap-1 z-10">
                    <?php if ($nivel_tutor === 'leyenda'): ?>
                        <span class="bg-gradient-to-tr from-red-700 to-rose-500 text-white text-[9px] font-extrabold uppercase tracking-wider px-2.5 py-1 rounded-full flex items-center gap-1 shadow-sm border border-red-400/30">
                            <i class="fa-solid fa-gem text-[7px]"></i> 
                        </span>
                    <?php elseif ($nivel_tutor === 'elite'): ?>
                        <span class="bg-gradient-to-tr from-cyan-400 to-blue-500 text-white text-[9px] font-extrabold uppercase tracking-wider px-2.5 py-1 rounded-full border border-cyan-300/30 flex items-center gap-1 shadow-sm">
                            <i class="fa-solid fa-diamond text-[7px]"></i> 
                        </span>
                    <?php elseif ($nivel_tutor === 'pro'): ?>
                        <span class="bg-gradient-to-tr from-yellow-400 to-amber-500 text-white text-[9px] font-extrabold uppercase tracking-wider px-2.5 py-1 rounded-full border border-yellow-300/30 shadow-sm flex items-center gap-1">
                            <i class="fa-solid fa-star text-[7px]"></i> 
                        </span>
                    <?php elseif ($nivel_tutor === 'top'): ?>
                        <span class="bg-gradient-to-tr from-slate-200 to-gray-300 text-slate-800 text-[9px] font-extrabold uppercase tracking-wider px-2.5 py-1 rounded-full border border-white/60 flex items-center gap-1 shadow-sm">
                            <i class="fa-solid fa-star text-[7px]"></i> 
                        </span>
                    <?php endif; ?>
                </div>
            </div>

           <div class="pt-2.5 flex flex-col flex-1 text-left">
                <h3 class="font-semibold text-[14px] leading-snug text-gray-900 line-clamp-2 mb-1 min-h-[40px]">
                    <?= htmlspecialchars((string)$row['titulo']) ?>
                </h3>
                
                <div class="text-[14px] font-bold <?= $precio_class ?> mt-auto mb-1.5 leading-none">
                    <?= $precio ?>
                </div>
                
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-1.5 text-[10px] text-gray-500 truncate max-w-[65%]">
                        <?php if(!empty($inst_text)): ?>
                            <?php if(function_exists('icon')): ?>
                                <?= icon('building', 'w-3 h-3 text-gray-300 flex-shrink-0') ?>
                            <?php else: ?>
                                <svg class="w-3 h-3 text-gray-300 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"></path></svg>
                            <?php endif; ?>
                            <span class="truncate"><?= $inst_text ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="shrink-0 flex items-center gap-1">
                        <?= $html_stars ?>
                    </div>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    
    <button onclick="scrollCarrusel('sec-inteligente', 1)" class="hidden md:flex absolute -right-5 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-lg items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-gray-200 transition"><i class="fa-solid fa-chevron-right text-xs"></i></button>
</div>