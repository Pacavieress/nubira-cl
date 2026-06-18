<?php
/**
 * VISTA: VER APUNTE (DETALLE)
 * ESTADO: OPTIMIZADO (NUBIRA 2.0)
 * 
 * [OPT-1] SQL: Recomendados inteligentes + filtro estado
 * [OPT-2] Seguridad: Secret desde ENV, no hardcodeado
 * [OPT-3] Rendimiento: Fonts preload, FA defer, scripts diferidos
 * [OPT-4] DRY: Modales extraídos a componentes
 */

ini_set('display_errors', 0); 
error_reporting(E_ALL);
session_start();

/* ===============================
   RUTAS BLINDADAS
================================ */
$base_path = __DIR__;
if (file_exists(__DIR__ . '/app/conexion.php')) {
    $base_path = __DIR__ . '/app';
} elseif (file_exists(__DIR__ . '/../app/conexion.php')) {
    $base_path = __DIR__ . '/../app';
}

if (!file_exists($base_path . '/conexion.php')) {
    die("Error crítico: No se encuentra conexion.php");
}

require_once $base_path . '/conexion.php';
require_once $base_path . '/iconos.php';

// [NUBIRA SHIELD] Cargar enmascarador de URLs
$rutas_shield = [$base_path . '/seguridad_url.php', dirname($base_path) . '/app/seguridad_url.php', $_SERVER['DOCUMENT_ROOT'] . '/app/seguridad_url.php'];
foreach ($rutas_shield as $rs) {
    if (file_exists($rs)) {
        require_once $rs;
        break;
    }
}

/* ===============================
   CONFIGURACIÓN BASE & TOKEN
================================ */
$base_url = "https://nubira.cl"; 

// ==========================================
// MÓDULO DE SEGURIDAD (NUBIRA SHIELD)
// ==========================================

if (isset($_GET['id'])) {
    $param_id = $_GET['id'];
    
    if (is_numeric($param_id)) {
        if (function_exists('nubira_encriptar_id')) {
            $hash_seguro = nubira_encriptar_id($param_id);
            header("Location: /apunte/" . $hash_seguro, true, 301);
            exit;
        }
    } else {
        if (function_exists('nubira_desencriptar_id')) {
            $id_req = nubira_desencriptar_id($param_id);
            if ($id_req > 0) {
                $stmtId = $conn->prepare("SELECT archivo FROM apuntes WHERE id = ? LIMIT 1");
                if ($stmtId) {
                    $stmtId->bind_param("i", $id_req);
                    $stmtId->execute();
                    $resId = $stmtId->get_result();
                    if ($rowId = $resId->fetch_assoc()) {
                        $_GET['archivo'] = $rowId['archivo']; 
                    }
                    $stmtId->close();
                }
            }
        }
    }
}

/* ===============================
   HELPERS
================================ */
function mostrarError($msg) {
    echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'><script src='https://cdn.tailwindcss.com'></script><title>Aviso</title></head><body class='bg-gray-50 flex items-center justify-center min-h-screen'><div class='bg-white p-10 rounded-3xl shadow-xl text-center max-w-md border border-gray-100'><h2 class='text-2xl font-bold text-gray-900 mb-3'>Aviso</h2><p class='text-gray-500 mb-6'>".htmlspecialchars($msg)."</p><a href='/vitrina-apuntes' class='inline-block bg-[#54A6D8] text-white font-bold py-3 px-8 rounded-xl'>Volver</a></div></body></html>";
    exit;
}

function miniatura_apunte($id, $portadaBD, $archivoOriginal) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'];
    
    $getVersionedPath = function($path) use ($docRoot) {
        return $path . '?v=' . filemtime($docRoot . $path);
    };
    
    if (!empty($portadaBD)) {
        $pathPort = "/upload/portadas/" . basename($portadaBD);
        if (file_exists($docRoot . $pathPort)) return $getVersionedPath($pathPort);
    }
    
    $pathWebp = "/upload/preview/{$id}.webp";
    if (file_exists($docRoot . $pathWebp)) return $getVersionedPath($pathWebp);
    
    if (!empty($archivoOriginal)) {
        $ext = strtolower(pathinfo($archivoOriginal, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','gif','svg'])) {
            $pathOrig = "/upload/apuntes/" . basename($archivoOriginal);
            if (file_exists($docRoot . $pathOrig)) return $getVersionedPath($pathOrig);
        }
    }

    return "/img/logo2.webp";
}

/* ===============================
   SESIÓN
================================ */
$logueado = isset($_SESSION['usuario_id']);
if (!$logueado) $_SESSION['redirigir_despues_login'] = $_SERVER['REQUEST_URI'];

$usuario_id = (int)($_SESSION['usuario_id'] ?? 0);
$nombre_usuario = $_SESSION['usuario_nombre'] ?? 'Invitado';
$display_carrera = $_SESSION['carrera'] ?? 'Estudiante';
$nombre_institucion = $_SESSION['institucion'] ?? 'Nubira';

if (!function_exists('nav_class')) {
    function nav_class(string $path): string {
        $base = 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent';
        $activo = ' bg-blue-50 text-[#54A6D8] border-blue-100';
        $inactivo = ' text-gray-500 hover:bg-gray-50 hover:text-gray-900';
        if ($path === '/vitrina-apuntes') return $base . $activo;
        return $base . $inactivo;
    }
}

/* ===============================
   DATOS DEL APUNTE
================================ */
// ¡No borrar esta línea! Captura el nombre del archivo desde la URL o el Shield
$archivo = $_GET['archivo'] ?? null;

if (!$archivo) {
    mostrarError("Apunte no especificado.");
}

// [OPT-1] Lectura directa de la Única Fuente de Verdad (Estándar Nubira 2.0)
$sql_apunte = "
    SELECT 
        id, titulo, precio, id_alumno, descripcion, sigla, semestre, anio, institucion, asignatura, fecha_subida, portada, archivo,
        ia_used, ia_keywords, categoria, estado, promo_gratis, promo_limite, promo_contador,
        descargas as total_ventas
    FROM apuntes
    WHERE archivo = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql_apunte);
if (!$stmt) {
    mostrarError("Error de base de datos en la consulta Nubira 2.0.");
}

$stmt->bind_param("s", $archivo);
$stmt->execute();
$apunte = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$apunte) mostrarError("El apunte no existe.");

$stmt = $conn->prepare($sql_apunte);
if (!$stmt) {
    mostrarError("Error de base de datos. Si eres admin, asegúrate de haber ejecutado el ALTER TABLE para las Promos Flash.");
}

$stmt->bind_param("s", $archivo);
$stmt->execute();
$apunte = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$apunte) mostrarError("El apunte no existe.");

