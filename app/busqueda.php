<?php

/**
 * VISTA: RESULTADOS DE BÚSQUEDA V15 (NUBIRA 2.0 — CARDS ESPEJO DE cargar_servicios.php)
 * FIX: Cards de servicios idénticas a vitrina. Tiers completos (leyenda/élite/pro/top/nuevo),
 *      ofertas con tachado, footer compacto, aspect-[3/2].
 */
session_start();
// [NUBIRA 2.0] Captura de búsqueda con sanitización reforzada (defensa contra SQL injection)
if (isset($_GET['q'])) {
    $termino_raw = trim($_GET['q']);
    if (strlen($termino_raw) > 0 && strlen($termino_raw) <= 100) {
        $termino_limpio_q = strip_tags($termino_raw);
        $termino_limpio_q = preg_replace('/[^\p{L}\p{N}\s\-\.\,]/u', '', $termino_limpio_q);
        $termino_limpio_q = trim(preg_replace('/\s+/', ' ', $termino_limpio_q));
        if (!empty($termino_limpio_q)) {
            $_SESSION['ultimo_termino_buscado'] = $termino_limpio_q;
        }
    }
}
session_write_close();

error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- FUNCIONES HELPER NUBIRA 2.0 ---
if (!function_exists('resaltarTermino')) {
    function resaltarTermino($texto, $busqueda) {
        if (empty(trim($busqueda))) return htmlspecialchars($texto);
        $termino = preg_quote(trim($busqueda), '/');
        return preg_replace('/(' . $termino . ')/iu', '<span class="bg-blue-50 text-[#54A6D8] font-black px-0.5 rounded">$1</span>', htmlspecialchars($texto));
    }
}

require_once __DIR__ . '/helpers/institucion.php';

if (!function_exists('render_rating_html')) {
    function render_rating_html(float $rating_val, int $total_votos, string $fallback_label = 'Nuevo'): string {
        if ($total_votos > 0) {
            return '<div class="flex items-center gap-1 bg-gray-50 px-1.5 py-0.5 rounded border border-gray-100">
                        <svg class="w-3 h-3 text-gray-900 pb-[1px]" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
                        <span class="text-[10px] font-bold text-gray-800 leading-none">'.number_format($rating_val, 1).'</span>
                    </div>';
        }
        return '';
    }
}

// [NUBIRA 2.0] Cache estático de filemtime()
if (!function_exists('resolver_portada_busqueda')) {
    function resolver_portada_busqueda(string $tipo, $row): string {
        static $cache = [];
        $default = 'https://nubira.cl/upload/servicios/default_clases.webp';
        $docRoot = $_SERVER['DOCUMENT_ROOT'];
        
        if ($tipo === 'servicio') {
            return url_portada($row); // [BANCO] banco → legacy → placeholder
        }
        
        if ($tipo === 'apunte') {
            $id = (int)$row['id'];
            $key = 'ap_' . $id;
            if (isset($cache[$key])) return $cache[$key];
            
            $port = $row['portada'] ?? '';
            if (!empty($port)) {
                $ruta_rel = "/upload/portadas/" . basename($port);
                $ruta_fis = $docRoot . $ruta_rel;
                if (file_exists($ruta_fis)) {
                    return $cache[$key] = $ruta_rel . "?v=" . filemtime($ruta_fis);
                }
            }
            $ruta_rel = "/upload/preview/{$id}.webp";
            $ruta_fis = $docRoot . $ruta_rel;
            if (file_exists($ruta_fis)) {
                return $cache[$key] = $ruta_rel . "?v=" . filemtime($ruta_fis);
            }
            return $cache[$key] = $default;
        }
        
        return $default;
    }
}

// 1. CONEXIÓN
$rutas = [__DIR__.'/conexion.php', __DIR__.'/../conexion.php', $_SERVER['DOCUMENT_ROOT'].'/app/conexion.php'];
foreach($rutas as $r) if(file_exists($r)){ require_once $r; break; }
if(!isset($conn)) die("Error de conexión.");

require_once __DIR__ . '/iconos.php';
require_once __DIR__ . '/helpers/ofertas.php';
require_once __DIR__ . '/helpers/imagen_servicio.php'; // [BANCO] resolver unificado de servicios

