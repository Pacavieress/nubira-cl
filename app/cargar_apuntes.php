<?php
session_start();

// === BLOQUE ANTI-CACHE ESTRICTO ===
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// CARGA SEGURA DE CONEXIÓN
if (file_exists(__DIR__ . '/conexion.php')) require_once __DIR__ . '/conexion.php';
elseif (file_exists(dirname(__DIR__) . '/app/conexion.php')) require_once dirname(__DIR__) . '/app/conexion.php';
else require_once $_SERVER['DOCUMENT_ROOT'] . '/app/conexion.php';

// [NUBIRA SHIELD] Cargar enmascarador de URLs
$rutas_shield = [__DIR__ . '/seguridad_url.php', dirname(__DIR__) . '/app/seguridad_url.php', $_SERVER['DOCUMENT_ROOT'] . '/app/seguridad_url.php'];
foreach ($rutas_shield as $rs) {
    if (file_exists($rs)) {
        require_once $rs;
        break;
    }
}

$usuario_id = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
$rol        = $_SESSION['rol'] ?? 'visitante';

date_default_timezone_set('America/Santiago');

/* ============================
 * HELPERS
 * ============================ */
function abreviar_conteo($n) {
    $n = (int)$n;
    if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
    if ($n >= 1000) return round($n / 1000, 1) . 'K';
    return (string)$n;
}