// [NUBIRA SHIELD] Bloqueo de visibilidad por estado
$es_propietario_shield = ($logueado && $usuario_id === (int)$apunte['id_alumno']);
$es_admin_shield = (isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin');

if (isset($apunte['estado']) && $apunte['estado'] !== 'aprobado' && !$es_propietario_shield && !$es_admin_shield) {
    http_response_code(403);
    mostrarError("Este apunte no está disponible o se encuentra en revisión.");
}

// LOGGER
if (file_exists($base_path . '/logger.php')) {
    require_once $base_path . '/logger.php';
    $detalle_apunte = "ID: " . $apunte['id'] . " | " . $apunte['titulo'] . " (" . ($apunte['asignatura'] ?? 'General') . ")";
    registrar_actividad($conn, $usuario_id, 'VER_APUNTE', $detalle_apunte);
}

// [OPT-1] RECOMENDADOS INTELIGENTES: Por asignatura/institución + filtro estado
$recomendados = null;
$asignatura_actual = $apunte['asignatura'] ?? '';
$institucion_actual = $apunte['institucion'] ?? '';

require_once __DIR__ . '/helpers/institucion.php';

// Primero intentamos por asignatura similar
$stmtRec = $conn->prepare("
    SELECT ap.id, ap.titulo, ap.descripcion, ap.precio, ap.asignatura, ap.portada, ap.archivo, ap.descargas,
           COALESCE(dp.institucion, NULLIF(ap.institucion,''), al.institucion) AS institucion
    FROM apuntes ap
    JOIN alumnos al ON al.id = ap.id_alumno
    LEFT JOIN dominios_permitidos dp ON al.dominio = dp.dominio
    WHERE ap.id != ? AND ap.estado = 'aprobado'
    ORDER BY
        (ap.asignatura = ?) DESC,
        (ap.institucion = ?) DESC,
        ap.id DESC
    LIMIT 4
");
if ($stmtRec) {
    $stmtRec->bind_param("iss", $apunte['id'], $asignatura_actual, $institucion_actual);
    $stmtRec->execute();
    $recomendados = $stmtRec->get_result();
    $stmtRec->close();
}

// PUBLICADOR
$stmtP = $conn->prepare("SELECT nombre, carrera, institucion, foto_perfil, verificacion_estado FROM alumnos WHERE id = ? LIMIT 1");
if ($stmtP) {
    $stmtP->bind_param("i", $apunte['id_alumno']);
    $stmtP->execute();
    $publicador = $stmtP->get_result()->fetch_assoc();
    $stmtP->close();
}

// DATOS VISUALES
$nombreDisplay = 'Usuario';
$iniciales_publicador = 'US';
if (!empty($publicador['nombre'])) {
    $partes = array_values(array_filter(explode(' ', trim($publicador['nombre']))));
    if (!empty($partes[0])) {
        $p_nombre = ucwords(strtolower($partes[0]));
        $inicial_apellido_txt = '';
        $iniciales_calc = strtoupper(substr($partes[0], 0, 1));
        if (count($partes) >= 2) {
            $ultimo = $partes[count($partes)-1];
            $letraApellido = strtoupper(substr($ultimo, 0, 1));
            $inicial_apellido_txt = ' ' . $letraApellido . '.';
            $iniciales_calc .= $letraApellido;
        } elseif (strlen($partes[0]) > 1) {
            $iniciales_calc = strtoupper(substr($partes[0], 0, 2));
        }
        $nombreDisplay = $p_nombre . $inicial_apellido_txt;
        $iniciales_publicador = $iniciales_calc;
    }
}
$institucionPublicador = ucfirst(strtolower($publicador['institucion'] ?? $apunte['institucion'] ?? ''));
$publicador_verificado = ($publicador['verificacion_estado'] === 'aprobado')
    || ($publicador['verificacion_estado'] === null && !empty($publicador['institucion']));

/* ===============================
   VARIABLES IA
================================ */
$has_ia = !empty($apunte['ia_used']) && $apunte['ia_used'] == 1;
$ia_cat = !empty($apunte['categoria']) ? ucfirst($apunte['categoria']) : 'General';
$ia_tags = [];
if ($has_ia && !empty($apunte['ia_keywords'])) {
    $ia_tags = explode(',', $apunte['ia_keywords']);
    $ia_tags = array_map('trim', $ia_tags);
    $ia_tags = array_filter($ia_tags); 
    $ia_tags = array_slice($ia_tags, 0, 6); 
}

/* ===============================
   VARIABLES GENERALES & URLS
================================ */
$id_apunte = (int)$apunte['id'];
$titulo = htmlspecialchars($apunte['titulo'] ?? '');
$precio = (int)$apunte['precio'];
$descripcion = nl2br(htmlspecialchars(html_entity_decode($apunte['descripcion'] ?? '')));
$total_ventas = (int)$apunte['total_ventas'];
$es_dueno = ((int)$apunte['id_alumno'] === $usuario_id);
$rol = $_SESSION['rol'] ?? 'alumno';

// Promo Flash
$promo_gratis_ap = isset($apunte['promo_gratis']) ? (int)$apunte['promo_gratis'] : 0;
$promo_limite_ap = isset($apunte['promo_limite']) ? (int)$apunte['promo_limite'] : 0;
$promo_contador_ap = isset($apunte['promo_contador']) ? (int)$apunte['promo_contador'] : 0;
$es_promo_activa = ($promo_gratis_ap === 1 && $promo_contador_ap < $promo_limite_ap);
$descargas_restantes = $promo_limite_ap - $promo_contador_ap;

if ($es_promo_activa) {
    $precio_fmt = "<span class='line-through text-gray-400 text-sm mr-2'>$" . number_format($precio, 0, ',', '.') . "</span><span class='text-orange-500'>¡Gratis!</span>";
} else {
    $precio_fmt = ($precio > 0) ? "$" . number_format($precio, 0, ',', '.') : "Gratis";
}

// BACKER URL (NUBIRA SHIELD)
$token_seguro = function_exists('nubira_encriptar_id') ? nubira_encriptar_id($id_apunte) : $id_apunte;
$url_apunte_masked = $base_url . "/apunte/" . $token_seguro; 
$url_canonical = $url_apunte_masked;
$share_txt = urlencode("¡Mira este apunte en Nubira.cl! " . $titulo);

/* ===============================
   PERMISOS Y ACCESO
================================ */
$acceso_completo = false;

if ($logueado) {
    if (($precio === 0) || ($rol === 'admin') || $es_dueno) {
        $acceso_completo = true;
    } else {
        $stmt = $conn->prepare("SELECT 1 FROM compras WHERE usuario_id = ? AND id_apunte = ? AND estado_pago = 'pagado' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("ii", $usuario_id, $id_apunte);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) $acceso_completo = true;
            $stmt->close();
        }
    }
}

// [OPT-2] Secret desde variable de entorno, con fallback temporal
function build_file_url($id, $file, $uid) {
    $secret = getenv('NUBIRA_HMAC_SECRET') ?: ($_ENV['NUBIRA_HMAC_SECRET'] ?? 'NUBIRA_SECRET_TEMP_CAMBIAR');
    $exp = time() + 3600;
    $sig = hash_hmac('sha256', "$id|$uid|$file|$exp", $secret);
    return "/app/descargar_apunte.php?id=$id&archivo=" . urlencode($file) . "&exp=$exp&sig=$sig";
}

$fileUrl = build_file_url($id_apunte, $archivo, $usuario_id); 
$inlineUrl = $fileUrl . "&inline=1"; 
$login_redir = '/login?redir=' . urlencode($_SERVER['REQUEST_URI']);

$extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
$isImage = in_array($extension, ['jpg','jpeg','png','gif','bmp','svg','webp']);
$isPDF = ($extension === 'pdf');

$thumb_url = miniatura_apunte($id_apunte, $apunte['portada'] ?? '', $archivo);

// STUDOCU MODE: Páginas extraídas
$paginas_preview = [];
$docRoot = $_SERVER['DOCUMENT_ROOT'];
for ($i = 1; $i <= 3; $i++) {
    $ruta_pag = "/upload/preview_paginas/{$id_apunte}_{$i}.webp";
    if (file_exists($docRoot . $ruta_pag)) {
        $paginas_preview[] = $ruta_pag . '?v=' . filemtime($docRoot . $ruta_pag);
    }
}

// OPEN GRAPH
$og_image = $base_url . "/img/logo2.webp";
$og_mime  = "image/png"; 
$og_w     = 1200;
$og_h     = 630;

if (!empty($thumb_url)) {
    $ruta_fisica = $_SERVER['DOCUMENT_ROOT'] . $thumb_url;
    if (file_exists($ruta_fisica)) {
        $info = @getimagesize($ruta_fisica);
        if ($info) {
            $og_w = $info[0];
            $og_h = $info[1];
            $og_mime = $info['mime'];
        }
        $dir_path = dirname($thumb_url);
        $filename = basename($thumb_url);
        $og_image = $base_url . $dir_path . "/" . rawurlencode($filename) . "?v=" . filemtime($ruta_fisica);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title><?= $titulo ?> | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>

  <link rel="canonical" href="<?= $url_canonical ?>" />
  <meta property="og:type" content="website" />
  <meta property="og:site_name" content="Nubira.cl" /> 
  <meta property="og:title" content="<?= $titulo ?>" />
  <meta property="og:description" content="Apunte de <?= htmlspecialchars($apunte['asignatura']) ?> - <?= htmlspecialchars($institucionPublicador) ?>" />
  <meta property="og:image" content="<?= $og_image ?>" />
  <meta property="og:image:secure_url" content="<?= $og_image ?>" />
  <meta property="og:image:type" content="<?= $og_mime ?>" />
  <meta property="og:image:width" content="<?= $og_w ?>" />
  <meta property="og:image:height" content="<?= $og_h ?>" />
  <meta property="og:url" content="<?= $url_apunte_masked ?>" />
  
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- [OPT-3] ViewerJS: solo si tiene acceso completo y es imagen -->
  <?php if ($acceso_completo && $isImage): ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/viewerjs/1.11.6/viewer.min.css" />
  <script src="https://cdnjs.cloudflare.com/ajax/libs/viewerjs/1.11.6/viewer.min.js" defer></script>
  <?php endif; ?>

  <!-- [OPT-3] PDF.js: solo si tiene acceso completo y es PDF -->
  <?php if ($acceso_completo && $isPDF): ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
  <script>pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';</script>
  <?php endif; ?>

  <!-- [OPT-3] Fonts: Preload + display=swap (no @import bloqueante) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" href="https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuLyfAZ9hjQ.woff2" as="font" type="font/woff2" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <!-- [OPT-3] FontAwesome: carga diferida -->
  <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"></noscript>

  <style>
    body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background-color: #f8fafc; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    .animate-fade-in-up { animation: fadeInUp 0.8s both; }
    @keyframes fadeInUp { 0% { opacity:0; transform: translateY(20px);} 100% { opacity:1; transform:none;} }
    .scrollbar-hide::-webkit-scrollbar { display: none; }
    .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
    .overflow-hidden-strict { overflow: hidden !important; }
    @media (max-width: 1023px) {
        nav.fixed.bottom-0,
        .fixed.bottom-0[id*="nav"] {
            display: none !important;
        }
    }
  </style>
</head>

<body class="bg-gray-50 min-h-screen text-gray-800 font-sans overflow-x-hidden"
      data-id="<?= (int)$id_apunte ?>" 
      data-tipo="apunte">

<div id="loader" class="fixed inset-0 bg-white/95 flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-10 w-10 border-4 border-blue-200 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
require_once $base_path . '/componentes/header.php'; 
require_once $base_path . '/componentes/sidebar.php'; 
?>

<main class="pt-20 pb-32 lg:pb-16 lg:ml-64 px-4 w-auto"
      data-track-type="apunte" 
      data-track-id="<?= (int)$id_apunte ?>">

  <!-- Topbar móvil: flecha volver + pill centrado -->
  <div class="lg:hidden flex items-center justify-between mb-4 mt-1 max-w-[1100px] mx-auto">
      <button type="button"
          onclick="navegacionSeguraNubira()"
          class="flex-shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-gray-50 hover:bg-gray-100 border border-gray-200/60 shadow-sm active:scale-95 transition-all"
          aria-label="Volver">
          <?= icon('arrow-left', 'w-5 h-5 text-gray-700') ?>
      </button>
      <div class="w-10 h-1.5 bg-gray-200 rounded-full"></div>
      <div class="w-10 h-10"></div>
  </div>

  <div class="w-full max-w-[1600px] mx-auto">

        <?php if ($es_dueno && isset($apunte['estado'])): ?>
            <?php if ($apunte['estado'] === 'pendiente'): ?>
                <div class="mb-6 bg-amber-50 border border-yellow-200 rounded-2xl p-4 flex items-start md:items-center gap-4 animate-pulse">
                    <div class="w-10 h-10 bg-yellow-100 text-yellow-600 rounded-full flex items-center justify-center shrink-0 mt-1 md:mt-0"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    <div>
                        <h4 class="font-bold text-yellow-800 text-sm">Apunte en Revisión</h4>
                        <p class="text-xs text-yellow-700 font-medium">Editaste este apunte recientemente. Un administrador lo está revisando para asegurar que cumple con nuestras normas. Volverá a la vitrina pronto.</p>
                    </div>
                </div>
            <?php elseif ($apunte['estado'] === 'rechazado'): ?>
                <div class="mb-6 bg-red-50 border border-red-200 rounded-2xl p-4 flex items-start md:items-center gap-4">
                    <div class="w-10 h-10 bg-red-100 text-red-600 rounded-full flex items-center justify-center shrink-0 mt-1 md:mt-0"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div>
                        <h4 class="font-bold text-red-800 text-sm">Publicación Pausada</h4>
                        <p class="text-xs text-red-700 font-medium">Hubo un problema con la última edición de este apunte. Por favor, revísalo y edítalo nuevamente.</p>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <div class="lg:col-span-8 space-y-6">
            
            <!-- HEADER MÓVIL -->
            <div class="block lg:hidden space-y-4 mb-2">
                <div>
                    <span class="bg-blue-50 text-[#54A6D8] px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider mb-2 inline-block">
                        Asignatura: <?= htmlspecialchars($apunte['asignatura'] ?? 'Apunte') ?>
                    </span>
                    <h1 class="text-2xl font-bold text-gray-900 leading-tight"><?= $titulo ?></h1>
                </div>

                <a href="/perfil/<?= (int)$apunte['id_alumno'] ?>" class="flex items-center gap-3 py-3 border-y border-gray-100 active:bg-gray-50 transition-colors track-seller">
                    <div class="w-10 h-10 rounded-full bg-white border border-gray-200 flex items-center justify-center text-[#54A6D8] font-bold shrink-0 overflow-hidden relative">
                         <?php 
                            $fotoUrlMovil = "";
                            if (!empty($publicador['foto_perfil'])) {
                                $fotoUrlMovil = "/app/perfil/fotos/" . htmlspecialchars($publicador['foto_perfil']);
                            }
                        ?>
                        <?php if($fotoUrlMovil): ?>
                            <img src="<?= $fotoUrlMovil ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                            <?= $iniciales_publicador ?>
                        <?php endif; ?>
                    </div>
                    <div class="overflow-hidden">
                        <p class="text-[10px] text-gray-400 font-bold uppercase leading-none mb-1">Publicado por</p>
                        <p class="font-bold text-gray-900 truncate flex items-center gap-1">
                            <?= htmlspecialchars($nombreDisplay) ?>
                            <i class="fa-solid fa-circle-check text-[#54A6D8] text-[10px]"></i>
                        </p>
                        <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($institucionPublicador) ?></p>
                    </div>
                </a>
            </div>

            <!-- VISOR -->
            <div class="bg-gray-200 rounded-2xl w-full relative h-[60vh] md:h-[70vh] border border-gray-200 flex flex-col" id="visor-wrapper">
                
                <?php if ($acceso_completo): ?>
                    <div class="bg-gray-800/90 backdrop-blur text-white p-3 flex items-center justify-between z-10 shrink-0 border-b border-white/10 rounded-t-2xl">
                        <div class="flex items-center gap-3">
                            <?php if ($isPDF): ?>
                                <button id="pdfPrev" class="w-8 h-8 flex items-center justify-center hover:bg-white/20 rounded-lg transition"><?= icon('chevron-left', 'w-4 h-4 text-white') ?></button>
                                <span class="text-xs md:text-sm font-semibold tabular-nums"><span id="page_num">1</span> / <span id="page_count">--</span></span>
                                <button id="pdfNext" class="w-8 h-8 flex items-center justify-center hover:bg-white/20 rounded-lg transition"><?= icon('chevron-right', 'w-4 h-4 text-white') ?></button>
                            <?php else: ?>
                                <span class="text-xs font-bold tracking-wide text-white/80 flex items-center gap-2"><?= icon('search', 'w-3 h-3') ?> VISUALIZADOR</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="<?= htmlspecialchars($fileUrl) ?>" download 
                               class="w-8 h-8 flex items-center justify-center hover:bg-white/20 rounded-lg text-white" 
                               title="Descargar"
                               data-track="click_contact" data-type="apunte" data-id="<?= $id_apunte ?>">
                                <?= icon('publish-doc', 'w-4 h-4') ?>
                            </a>
                        </div>
                    </div>

                    <div class="flex-grow relative overflow-auto bg-gray-900 flex justify-center items-center rounded-b-2xl" id="visor-container">
                        <?php if ($isPDF): ?>
    <!-- Skeleton mientras PDF.js carga -->
    <div id="pdf-skeleton" class="flex flex-col items-center justify-center gap-3 py-10">
        <div class="animate-spin h-8 w-8 border-3 border-gray-600 border-t-white rounded-full"></div>
        <span class="text-white/50 text-xs font-medium">Cargando documento...</span>
    </div>
    <canvas id="the-canvas" class="my-4 rounded-sm block hidden" style="max-width: 95%; height: auto;"></canvas>
<?php elseif ($isImage): ?>
                            <img id="image-target" src="<?= htmlspecialchars($inlineUrl) ?>" class="max-w-full max-h-full object-contain">
                        <?php else: ?>
                            <div class="text-white/70 text-sm p-6 text-center">Este tipo de archivo no se puede previsualizar aquí. Usa "Descargar".</div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <!-- VISTA PREVIA BLOQUEADA -->
                    <div class="absolute inset-0 w-full h-full bg-gray-200 z-0 rounded-2xl overflow-y-auto" id="preview-container">
                        <div class="flex flex-col items-center p-4 gap-4 pb-32">
                            <?php if (count($paginas_preview) > 0): ?>
                                <?php foreach ($paginas_preview as $idx => $ruta): ?>
                                    <div class="relative w-full max-w-[800px] bg-white border border-gray-200">
                                        <img src="<?= htmlspecialchars($ruta) ?>" class="w-full h-auto object-top transition-all duration-500" onerror="this.src='/img/logo2.webp'">
                                        <div class="absolute bottom-2 right-2 bg-black/40 text-white text-[10px] px-2 py-0.5 rounded backdrop-blur-sm">Pág <?= $idx + 1 ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="relative w-full max-w-[800px] bg-white border border-gray-200">
                                    <img id="previewImg" src="<?= htmlspecialchars($thumb_url) ?>" class="w-full h-auto object-top transition-all duration-500" onerror="this.src='/img/logo2.webp'">
                                </div>
                            <?php endif; ?>
                            <div class="w-full h-32 bg-gradient-to-b from-transparent to-gray-200 pointer-events-none"></div>
                        </div>
                    </div>
                    
                    <div class="absolute inset-0 flex flex-col justify-center items-center z-20 px-6 text-center transition-all duration-700 rounded-2xl pointer-events-none" id="capa-bloqueo">
                        
                        <div id="msgPreview" class="bg-white/90 backdrop-blur border border-gray-100 rounded-2xl px-6 py-3 shadow-xl mb-4 pointer-events-auto">
                            <p class="text-gray-800 font-bold text-sm flex items-center gap-2">
                                <span class="flex h-2 w-2 rounded-full bg-[#54A6D8] animate-ping"></span>
                                Vista previa: <span id="countdown-timer">5</span>s
                            </p>
                        </div>

                        <div id="msgLocked" class="hidden bg-white/95 backdrop-blur-md rounded-3xl p-6 shadow-2xl max-w-sm w-full animate-fade-in-up border border-gray-100 pointer-events-auto mb-12">
                            <div class="bg-blue-50 w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3">
                                <?= icon('lock', 'w-6 h-6 text-[#54A6D8]') ?>
                            </div>
                            <h3 class="text-lg font-bold text-gray-900 mb-1">Fin de la vista previa</h3>
                            <p class="text-xs text-gray-500 mb-4 leading-relaxed">Únete a Nubira para ver todas las páginas y descargar el archivo original sin marcas.</p>
                            
                            <?php if ($es_promo_activa): ?>
                                 <a href="/app/descargar_promo.php?id=<?= $token_seguro ?>"
                                    class="btn-descarga-promo-flash block w-full bg-[#54A6D8] hover:bg-blue-600 text-white font-bold py-3.5 rounded-xl transition text-center flex items-center justify-center gap-2"
                                    data-track="click_contact" data-type="apunte_promo" data-id="<?= $id_apunte ?>">
                                     <?= icon('publish-doc', 'w-5 h-5') ?> Descargar Gratis
                                 </a>
                                  <p class="text-xs font-semibold text-gray-500 mt-3 text-center tracking-wide">Quedan <?= $descargas_restantes ?> descargas</p>
                            <?php else: ?>
                                <?php if ($logueado): ?>
                                    <a href="/iniciar-pago?id_apunte=<?= $id_apunte ?>&archivo=<?= urlencode($archivo) ?>" 
                                       class="block w-full bg-[#54A6D8] hover:bg-[#4895c2] text-white font-bold py-3.5 rounded-2xl transition-all hover:scale-[1.02]"
                                       data-track="click_contact" data-type="apunte" data-id="<?= $id_apunte ?>">
                                        Desbloquear por <?= $precio_fmt ?>
                                    </a>
                                <?php else: ?>
                                    <a href="<?= $login_redir ?>" class="block w-full bg-[#54A6D8] text-white font-bold py-3.5 rounded-2xl hover:scale-[1.02] transition-all flex justify-center items-center gap-2">
                                        <?= icon('user', 'w-5 h-5') ?> <?= ($precio === 0) ? 'Inicia sesión para ver gratis' : 'Inicia sesión para comprar' ?>
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- IA TAGS + DESCRIPCIÓN -->
            <div class="space-y-6">
                <?php if ($has_ia && count($ia_tags) > 0): ?>
                <div class="bg-gray-50 rounded-2xl p-4 md:p-6 border border-gray-200">
                    <p class="text-[10px] md:text-xs font-bold text-gray-500 uppercase mb-3 tracking-wide">Etiquetas y materias detectadas</p>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach($ia_tags as $tag): ?>
                            <span class="px-3 py-1.5 bg-white border border-gray-200 rounded-md text-xs font-medium text-gray-700 hover:border-gray-300 transition-colors"><?= htmlspecialchars(ucfirst($tag)) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($descripcion)): ?>
                    <div class="text-sm md:text-base text-gray-600 bg-white p-5 md:p-6 rounded-2xl border border-gray-100">
                        <p class="text-[10px] md:text-xs text-gray-400 font-bold uppercase mb-3">Descripción del Apunte</p>
                        <p class="leading-relaxed text-gray-700"><?= $descripcion ?></p>
                    </div>
                <?php endif; ?>
            </div>
            
        </div>

        <!-- SIDEBAR DERECHO -->
        <div class="lg:col-span-4">
            <div class="sticky top-24 space-y-6">
                
                <div class="bg-white rounded-2xl border border-gray-200 p-6">
                    <div class="hidden lg:block mb-6 pb-6 border-b border-gray-100">
                        <span class="bg-gray-100 text-gray-700 px-3 py-1 rounded-full text-xs font-semibold uppercase tracking-wide mb-3 inline-block border border-gray-200">
                            Asignatura: <?= htmlspecialchars($apunte['asignatura'] ?? 'Apunte') ?>
                        </span>
                        <h1 class="text-2xl font-bold text-gray-900 leading-tight"><?= $titulo ?></h1>
                    </div>
                    
                    <div class="flex items-end justify-between mb-6">
                        <div>
                            <p class="text-xs text-gray-400 font-bold uppercase mb-1">Precio</p>
                            <p class="text-3xl font-bold text-gray-900" id="precio-block"><?= $precio_fmt ?></p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-gray-400 font-bold uppercase mb-1">Descargas</p>
                            <p class="font-bold text-gray-700 flex items-center justify-end gap-1">
                                <?= icon('publish-doc', 'w-4 h-4 text-gray-400') ?> <?= $total_ventas ?>
                            </p>
                        </div>
                    </div>
                    
                    <?php if ($es_promo_activa && !$acceso_completo): ?>
                        <div class="space-y-3">
                            <a href="/app/descargar_promo.php?id=<?= $token_seguro ?>" target="_blank"
                               class="btn-descarga-promo-flash block w-full bg-[#54A6D8] text-white font-bold py-3.5 rounded-xl hover:bg-blue-600 transition text-center flex items-center justify-center gap-2"
                               data-track="click_contact" data-type="apunte_promo" data-id="<?= $id_apunte ?>">
                                <?= icon('publish-doc', 'w-5 h-5') ?> Descargar Gratis
                            </a>
                            <p class="text-[10px] font-bold text-center text-gray-500 uppercase tracking-widest mt-2">Promo Limitada: Quedan <?= $descargas_restantes ?></p>
                        </div>
                    <?php elseif ($acceso_completo): ?>
                        <div class="space-y-3">
                            <a href="<?= htmlspecialchars($fileUrl) ?>" download
                               class="block w-full bg-[#54A6D8] text-white font-bold py-3.5 rounded-xl hover:bg-blue-600 transition text-center flex items-center justify-center gap-2"
                               data-track="click_contact" data-type="apunte" data-id="<?= $id_apunte ?>">
                                <?= icon('publish-doc', 'w-5 h-5') ?> Descargar Archivo
                            </a>
                            <?php if ($es_dueno): ?>
                                <a href="/app/editar_apunte.php?id=<?= $id_apunte ?>" class="block w-full bg-gray-100 text-gray-700 font-bold py-3.5 rounded-xl text-center hover:bg-gray-200 transition flex items-center justify-center gap-2">
                                    <?= icon('pencil', 'w-4 h-4') ?> Editar Apunte
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php if (!$logueado && $precio === 0): ?>
                            <a href="<?= $login_redir ?>" class="block w-full bg-[#54A6D8] text-white font-bold py-3.5 rounded-xl hover:bg-blue-600 transition flex items-center justify-center gap-2">
                                <?= icon('publish-doc', 'w-5 h-5') ?> Inicia sesión para descargar
                            </a>
                        <?php elseif (!$logueado && $precio > 0): ?>
                            <a href="<?= $login_redir ?>" class="block w-full bg-[#54A6D8] text-white font-bold py-3.5 rounded-xl hover:bg-[#4895c2] transition flex items-center justify-center gap-2">
                                <?= icon('lock', 'w-5 h-5') ?> Comprar por <?= $precio_fmt ?>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- COMPARTIR -->
                    <div class="mt-6 pt-6 border-t border-gray-100">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest text-center mb-3">Compartir apunte</p>
                        <div class="flex flex-wrap justify-center gap-3">
                            <a href="https://api.whatsapp.com/send?text=<?= $share_txt ?>%20<?= urlencode($url_apunte_masked) ?>" target="_blank" class="w-11 h-11 bg-gray-50 text-[#25D366] border border-gray-100 rounded-full flex items-center justify-center shadow-sm hover:bg-[#25D366] hover:text-white hover:border-[#25D366] transition-all duration-300" title="Compartir en WhatsApp">
                                <i class="fab fa-whatsapp text-lg"></i>
                            </a>
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($url_apunte_masked) ?>" target="_blank" class="w-11 h-11 bg-gray-50 text-[#1877F2] border border-gray-100 rounded-full flex items-center justify-center shadow-sm hover:bg-[#1877F2] hover:text-white hover:border-[#1877F2] transition-all duration-300" title="Compartir en Facebook">
                                <i class="fab fa-facebook-f text-lg"></i>
                            </a>
                            <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= urlencode($url_apunte_masked) ?>" target="_blank" class="w-11 h-11 bg-gray-50 text-[#0A66C2] border border-gray-100 rounded-full flex items-center justify-center shadow-sm hover:bg-[#0A66C2] hover:text-white hover:border-[#0A66C2] transition-all duration-300" title="Compartir en LinkedIn">
                                <i class="fab fa-linkedin-in text-lg"></i>
                            </a>
                            <button id="btn-copiar-enlace" data-url="<?= htmlspecialchars($url_apunte_masked) ?>" class="w-11 h-11 bg-gray-50 text-gray-500 border border-gray-100 rounded-full flex items-center justify-center shadow-sm hover:bg-gray-600 hover:text-white hover:border-gray-600 transition-all duration-300" title="Copiar Enlace">
                                <i class="fas fa-link text-lg" id="copy-icon"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- PUBLICADOR (DESKTOP) -->
                <div class="hidden lg:block bg-gray-50 rounded-2xl p-5 border border-gray-100 space-y-4">
                    <a href="/perfil/<?= (int)$apunte['id_alumno'] ?>" class="group flex items-center gap-4 pb-4 w-full transition-colors track-seller">
                        <div class="w-14 h-14 rounded-full border border-gray-200 bg-white overflow-hidden flex-shrink-0 relative">
                            <?php 
                                $fotoUrl = "";
                                if (!empty($publicador['foto_perfil'])) {
                                    $fotoUrl = "/app/perfil/fotos/" . htmlspecialchars($publicador['foto_perfil']);
                                }
                            ?>
                            <?php if($fotoUrl): ?>
                                <img src="<?= $fotoUrl ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center bg-blue-50 text-[#54A6D8] font-bold text-lg"><?= $iniciales_publicador ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-gray-900 group-hover:text-[#54A6D8] transition-colors flex items-center gap-1">
                                Publicado por <?= htmlspecialchars($nombreDisplay) ?>
                                <?php if ($publicador_verificado ?? false): ?><?= icon('check-circle', 'w-3.5 h-3.5 text-[#54A6D8]') ?><?php endif; ?>
                            </p>
                            <p class="text-xs text-gray-500 flex items-start gap-1 mt-0.5 leading-snug">
                                <span class="mt-0.5 flex-shrink-0"><?= icon('building', 'w-3 h-3') ?></span>
                                <span class="break-words font-medium uppercase"><?= htmlspecialchars($institucionPublicador ?: 'Estudiante') ?></span>
                            </p>
                        </div>
                    </a>
                </div>

            </div>
        </div>
    </div>

    <!-- RECOMENDADOS -->
    <?php if ($recomendados && $recomendados->num_rows > 0): ?>
    <section class="mt-12 mb-12 overflow-hidden">
        <h3 class="text-lg md:text-xl font-bold text-gray-900 tracking-tight mb-6">Quizás te interese</h3>
        <div class="flex gap-4 overflow-x-auto pb-6 scrollbar-hide snap-x snap-mandatory">
            <?php while ($rec = $recomendados->fetch_assoc()): 
                $p_fmt = ($rec['precio'] > 0) ? "$" . number_format($rec['precio'], 0, ',', '.') : "Gratis";
                $thumb = miniatura_apunte($rec['id'], $rec['portada'] ?? '', $rec['archivo'] ?? '');
                $rec_hash = function_exists('nubira_encriptar_id') ? nubira_encriptar_id($rec['id']) : $rec['id'];
            ?>
            <a href="/apunte/<?= $rec_hash ?>" class="block rounded-xl flex flex-col cursor-pointer w-[240px] md:w-[280px] flex-shrink-0 bg-transparent group transition-transform duration-300 hover:-translate-y-1">

                <div class="relative w-full aspect-[4/3] bg-gray-100 overflow-hidden rounded-xl border border-gray-200">
                    <img src="<?= $thumb ?>"
                         alt="<?= htmlspecialchars($rec['titulo'] ?? '') ?>"
                         class="w-full h-full object-cover object-top transition-transform duration-500 group-hover:scale-105"
                         loading="lazy">
                </div>

                <div class="pt-2.5 flex flex-col flex-1 text-left">
                    <h3 class="font-semibold text-[14px] leading-snug text-gray-900 line-clamp-2 mb-1 min-h-[40px]"><?= htmlspecialchars($rec['titulo'] ?? '') ?></h3>
                    <p class="text-[13px] text-gray-600 line-clamp-2 leading-snug mb-1 min-h-[36px]"><?= htmlspecialchars($rec['descripcion'] ?? '') ?></p>
                    <div class="text-[14px] mb-1.5 leading-none">
                        <span class="text-gray-700 font-semibold tracking-tight"><?= $p_fmt ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-1.5 text-[10px] text-gray-400 font-bold uppercase tracking-wide truncate max-w-[65%]">
                            <span class="truncate"><?= abreviar_institucion($rec['institucion'] ?? '') ?></span>
                        </div>
                        <div class="flex items-center gap-1 text-[11px] text-gray-500">
                            <svg class="w-3 h-3 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            <?= (int)($rec['descargas'] ?? 0) ?>
                        </div>
                    </div>
                </div>
            </a>
            <?php endwhile; ?>
        </div>
    </section>
    <?php endif; ?>

  </div>
  <div class="h-16 lg:hidden"></div>
</main>

<?php 
// [OPT-4] Modales extraídos a componentes reutilizables
$modal_captacion_path = $base_path . '/componentes/modal_captacion.php';
$modal_alumno_path = $base_path . '/componentes/modal_beneficios_alumno.php';

// Si existen los componentes, úsalos. Si no, fallback inline mínimo.
if (file_exists($modal_captacion_path)) {
    require_once $modal_captacion_path;
} else {
    // Fallback: modal captación inline (para retrocompatibilidad)
?>
<div id="modal-captacion" class="fixed inset-0 z-[100] flex items-center justify-center hidden opacity-0 transition-opacity duration-300 bg-gray-900/40 backdrop-blur-sm px-4 md:p-0">
    <div id="card-captacion" class="bg-white w-full max-w-[850px] rounded-2xl md:rounded-3xl border border-gray-200 transform translate-y-full scale-95 transition-all duration-300 overflow-hidden relative flex flex-col max-h-[85vh] md:max-h-[90vh]">
        <div class="px-5 py-4 md:px-6 md:py-5 border-b border-gray-100 flex justify-between items-center bg-white shrink-0">
            <div>
                <h3 class="text-lg md:text-2xl font-bold text-gray-900 tracking-tight leading-tight">¿Haces clases o vendes apuntes?</h3>
                <p class="text-xs md:text-sm text-gray-500 mt-0.5">Descubre por qué los mejores tutores usan Nubira.</p>
            </div>
            <button onclick="cerrarModalCaptacion()" class="p-2 bg-gray-50 hover:bg-gray-100 rounded-full transition-all hover:scale-[1.05] shrink-0 ml-4"><i class="fa-solid fa-xmark text-gray-500"></i></button>
        </div>
        <div class="flex-1 overflow-y-auto overflow-x-hidden p-0 bg-white">
            <div class="grid grid-cols-1 md:grid-cols-2 h-full">
                <div class="bg-gray-50 p-5 md:p-8 border-b md:border-b-0 md:border-r border-gray-100">
                    <div class="flex items-center gap-3 mb-4 md:mb-6"><div class="w-8 h-8 md:w-10 md:h-10 rounded-xl bg-red-100 text-red-500 flex items-center justify-center shrink-0"><i class="fa-solid fa-chalkboard-user text-lg md:text-xl"></i></div><h4 class="text-base md:text-lg font-bold text-gray-800 tracking-tight">Dar clases por RRSS</h4></div>
                    <ul class="space-y-4 md:space-y-5 text-xs md:text-sm text-gray-600">
                        <li class="flex items-start gap-2.5"><span class="text-red-400 mt-0.5 shrink-0"><?= icon('x-mark', 'w-4 h-4') ?></span><div><strong class="text-gray-800 block mb-0.5">Te dejan en "visto"</strong>Pierdes tiempo respondiendo mensajes a personas que preguntan precios y luego desaparecen.</div></li>
                        <li class="flex items-start gap-2.5"><span class="text-red-400 mt-0.5 shrink-0"><?= icon('x-mark', 'w-4 h-4') ?></span><div><strong class="text-gray-800 block mb-0.5">Cobros incómodos</strong>Tienes que perseguir a los alumnos para que transfieran.</div></li>
                        <li class="flex items-start gap-2.5"><span class="text-red-400 mt-0.5 shrink-0"><?= icon('x-mark', 'w-4 h-4') ?></span><div><strong class="text-gray-800 block mb-0.5">Agenda desordenada</strong>Agendar por WhatsApp mezcla tu vida personal con tus alumnos.</div></li>
                    </ul>
                </div>
                <div class="bg-white p-5 md:p-8 flex flex-col">
                    <div class="flex items-center gap-3 mb-4 md:mb-6"><div class="w-8 h-8 md:w-10 md:h-10 rounded-xl bg-sky-100 text-[#54A6D8] flex items-center justify-center shrink-0"><i class="fa-solid fa-rocket text-lg md:text-xl"></i></div><h4 class="text-base md:text-lg font-bold text-gray-800 tracking-tight">Enseñar en Nubira</h4></div>
                    <ul class="space-y-4 md:space-y-5 text-xs md:text-sm text-gray-600">
                        <li class="flex items-start gap-2.5"><span class="text-[#54A6D8] mt-0.5 shrink-0"><?= icon('check-circle', 'w-5 h-5') ?></span><div><strong class="text-gray-800 block mb-0.5">Pagos 100% garantizados</strong>El alumno paga por adelantado. Tu dinero está seguro siempre.</div></li>
                        <li class="flex items-start gap-2.5"><span class="text-[#54A6D8] mt-0.5 shrink-0"><?= icon('check-circle', 'w-5 h-5') ?></span><div><strong class="text-gray-800 block mb-0.5">Ventas en automático</strong>Sube tus apuntes una vez y genera ingresos 24/7.</div></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="p-4 md:p-6 bg-white border-t border-gray-100 shrink-0">
            <a href="/registro?rol=tutor" class="block w-full text-center py-3 md:py-4 rounded-2xl font-bold text-white bg-gradient-to-r from-sky-400 to-[#54A6D8] transition-all hover:scale-[1.02] text-sm md:text-base">Crea tu cuenta gratis</a>
        </div>
    </div>
</div>
<?php } ?>

<?php
if (file_exists($modal_alumno_path)) {
    require_once $modal_alumno_path;
} else {
?>
<div id="modal-beneficios-alumno" class="fixed inset-0 z-[100] flex items-center justify-center hidden opacity-0 transition-opacity duration-300 bg-gray-900/40 backdrop-blur-sm px-4 md:p-0">
    <div id="card-beneficios-alumno" class="bg-white w-full max-w-[850px] rounded-2xl md:rounded-3xl border border-gray-200 transform translate-y-full scale-95 transition-all duration-300 overflow-hidden relative flex flex-col max-h-[85vh] md:max-h-[90vh]">
        <div class="px-5 py-4 md:px-6 md:py-5 border-b border-gray-100 flex justify-between items-center bg-white shrink-0">
            <div>
                <h3 class="text-lg md:text-2xl font-bold text-gray-900 tracking-tight leading-tight">¿Buscas apuntes o tutorías?</h3>
                <p class="text-xs md:text-sm text-gray-500 mt-0.5">La forma inteligente de salvar el semestre.</p>
            </div>
            <button onclick="cerrarModalAlumno()" class="p-2 bg-gray-50 hover:bg-gray-100 rounded-full transition-all hover:scale-[1.05] shrink-0 ml-4"><i class="fa-solid fa-xmark text-gray-500"></i></button>
        </div>
        <div class="flex-1 overflow-y-auto overflow-x-hidden p-0 bg-white">
            <div class="grid grid-cols-1 md:grid-cols-2 h-full">
                <div class="bg-gray-50 p-5 md:p-8 border-b md:border-b-0 md:border-r border-gray-100">
                    <div class="flex items-center gap-3 mb-4 md:mb-6"><div class="w-8 h-8 md:w-10 md:h-10 rounded-xl bg-red-100 text-red-500 flex items-center justify-center shrink-0"><i class="fa-brands fa-whatsapp text-lg md:text-xl"></i></div><h4 class="text-base md:text-lg font-bold text-gray-800 tracking-tight">Buscar en RRSS</h4></div>
                    <ul class="space-y-4 md:space-y-5 text-xs md:text-sm text-gray-600">
                        <li class="flex items-start gap-2.5"><span class="text-red-400 mt-0.5 shrink-0"><?= icon('x-mark', 'w-4 h-4') ?></span><div><strong class="text-gray-800 block mb-0.5">Apuntes basura o virus</strong>Descargas PDFs dudosos que no tienen la materia de tu prueba.</div></li>
                        <li class="flex items-start gap-2.5"><span class="text-red-400 mt-0.5 shrink-0"><?= icon('x-mark', 'w-4 h-4') ?></span><div><strong class="text-gray-800 block mb-0.5">Estafas con tutores</strong>Transfieres por adelantado en Instagram y desaparecen.</div></li>
                        <li class="flex items-start gap-2.5"><span class="text-red-400 mt-0.5 shrink-0"><?= icon('x-mark', 'w-4 h-4') ?></span><div><strong class="text-gray-800 block mb-0.5">Tiempo perdido</strong>Pierdes horas rogando por accesos a Drive en grupos muertos.</div></li>
                    </ul>
                </div>
                <div class="bg-white p-5 md:p-8 flex flex-col">
                    <div class="flex items-center gap-3 mb-4 md:mb-6"><div class="w-8 h-8 md:w-10 md:h-10 rounded-xl bg-sky-100 text-[#54A6D8] flex items-center justify-center shrink-0"><?= icon('academic-cap', 'w-5 h-5') ?></div><h4 class="text-base md:text-lg font-bold text-gray-800 tracking-tight">Estudiar con Nubira</h4></div>
                    <ul class="space-y-4 md:space-y-5 text-xs md:text-sm text-gray-600">
                        <li class="flex items-start gap-2.5"><span class="text-[#54A6D8] mt-0.5 shrink-0"><?= icon('check-circle', 'w-5 h-5') ?></span><div><strong class="text-gray-800 block mb-0.5">Material verificado</strong>Resúmenes filtrados por estudiantes reales de tu universidad.</div></li>
                        <li class="flex items-start gap-2.5"><span class="text-[#54A6D8] mt-0.5 shrink-0"><?= icon('check-circle', 'w-5 h-5') ?></span><div><strong class="text-gray-800 block mb-0.5">Dinero 100% protegido</strong>Si el apunte o clase no cumple, tienes respaldo total.</div></li>
                        <li class="flex items-start gap-2.5"><span class="text-[#54A6D8] mt-0.5 shrink-0"><?= icon('check-circle', 'w-5 h-5') ?></span><div><strong class="text-gray-800 block mb-0.5">Descarga instantánea</strong>Haces clic y el PDF es tuyo de inmediato. Sin esperas.</div></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="p-4 md:p-6 bg-white border-t border-gray-100 shrink-0">
            <a href="/registro?rol=alumno" class="block w-full text-center py-3 md:py-4 rounded-2xl font-bold text-white bg-gradient-to-r from-sky-400 to-[#54A6D8] transition-all hover:scale-[1.02] text-sm md:text-base">Crea tu cuenta gratis</a>
        </div>
    </div>
</div>
<?php } ?>

<?php
require_once $base_path . '/componentes/nav_bottom.php';
require_once $base_path . '/componentes/modal_publicar.php';
require_once $base_path . '/componentes/modal_explora.php';
?>

<?php if (!$es_dueno): ?>
<div id="barra-apunte-movil"
     class="lg:hidden fixed bottom-0 left-0 right-0 bg-white/95 backdrop-blur-md border-t border-gray-100 shadow-[0_-4px_12px_rgba(0,0,0,0.04)] z-40 px-4 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))]">
    <div class="flex items-center justify-between gap-3">

        <div class="flex flex-col min-w-0 flex-1">
            <?php if ($acceso_completo): ?>
                <span class="text-xs text-gray-400 font-medium">Ya tienes acceso</span>
            <?php else: ?>
                <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wide leading-none mb-0.5">Precio</span>
                <span class="text-xl font-extrabold text-gray-900 tracking-tight leading-none"><?= $precio_fmt ?></span>
            <?php endif; ?>
        </div>

        <?php if ($es_promo_activa && !$acceso_completo): ?>
            <a href="/app/descargar_promo.php?id=<?= $token_seguro ?>"
               class="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl px-5 py-3 text-sm shadow-md active:scale-95 transition-all whitespace-nowrap flex items-center gap-2"
               data-track="click_contact" data-type="apunte_promo" data-id="<?= $id_apunte ?>">
                <?= icon('publish-doc', 'w-4 h-4') ?> Descargar gratis
            </a>
        <?php elseif ($acceso_completo): ?>
            <a href="<?= htmlspecialchars($fileUrl) ?>" download
               class="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl px-5 py-3 text-sm shadow-md active:scale-95 transition-all whitespace-nowrap flex items-center gap-2"
               data-track="click_contact" data-type="apunte" data-id="<?= $id_apunte ?>">
                <?= icon('publish-doc', 'w-4 h-4') ?> Descargar
            </a>
        <?php elseif (!$logueado && $precio === 0): ?>
            <a href="<?= $login_redir ?>"
               class="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl px-5 py-3 text-sm shadow-md active:scale-95 transition-all whitespace-nowrap flex items-center gap-2">
                <?= icon('user', 'w-4 h-4') ?> Inicia sesión
            </a>
        <?php elseif (!$logueado && $precio > 0): ?>
            <a href="<?= $login_redir ?>"
               class="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl px-5 py-3 text-sm shadow-md active:scale-95 transition-all whitespace-nowrap flex items-center gap-2">
                <?= icon('lock', 'w-4 h-4') ?> Comprar
            </a>
        <?php elseif ($logueado && !$acceso_completo && !$es_promo_activa): ?>
            <a href="/iniciar-pago?id_apunte=<?= $id_apunte ?>&archivo=<?= urlencode($archivo) ?>"
               class="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl px-5 py-3 text-sm shadow-md active:scale-95 transition-all whitespace-nowrap flex items-center gap-2"
               data-track="click_contact" data-type="apunte" data-id="<?= $id_apunte ?>">
                <?= icon('lock', 'w-4 h-4') ?> Desbloquear
            </a>
        <?php endif; ?>

    </div>
</div>
<?php endif; ?>
<script>
window.onload = () => { const l = document.getElementById('loader'); if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } };

// Copiar Enlace
const bc = document.getElementById('btn-copiar-enlace');
if(bc) {
    bc.addEventListener('click', function(e) {
        e.preventDefault();
        navigator.clipboard.writeText(this.getAttribute('data-url')).then(() => {
            const i = document.getElementById('copy-icon');
            if(i) { i.className = 'fa-solid fa-check text-green-500'; setTimeout(() => i.className = 'fas fa-link', 2000); }
        });
    });
}

// Promo Flash: Descarga con iframe + recarga
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.btn-descarga-promo-flash').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault(); 
            const urlDescarga = this.href;
            this.innerHTML = '<div class="absolute inset-0 bg-gradient-to-r from-orange-400 to-orange-500 opacity-20 pointer-events-none animate-pulse"></div><span class="relative z-10 flex items-center gap-2"><i class="fa-solid fa-circle-notch fa-spin text-orange-400 w-5 h-5"></i> Procesando...</span>';
            this.classList.add('opacity-75', 'pointer-events-none', 'cursor-not-allowed');
            const iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.src = urlDescarga;
            document.body.appendChild(iframe);
            setTimeout(() => { window.location.reload(); }, 2500);
        });
    });

    // Modales
    setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
    setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
});

