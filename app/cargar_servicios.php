<?php
session_start();

// === BLOQUE ANTI-CACHE ===
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); 

require_once __DIR__ . '/helpers/usuario_helper.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/helpers/portada_helper.php';
require_once __DIR__ . '/helpers/imagen_servicio.php'; // [BANCO] resolver unificado de portada
require_once __DIR__ . '/helpers/ofertas.php';
require_once __DIR__ . '/helpers/institucion.php'; // abreviar_institucion() / institucion_tutor()
require_once __DIR__ . '/helpers/seo.php';

// [NUBIRA 2.0] Cargar iconos oficiales de la plataforma
$rutas_iconos = [__DIR__.'/iconos.php', __DIR__.'/../iconos.php', $_SERVER['DOCUMENT_ROOT'].'/app/iconos.php', $_SERVER['DOCUMENT_ROOT'].'/iconos.php'];
foreach($rutas_iconos as $ri) {
    if(file_exists($ri)){ 
        require_once $ri; 
        break; 
    }
}

// [NUBIRA SHIELD] Cargar enmascarador de URLs
$rutas_shield = [__DIR__ . '/seguridad_url.php', __DIR__ . '/../seguridad_url.php', $_SERVER['DOCUMENT_ROOT'] . '/app/seguridad_url.php'];
foreach ($rutas_shield as $rs) {
    if (file_exists($rs)) {
        require_once $rs;
        break;
    }
}

// === SEGURIDAD LAZY REGISTRATION ===
$is_guest = !isset($_SESSION['usuario_id']);
$rol    = $_SESSION['rol'] ?? 'alumno';
$pagina = max(1, intval($_GET['pagina'] ?? 1));

date_default_timezone_set('America/Santiago');

// === CONFIGURACIÓN ===
$limite = 12; 
$offset = ($pagina - 1) * $limite;
$hide_inst = !empty($_GET['hide_inst']); 
$compacto  = !empty($_GET['compacto']);  

// === FILTROS ===
// === FILTROS ===
// [NUBIRA 2.0] Exigimos que tanto el servicio como el autor estén visibles (Soft Delete Shield)
$filtros = [
    "s.estado = 'aprobado'",
    "s.visible = 1",
    "COALESCE(a.visible, 1) = 1"
];
$params  = [];
$tipos   = "";

$institucion_param = trim($_GET['institucion'] ?? '');
$ver_todas         = !empty($_GET['ver_todas']);

if ($rol !== 'admin' && !$ver_todas && $institucion_param !== '') {
    $filtros[] = "s.institucion = ?";
    $params[]  = $institucion_param;
    $tipos     .= "s";
}

$campos = ['categoria', 'modalidad', 'ubicacion', 'area'];
foreach ($campos as $f) {
    if (!isset($_GET[$f]) || $_GET[$f] === '') continue;
    $valor = trim($_GET[$f]);
    if ($f === 'ubicacion') {
        $filtros[] = "s.$f LIKE ?";
        $params[]  = "%$valor%";
        $tipos     .= "s";
    } else {
        $filtros[] = "s.$f = ?";
        $params[]  = $valor;
        $tipos     .= "s";
    }
}

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $filtros[] = "(s.titulo LIKE ? OR s.descripcion LIKE ? OR s.categoria LIKE ?)";
    $busqueda = "%$q%";
    $params[] = $busqueda; $params[] = $busqueda; $params[] = $busqueda;
    $tipos .= "sss";
}

// === CONSULTA MAESTRA (GAMIFICACIÓN ENTERPRISE) ===
$modo = $_GET['modo'] ?? 'default';
$hour = (int)date('G');
$bucket = (int)floor($hour / 4);
$seed = date('Y-m-d') . "|$bucket";

