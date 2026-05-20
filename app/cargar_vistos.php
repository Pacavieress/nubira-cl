<?php
/**
 * PROCESO: CARGAR VISTOS RECIENTEMENTE
 * ESTADO: PRODUCCIÓN (Diseño Nubira 2.0 Flat - YouTube Edition)
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

// 1. CONEXIÓN
$base_path = __DIR__;
if (file_exists(__DIR__ . '/conexion.php')) $base_path = __DIR__;
elseif (file_exists(__DIR__ . '/../conexion.php')) $base_path = __DIR__ . '/..';

if (!file_exists($base_path . '/conexion.php')) exit;
require_once $base_path . '/conexion.php';

if (!isset($_SESSION['usuario_id'])) exit;
$usuario_id = (int)$_SESSION['usuario_id'];

// 2. CONSULTA SQL (LOGS)
$sql_log = "SELECT entidad_tipo, entidad_id, MAX(fecha) as fecha_reciente
            FROM nubira_behavior_logs 
            WHERE usuario_id = ? 
            AND tipo_evento = 'view' 
            AND entidad_tipo IN ('servicio', 'apunte')
            GROUP BY entidad_tipo, entidad_id
            ORDER BY fecha_reciente DESC 
            LIMIT 10";

$stmt = $conn->prepare($sql_log);
if (!$stmt) exit;

$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$res_log = $stmt->get_result();

if ($res_log->num_rows === 0) exit;

// 3. PROCESAMIENTO
$items_ordenados = []; 
$ids_servicios = [];
$ids_apuntes = [];

while ($row = $res_log->fetch_assoc()) {
    $id = (int)$row['entidad_id'];
    $tipo_bd = strtolower(trim($row['entidad_tipo']));
    $tipo = ($tipo_bd === 'servicio') ? 's' : 'a';
    
    $items_ordenados[] = ['tipo' => $tipo, 'id' => $id];
    
    if ($tipo === 's') $ids_servicios[] = $id;
    else $ids_apuntes[] = $id;
}
$stmt->close();

// 4. RECUPERAR DATOS REALES (Ahora con datos del Tutor y Gamificación)
$data_servicios = [];
$data_apuntes = [];

if (!empty($ids_servicios)) {
    $ids_in = implode(',', $ids_servicios);
    $res = $conn->query("
      SELECT s.id, s.titulo, s.precio, s.imagen, s.score_nubira, s.fecha_publicacion,
               s.is_subvencionado, s.precio_oferta, s.cupos_oferta,
               a.nombre as tutor_nombre, a.foto_perfil,
               (SELECT COUNT(*) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as total_votos,
               (SELECT AVG(c.calificacion_comprador) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as rating_promedio
        FROM servicios s 
        LEFT JOIN alumnos a ON s.alumno_id = a.id
        WHERE s.id IN ($ids_in)
    ");
    if($res) while ($r = $res->fetch_assoc()) $data_servicios[$r['id']] = $r;
}

if (!empty($ids_apuntes)) {
    $ids_in = implode(',', $ids_apuntes);
    $res = $conn->query("
        SELECT ap.id, ap.titulo, ap.precio, ap.portada, ap.preview, ap.archivo, ap.fecha_subida,
               a.nombre as tutor_nombre, a.foto_perfil
        FROM apuntes ap
        LEFT JOIN alumnos a ON ap.id_alumno = a.id
        WHERE ap.id IN ($ids_in)
    ");
    if($res) while ($r = $res->fetch_assoc()) $data_apuntes[$r['id']] = $r;
}

$hoy = new DateTime();

// 5. RENDERIZADO NUBIRA 2.0 FLAT
foreach ($items_ordenados as $item):
    $tipo = $item['tipo'];
    $id = $item['id'];
    $row = null;
    
    // [FIX NUBIRA 2.0] Destruir las variables del ciclo anterior para evitar que 
    // la oferta o los badges de un Servicio se "fuguen" hacia un Apunte.
    $es_oferta = false;
    $precio_html = null;
    $nivel_tutor = null;

    if ($tipo === 's' && isset($data_servicios[$id])) {
        $row = $data_servicios[$id];
        
        // [NUBIRA 2.0] Usar thumb 240px (cae al main si el thumb no existe)
        $img = 'https://nubira.cl/upload/servicios/default_clases.webp';
        if (!empty($row['imagen'])) {
            $base = pathinfo(basename($row['imagen']), PATHINFO_FILENAME);
            $thumb_check = $_SERVER['DOCUMENT_ROOT'] . '/upload/servicios/' . $base . '_thumb.webp';
            $main_check  = $_SERVER['DOCUMENT_ROOT'] . '/upload/servicios/' . basename($row['imagen']);
            if (file_exists($thumb_check)) {
                $img = '/upload/servicios/' . $base . '_thumb.webp';
            } elseif (file_exists($main_check)) {
                $img = '/upload/servicios/' . basename($row['imagen']);
            }
        }
        
// --- LÓGICA DE PRECIOS Y OFERTAS (ESTÁNDAR CARD HORIZONTAL NUBIRA 2.0) ---
        $es_oferta = (isset($row['is_subvencionado']) && $row['is_subvencionado'] == 1 && (int)$row['cupos_oferta'] > 0);
        $precio_normal = $row['precio'] ?? 0;

        if ($es_oferta) {
            $precio_html = '<div class="flex flex-col justify-end mt-1"><span class="text-[9px] md:text-[10px] text-gray-400 line-through font-medium block leading-none mb-0.5">Normal $' . number_format($precio_normal, 0, ',', '.') . '</span><span class="text-base md:text-lg text-gray-700 font-semibold tracking-tight leading-none">$' . number_format($row['precio_oferta'], 0, ',', '.') . '</span></div>';
        } else {
            if (is_numeric($precio_normal) && $precio_normal > 0) { 
                $precio_html = '<div class="text-[13px] md:text-[14px] text-gray-700 font-semibold tracking-tight mt-1">$' . number_format($precio_normal, 0, ',', '.') . '</div>';
            } else { 
                $precio_html = '<div class="text-[13px] md:text-[14px] text-gray-700 font-semibold tracking-tight mt-1">Gratis</div>';
            }
        }
        
        $link = "/detalle-servicio/" . (function_exists('nubira_encriptar_id') ? nubira_encriptar_id($row['id']) : $row['id']);
        $titulo = $row['titulo'];

        // Lógica Tiers YouTube Edition
        $score = (int)($row['score_nubira'] ?? 0);
        $total_v = (int)($row['total_votos'] ?? 0);
        $rating_val = (float)($row['rating_promedio'] ?? 0);
        $nivel_tutor = '';
        $es_basico = ($score < 60);

        if ($score >= 100 && $total_v >= 10 && $rating_val >= 4.7) $nivel_tutor = 'leyenda';
        elseif ($score >= 80 && $total_v >= 3 && $rating_val >= 4.0) $nivel_tutor = 'elite';
        elseif ($score >= 80) $nivel_tutor = 'pro';
        elseif ($score >= 60) $nivel_tutor = 'top';

      
     // AGREGA ESTO:
        $tag = "CLASES";
        $tag_color = "text-[#54A6D8]";

        // --- [NUEVO] CREAR PASTILLA DE ESTRELLAS ---
        if ($total_v > 0) {
            $html_stars = '<div class="flex items-center gap-1 bg-gray-50 px-1.5 py-0.5 rounded border border-gray-100">
                <svg class="w-3 h-3 text-gray-900 pb-[1px]" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
                <span class="text-[10px] font-bold text-gray-800 leading-none">'.number_format($rating_val, 1).'</span>
            </div>';
        } else {
            $html_stars = '';
        }

    } elseif ($tipo === 'a' && isset($data_apuntes[$id])) {
        // ... (código existente del apunte)
        $html_stars = ''; // Los apuntes no llevan estrellas aquí
        $row = $data_apuntes[$id];
        
        $precio_val = $row['precio'] ?? 0;
        if (is_numeric($precio_val) && $precio_val > 0) {
            $precio = "$" . number_format($precio_val, 0, ',', '.');
            $precio_class = "text-gray-700";
        } else {
            $precio = "Gratis"; 
            $precio_class = "text-gray-700";
        }

        $img = 'https://nubira.cl/upload/servicios/default_clases.webp';
        $root = $_SERVER['DOCUMENT_ROOT'];
        if (!empty($row['portada']) && file_exists($root . '/upload/portadas/' . basename($row['portada']))) {
            $img = '/upload/portadas/' . basename($row['portada']);
        } elseif (!empty($row['preview']) && file_exists($root . '/upload/preview/' . basename($row['preview']))) {
            $img = '/upload/preview/' . basename($row['preview']);
        } else {
            $exts = ['webp', 'jpg', 'png', 'jpeg'];
            foreach ($exts as $ext) {
                if (file_exists($root . "/upload/preview/{$id}.{$ext}")) { 
                    $img = "/upload/preview/{$id}.{$ext}"; 
                    break; 
                }
            }
        }
        
        $link = "/apunte/" . (function_exists('nubira_encriptar_id') ? nubira_encriptar_id($row['id']) : $row['id']); 
        $titulo = $row['titulo'];
        $nivel_tutor = 'apunte'; // Bandera especial para pintar el PDF
        $es_basico = false;
        
       
        // AGREGA ESTO:
        $tag = "APUNTE";
        $tag_color = "text-orange-500";

    } else {
        continue; 
    }

    // Procesar nombre y foto del tutor
    $nombre_completo = !empty($row['tutor_nombre']) ? $row['tutor_nombre'] : 'Estudiante';
    $partes_nombre = array_values(array_filter(explode(' ', trim((string)$nombre_completo))));
    $tutor_nombre = "Estudiante";
    if (!empty($partes_nombre[0])) {
        $tutor_nombre = ucwords(strtolower($partes_nombre[0]));
        if (count($partes_nombre) >= 2) {
            $tutor_nombre .= ' ' . strtoupper(substr($partes_nombre[count($partes_nombre)-1], 0, 1)) . '.';
        }
    }
    $foto_tutor = !empty($row['foto_perfil']) ? '/app/perfil/fotos/' . $row['foto_perfil'] : "https://ui-avatars.com/api/?name=".urlencode($tutor_nombre)."&background=f1f5f9&color=64748b";

    // Versionamiento de imagen para cache
    if (strpos($img, '/upload/') !== false) {
        $ruta_fisica_img = $_SERVER['DOCUMENT_ROOT'] . explode('?', $img)[0];
        if (file_exists($ruta_fisica_img)) {
            $img .= '?v=' . filemtime($ruta_fisica_img);
        }
    }
    ?>

<a href="<?= $link ?>" 
       class="group flex-shrink-0 w-[240px] md:w-[320px] h-[104px] bg-white rounded-2xl flex gap-3 shadow-sm hover:shadow-md hover:scale-[1.01] transition-all snap-start overflow-hidden relative items-center p-2 <?= isset($es_oferta) && $es_oferta ? 'border border-orange-300' : 'border border-gray-100' ?> <?= $es_basico ? 'opacity-90 grayscale-[15%]' : '' ?>">
        
        <div class="w-[84px] h-[84px] md:w-[88px] md:h-[88px] rounded-xl bg-gray-100 flex-shrink-0 overflow-hidden relative self-center">
            <img src="<?= htmlspecialchars($img) ?>" 
                 class="w-full h-full object-cover" 
                 loading="lazy" 
                 onerror="this.src='/img/logo2.webp'">
            
          
        </div>
        <!-- BADGE FLOTANTE ARRIBA A LA DERECHA (Diseño Airbnb) -->
        <div class="absolute top-2.5 right-2.5 flex z-10">
            <?php if (isset($es_oferta) && $es_oferta): ?>
              <span class="inline-flex items-center w-fit px-1.5 py-0.5 rounded-full text-[9px] font-extrabold uppercase tracking-wider bg-gradient-to-tr from-orange-400 to-orange-500 text-white shadow-sm border border-orange-300/50 leading-none">
    🔥 <?= (int)$row['cupos_oferta'] ?> CUPOS
</span>
            <?php elseif ($nivel_tutor === 'leyenda'): ?>
                <span class="bg-gradient-to-tr from-red-700 to-rose-500 text-white text-[9px] font-extrabold uppercase tracking-wider px-2 py-0.5 rounded-full flex items-center gap-1 shadow-sm border border-red-400/30">
                    <i class="fa-solid fa-gem text-[7px]"></i> 
                </span>
            <?php elseif ($nivel_tutor === 'elite'): ?>
                <span class="bg-gradient-to-tr from-cyan-400 to-blue-500 text-white text-[9px] font-extrabold uppercase tracking-wider px-2 py-0.5 rounded-full border border-cyan-300/30 shadow-sm flex items-center gap-1">
                    <i class="fa-solid fa-diamond text-[7px]"></i> 
                </span>
            <?php elseif ($nivel_tutor === 'pro' || $nivel_tutor === 'top'): ?>
                <span class="bg-slate-100 text-slate-800 text-[9px] font-extrabold uppercase tracking-wider px-2 py-0.5 rounded-full border border-gray-200 shadow-sm flex items-center gap-1">
                    <i class="fa-solid fa-star text-[7px]"></i> 
                </span>
            <?php endif; ?>
        </div>

      <div class="flex flex-col justify-center min-w-0 w-full h-full py-0.5">
            <p class="text-[9px] font-bold <?= $tag_color ?> uppercase tracking-wider mb-0.5"><?= $tag ?></p>
            
            <h4 class="text-[13px] md:text-[14px] font-semibold text-gray-900 leading-tight line-clamp-2 mb-1 pr-16">
                <?= htmlspecialchars($titulo) ?>
            </h4>
            
            <div class="mt-auto flex items-end justify-between w-full">
                <!-- Precio Dinámico (Normal o Apilado) -->
                <div>
                    <?= isset($precio_html) ? $precio_html : '<span class="text-[13px] font-bold ' . ($precio_class ?? '') . '">' . ($precio ?? '') . '</span>' ?>
                </div>
                
                <div class="shrink-0 flex items-center mb-0.5">
                    <?= $html_stars ?>
                </div>
            </div>
        </div>
    </a>
<?php endforeach; ?>