<?php if ($acceso_completo && $isPDF): ?>
// PDF ENGINE
const url = "/app/ver_pdf_apunte.php?id=<?= (int)$id_apunte ?>";
let pdfDoc = null, pageNum = 1, pageRendering = false, pageNumPending = null;
let scale = window.innerWidth < 768 ? 1.0 : 1.5; 
const canvas = document.getElementById('the-canvas');
const ctx = canvas.getContext('2d', { willReadFrequently: true });

const renderPage = (num) => {
    pageRendering = true;
    pdfDoc.getPage(num).then((page) => {
        const viewport = page.getViewport({scale: scale});
        const outputScale = window.devicePixelRatio || 1;
        canvas.width = Math.floor(viewport.width * outputScale);
        canvas.height = Math.floor(viewport.height * outputScale);
        canvas.style.width = Math.floor(viewport.width) + "px";
        canvas.style.height = Math.floor(viewport.height) + "px";
        const renderContext = { canvasContext: ctx, viewport: viewport, transform: [outputScale, 0, 0, outputScale, 0, 0] };
      page.render(renderContext).promise.then(() => {
    // Mostrar canvas y ocultar skeleton en el primer render
    canvas.classList.remove('hidden');
    const skel = document.getElementById('pdf-skeleton');
    if (skel) skel.remove();
    
    pageRendering = false;
    if (pageNumPending !== null) { renderPage(pageNumPending); pageNumPending = null; }
}).catch(err => console.warn('Página cancelada/reemplazada', err));
    });
    document.getElementById('page_num').textContent = num;
};

