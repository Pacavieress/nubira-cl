<?php
/**
 * VISTA: PERFIL DE USUARIO (RESPONSIVE FIX + LAZY REGISTRATION + ECOSISTEMA NUBIRA 2.0)
 * ESTADO: BLINDADO (NUBIRA SHIELD) + SOFT DELETE FILTER + GAMIFICACIÓN + SOCIAL PROOF
 */

// Errores: registrar en log, nunca mostrar al usuario
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// === BLOQUE ANTI-CACHE ===
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 1. CARGA MAESTRA DE SEGURIDAD
require_once __DIR__ . '/init_sesion.php'; 
require_once __DIR__ . '/iconos.php';        

// [NUBIRA SHIELD] Cargar enmascarador de URLs
$rutas_shield = [__DIR__ . '/seguridad_url.php', __DIR__ . '/app/seguridad_url.php', $_SERVER['DOCUMENT_ROOT'] . '/app/seguridad_url.php'];
foreach ($rutas_shield as $rs) {
    if (file_exists($rs)) {
        require_once $rs;
        break;
    }
}

// 2. SEGURIDAD MODIFICADA (LAZY REGISTRATION)
$is_guest = !isset($_SESSION['usuario_id']);

// FIX: Sanitizar REQUEST_URI (definido siempre, usado en redirecciones)
$raw_uri = $_SERVER['REQUEST_URI'] ?? '/';
$safe_uri = filter_var($raw_uri, FILTER_SANITIZE_URL);
if (strpos($safe_uri, '/') !== 0 || strpos($safe_uri, '//') === 0) {
    $safe_uri = '/';
}

if ($is_guest) {
    $_SESSION['redirigir_despues_login'] = $safe_uri;
}

// FIX: Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 3. ADAPTADOR DE VARIABLES
$usuario_logueado_id = !$is_guest ? (int)$_SESSION['usuario_id'] : 0; 
$rol_logueado        = $_SESSION['rol'] ?? 'visitante';
$es_visitante        = $is_guest;

// 4. RESOLUCIÓN DE IDENTIDAD (NUBIRA SHIELD)
$perfil_id_ver = 0;

if (isset($_GET['id'])) { 
    $param_id = $_GET['id'];
    
    if (is_numeric($param_id)) {
        // FIX: Redirigir a hash en vez de aceptar numérico
        if (function_exists('nubira_encriptar_id')) {
            $hash_seguro = nubira_encriptar_id($param_id);
            header("Location: /perfil/" . $hash_seguro, true, 301);
            exit;
        }
    } else {
        if (function_exists('nubira_desencriptar_id')) {
            $perfil_id_ver = nubira_desencriptar_id($param_id);
        }
    }
}

// FIX: Cerrar bypass numérico — solo aceptar hashes, no IDs numéricos directos
if ($perfil_id_ver <= 0 && !empty($_SERVER['REQUEST_URI'])) {
    if (preg_match('#^/perfil/([0-9]+)(?:\?|$)#', $_SERVER['REQUEST_URI'], $m)) { 
        // ID numérico en URL: redirigir a hash si existe la función, sino 404
        if (function_exists('nubira_encriptar_id')) {
            $hash_seguro = nubira_encriptar_id((int)$m[1]);
            header("Location: /perfil/" . $hash_seguro, true, 301);
            exit;
        } else {
            // Sin shield activo, aceptar como fallback
            $perfil_id_ver = (int)$m[1];
        }
    }
}

// 5. EL CANDADO INTELIGENTE
if ($perfil_id_ver <= 0) {
    if ($es_visitante) {
        header("Location: /login?redir=" . urlencode($safe_uri ?? $_SERVER['REQUEST_URI']));
        exit;
    } else {
        // Fallback: mostrar perfil propio
        $perfil_id_ver = $usuario_logueado_id; 
    }
}

// 6. DEFINIR PERMISOS
$es_propio = ($usuario_logueado_id === $perfil_id_ver && !$es_visitante);
$es_admin  = ($rol_logueado === 'admin');

// 7. HELPERS VISUALES
if (!function_exists('formatearNombrePrivado')) {
    function formatearNombrePrivado($nombre_completo) {
        $partes = array_values(array_filter(explode(' ', trim((string)$nombre_completo))));
        if (empty($partes[0])) return "Usuario";
        $p_nombre = ucwords(strtolower($partes[0]));
        $inicial = '';
        if (count($partes) >= 2) {
            $inicial = ' ' . strtoupper(substr($partes[count($partes)-1], 0, 1)) . '.';
        }
        return $p_nombre . $inicial;
    }
}

if (!function_exists('tiempo_transcurrido')) {
    function tiempo_transcurrido($fecha_db) {
        if (empty($fecha_db)) return '';
        $timestamp = strtotime($fecha_db);
        $diferencia = time() - $timestamp;

        if ($diferencia < 60) return "hace un momento";
        if ($diferencia < 3600) return "hace " . floor($diferencia / 60) . " min";
        if ($diferencia < 86400) return "hace " . floor($diferencia / 3600) . " h";
        if ($diferencia < 2592000) {
            $dias = floor($diferencia / 86400);
            return $dias == 1 ? "hace 1 día" : "hace " . $dias . " días";
        }
        if ($diferencia < 31536000) {
            $meses = floor($diferencia / 2592000);
            return $meses == 1 ? "hace 1 mes" : "hace " . $meses . " meses";
        }
        $anios = floor($diferencia / 31536000);
        return $anios == 1 ? "hace 1 año" : "hace " . $anios . " años";
    }
}

// 8. CONSULTA DE DATOS (BLINDADA CON TRIPLE JOIN + FILTRO SOFT DELETE)
$sql_user = "SELECT a.*, 
                    COALESCE(dp.institucion, a.institucion) AS institucion_maestra,
                    dpu.banco AS banco_registrado,
                    dpu.numero_cuenta AS cuenta_registrada,
                    (SELECT MAX(score_nubira) FROM servicios WHERE alumno_id = a.id AND estado = 'aprobado' AND visible = 1) AS max_score
             FROM alumnos a 
             LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio 
             LEFT JOIN datos_pago_usuario dpu ON a.id = dpu.usuario_id
             WHERE a.id = ? AND a.visible = 1";

$stmt = $conn->prepare($sql_user);
if (!$stmt) { die("Error interno del sistema."); }
$stmt->bind_param("i", $perfil_id_ver);
$stmt->execute();
$perfil_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$perfil_data) {
    header("Location: /"); 
    exit;
}

// Mapeo de datos
$nombre_real    = (string)($perfil_data['nombre'] ?? 'Usuario');
$bio_actual     = (string)($perfil_data['bio'] ?? '');
$foto_field     = (string)($perfil_data['foto_perfil'] ?? ''); 
$leg_nota       = (float)($perfil_data['calificacion_promedio'] ?? 0);
$leg_qty        = (int)($perfil_data['cantidad_votos'] ?? 0);
$inst_display   = (string)($perfil_data['institucion_maestra'] ?? $perfil_data['institucion'] ?? 'Estudiante Universitario');
$nombre_display = formatearNombrePrivado($nombre_real);
$max_score      = (int)($perfil_data['max_score'] ?? 0); // Puntuación de Gamificación
$vistas_actuales = (int)($perfil_data['vistas_perfil'] ?? 0); // Contador de visitas

// === NUEVO: LÓGICA DE CONTADOR DE VISITAS (ANTI-SPAM F5) ===
if (!$es_propio && !$es_visitante) {
    $session_vistas_key = 'vista_perfil_' . $perfil_id_ver;
    if (!isset($_SESSION[$session_vistas_key])) {
        $upd_vistas = $conn->prepare("UPDATE alumnos SET vistas_perfil = vistas_perfil + 1 WHERE id = ?");
        if ($upd_vistas) {
            $upd_vistas->bind_param("i", $perfil_id_ver);
            $upd_vistas->execute();
            $upd_vistas->close();
            $_SESSION[$session_vistas_key] = true;
            $vistas_actuales++; // Reflejar el incremento en tiempo real en la vista
        }
    }
}
// ============================================================

// --- LÓGICA DE INCENTIVOS & ALERTAS ---
$falta_foto = empty($foto_field);
$falta_bio  = empty(trim($bio_actual));
$perfil_incompleto_local = ($es_propio && ($falta_foto || $falta_bio));

// LÓGICA DE TIERS PARA EL WIDGET
$tier_actual = "Básico";
$tier_color = "bg-gray-100 text-gray-500 border-gray-200";
$tier_icon = "user";
$progreso_porcentaje = min(100, max(0, $max_score));

if ($max_score >= 100) { $tier_actual = "Leyenda"; $tier_color = "bg-gradient-to-r from-slate-950 to-slate-900 text-amber-400 border-amber-500/30"; $tier_icon = "star-solid"; }
elseif ($max_score >= 80) { $tier_actual = "Pro"; $tier_color = "bg-gradient-to-tr from-yellow-400 to-amber-500 text-white border-yellow-300"; $tier_icon = "star-solid"; }
elseif ($max_score >= 60) { $tier_actual = "Top"; $tier_color = "bg-gradient-to-tr from-slate-200 to-gray-300 text-slate-800 border-white/60"; $tier_icon = "star-solid"; }

// LÓGICA BANCARIA
$banco_nombre = (string)($perfil_data['banco_registrado'] ?? '');
$banco_cuenta = (string)($perfil_data['cuenta_registrada'] ?? '');
$falta_banco = ($es_propio && (empty($banco_nombre) || empty($banco_cuenta)));

