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

// 3. Configuración Base & Lazy Registration
$base_url = "https://nubira.cl"; 
$default_image = $base_url . "/upload/servicios/default_clases.webp";
$logueado = isset($_SESSION['usuario_id']);
if (!$logueado) $_SESSION['redirigir_despues_login'] = $_SERVER['REQUEST_URI'];
$uid = (int)($_SESSION['usuario_id'] ?? 0); 

// 4. Validación ID (NUBIRA SHIELD)
require_once $ruta_raiz . '/seguridad_url.php';

$param_id = $_GET['id'] ?? null;
$id = 0;

if ($param_id) {
    if (is_numeric($param_id)) {
        // Es un ID viejo o vulnerable. Lo convertimos y forzamos la URL segura.
        $hash_seguro = nubira_encriptar_id($param_id);
        header("Location: /detalle-servicio/" . $hash_seguro, true, 301);
        exit;
    } else {
        // Es un hash seguro estilo Nubira 2.0. Lo desencriptamos silenciosamente.
        $id = nubira_desencriptar_id($param_id);
    }
}

if ($id === 0) { 
    http_response_code(404); 
    die("Servicio no encontrado o enlace caducado."); 
}

// 5. Consulta SQL
$servicio = null;
$sql = "SELECT s.*, a.nombre AS nombre_alumno, a.foto_perfil, a.tiempo_respuesta_promedio, COALESCE(dp.institucion, a.institucion) AS institucion_maestra 
        FROM servicios s 
        LEFT JOIN alumnos a ON s.alumno_id = a.id 
        LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio 
        WHERE s.id = ?";
try {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $servicio = $stmt->get_result()->fetch_assoc();
} catch (Exception $e) { die("Error DB."); }

if (!$servicio) { http_response_code(404); die("Servicio eliminado."); }

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
                registrar_actividad($conn, $usuario_id, 'view', 'servicio', $id);
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
 * Recibe minutos (mediana móvil 30d, ya filtrada de outliers >24h por el cron).
 * 
 * @return array ['texto' => string, 'tono' => 'verde'|'azul'|'naranjo'|'gris']
 */
function formatearTiempoRespuestaNubira($minutos) {
    // Tutor nuevo o sin métrica suficiente (< 5 respuestas)
    if ($minutos === null) {
        return ['texto' => 'Tutor nuevo', 'tono' => 'gris'];
    }

    $minutos = (int)$minutos;

    if ($minutos < 15) {
        return ['texto' => 'En minutos', 'tono' => 'verde'];
    }
    if ($minutos < 60) {
        return ['texto' => 'En menos de 1 hora', 'tono' => 'verde'];
    }
    if ($minutos < 180) {
        return ['texto' => 'En pocas horas', 'tono' => 'azul'];
    }
    if ($minutos < 720) {
        return ['texto' => 'En el día', 'tono' => 'azul'];
    }
    // 720+ minutos = más de 12h
    return ['texto' => 'En 1 día', 'tono' => 'naranjo'];
}