const queueRenderPage = (num) => { if (pageRendering) pageNumPending = num; else renderPage(num); };

pdfjsLib.getDocument(url).promise.then((pdfDoc_) => {
    pdfDoc = pdfDoc_;
    document.getElementById('page_count').textContent = pdfDoc.numPages;
    renderPage(pageNum);
}).catch(err => {
    console.error('Error al cargar PDF:', err);
    const container = document.getElementById('visor-container');
    if(container) { container.innerHTML = `<div class="text-red-400 p-6 text-center text-sm font-bold"><i class="fa-solid fa-triangle-exclamation mb-2 text-2xl"></i><br>Error al cargar el documento.</div>`; }
});

document.getElementById('pdfPrev').addEventListener('click', () => { if(pageNum <= 1) return; pageNum--; queueRenderPage(pageNum); });
document.getElementById('pdfNext').addEventListener('click', () => { if(pageNum >= pdfDoc.numPages) return; pageNum++; queueRenderPage(pageNum); });
document.addEventListener('keydown', (e) => {
    if(e.key === 'ArrowLeft' && pageNum > 1) { pageNum--; queueRenderPage(pageNum); }
    if(e.key === 'ArrowRight' && pageNum < pdfDoc.numPages) { pageNum++; queueRenderPage(pageNum); }
});
<?php endif; ?>