// [FIX] URL Foto con filemtime() en vez de time() para cache inteligente
$foto_url = '';
if (!empty($foto_field)) {
    $foto_path_fisica = $_SERVER['DOCUMENT_ROOT'] . "/app/perfil/fotos/" . $foto_field;
    $foto_cache_v = file_exists($foto_path_fisica) ? filemtime($foto_path_fisica) : time();
    $foto_url = "/app/perfil/fotos/" . $foto_field . "?v=" . $foto_cache_v;
} else {
    $foto_url = "https://ui-avatars.com/api/?name=" . urlencode($nombre_real) . "&background=54A6D8&color=fff";
}

// Fallback image para publicaciones
$default_pub_img = 'https://nubira.cl/upload/servicios/default_clases.webp';

// 9. SENSOR NUBIRA
if (file_exists(__DIR__ . '/logger.php') && !$es_visitante) {
    require_once __DIR__ . '/logger.php';
    if ($es_propio) {
        registrar_actividad($conn, $usuario_logueado_id, 'VER_PROPIO_PERFIL', 'Revisando su perfil público');
    } else {
        registrar_actividad($conn, $usuario_logueado_id, 'VER_PERFIL_OTRO', "Viendo perfil ID: $perfil_id_ver");
    }
}

// 10. REPUTACIÓN Y RESEÑAS
$sql_new = "SELECT AVG(CASE WHEN rol_evaluado = 'vendedor' THEN calificacion END) AS v_nota, SUM(CASE WHEN rol_evaluado = 'vendedor' THEN 1 ELSE 0 END) AS v_qty, AVG(CASE WHEN rol_evaluado = 'comprador' THEN calificacion END) AS c_nota, SUM(CASE WHEN rol_evaluado = 'comprador' THEN 1 ELSE 0 END) AS c_qty FROM valoraciones WHERE id_evaluado = ?";
$st_n = $conn->prepare($sql_new); 
$st_n->bind_param("i", $perfil_id_ver); 
$st_n->execute();
$rn = $st_n->get_result()->fetch_assoc(); 
$st_n->close();

$v_qty = (int)($rn['v_qty'] ?? 0); 
$c_qty = (int)($rn['c_qty'] ?? 0); 
$v_nota = (float)($rn['v_nota'] ?? 0); 
$c_nota = (float)($rn['c_nota'] ?? 0);
$total_v_qty = $leg_qty + $v_qty; 
$prom_t = ($total_v_qty > 0) ? (($leg_nota * $leg_qty) + ($v_nota * $v_qty)) / $total_v_qty : 0; 
$total_a = $c_qty; 
$prom_a  = $c_nota;


// =========================================================================
// =========================================================================
// [NUBIRA 2.0] TRUST SIGNAL: TIEMPO DE RESPUESTA
// =========================================================================
function formatearTiempoRespuesta($minutos) {
    if ($minutos === null) return "Por evaluar";
    if ($minutos <= 15) return "Menos de 15 min";
    if ($minutos <= 60) return "Menos de 1 hora";
    if ($minutos <= 120) return "Aprox. 1 a 2 horas";
    if ($minutos <= 240) return "Aprox. 3 a 4 horas";
    if ($minutos <= 720) return "Menos de 12 horas"; 
    if ($minutos <= 1440) return "En el transcurso del día";
    return "1 día o más";
}

$stmt_avg = $conn->prepare("SELECT AVG(primera_respuesta_minutos) as prom FROM conversaciones WHERE vendedor_id = ? AND primera_respuesta_minutos IS NOT NULL");
$stmt_avg->bind_param("i", $perfil_id_ver);
$stmt_avg->execute();
$res_avg = $stmt_avg->get_result()->fetch_assoc();
$texto_respuesta = formatearTiempoRespuesta($res_avg['prom'] ? (int)$res_avg['prom'] : null);
$stmt_avg->close();
// =========================================================================

// Obtener Reseñas Tutor
$resenas_tutor = []; 
$qr_rt = $conn->prepare("SELECT v.*, a.nombre AS autor_nombre, a.foto_perfil AS autor_foto, a.id AS autor_id FROM valoraciones v JOIN alumnos a ON v.id_evaluador = a.id WHERE v.id_evaluado = ? AND v.rol_evaluado = 'vendedor' AND v.calificacion > 0 ORDER BY v.fecha DESC LIMIT 20");
$qr_rt->bind_param("i", $perfil_id_ver); 
$qr_rt->execute(); 
$res = $qr_rt->get_result(); 
while ($row = $res->fetch_assoc()) $resenas_tutor[] = $row; 
$qr_rt->close();

// Obtener Reseñas Alumno
$resenas_alumno = []; 
$qr_ra = $conn->prepare("SELECT v.*, a.nombre AS autor_nombre, a.foto_perfil AS autor_foto, a.id AS autor_id FROM valoraciones v JOIN alumnos a ON v.id_evaluador = a.id WHERE v.id_evaluado = ? AND v.rol_evaluado = 'comprador' AND v.calificacion > 0 ORDER BY v.fecha DESC LIMIT 20");
$qr_ra->bind_param("i", $perfil_id_ver); 
$qr_ra->execute(); 
$res = $qr_ra->get_result(); 
while ($row = $res->fetch_assoc()) $resenas_alumno[] = $row; 
$qr_ra->close();

// === OBTENER SERVICIOS Y APUNTES CON LIMIT Y FILTRO SOFT DELETE ===
$publicaciones = []; 

// 1. Cargar Servicios (Solo aprobados y NO eliminados lógicamente)
$qs = $conn->prepare("SELECT *, 'servicio' AS tipo_pub FROM servicios WHERE alumno_id = ? AND estado = 'aprobado' AND COALESCE(visible, 1) = 1 ORDER BY fecha_publicacion DESC LIMIT 30"); 
$qs->bind_param("i", $perfil_id_ver); 
$qs->execute(); 
$res_s = $qs->get_result(); 
while ($p = $res_s->fetch_assoc()) {
    $p['fecha_orden'] = strtotime($p['fecha_publicacion'] ?? 'now');
    $publicaciones[] = $p; 
}
$qs->close();

// 2. Cargar Apuntes (Solo aprobados, no bloqueados y NO eliminados lógicamente)
$qa = $conn->prepare("SELECT *, 'apunte' AS tipo_pub FROM apuntes WHERE id_alumno = ? AND estado = 'aprobado' AND bloqueado = 0 AND COALESCE(visible, 1) = 1 ORDER BY fecha_subida DESC LIMIT 30"); 
if ($qa) {
    $qa->bind_param("i", $perfil_id_ver); 
    $qa->execute(); 
    $res_a = $qa->get_result(); 
    while ($a = $res_a->fetch_assoc()) {
        $a['fecha_orden'] = strtotime($a['fecha_subida'] ?? 'now');
        $publicaciones[] = $a; 
    }
    $qa->close();
}

// ORDENAR CRONOLÓGICAMENTE (Más recientes primero)
usort($publicaciones, function($a, $b) {
    return $b['fecha_orden'] <=> $a['fecha_orden'];
});

// =========================================================================
// [NUBIRA 2.0] BEHAVIOR-DRIVEN UI: CEREBRO DE ROLES DINÁMICOS (AHORA SÍ FUNCIONA)
// =========================================================================
// 1. ¿Es un Creador/Tutor activo? (Vende apuntes/clases o tiene reseñas como vendedor)
$es_creador = (!empty($publicaciones) || $total_v_qty > 0);

// 2. ¿Tiene reputación pública como Alumno? (Para mostrar en la Vitrina Pública)
$tiene_resenas_alumno = ($total_a > 0);

// 3. ¿Ha comprado algo alguna vez? (Para desbloquear secciones en el Panel Privado)
$ha_comprado_algo = false;
if ($es_propio || $es_admin) {
    $stmt_compras = $conn->prepare("SELECT 1 FROM compras WHERE usuario_id = ? LIMIT 1");
    if ($stmt_compras) {
        $stmt_compras->bind_param("i", $perfil_id_ver);
        $stmt_compras->execute();
        $stmt_compras->store_result();
        $ha_comprado_algo = ($stmt_compras->num_rows > 0);
        $stmt_compras->close();
    }
}
// =========================================================================

