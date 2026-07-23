<?php
/**
 * VISTA: DETALLE DE SERVICIO
 * ESTADO: FINAL - BLINDADO (TRACKER + PATH FINDER + SAFETY NET)
 * VERSION: NETFLIX SENSOR READY + TRUST SIGNALS UI + NUBIRA 2.0 ECOSYSTEM
 */

// 1. Configuración de Seguridad y Errores
ini_set('display_errors', 0); 
error_reporting(E_ALL);
session_start();

// Anti-cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// 2. Rutas Seguras (Buscador de Conexión)
$ruta_raiz = '';
if (file_exists(__DIR__ . '/conexion.php')) { $ruta_raiz = __DIR__; } 
elseif (file_exists(dirname(__DIR__) . '/app/conexion.php')) { $ruta_raiz = dirname(__DIR__) . '/app'; } 
elseif (file_exists(dirname(__DIR__) . '/conexion.php')) { $ruta_raiz = dirname(__DIR__); } 
else { die("Error Crítico: No se encuentra conexion.php."); }

require_once $ruta_raiz . '/conexion.php';

// =========================================================================
// 🛡️ [NUBIRA SHIELD] MIDDLEWARE ANTI-BOT (Nivel Arquitectura)
// Se ejecuta AQUÍ, antes de enviar HTML o hacer queries pesadas.
// =========================================================================
if (isset($conn)) {
    $antibot_path = $app_dir . '/middleware/antibot.php';
    if (file_exists($antibot_path)) {
        require_once $antibot_path;
        if (function_exists('check_nubira_shield')) {
            check_nubira_shield($conn); // Si es bot, corta aquí y devuelve 403 puro
        }
    }
}
// =========================================================================

// Ruta Componentes
$ruta_comp = dirname($ruta_raiz) . '/componentes';
if (!is_dir($ruta_comp)) $ruta_comp = $ruta_raiz . '/componentes';
if (!is_dir($ruta_comp)) $ruta_comp = $_SERVER['DOCUMENT_ROOT'] . '/componentes';

// Helper Iconos
if (file_exists($ruta_raiz . '/iconos.php')) require_once $ruta_raiz . '/iconos.php';
else { if (!function_exists('icon')) { function icon($n, $c=''){ return "<i class='fa-solid fa-star $c'></i>"; } } }

require_once $ruta_raiz . '/helpers/ofertas.php';
require_once $ruta_raiz . '/helpers/imagen_servicio.php'; // [BANCO] resolver unificado de portada
require_once $ruta_raiz . '/helpers/institucion.php';     // institucion_tutor()

// 3. Configuración Base & Lazy Registration
$base_url = "https://nubira.cl"; 
$default_image = $base_url . "/upload/servicios/default_clases.webp";
$logueado = isset($_SESSION['usuario_id']);
if (!$logueado) $_SESSION['redirigir_despues_login'] = $_SERVER['REQUEST_URI'];
$uid = (int)($_SESSION['usuario_id'] ?? 0); 

// 4. Validación ID (NUBIRA SHIELD)
require_once $ruta_raiz . '/seguridad_url.php';

require_once $ruta_raiz . '/helpers/seo.php';
$id = 0;
$viene_url_nueva = false;

if (isset($_GET['servicio_id']) && is_numeric($_GET['servicio_id'])) {
    $id = (int)$_GET['servicio_id'];
    $viene_url_nueva = true;
} elseif (isset($_GET['id'])) {
    $param_id = $_GET['id'];
    if (is_numeric($param_id)) {
        $id = (int)$param_id;
    } else {
        $id = nubira_desencriptar_id($param_id);
    }
}

if ($id === 0) { 
    http_response_code(404); 
    die("Servicio no encontrado o enlace caducado."); 
}

// 5. Consulta SQL
$servicio = null;
$sql = "SELECT s.*, a.nombre AS nombre_alumno, a.foto_perfil, a.verificacion_estado, COALESCE(dp.institucion, a.institucion) AS institucion_maestra, bi.archivo AS banco_archivo
        FROM servicios s
        LEFT JOIN alumnos a ON s.alumno_id = a.id
        LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
        LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
        WHERE s.id = ?";
try {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $servicio = $stmt->get_result()->fetch_assoc();
} catch (Exception $e) { die("Error DB."); }

if (!$servicio) { http_response_code(404); die("Servicio eliminado."); }

// Canonicalizar URL a formato slug
if (!$viene_url_nueva) {
    $slug_can = $servicio['slug'] ?? '';
    header("Location: " . url_servicio($id, $slug_can ?: null), true, 301);
    exit;
} elseif (!empty($_GET['slug_captured']) && !empty($servicio['slug'])) {
    if ($_GET['slug_captured'] !== $servicio['slug']) {
        header("Location: " . url_servicio($id, $servicio['slug']), true, 301);
        exit;
    }
}