<?php if (!$acceso_completo): ?>
// Countdown con visibilitychange awareness
(function(){
    let seconds = 5;
    const countSpan = document.getElementById('countdown-timer');
    const msgPrev = document.getElementById('msgPreview');
    const msgLocked = document.getElementById('msgLocked');
    const cap = document.getElementById('capa-bloqueo');

    document.addEventListener('contextmenu', event => event.preventDefault());

    // [FIX UX] Pausar countdown cuando la pestaña no es visible
    let lastTick = Date.now();
    const interval = setInterval(() => {
        // Solo decrementar si la pestaña está visible
        if (document.hidden) return;
        
        seconds--;
        if(countSpan) countSpan.innerText = seconds;
        if (seconds <= 0) {
            clearInterval(interval);
            if(msgPrev) msgPrev.classList.add('hidden');
            if(msgLocked) msgLocked.classList.remove('hidden');
            if(cap) { cap.classList.add('bg-gray-900/40', 'backdrop-blur-[3px]'); }
        }
    }, 1000);
})();
<?php endif; ?>

// [NUBIRA 2.0] SMART BACK: Previene bucles infinitos con pasarelas de pago
window.navegacionSeguraNubira = function() {
    let ref = document.referrer.toLowerCase();
    if (ref.includes('mercadopago') || ref.includes('pago_error') || ref.includes('contratar_servicio') || ref.includes('iniciar_pago') || ref.includes('iniciar-pago')) {
        window.location.href = '/vitrina-apuntes';
    } else if (window.history.length > 1) {
        window.history.back();
    } else {
        window.location.href = '/vitrina-apuntes';
    }
};

