<?php
// app/componentes/render_card.php
// RENDERIZADOR UNIVERSAL DE TARJETAS (IA & AJAX)

// 1. BLINDAJE DE CONEXIÓN
$rutas = [__DIR__.'/../../conexion.php', __DIR__.'/../conexion.php', $_SERVER['DOCUMENT_ROOT'].'/conexion.php'];
foreach($rutas as $r) if(file_exists($r)){ require_once $r; break; }
if(!isset($conn)) exit;

// 2. RECIBIR DATOS
$id = (int)($_GET['id'] ?? 0);
$tipo = $_GET['tipo'] ?? 'servicio'; 

if ($id <= 0) exit;

$es_basico = false; // Por defecto no penaliza a apuntes

// 3. LOGICA DE RENDERIZADO
if ($tipo === 'servicio') {
    // 1. Agregamos precio_oferta, cupos_oferta e is_subvencionado a la consulta
    $sql = "SELECT s.titulo, s.precio, s.precio_oferta, s.cupos_oferta, s.is_subvencionado, s.imagen, s.categoria, s.score_nubira, 
            (SELECT COUNT(*) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as total_votos,
            (SELECT AVG(c.calificacion_comprador) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as rating_promedio
            FROM servicios s WHERE s.id=$id LIMIT 1";
    
    $res = $conn->query($sql);
    if (!$res || $res->num_rows === 0) exit;
    $row = $res->fetch_assoc();

    $titulo = htmlspecialchars($row['titulo']);
    
    // 2. Lógica Núbira 2.0: Detectar si es oferta
    $precio_normal = (int)$row['precio'];
    $precio_oferta = (int)$row['precio_oferta'];
    $cupos_oferta = (int)$row['cupos_oferta'];
    $es_oferta = (isset($row['is_subvencionado']) && $row['is_subvencionado'] == 1 && $cupos_oferta > 0);
    
    // 3. Formateo de precio base (por si no es oferta)
    $precio = ($precio_normal > 0) ? "$".number_format($precio_normal,0,',','.') : "Gratis";
    $precio_cls = ($precio_normal > 0) ? "text-gray-700" : "text-gray-700";
    $tag = "CLASES"; // <--- AJUSTADO A PLURAL
    $tag_color = "text-[#54A6D8]";
    $link = "/detalle-servicio/$id";
    
    // --- LÓGICA DE ESCALAFONES DE STATUS (TIERS NUBIRA 2.0 YOUTUBE EDITION) ---
    $score = (int)($row['score_nubira'] ?? 0);
    $total_v = (int)$row['total_votos'];
    $rating_val = (float)$row['rating_promedio'];
    $nivel_tutor = '';
    $es_basico = ($score < 60);

    if ($score >= 100 && $total_v >= 10 && $rating_val >= 4.7) $nivel_tutor = 'leyenda';
    elseif ($score >= 80 && $total_v >= 3 && $rating_val >= 4.0) $nivel_tutor = 'elite';
    elseif ($score >= 80) $nivel_tutor = 'pro';
    elseif ($score >= 60) $nivel_tutor = 'top';

    $img = 'https://nubira.cl/upload/servicios/default_clases.webp';
    if(!empty($row['imagen'])) {
        $ruta_rel = '/upload/servicios/' . basename($row['imagen']);
        if(file_exists($_SERVER['DOCUMENT_ROOT'] . $ruta_rel)) $img = $ruta_rel . '?v=' . filemtime($_SERVER['DOCUMENT_ROOT'] . $ruta_rel);
    }
    
   
    // --- [NUEVO] CREAR PASTILLA DE ESTRELLAS ---
    if ($total_v > 0) {
        $html_stars = '<div class="flex items-center gap-1 bg-gray-50 px-1.5 py-0.5 rounded border border-gray-100">
            <svg class="w-3 h-3 text-gray-900 pb-[1px]" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
            <span class="text-[10px] font-bold text-gray-800 leading-none">'.number_format($rating_val, 1).'</span>
        </div>';
    } else {
        $html_stars = '';
    }
} elseif ($tipo === 'apunte') {
    $sql = "SELECT titulo, precio, portada, archivo, asignatura FROM apuntes WHERE id=$id LIMIT 1";
    $res = $conn->query($sql);
    if (!$res || $res->num_rows === 0) exit;
    $row = $res->fetch_assoc();

    $titulo = htmlspecialchars($row['titulo']);
    $precio = ($row['precio'] > 0) ? "$".number_format($row['precio'],0,',','.') : "Gratis";
    $precio_cls = ($row['precio'] > 0) ? "text-gray-900" : "text-green-600";
    $tag = "APUNTE";
    $tag_color = "text-orange-500";
    $link = "/ver-apunte?archivo=" . urlencode($row['archivo']);

    $img = '/img/logo2.webp';
    $docRoot = $_SERVER['DOCUMENT_ROOT'];
    $rutas_ap = ["/upload/portadas/".basename($row['portada']??""), "/upload/preview/{$id}.webp", "/upload/preview/{$id}.jpg"];
    foreach($rutas_ap as $r) {
        if(!empty($r) && file_exists($docRoot.$r)) { $img = $r . '?v=' . filemtime($docRoot.$r); break; }
    }
}
?>

<a href="<?= $link ?>"
   class="block flex flex-col cursor-pointer group snap-center w-[150px] md:w-[170px] flex-shrink-0 bg-transparent h-full <?= $es_basico ? 'opacity-90 grayscale-[15%]' : '' ?>">

    <div class="relative w-full aspect-[4/3] bg-gray-100 overflow-hidden rounded-xl border border-gray-200 transition-all">
        <img src="<?= $img ?>"
             class="w-full h-full object-cover transition-transform duration-500 ease-out group-hover:scale-105"
             loading="lazy" decoding="async" width="240" height="180">

        <!-- Badge nivel (izquierda) - solo servicios -->
        <?php if ($tipo === 'servicio' && !empty($nivel_tutor) && empty($es_oferta)): ?>
        <div class="absolute top-2.5 left-2.5 z-10">
            <?php
              $nivel_label = ['leyenda'=>'Leyenda','elite'=>'Élite','pro'=>'Pro','top'=>'Top'][$nivel_tutor] ?? '';
            ?>
            <?php if ($nivel_label): ?>
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-semibold bg-white/95 backdrop-blur-sm text-gray-900 border border-gray-200"><?= $nivel_label ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Badge cupos (derecha) -->
        <?php if (!empty($es_oferta)): ?>
        <div class="absolute top-2.5 right-2.5 z-10">
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-900 border border-amber-200">
                <?= (int)$cupos_oferta ?> <?= (int)$cupos_oferta === 1 ? 'cupo' : 'cupos' ?>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <div class="pt-2.5 flex flex-col flex-1 text-left">
        <h3 class="font-semibold text-[14px] leading-snug text-gray-900 line-clamp-2 mb-1 min-h-[40px]"><?= $titulo ?></h3>

        <div class="text-[14px] mt-auto mb-1.5 leading-none">
            <?php if (!empty($es_oferta)): ?>
                <span class="text-[11px] text-gray-400 line-through font-medium mr-1">$<?= number_format($precio_normal, 0, ',', '.') ?></span>
                <span class="text-gray-700 font-semibold tracking-tight">$<?= number_format($precio_oferta, 0, ',', '.') ?></span>
            <?php else: ?>
                <span class="text-gray-700 font-semibold tracking-tight"><?= $precio ?></span>
            <?php endif; ?>
        </div>

        <div class="flex items-center justify-between">
            <div class="flex items-center gap-1.5 text-[10px] text-gray-400 font-bold uppercase tracking-wide truncate max-w-[65%]">
                <span class="truncate"><?= $tag ?></span>
            </div>
            <div class="shrink-0 flex items-center gap-1">
                <?php if ($tipo === 'servicio') echo $html_stars; ?>
            </div>
        </div>
    </div>
</a>