// [NUBIRA SHIELD] Bloqueo de visibilidad por estado
$es_propietario = ($logueado && $uid == $servicio['alumno_id']);
$es_admin = (isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin');

if ($servicio['estado'] !== 'aprobado' && !$es_propietario && !$es_admin) {
    http_response_code(403);
    die("Este servicio no está disponible o se encuentra en revisión.");
}

// =============================================================================
// [SENSOR NUBIRA] REGISTRO DE ACTIVIDAD PARA IA (GEMINI FLASH 2.0)
// =============================================================================
$rutas_logger = [
    __DIR__ . '/app/logger.php', 
    __DIR__ . '/logger.php',
    $_SERVER['DOCUMENT_ROOT'] . '/app/logger.php',
    dirname(__DIR__) . '/app/logger.php'
];

foreach($rutas_logger as $r) {
    if(file_exists($r)) { 
        require_once $r; 
        
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $is_bot = preg_match('/bot|crawl|spider|slurp|yahoo|mediapartners/i', strtolower($user_agent));
        
        if (!$is_bot && function_exists('registrar_actividad')) {
            $is_guest = !isset($_SESSION['usuario_id']);
            $usuario_id = !$is_guest ? (int)$_SESSION['usuario_id'] : 0;
            
            if ($is_guest) {
                $guest_hash = substr(md5(session_id()), 0, 8);
                registrar_actividad($conn, 0, 'VIEW_SERVICIO_GUEST', "Invitado [$guest_hash] vio servicio ID: $id");
            } else {
                registrar_actividad($conn, $usuario_id, 'VER_SERVICIO', "Usuario vio servicio ID: $id");
            }
        }
        break; 
    }
}
// =============================================================================

// [TRACKER BACKEND] Contador simple (Blindado Nubira 2.0)
if ($logueado && $uid != $servicio['alumno_id']) { 
    $stmt_visitas = $conn->prepare("UPDATE servicios SET visitas = visitas + 1 WHERE id = ?");
    if ($stmt_visitas) {
        $stmt_visitas->bind_param("i", $id);
        $stmt_visitas->execute();
        $stmt_visitas->close();
    }
}

// Datos Autor
$nombrePub = 'Usuario'; $inicPub = 'US';
if (!empty($servicio['nombre_alumno'])) {
    $p = array_values(array_filter(explode(' ', trim($servicio['nombre_alumno']))));
    if (!empty($p[0])) {
        $nombrePub = ucwords(strtolower($p[0]));
        $inicPub = strtoupper(substr($p[0], 0, 1));
        if (count($p) >= 2) { $nombrePub .= ' '.strtoupper(substr(end($p),0,1)).'.'; $inicPub .= strtoupper(substr(end($p),0,1)); }
    }
}

/**
 * [NUBIRA 2.0] Formatea tiempo de respuesta del tutor en rangos amigables (estilo Airbnb).
 * Recibe minutos (mediana móvil 30d, calculada on-demand, outliers >24h descartados).
 * 
 * @return array ['texto' => string, 'tono' => 'verde'|'azul'|'naranjo'|'gris']
 */
require_once __DIR__ . '/helpers/tiempo_respuesta.php';

$tiempo_data = formatearTiempoRespuestaNubira(
    calcular_tiempo_respuesta_tutor($conn, (int)$servicio['alumno_id'])
);
$texto_valor = $tiempo_data['texto'];
$tono_valor = $tiempo_data['tono'];
// =========================================================================
// Contrato Existente
$contrato = null;
if ($logueado) {
    $q = $conn->prepare("SELECT id FROM contratos WHERE servicio_id = ? AND estado IN ('activo', 'en_progreso') AND (comprador_id = ? OR vendedor_id = ?) LIMIT 1");
    $q->bind_param("iii", $id, $uid, $uid); $q->execute(); 
    $contrato = $q->get_result()->fetch_assoc();
}

// Comentarios
$coms = []; $tot_stars = 0; $tot_votos = 0; 
$sc = $conn->prepare("SELECT v.id as c_id, v.calificacion as r, v.comentario as t, a.nombre as n, a.foto_perfil as f, v.fecha as d FROM valoraciones v JOIN alumnos a ON v.id_evaluador = a.id WHERE v.servicio_id = ? AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor' ORDER BY v.id DESC");
$sc->bind_param("i", $id); $sc->execute(); $rc = $sc->get_result();
while($r = $rc->fetch_assoc()) { $coms[] = $r; $tot_stars += $r['r']; $tot_votos++; }
$promedio = ($tot_votos > 0) ? round($tot_stars / $tot_votos, 1) : 0;

// =========================================================================
// [NUBIRA 2.0] MOTOR DE RECOMENDACIÓN BASADO EN INTERESES (AFFINITY ENGINE)
// =========================================================================
$recs = null;

// 1. ¿Quién es el usuario? (Logueado o Huella)
$identificador_sql = "";
$param_identificador = "";
$tipo_param = "";

if ($logueado) {
    $identificador_sql = "usuario_id = ?";
    $param_identificador = $uid;
    $tipo_param = "i";
} else {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $huella = hash('sha256', $ip . $user_agent);
    $identificador_sql = "huella_visitante = ?";
    $param_identificador = $huella;
    $tipo_param = "s";
}

// 2. Descubrir la categoría favorita del usuario (Donde tiene más puntos)
$cat_favorita = $servicio['categoria']; // Por defecto, usamos la categoría del servicio actual
$sql_fav = "SELECT categoria, SUM(peso_score) as total_puntos 
            FROM tracker_intereses 
            WHERE $identificador_sql AND categoria != 'General'
            GROUP BY categoria 
            ORDER BY total_puntos DESC LIMIT 1";

if ($stmt_fav = $conn->prepare($sql_fav)) {
    $stmt_fav->bind_param($tipo_param, $param_identificador);
    $stmt_fav->execute();
    $res_fav = $stmt_fav->get_result()->fetch_assoc();
    if ($res_fav && !empty($res_fav['categoria'])) {
        $cat_favorita = $res_fav['categoria'];
    }
    $stmt_fav->close();
}

// 3. Buscar recomendaciones mezclando: Su categoría favorita + La categoría actual + Algo de frescura
// Priorizamos lo que le gusta, pero si no hay suficiente, rellenamos con la categoría del servicio actual.
$sql_recs = "SELECT s.id, s.slug, s.titulo, s.precio, s.imagen, s.imagen_banco_id, s.categoria, s.modalidad,
                    s.score_nubira, s.precio_oferta, s.cupos_oferta, s.is_subvencionado, s.oferta_termino,
                    a.nombre as nombre_tutor, a.foto_perfil,
                    COALESCE(dp.institucion, a.institucion) as institucion_maestra,
                    (SELECT AVG(c.calificacion_comprador) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as rating_promedio,
                    (SELECT COUNT(*) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as total_votos,
                    bi.archivo as banco_archivo
             FROM servicios s
             LEFT JOIN alumnos a ON s.alumno_id = a.id
             LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
             LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
             WHERE s.estado = 'aprobado' AND COALESCE(s.visible, 1) = 1 AND a.bloqueado = 0 AND s.id != ?
             ORDER BY
                CASE WHEN s.categoria = ? THEN 1 ELSE 2 END,
                CASE WHEN s.categoria = ? THEN 1 ELSE 2 END,
                s.id DESC
             LIMIT 4";

if ($stmt_recs = $conn->prepare($sql_recs)) {
    $stmt_recs->bind_param("iss", $id, $cat_favorita, $servicio['categoria']);
    $stmt_recs->execute();
    $recs = $stmt_recs->get_result();
}
// =========================================================================


// 6. METADATOS & OG TAGS
$page_title = htmlspecialchars($servicio['titulo']);
$_inst = !empty($servicio['institucion_maestra'])
    ? $servicio['institucion_maestra']
    : (!empty($servicio['institucion']) ? $servicio['institucion'] : 'Chile');
$_paes_sufijo_titulo = !empty($servicio['es_paes']) ? ' (PAES)' : '';
$_seo_titulo_raw = $servicio['titulo'] . $_paes_sufijo_titulo . ' en ' . $_inst . ' | Nubira';
$seo_title = htmlspecialchars(mb_strlen($_seo_titulo_raw) > 65
    ? mb_substr($_seo_titulo_raw, 0, 62) . '...'
    : $_seo_titulo_raw);
$_nombre_tutor = explode(' ', trim($servicio['nombre_alumno'] ?? ''))[0];
$_nombre_tutor = !empty($_nombre_tutor) ? $_nombre_tutor : 'tu tutor';
$_desc_corta = mb_strimwidth(strip_tags($servicio['descripcion'] ?? ''), 0, 100, '');
$_paes_sufijo_desc = !empty($servicio['es_paes']) ? ' (Preparación PAES)' : '';
$_meta_desc_raw = ucfirst($servicio['modalidad'] ?? '') . ' de ' . ($servicio['categoria'] ?? '')
    . ' con ' . $_nombre_tutor . '. ' . $_desc_corta . '. Contrata en Nubira.' . $_paes_sufijo_desc;
$og_desc = htmlspecialchars(mb_strlen($_meta_desc_raw) > 155
    ? mb_substr($_meta_desc_raw, 0, 152) . '...'
    : $_meta_desc_raw);
$token_seguro = nubira_encriptar_id($id);
$url_servicio_masked = $base_url . "/detalle-servicio/" . $token_seguro;
$url_canonical = $base_url . url_servicio($id, $servicio['slug'] ?? null);

$og_image = $default_image; 
$web_src = $default_image;
$og_mime = "image/webp"; 
$og_w = 1200; $og_h = 630; 

// [BANCO] portada vía helper unificado (banco → legacy → placeholder)
$portada_rel = url_portada($servicio);
$portada_fis = path_portada($servicio);
if ($portada_rel && $portada_fis) {
    $web_src  = $portada_rel;
    $og_image = $base_url . $portada_rel;

    $ext = strtolower(pathinfo($portada_fis, PATHINFO_EXTENSION));
    if ($ext === 'jpg' || $ext === 'jpeg') $og_mime = "image/jpeg";
    elseif ($ext === 'png') $og_mime = "image/png";

    $d = @getimagesize($portada_fis);
    if ($d) { $og_w = $d[0]; $og_h = $d[1]; }
}
$share_txt = urlencode("¡Mira este servicio en Nubira.cl! " . $servicio['titulo']);

require_once __DIR__ . '/helpers/horarios.php';

// --- LÓGICA DE HORARIOS DINÁMICOS NUBIRA 2.0 ---
$horarios_info    = parsear_horarios_servicio($servicio['horarios_json'] ?? null);
$tiene_horarios   = $horarios_info['tiene_horarios'];
$dias_disponibles = $horarios_info['dias'];
$dia_proximo      = $horarios_info['dia_proximo'];
// --- LÓGICA DE TUTOR EN CLASE ---
$tutor_en_clase = false;
$q_busy = $conn->prepare("SELECT id FROM contratos WHERE vendedor_id = ? AND estado = 'en_progreso' LIMIT 1");
$q_busy->bind_param("i", $servicio['alumno_id']);
$q_busy->execute();
if ($q_busy->get_result()->num_rows > 0) {
    $tutor_en_clase = true;
}
$q_busy->close();
// ---------------------------------

// [NUBIRA 2.0 PERF] Liberar la sesión para no bloquear el navegador
session_write_close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= $seo_title ?></title>
    <meta name="description" content="<?= $og_desc ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <link rel="canonical" href="<?= $url_canonical ?>" />
    
    <meta property="fb:app_id" content="966242223397117" />
    <meta property="og:type" content="website" />
    <meta property="og:site_name" content="Nubira.cl" /> 
    <meta property="og:title" content="<?= $page_title ?>" />
    <meta property="og:description" content="<?= $og_desc ?>" />
    <meta property="og:image" content="<?= $og_image ?>" />
    <meta property="og:image:secure_url" content="<?= $og_image ?>" />
    <meta property="og:image:type" content="<?= $og_mime ?>" />
    <meta property="og:image:width" content="<?= $og_w ?>" />
    <meta property="og:image:height" content="<?= $og_h ?>" />
    <meta property="og:url" content="<?= $url_canonical ?>" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:image" content="<?= $og_image ?>" />

    <script type="application/ld+json">
    <?php
    $ld_provider = ['@type' => 'Person', 'name' => $servicio['nombre_alumno']];
    if (!empty($servicio['foto_perfil'])) {
        $ld_provider['image'] = str_starts_with($servicio['foto_perfil'], 'http')
            ? $servicio['foto_perfil']
            : $base_url . $servicio['foto_perfil'];
    }
    $ld = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Service',
        'name'        => $servicio['titulo'],
        'description' => mb_strimwidth(strip_tags($servicio['descripcion']), 0, 300, '…'),
        'url'         => $url_canonical,
        'provider'    => $ld_provider,
        'areaServed'  => 'Chile',
        'offers'      => [
            '@type'         => 'Offer',
            'price'         => (int)$servicio['precio'],
            'priceCurrency' => 'CLP',
            'availability'  => 'https://schema.org/InStock',
        ],
    ];
    if ($tot_votos > 0) {
        $ld['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => $promedio,
            'bestRating'  => 5,
            'worstRating' => 1,
            'reviewCount' => $tot_votos,
        ];
    }
    echo json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    ?>
    </script>

    <script>
      !function(f,b,e,v,n,t,s)
      {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
      n.callMethod.apply(n,arguments):n.queue.push(arguments)};
      if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
      n.queue=[];t=b.createElement(e);t.async=!0;
      t.src=v;s=b.getElementsByTagName(e)[0];
      s.parentNode.insertBefore(t,s)}(window, document,'script',
      'https://connect.facebook.net/en_US/fbevents.js');
      
      fbq('init', '949858788026352'); 
      fbq('track', 'PageView');
    </script>
    <noscript>
      <img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=949858788026352&ev=PageView&noscript=1" />
    </noscript>
    <script src="https://cdn.tailwindcss.com"></script>
    
   <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #ffffff; }
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    .text-nubira { color: #54A6D8; }
    .bg-nubira { background-color: #54A6D8; }
    nav form[role="search"] { opacity: 0; pointer-events: none; visibility: hidden; width: 0; }
    
    /* [NUBIRA 2.0] Modo "Task" en móvil: detalle enfocado en conversión.
       - Oculta nav_bottom (la X reemplaza la salida)
       - Patrón consistente con publicar_servicio.php */
    @media (max-width: 1023px) {
        nav.fixed.bottom-0,
        .fixed.bottom-0[id*="nav"] {
            display: none !important;
        }
    }
</style>
</head>
<body class="bg-white text-gray-900 antialiased overflow-x-hidden pb-safe"
      data-id="<?= (int)$servicio['id'] ?>" 
      data-tipo="servicio"
      data-categoria="<?= htmlspecialchars($servicio['categoria'] ?? 'General') ?>">

<div id="loader" class="fixed inset-0 bg-white/95 flex items-center justify-center z-[60] transition-opacity duration-300">
    <div class="animate-spin h-10 w-10 border-4 border-blue-200 border-t-[#54A6D8] rounded-full"></div>
</div>

<!-- [NUBIRA 2.0] Ocultar Header global en móvil para experiencia Inmersiva -->
<div class="hidden md:block">
    <?php if(file_exists($ruta_comp . '/header.php')) require_once $ruta_comp . '/header.php'; ?>
</div>
<?php if(file_exists($ruta_comp . '/sidebar.php')) require_once $ruta_comp . '/sidebar.php'; ?>

<main class="pt-4 md:pt-20 pb-28 md:pb-10 lg:ml-64 px-4 max-w-full mx-auto overflow-hidden md:px-10" 
      data-track-id="<?= (int)$servicio['id'] ?>" 
      data-track-type="servicio">

    <div class="max-w-[1100px] mx-auto">

        <?php if ($es_propietario): ?>
            <?php if ($servicio['estado'] === 'pendiente'): ?>
                <div class="mb-6 bg-amber-50 border border-yellow-200 rounded-2xl p-4 flex items-start md:items-center gap-4 shadow-sm animate-pulse">
                    <div class="w-10 h-10 bg-yellow-100 text-yellow-600 rounded-full flex items-center justify-center shrink-0 mt-1 md:mt-0">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div>
                        <h4 class="font-bold text-yellow-800 text-sm">Servicio en Revisión</h4>
                        <p class="text-xs text-yellow-700 font-medium">Editaste este servicio recientemente. Un administrador lo está revisando para asegurar que cumple con nuestras normas. Volverá a la vitrina pronto.</p>
                    </div>
                </div>
            <?php elseif ($servicio['estado'] === 'rechazado'): ?>
                <div class="mb-6 bg-red-50 border border-red-200 rounded-2xl p-4 flex items-start md:items-center gap-4 shadow-sm">
                    <div class="w-10 h-10 bg-red-100 text-red-600 rounded-full flex items-center justify-center shrink-0 mt-1 md:mt-0">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <h4 class="font-bold text-red-800 text-sm">Publicación Pausada</h4>
                        <p class="text-xs text-red-700 font-medium">Hubo un problema con la última edición de este servicio. Por favor, revísalo y edítalo nuevamente.</p>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:items-start">
            
            <div class="lg:col-span-8 space-y-8">
                <div class="bg-white border border-gray-100 rounded-2xl p-6 shadow-sm">
                    <!-- Topbar dentro de la card (solo móvil): Volver + Categoría + Compartir -->
                    <div class="lg:hidden flex items-center justify-between gap-2 mb-4">
                        <button type="button" onclick="navegacionSeguraNubira()"
                                class="w-10 h-10 flex items-center justify-center rounded-full bg-gray-50 hover:bg-gray-100 border border-gray-200/60 shadow-sm active:scale-95 transition-all" aria-label="Volver">
                            <i class="fa-solid fa-arrow-left text-gray-700 text-[17px]"></i>
                        </button>
                        <span class="px-2.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-700 border border-gray-200 uppercase tracking-wide"><?= htmlspecialchars($servicio['categoria']) ?></span>
                        <button type="button"
                                class="js-abrir-sheet-compartir w-10 h-10 flex items-center justify-center rounded-full bg-gray-50 hover:bg-[#54A6D8] hover:text-white text-[#54A6D8] border border-gray-200/60 shadow-sm active:scale-95 transition-all" aria-label="Compartir" data-track-click="share:abrir_sheet">
                            <?= icon('share-outline', 'w-5 h-5') ?>
                        </button>
                    </div>
                    <div class="hidden lg:flex items-center justify-between gap-3 mb-3">
                      <div class="flex items-center gap-3">
                          <span class="px-2.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-700 border border-gray-200 uppercase tracking-wide"><?= htmlspecialchars($servicio['categoria']) ?></span>
                      </div>
                      <!-- ✈️ Compartir (desktop): abre el bottom sheet -->
                      <button type="button"
                              class="js-abrir-sheet-compartir hidden lg:inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-gray-50 hover:bg-[#54A6D8] hover:text-white text-[#54A6D8] border border-gray-200 text-sm font-bold transition-all shrink-0"
                              aria-label="Compartir" data-track-click="share:abrir_sheet">
                          <?= icon('share-outline','w-4 h-4') ?> Compartir
                      </button>
                    </div>

                    <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 leading-tight mb-6 tracking-tight"><?= $page_title ?></h1>
                    
                    <a href="/perfil/<?= (int)$servicio['alumno_id'] ?>" 
                       class="flex items-center gap-4 pb-6 border-b border-gray-100 w-full hover:bg-gray-50 p-3 rounded-xl transition -mx-3 track-seller"
                       data-track-click="perfil:<?= (int)$servicio['alumno_id'] ?>">
                        <div class="w-14 h-14 rounded-full border border-gray-200 bg-white overflow-hidden shadow-sm flex-shrink-0">
                             <?php $pf = (!empty($servicio['foto_perfil'])) ? "/app/perfil/fotos/".htmlspecialchars($servicio['foto_perfil']) : ""; ?>
                            <?php if($pf): ?><img src="<?= $pf ?>" class="w-full h-full object-cover"><?php else: ?><div class="w-full h-full flex items-center justify-center bg-blue-50 text-[#54A6D8] font-bold text-lg"><?= $inicPub ?></div><?php endif; ?>
                        </div>
                        <div>
                            <div class="flex items-center gap-1.5">
                                <p class="text-sm font-bold text-gray-900">Publicado por <?= htmlspecialchars($nombrePub) ?></p>
                                <?php if (($servicio['verificacion_estado'] === 'aprobado') || ($servicio['verificacion_estado'] === null && !empty($servicio['institucion_maestra']))): ?><i class="fa-solid fa-circle-check text-[#54A6D8] text-xs"></i><?php endif; ?>
                            </div>
                           <div class="flex items-center gap-1.5 mt-0.5">
            <span class="text-gray-400 text-xs"><i class="fa-solid fa-building-columns"></i></span>
            <p class="text-xs text-gray-500 uppercase font-medium"><?= htmlspecialchars(institucion_tutor($servicio['institucion_maestra'] ?? '', false)) ?></p>
        </div>
        
     <div class="mt-1.5">
    <?php
    // Mapeo de tonos Nubira 2.0 (estilo Airbnb)
    $clases_tono = [
        'verde'   => ['icono' => 'text-emerald-500', 'texto' => 'text-emerald-700'],
        'azul'    => ['icono' => 'text-[#54A6D8]',   'texto' => 'text-gray-900'],
        'naranjo' => ['icono' => 'text-orange-500',  'texto' => 'text-orange-700'],
        'gris'    => ['icono' => 'text-gray-400',    'texto' => 'text-gray-500'],
    ];
    $c = $clases_tono[$tono_valor] ?? $clases_tono['gris'];
    ?>
    <p class="text-[11px] text-gray-500 font-medium flex items-center gap-1.5">
        <i class="fa-regular fa-clock <?= $c['icono'] ?>"></i>
        <?php if ($tono_valor === 'gris'): ?>
            <span class="<?= $c['texto'] ?> font-bold">Sin historial de respuesta</span>
        <?php else: ?>
            Responde <span class="<?= $c['texto'] ?> font-bold"><?= htmlspecialchars($texto_valor) ?></span>
        <?php endif; ?>
        <span class="text-gray-300">·</span>
        <i class="fa-solid fa-star text-gray-700 text-[10px]"></i>
        <span id="val-promedio" class="font-bold text-gray-900"><?= $promedio > 0 ? $promedio : 'Nuevo' ?></span>
        <?php if($tot_votos > 0): ?>
            <span id="val-bullet" class="text-gray-300">·</span>
            <span id="val-votos-txt" class="text-gray-500"><?= $tot_votos ?> reseña<?= $tot_votos != 1 ? 's' : '' ?></span>
        <?php endif; ?>
    </p>
</div>
        
    </div>
</a>


                   <div class="mt-6">
                        <h3 class="font-bold text-gray-900 mb-3">Sobre este servicio</h3>
                        <?php
                            $desc_raw = trim($servicio['descripcion'] ?? '');
                            $desc_raw = html_entity_decode($desc_raw, ENT_QUOTES, 'UTF-8');
                            $desc_raw = preg_replace_callback('/\(([^)]+\|[^)]+)\)/', function($m) {
                                $ops = explode('|', $m[1]);
                                return $ops[array_rand($ops)];
                            }, $desc_raw);
                            $desc_larga = mb_strlen($desc_raw) > 150;
                            $desc_corta = $desc_larga ? mb_strimwidth($desc_raw, 0, 150, '…') : $desc_raw;
                        ?>
                        <div id="desc-servicio-corta" class="text-gray-600 text-sm whitespace-normal font-normal leading-relaxed" style="overflow-wrap:anywhere; word-break:break-word;">
                            <?= nl2br(htmlspecialchars($desc_corta, ENT_QUOTES, 'UTF-8')) ?>
                        </div>
                        <?php if ($desc_larga): ?>
                        <div id="desc-servicio-completa" class="hidden text-gray-600 text-sm whitespace-normal font-normal leading-relaxed" style="overflow-wrap:anywhere; word-break:break-word;">
                            <?= nl2br(htmlspecialchars($desc_raw, ENT_QUOTES, 'UTF-8')) ?>
                        </div>
                        <button type="button" onclick="toggleDescripcionServicio(this)" class="text-[#54A6D8] text-[11px] font-bold mt-1.5 hover:underline outline-none tracking-wide uppercase">Leer más</button>
                        <?php endif; ?>
                    </div>
                    <?php if ($desc_larga): ?>
                    <script>
                    function toggleDescripcionServicio(btn) {
                        var corta = document.getElementById('desc-servicio-corta');
                        var completa = document.getElementById('desc-servicio-completa');
                        if (!corta || !completa) return;
                        var expandido = !completa.classList.contains('hidden');
                        if (expandido) {
                            completa.classList.add('hidden');
                            corta.classList.remove('hidden');
                            btn.innerText = 'Leer más';
                        } else {
                            completa.classList.remove('hidden');
                            corta.classList.add('hidden');
                            btn.innerText = 'Leer menos';
                        }
                    }
                    </script>
                    <?php endif; ?>

<?php if (!empty($servicio['video_path']) && $servicio['video_estado'] === 'aprobado'): ?>
<div class="mt-6 pt-6 border-t border-gray-50">
    <h3 class="text-sm font-bold text-gray-700 mb-3">Video de presentación del tutor</h3>
    <?php
        $poster_video = !empty($servicio['video_thumb_path'])
            ? '/upload/servicios/' . htmlspecialchars($servicio['video_thumb_path'])
            : $portada_rel;
    ?>
    <div class="w-[140px] md:w-[180px]">
        <div class="relative aspect-[9/16] bg-black rounded-xl overflow-hidden shadow-sm">
            <video id="video-tutor-player"
                   src="/upload/videos_servicios/<?= htmlspecialchars($servicio['video_path']) ?>"
                   poster="<?= htmlspecialchars($poster_video) ?>"
                   class="w-full h-full object-cover"
                   controls
                   preload="none"
                   controlsList="nodownload"
                   disablePictureInPicture
                   playsinline>
            </video>
            <button type="button" id="btn-play-video-tutor" onclick="reproducirVideoTutor()"
                    class="absolute inset-0 flex items-center justify-center bg-black/10 hover:bg-black/20 transition-colors">
                <span class="w-11 h-11 rounded-full bg-white/90 flex items-center justify-center shadow-md">
                    <svg class="w-4 h-4 text-gray-900 ml-0.5" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M8 5v14l11-7z"/>
                    </svg>
                </span>
            </button>
            <div class="absolute top-1.5 right-1.5 pointer-events-none z-10">
                <span class="text-white/75 text-[9px] font-bold tracking-wide px-1.5 py-0.5 rounded bg-black/30"
                      style="text-shadow:0 1px 2px rgba(0,0,0,0.8);">
                    Nubira.cl
                </span>
            </div>
        </div>
    </div>
</div>
<script>
function reproducirVideoTutor() {
    var v = document.getElementById('video-tutor-player');
    var btn = document.getElementById('btn-play-video-tutor');
    if (!v) return;
    v.play();
    if (btn) btn.style.display = 'none';
}
(function () {
    var v = document.getElementById('video-tutor-player');
    var btn = document.getElementById('btn-play-video-tutor');
    if (!v || !btn) return;
    v.addEventListener('pause', function () { btn.style.display = 'flex'; });
    v.addEventListener('ended', function () { btn.style.display = 'flex'; });
}());
</script>
<?php elseif ($es_propietario): ?>
<div class="mt-6 pt-6 border-t border-gray-50">
    <div class="border border-gray-100 rounded-2xl p-4 flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <i class="fa-solid fa-video text-gray-300 text-lg shrink-0"></i>
            <span class="text-sm text-gray-500">Los servicios con video reciben más contactos</span>
        </div>
        <a href="/app/editar_servicio.php?id=<?= $id ?>#seccion-video" class="text-[11px] font-bold text-[#54A6D8] hover:text-blue-700 bg-blue-50 px-3 py-1.5 rounded-full transition-colors shrink-0">
            <i class="fa-solid fa-pen-to-square mr-1"></i> Agregar video
        </a>
    </div>
</div>
<?php endif; ?>

                   <div class="mt-8 pt-8 border-t border-gray-50">
    <h3 class="font-bold text-gray-900 mb-5">Lo que incluye este servicio</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-y-4 gap-x-6">
        <div class="flex items-start gap-3">
            <svg class="text-gray-400 mt-1 w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0H3" />
            </svg>
            <div>
                <p class="text-sm font-bold text-gray-800">Clase 100% online en Nubira</p>
                <p class="text-[11px] text-gray-500">Sin Meet, Zoom ni Teams. Aula virtual integrada en la plataforma.</p>
            </div>
        </div>
        <div class="flex items-start gap-3">
            <svg class="text-gray-400 mt-1 w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
            </svg>
            <div>
                <p class="text-sm font-bold text-gray-800">Chat anónimo antes de contratar</p>
                <p class="text-[11px] text-gray-500">Conversa con el tutor sin compartir WhatsApp ni redes sociales. Cuidamos tu privacidad.</p>
            </div>
        </div>
        <div class="flex items-start gap-3">
            <svg class="text-gray-400 mt-1 w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
            </svg>
            <div>
                <p class="text-sm font-bold text-gray-800">Horarios publicados por el tutor</p>
                <p class="text-[11px] text-gray-500">Reserva con días de anticipación según su disponibilidad.</p>
            </div>
        </div>
        <div class="flex items-start gap-3">
            <svg class="text-gray-400 mt-1 w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
            <div>
                <p class="text-sm font-bold text-gray-800">Garantía Nubira</p>
                <p class="text-[11px] text-gray-500">Tu pago queda protegido hasta confirmar la clase.</p>
            </div>
        </div>
    </div>
</div>
                    <?php if ($tiene_horarios): ?>
                    <div class="mt-8 pt-8 border-t border-gray-50">
                        <div class="flex items-center justify-between mb-5">
                            <div class="flex items-center gap-2">
                                <h3 class="font-bold text-gray-900">Disponibilidad</h3>
                                <span class="text-[10px] text-gray-400 font-medium bg-gray-50 px-2 py-0.5 rounded-full border border-gray-100">Referencial</span>
                            </div>
                           <?php if ($es_propietario): ?>
    <a href="/app/editar_horarios.php?id=<?= $id ?>" class="text-[11px] font-bold text-[#54A6D8] hover:text-blue-700 bg-blue-50 px-3 py-1.5 rounded-full transition-colors">
        <i class="fa-solid fa-pen-to-square mr-1"></i> Editar mis horarios
    </a>
<?php endif; ?>
                        </div>
                        <?php $cantidad_dias = count($dias_disponibles); ?>
    <div class="mb-4 flex items-center gap-2">
        <div class="inline-flex items-center gap-1.5 bg-emerald-50 border border-emerald-100 rounded-full px-3 py-1">
            <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
            <span class="text-[11px] font-bold text-emerald-700">
                Disponible <?= $cantidad_dias ?> día<?= $cantidad_dias > 1 ? 's' : '' ?> a la semana
            </span>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
        <?php foreach ($dias_disponibles as $dia => $bloques):
            $es_proximo = ($dia === $dia_proximo);
        ?>
            <div class="bg-white border <?= $es_proximo ? 'border-[#54A6D8] shadow-md ring-2 ring-blue-100' : 'border-blue-100 shadow-sm' ?> rounded-xl p-3 hover:shadow-md hover:border-[#54A6D8] transition-all group relative">

                <?php if ($es_proximo): ?>
                    <span class="absolute -top-2 -right-2 bg-[#54A6D8] text-white text-[9px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full shadow-sm">
                        Próximo
                    </span>
                <?php endif; ?>

                <p class="text-xs font-extrabold <?= $es_proximo ? 'text-[#54A6D8]' : 'text-gray-800' ?> mb-2 group-hover:text-[#54A6D8] transition-colors">
                    <?= $dia ?>
                </p>

                <div class="flex flex-col gap-1.5">
                    <?php foreach ($bloques as $h): ?>
                        <span class="bg-blue-50 text-[#54A6D8] text-[10px] font-bold px-2 py-1 rounded-md text-center border border-blue-100/50 truncate">
                            <?= htmlspecialchars($h) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
                    </div>
                    <?php else: ?>
                    <div class="mt-8 pt-8 border-t border-gray-50 rounded-2xl">
                        <div class="border border-gray-100 rounded-2xl p-4 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <i class="fa-regular fa-calendar-days text-gray-300 text-lg shrink-0"></i>
                                <span class="text-sm text-gray-500">Coordina directo con <span class="font-semibold text-gray-800"><?= htmlspecialchars($nombrePub) ?></span> por chat</span>
                            </div>
                            <?php if ($es_propietario): ?>
                            <a href="/app/editar_horarios.php?id=<?= $id ?>" class="text-[11px] font-bold text-[#54A6D8] hover:text-blue-700 bg-blue-50 px-3 py-1.5 rounded-full transition-colors shrink-0">
                                <i class="fa-solid fa-pen-to-square mr-1"></i> Agregar horarios
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    </div>
                
                <div class="bg-white border border-gray-100 rounded-2xl p-6 shadow-sm">
             <h3 class="font-bold text-gray-900 mb-6 flex gap-2 items-center">Opiniones <?php if ($tot_votos > 0): ?><span id="badge-votos" class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full text-xs"><?= $tot_votos ?></span><?php endif; ?></h3>
                    <?php if (count($coms) > 0): ?>
                        <div class="flex gap-4 overflow-x-auto pb-4 snap-x snap-mandatory no-scrollbar">
                            <?php foreach ($coms as $c): 
                                $cn_raw = trim($c['n'] ?? 'Usuario');
                                $partes = array_values(array_filter(explode(' ', $cn_raw)));
                                $cn = !empty($partes[0]) ? ucfirst(strtolower($partes[0])) : 'Usuario';
                                if (count($partes) > 1) {
                                    $cn .= ' ' . strtoupper(substr(end($partes), 0, 1)) . '.';
                                }
                                $ci = strtoupper(substr($cn_raw, 0, 1));
                                
                                $cf = (!empty($c['f'])) ? "/app/perfil/fotos/".htmlspecialchars($c['f']) : "";
                            ?>
<div class="min-w-[85%] md:min-w-[45%] snap-start reseña-card" data-rating="<?= $c['r'] ?>">
                                <div class="bg-gray-50 border border-gray-100 p-4 rounded-xl h-full flex flex-col relative group/resena">
        
        <?php if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin'): ?>
            <button onclick="borrarOpinionUI(this, <?= $c['c_id'] ?>, 'contrato')" 
                    class="absolute top-2 right-2 text-gray-300 hover:text-red-500 transition-all p-2 rounded-full hover:bg-red-50 active:scale-95 z-10 opacity-0 group-hover/resena:opacity-100 focus:opacity-100" 
                    title="Eliminar reseña (Modo Admin)">
                <i class="fa-solid fa-trash-can text-sm"></i>
            </button>
        <?php endif; ?>

        <div class="flex items-center gap-3 mb-2">
            <div class="w-8 h-8 rounded-full bg-white border border-gray-200 overflow-hidden"><?php if($cf): ?><img src="<?= $cf ?>" class="w-full h-full object-cover"><?php else: ?><div class="w-full h-full flex items-center justify-center text-[#54A6D8] bg-blue-50 font-bold text-xs"><?= $ci ?></div><?php endif; ?></div>
            <div><p class="font-bold text-xs text-gray-900"><?= htmlspecialchars($cn) ?></p><p class="text-[10px] text-gray-400"><?= date('d M Y', strtotime($c['d'])) ?></p></div>
        </div>
        <div class="flex text-yellow-400 text-[10px] mb-2"><?php for($i=0;$i<5;$i++) echo ($i<$c['r'])?'<i class="fa-solid fa-star"></i>':'<i class="fa-regular fa-star text-gray-300"></i>'; ?></div>
        <p class="text-gray-600 text-xs leading-relaxed"><?= nl2br(htmlspecialchars($c['t'])) ?></p>
    </div>
</div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-6 text-gray-400 text-sm italic">Aún no hay opiniones para este servicio.</div>
                    <?php endif; ?>
                </div>

               <div class="flex justify-end px-2 pt-2">
                    <a href="/reportar-servicio?id=<?= $id ?>" class="text-xs font-semibold text-gray-400 hover:text-red-500 transition-colors flex items-center gap-1.5 group">
                        <i class="fa-solid fa-flag text-[10px] group-hover:animate-pulse"></i> Reportar publicación
                    </a>
                </div>

            </div>

            <div class="lg:col-span-4">
                <div class="sticky top-16 space-y-6">
                      <div class="hidden lg:block bg-white rounded-2xl border border-gray-200 p-6">
                        <?php 
// [INYECCIÓN NUBIRA] Lógica de oferta
$is_oferta = oferta_vigente($servicio);
?>
<?php if ($tutor_en_clase): ?>
                            <div class="mb-5 bg-orange-50 border border-orange-100 rounded-xl p-3.5 flex items-start gap-3 shadow-sm animate-fade-in-up">
                                <div class="relative flex h-2.5 w-2.5 mt-1 shrink-0">
                                  <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span>
                                  <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-orange-500 border border-white"></span>
                                </div>
                                <div>
                                    <h4 class="font-bold text-orange-800 text-[13px] leading-none mb-1">El tutor está en clase</h4>
                                    <p class="text-[11px] text-orange-700 font-medium leading-tight">Puedes reservar ahora, pero su respuesta podría demorar unos minutos hasta que termine su sesión actual.</p>
                                </div>
                            </div>
                        <?php endif; ?>
<div class="mb-6 border-b border-gray-100 pb-4 relative">
    <p class="text-xs text-gray-500 font-bold uppercase mb-1">Inversión total</p>
    
    <?php if($is_oferta): ?>
          <div class="absolute top-0 right-0 bg-amber-100 text-amber-900 text-[10px] font-semibold px-2.5 py-1 rounded-full border border-amber-200 z-10">
              Solo <?= (int)$servicio['cupos_oferta'] ?> <?= (int)$servicio['cupos_oferta'] === 1 ? 'cupo' : 'cupos' ?>
          </div>
        <div class="flex flex-col gap-0.5" id="precio-block">
            <span class="text-sm text-gray-400 line-through font-medium">Normal $<?= number_format($servicio['precio'], 0, ',', '.') ?></span>
            <div class="flex items-baseline gap-2">
                  <span class="text-4xl font-black text-gray-900 tracking-tight leading-none">$<?= number_format($servicio['precio_oferta'], 0, ',', '.') ?></span>
                  <?php $pct_det = (int)round(($servicio['precio'] - $servicio['precio_oferta']) / $servicio['precio'] * 100); ?>
                  <?php if ($pct_det > 0): ?><span class="bg-green-600 text-white text-xs font-semibold px-1.5 py-0.5 rounded ml-1 align-middle">-<?= $pct_det ?>%</span><?php endif; ?>
            </div>
            <?php if (!empty($servicio['oferta_termino'])): ?>
                <?php
                    $dias = (int)((strtotime($servicio['oferta_termino']) - strtotime(date('Y-m-d'))) / 86400);
                    if ($dias > 30)     $txt_term = 'Oferta hasta el ' . date('d/m', strtotime($servicio['oferta_termino']));
                    elseif ($dias >= 2) $txt_term = 'Termina en ' . $dias . ' días';
                    elseif ($dias == 1) $txt_term = 'Termina mañana';
                    elseif ($dias == 0) $txt_term = 'Termina hoy';
                    else                $txt_term = null;
                ?>
                <?php if ($txt_term !== null): ?><p class="text-xs text-gray-500 mt-1"><?= $txt_term ?></p><?php endif; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="flex items-baseline gap-2" id="precio-block">
            <span class="text-4xl font-extrabold text-gray-900 tracking-tight">$<?= number_format($servicio['precio'], 0, ',', '.') ?></span>
            <span class="text-xs font-bold bg-gray-100 px-2 py-1 rounded text-gray-600"><?= strtoupper($servicio['modalidad']) ?></span>
        </div>
    <?php endif; ?>
</div>
                        <div class="space-y-3">
                            <?php if ($contrato): ?>
                                <a href="/app/mini_aula.php?id=<?= (int)$contrato['id'] ?>" class="block w-full bg-green-600 text-white font-bold py-3 rounded-xl text-center hover:bg-green-700 transition shadow-md">Ir al Aula Virtual</a>
                            <?php elseif ($uid == $servicio['alumno_id']): ?>
                                <a href="/app/editar_servicio.php?id=<?= $id ?>" class="block w-full bg-gray-100 text-gray-700 font-bold py-3 rounded-xl text-center hover:bg-gray-200 transition">Editar Servicio</a>
                            <?php else: ?>
                                <?php if (!$logueado): ?>
                                    <a href="/app/iniciar_chat.php?servicio_id=<?= $id ?>"
                                       class="mt-1 w-full bg-[#54A6D8] text-white font-bold rounded-xl text-sm px-5 py-3.5 hover:bg-blue-600 transition-all shadow-md active:scale-95 flex items-center justify-center gap-2"
                                       data-track-click="contact:mensaje_visitante">
                                       <?= icon('chat-outline', 'w-5 h-5') ?>
                                       <span>Iniciar chat</span>
                                    </a>
                                    <div class="flex items-center gap-2 my-3">
                                        <div class="flex-1 h-px bg-gray-200"></div>
                                        <span class="text-xs text-gray-400 font-medium">o</span>
                                        <div class="flex-1 h-px bg-gray-200"></div>
                                    </div>
                                    <div class="p-4 bg-gray-50 rounded-xl text-center border border-gray-200">
                                        <p class="text-xs text-gray-600 mb-3 font-semibold">¿Ya tienes cuenta?</p>
                                        <a href="/login?redir=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="block w-full bg-white text-gray-700 border border-gray-300 font-bold text-sm py-2.5 rounded-xl hover:bg-gray-100 transition mb-2">Ingresar ahora</a>
                                        <a href="/registro?redir=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="block w-full text-[#54A6D8] font-bold text-xs hover:underline">¿No tienes cuenta? Regístrate gratis</a>
                                    </div>
                                <?php else: ?>
                                 <!-- [NUBIRA 2.0] Oculto en móvil (usa la barra flotante) -->
<form id="form-pago-principal" action="/app/contratar_servicio.php" method="GET" class="hidden lg:block">
    <input type="hidden" name="servicio_id" value="<?= $id ?>">
    <input type="hidden" name="codigo_beca" id="codigo_beca_hidden" value="">
    
    <?php if($is_oferta): ?>
          <button type="submit" id="btn-submit-pago" class="w-full text-white bg-[#54A6D8] hover:bg-blue-600 font-bold rounded-xl text-sm px-5 py-3.5 text-center transition-all active:scale-95 flex items-center justify-center"
                  data-track-click="contact:contratar_oferta">
              <span id="txt-btn-pago">Contratar por $<?= number_format($servicio['precio_oferta'], 0, ',', '.') ?></span>
          </button>
    <?php else: ?>
          <button type="submit" id="btn-submit-pago" class="w-full text-white bg-[#54A6D8] hover:bg-blue-600 font-bold rounded-xl text-sm px-5 py-3.5 text-center transition-all active:scale-95"
                data-track-click="contact:contratar">
            <span id="txt-btn-pago">Contratar Servicio</span>
        </button>
    <?php endif; ?>
</form>

<!-- [NUBIRA 2.0] Enrutador Inteligente SSOT (Reemplazó al form-contactar viejo) -->
<a href="/app/iniciar_chat.php?servicio_id=<?= $id ?>"
   class="mt-3 w-full bg-white text-[#54A6D8] border-2 border-[#54A6D8] font-bold rounded-xl text-sm px-5 py-3 hover:bg-blue-50 transition-all shadow-sm active:scale-95 flex items-center justify-center gap-2"
   data-track-click="contact:mensaje">
   <?= icon('chat-outline', 'w-5 h-5') ?>
   <span>Iniciar chat</span>
</a>

<div class="mt-4 border-t border-gray-100 pt-4">
    <button type="button" id="btn-toggle-cupon" class="text-xs font-bold text-gray-400 hover:text-[#54A6D8] transition-colors flex items-center gap-1.5 w-full justify-center group">
        <?= icon('ticket', 'group-hover:rotate-12 transition-transform') ?> ¿Tienes un código de beca?
    </button>
    
    <div id="box-cupon" class="hidden mt-4 transition-all duration-300 opacity-0 -translate-y-2">
        <div class="flex flex-col gap-2">
            <div class="relative w-full">
                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-[10px]">
                    <?= icon('ticket') ?>
                </span>
                <input type="text" id="input-cupon" placeholder="Ingresa tu código" 
                       class="w-full bg-gray-50 border border-gray-100 text-gray-900 text-[16px] md:text-sm rounded-xl pl-9 pr-3 py-3 focus:border-[#54A6D8] focus:bg-white focus:ring-2 focus:ring-[#54A6D8]/20 outline-none uppercase font-bold transition-all placeholder:font-normal placeholder:normal-case placeholder:text-gray-400">
            </div>
            <button type="button" id="btn-aplicar-cupon" class="w-full bg-slate-900 text-white text-[11px] uppercase tracking-widest font-extrabold px-4 py-3 rounded-xl transition-all shadow-sm hover:bg-slate-800 active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                <span id="texto-btn-cupon">Validar Beca</span>
                <svg id="spinner-cupon" class="animate-spin h-3.5 w-3.5 text-white hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            </button>
        </div>
        <div id="msg-cupon" class="hidden mt-3 p-3 rounded-xl text-xs font-bold flex items-start gap-2 transition-all duration-300">
        </div>
    </div>
</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    

                    <div class="mt-8 bg-slate-50 border border-slate-100 rounded-2xl p-5 flex gap-4 items-start shadow-sm">
                        <div class="w-10 h-10 rounded-full bg-blue-100 text-[#54A6D8] flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-shield-halved text-lg"></i>
                        </div>
                        <div>
                            <h4 class="font-bold text-slate-800 text-xs uppercase tracking-wider mb-1">Pago Protegido</h4>
                            <p class="text-[11px] text-slate-500 leading-relaxed font-medium">
                                Tu dinero está seguro. El pago se retiene en nuestra plataforma y solo se libera al estudiante cuando confirmas que la clase o servicio se realizó con éxito.
                            </p>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        
      <?php if ($recs && $recs->num_rows > 0): ?>
        <div class="mt-16 border-t border-gray-100 pt-10">
            <h2 class="text-xl font-bold text-gray-900 mb-6">Otros tutores disponibles ahora</h2>
            
            <div class="relative group">
                
                <button onclick="scrollCarrusel('carrusel-recomendados', -1)" class="hidden md:flex absolute -left-5 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-lg items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-gray-200 transition">
                    <i class="fa-solid fa-chevron-left text-xs"></i>
                </button>

            <div id="carrusel-recomendados" class="flex gap-4 overflow-x-auto pb-6 px-1 no-scrollbar snap-x snap-proximity scroll-smooth">
    <?php while ($r = $recs->fetch_assoc()): 
        $ir = url_portada($r); // [BANCO] banco → legacy → placeholder
        
        // Calcular estrellas y votos
        $rating_val = isset($r['rating_promedio']) ? (float)$r['rating_promedio'] : 0;
        $total_v = isset($r['total_votos']) ? (int)$r['total_votos'] : 0;
        
        $html_stars = '';
        if ($total_v > 0) {
            $html_stars = '<div class="flex items-center gap-1 bg-gray-50 px-1.5 py-0.5 rounded border border-gray-100">
                <svg class="w-3 h-3 text-gray-900 pb-[1px]" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
                <span class="text-[10px] font-bold text-gray-800 leading-none">'.number_format($rating_val, 1).'</span>
            </div>';
        } else {
            $html_stars = '';
        }
        
        // Institución: mismo helper compartido que usa vitrina.php (institucion_tutor ya devuelve texto escapado)
        $inst_text = institucion_tutor($r['institucion_maestra'] ?? '');

        // --- Tier del tutor (idéntico a vitrina.php / card_servicio_grid.php) ---
        $score = (int)($r['score_nubira'] ?? 0);
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

        // --- Oferta vigente (idéntico a vitrina.php) ---
        $es_oferta = oferta_vigente($r);
        $pct_descuento = ($es_oferta && (int)$r['precio'] > 0)
            ? round(((int)$r['precio'] - (int)$r['precio_oferta']) / (int)$r['precio'] * 100)
            : 0;

        // --- Avatar y nombre del tutor para el overlay (idéntico a vitrina.php) ---
        $nombre_completo = !empty($r['nombre_tutor']) ? $r['nombre_tutor'] : 'Profesor';
        $partes_nombre = array_values(array_filter(explode(' ', trim((string)$nombre_completo))));
        $tutor_nombre = "Profesor";
        if (!empty($partes_nombre[0])) {
            $tutor_nombre = ucwords(strtolower($partes_nombre[0]));
            if (count($partes_nombre) >= 2) {
                $tutor_nombre .= ' ' . strtoupper(substr($partes_nombre[count($partes_nombre)-1], 0, 1)) . '.';
            }
        }
        $foto_tutor = !empty($r['foto_perfil'])
            ? '/app/perfil/fotos/' . $r['foto_perfil']
            : "https://ui-avatars.com/api/?name=" . urlencode($tutor_nombre) . "&background=54A6D8&color=fff&size=128&bold=true";

        $categoria_overlay = $r['categoria'] ?? 'Otros';
        $prefijo_overlay = in_array($categoria_overlay, ['Otros','Asesoría']) ? '' : 'Clase de';
        $nombre_categoria_overlay = ($categoria_overlay === 'Otros') ? 'Clase' : $categoria_overlay;
    ?>
        <a href="<?= url_servicio((int)$r['id'], $r['slug'] ?? null) ?>" class="block flex flex-col cursor-pointer group snap-start shrink-0 w-[220px] md:w-[240px] bg-transparent h-full <?= $es_basico ? 'opacity-90 grayscale-[15%]' : '' ?>" data-track-click="rec:<?= $r['id'] ?>">
            <div class="relative w-full aspect-[4/3] bg-gray-100 overflow-hidden rounded-xl border border-gray-200 transition-all">
                <img src="<?= htmlspecialchars($ir) ?>" alt="<?= htmlspecialchars($r['titulo']) ?>" loading="lazy" decoding="async" width="240" height="180" class="w-full h-full object-cover group-hover:scale-105 transition duration-500" onerror="this.onerror=null;this.src='<?= $default_image ?>'">

                <?php
                $ov_prefijo   = $prefijo_overlay;
                $ov_categoria = $nombre_categoria_overlay;
                $ov_foto      = $foto_tutor;
                $ov_nombre    = $tutor_nombre;
                $ov_size      = 'lg';
                include __DIR__ . '/componentes/overlay_card_servicio.php';
                ?>

                <?php if (empty($es_oferta) && !empty($nivel_tutor)): ?>
                <div class="absolute top-1 right-1 z-10">
                    <?php $nivel_label = ['leyenda'=>'Leyenda','elite'=>'Élite','pro'=>'Pro','top'=>'Top'][$nivel_tutor] ?? ''; ?>
                    <?php if ($nivel_label): ?>
                    <span class="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-semibold bg-white/95 backdrop-blur-sm text-gray-900 border border-gray-200"><?= $nivel_label ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($es_oferta): ?>
                <div class="absolute top-1 right-1 z-10">
                    <span class="inline-flex items-center px-1.5 py-0 md:px-2 md:py-0.5 rounded-full text-[9px] md:text-[10px] font-semibold bg-amber-100 text-amber-900 border border-amber-200">
                        <?= (int)$r['cupos_oferta'] ?> <?= (int)$r['cupos_oferta'] === 1 ? 'cupo' : 'cupos' ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <div class="pt-2.5 flex flex-col flex-1 text-left">
                <h3 class="font-semibold text-[14px] leading-snug text-gray-900 line-clamp-2 mb-1 min-h-[40px]">
                    <?= htmlspecialchars($r['titulo']) ?>
                </h3>

                <div class="text-[14px] mt-auto mb-1.5 leading-none">
                    <?php if ($es_oferta): ?>
                        <span class="text-[11px] text-gray-400 line-through font-medium mr-1">$<?= number_format($r['precio'], 0, ',', '.') ?></span>
                        <span class="text-gray-700 font-semibold tracking-tight">$<?= number_format($r['precio_oferta'], 0, ',', '.') ?></span>
                        <?php if ($pct_descuento > 0): ?><span class="bg-green-600 text-white text-[9px] font-semibold px-1 py-px rounded ml-1.5 leading-none relative -top-0.5">-<?= $pct_descuento ?>%</span><?php endif; ?>
                    <?php else: ?>
                        <span class="text-gray-700 font-semibold tracking-tight">$<?= number_format($r['precio'], 0, ',', '.') ?></span>
                    <?php endif; ?>
                </div>

                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-1.5 text-[10px] text-gray-400 font-bold uppercase tracking-wide truncate max-w-[65%]">
                        <span class="truncate"><?= $inst_text ?></span>
                    </div>
                    <div class="shrink-0 flex items-center gap-1">
                        <?= $html_stars ?>
                    </div>
                </div>
            </div>
        </a>
    <?php endwhile; ?>
</div>

                <button onclick="scrollCarrusel('carrusel-recomendados', 1)" class="hidden md:flex absolute -right-5 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-lg items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-gray-200 transition">
                    <i class="fa-solid fa-chevron-right text-xs"></i>
                </button>
                
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php 
// =========================================================================
// [NUBIRA 2.0] BARRA INFERIOR FIJA DE CONTRATACIÓN (MÓVIL)
// Solo visible en pantallas < md. Reutiliza estados ya calculados.
// =========================================================================
$mostrar_barra_movil = (
    !$es_propietario && 
    !$contrato && 
    $servicio['estado'] === 'aprobado'
);
?>

<?php if ($mostrar_barra_movil): ?>
<div id="barra-contratar-movil"
     class="lg:hidden fixed bottom-0 left-0 right-0 bg-white/95 backdrop-blur-md border-t border-gray-100 shadow-[0_-4px_12px_rgba(0,0,0,0.04)] z-40 px-4 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))]">
    
    <div class="flex items-center justify-between gap-3">

        <div class="flex flex-col min-w-0 flex-1">
            <?php if ($is_oferta): ?>
                <div class="flex items-center gap-1.5">
                    <span class="text-[10px] text-gray-400 line-through font-medium leading-none">
                        $<?= number_format($servicio['precio'], 0, ',', '.') ?>
                    </span>
                    <?php if ((int)$servicio['cupos_oferta'] > 0): ?>
                        <span class="inline-flex items-center text-[10px] font-semibold bg-amber-100 text-amber-900 border border-amber-200 rounded-full px-1.5 py-px leading-none">
                            Solo <?= (int)$servicio['cupos_oferta'] ?> <?= (int)$servicio['cupos_oferta'] === 1 ? 'cupo' : 'cupos' ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="flex items-baseline gap-1.5 mt-0.5">
    <span id="precio-movil-oferta" class="text-xl font-black text-gray-900 tracking-tight leading-none">
        $<?= number_format($servicio['precio_oferta'], 0, ',', '.') ?>
    </span>
    <?php if (isset($pct_det) && $pct_det > 0): ?><span class="bg-green-600 text-white text-[10px] font-semibold px-1 py-px rounded ml-1 leading-none relative -top-0.5">-<?= $pct_det ?>%</span><?php endif; ?>
</div>
<?php if (isset($txt_term) && $txt_term !== null): ?><p class="text-xs text-gray-500 leading-none mt-0.5"><?= $txt_term ?></p><?php endif; ?>
<?php else: ?>
<span class="text-[10px] text-gray-400 font-bold uppercase tracking-wide leading-none">Inversión total</span>
<span id="precio-movil-main" class="text-xl font-extrabold text-gray-900 tracking-tight leading-none mt-0.5">
    $<?= number_format($servicio['precio'], 0, ',', '.') ?>
</span>
            <?php endif; ?>
        </div>

        <?php if (!$logueado): ?>
            <div class="flex gap-2 shrink-0">
                <a href="/app/iniciar_chat.php?servicio_id=<?= $id ?>"
                   class="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl px-4 py-3 text-sm shadow-md active:scale-95 transition-all whitespace-nowrap flex items-center gap-1.5"
                   data-track-click="contact:mensaje_visitante_movil">
                   <?= icon('chat-outline', 'w-4 h-4') ?>
                   <span>Iniciar chat</span>
                </a>
                <a href="/login?redir=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
                   class="bg-white border border-gray-300 text-gray-700 font-bold rounded-xl px-4 py-3 text-sm active:scale-95 transition-all whitespace-nowrap">
                    Ingresar
                </a>
            </div>
        <?php else: ?>
            <div class="flex gap-2 shrink-0">
                <a href="/app/iniciar_chat.php?servicio_id=<?= $id ?>"
                   class="border border-[#54A6D8] bg-white text-[#54A6D8] font-bold rounded-xl px-3 py-3 text-xs whitespace-nowrap active:scale-95 transition-all flex items-center justify-center gap-1.5"
                   data-track-click="contact:mensaje_movil">
                   <?= icon('chat-outline', 'w-4 h-4') ?>
                   <span>Iniciar chat</span>
                </a>
                <button type="button"
                        onclick="document.getElementById('btn-submit-pago')?.click()"
                        class="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl px-4 py-3 text-xs shadow-md active:scale-95 transition-all whitespace-nowrap"
                        data-track-click="contact:contratar_movil">
                    Contratar
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php 
// ----------------------------------------------------
// FOOTER PATH FINDER
// ----------------------------------------------------
$rutas_footer = [
    __DIR__ . '/app/includes/footer.php',
    __DIR__ . '/includes/footer.php',
    __DIR__ . '/componentes/footer.php',
    __DIR__ . '/footer.php',
    $_SERVER['DOCUMENT_ROOT'] . '/app/includes/footer.php',
    $_SERVER['DOCUMENT_ROOT'] . '/footer.php'
];

$footer_encontrado = false;
foreach ($rutas_footer as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        $footer_encontrado = true;
        break;
    }
}

if (!$footer_encontrado): 
?>
    <div style="position:fixed; bottom:0; width:100%; background:red; color:white; padding:10px; text-align:center; z-index:9999;">
        ⚠️ DEBUG: No se encontró footer.php. Verifica la ruta.
    </div>
<?php endif; ?>

<?php 
// INCLUSIÓN OBLIGATORIA DEL ECOSISTEMA MÓVIL
if(file_exists($ruta_comp . '/nav_bottom.php')) require_once $ruta_comp . '/nav_bottom.php'; 
if(file_exists($ruta_comp . '/modal_publicar.php')) require_once $ruta_comp . '/modal_publicar.php';
if(file_exists($ruta_comp . '/modal_explora.php')) require_once $ruta_comp . '/modal_explora.php';
if(file_exists($ruta_comp . '/sheet_compartir_servicio.php'))  require_once $ruta_comp . '/sheet_compartir_servicio.php';
if(file_exists($ruta_comp . '/modal_compartir_servicio.php')) require_once $ruta_comp . '/modal_compartir_servicio.php';
?>

<script src="/assets/js/behavior_tracker.js"></script>

<script>
// [NUBIRA 2.0] Prevenir salto de scroll al recargar
if ('scrollRestoration' in history) {
    history.scrollRestoration = 'manual';
}
window.scrollTo(0, 0);

// [NUBIRA 2.0 UX] Quitar el loader apenas el esqueleto HTML exista (sin esperar imágenes lentas)
document.addEventListener('DOMContentLoaded', function() {
    
    // Forzamos nuevamente el scroll top antes de quitar el loader por seguridad en móviles
    window.scrollTo(0, 0);
    
    const l = document.getElementById('loader');
    if(l) {
        l.classList.add('opacity-0');
        setTimeout(() => l.classList.add('hidden'), 300);
    }
    
    // [PIXEL META] 1. Trackear la vista del servicio (ViewContent)
    if (typeof fbq === 'function') {
        fbq('track', 'ViewContent', {
            content_name: '<?= htmlspecialchars($servicio['titulo'], ENT_QUOTES, 'UTF-8') ?>',
            content_category: '<?= htmlspecialchars($servicio['categoria'], ENT_QUOTES, 'UTF-8') ?>',
            content_ids: ['<?= (int)$servicio['id'] ?>'],
            content_type: 'product',
            value: <?= (int)($is_oferta ? $servicio['precio_oferta'] : $servicio['precio']) ?>,
            currency: 'CLP'
        });
    }

   // --- LÓGICA DE BECAS / CUPONES NUBIRA 2.0 ---
    const btnToggleCupon = document.getElementById('btn-toggle-cupon');
    const boxCupon = document.getElementById('box-cupon');
    const btnAplicarCupon = document.getElementById('btn-aplicar-cupon');
    const inputCupon = document.getElementById('input-cupon');
    const msgCupon = document.getElementById('msg-cupon');
    const hiddenCupon = document.getElementById('codigo_beca_hidden');
    const blockPrecio = document.getElementById('precio-block');
    const txtBtnPago = document.getElementById('txt-btn-pago');
    const txtBtnSub = document.getElementById('txt-btn-sub');
    const textoBtnCupon = document.getElementById('texto-btn-cupon');
    const spinnerCupon = document.getElementById('spinner-cupon');
    // [NUBIRA 2.0] Referencias a la barra móvil
const precioMovilMain = document.getElementById('precio-movil-main');
const precioMovilOferta = document.getElementById('precio-movil-oferta');

    // Variables de precio extraídas de PHP para cálculo JS
    const precioNormal = <?= (int)$servicio['precio'] ?>;
    const precioBaseCalculo = <?= (int)($is_oferta ? $servicio['precio_oferta'] : $servicio['precio']) ?>;
    const isOferta = <?= $is_oferta ? 'true' : 'false' ?>;

    const formatCLP = (num) => new Intl.NumberFormat('es-CL', { style: 'currency', currency: 'CLP', maximumFractionDigits: 0 }).format(num);

    if (btnToggleCupon && boxCupon) {
        // Animación suave del contenedor
        btnToggleCupon.addEventListener('click', () => {
            if (boxCupon.classList.contains('hidden')) {
                boxCupon.classList.remove('hidden');
                // Pequeño delay para que Tailwind aplique la transición
                requestAnimationFrame(() => {
                    boxCupon.classList.remove('opacity-0', '-translate-y-2');
                    inputCupon.focus();
                });
            } else {
                boxCupon.classList.add('opacity-0', '-translate-y-2');
                setTimeout(() => boxCupon.classList.add('hidden'), 300);
            }
        });

       btnAplicarCupon.addEventListener('click', async () => {
            const code = inputCupon.value.trim().toUpperCase();
            if (!code) {
                inputCupon.focus();
                return;
            }

            // UI: Estado de Carga
            btnAplicarCupon.disabled = true;
            inputCupon.disabled = true;
            textoBtnCupon.textContent = 'Procesando...';
            spinnerCupon.classList.remove('hidden');
            msgCupon.classList.add('hidden', 'opacity-0');

            try {
                // [NUBIRA 2.0 FIX] 
                // 1. encodeURIComponent previene caracteres inválidos
                // 2. Consistencia con el backend
                const urlFetch = `/app/validar_cupon.php?codigo_beca=${encodeURIComponent(code)}&servicio_id=<?= (int)$id ?>`;
                
                const res = await fetch(urlFetch);
                if (!res.ok) throw new Error('Error HTTP: ' + res.status);
                
                // ESCUDO NUBIRA: Leemos como texto primero para capturar "basura" de PHP
                const textResponse = await res.text();
                let data;
                try {
                    data = JSON.parse(textResponse);
                } catch (parseError) {
                    console.error("❌ El backend no devolvió JSON válido. Respuesta cruda recibida:\n", textResponse);
                    throw new Error("Respuesta inválida del servidor");
                }

                msgCupon.className = `mt-3 p-3 rounded-xl text-xs font-bold flex items-start gap-2 transition-all duration-300 transform opacity-100 ${data.valido ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-rose-50 text-rose-700 border border-rose-100'}`;

                if (data.valido) {
                    msgCupon.innerHTML = `<i class="fa-solid fa-circle-check mt-0.5"></i> <span>${data.mensaje}</span>`;
                    hiddenCupon.value = code; 
                    
                    // UX: Transformar botón
                    btnAplicarCupon.classList.replace('bg-slate-900', 'bg-emerald-500');
                    btnAplicarCupon.classList.replace('hover:bg-slate-800', 'hover:bg-emerald-600');
                    textoBtnCupon.textContent = 'Beca Activada';
                    
                    // Cálculo matemático blindado
                    const descuentoPorcentaje = parseInt(data.descuento, 10) || 0;
                    const montoDescuento = (precioBaseCalculo * descuentoPorcentaje) / 100;
                    const totalPagar = Math.max(0, precioBaseCalculo - montoDescuento); // Evita números negativos

                    // Renderizado del DOM (Smooth)
                    blockPrecio.innerHTML = `
                        <div class="flex flex-col gap-1 w-full animate-fade-in">
                            <div class="flex justify-between items-center text-sm text-gray-500">
                                <span>Subtotal</span>
                                <span class="font-bold line-through">${formatCLP(precioBaseCalculo)}</span>
                            </div>
                            <div class="flex justify-between items-center text-sm text-emerald-500 font-bold bg-emerald-50/50 p-1.5 rounded-lg border border-emerald-100/50">
                                <span>Beca Nubira (${descuentoPorcentaje}%)</span>
                                <span>-${formatCLP(montoDescuento)}</span>
                            </div>
                            <div class="flex justify-between items-end mt-2 pt-2 border-t border-gray-100">
                                <span class="text-xs font-bold uppercase text-gray-400">Total a pagar</span>
                                <span class="text-4xl font-black text-emerald-500 tracking-tight leading-none">${formatCLP(totalPagar)}</span>
                            </div>
                        </div>
                    `;
                    
                    // Actualizar Botón de Compra
                    if(txtBtnPago) txtBtnPago.innerText = totalPagar <= 0 ? "Canjear Beca 100% Gratis" : `Pagar ${formatCLP(totalPagar)}`;
                    if(txtBtnSub) txtBtnSub.style.display = 'none';
                    
                    const btnSubmitPago = document.getElementById('btn-submit-pago');
                    if(btnSubmitPago) btnSubmitPago.className = "w-full text-white bg-gradient-to-r from-emerald-500 to-emerald-600 hover:to-emerald-700 font-bold rounded-xl text-sm px-5 py-3.5 text-center shadow-lg transform active:scale-95 transition-all shadow-emerald-500/30 flex flex-col items-center justify-center gap-0.5 animate-pulse";
                    
                    // [NUBIRA 2.0] Sincronizar Barra Móvil Abajo en Tiempo Real
                    if (precioMovilMain) {
                        precioMovilMain.innerText = formatCLP(totalPagar);
                        precioMovilMain.classList.replace('text-gray-900', 'text-emerald-500');
                    }
                    if (precioMovilOferta) {
                        precioMovilOferta.innerText = formatCLP(totalPagar);
                        precioMovilOferta.classList.replace('text-[#54A6D8]', 'text-emerald-500');
                    }

                } else {
                    msgCupon.innerHTML = `<i class="fa-solid fa-triangle-exclamation mt-0.5"></i> <span>${data.mensaje}</span>`;
                    hiddenCupon.value = '';
                    
                    // Restaurar UI para permitir reintento
                    btnAplicarCupon.disabled = false;
                    inputCupon.disabled = false;
                    textoBtnCupon.textContent = 'Reintentar';
                }
            } catch (e) {
                console.error("🚨 Error en validación de cupón:", e.message);
                msgCupon.className = 'mt-3 p-3 rounded-xl text-xs font-bold flex items-start gap-2 bg-rose-50 text-rose-700 border border-rose-100 transition-all duration-300 opacity-100';
                msgCupon.innerHTML = '<i class="fa-solid fa-triangle-exclamation mt-0.5"></i> <span>Hubo un problema técnico. Abre la consola (F12) para ver el error exacto de PHP.</span>';
                
                btnAplicarCupon.disabled = false;
                inputCupon.disabled = false;
                textoBtnCupon.textContent = 'Validar Beca';
            } finally {
                spinnerCupon.classList.add('hidden');
                msgCupon.classList.remove('hidden');
            }
        });

        // Permitir enter en el input
        inputCupon.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                btnAplicarCupon.click();
            }
        });
    }

    // --- LÓGICA DE MODALES NUBIRA 2.0 ---
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

    setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
    setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
    

    // --- COPIAR ENLACE ---
    const bc = document.getElementById('btn-copiar-enlace');
    if(bc) {
        bc.addEventListener('click', function(e) {
            e.preventDefault();
            if(navigator.clipboard) {
                navigator.clipboard.writeText(this.getAttribute('data-url')).then(() => {
                    const i = document.getElementById('copy-icon');
                    if(i) { i.className = 'fa-solid fa-check text-green-500'; setTimeout(() => i.className = 'fas fa-link', 2000); }
                });
            } else {
                alert("Tu navegador no soporta copiar al portapapeles automáticamente.");
            }
        });
    }

    // [PIXEL META] 3. Trackear intención de pago (InitiateCheckout)
    const formContratar = document.getElementById('form-pago-principal');
    if (formContratar) {
        formContratar.addEventListener('submit', function(e) {
            e.preventDefault(); 
            const form = this;
            const btn = document.getElementById('btn-submit-pago');
            
            if (btn) {
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Cargando...';
                btn.disabled = true;
            }

            if (typeof fbq === 'function') {
                fbq('track', 'InitiateCheckout', {
                    content_name: '<?= htmlspecialchars($servicio['titulo'], ENT_QUOTES, 'UTF-8') ?>',
                    content_ids: ['<?= (int)$servicio['id'] ?>'],
                    value: <?= (int)($is_oferta ? $servicio['precio_oferta'] : $servicio['precio']) ?>,
                    currency: 'CLP',
                    num_items: 1
                });
            }
            
            setTimeout(() => {
                HTMLFormElement.prototype.submit.call(form);
            }, 300);
        });
    }
});