// Modal helpers (unificado)
function setupModal(triggerId, modalId, cardId, closeId) {
    const btn=document.getElementById(triggerId), modal=document.getElementById(modalId), card=document.getElementById(cardId), close=document.getElementById(closeId);
    if(!btn||!modal) return;
    const open=()=>{modal.classList.remove('hidden'); requestAnimationFrame(()=>card.classList.remove('translate-y-full','opacity-0')); document.body.style.overflow='hidden';};
    const shut=()=>{card.classList.add('translate-y-full','opacity-0'); setTimeout(()=>{modal.classList.add('hidden');document.body.style.overflow='';},300);};
    btn.onclick = (e) => { 
        e.preventDefault(); 
        <?php if (!$logueado): ?>
            if (triggerId === 'btn-publicar') {
                window.location.href = '/login?redir=' + encodeURIComponent(window.location.pathname + window.location.search);
                return;
            }
        <?php endif; ?>
        open(); 
    };
    if(close) close.onclick=shut;
    modal.onclick=(e)=>{if(e.target===modal)shut();};
}

function abrirModalCaptacion() {
    const modal = document.getElementById('modal-captacion'), card = document.getElementById('card-captacion');
    if(!modal || !card) return;
    modal.classList.remove('hidden');
    requestAnimationFrame(() => { modal.classList.remove('opacity-0'); card.classList.remove('translate-y-full', 'scale-95'); });
    document.body.style.overflow = 'hidden';
}
function cerrarModalCaptacion() {
    const modal = document.getElementById('modal-captacion'), card = document.getElementById('card-captacion');
    card.classList.add('translate-y-full', 'scale-95'); modal.classList.add('opacity-0');
    setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300);
}
function abrirModalAlumno() {
    const modal = document.getElementById('modal-beneficios-alumno'), card = document.getElementById('card-beneficios-alumno');
    if(!modal || !card) return;
    modal.classList.remove('hidden');
    requestAnimationFrame(() => { modal.classList.remove('opacity-0'); card.classList.remove('translate-y-full', 'scale-95'); });
    document.body.style.overflow = 'hidden';
}
function cerrarModalAlumno() {
    const modal = document.getElementById('modal-beneficios-alumno'), card = document.getElementById('card-beneficios-alumno');
    card.classList.add('translate-y-full', 'scale-95'); modal.classList.add('opacity-0');
    setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300);
}
</script>

<!-- [NUBIRA TRACKER] Engagement tracking - NO modificar sin revisar track_vista.php -->
<script>
(function() {
    var TIPO = 'apunte';
    var PUB_ID = <?= (int)($apunte['id'] ?? 0) ?>;
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

<?php
$ruta_footer = $base_path . '/includes/footer.php';
if (!file_exists($ruta_footer)) $ruta_footer = __DIR__ . '/app/includes/footer.php';
if (!file_exists($ruta_footer)) $ruta_footer = $_SERVER['DOCUMENT_ROOT'] . '/app/includes/footer.php';
if (file_exists($ruta_footer)) { require_once $ruta_footer; } else { echo "</body></html>"; }
?>
<script src="/assets/js/behavior_tracker.js"></script>