// --- FUNCIÓN DE IMAGEN BLINDADA (NUBIRA 2.0 ANTI-CACHE) ---
function obtenerMiniaturaApunte($id, $portadaBD, $previewBD, $archivoOriginal) {
    $docRoot = $_SERVER['DOCUMENT_ROOT']; 
    
    // Función anidada para inyectar el sello de tiempo (filemtime)
    $getVersionedPath = function($path) use ($docRoot) {
        return $path . '?v=' . filemtime($docRoot . $path);
    };
    
    // 1. Preview WebP
    $rutaWebP = "/upload/preview/{$id}.webp";
    if (file_exists($docRoot . $rutaWebP)) return $getVersionedPath($rutaWebP);

    // 2. Legacy Previews
    $exts = ['jpg', 'png', 'jpeg'];
    foreach($exts as $ext) {
        $rutaLegacy = "/upload/preview/{$id}.{$ext}";
        if (file_exists($docRoot . $rutaLegacy)) return $getVersionedPath($rutaLegacy);
    }

    // 3. Portada personalizada
    if (!empty($portadaBD)) {
        $p = basename($portadaBD);
        if (file_exists($docRoot . "/upload/portadas/" . $p)) return $getVersionedPath("/upload/portadas/" . $p);
        if (file_exists($docRoot . "/upload/preview/" . $p)) return $getVersionedPath("/upload/preview/" . $p); 
    }

    // 4. Archivo Original (Imagen)
    if (!empty($archivoOriginal)) {
        $ext = strtolower(pathinfo($archivoOriginal, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','gif','bmp'])) {
            $rutaOrig = "/upload/apuntes/" . basename($archivoOriginal);
            if (file_exists($docRoot . $rutaOrig)) return $getVersionedPath($rutaOrig);
        }
    }

    // 5. Fallback (No requiere anti-caché)
    return "/img/logo2.webp"; 
}

/* ============================
 * CONFIGURACIÓN
 * ============================ */
$pagina     = max(1, (int)($_GET['pagina'] ?? 1));
$limite     = 10; 
$offset     = ($pagina - 1) * $limite;

// BANDERAS
$compacto   = !empty($_GET['compacto']); 
$hide_inst  = !empty($_GET['hide_inst']);
$no_banners = !empty($_GET['no_banners']);

// Filtros
$q            = trim($_GET['q'] ?? '');
$asignatura   = trim($_GET['asignatura'] ?? '');
$anio         = trim($_GET['anio'] ?? '');
$precioFiltro = $_GET['precio'] ?? '';
$order        = $_GET['orden'] ?? '';
$institFiltro = trim($_GET['institucion'] ?? '');
$nivelFiltro = trim($_GET['nivel'] ?? '');
$niveles_validos = ['universitario', 'paes', 'escolar'];

/* ============================
 * BANNERS (OPTIMIZADO N+1)
 * ============================ */
$banners_entre = [];
if (!$no_banners) {
    $institucion_banner = $_SESSION['institucion'] ?? '';
    
    $sqlB = "SELECT b.id, COALESCE(b.imagen, bi.imagen) as imagen, b.titulo, b.enlace, b.frecuencia, b.orden 
             FROM banners b
             LEFT JOIN banner_imagenes bi ON bi.banner_id = b.id
             WHERE b.activo = 1 AND b.ubicacion = 'apuntes'";
             
    if ($rol !== 'admin') $sqlB .= " AND (b.institucion = ? OR b.institucion IS NULL)";
    
    $sqlB .= " GROUP BY b.id ORDER BY b.orden ASC, b.id ASC";
    
    $stmtB = $conn->prepare($sqlB);
    if ($rol !== 'admin') $stmtB->bind_param("s", $institucion_banner);
    $stmtB->execute();
    $resultB = $stmtB->get_result();
    
    while ($banner = $resultB->fetch_assoc()) {
        $banner['frecuencia'] = max(4, (int)$banner['frecuencia']);
        $banners_entre[] = $banner;
    }
    $stmtB->close();
}

/* ============================
 * CONSULTA APUNTES
 * ============================ */
// [NUBIRA 2.0] Filtro estricto de Soft Delete (Apunte visible + Creador visible)
$filtros = [
    "ap.publico = 1",
    "ap.visible = 1",
    "al.visible = 1",
    "al.bloqueado = 0"
];
$params  = [];
$tipos   = "";

if ($q !== '') {
    $filtros[] = "(ap.titulo LIKE ? OR ap.asignatura LIKE ? OR ap.nombre_curso LIKE ? OR ap.ia_keywords LIKE ? OR ap.categoria LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $tipos .= "sssss";
}
if ($asignatura !== '') { $filtros[] = "ap.asignatura = ?"; $params[] = $asignatura; $tipos .= "s"; }
if ($anio !== '') { $filtros[] = "ap.anio = ?"; $params[] = (int)$anio; $tipos .= "i"; }
if ($precioFiltro === 'gratis') $filtros[] = "ap.precio = 0";
elseif ($precioFiltro === 'pagado') $filtros[] = "ap.precio > 0";
if ($institFiltro !== '') { $filtros[] = "ap.institucion = ?"; $params[] = $institFiltro; $tipos .= "s"; }
if ($nivelFiltro !== '' && in_array($nivelFiltro, $niveles_validos, true)) {
    $filtros[] = "ap.nivel_academico = ?";
    $params[] = $nivelFiltro;
    $tipos .= "s";
}

// OPTIMIZACIÓN DE ORDENAMIENTO
$orderBy = 'ap.fecha_subida DESC, ap.id DESC';
$seed_int = crc32(date('Y-m-d') . floor(date('G') / 4));

switch ($order) {
    case 'fecha_asc':   $orderBy = 'ap.fecha_subida ASC, ap.id ASC'; break;
    case 'precio_desc': $orderBy = 'ap.precio DESC, ap.id DESC'; break;
    case 'precio_asc':  $orderBy = 'ap.precio ASC, ap.id ASC'; break;
    case 'vendidos_desc':
    case 'tendencia':
        $orderBy = "ventas_totales DESC, ap.id DESC";
        break;
    default:
        $orderBy = "RAND($seed_int)";
        break;
}

// --- FIX ABSOLUTO NUBIRA: CÁLCULO DE VENTAS DIRECTO EN MYSQL ---
$cols = "ap.id, ap.id_alumno, ap.titulo, ap.precio, ap.archivo, ap.descripcion, ap.fecha_subida, ap.portada, ap.preview, ap.asignatura, COALESCE(dp.institucion, NULLIF(ap.institucion,''), al.institucion) AS institucion, ap.ia_used, ap.categoria, ap.promo_gratis, ap.promo_limite, ap.promo_contador, ap.descargas AS ventas_totales";

$sql = "SELECT $cols FROM apuntes ap JOIN alumnos al ON al.id = ap.id_alumno LEFT JOIN dominios_permitidos dp ON al.dominio = dp.dominio WHERE " . implode(" AND ", $filtros) . " ORDER BY $orderBy LIMIT ? OFFSET ?";
$params[] = $limite; 
$params[] = $offset; 
$tipos .= "ii";

$stmt = $conn->prepare($sql);
if (!$stmt) exit;

if (!empty($params)) {
    $stmt->bind_param($tipos, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$apuntes = [];
while ($row = $res->fetch_assoc()) $apuntes[] = $row;
$stmt->close();

if (empty($apuntes)) {
    if ($pagina == 1) echo '<div class="col-span-full text-center text-gray-400 py-12 flex flex-col items-center gap-2"><svg class="w-12 h-12 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg><span>No hay apuntes disponibles.</span></div>';
    exit;
}

/* ============================
 * RENDERIZADO UNIFICADO
 * ============================ */
foreach ($apuntes as $i => $a):

    $id = (int)$a['id'];
    $titulo = htmlspecialchars($a['titulo'], ENT_QUOTES, 'UTF-8');
    $precio = (int)$a['precio'];
    $archivo = $a['archivo'] ?? '';
    
    // --- LÓGICA DE PROMO FLASH (FOMO) ---
    $promo_gratis = isset($a['promo_gratis']) ? (int)$a['promo_gratis'] : 0;
    $promo_limite = isset($a['promo_limite']) ? (int)$a['promo_limite'] : 0;
    $promo_contador = isset($a['promo_contador']) ? (int)$a['promo_contador'] : 0;
    $es_promo_activa = ($promo_gratis === 1 && $promo_contador < $promo_limite);
    $descargas_restantes = $promo_limite - $promo_contador;

    // Miniatura
    $rutaMiniatura = obtenerMiniaturaApunte($id, $a['portada'] ?? '', $a['preview'] ?? '', $archivo);
    
    // Precio (Modificado para Promo Flash)
    if ($es_promo_activa) {
        $txtPrecio = "<span class='line-through text-gray-400 text-[10px] md:text-xs font-medium mr-1'>$" . number_format($precio, 0, ',', '.') . "</span><span class='text-[#54A6D8] font-normal tracking-[-0.01em]'>¡Gratis!</span>";
        $precio_class = "flex items-center";
    } else {
        $txtPrecio = ($precio > 0) ? '$' . number_format($precio, 0, ',', '.') : 'Gratis';
        $precio_class = "text-[#222222] font-normal tracking-[-0.01em]";
    }
    
    // --- LECTURA DIRECTA DEL TOTAL DE VENTAS DESDE MYSQL ---
    $ventas_totales = (int)($a['ventas_totales'] ?? 0);
    $ventas_txt = abreviar_conteo($ventas_totales);
    
    $desc = !empty($a['descripcion']) ? mb_substr($a['descripcion'], 0, 120, 'UTF-8') . '...' : ''; 
    $es_nuevo = (!empty($a['fecha_subida']) && strtotime($a['fecha_subida']) > strtotime('-7 days'));

    // [NUBIRA SHIELD] Enmascarar ID para la URL
    $link_hash = function_exists('nubira_encriptar_id') ? nubira_encriptar_id($id) : $id;
    $enlace = "/apunte/$link_hash";

    if (!empty($a['asignatura'])) {
        $cat = $a['asignatura'];
    } elseif (!empty($a['categoria']) && $a['categoria'] !== 'general') {
        $cat = ucfirst($a['categoria']);
    } else {
        $cat = 'General';
    }

    $is_ai = !empty($a['ia_used']) && $a['ia_used'] == 1;

    // Institución (Abreviada)
    $inst_text = '';
    if (!$hide_inst && !empty($a['institucion'])) {
        $inst_clean = $a['institucion'];
        $dicc = [
            'Universidad Andr'=>'UNAB','Universidad Nac'=>'UNAB',
            'Pontificia Universidad Cat'=>'PUC','Universidad de Santiago'=>'USACH',
            'Universidad de Concepci'=>'UdeC','Universidad T'=>'USM',
            'Federico Santa Mar'=>'USM','Adolfo Ib'=>'UAI',
            'Universidad de Chile'=>'U. de Chile',
            'Instituto Profesional'=>'IP','Centro de Formación Técnica'=>'CFT'
        ];
        foreach($dicc as $k=>$v) { if (stripos($inst_clean, $k)!==false) { if(strlen($v)<=6)$inst_clean=$v; else $inst_clean=str_ireplace($k,$v,$inst_clean); break; } }
        if (stripos($inst_clean, 'universidad ') === 0) {
            $inst_clean = 'U. ' . substr($inst_clean, 12);
        }
        $inst_text = htmlspecialchars(mb_strimwidth($inst_clean, 0, 22, '...'));
    }
?>

<a href="<?= $enlace ?>" 
   class="block rounded-xl flex flex-col mb-2 transition-transform duration-300
         hover:-translate-y-1 cursor-pointer w-[100%] sm:w-full sm:max-w-[380px] mx-auto md:max-w-none
         bg-transparent group">

    <div class="card-apunte relative bg-gray-100 rounded-xl overflow-hidden w-full border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] <?= $compacto ? 'aspect-square' : 'aspect-[3/2]' ?>">
        
        <img src="<?= htmlspecialchars($rutaMiniatura) ?>" 
             alt="<?= $titulo ?>" 
             class="w-full h-full object-cover object-top transition-transform duration-500 group-hover:scale-105" 
             loading="lazy"
             onerror="this.src='/img/logo2.webp'">
        
        <div class="absolute top-2.5 left-2.5 flex flex-wrap gap-2 z-10">
            <?php if ($es_promo_activa): ?>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-900 border border-amber-200">
                    Quedan <?= $descargas_restantes ?>
                </span>
            <?php elseif ($es_nuevo): ?>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-medium bg-white/95 backdrop-blur-sm text-[#222222] border border-[#f0f0f0] shadow-[0_1px_2px_rgba(0,0,0,0.08)]">Nuevo</span>
            <?php endif; ?>
        </div>

    </div>

    <div class="pl-1 pr-2 pt-2 pb-2 flex flex-col gap-0 flex-1 text-left">
        
        <?php if ($compacto): ?>
            <div class="flex items-center text-[10px] font-medium mb-0.5 h-[16px]">
                <span class="uppercase tracking-wide text-[#54A6D8] truncate w-full">
                    <?= htmlspecialchars($cat) ?>
                </span>
            </div>

            <h6 class="font-medium text-[13px] md:text-[14px] leading-tight tracking-[-0.01em] text-[#222222] line-clamp-2 h-[34px] overflow-hidden mb-0.5">
                <?= $titulo ?>
            </h6>

            <div class="text-[13px] md:text-sm <?= $precio_class ?> leading-none mb-0">
                <?= $txtPrecio ?>
            </div>

            <div class="flex items-center justify-between mt-0.5">
                <div class="flex items-center gap-1 text-[9px] text-gray-500 font-normal uppercase tracking-[0.01em] truncate max-w-[65%]">
                    <?php if(!empty($inst_text)): ?>
                        <svg class="w-3 h-3 text-gray-300 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"></path></svg>
                        <span class="truncate"><?= $inst_text ?></span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-1 text-[10px] text-gray-400 font-medium">
                    <?php if ($ventas_totales > 0): ?>
                    <?= $ventas_txt ?> descargas
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <h6 class="font-medium text-[15px] leading-snug tracking-[-0.01em] text-[#222222] line-clamp-2 h-[40px] overflow-hidden mb-1">
                <?= $titulo ?>
            </h6>
            
            <p class="text-[13px] text-gray-600 line-clamp-2 h-[36px] overflow-hidden mb-1.5">
                <?= htmlspecialchars($desc) ?>
            </p>

            <div class="text-[14px] <?= $precio_class ?> leading-none mb-0">
                <?= $txtPrecio ?>
            </div>

            <div class="flex items-center justify-between mt-1 pt-0">
                <div class="flex items-center gap-1.5 text-[10px] text-gray-500 font-normal uppercase tracking-[0.01em] truncate max-w-[70%]">
                    <?php if(!empty($inst_text)): ?>
                        <svg class="w-3 h-3 text-gray-300 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"></path></svg>
                        <span class="truncate"><?= $inst_text ?></span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-1 text-[11px] text-gray-500">
                    <?php if ($ventas_totales > 0): ?>
                    <?= $ventas_txt ?> descargas
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>
</a>

<?php
// BANNER ENTRE CARDS
if (!empty($banners_entre)) {
    $idx_global = $offset + $i + 1;
    $b_freq = 4;

    if ($idx_global % $b_freq === 0) {
        $b_idx = (($idx_global / $b_freq) - 1) % count($banners_entre);
        $b = $banners_entre[$b_idx] ?? $banners_entre[0];
?>
    <article class="bg-white p-6 rounded-2xl shadow border border-blue-50 relative mb-6 flex flex-col overflow-hidden <?= $compacto ? 'aspect-square justify-end' : 'h-[260px] md:h-[280px]' ?>">
        <div class="absolute inset-0">
            <img src="/upload/banners/<?= htmlspecialchars($b['imagen']) ?>" class="w-full h-full object-cover" alt="">
        </div>
        <a href="<?= htmlspecialchars($b['enlace'] ?? '#') ?>" target="_blank" class="absolute inset-0 z-10"></a>
        <span class="relative z-10 bg-yellow-400 text-yellow-900 text-xs font-bold px-3 py-1 rounded-full shadow self-start pointer-events-none">
            Publicidad
        </span>
    </article>
<?php
    }
}
?>

<?php endforeach;
$conn->close();
?>
<div class="sentinel" data-next="<?= $pagina + 1 ?>" style="width:100%;height:1px;"></div>