// Función global para mover los carruseles
window.scrollCarrusel = (id, dir) => { 
    const c = document.getElementById(id); 
    if(c) c.scrollBy({ left: dir * 300, behavior: 'smooth' }); 
};

// [NUBIRA 2.0] SMART BACK: Previene bucles infinitos con pasarelas de pago
window.navegacionSeguraNubira = function() {
    let ref = document.referrer.toLowerCase();
    
    // Si el usuario viene de un error de pago, checkout, o la pasarela, 
    // forzamos la salida segura a la vitrina para romper el bucle.
    if (ref.includes('mercadopago') || ref.includes('pago_error') || ref.includes('contratar_servicio') || ref.includes('iniciar_pago')) {
        window.location.href = '/vitrina';
    } 
    // Si la navegación es limpia, usamos el historial normal
    else if (window.history.length > 1) {
        window.history.back();
    } 
    // Fallback por defecto
    else {
        window.location.href = '/vitrina';
    }
};

<?php if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin'): ?>
// Función global Admin para borrar reseñas
window.borrarOpinionUI = async function(botonDom, id, tipo) {
    if (!confirm('🛡️ MODO ADMIN:\n¿Seguro que deseas eliminar esta reseña permanentemente?')) return;

    const cardResena = botonDom.closest('.reseña-card');
    const originalHtml = botonDom.innerHTML;
    
    botonDom.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-sm text-red-400"></i>';
    botonDom.disabled = true;

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('tipo', tipo);

        const response = await fetch('/app/eliminar_opinion.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            cardResena.style.transition = "all 0.4s ease";
            cardResena.style.opacity = "0";
            cardResena.style.transform = "scale(0.95)";
            
            setTimeout(() => {
                cardResena.remove();
                
                // [NUBIRA 2.0] UX Reactiva: Recalcular promedio y votos en el DOM
                const tarjetasRestantes = document.querySelectorAll('.reseña-card');
                let totalVotos = tarjetasRestantes.length;
                let totalEstrellas = 0;
                
                tarjetasRestantes.forEach(card => {
                    totalEstrellas += parseFloat(card.getAttribute('data-rating')) || 0;
                });
                
                let nuevoPromedio = totalVotos > 0 ? (totalEstrellas / totalVotos).toFixed(1) : 0;
                
                // Actualizar UI instantáneamente sin recargar la página
                const valPromedio = document.getElementById('val-promedio');
                const valVotosTxt = document.getElementById('val-votos-txt');
                const badgeVotos = document.getElementById('badge-votos');
                const valBullet = document.getElementById('val-bullet');
                
                if(valPromedio) valPromedio.innerText = totalVotos > 0 ? nuevoPromedio : 'Nuevo';
                if(badgeVotos) badgeVotos.innerText = totalVotos;
                
                if(totalVotos > 0) {
                    if(valVotosTxt) valVotosTxt.innerText = totalVotos + (totalVotos === 1 ? ' reseña' : ' reseñas');
                } else {
                    if(valBullet) valBullet.style.display = 'none';
                    if(valVotosTxt) valVotosTxt.style.display = 'none';
                    const container = document.querySelector('.snap-x');
                    if(container) {
                        container.innerHTML = '<div class="text-center py-6 text-gray-400 text-sm italic w-full">Aún no hay opiniones para este servicio.</div>';
                        container.classList.remove('flex', 'gap-4', 'overflow-x-auto', 'snap-x', 'snap-mandatory');
                    }
                }
            }, 400);
        } else {
            alert('Error: ' + (data.error || 'No se pudo eliminar'));
            botonDom.innerHTML = originalHtml;
            botonDom.disabled = false;
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error de conexión al intentar eliminar.');
        botonDom.innerHTML = originalHtml;
        botonDom.disabled = false;
    }
};
<?php endif; ?>
</script>