// RUTA PANEL GESTION
$archivo_gestion = __DIR__ . '/componentes/panel_gestion.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Perfil de <?= htmlspecialchars($nombre_display) ?> | Nubira</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .tab-active { border-bottom: 2px solid #54A6D8; color: #54A6D8; }
        .tab-inactive { border-bottom: 2px solid transparent; color: #9ca3af; }
        .btn-nav-top { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 12px; border: 1px solid #e5e7eb; color: #9ca3af; background: white; transition: all 0.15s; }
        .btn-nav-top:hover { color: #54A6D8; border-color: #54A6D8; }
        .card-horizontal { flex: 0 0 75%; }
        @media (min-width: 768px) { .card-horizontal { flex: 0 0 40%; } }
        @keyframes slide-in { from { transform: translateY(8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .animate-slide-in { animation: slide-in 0.35s ease-out forwards; }
        
        /* FIX: Reset text-shadow a nivel css para asegurar que Tailwind no pierda la batalla */
        .force-no-shadow * { text-shadow: none !important; }
    </style>
</head>
<body class="text-[#222222] antialiased overflow-x-hidden">


<?php 
$ocultar_buscador = true; // Variable bandera para ocultar el buscador
require_once __DIR__ . '/componentes/header.php'; 
require_once __DIR__ . '/componentes/sidebar.php'; 
?>

<main class="pt-20 pb-28 md:pb-10 md:ml-64 px-4 max-w-[1600px] mx-auto md:px-8">
    <div class="grid grid-cols-1 xl:grid-cols-[1fr_350px] gap-6 md:gap-8 items-start">
        <div class="space-y-5 md:space-y-6 min-w-0">

            <?php if ($es_propio && ($falta_banco || $perfil_incompleto_local)): ?>
            <section class="mb-5">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Avisos</h2>
                    <span class="bg-rose-50 text-rose-600 text-[10px] font-bold px-2 py-0.5 rounded-lg">
                        <?= (int)$falta_banco + (int)$falta_foto + (int)$falta_bio ?> PENDIENTES
                    </span>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-2 gap-3">
                    <?php if ($falta_banco): ?>
                    <div class="bg-white border border-rose-200 rounded-2xl p-3 hover:bg-rose-50/30 active:bg-rose-50 transition-all duration-150 flex items-center justify-between gap-3 group">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-8 h-8 rounded-full bg-rose-50 flex items-center justify-center shrink-0 text-rose-500">
                                <?= icon('building', 'w-3 h-3') ?>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-xs font-semibold text-gray-900 truncate">Configura tus pagos</h3>
                                <p class="text-[10px] text-gray-500 truncate hidden sm:block">Añade tu banco para recibir dinero.</p>
                            </div>
                        </div>
                        <a href="/datos_bancarios" class="shrink-0 px-4 py-1.5 bg-rose-50 text-rose-600 text-[10px] font-semibold uppercase rounded-2xl hover:bg-rose-500 hover:text-white active:bg-rose-600 transition-colors duration-150">
                            Ir <?= icon('arrow-right', 'w-3 h-3 ml-1') ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if ($perfil_incompleto_local): ?>
                    <div class="bg-white border border-orange-200 rounded-2xl p-3 hover:bg-orange-50/30 active:bg-orange-50 transition-all duration-150 flex items-center justify-between gap-3 group">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-8 h-8 rounded-full bg-orange-50 flex items-center justify-center shrink-0 text-orange-500">
                                <?= icon('sparkles', 'w-3 h-3') ?>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-xs font-semibold text-gray-900 truncate">Impulsa tu perfil</h3>
                                <p class="text-[10px] text-gray-500 truncate hidden sm:block">Completa tu info personal.</p>
                            </div>
                        </div>
                        <div class="flex gap-1.5 shrink-0">
                            <?php if($falta_foto): ?>
                                <button onclick="document.getElementById('foto-input').click()" class="px-3 py-1.5 border border-gray-200 text-gray-600 text-[10px] font-semibold uppercase rounded-2xl hover:bg-gray-50 active:bg-gray-100 transition-colors duration-150">Foto</button>
                            <?php endif; ?>
                            <?php if($falta_bio): ?>
                                <button onclick="toggleEditBio(); document.getElementById('bio-input')?.focus();" class="px-3 py-1.5 bg-orange-50 text-orange-600 text-[10px] font-semibold uppercase rounded-2xl hover:bg-orange-500 hover:text-white active:bg-orange-600 transition-colors duration-150">Bio</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>
            
            <section class="bg-white rounded-[2rem] border border-gray-100 p-6 md:p-10 relative w-full">
                <div class="flex flex-col gap-6 md:gap-8">
                    
                    <div class="flex flex-col md:flex-row gap-6 md:gap-8 items-center md:items-start w-full">
                        
                        <div class="shrink-0 relative group w-36 h-36 md:w-36 md:h-36 mx-auto md:mx-0">
                            <img id="img-perfil-visual" src="<?= $foto_url ?>" decoding="async" class="w-full h-full rounded-full object-cover border border-gray-200 transition-opacity duration-300 bg-white">
                            <?php if ($es_propio): ?>
                                <div class="absolute inset-0 bg-black/40 rounded-full flex items-center justify-center gap-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 backdrop-blur-[2px]">
                                    <button onclick="document.getElementById('foto-input').click()" class="text-white hover:text-[#54A6D8] transition-colors duration-200 p-2" title="Cambiar foto"><?= icon('camera', 'w-5 h-5') ?></button>
                                    <?php if (!empty($foto_field)): ?>
                                        <button onclick="eliminarFotoPerfil()" id="btn-borrar-foto" class="text-white hover:text-red-400 transition-colors duration-200 p-2" title="Eliminar foto"><?= icon('trash', 'w-5 h-5') ?></button>
                                    <?php endif; ?>
                                </div>
                                <input type="file" id="foto-input" class="hidden" accept="image/jpeg,image/png,image/webp" onchange="subirFotoPerfil()">
                                <div id="foto-spinner" class="absolute inset-0 flex items-center justify-center bg-white/80 rounded-full hidden z-20"><div class="w-5 h-5 border-2 border-[#54A6D8] border-t-transparent rounded-full animate-spin"></div></div>
                            <?php endif; ?>
                        </div>

                        <div class="flex-1 min-w-0 w-full pt-1">
                            
                            <div class="flex flex-col xl:flex-row xl:justify-between xl:items-start gap-6 w-full">
                                
                                <div class="flex flex-col items-center md:items-start w-full">
                                    <div class="flex items-center justify-center md:justify-start gap-2 w-full">
                                        <h1 class="text-2xl md:text-3xl font-bold tracking-tight text-gray-900 break-words"><?= htmlspecialchars($nombre_display) ?></h1>
                                        <span title="Alumno Verificado"><?= icon('check-circle', 'w-5 h-5 text-[#54A6D8] shrink-0') ?></span>
                                    </div>
                                    <p class="text-[11px] md:text-xs text-gray-500 flex items-center justify-center md:justify-start gap-1.5 mt-1.5 md:mt-2 leading-snug w-full">
                                        <span class="flex-shrink-0 text-gray-400"><?= icon('building', 'w-4 h-4') ?></span>
                                        <span class="break-words font-medium uppercase tracking-wider"><?= htmlspecialchars($inst_display) ?></span>
                                    </p>
                                </div>

                                <div class="flex flex-row justify-center md:justify-end items-center shrink-0 -mt-4 md:mt-0 divide-x divide-gray-200 md:divide-none">
                                    
                                    <div class="px-5 md:pl-0 md:pr-2 min-w-0 text-center md:text-left flex flex-col items-center md:items-start">
                                        <p id="val-total-reviews" data-leg-qty="<?= $leg_qty ?>" data-leg-nota="<?= $leg_nota ?>" class="text-xl md:text-lg font-bold tracking-tight text-gray-900 flex items-center justify-center md:justify-start">
    <?= (int)($total_v_qty + $total_a) ?>
</p>
                                        <p class="text-[10px] uppercase font-semibold text-gray-400 whitespace-nowrap mt-0.5 tracking-wider">Reseñas</p>
                                    </div>

                                    <div class="px-5 md:px-2 md:border-l md:border-gray-100 min-w-0 text-center md:text-left flex flex-col items-center md:items-start">
                                        <p class="text-xl md:text-lg font-bold tracking-tight text-gray-900 flex items-center gap-1">
                                           <span id="val-avg-rating"><?= number_format($prom_t, 1) ?></span> <?= icon('star-solid', 'w-4 h-4 text-gray-900 pb-[1px]') ?>
                                        </p>
                                        <p class="text-[10px] uppercase font-semibold text-gray-400 whitespace-nowrap mt-0.5 tracking-wider">Rating</p>
                                    </div>

                                    <?php if ($es_admin && !$es_propio): ?>
                                        <div class="px-5 md:pl-2 md:border-l md:border-gray-100 min-w-0 text-center md:text-left flex flex-col items-center md:items-start">
                                            <button onclick="abrirModalMensajeAdmin()" class="px-3 py-1.5 bg-rose-500 text-white text-[10px] font-bold uppercase tracking-wider rounded-xl hover:bg-rose-600 active:scale-95 transition-all flex items-center gap-1.5">
                                                <?= icon('shield-check', 'w-3 h-3') ?> Mensaje
                                            </button>
                                            <p class="text-[10px] uppercase font-semibold text-gray-400 whitespace-nowrap mt-1 tracking-wider">Modo Admin</p>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($es_propio): ?>
                                        <div class="px-5 md:pl-2 md:border-l md:border-gray-100 group cursor-help min-w-0 text-center md:text-left flex flex-col items-center md:items-start" title="Personas que han visto tu perfil">
                                            <p class="text-xl md:text-lg font-bold tracking-tight text-gray-900 flex items-center gap-1.5 transition-all duration-300" id="contenedor-visitas-live">
                                                <span id="num-visitas-live"><?= $vistas_actuales ?></span> <?= icon('eye', 'w-4 h-4 text-gray-400') ?>
                                            </p>
                                            <p class="text-[10px] uppercase font-semibold text-gray-400 whitespace-nowrap mt-0.5 tracking-wider">Visitas</p>
                                        </div>
                                    <?php elseif ($vistas_actuales >= 100): ?>
                                        <div class="px-5 md:pl-2 md:border-l md:border-gray-100 cursor-default min-w-0 text-center md:text-left flex flex-col items-center md:items-start" title="Este perfil tiene alta demanda">
                                            <p class="text-xl md:text-lg font-bold tracking-tight text-gray-700 flex items-center gap-1">
                                                Top <?= icon('fire', 'w-4 h-4 text-gray-500 pb-[1px]') ?>
                                            </p>
                                            <p class="text-[10px] uppercase font-semibold text-gray-400 whitespace-nowrap mt-0.5 tracking-wider">Demanda</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="mt-4 md:mt-5 flex justify-center md:justify-start w-full transform translate-y-3 md:translate-y-0">
                                <p class="text-[12px] md:text-[11px] text-gray-600 font-medium inline-flex items-center gap-2 bg-white md:bg-gray-50 border border-gray-200 md:border-gray-100 rounded-xl px-4 py-2 w-fit" title="Tiempo promedio de respuesta">
                                    <?= icon('clock', 'w-4 h-4 text-gray-400') ?>
                                    <span>Tiempo de respuesta: <strong class="text-gray-900 font-medium"><?= htmlspecialchars($texto_respuesta) ?></strong></span>
                                </p>
                            </div>

                        </div>
                    </div> 

                    <div class="hidden md:block w-full h-px bg-gray-100 my-1"></div>
                    <div class="block md:hidden w-16 h-1 bg-gray-100 rounded-full mx-auto my-2"></div>

                    <div class="w-full relative px-2 md:px-0"> 
                        
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-[11px] font-medium uppercase tracking-widest text-gray-400">Biografía</h2>
                            
                            <?php if ($es_propio): ?>
                                <button onclick="toggleEditBio()" class="absolute -top-3 right-0 md:relative md:top-auto md:right-auto bg-[#54A6D8] text-white md:bg-gray-50 md:text-gray-400 md:hover:text-[#54A6D8] transition-all duration-150 p-3 md:p-2 rounded-full border border-transparent md:border-gray-200 active:scale-95" id="btn-edit-bio">
                                    <?= icon('pencil', 'w-4 h-4') ?>
                                </button>
                            <?php endif; ?>
                        </div>

                        <div id="bio-view" class="text-gray-800 md:text-gray-700 text-base md:text-sm font-normal leading-relaxed tracking-wide break-words text-left mt-2 md:mt-0">
                            <?= !empty($bio_actual) ? nl2br(htmlspecialchars($bio_actual)) : ($es_propio ? "Añade una breve biografía para que estudiantes y tutores confíen en ti." : "Aún preparando mi biografía...") ?>
                        </div>

                        <?php if ($es_propio): ?>
                        <div id="bio-edit-container" class="hidden mt-4 space-y-4">
                            <textarea id="bio-input" maxlength="250" class="w-full p-4 border border-gray-200 rounded-2xl focus:border-[#54A6D8] focus:ring-4 focus:ring-[#54A6D8]/10 outline-none text-gray-800 font-normal leading-relaxed tracking-wide bg-gray-50 transition-all duration-200 text-base md:text-sm resize-none" rows="3"><?= htmlspecialchars($bio_actual) ?></textarea>
                            <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                                <span class="text-[10px] font-medium text-gray-400 uppercase tracking-widest text-center sm:text-left">Máx. 250 caracteres</span>
                                <div class="flex justify-end gap-3 w-full sm:w-auto">
                                    <button onclick="toggleEditBio()" class="flex-1 sm:flex-none px-4 py-3 sm:py-2 text-[11px] sm:text-[10px] font-medium uppercase text-gray-500 hover:text-red-500 bg-gray-100 hover:bg-red-50 sm:bg-transparent rounded-xl transition-colors duration-150">Cancelar</button>
                                    <button onclick="saveBio()" id="btn-save-bio" class="flex-1 sm:flex-none px-6 py-3 sm:py-2 bg-[#54A6D8] text-white text-[11px] sm:text-[10px] font-medium uppercase rounded-xl hover:bg-[#3d91c7] active:bg-[#347fae] transition-all duration-150">Guardar</button>
                                </div>
                            </div>
                            <p id="bio-error" class="text-red-500 text-[11px] font-medium uppercase hidden text-center sm:text-left"></p>
                        </div>
                        <?php endif; ?>
                    </div> 
                </div>
            </section>
            
            <?php if ($es_propio && $max_score > 0): 
                $tiene_apunte = false;
                $tiene_desc_larga = false;
                $tiene_resena = ($v_qty > 0); 

                foreach ($publicaciones as $pub) {
                    if (($pub['tipo_pub'] ?? '') === 'apunte') $tiene_apunte = true;
                    if (($pub['tipo_pub'] ?? '') === 'servicio' && !empty($pub['descripcion']) && strlen(trim($pub['descripcion'])) > 40) $tiene_desc_larga = true;
                }
            ?>
            <section class="bg-white rounded-3xl border border-gray-200 p-6 md:p-8">
                <div onclick="toggleNivelTutor()" class="cursor-pointer group select-none">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h2 class="text-sm md:text-base font-bold text-gray-900 flex items-center gap-2 group-hover:text-[#54A6D8] transition-colors duration-200">
                                Tu Nivel de Tutor
                                <span id="chevron-nivel" class="text-gray-400 transition-transform duration-300 inline-flex"><?= icon('chevron-down', 'w-3 h-3') ?></span>
                            </h2>
                            <p class="text-[10px] md:text-xs text-gray-500 mt-0.5">Sube de nivel completando misiones para destacar en búsquedas.</p>
                        </div>
                        <div class="shrink-0 pl-3">
                            <span class="<?= $tier_color ?> text-[10px] md:text-xs font-extrabold uppercase tracking-wider px-3 md:px-4 py-1.5 md:py-2 rounded-full border shadow-sm flex items-center gap-1.5">
                                <?= icon($tier_icon, 'w-3 h-3') ?> <?= $tier_actual ?>
                            </span>
                        </div>
                    </div>

                    <div class="w-full bg-gray-100 rounded-full h-3 mb-2 overflow-hidden border border-gray-200/50">
                        <div class="bg-gradient-to-r from-sky-400 to-[#54A6D8] h-full rounded-full transition-all duration-1000" style="width: <?= $progreso_porcentaje ?>%"></div>
                    </div>
                    <div class="flex justify-between text-[9px] md:text-[10px] font-bold text-gray-400 uppercase tracking-wider">
                        <span>0 Pts</span>
                        <span class="text-[#54A6D8]"><?= $max_score ?> Pts</span>
                        <span>100 Pts</span>
                    </div>
                </div>

                <div id="misiones-nivel" class="hidden mt-5 pt-5 border-t border-gray-100 animate-slide-in">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <div class="flex items-center gap-2 text-[11px] md:text-xs <?= !$falta_foto ? 'text-emerald-600 font-semibold' : 'text-gray-500' ?> bg-gray-50 p-2.5 rounded-xl border border-gray-200/60">
                            <?= !$falta_foto ? icon('check-circle', 'w-3 h-3') : icon('circle', 'w-3 h-3 text-gray-300') ?> Foto de perfil (+20)
                        </div>
                        <div class="flex items-center gap-2 text-[11px] md:text-xs <?= !$falta_bio ? 'text-emerald-600 font-semibold' : 'text-gray-500' ?> bg-gray-50 p-2.5 rounded-xl border border-gray-200/60">
                            <?= !$falta_bio ? icon('check-circle', 'w-3 h-3') : icon('circle', 'w-3 h-3 text-gray-300') ?> Biografía (+20)
                        </div>
                        <div class="flex items-center gap-2 text-[11px] md:text-xs <?= $tiene_desc_larga ? 'text-emerald-600 font-semibold' : 'text-gray-500' ?> bg-gray-50 p-2.5 rounded-xl border border-gray-200/60" title="Añade al menos 300 letras a la descripción">
                            <?= $tiene_desc_larga ? icon('check-circle', 'w-3 h-3') : icon('circle', 'w-3 h-3 text-gray-300') ?> Descripción Larga (+20)
                        </div>
                        <div class="flex items-center gap-2 text-[11px] md:text-xs <?= $tiene_apunte ? 'text-emerald-600 font-semibold' : 'text-gray-500' ?> bg-gray-50 p-2.5 rounded-xl border border-gray-200/60">
                            <?= $tiene_apunte ? icon('check-circle', 'w-3 h-3') : icon('circle', 'w-3 h-3 text-gray-300') ?> Subir Apunte Público (+20)
                        </div>
                        <div class="flex items-center gap-2 text-[11px] md:text-xs <?= $tiene_resena ? 'text-emerald-600 font-semibold' : 'text-gray-500' ?> bg-gray-50 p-2.5 rounded-xl border border-gray-200/60">
                            <?= $tiene_resena ? icon('check-circle', 'w-3 h-3') : icon('circle', 'w-3 h-3 text-gray-300') ?> Obtener 1 Reseña (+20)
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php if (($es_propio || $es_admin) && file_exists($archivo_gestion)): ?>
            <section class="xl:hidden bg-white rounded-3xl border border-gray-200 p-8">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Panel de Control</h2>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 panel-links-wrapper"><?php include $archivo_gestion; ?></div>
            </section>
            <?php endif; ?>

            <section class="bg-white rounded-3xl border border-gray-200 p-5 md:p-8">
                <div class="flex items-center justify-between mb-4 border-b border-gray-100 min-h-[44px]">
                    <div class="flex items-center gap-8 self-end">
                        <?php if ($es_creador && $tiene_resenas_alumno): ?>
                            <button onclick="switchReviews('tutor')" id="btn-tutor" class="pb-4 text-xs font-bold uppercase tracking-widest transition-all duration-200 tab-active">Reseñas Tutor (<?= (int)$total_v_qty ?>)</button>
                            <button onclick="switchReviews('alumno')" id="btn-alumno" class="pb-4 text-xs font-bold uppercase tracking-widest transition-all duration-200 tab-inactive">Reseñas Alumno (<?= (int)$total_a ?>)</button>
                        <?php elseif ($es_creador): ?>
                            <button id="btn-tutor" class="pb-4 text-xs font-bold uppercase tracking-widest transition-all duration-200 tab-active cursor-default border-b-2 border-[#54A6D8] text-[#54A6D8]">
                                Reseñas Tutor (<?= (int)$total_v_qty ?>)
                            </button>
                        <?php else: ?>
                            <button id="btn-alumno" class="pb-4 text-xs font-bold uppercase tracking-widest transition-all duration-200 tab-active cursor-default border-b-2 border-[#54A6D8] text-[#54A6D8]">
                                Reseñas Alumno (<?= (int)$total_a ?>)
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="hidden md:flex items-center gap-2 pb-4">
                        <button onclick="navScroll('reviews-container', -1)" class="btn-nav-top"><?= icon('chevron-left', 'w-3 h-3') ?></button>
                        <button onclick="navScroll('reviews-container', 1)" class="btn-nav-top"><?= icon('chevron-right', 'w-3 h-3') ?></button>
                    </div>
                </div>

            <?php $mostrar_alumno_defecto = !$es_creador; ?>
                <div id="reviews-container" class="flex overflow-x-auto gap-4 no-scrollbar snap-x snap-mandatory scroll-smooth pb-2">
                    
                    <div id="reviews-tutor" class="<?= $mostrar_alumno_defecto ? 'hidden' : 'flex' ?> gap-4 min-w-full">
                        <?php if (empty($resenas_tutor)): ?>
                            <div class="min-w-full py-6 text-center text-gray-400 italic border border-dashed border-gray-200 rounded-3xl">Sin reseñas como tutor aún.</div>
                        <?php else: foreach($resenas_tutor as $r): ?>
                          <div class="card-horizontal shrink-0 snap-start bg-white p-6 rounded-3xl border border-gray-200 flex flex-col gap-3 relative group/resena" data-rating="<?= (int)($r['calificacion'] ?? 0) ?>">
                                <?php if ($es_admin): ?>
                                    <button onclick="borrarValoracionPerfil(this, <?= (int)$r['id'] ?>)" 
                                            class="absolute top-3 right-3 text-gray-300 hover:text-red-500 transition-all duration-150 p-2 rounded-full hover:bg-red-50 active:scale-95 z-10 opacity-0 group-hover/resena:opacity-100 focus:opacity-100" 
                                            title="Eliminar reseña (Modo Admin)">
                                        <?= icon('trash', 'w-4 h-4') ?>
                                    </button>
                                <?php endif; ?>
                                <?php 
    $autor_id_raw = (int)($r['autor_id'] ?? 0);
    $link_autor = function_exists('nubira_encriptar_id') && $autor_id_raw > 0 ? "/perfil/" . nubira_encriptar_id($autor_id_raw) : "/perfil/" . $autor_id_raw;
?>
<a href="<?= htmlspecialchars($link_autor) ?>" class="flex items-center gap-3 group/autor transition-all duration-200">
    <?php if (!empty($r['autor_foto'])): ?>
        <img src="/app/perfil/fotos/<?= htmlspecialchars($r['autor_foto']) ?>" class="w-10 h-10 rounded-full object-cover border border-gray-100 bg-white shrink-0 group-hover/autor:ring-2 group-hover/autor:ring-[#54A6D8]/30 transition-all" alt="Avatar" loading="lazy" onerror="this.outerHTML='<div class=\'w-10 h-10 rounded-full bg-sky-50 flex items-center justify-center font-semibold text-[#54A6D8] text-xs uppercase shrink-0\'><?= htmlspecialchars(substr((string)($r['autor_nombre'] ?? 'U'), 0, 1)) ?></div>'">
    <?php else: ?>
        <div class="w-10 h-10 rounded-full bg-sky-50 flex items-center justify-center font-semibold text-[#54A6D8] text-xs uppercase shrink-0 group-hover/autor:bg-sky-100 transition-colors"><?= htmlspecialchars(substr((string)($r['autor_nombre'] ?? 'U'), 0, 1)) ?></div>
    <?php endif; ?>
    
    <div class="min-w-0">
        <p class="font-semibold text-sm truncate group-hover/autor:text-[#54A6D8] transition-colors"><?= htmlspecialchars(formatearNombrePrivado($r['autor_nombre'] ?? 'Usuario')) ?></p>
        <p class="text-[10px] text-gray-400 font-semibold uppercase tracking-tight"><?= tiempo_transcurrido($r['fecha'] ?? '') ?></p>
    </div>
</a>
                               <div class="flex text-gray-700 gap-0.5">
                                    <?php $cal = (int)($r['calificacion'] ?? 0); for($i=0; $i<5; $i++) echo ($i < $cal) ? icon('star-solid', 'w-3 h-3') : icon('star-solid', 'w-3 h-3 text-gray-200'); ?>
                                </div>
                                <div class="mt-1">
                                    <p id="rev-t-<?= (int)$r['id'] ?>" class="text-gray-700 text-sm font-normal leading-relaxed line-clamp-3 transition-all duration-300">
                                        <?= nl2br(htmlspecialchars(trim((string)($r['comentario'] ?? '')))) ?>
                                    </p>
                                    <?php if (mb_strlen(trim((string)($r['comentario'] ?? ''))) > 130): ?>
                                        <button onclick="toggleReviewText('rev-t-<?= (int)$r['id'] ?>', this)" class="text-[#54A6D8] text-[11px] font-bold mt-1.5 hover:underline outline-none tracking-wide uppercase">Mostrar más</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>

                    <div id="reviews-alumno" class="<?= $mostrar_alumno_defecto ? 'flex' : 'hidden' ?> gap-4 min-w-full">
                        <?php if (empty($resenas_alumno)): ?>
                            <div class="min-w-full py-6 text-center text-gray-400 italic border border-dashed border-gray-200 rounded-3xl">Sin reseñas como alumno aún.</div>
                        <?php else: foreach($resenas_alumno as $r): ?>
                            <div class="card-horizontal shrink-0 snap-start bg-white p-6 rounded-3xl border border-gray-200 flex flex-col gap-3 relative group/resena">
                                <?php if ($es_admin): ?>
                                    <button onclick="borrarValoracionPerfil(this, <?= (int)$r['id'] ?>)" 
                                            class="absolute top-3 right-3 text-gray-300 hover:text-red-500 transition-all duration-150 p-2 rounded-full hover:bg-red-50 active:scale-95 z-10 opacity-0 group-hover/resena:opacity-100 focus:opacity-100" 
                                            title="Eliminar reseña (Modo Admin)">
                                        <?= icon('trash', 'w-4 h-4') ?>
                                    </button>
                                <?php endif; ?>
<?php 
    $autor_id_raw = (int)($r['autor_id'] ?? 0);
    $link_autor = function_exists('nubira_encriptar_id') && $autor_id_raw > 0 ? "/perfil/" . nubira_encriptar_id($autor_id_raw) : "/perfil/" . $autor_id_raw;
?>
<a href="<?= htmlspecialchars($link_autor) ?>" class="flex items-center gap-3 group/autor transition-all duration-200">
    <?php if (!empty($r['autor_foto'])): ?>
        <img src="/app/perfil/fotos/<?= htmlspecialchars($r['autor_foto']) ?>" class="w-10 h-10 rounded-full object-cover border border-gray-100 bg-white shrink-0 group-hover/autor:ring-2 group-hover/autor:ring-orange-400/30 transition-all" alt="Avatar" loading="lazy" onerror="this.outerHTML='<div class=\'w-10 h-10 rounded-full bg-orange-50 flex items-center justify-center font-semibold text-orange-400 text-xs uppercase shrink-0\'><?= htmlspecialchars(substr((string)($r['autor_nombre'] ?? 'U'), 0, 1)) ?></div>'">
    <?php else: ?>
        <div class="w-10 h-10 rounded-full bg-orange-50 flex items-center justify-center font-semibold text-orange-400 text-xs uppercase shrink-0 group-hover/autor:bg-orange-100 transition-colors"><?= htmlspecialchars(substr((string)($r['autor_nombre'] ?? 'U'), 0, 1)) ?></div>
    <?php endif; ?>
    
    <div class="min-w-0">
        <p class="font-semibold text-sm truncate group-hover/autor:text-orange-500 transition-colors"><?= htmlspecialchars(formatearNombrePrivado($r['autor_nombre'] ?? 'Usuario')) ?></p>
        <p class="text-[10px] text-gray-400 font-semibold uppercase tracking-tight"><?= tiempo_transcurrido($r['fecha'] ?? '') ?></p>
    </div>
</a>
                                <div class="flex text-gray-700 gap-0.5">
                                    <?php $cal = (int)($r['calificacion'] ?? 0); for($i=0; $i<5; $i++) echo ($i < $cal) ? icon('star-solid', 'w-3 h-3') : icon('star-solid', 'w-3 h-3 text-gray-200'); ?>
                                </div>
                             <div class="mt-1">
    <p id="rev-a-<?= (int)$r['id'] ?>" class="text-gray-700 text-sm font-normal leading-relaxed line-clamp-3 transition-all duration-300">
        <?= nl2br(htmlspecialchars(trim((string)($r['comentario'] ?? '')))) ?>
    </p>
    <?php if (mb_strlen(trim((string)($r['comentario'] ?? ''))) > 130): ?>
        <button onclick="toggleReviewText('rev-a-<?= (int)$r['id'] ?>', this)" class="text-orange-500 text-[11px] font-bold mt-1.5 hover:underline outline-none tracking-wide uppercase">Mostrar más</button>
    <?php endif; ?>
</div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>

                </div>
            </section>

           <?php if (!empty($publicaciones)): ?>
            <section class="pb-2 force-no-shadow">
                <div class="flex items-center justify-between mb-6 min-h-[40px]">
                    <h2 class="text-xl font-bold tracking-tight text-gray-900">Vitrina de <?= htmlspecialchars($nombre_display) ?></h2>
                    <div class="hidden md:flex items-center gap-2">
                        <button onclick="navScroll('pub-container', -1)" class="btn-nav-top"><?= icon('chevron-left', 'w-3 h-3') ?></button>
                        <button onclick="navScroll('pub-container', 1)" class="btn-nav-top"><?= icon('chevron-right', 'w-3 h-3') ?></button>
                    </div>
                </div>
                
                <div id="pub-container" class="flex overflow-x-auto gap-4 md:gap-5 no-scrollbar snap-x snap-mandatory scroll-smooth pb-4 pt-1 px-1">
                    <?php if (empty($publicaciones)): ?>
                        <div class="min-w-full py-12 bg-white rounded-3xl border border-dashed border-gray-200 text-center text-gray-400 italic">No hay publicaciones activas.</div>
                    <?php else: foreach($publicaciones as $row): 
                        
                        $sid_raw = (int)($row['id'] ?? 0);
                        $link_hash = function_exists('nubira_encriptar_id') ? nubira_encriptar_id($sid_raw) : $sid_raw;
                        
                        $tipo_pub = $row['tipo_pub'] ?? 'servicio';
                        $es_apunte = ($tipo_pub === 'apunte');

                        // Data Prep
                        $titulo = (string)($row['titulo'] ?? ''); 
                        $precio_val = $row['precio'] ?? 0; 
                        $img = (string)($row['imagen'] ?? ($row['portada'] ?? '')); 

                        $base_url_img = $es_apunte ? "/upload/preview/" : "/upload/servicios/";
                        $portada_url = !empty($img) ? $base_url_img . $img : $default_pub_img; 

                        $enlace_detalle = $es_apunte ? "/apunte/" . $link_hash : "/detalle-servicio/" . $link_hash;
                        
                        // Formato de Precio
                        if (is_numeric($precio_val) && $precio_val > 0) {
                            $precio = "$" . number_format($precio_val, 0, ',', '.') . ""; 
                            $precio_class = "text-gray-900 font-bold"; 
                        } else {
                            $precio = "Gratis"; 
                            $precio_class = "text-green-600 font-bold";
                        }

                        // Lógica de Score y Tiers Youtube Edition
                        $score = (int)($row['score_nubira'] ?? 0); 
                        $total_v = isset($row['total_votos']) ? (int)$row['total_votos'] : 0;
                        $rating_val = isset($row['rating_promedio']) ? (float)$row['rating_promedio'] : 0;
                        
                        $nivel_tutor = '';
                        $es_basico = ($score < 60);

                        if ($score >= 100 && $total_v >= 10 && $rating_val >= 4.7) $nivel_tutor = 'leyenda';
                        elseif ($score >= 80 && $total_v >= 3 && $rating_val >= 4.0) $nivel_tutor = 'elite';
                        elseif ($score >= 80) $nivel_tutor = 'pro';
                        elseif ($score >= 60) $nivel_tutor = 'top';

                        // Lógica Nuevo
                        $fecha_pub = !empty($row['fecha_publicacion']) ? new DateTime($row['fecha_publicacion']) : new DateTime();
                        $es_nuevo  = ((new DateTime())->diff($fecha_pub)->days <= 14); 

                        // Tutor
                        $foto_tutor = !empty($foto_field) ? '/app/perfil/fotos/' . $foto_field : "https://ui-avatars.com/api/?name=".urlencode($nombre_display)."&background=f1f5f9&color=64748b";

                        // Modalidad
                        $mod = ucfirst($row['modalidad'] ?? '');
                        if (stripos($mod, 'online') !== false) $icon_mod = icon('wifi', 'w-3 h-3');
                        elseif (stripos($mod, 'presencial') !== false) $icon_mod = icon('users', 'w-3 h-3');
                        else $icon_mod = icon('laptop', 'w-3 h-3');

                        // HTML Stars
                        if ($total_v > 0) {
                            $html_stars = '<div class="flex items-center gap-1 px-1.5 py-0.5">'.icon('star-solid', 'w-3 h-3 text-gray-700').'<span class="text-[10px] font-extrabold text-gray-800 leading-none">'.number_format($rating_val, 1).'</span></div>';
                        } else {
                            $html_stars = '<div class="flex items-center gap-1">'.icon('star-solid', 'w-3 h-3 text-gray-300').'<span class="text-[10px] font-medium text-gray-400">Nuevo</span></div>';
                        }

                        // Institucion (Truncada)
                        $inst_raw = $row['institucion_maestra'] ?? ($row['institucion'] ?? $inst_display);
                        $inst_text = htmlspecialchars(mb_strimwidth($inst_raw, 0, 22, '...'));
                        
                        // Etiqueta Superior
                        $tag_txt = $es_apunte ? "APUNTE" : "CLASES";
                        $tag_color = $es_apunte ? "text-orange-500" : "text-[#54A6D8]";
                        ?>
                        
                        <a href="<?= $enlace_detalle ?>"
                           class="w-[240px] md:w-[260px] shrink-0 snap-start block flex flex-col mb-4 bg-transparent cursor-pointer select-none group h-full active:scale-[0.97] active:opacity-80 transition-all duration-200 <?= $es_basico ? 'opacity-90 grayscale-[15%]' : '' ?>">

                          <div class="relative bg-gray-100 rounded-2xl overflow-hidden w-full aspect-[3/2] border border-gray-200/60">
                            <img src="<?= htmlspecialchars($portada_url) ?>"
                                 alt="<?= htmlspecialchars($titulo) ?>"
                                 class="w-full h-full object-cover transition-opacity duration-300"
                                 loading="lazy"
                                 onerror="this.src='<?= $default_pub_img ?>'">
                            
                            <div class="absolute top-2 left-2 flex flex-wrap gap-1 z-10 scale-90 origin-top-left">
                              <?php if ($nivel_tutor === 'leyenda'): ?>
                                  <span class="bg-white/95 backdrop-blur-sm text-gray-500 text-[9px] font-extrabold uppercase tracking-wider px-2 py-1 rounded-full flex items-center shadow-sm border border-gray-200">
                                      Leyenda
                                  </span>
                              <?php elseif ($nivel_tutor === 'elite'): ?>
                                  <span class="bg-white/95 backdrop-blur-sm text-gray-500 text-[9px] font-extrabold uppercase tracking-wider px-2 py-1 rounded-full flex items-center shadow-sm border border-gray-200">
                                      Élite
                                  </span>
                              <?php elseif ($nivel_tutor === 'pro'): ?>
                                  <span class="bg-white/95 backdrop-blur-sm text-gray-500 text-[9px] font-extrabold uppercase tracking-wider px-2 py-1 rounded-full flex items-center shadow-sm border border-gray-200">
                                      Pro
                                  </span>
                              <?php elseif ($nivel_tutor === 'top'): ?>
                                  <span class="bg-white/95 backdrop-blur-sm text-gray-500 text-[9px] font-extrabold uppercase tracking-wider px-2 py-1 rounded-full flex items-center shadow-sm border border-gray-200">
                                      Top
                                  </span>
                              <?php endif; ?>
                              
                              <?php if($es_apunte): ?>
                                <span class="bg-white/95 backdrop-blur-sm text-orange-600 text-[8px] font-extrabold uppercase tracking-widest px-2 py-0.5 rounded-md border border-slate-100 flex items-center gap-1 shadow-sm">
                                    PDF
                                </span>
                              <?php elseif ($es_nuevo): ?>
                                <span class="bg-white/95 backdrop-blur-sm text-emerald-600 text-[9px] font-extrabold uppercase tracking-wider px-2 py-1 rounded-full border border-slate-100 flex items-center gap-1 shadow-sm">
                                    Nuevo
                                </span>
                              <?php endif; ?>
                            </div>

                            <div class="absolute top-2 right-2 z-20 shrink-0">
                                <img src="<?= htmlspecialchars($foto_tutor, ENT_QUOTES, 'UTF-8') ?>" 
                                     class="w-8 h-8 rounded-full object-cover shadow-sm border-2 border-white bg-gray-50"
                                     alt="Tutor">
                            </div>
                          </div>

                          <div class="pt-2.5 pb-1 flex flex-col flex-1 text-left px-0.5 text-shadow-none">
                              
                              <p class="text-[9px] font-bold <?= $tag_color ?> uppercase tracking-wider mb-0.5"><?= $tag_txt ?></p>

                              <div class="font-bold text-[14px] md:text-[15px] tracking-tight leading-[1.25] text-gray-900 line-clamp-2 mb-1.5">
                                  <?= htmlspecialchars($titulo) ?>
                              </div>

                              <div class="flex items-center gap-1.5 text-[10px] text-gray-400 font-semibold uppercase tracking-wide mb-1.5">
                                  <?php if(!empty($inst_text)): ?>
                                      <span class="truncate max-w-[70%]"><?= $inst_text ?></span>
                                      <span class="w-1 h-1 rounded-full bg-gray-300 shrink-0"></span>
                                  <?php endif; ?>
                                  <span class="shrink-0"><?= $icon_mod ?></span>
                              </div>

                              <div class="flex items-center justify-between mt-auto pt-1">
                                  <div class="text-[14px] font-extrabold <?= $precio_class ?> leading-none">
                                      <?= $precio ?>
                                  </div>
                                  <div class="shrink-0 flex items-center">
                                      <?= $html_stars ?>
                                  </div>
                              </div>
                          </div>
                        </a>
                    <?php endforeach; endif; ?>
                </div>
            </section>
            <?php endif; ?>
        </div>

        <aside class="hidden xl:block">
            <div class="sticky top-24 space-y-6">
                 <?php if (($es_propio || $es_admin) && file_exists($archivo_gestion)): ?>
                    <div class="bg-white rounded-3xl border border-gray-200 p-8 panel-links-wrapper">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Panel de Gestión</h2>
                        </div>
                        <div class="space-y-2"><?php include $archivo_gestion; ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</main>

<?php 
require_once __DIR__ . '/componentes/nav_bottom.php'; 
require_once __DIR__ . '/componentes/modal_publicar.php'; 
require_once __DIR__ . '/componentes/modal_explora.php'; 
?>
<?php if ($es_admin && !$es_propio): ?>
<div id="modal-msg-admin" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-[100] flex items-end md:items-center justify-center p-0 md:p-4">
    <div id="msg-admin-card" class="bg-white w-full md:max-w-md rounded-t-3xl md:rounded-3xl p-6 transform translate-y-full opacity-0 transition-all duration-300">
        
        <!-- Estado: Formulario -->
        <div id="msg-admin-form">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                    <?= icon('shield-check', 'w-5 h-5 text-rose-500') ?> Mensaje a <?= htmlspecialchars($nombre_display) ?>
                </h3>
                <button onclick="cerrarModalMensajeAdmin()" class="text-gray-400 hover:text-gray-700"><?= icon('x-mark', 'w-5 h-5') ?></button>
            </div>
            
            <textarea id="msg-admin-input" maxlength="1000" rows="5" placeholder="Escribe el mensaje oficial..." 
                      class="w-full p-4 border border-gray-200 rounded-2xl focus:border-[#54A6D8] focus:ring-4 focus:ring-[#54A6D8]/10 outline-none text-sm resize-none"
                      oninput="actualizarContadorAdmin()"></textarea>
            
            <div class="flex items-center justify-between mt-2 px-1">
                <p id="msg-admin-error" class="text-red-500 text-[11px] font-medium hidden"></p>
                <p id="msg-admin-counter" class="text-[11px] font-medium text-gray-400 ml-auto">0 / 1000</p>
            </div>
            
            <div class="flex justify-end gap-3 mt-4">
                <button onclick="cerrarModalMensajeAdmin()" class="px-4 py-2 text-[11px] font-bold uppercase text-gray-500 hover:bg-gray-100 rounded-xl transition-colors">Cancelar</button>
                <button id="btn-enviar-admin" onclick="enviarMensajeAdmin(<?= (int)$perfil_id_ver ?>, this)" 
                        class="px-5 py-2 bg-rose-500 text-white text-[11px] font-bold uppercase rounded-xl hover:bg-rose-600 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-rose-500 transition-all"
                        disabled>Enviar</button>
            </div>
        </div>

        <!-- Estado: Éxito (oculto al inicio) -->
        <div id="msg-admin-success" class="hidden text-center py-6">
            <div class="w-16 h-16 mx-auto rounded-full bg-emerald-50 border border-emerald-200 flex items-center justify-center mb-4">
                <?= icon('check', 'w-6 h-6 text-emerald-500') ?>
            </div>
            <h3 class="text-base font-bold text-gray-900 mb-1">Mensaje enviado</h3>
            <p class="text-xs text-gray-500">El usuario lo verá en su próxima visita.</p>
        </div>

    </div>
</div>

<script>
function abrirModalMensajeAdmin() {
    const m = document.getElementById('modal-msg-admin'), c = document.getElementById('msg-admin-card');
    m.classList.remove('hidden');
    requestAnimationFrame(() => c.classList.remove('translate-y-full', 'opacity-0'));
    document.body.style.overflow = 'hidden';
}
function cerrarModalMensajeAdmin() {
    const m = document.getElementById('modal-msg-admin'), c = document.getElementById('msg-admin-card');
    c.classList.add('translate-y-full', 'opacity-0');
    setTimeout(() => { m.classList.add('hidden'); document.body.style.overflow = ''; }, 300);
}
function actualizarContadorAdmin() {
    const input = document.getElementById('msg-admin-input');
    const counter = document.getElementById('msg-admin-counter');
    const btn = document.getElementById('btn-enviar-admin');
    const err = document.getElementById('msg-admin-error');
    
    const len = input.value.trim().length;
    const total = input.value.length;
    
    counter.textContent = `${total} / 1000`;
    
    // Color dinámico estilo Nubira
    if (total >= 900) counter.className = 'text-[11px] font-medium text-rose-500 ml-auto';
    else if (total >= 700) counter.className = 'text-[11px] font-medium text-orange-500 ml-auto';
    else counter.className = 'text-[11px] font-medium text-gray-400 ml-auto';
    
    // Botón habilitado solo si >= 3 chars
    btn.disabled = (len < 3);
    
    // Ocultar error al escribir
    err.classList.add('hidden');
}

async function enviarMensajeAdmin(destinoId, btn) {
    const input = document.getElementById('msg-admin-input');
    const err = document.getElementById('msg-admin-error');
    const texto = input.value.trim();
    
    if (texto.length < 3) {
        err.textContent = 'Escribe al menos 3 caracteres.';
        err.classList.remove('hidden');
        return;
    }
    
    btn.disabled = true;
    btn.innerText = 'ENVIANDO...';
    err.classList.add('hidden');
    
    try {
        const fd = new FormData();
        fd.append('destino_id', destinoId);
        fd.append('mensaje', texto);
        fd.append('csrf_token', CSRF_TOKEN);
        const r = await fetch('/app/admin_enviar_mensaje.php', { method: 'POST', body: fd });
        const d = await r.json();
        
        if (d.success) {
            // Transición visual: form → success
            document.getElementById('msg-admin-form').classList.add('hidden');
            document.getElementById('msg-admin-success').classList.remove('hidden');
            
            // Auto-cerrar tras 1.5s
            setTimeout(() => {
                cerrarModalMensajeAdmin();
                // Reset para la próxima vez
                setTimeout(() => {
                    input.value = '';
                    actualizarContadorAdmin();
                    document.getElementById('msg-admin-form').classList.remove('hidden');
                    document.getElementById('msg-admin-success').classList.add('hidden');
                }, 400);
            }, 1500);
        } else {
            err.textContent = d.error || 'Error al enviar.';
            err.classList.remove('hidden');
            btn.disabled = false;
            btn.innerText = 'Enviar';
        }
    } catch (e) {
        err.textContent = 'Error de conexión.';
        err.classList.remove('hidden');
        btn.disabled = false;
        btn.innerText = 'Enviar';
    }
}
</script>
<?php endif; ?>
<?php 
$rutas_footer = [
    __DIR__ . '/includes/footer.php',
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
// TOKEN CSRF disponible para todas las peticiones
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;

// === NUBIRA SHARE API ===
function compartirPerfilNubira() {
    const urlPerfil = window.location.href;
    const tituloP = 'Perfil de <?= addslashes($nombre_display) ?> en Nubira';
    
    if (navigator.share) {
        navigator.share({
            title: tituloP,
            text: '¡Mira este perfil en Nubira!',
            url: urlPerfil
        }).catch((error) => console.log('Error compartiendo', error));
    } else {
        // Fallback para PC
        navigator.clipboard.writeText(urlPerfil).then(() => {
            alert('¡Enlace del perfil copiado al portapapeles!');
        }).catch(err => {
            console.error('Error al copiar: ', err);
        });
    }
}

// LÓGICA DE NIVEL DE TUTOR DESPLEGABLE
function toggleNivelTutor() {
    const content = document.getElementById('misiones-nivel');
    const chevron = document.getElementById('chevron-nivel');
    if (content.classList.contains('hidden')) {
        content.classList.remove('hidden');
        chevron.classList.add('rotate-180');
    } else {
        content.classList.add('hidden');
        chevron.classList.remove('rotate-180');
    }
}

// FUNCIONES UI
function navScroll(id, d) { const c = document.getElementById(id); if (c) c.scrollBy({ left: d * (c.clientWidth * 0.8), behavior: 'smooth' }); }
function switchReviews(tipo) {
    const t = document.getElementById('reviews-tutor'), a = document.getElementById('reviews-alumno');
    const tb = document.getElementById('btn-tutor'), ab = document.getElementById('btn-alumno');
    if (tipo === 'tutor') { t.classList.remove('hidden'); t.classList.add('flex'); a.classList.add('hidden'); a.classList.remove('flex'); tb.classList.add('tab-active'); tb.classList.remove('tab-inactive'); ab.classList.add('tab-inactive'); ab.classList.remove('tab-active'); }
    else { a.classList.remove('hidden'); a.classList.add('flex'); t.classList.add('hidden'); t.classList.remove('flex'); ab.classList.add('tab-active'); ab.classList.remove('tab-inactive'); tb.classList.add('tab-inactive'); tb.classList.remove('tab-active'); }
}

// BIO
function toggleEditBio() { document.getElementById('bio-view')?.classList.toggle('hidden'); document.getElementById('bio-edit-container')?.classList.toggle('hidden'); document.getElementById('btn-edit-bio')?.classList.toggle('hidden'); }
function saveBio() {
    const input = document.getElementById('bio-input'), btn = document.getElementById('btn-save-bio');
    if (!input || !btn) return;

    // Estado de carga (Feedback inmediato)
    btn.disabled = true; 
    btn.innerText = 'GUARDANDO...';

    // Limpiar errores previos en la UI
    const errEl = document.getElementById('bio-error');
    if (errEl) errEl.classList.add('hidden'); 

    // Uso de FormData: Más robusto para textos largos y emojis nativos (Mobile-First)
    const formData = new FormData();
    formData.append('bio', input.value);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('/app/actualizar_bio.php', { method: 'POST', body: formData })
    .then(async (r) => {
        const rawText = await r.text();
        try {
            return JSON.parse(rawText);
        } catch (e) {
            console.error("❌ BASURA DEL SERVIDOR DETECTADA (BIO):", rawText);
            throw new Error("El servidor envió texto inválido (Posible Warning de PHP). Revisa la consola.");
        }
    })
    .then(d => {
        if(d.success) {
            const bioView = document.getElementById('bio-view');
            // Optimistic UI: Usamos el valor del input en vez de esperar 'd.newBio'
            bioView.innerText = input.value; 
            toggleEditBio();

            // [UX NUBIRA] Recargar suavemente para actualizar la barra de nivel
            setTimeout(() => location.reload(), 800);
        } else {
            if (errEl) { 
                errEl.textContent = d.error || d.message || 'Error al guardar'; 
                errEl.classList.remove('hidden'); 
            }
        }
    })
    .catch((err) => {
        console.error("Error en Fetch Bio:", err);
        if (errEl) { 
            errEl.textContent = err.message || 'Error de conexión'; 
            errEl.classList.remove('hidden'); 
        }
    })
    .finally(() => { 
        // Restaurar botón
        btn.disabled = false; 
        btn.innerText = 'GUARDAR'; 
    });
}
// FOTO PERFIL
function subirFotoPerfil() {
    const input = document.getElementById('foto-input');
    const spinner = document.getElementById('foto-spinner');
    if (!input.files || !input.files[0]) return;
    
    spinner.classList.remove('hidden');
    const formData = new FormData();
    formData.append('foto', input.files[0]);
    formData.append('csrf_token', CSRF_TOKEN);
    
    fetch('/app/actualizar_foto.php', { method: 'POST', body: formData })
    .then(async (r) => {
        const rawText = await r.text();
        try {
            return JSON.parse(rawText);
        } catch (e) {
            console.error("❌ BASURA DEL SERVIDOR DETECTADA:", rawText);
            alert("Error: El servidor envió texto inválido. Revisa la consola (F12) para ver qué dice.");
            throw new Error("JSON Inválido");
        }
    })
    .then(data => {
        if (data.success) {
            const img = document.getElementById('img-perfil-visual');
            img.src = data.url;

            setTimeout(() => {
                location.reload(); 
            }, 800);
        } else {
            alert('Error del servidor: ' + data.message);
        }
    })
    .catch((err) => console.error("Error en Fetch:", err))
    .finally(() => { spinner.classList.add('hidden'); input.value = ''; });
}

function eliminarFotoPerfil() {
    if (!confirm('¿Estás seguro de querer eliminar tu foto de perfil?')) return;
    const spinner = document.getElementById('foto-spinner');
    spinner.classList.remove('hidden');
    const formData = new FormData();
    formData.append('csrf_token', CSRF_TOKEN);
    
    fetch('/app/eliminar_foto.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const img = document.getElementById('img-perfil-visual');
            img.src = data.newUrl;
            
            const btnBorrar = document.getElementById('btn-borrar-foto');
            if (btnBorrar) btnBorrar.remove();

            setTimeout(() => {
                location.reload(); 
            }, 800);

        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(() => alert('Error de conexión'))
    .finally(() => spinner.classList.add('hidden'));
}

// MODALES NUBIRA 2.0
function setupModal(t, m, c, cl) {
    const b = document.getElementById(t), mo = document.getElementById(m), ca = document.getElementById(c), clo = document.getElementById(cl);
    if (!b || !mo || !ca) return;
    const op = () => { mo.classList.remove('hidden'); requestAnimationFrame(() => ca.classList.remove('translate-y-full', 'opacity-0')); document.body.style.overflow = 'hidden'; };
    const clse = () => { ca.classList.add('translate-y-full', 'opacity-0'); setTimeout(() => { mo.classList.add('hidden'); document.body.style.overflow = ''; }, 300); };
    b.onclick = (e) => { e.preventDefault(); op(); };
    if (clo) clo.onclick = (e) => { e.preventDefault(); clse(); };
    mo.onclick = (e) => { if (e.target === mo) clse(); };
}

// NOTIFICACIONES
async function actualizarBadgeChats() {
    <?php if ($is_guest) echo 'return;'; ?>
    try {
        const res = await fetch('/app/contar_mensajes_nuevos.php');
        const data = await res.json();
        const total = parseInt(data.total || 0);
        ['badge-chats-sidebar', 'badge-chats-bottom'].forEach(id => {
            const el = document.getElementById(id);
            if(el) { 
                if(id === 'badge-chats-sidebar') el.innerText = total;
                total > 0 ? el.classList.remove('hidden') : el.classList.add('hidden'); 
            }
        });
    } catch {}
}

document.addEventListener('DOMContentLoaded', () => {
    <?php if ($is_guest): ?>
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

    actualizarBadgeChats(); 
    setInterval(actualizarBadgeChats, 10000);
});
// Mostrar más / Mostrar menos en reseñas
function toggleReviewText(id, btn) {
    const el = document.getElementById(id);
    if (!el) return;
    
    if (el.classList.contains('line-clamp-3')) {
        el.classList.remove('line-clamp-3');
        btn.innerText = 'MOSTRAR MENOS';
    } else {
        el.classList.add('line-clamp-3');
        btn.innerText = 'MOSTRAR MÁS';
    }
}
// ADMIN: Borrar reseñas
<?php if ($es_admin): ?>
window.borrarValoracionPerfil = async function(botonDom, idValoracion) {
    if (!confirm('🛡️ MODO ADMIN:\n¿Seguro que deseas eliminar permanentemente esta reseña del perfil?')) return;

    const cardResena = botonDom.closest('.card-horizontal');
    const originalHtml = botonDom.innerHTML;
    
    botonDom.innerHTML = '<div class="w-4 h-4 border-2 border-current border-t-transparent rounded-full animate-spin text-red-400"></div>';
    botonDom.disabled = true;

    try {
        const formData = new FormData();
        formData.append('id', idValoracion);
        formData.append('csrf_token', CSRF_TOKEN);

        const response = await fetch('/app/eliminar_valoracion.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

       if (data.success) {
            cardResena.style.transition = "all 0.4s ease";
            cardResena.style.opacity = "0";
            cardResena.style.transform = "scale(0.95)";
            
            setTimeout(() => {
                const container = cardResena.closest('#reviews-tutor, #reviews-alumno');
                const isTutor = container.id === 'reviews-tutor';
                cardResena.remove();
                
                // 1. Recalcular las tarjetas visibles en esa pestaña
                const tarjetasRestantes = container.querySelectorAll('.card-horizontal');
                
                // 2. Actualizar el texto del botón de la pestaña (Tutor o Alumno)
                const btnTab = document.getElementById(isTutor ? 'btn-tutor' : 'btn-alumno');
                const elStats = document.getElementById('val-total-reviews');
                const legQty = elStats ? (parseInt(elStats.getAttribute('data-leg-qty')) || 0) : 0;
                
                if (btnTab) {
                    const baseText = isTutor ? 'Reseñas Tutor' : 'Reseñas Alumno';
                    const tabTotalQty = tarjetasRestantes.length + (isTutor ? legQty : 0);
                    btnTab.innerText = `${baseText} (${tabTotalQty})`;
                }
                
                // 3. Recalcular el Total Global y el Promedio Estrella
                const elAvg = document.getElementById('val-avg-rating');
                
                if (elStats && elAvg) {
                    const legNota = parseFloat(elStats.getAttribute('data-leg-nota')) || 0;
                    
                    const tarjetasTutor = document.querySelectorAll('#reviews-tutor .card-horizontal');
                    const tarjetasAlumno = document.querySelectorAll('#reviews-alumno .card-horizontal');
                    
                    let sumTutor = 0;
                    tarjetasTutor.forEach(c => sumTutor += parseInt(c.getAttribute('data-rating')) || 0);
                    
                    const totalTutorQty = legQty + tarjetasTutor.length;
                    const totalAlumnoQty = tarjetasAlumno.length;
                    
                    // Actualizar el número total combinado (Tutor + Alumno)
                    elStats.innerText = totalTutorQty + totalAlumnoQty;
                    
                    // Calcular el nuevo promedio de Tutor (que es el que se muestra arriba en PHP)
                    let nuevoPromedio = 0;
                    if (totalTutorQty > 0) {
                        nuevoPromedio = ((legNota * legQty) + sumTutor) / totalTutorQty;
                    }
                    elAvg.innerText = nuevoPromedio > 0 ? nuevoPromedio.toFixed(1) : '0.0';
                }
                
                // 4. Mostrar mensaje de "vacío" si se borraron todas de la sección
                if (tarjetasRestantes.length === 0) {
                    container.innerHTML = `<div class="min-w-full py-6 text-center text-gray-400 italic border border-dashed border-gray-200 rounded-3xl">Sin reseñas como ${isTutor ? 'tutor' : 'alumno'} aún.</div>`;
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

<?php if ($es_propio): ?>


// === SMART POLLING: VISITAS EN TIEMPO REAL NUBIRA ===
setInterval(async () => {
    try {
        const res = await fetch('/app/obtener_mis_visitas.php');
        const data = await res.json();
        
        if (data.vistas !== undefined) {
            const spanVisitas = document.getElementById('num-visitas-live');
            const contVisitas = document.getElementById('contenedor-visitas-live');
            
            const visitasActuales = parseInt(spanVisitas.innerText);
            
            if (data.vistas > visitasActuales) {
                spanVisitas.innerText = data.vistas;
                contVisitas.classList.add('scale-110');
                setTimeout(() => contVisitas.classList.remove('scale-110'), 300);
            }
        }
    } catch (e) {}
}, 15000);
<?php endif; ?>
</script>

</body>
</html>