// 1. SELECT PRINCIPAL (Sin GROUP BY innecesario)
$select_sql = "SELECT s.*, 
                      COALESCE(dp.institucion, a.institucion) as institucion_maestra,
                      (SELECT COUNT(*) FROM valoraciones v WHERE v.servicio_id = s.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') as total_votos,
                      (SELECT AVG(v.calificacion) FROM valoraciones v WHERE v.servicio_id = s.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') as rating_promedio,
                      a.foto_perfil,
                      a.nombre as nombre_tutor,
                      bi.archivo as banco_archivo
               FROM servicios s
               LEFT JOIN alumnos a ON s.alumno_id = a.id
               LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
               LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id";

// 2. WHERE CONDICIONALES
$where_sql = "WHERE " . implode(" AND ", $filtros);

// 3. LA GUERRA DEL DESEMPATE LEE s.score_nubira
switch ($modo) {
    case 'top':
        // FIX: Se agregó OFFSET para que el scroll infinito no cicle los mismos resultados
        $sql = "$select_sql $where_sql 
                AND (s.visitas > 0 OR s.contrataciones > 0) 
                ORDER BY s.score_nubira DESC, total_votos DESC, rating_promedio DESC, (s.visitas + (s.contrataciones * 10)) DESC 
                LIMIT ? OFFSET ?";
        $params[] = $limite; $params[] = $offset; $tipos .= "ii";
        break;
        
    case 'recientes':
        $sql = "$select_sql $where_sql 
                ORDER BY s.score_nubira DESC, total_votos DESC, rating_promedio DESC, s.fecha_publicacion DESC 
                LIMIT ? OFFSET ?";
        $params[] = $limite; $params[] = $offset; $tipos .= "ii";
        break;
        
    default:
        // Mezcla inteligente sembrada cada 4 horas
        $sql = "$select_sql $where_sql 
                ORDER BY s.score_nubira DESC, total_votos DESC, rating_promedio DESC, SHA2(CONCAT(CAST(s.id AS CHAR), '|', ?), 256) ASC 
                LIMIT ? OFFSET ?";
        $params[] = $seed; $params[] = $limite; $params[] = $offset; $tipos .= "sii";
        break;
}

$stmt = $conn->prepare($sql);
if (!$stmt) exit("Error SQL: " . $conn->error);
if (!empty($tipos)) {
    $stmt->bind_param($tipos, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$servicios = [];
while ($row = $res->fetch_assoc()) {
    $servicios[] = $row;
}
$stmt->close();

if (empty($servicios)) {
    if ($pagina == 1) echo '<div class="col-span-full flex flex-col items-center justify-center text-center py-12 text-gray-400"><i class="fa-solid fa-inbox text-4xl mb-3 opacity-50"></i><p class="text-sm">No encontramos servicios con estos filtros.</p></div>';
    exit;
}

// === BANNERS ===
$inst_banner = ($rol === 'admin') ? ($institucion_param ?: '') : ($is_guest ? '' : obtenerInstitucionUsuario());

if ($is_guest) {
    $stmtB = $conn->prepare("SELECT * FROM banners WHERE activo = 1 AND (institucion IS NULL OR institucion = '') AND ubicacion = 'servicios' ORDER BY orden ASC");
} else {
    $stmtB = $conn->prepare("SELECT * FROM banners WHERE activo = 1 AND (institucion = ? OR institucion IS NULL) AND ubicacion = 'servicios' ORDER BY orden ASC");
    $stmtB->bind_param("s", $inst_banner);
}

$stmtB->execute();
$resB = $stmtB->get_result();
$banners_servicios = [];
while ($b = $resB->fetch_assoc()) $banners_servicios[] = $b;
$stmtB->close();

/* ==========================================================================
   RENDERIZADO (DISEÑO ULTRA LIMPIO Y COMPACTO CON ESCALAFONES DE STATUS)
   ========================================================================== */
$hoy = new DateTime();
$frecuencia_banner = 4;

foreach ($servicios as $i => $row):
    
    $link_hash = url_servicio((int)$row['id'], $row['slug'] ?? null);

    // 1. DATA PREP PORTADA (banco → legacy → placeholder, vía helper unificado; ignora imagen_estado)
    $portada_url = url_portada($row);

    // [OVERLAY NUBIRA] categoría sobre la portada
    $categoria_overlay = $row['categoria'] ?? 'Otros';
    $prefijo_overlay = in_array($categoria_overlay, ['Otros','Asesoría']) ? '' : 'Clase de';
    $nombre_categoria_overlay = ($categoria_overlay === 'Otros') ? 'Clase' : $categoria_overlay;

    $fecha_pub = !empty($row['fecha_publicacion']) ? new DateTime($row['fecha_publicacion']) : $hoy;
    $es_nuevo  = ($hoy->diff($fecha_pub)->days <= 14); 
    
    // Rating
    $rating_val = isset($row['rating_promedio']) ? (float)$row['rating_promedio'] : 0;
    $total_v    = isset($row['total_votos']) ? (int)$row['total_votos'] : 0;

   // --- LÓGICA DE PRECIOS Y OFERTAS (NUBIRA 2.0) ---
    $precio_val = $row['precio'] ?? 0;
    $es_oferta = oferta_vigente($row);
    $pct_descuento = ($es_oferta && (int)$precio_val > 0) ? round(((int)$precio_val - (int)$row['precio_oferta']) / (int)$precio_val * 100) : 0;
    $precio_html = "";

   if ($es_oferta) {
        $precio_oferta = (int)$row['precio_oferta'];
        $precio_html = '<div class="flex items-baseline gap-1.5 mb-0.5"><span class="text-[11px] text-gray-400 line-through font-medium leading-none">$' . number_format($precio_val, 0, ',', '.') . '</span><span class="text-[14px] text-gray-700 font-semibold tracking-tight leading-none">$' . number_format($precio_oferta, 0, ',', '.') . '</span>' . ($pct_descuento > 0 ? '<span class="bg-green-600 text-white text-[9px] font-semibold px-1 py-px rounded ml-1.5 leading-none relative -top-0.5">-' . $pct_descuento . '%</span>' : '') . '</div>';
    
    } else {
        if (is_numeric($precio_val) && $precio_val > 0) {
            $precio = "$" . number_format($precio_val, 0, ',', '.'); 
            $precio_class = "text-gray-700 font-semibold tracking-tight";
            $precio_html = '<div class="text-[13px] ' . $precio_class . ' leading-none mb-0.5">' . $precio . '</div>';
        } else {
            $precio = "Gratis"; 
            $precio_class = "text-gray-900 font-bold tracking-tight";
            $precio_html = '<div class="text-[13px] ' . $precio_class . ' leading-none mb-0.5">' . $precio . '</div>';
        }
    }
    
    // --- LÓGICA DE ESCALAFONES DE STATUS (TIERS NUBIRA 2.0) ---
    $score = (int)($row['score_nubira'] ?? 0);
    $nivel_tutor = '';
    $es_basico = ($score < 60);

    if ($score >= 100 && $total_v >= 10 && $rating_val >= 4.7) {
        $nivel_tutor = 'leyenda';
    } elseif ($score >= 80 && $total_v >= 3 && $rating_val >= 4.0) {
        $nivel_tutor = 'elite';
    } elseif ($score >= 80) {
        $nivel_tutor = 'pro';
    } elseif ($score >= 60) {
        $nivel_tutor = 'top';
    }
    
    // --- LÓGICA DE AVATAR Y TUTOR ---
    $nombre_completo = !empty($row['nombre_tutor']) ? $row['nombre_tutor'] : 'Profesor';
    $partes_nombre = array_values(array_filter(explode(' ', trim((string)$nombre_completo))));
    $tutor_nombre = "Profesor";
    if (!empty($partes_nombre[0])) {
        $tutor_nombre = ucwords(strtolower($partes_nombre[0]));
        if (count($partes_nombre) >= 2) {
            $tutor_nombre .= ' ' . strtoupper(substr($partes_nombre[count($partes_nombre)-1], 0, 1)) . '.';
        }
    }
    $foto_tutor = !empty($row['foto_perfil']) ? '/app/perfil/fotos/' . $row['foto_perfil'] : "https://ui-avatars.com/api/?name=".urlencode($tutor_nombre)."&background=f1f5f9&color=64748b&size=128";
    
    // Modalidad Icono
    $mod = ucfirst($row['modalidad'] ?? '');
    if (stripos($mod, 'online') !== false) $icon_mod = '<i class="fa-solid fa-wifi text-[10px]"></i>';
    elseif (stripos($mod, 'presencial') !== false) $icon_mod = '<i class="fa-solid fa-user-group text-[10px]"></i>';
    else $icon_mod = '<i class="fa-solid fa-laptop text-[10px]"></i>';

    // --- HTML RATING (Derecha) ---
    $html_stars = '';
    if ($total_v > 0) {
        $html_stars = '<div class="flex items-center gap-1">
            <svg class="w-3 h-3 text-gray-700" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
            <span class="text-[11px] text-gray-700 font-semibold leading-none">'.number_format($rating_val, 1).'</span>
        </div>';
    }

    // --- HTML INSTITUCIÓN (Izquierda) ---
    $inst_text = $hide_inst ? '' : institucion_tutor($row['institucion_maestra'] ?? ($row['institucion'] ?? ''));
?>

<a href="<?= $link_hash ?>"
   class="block rounded-xl flex flex-col transition-transform duration-300 hover:-translate-y-1 cursor-pointer w-[100%] sm:w-full sm:max-w-[380px] mx-auto md:max-w-none bg-transparent group h-full <?php echo $es_basico ? 'opacity-90 grayscale-[15%]' : ''; ?>">

  <div class="card-apunte relative overflow-hidden w-full <?= $compacto ? 'aspect-square rounded-xl' : 'aspect-[3/2] rounded-xl' ?> bg-gray-100 border border-gray-200">
    <img src="<?= htmlspecialchars($portada_url) ?>"
         alt="<?= htmlspecialchars($row['titulo']) ?>"
       class="w-full h-full object-cover transition-transform duration-500 ease-out group-hover:scale-105"
         loading="lazy"
         onerror="this.src='/upload/preview/default_file.webp'">
    
  <?php
  $ov_prefijo   = $prefijo_overlay;
  $ov_categoria = $nombre_categoria_overlay;
  $ov_foto      = $foto_tutor;
  $ov_nombre    = $tutor_nombre;
  $ov_size      = 'lg';
  include __DIR__ . '/componentes/overlay_card_servicio.php';
  ?>

  <!-- Badge derecha: tier (oculto en ofertas; ahí manda cupos) -->
  <?php if (!$es_oferta): ?>
  <div class="absolute top-2.5 right-2.5 z-10">
    <?php if ($nivel_tutor === 'leyenda'): ?>
        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[9px] font-semibold bg-white/95 backdrop-blur-sm text-gray-900 border border-gray-200">Leyenda</span>
    <?php elseif ($nivel_tutor === 'elite'): ?>
        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[9px] font-semibold bg-white/95 backdrop-blur-sm text-gray-900 border border-gray-200">Élite</span>
    <?php elseif ($nivel_tutor === 'pro'): ?>
        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[9px] font-semibold bg-white/95 backdrop-blur-sm text-gray-900 border border-gray-200">Pro</span>
    <?php elseif ($nivel_tutor === 'top'): ?>
        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[9px] font-semibold bg-white/95 backdrop-blur-sm text-gray-900 border border-gray-200">Top</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Badge cupos (derecha) -->
  <?php if ($es_oferta): ?>
  <div class="absolute top-1 right-1 z-10">
    <span class="inline-flex items-center px-1 py-0 md:px-2 md:py-0.5 rounded-full text-[8px] md:text-[10px] font-semibold bg-amber-100 text-amber-900 border border-amber-200">
        <?= (int)$row['cupos_oferta'] ?> <?= (int)$row['cupos_oferta'] === 1 ? 'cupo' : 'cupos' ?>
    </span>
  </div>
  <?php endif; ?>
  </div>

 <div class="pl-1 pr-1 pt-3 pb-1 flex flex-col flex-1 text-left min-h-[90px]">
      
      <?php if ($compacto): ?>
      <h6 class="font-bold text-[14px] leading-[1.3] text-gray-900 line-clamp-2 h-[36px] overflow-hidden">
              <?= htmlspecialchars($row['titulo']) ?>
          </h6>
          
          <?= $precio_html ?>

<div class="flex items-center justify-between">
              <div class="flex items-center gap-1 text-[9px] text-gray-400 font-bold uppercase tracking-wide truncate max-w-[65%]">
                <?php if(!empty($inst_text)): ?>
                    <span class="truncate"><?= $inst_text ?></span>
                <?php endif; ?>
              </div>
              <?php if ($html_stars): ?><div class="shrink-0"><?= $html_stars ?></div><?php endif; ?>
          </div>

      <?php else: ?>
          <h6 class="font-bold text-[14px] leading-[1.3] text-gray-900 line-clamp-2 h-[36px] overflow-hidden mb-1">
              <?= htmlspecialchars($row['titulo']) ?>
          </h6>

          <?= $precio_html ?>

<div class="flex items-center justify-between pt-1">
              <div class="flex items-center gap-1.5 text-[10px] text-gray-400 font-bold uppercase tracking-wide truncate max-w-[70%]">
                <?php if(!empty($inst_text)): ?>
                    <span class="truncate"><?= $inst_text ?></span>
                <?php endif; ?>
              </div>
              <?php if ($html_stars): ?><div class="shrink-0"><?= $html_stars ?></div><?php endif; ?>
          </div>
      <?php endif; ?>
  </div>
</a>
<?php
if (!empty($banners_servicios) && (($i + 1) % $frecuencia_banner === 0)):
    $banner_idx = (($i + 1) / $frecuencia_banner) % count($banners_servicios);
    $b = $banners_servicios[$banner_idx];
    
    // Banner Bug Fix
    $ruta_b_rel = '/upload/banners/' . basename($b['imagen']);
    $ruta_b_fis = $_SERVER['DOCUMENT_ROOT'] . $ruta_b_rel;
    $src_banner = $ruta_b_rel;
    
    if (file_exists($ruta_b_fis)) {
        clearstatcache(true, $ruta_b_fis);
        $src_banner .= '?v=' . filemtime($ruta_b_fis);
    }
?>
<article class="bg-white p-6 rounded-2xl shadow border border-blue-50 relative mb-6 flex flex-col overflow-hidden <?= $compacto ? 'aspect-square' : 'h-[320px]' ?>">
    <div class="absolute inset-0">
        <img src="<?= htmlspecialchars($src_banner) ?>" class="w-full h-full object-cover" alt="Publicidad">
    </div>
    <a href="<?= htmlspecialchars($b['enlace'] ?? '#') ?>" target="_blank" class="absolute inset-0 z-10"></a>
</article>
<?php endif; endforeach; ?>

<div class="sentinel absolute bottom-0 left-0 w-full h-1 -z-10 opacity-0 pointer-events-none" data-next="<?= $pagina + 1 ?>"></div>