<!-- [NUBIRA TRACKER] Engagement tracking - NO modificar sin revisar track_vista.php -->
<script>
(function() {
    var TIPO = 'servicio';
    var PUB_ID = <?= (int)($servicio['id'] ?? 0) ?>;
    if (!PUB_ID) return;

    var SK = 'nubira_sid';
    var sid = localStorage.getItem(SK);
    if (!sid || sid.length < 10) {
        sid = Date.now() + '-' + Math.random().toString(36).slice(2, 10);
        localStorage.setItem(SK, sid);
    }

    function getDispositivo() {
        var w = window.innerWidth;
        if (w < 768) return 'movil';
        if (w <= 1024) return 'tablet';
        return 'desktop';
    }

    var origen = (document.referrer || '').slice(0, 120);
    var tiempo = 0;
    var scrollPct = 0;
    var leyoCompleto = false;

    function calcScroll() {
        var h = document.body.scrollHeight - window.innerHeight;
        if (h <= 0) return 100;
        return Math.round((window.scrollY + window.innerHeight) / document.body.scrollHeight * 100);
    }

    document.addEventListener('scroll', function() {
        var p = calcScroll();
        if (p > scrollPct) scrollPct = p;
        if (!leyoCompleto && scrollPct >= 90 && tiempo >= 30) leyoCompleto = true;
    }, { passive: true });

    setInterval(function() {
        if (document.visibilityState === 'visible') {
            tiempo++;
            if (!leyoCompleto && scrollPct >= 90 && tiempo >= 30) leyoCompleto = true;
        }
    }, 1000);

    function payload() {
        return JSON.stringify({
            tipo: TIPO,
            publicacion_id: PUB_ID,
            session_id: sid,
            tiempo_segundos: tiempo,
            scroll_max_pct: scrollPct,
            leyo_completo: leyoCompleto,
            dispositivo: getDispositivo(),
            origen: origen
        });
    }

    setInterval(function() {
        fetch('/app/track_vista.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload() }).catch(function(){});
    }, 5000);

    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') {
            navigator.sendBeacon('/app/track_vista.php', payload());
        }
    });
})();
</script>