// [NUBIRA SHIELD] Cargar enmascarador de URLs
$rutas_shield = [__DIR__ . '/seguridad_url.php', __DIR__ . '/../seguridad_url.php', $_SERVER['DOCUMENT_ROOT'] . '/app/seguridad_url.php'];
foreach ($rutas_shield as $rs) {
    if (file_exists($rs)) {
        require_once $rs;
        break;
    }
}

$uid = $_SESSION['usuario_id'] ?? 0;

// =============================================================================
// LÓGICA DE BÚSQUEDA
// =============================================================================
$q = trim($_GET['q'] ?? '');
$mod_filtro = trim($_GET['modalidad'] ?? '');
$orden_usuario = trim($_GET['orden'] ?? ''); 

// [NUBIRA 2.0] WHITELIST SEGURA DE FILTROS
$ORDENES_VALIDOS = ['', 'precio_asc', 'precio_desc', 'calificacion'];
$MODALIDADES_VALIDAS = ['', 'Presencial', 'Remoto', 'Híbrido'];

if (!in_array($orden_usuario, $ORDENES_VALIDOS, true)) $orden_usuario = '';
if (!in_array($mod_filtro, $MODALIDADES_VALIDAS, true)) $mod_filtro = '';

// =============================================================================
// [SENSOR NUBIRA] REGISTRO DE BÚSQUEDA GLOBAL
// =============================================================================
if (!empty($q) || !empty($mod_filtro)) {
    $rutas_logger = [__DIR__ . '/logger.php', __DIR__ . '/../logger.php', $_SERVER['DOCUMENT_ROOT'] . '/app/logger.php'];
    foreach($rutas_logger as $r) {
        if(file_exists($r)) { 
            require_once $r; 
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $is_bot = preg_match('/bot|crawl|spider|slurp|yahoo|mediapartners/i', strtolower($user_agent));
            if (!$is_bot && function_exists('registrar_actividad')) {
                $is_guest = !isset($_SESSION['usuario_id']);
                $termino_limpio = substr($q . (!empty($mod_filtro) ? " | Mod: $mod_filtro" : ""), 0, 100);
                if ($is_guest) {
                    $guest_hash = substr(md5(session_id()), 0, 8);
                    registrar_actividad($conn, 0, 'BUSQUEDA_GLOBAL_GUEST', "Invitado [$guest_hash] buscó: " . $termino_limpio);
                } else {
                    registrar_actividad($conn, $uid, 'BUSQUEDA_GLOBAL', "Término: " . $termino_limpio);
                }
            }
            break; 
        }
    }
}

$titulo_pag = "Resultados";
$resultados_servicios = [];
$resultados_apuntes = [];