$tiempo_data = formatearTiempoRespuestaNubira(
    $servicio['tiempo_respuesta_promedio'] !== null 
        ? (int)$servicio['tiempo_respuesta_promedio'] 
        : null
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
$sql_recs = "SELECT s.id, s.titulo, s.precio, s.imagen, s.categoria, s.modalidad,
                    COALESCE(dp.institucion, a.institucion) as institucion_maestra,
                    (SELECT AVG(c.calificacion_comprador) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as rating_promedio,
                    (SELECT COUNT(*) FROM contratos c WHERE c.servicio_id = s.id AND c.calificacion_comprador > 0) as total_votos
             FROM servicios s
             LEFT JOIN alumnos a ON s.alumno_id = a.id
             LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
             WHERE s.estado = 'aprobado' AND s.id != ? 
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
$token_seguro = nubira_encriptar_id($id);
$url_servicio_masked = $base_url . "/detalle-servicio/" . $token_seguro;
$url_canonical = $url_servicio_masked;
$og_desc = mb_strimwidth(strip_tags($servicio['descripcion']), 0, 150, "...");

$og_image = $default_image; 
$web_src = $default_image;
$og_mime = "image/webp"; 
$og_w = 1200; $og_h = 630; 

if (!empty($servicio['imagen']) && $servicio['imagen'] !== 'default.webp') {
    $fname = basename($servicio['imagen']);
    $ruta_fis = $_SERVER['DOCUMENT_ROOT'] . "/upload/servicios/" . $fname;
    
    if (file_exists($ruta_fis)) {
        $version = filemtime($ruta_fis);
        $web_src = "/upload/servicios/" . $fname . "?v=" . $version;
        $og_image = $base_url . "/upload/servicios/" . rawurlencode($fname) . "?v=" . $version;
        
        $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
        if ($ext == 'jpg' || $ext == 'jpeg') $og_mime = "image/jpeg"; 
        elseif ($ext == 'png') $og_mime = "image/png";
        $d = @getimagesize($ruta_fis); 
        if ($d) { $og_w = $d[0]; $og_h = $d[1]; }
    }
}
$share_txt = urlencode("¡Mira este servicio en Nubira.cl! " . $servicio['titulo']);

// --- LÓGICA DE HORARIOS DINÁMICOS NUBIRA 2.0 ---
$horarios_tutor = null;
$tiene_horarios = false;
$dias_disponibles = []; // Solo días con bloques reales
$dia_proximo = null;    // Primer día disponible desde hoy

if (!empty($servicio['horarios_json'])) {
    $horarios_tutor = json_decode($servicio['horarios_json'], true);
    
    if (is_array($horarios_tutor)) {
        $orden_dias = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
        
        // Filtrar solo días con bloques no vacíos
        foreach ($orden_dias as $dia) {
            if (!empty($horarios_tutor[$dia]) && count($horarios_tutor[$dia]) > 0) {
                $dias_disponibles[$dia] = $horarios_tutor[$dia];
            }
        }
        
        if (count($dias_disponibles) > 0) {
            $tiene_horarios = true;
            
            // Calcular el próximo día disponible desde HOY (timezone Chile)
            date_default_timezone_set('America/Santiago');
            $hoy_index = (int)date('N') - 1; // 0=Lunes ... 6=Domingo
            
            // Buscar el próximo día disponible (incluyendo hoy)
            for ($i = 0; $i < 7; $i++) {
                $check_index = ($hoy_index + $i) % 7;
                $check_dia = $orden_dias[$check_index];
                if (isset($dias_disponibles[$check_dia])) {
                    $dia_proximo = $check_dia;
                    break;
                }
            }
        }
    }
}
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
    <title><?= $page_title ?> | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="theme-color" content="#ffffff" />
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
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
    <meta property="og:url" content="<?= $url_servicio_masked ?>" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:image" content="<?= $og_image ?>" />

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
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
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

    <!-- [NUBIRA 2.0] Topbar Nativo Móvil (Reemplaza al Header global) -->
    <div class="lg:hidden flex items-center justify-between mb-4 mt-1 max-w-[1100px] mx-auto">
       <button type="button"
        onclick="navegacionSeguraNubira()"
        class="flex-shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-gray-50 hover:bg-gray-100 border border-gray-200/60 shadow-sm active:scale-95 transition-all"
        aria-label="Volver">
    <i class="fa-solid fa-arrow-left text-gray-700 text-[17px]"></i>
</button>
        
        <!-- Indicador visual de "Hoja/Sheet" estilo iOS -->
        <div class="w-10 h-1.5 bg-gray-200 rounded-full"></div>
        
        <!-- Espaciador invisible para mantener el centrado perfecto -->
        <div class="w-10 h-10"></div>
    </div>

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

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10">
            
            <div class="lg:col-span-8 space-y-8">
                <div class="rounded-2xl overflow-hidden border border-gray-100 bg-gray-50 aspect-video relative shadow-sm group">
                    <img src="<?= $web_src ?>" fetchpriority="high" class="w-full h-full object-cover group-hover:scale-[1.02] transition duration-700 track-gallery" onerror="this.src='<?= $default_image ?>';">
                </div>

                <div class="bg-white border border-gray-100 rounded-2xl p-6 shadow-sm">
                    <div class="flex items-center gap-3 mb-3">
                          <span class="px-2.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-700 border border-gray-200 uppercase tracking-wide"><?= htmlspecialchars($servicio['categoria']) ?></span>
                        <div class="flex items-center gap-1.5 text-sm text-gray-500">
                             <i class="fa-solid fa-star text-gray-700 text-xs"></i>
<span id="val-promedio" class="font-bold text-gray-900"><?= $promedio > 0 ? $promedio : 'Nuevo' ?></span>
<?php if($tot_votos > 0): ?>
    <span id="val-bullet" class="text-gray-300">•</span>
    <span id="val-votos-txt" class="text-gray-500"><?= $tot_votos ?> reseña<?= $tot_votos != 1 ? 's' : '' ?></span>
<?php endif; ?>
                        </div>
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
                                <i class="fa-solid fa-circle-check text-[#54A6D8] text-xs"></i>
                            </div>
                           <div class="flex items-center gap-1.5 mt-0.5">
            <span class="text-gray-400 text-xs"><i class="fa-solid fa-building-columns"></i></span>
            <p class="text-xs text-gray-500 uppercase font-medium"><?= htmlspecialchars($servicio['institucion_maestra'] ?? 'Estudiante') ?></p>
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
            <span class="<?= $c['texto'] ?> font-bold"><?= htmlspecialchars($texto_valor) ?></span>
        <?php else: ?>
            Responde <span class="<?= $c['texto'] ?> font-bold"><?= htmlspecialchars($texto_valor) ?></span>
        <?php endif; ?>
    </p>
</div>
        
    </div>
</a>

                   <div class="mt-6">
                        <h3 class="font-bold text-gray-900 mb-3">Sobre este servicio</h3>
                        <div class="text-gray-600 text-sm whitespace-normal font-normal leading-relaxed">
                            <?php
                                $desc_raw = trim($servicio['descripcion'] ?? '');
                                $desc_raw = html_entity_decode($desc_raw, ENT_QUOTES, 'UTF-8');
                                $desc_raw = preg_replace_callback('/\(([^)]+\|[^)]+)\)/', function($m) {
                                    $ops = explode('|', $m[1]);
                                    return $ops[array_rand($ops)];
                                }, $desc_raw);
                                echo nl2br(htmlspecialchars($desc_raw, ENT_QUOTES, 'UTF-8'));
                            ?>
                        </div>
                    </div>

                   <div class="mt-8 pt-8 border-t border-gray-50">
    <h3 class="font-bold text-gray-900 mb-5">Lo que incluye este servicio</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-y-4 gap-x-6">
        <div class="flex items-start gap-3">
            <?= icon('video', 'text-gray-400 mt-1') ?>
            <div>
                <p class="text-sm font-bold text-gray-800">Sesión interactiva</p>
                <p class="text-[11px] text-gray-500">Clase o reunión 100% online.</p>
            </div>
        </div>
        <div class="flex items-start gap-3">
            <?= icon('comments', 'text-gray-400 mt-1') ?>
            <div>
                <p class="text-sm font-bold text-gray-800">Chat directo</p>
                <p class="text-[11px] text-gray-500">Comunícate por el aula virtual.</p>
            </div>
        </div>
        <div class="flex items-start gap-3">
            <?= icon('clock-rotate-left', 'text-gray-400 mt-1') ?>
            <div>
                <p class="text-sm font-bold text-gray-800">Horario a convenir</p>
                <p class="text-[11px] text-gray-500">Coordina directo con el vendedor.</p>
            </div>
        </div>
        <div class="flex items-start gap-3">
            <?= icon('file-pdf', 'text-gray-400 mt-1') ?>
            <div>
                <p class="text-sm font-bold text-gray-800">Material de apoyo</p>
                <p class="text-[11px] text-gray-500">Si el autor lo especifica.</p>
            </div>
        </div>
    </div>
</div>
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

                        
                        <?php if ($tiene_horarios): 
    $cantidad_dias = count($dias_disponibles);
?>
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
<?php else: ?>
                            <div class="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-6 text-center">
                                <div class="w-12 h-12 bg-white rounded-full flex items-center justify-center mx-auto mb-3 shadow-sm border border-gray-100 text-gray-300">
                                    <i class="fa-regular fa-calendar-days text-xl"></i>
                                </div>
                                <p class="text-sm font-bold text-gray-700 mb-1">Horario a convenir</p>
                                <p class="text-xs text-gray-500 max-w-sm mx-auto">Comunícate directamente con <?= htmlspecialchars($nombrePub) ?> para acordar el horario que mejor les acomode a ambos.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    </div>
                
                <div class="bg-white border border-gray-100 rounded-2xl p-6 shadow-sm">
             <h3 class="font-bold text-gray-900 mb-6 flex gap-2 items-center">Opiniones <span id="badge-votos" class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full text-xs"><?= $tot_votos ?></span></h3>
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

            <div class="lg:col-span-4 relative">
                <div class="sticky top-24 space-y-6">
                      <div class="bg-white rounded-2xl border border-gray-200 p-6">
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
                    if ($dias > 7)      $txt_term = 'Oferta hasta el ' . date('d/m', strtotime($servicio['oferta_termino']));
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
                                    <div class="p-5 bg-gradient-to-b from-blue-50 to-white rounded-xl text-center border border-blue-100 mb-4 shadow-sm">
                                        <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-3 text-[#54A6D8]">
                                            <i class="fa-solid fa-lock"></i>
                                        </div>
                                        <p class="text-sm text-gray-800 mb-4 font-bold">Inicia sesión para contratar este servicio</p>
                                        <a href="/login?redir=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="block w-full bg-[#54A6D8] text-white font-bold py-3 rounded-xl hover:bg-blue-600 transition shadow-md mb-2 transform hover:scale-[1.02]">Ingresar ahora</a>
                                        <a href="/registro?redir=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="block w-full text-[#54A6D8] font-bold text-xs hover:underline mt-2">¿No tienes cuenta? Regístrate gratis</a>
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
   class="block mt-3 w-full bg-white text-[#54A6D8] border-2 border-[#54A6D8] font-bold rounded-xl text-sm px-5 py-3 text-center hover:bg-blue-50 transition-all shadow-sm active:scale-95"
   data-track-click="contact:mensaje">
   Contactar al tutor
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
                    
                  <div class="mt-4 pt-4 border-t border-gray-100/60">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest text-center mb-3">Compartir servicio</p>
                        <div class="flex flex-wrap justify-center gap-3">
                            <a href="https://api.whatsapp.com/send?text=<?= $share_txt ?>%20<?= urlencode($url_servicio_masked) ?>" target="_blank" class="w-11 h-11 bg-gray-50 text-[#25D366] border border-gray-100 rounded-full flex items-center justify-center shadow-sm hover:bg-[#25D366] hover:text-white hover:border-[#25D366] transition-all duration-300" title="Compartir en WhatsApp" data-track-click="share:whatsapp">
                                <i class="fab fa-whatsapp text-lg"></i>
                            </a>
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($url_servicio_masked) ?>" target="_blank" class="w-11 h-11 bg-gray-50 text-[#1877F2] border border-gray-100 rounded-full flex items-center justify-center shadow-sm hover:bg-[#1877F2] hover:text-white hover:border-[#1877F2] transition-all duration-300" title="Compartir en Facebook" data-track-click="share:facebook">
                                <i class="fab fa-facebook-f text-lg"></i>
                            </a>
                            <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= urlencode($url_servicio_masked) ?>" target="_blank" class="w-11 h-11 bg-gray-50 text-[#0A66C2] border border-gray-100 rounded-full flex items-center justify-center shadow-sm hover:bg-[#0A66C2] hover:text-white hover:border-[#0A66C2] transition-all duration-300" title="Compartir en LinkedIn" data-track-click="share:linkedin">
                                <i class="fab fa-linkedin-in text-lg"></i>
                            </a>
                            <button id="btn-copiar-enlace" data-url="<?= htmlspecialchars($url_servicio_masked) ?>" class="w-11 h-11 bg-gray-50 text-gray-500 border border-gray-100 rounded-full flex items-center justify-center shadow-sm hover:bg-gray-600 hover:text-white hover:border-gray-600 transition-all duration-300" title="Copiar Enlace" data-track-click="share:copy">
                                <i class="fas fa-link text-lg" id="copy-icon"></i>
                            </button>
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
            <h2 class="text-xl font-bold text-gray-900 mb-6">Podría interesarte</h2>
            
            <div class="relative group">
                
                <button onclick="scrollCarrusel('carrusel-recomendados', -1)" class="hidden md:flex absolute -left-5 top-[40%] -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-lg items-center justify-center z-10 text-gray-400 hover:text-[#54A6D8] border border-gray-200 transition">
                    <i class="fa-solid fa-chevron-left text-xs"></i>
                </button>

            <div id="carrusel-recomendados" class="flex gap-4 overflow-x-auto pb-6 px-1 no-scrollbar snap-x snap-proximity scroll-smooth">
    <?php while ($r = $recs->fetch_assoc()): 
        $ir = $default_image;
        if(!empty($r['imagen'])) $ir = '/upload/servicios/'.basename($r['imagen']);
        
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
        
        // [NUBIRA 2.0] Limpiar y formatear institución (Diccionario Global)
        $inst_raw = $r['institucion_maestra'] ?? '';
        if (empty(trim($inst_raw))) {
            $inst_text = 'Estudiante';
        } else {
            $inst_clean = trim($inst_raw);
            $dicc = [
                'Economía y Negocios' => 'FEN U. Chile',
                'ECONOMíA Y NEGOCIOS' => 'FEN U. Chile',
                'Servicio Local de Educ' => 'SLEP',
                'SERVICIO LOCAL DE EDUC' => 'SLEP',
                'Santísima Concepci' => 'UCSC',
                'SANTíSIMA CONCEPCI' => 'UCSC',
                'Santisima Concepci' => 'UCSC',
                'Konrad Lorenz' => 'Konrad Lorenz',
                'Universidad Andr' => 'UNAB', 'Universidad Nac' => 'UNAB',
                'Pontificia Universidad Cat' => 'PUC', 'Universidad de Santiago' => 'USACH',
                'Universidad de Concepci' => 'UdeC', 'Universidad T' => 'USM', 
                'Federico Santa Mar' => 'USM', 'Adolfo Ib' => 'UAI',
                'Universidad de Chile' => 'U. de Chile', 
                'Universidad del B' => 'UBB', 'Bío Bío' => 'UBB', 'Bio Bio' => 'UBB',
                'Instituto Profesional' => 'IP', 'Centro de Formación Técnica' => 'CFT'
            ];

            foreach($dicc as $parcial => $corto) {
                if (stripos($inst_clean, $parcial) !== false) {
                    if (strlen($corto) <= 6) {
                        $inst_clean = $corto;
                    } else {
                        $inst_clean = str_ireplace($parcial, $corto, $inst_clean);
                    }
                    break;
                }
            }
              if (stripos($inst_clean, 'universidad ') === 0) {
                  $inst_clean = 'U. ' . substr($inst_clean, 12);
              }
            $inst_text = htmlspecialchars(mb_strimwidth($inst_clean, 0, 22, '...'));
        }
    ?>
        <a href="/detalle-servicio/<?= $r['id'] ?>" class="group block snap-start shrink-0 w-[240px] md:w-[280px]" data-track-click="rec:<?= $r['id'] ?>">
                  <div class="relative bg-white rounded-xl overflow-hidden aspect-[4/3] mb-3 border border-gray-200 transition-all">
                <img src="<?= $ir ?>" loading="lazy" class="w-full h-full object-cover group-hover:scale-105 transition duration-500" onerror="this.src='<?= $default_image ?>'">
            </div>
            <div class="px-1 flex flex-col">
                <!-- Título estricto a 2 líneas -->
                <h6 class="font-bold text-[13px] leading-[1.3] text-gray-900 line-clamp-2 h-[34px] overflow-hidden mb-0.5 group-hover:text-[#54A6D8] transition-colors">
                    <?= htmlspecialchars($r['titulo']) ?>
                </h6>
                
                  <div class="text-[13px] text-gray-700 font-semibold leading-none mb-0 mt-0.5">
                    $<?= number_format($r['precio'], 0, ',', '.') ?>
                </div>

                <div class="flex items-center justify-between mt-1">
                      <div class="flex items-center gap-1 text-[9px] text-gray-400 font-bold uppercase tracking-wide truncate max-w-[65%]">
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
                <span class="text-[10px] text-gray-400 line-through font-medium leading-none">
                    $<?= number_format($servicio['precio'], 0, ',', '.') ?>
                </span>
                <div class="flex items-baseline gap-1.5 mt-0.5">
    <span id="precio-movil-oferta" class="text-xl font-black text-gray-900 tracking-tight leading-none">
        $<?= number_format($servicio['precio_oferta'], 0, ',', '.') ?>
    </span>
</div>
<?php else: ?>
<span class="text-[10px] text-gray-400 font-bold uppercase tracking-wide leading-none">Inversión total</span>
<span id="precio-movil-main" class="text-xl font-extrabold text-gray-900 tracking-tight leading-none mt-0.5">
    $<?= number_format($servicio['precio'], 0, ',', '.') ?>
</span>
            <?php endif; ?>
        </div>

        <?php if (!$logueado): ?>
            <a href="/login?redir=<?= urlencode($_SERVER['REQUEST_URI']) ?>" 
               class="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl px-6 py-3 text-sm shadow-md active:scale-95 transition-all whitespace-nowrap">
                Ingresar
            </a>
        <?php else: ?>
            <button type="button" 
                    onclick="document.getElementById('btn-submit-pago')?.click()"
                    class="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl px-6 py-3 text-sm shadow-md active:scale-95 transition-all whitespace-nowrap"
                    data-track-click="contact:contratar_movil">
                Contratar ahora
            </button>
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

</body>
</html>