<!-- MODAL CHAT VISITANTE — Cuenta Express -->
<div id="modal-chat-visitante" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
  <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-gray-900">Para chatear con <?= htmlspecialchars($nombrePub) ?></h2>
      <button type="button" id="btn-cerrar-modal-visitante" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">×</button>
    </div>
    <p class="text-sm text-gray-600 mb-4">Te avisaremos por correo cuando responda.</p>
    <form id="form-cuenta-express" class="space-y-3">
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Tu nombre</label>
        <input type="text" name="nombre" required minlength="2" maxlength="100"
               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#54A6D8]"
               placeholder="Ej: Camila Soto">
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Tu email</label>
        <input type="email" name="email" required
               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#54A6D8]"
               placeholder="camila@ejemplo.cl">
      </div>
      <label class="flex items-start gap-2 text-xs text-gray-700 cursor-pointer">
        <input type="checkbox" name="acepta_terminos" required class="mt-0.5 accent-[#54A6D8]">
        <span>Acepto los <a href="/terminos" target="_blank" class="text-[#54A6D8] underline">términos</a></span>
      </label>
      <div id="error-visitante" class="hidden text-red-600 text-xs py-1"></div>
      <button type="submit" id="btn-enviar-visitante"
              class="w-full bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl py-3 text-sm transition-all active:scale-95">
        Empezar conversación
      </button>
      <p class="text-center text-xs text-gray-500">
        ¿Ya tienes cuenta? <a href="/login" class="text-[#54A6D8] underline">Inicia sesión</a>
      </p>
    </form>
  </div>