if (strlen($q) > 1 || !empty($mod_filtro)) {
    $titulo_pag = "Resultados para: " . htmlspecialchars($q);
    
    $mapa_ordenes = [
        ''             => "s.score_nubira DESC, rating_promedio DESC, s.precio DESC, s.id DESC",
        'precio_asc'   => "s.precio ASC",
        'precio_desc'  => "s.precio DESC",
        'calificacion' => "s.score_nubira DESC, rating_promedio DESC, total_votos DESC",
    ];
    $order_sql_servicios = $mapa_ordenes[$orden_usuario] ?? $mapa_ordenes[''];
    $order_sql_apuntes = "ap.id DESC";

    $sql_mod_s = "";
    $params_mod = [];
    $tipos_mod = "";
    if (!empty($mod_filtro)) {
        if ($mod_filtro === 'Remoto') {
            $sql_mod_s = " AND (s.modalidad LIKE ? OR s.modalidad LIKE ? OR s.modalidad LIKE ?)";
            $params_mod = ['%Remoto%', '%Online%', '%Virtual%'];
            $tipos_mod = "sss";
        } elseif ($mod_filtro === 'Híbrido') {
            $sql_mod_s = " AND (s.modalidad LIKE ? OR s.modalidad LIKE ?)";
            $params_mod = ['%Híbrido%', '%Hibrido%'];
            $tipos_mod = "ss";
        } else {
            $sql_mod_s = " AND s.modalidad LIKE ?";
            $params_mod = ['%' . $mod_filtro . '%'];
            $tipos_mod = "s";
        }
    }

 
    
// [NUBIRA 2.0] Búsqueda multi-palabra con raíz tolerante a typos
    // "matematicas fisica" → cada palabra como raíz independiente, todas deben matchear
    $conds_s = ["1=1"]; $conds_a = ["1=1"];
    $params_servicios = [];
    $tipos_servicios = "";
    $params_apuntes = [];
    $tipos_apuntes = "";

    if (strlen($q) > 1) {
        // 1. Limpiar y trocear el query en palabras
        $palabras = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY);
        // Filtrar palabras ultra cortas (de, la, el...) salvo si el query completo es corto
        $palabras_validas = array_filter($palabras, fn($p) => mb_strlen($p) >= 3);
        if (empty($palabras_validas)) $palabras_validas = $palabras; // fallback

        // 2. Para cada palabra, calcular su raíz (recorta plurales comunes)
        $bloques_servicios = [];
        $bloques_apuntes = [];

        foreach ($palabras_validas as $palabra) {
            $raiz = $palabra;
            $largo = mb_strlen($palabra);

            // Recorte de plurales en español
            if ($largo > 4 && mb_substr($palabra, -2) === 'es') {
                $raiz = mb_substr($palabra, 0, -2);     // clases → clas, peces → pec
            } elseif ($largo > 3 && mb_substr($palabra, -1) === 's') {
                $raiz = mb_substr($palabra, 0, -1);     // matemáticas → matemática
            }
            // Si la raíz quedó muy corta, usar la palabra original
            if (mb_strlen($raiz) < 3) $raiz = $palabra;

            $t = "%" . $raiz . "%";

            // Cada palabra debe aparecer en al menos uno de los campos
            $bloques_servicios[] = "(s.titulo LIKE ? OR s.descripcion LIKE ? OR s.categoria LIKE ? OR s.materia LIKE ? OR s.asignatura LIKE ? OR s.area LIKE ?)";
            array_push($params_servicios, $t, $t, $t, $t, $t, $t);
            $tipos_servicios .= "ssssss";

            $bloques_apuntes[] = "(ap.titulo LIKE ? OR ap.descripcion LIKE ? OR ap.asignatura LIKE ? OR ap.materia LIKE ?)";
            array_push($params_apuntes, $t, $t, $t, $t);
            $tipos_apuntes .= "ssss";
        }

        // 3. Todas las palabras deben matchear (AND entre bloques)
        $conds_s = [implode(" AND ", $bloques_servicios)];
        $conds_a = [implode(" AND ", $bloques_apuntes)];
    }
    $where_s = implode(" OR ", $conds_s);
    $where_a = implode(" OR ", $conds_a);
    $params_s_full = array_merge($params_servicios, $params_mod);
    $tipos_s_full = $tipos_servicios . $tipos_mod;

    // BÚSQUEDA SERVICIOS
    $stmtS = $conn->prepare("SELECT s.*, 
            a.nombre as tutor_nombre,
            a.foto_perfil as tutor_foto,
            COALESCE(dp.institucion, a.institucion) as institucion_maestra,
          (SELECT COUNT(*) FROM valoraciones v WHERE v.servicio_id = s.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') as total_votos,
            (SELECT AVG(v.calificacion) FROM valoraciones v WHERE v.servicio_id = s.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') as rating_promedio,
            bi.archivo as banco_archivo
            FROM servicios s
            LEFT JOIN alumnos a ON s.alumno_id = a.id
            LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
            LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
            WHERE s.estado='aprobado' AND ($where_s) $sql_mod_s
            ORDER BY $order_sql_servicios LIMIT 20");
            
    if ($stmtS) {
        if(!empty($params_s_full)) $stmtS->bind_param($tipos_s_full, ...$params_s_full);
        $stmtS->execute();
        $resultados_servicios = $stmtS->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtS->close();
    }

    // BÚSQUEDA APUNTES
    $stmtA = $conn->prepare("SELECT ap.*,
            a.nombre as tutor_nombre,
            a.foto_perfil as tutor_foto,
            COALESCE(dp.institucion, a.institucion) as institucion_maestra
            FROM apuntes ap 
            LEFT JOIN alumnos a ON ap.id_alumno = a.id
            LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
            WHERE ap.publico=1 AND ($where_a) 
            ORDER BY $order_sql_apuntes LIMIT 20");
            
if ($stmtA) {
        if(!empty($params_apuntes)) $stmtA->bind_param($tipos_apuntes, ...$params_apuntes);
        $stmtA->execute();
        $resultados_apuntes = $stmtA->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtA->close();
    }
    
    // --- [SENSOR NUBIRA] ZERO-RESULTS TRACKING ---
    if (strlen($q) > 2 && empty($resultados_servicios) && empty($resultados_apuntes)) {
        $uid_fallida = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
        $stmt_fallida = $conn->prepare("INSERT INTO busquedas_fallidas (termino, usuario_id, fecha) VALUES (?, ?, NOW())");
        if ($stmt_fallida) {
            $stmt_fallida->bind_param("si", $q, $uid_fallida);
            $stmt_fallida->execute();
            $stmt_fallida->close();
        }
    }
}

// URL base
$params_url = $_GET; unset($params_url['orden']);
$url_base = '?' . http_build_query($params_url) . '&orden=';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= $titulo_pag ?> | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap'); body{font-family:'Inter',sans-serif;}</style>
</head>
<body class="bg-white text-gray-900 antialiased">

<?php 
$ruta_comp = __DIR__ . '/componentes';
if(file_exists($ruta_comp.'/header.php')) require_once $ruta_comp.'/header.php';
if(file_exists($ruta_comp.'/sidebar.php')) require_once $ruta_comp.'/sidebar.php';
?>

<main class="pt-20 pb-28 md:pb-20 lg:ml-64 px-4 max-w-[1600px] mx-auto md:px-8 min-h-[80vh]">
    
    <?php if (empty($q) && empty($mod_filtro)): ?>
        <div class="flex flex-col items-center justify-center py-12 md:py-24 px-4 animate-fade-in-up">
            <div class="w-20 h-20 bg-blue-50 border border-blue-100 rounded-full flex items-center justify-center mb-6">
                <i class="fa-solid fa-fire text-3xl text-[#54A6D8]"></i>
            </div>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900 mb-2 tracking-tight text-center">¿Qué quieres aprender hoy?</h2>
            <p class="text-sm text-gray-500 mb-8 text-center max-w-md">Busca clases, tutorías o apuntes. Aquí te dejamos lo más popular del momento en Nubira:</p>
            
            <div class="flex flex-wrap justify-center gap-3 max-w-2xl">
                <?php 
                $trending = [];
                try {
                    $sql_trend = "
                        SELECT termino FROM (
                            SELECT categoria AS termino, COUNT(*) AS total FROM servicios WHERE estado = 'aprobado' AND categoria != '' AND categoria IS NOT NULL GROUP BY categoria
                            UNION ALL
                            SELECT asignatura AS termino, COUNT(*) AS total FROM apuntes WHERE publico = 1 AND asignatura != '' AND asignatura IS NOT NULL GROUP BY asignatura
                        ) AS combinados
                        GROUP BY termino
                        ORDER BY SUM(total) DESC
                        LIMIT 6
                    ";
                    $res_trend = $conn->query($sql_trend);
                    if ($res_trend && $res_trend->num_rows > 0) {
                        while ($rt = $res_trend->fetch_assoc()) {
                            $trending[] = $rt['termino'];
                        }
                    }
                } catch (Exception $e) {}

                if (empty($trending)) {
                    $trending = ['Matemáticas', 'Física', 'Programación'];
                }

                foreach($trending as $t): 
                ?>
                    <a href="/busqueda?q=<?= urlencode($t) ?>" class="px-4 py-2 bg-white border border-gray-200 hover:border-[#54A6D8] hover:text-[#54A6D8] text-gray-700 text-sm font-bold rounded-full transition-colors group">
                        <i class="fa-solid fa-magnifying-glass text-gray-300 group-hover:text-[#54A6D8] mr-1 text-xs transition-colors"></i> <?= htmlspecialchars($t) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

    <?php else: ?>
       <div class="mb-4 md:mb-6 border-b border-gray-100 pb-3">
            <h1 class="text-xl md:text-3xl font-extrabold text-gray-900 mb-2 md:mb-3 tracking-tight truncate">
                Resultados para <span class="text-[#54A6D8]">"<?= htmlspecialchars($q) ?>"</span>
            </h1>

            <div class="flex items-center gap-2 overflow-x-auto no-scrollbar py-1 -mx-4 px-4 md:mx-0 md:px-0">
                <div class="relative shrink-0">
                    <select id="ordenar_por" onchange="window.location.href='<?= $url_base ?>' + this.value" class="appearance-none pl-3 pr-7 py-1.5 text-xs font-bold bg-gray-900 text-white border border-gray-900 rounded-full outline-none cursor-pointer focus:ring-2 focus:ring-gray-300 transition-all">
                        <option value="" <?= (empty($orden_usuario))?'selected':'' ?>>Recomendados</option>
                        <option value="calificacion" <?= ($orden_usuario=='calificacion')?'selected':'' ?>>Mejor Calificados</option>
                        <option value="precio_desc" <?= ($orden_usuario=='precio_desc')?'selected':'' ?>>Mayor Precio</option>
                        <option value="precio_asc"  <?= ($orden_usuario=='precio_asc')?'selected':'' ?>>Menor Precio</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-white">
                        <i class="fa-solid fa-chevron-down text-[9px]"></i>
                    </div>
                </div>

                <div class="h-4 w-px bg-gray-200 shrink-0 mx-0.5"></div>

                <a href="?q=<?= urlencode($q) ?>&orden=precio_asc" class="shrink-0 px-3 py-1.5 bg-white border <?= $orden_usuario=='precio_asc' ? 'border-gray-900 text-gray-900 bg-gray-50' : 'border-gray-200 text-gray-600 hover:border-gray-300' ?> rounded-full text-xs font-bold whitespace-nowrap transition-colors flex items-center gap-1.5">
                    Económicos
                </a>
                <a href="?q=<?= urlencode($q) ?>&orden=calificacion" class="shrink-0 px-3 py-1.5 bg-white border <?= $orden_usuario=='calificacion' ? 'border-gray-900 text-gray-900 bg-gray-50' : 'border-gray-200 text-gray-600 hover:border-gray-300' ?> rounded-full text-xs font-bold whitespace-nowrap transition-colors flex items-center gap-1.5">
                    Mejor Calificados
                </a>
                <a href="?q=<?= urlencode($q) ?>&modalidad=Remoto" class="shrink-0 px-3 py-1.5 bg-white border <?= $mod_filtro=='Remoto' ? 'border-gray-900 text-gray-900 bg-gray-50' : 'border-gray-200 text-gray-600 hover:border-gray-300' ?> rounded-full text-xs font-bold whitespace-nowrap transition-colors flex items-center gap-1.5">
                    100% Online
                </a>
            </div>
        </div>

       <?php if (!empty($resultados_servicios)): ?>
            <section class="mb-12">
                <h2 class="text-xl font-extrabold text-gray-900 mb-4 tracking-tight">Clases y Servicios</h2>
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6">
                    <?php 
                    $idx_srv = 0;
                    foreach ($resultados_servicios as $row): 
                        $idx_srv++;

                        // Identificadores y Links
                        $link_hash_srv = function_exists('nubira_encriptar_id') ? nubira_encriptar_id($row['id']) : (int)$row['id'];
                        
                        // Imagen de portada con cache estático
                        $img_url = resolver_portada_busqueda('servicio', $row);

                        // Fecha publicación → ¿es nuevo?
                        $hoy = new DateTime();
                        $fecha_pub = !empty($row['fecha_publicacion']) ? new DateTime($row['fecha_publicacion']) : $hoy;
                        $es_nuevo  = ($hoy->diff($fecha_pub)->days <= 14);

                        // Rating y score
                        $rating_val = (float)($row['rating_promedio'] ?? 0);
                        $total_v    = (int)($row['total_votos'] ?? 0);
                        $score      = (int)($row['score_nubira'] ?? 0);
                        $es_basico  = ($score < 60);

                        // Lógica de Precios y Ofertas
                        $precio_val = $row['precio'] ?? 0;
                        $es_oferta  = oferta_vigente($row);
                        $pct_descuento = ($es_oferta && (int)$precio_val > 0) ? round(((int)$precio_val - (int)$row['precio_oferta']) / (int)$precio_val * 100) : 0;
                        $precio_html = "";

                        if ($es_oferta) {
                            $precio_oferta = (int)$row['precio_oferta'];
                            $precio_html = '<div class="flex items-baseline gap-1.5 mb-0.5">'
                                . '<span class="text-[11px] text-gray-400 line-through font-medium leading-none">$' . number_format($precio_val, 0, ',', '.') . '</span>'
                                . '<span class="text-[14px] text-gray-700 font-semibold tracking-tight leading-none">$' . number_format($precio_oferta, 0, ',', '.') . '</span>'
                                . ($pct_descuento > 0 ? '<span class="bg-green-600 text-white text-[9px] font-semibold px-1 py-px rounded ml-1.5 leading-none relative -top-0.5">-' . $pct_descuento . '%</span>' : '')
                                . '</div>';
                        } else {
                            if (is_numeric($precio_val) && $precio_val > 0) {
                                $precio = "$" . number_format($precio_val, 0, ',', '.');
                                $precio_class = "text-gray-700 font-semibold tracking-tight";
                                $precio_html = '<div class="text-[13px] ' . $precio_class . ' leading-none mb-0.5">' . $precio . '</div>';
                            } else {
                                $precio = "Gratis";
                                $precio_class = "text-gray-700 font-semibold tracking-tight";
                                $precio_html = '<div class="text-[13px] ' . $precio_class . ' leading-none mb-0.5">' . $precio . '</div>';
                            }
                        }

                        // Tiers Nubira 2.0
                        $nivel_tutor = '';
                        if ($score >= 100 && $total_v >= 10 && $rating_val >= 4.7)      $nivel_tutor = 'leyenda';
                        elseif ($score >= 80 && $total_v >= 3 && $rating_val >= 4.0)    $nivel_tutor = 'elite';
                        elseif ($score >= 80)                                           $nivel_tutor = 'pro';
                        elseif ($score >= 60)                                           $nivel_tutor = 'top';

                        // Avatar y nombre del tutor
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

                        // Modalidad ícono
                        $mod = strtolower($row['modalidad'] ?? '');
                        if (strpos($mod, 'online') !== false)        $icon_mod = '<i class="fa-solid fa-wifi text-[10px]"></i>';
                        elseif (strpos($mod, 'presencial') !== false) $icon_mod = '<i class="fa-solid fa-user-group text-[10px]"></i>';
                        else                                          $icon_mod = '<i class="fa-solid fa-laptop text-[10px]"></i>';

                        // Rating
                        $html_stars = render_rating_html($rating_val, $total_v, 'Nuevo');

                        // Institución abreviada
                        $inst_text = institucion_tutor($row['institucion_maestra'] ?? ($row['institucion'] ?? ''));

                        // [OVERLAY NUBIRA] categoría sobre la portada
                        $categoria_overlay = $row['categoria'] ?? 'Otros';
                        $prefijo_overlay = in_array($categoria_overlay, ['Otros','Asesoría']) ? '' : 'Clase de';
                        $nombre_categoria_overlay = ($categoria_overlay === 'Otros') ? 'Clase' : $categoria_overlay;
                    ?>
                    
                    <a href="/detalle-servicio/<?= $link_hash_srv ?>"
                       onclick="if(typeof registrarClick === 'function') registrarClick(<?= (int)$row['id'] ?>, 'servicio')"
                       class="block rounded-xl flex flex-col transition-transform duration-300 hover:-translate-y-1 cursor-pointer w-[100%] sm:w-full sm:max-w-[380px] mx-auto md:max-w-none bg-transparent group h-full <?= $es_basico ? 'opacity-90 grayscale-[15%]' : '' ?>">

                          <div class="card-apunte relative overflow-hidden w-full aspect-[3/2] rounded-xl bg-gray-100 border border-gray-200">
                            <img src="<?= htmlspecialchars((string)$img_url) ?>"
                                 alt="<?= htmlspecialchars($row['titulo']) ?>"
                                 class="w-full h-full object-cover transition-transform duration-500 ease-out group-hover:scale-105"
                                 loading="<?= $idx_srv <= 4 ? 'eager' : 'lazy' ?>"
                                 decoding="async"
                                 <?= $idx_srv <= 4 ? 'fetchpriority="high"' : '' ?>
                                 width="320" height="240"
                                 onerror="this.onerror=null;this.src='/upload/preview/default_file.webp';">

                            <!-- [OVERLAY NUBIRA] gradient + categoría + tutor (partial) -->
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
                            <?php if ($es_oferta): ?>
                            <div class="absolute top-2.5 right-2.5 z-10">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-900 border border-amber-200">
                                    <?= (int)$row['cupos_oferta'] ?> <?= (int)$row['cupos_oferta'] === 1 ? 'cupo' : 'cupos' ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="pl-1 pr-1 pt-3 pb-1 flex flex-col flex-1 text-left min-h-[90px]">
                            <h6 class="font-bold text-[14px] leading-[1.3] text-gray-900 line-clamp-2 h-[36px] overflow-hidden mb-1">
                                <?= resaltarTermino($row['titulo'], $q) ?>
                            </h6>

                            <?= $precio_html ?>

                            <div class="flex items-center justify-between pt-1">
                                <div class="flex items-center gap-1.5 text-[10px] text-gray-400 font-bold uppercase tracking-wide truncate max-w-[70%]">
                                    <?php if(!empty($inst_text)): ?>
                                        <span class="truncate"><?= $inst_text ?></span>
                                    <?php endif; ?>
                                </div>
                              <div class="shrink-0 flex items-center gap-2">
                                    <?= $html_stars ?>
                                </div>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($resultados_apuntes)): ?>
            <section class="mb-12">
                <h2 class="text-xl font-extrabold text-gray-900 mb-4 tracking-tight">Apuntes</h2>
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6">
                    <?php 
                    $idx_ap = 0;
                    foreach ($resultados_apuntes as $a): 
                        $idx_ap++;
                        $link_hash_ap = function_exists('nubira_encriptar_id') ? nubira_encriptar_id($a['id']) : (int)$a['id'];
                        $img_ap = resolver_portada_busqueda('apunte', $a);

                        $nombre_completo_ap = !empty($a['tutor_nombre']) ? $a['tutor_nombre'] : 'Estudiante';
                        $partes_nombre_ap = array_values(array_filter(explode(' ', trim((string)$nombre_completo_ap))));
                        $tutor_nombre_ap = "Estudiante";
                        if (!empty($partes_nombre_ap[0])) {
                            $tutor_nombre_ap = ucwords(strtolower($partes_nombre_ap[0]));
                            if (count($partes_nombre_ap) >= 2) {
                                $tutor_nombre_ap .= ' ' . strtoupper(substr($partes_nombre_ap[count($partes_nombre_ap)-1], 0, 1)) . '.';
                            }
                        }
                        $foto_tutor_ap = !empty($a['tutor_foto']) ? '/app/perfil/fotos/' . $a['tutor_foto'] : "https://ui-avatars.com/api/?name=".urlencode($tutor_nombre_ap)."&background=f1f5f9&color=64748b";

                        $precio_val_ap = $a['precio'] ?? 0;
                        if (is_numeric($precio_val_ap) && $precio_val_ap > 0) {
                            $precio_ap = "$" . number_format($precio_val_ap, 0, ',', '.');
                            $precio_class_ap = "text-gray-700 font-semibold tracking-tight";
                        } else {
                            $precio_ap = "Gratis";
                            $precio_class_ap = "text-gray-700 font-semibold tracking-tight";
                        }

                        $inst_text_ap = abreviar_institucion($a['institucion_maestra'] ?? ($a['institucion'] ?? ''));
                    ?>
                    
                    <a href="/apunte/<?= $link_hash_ap ?>"
                       onclick="if(typeof registrarClick === 'function') registrarClick(<?= (int)$a['id'] ?>, 'apunte')"
                       class="block rounded-xl flex flex-col transition-transform duration-300 hover:-translate-y-1 cursor-pointer w-[100%] sm:w-full sm:max-w-[380px] mx-auto md:max-w-none bg-transparent group h-full">

                        <div class="card-apunte relative overflow-hidden w-full aspect-[3/2] rounded-xl bg-gray-100 border border-gray-200">
                            <img src="<?= htmlspecialchars((string)$img_ap) ?>"
                                 alt="<?= htmlspecialchars($a['titulo']) ?>"
                                 class="w-full h-full object-cover transition-transform duration-500 ease-out group-hover:scale-105"
                                 loading="<?= $idx_ap <= 4 ? 'eager' : 'lazy' ?>"
                                 decoding="async"
                                 <?= $idx_ap <= 4 ? 'fetchpriority="high"' : '' ?>
                                 width="320" height="240"
                                 onerror="this.onerror=null;this.src='/upload/preview/default_file.webp';">

                            <div class="absolute top-2.5 left-2.5 z-10">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-semibold bg-white/95 backdrop-blur-sm text-gray-900 border border-gray-200">Apunte</span>
                            </div>
                        </div>

                        <div class="pl-1 pr-1 pt-3 pb-1 flex flex-col flex-1 text-left min-h-[90px]">
                            <h6 class="font-bold text-[14px] leading-[1.3] text-gray-900 line-clamp-2 h-[36px] overflow-hidden mb-1">
                                <?= resaltarTermino($a['titulo'], $q) ?>
                            </h6>

                            <div class="text-[13px] <?= $precio_class_ap ?> leading-none mb-0.5"><?= $precio_ap ?></div>

                            <div class="flex items-center justify-between pt-1">
                                <div class="flex items-center gap-1.5 text-[10px] text-gray-400 font-bold uppercase tracking-wide truncate max-w-[70%]">
                                    <?php if(!empty($inst_text_ap)): ?>
                                        <span class="truncate"><?= $inst_text_ap ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="shrink-0 flex items-center gap-1 text-[10px] text-gray-500 font-semibold">
                                    <svg class="w-3 h-3 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                    <?= (int)($a['descargas'] ?? 0) ?>
                                </div>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (empty($resultados_servicios) && empty($resultados_apuntes) && strlen($q) > 0): ?>
            <div class="flex flex-col items-center justify-center py-20 px-4 text-center animate-fade-in-up">
                <div class="w-24 h-24 bg-gray-50 border border-gray-100 rounded-full flex items-center justify-center mb-6">
                    <i class="fa-solid fa-magnifying-glass text-4xl text-gray-300"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-900 mb-2 tracking-tight">Cero resultados</h2>
                <p class="text-sm text-gray-500 max-w-md mx-auto mb-6">No encontramos clases ni apuntes exactos para "<strong><?= htmlspecialchars($q) ?></strong>". <br>Ya le enviamos una alerta a nuestros tutores para que lo agreguen pronto.</p>
                <a href="/busqueda" class="px-5 py-2.5 bg-gray-900 hover:bg-[#54A6D8] text-white text-xs font-bold rounded-xl transition-colors flex items-center gap-2">
                    <i class="fa-solid fa-fire"></i> Ver lo más popular
                </a>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</main>

<?php 
if(file_exists($ruta_comp.'/nav_bottom.php')) require_once $ruta_comp.'/nav_bottom.php';
if(file_exists($ruta_comp.'/modal_publicar.php')) require_once $ruta_comp.'/modal_publicar.php';
if(file_exists($ruta_comp.'/modal_explora.php')) require_once $ruta_comp.'/modal_explora.php';
?>

<script>
    function setupModal(triggerId, modalId, cardId, closeId) {
        const btn = document.getElementById(triggerId), modal = document.getElementById(modalId), card = document.getElementById(cardId), close = document.getElementById(closeId);
        if(!btn || !modal) return;
        const open = () => { modal.classList.remove('hidden'); requestAnimationFrame(() => card.classList.remove('translate-y-full', 'opacity-0')); document.body.style.overflow = 'hidden'; };
        const shut = () => { card.classList.add('translate-y-full', 'opacity-0'); setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); };
        btn.onclick = (e) => { e.preventDefault(); open(); }; 
        if(close) close.onclick = shut; 
        modal.onclick = (e) => { if(e.target === modal) shut(); };
    }

    document.addEventListener('DOMContentLoaded', () => {
        <?php if (!isset($_SESSION['usuario_id'])): ?>
            const btnPublicar = document.getElementById('btn-publicar');
            if(btnPublicar) {
                btnPublicar.onclick = (e) => { 
                    e.preventDefault(); 
                    window.location.href = '/login?redir=' + encodeURIComponent(window.location.pathname + window.location.search);
                };
            }
        <?php else: ?>
            setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
        <?php endif; ?>

        setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');

        async function actualizarBadgeChats() {
            <?php if (!isset($_SESSION['usuario_id'])) echo 'return;'; ?>
            try {
                const res = await fetch('/app/contar_mensajes_nuevos.php');
                const data = await res.json();
                const total = parseInt(data.total || 0);
                ['badge-chats-sidebar', 'badge-chats-bottom'].forEach(id => {
                    const el = document.getElementById(id);
                    if(el) { 
                        if(id.includes('sidebar')) el.innerText = total;
                        total > 0 ? el.classList.remove('hidden') : el.classList.add('hidden'); 
                    }
                });
            } catch {}
        }
        
        actualizarBadgeChats();
        setInterval(actualizarBadgeChats, 10000);
    });
</script>

<script src="/assets/js/behavior_tracker.js"></script>
</body>
</html>