</div>

<script>
(function(){
  const ES_VISITANTE = <?= !isset($_SESSION['usuario_id']) ? 'true' : 'false' ?>;
  const SERVICIO_ID  = <?= (int)$id ?>;
  if (!ES_VISITANTE) return;

  const modal     = document.getElementById('modal-chat-visitante');
  const form      = document.getElementById('form-cuenta-express');
  const errorBox  = document.getElementById('error-visitante');
  const btnEnviar = document.getElementById('btn-enviar-visitante');
  const btnCerrar = document.getElementById('btn-cerrar-modal-visitante');

  document.querySelectorAll('a[href*="iniciar_chat.php"]').forEach(a => {
    a.addEventListener('click', e => {
      e.preventDefault();
      modal.classList.remove('hidden');
    });
  });

  btnCerrar.addEventListener('click', () => modal.classList.add('hidden'));
  modal.addEventListener('click', e => { if (e.target === modal) modal.classList.add('hidden'); });

  form.addEventListener('submit', async e => {
    e.preventDefault();
    errorBox.classList.add('hidden');
    btnEnviar.disabled = true;
    btnEnviar.textContent = 'Creando cuenta...';

    const fd = new FormData(form);
    const payload = {
      nombre:          fd.get('nombre').trim(),
      email:           fd.get('email').trim().toLowerCase(),
      acepta_terminos: fd.get('acepta_terminos') === 'on',
      servicio_id:     SERVICIO_ID
    };

    try {
      const r    = await fetch('/app/crear_cuenta_express.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        credentials: 'same-origin'
      });
      const data = await r.json();
      if (data.ok) {
        window.location.href = data.redirect;
      } else {
        errorBox.textContent = data.error || 'Error desconocido.';
        errorBox.classList.remove('hidden');
        btnEnviar.disabled = false;
        btnEnviar.textContent = 'Empezar conversación';
      }
    } catch {
      errorBox.textContent = 'Error de conexión. Intenta de nuevo.';
      errorBox.classList.remove('hidden');
      btnEnviar.disabled = false;
      btnEnviar.textContent = 'Empezar conversación';
    }
  });
})();
</script>

</body>
</html>
