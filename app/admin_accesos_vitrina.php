<?php
// === MODO PRODUCCIÓN ===
$IS_DEV = ($_SERVER['HTTP_HOST'] ?? '') === 'localhost' || strpos($_SERVER['HTTP_HOST'] ?? '', 'staging') !== false;
ini_set('display_errors', $IS_DEV ? 1 : 0);
ini_set('display_startup_errors', $IS_DEV ? 1 : 0);
error_reporting($IS_DEV ? E_ALL : 0);

if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();

$rutas_conexion = [__DIR__.'/conexion.php', __DIR__.'/../conexion.php', $_SERVER['DOCUMENT_ROOT'].'/app/conexion.php', $_SERVER['DOCUMENT_ROOT'].'/conexion.php'];
$conn_found = false;
foreach($rutas_conexion as $rc) { 
    if(file_exists($rc)){ require_once $rc; $conn_found = true; break; } 
}
if (!$conn_found) die("Error Crítico [Nubira Shield]: No se encontró conexion.php.");

$rutas_iconos = [__DIR__.'/iconos.php', __DIR__.'/../iconos.php', $_SERVER['DOCUMENT_ROOT'].'/app/iconos.php', $_SERVER['DOCUMENT_ROOT'].'/iconos.php'];
foreach($rutas_iconos as $ri) {
    if(file_exists($ri)){ require_once $ri; break; }
}

require_once __DIR__ . '/seguridad_url.php';

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') { 
    header("Location: /login"); 
    exit; 
}

if (!isset($conn) || $conn->connect_error) {
    die("Error Crítico [Base de Datos]: No se pudo establecer conexión.");
}

// AUTO-MIGRACIÓN SILENCIOSA: columnas referrer y utm_source en historial_actividad
$chk_ref = $conn->query("SHOW COLUMNS FROM historial_actividad LIKE 'referrer'");
if ($chk_ref && $chk_ref->num_rows === 0) {
    $conn->query("ALTER TABLE historial_actividad ADD COLUMN referrer VARCHAR(255) NULL DEFAULT NULL");
}
$chk_utm = $conn->query("SHOW COLUMNS FROM historial_actividad LIKE 'utm_source'");
if ($chk_utm && $chk_utm->num_rows === 0) {
    $conn->query("ALTER TABLE historial_actividad ADD COLUMN utm_source VARCHAR(100) NULL DEFAULT NULL");
}

// ============================================================
// ACCIONES POST
// ============================================================
if (isset($_POST['accion_global'])) {
    if ($_POST['accion_global'] === 'eliminar' && !empty($_POST['ids'])) {
        $ids = array_map('intval', $_POST['ids']);
        $stmt = $conn->prepare("DELETE FROM historial_actividad WHERE id = ?");
        foreach ($ids as $id) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
        }
        $stmt->close();
    } elseif ($_POST['accion_global'] === 'purgar_bots') {
        // Purgar bots de más de 30 días
        $conn->query("DELETE FROM historial_actividad WHERE es_bot = 1 AND fecha < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    }
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// ============================================================
// EXPORTACIÓN CSV
// ============================================================
if (isset($_GET['exportar'])) {
    $uid_export = isset($_GET['uid']) ? (int)$_GET['uid'] : null;
    $fecha_export = isset($_GET['fecha']) ? $_GET['fecha'] : null;
    $incluir_bots = !empty($_GET['incluir_bots']);
    
    $filename = "nubira_actividad_" . date('Y-m-d_H-i') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Usuario ID', 'Nombre', 'Accion', 'Detalle', 'URL', 'IP', 'Es Bot', 'User Agent', 'Fecha']);

    $sql_ex = "SELECT h.*, a.nombre FROM historial_actividad h LEFT JOIN alumnos a ON h.usuario_id = a.id WHERE 1=1";
    $params = [];
    $types = "";

    if (!$incluir_bots) {
        $sql_ex .= " AND h.es_bot = 0";
    }

    if ($uid_export !== null) {
        if ($uid_export === 0) {
            $sql_ex .= " AND (h.usuario_id IS NULL OR h.usuario_id = 0)";
        } else {
            $sql_ex .= " AND h.usuario_id = ?";
            $params[] = $uid_export;
            $types .= "i";
        }
    }
    if ($fecha_export) {
        $sql_ex .= " AND DATE(h.fecha) = ?";
        $params[] = $fecha_export;
        $types .= "s";
    }

    $sql_ex .= " ORDER BY h.id DESC";
    
    $stmt = $conn->prepare($sql_ex);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res_ex = $stmt->get_result();
    
    if ($res_ex) {
        while ($row = $res_ex->fetch_assoc()) {
            fputcsv($output, [
                $row['id'], $row['usuario_id'], $row['nombre'] ?? 'Visitante', 
                $row['accion'], $row['detalle'], $row['url'], $row['ip_usuario'], 
                $row['es_bot'] ?? 0, $row['user_agent'] ?? '', $row['fecha']
            ]);
        }
    }
    fclose($output);
    exit;
}

// ============================================================
// LÓGICA PRINCIPAL
// ============================================================
$tab_activa = $_GET['tab'] ?? 'trafico'; 
$ver_usuario_id = isset($_GET['uid']) ? (int)$_GET['uid'] : null;

// ============================================================
// VISTA DETALLE DE USUARIO
// ============================================================
if ($ver_usuario_id !== null) {
    $filtro_ip = isset($_GET['ip']) ? $_GET['ip'] : null;
    $orden = $_GET['ord'] ?? 'desc';
    $col_orden = $_GET['col'] ?? 'id';
    $valid_cols = ['fecha', 'accion', 'id'];
    if (!in_array($col_orden, $valid_cols)) $col_orden = 'id';
    $sql_sort = " ORDER BY $col_orden " . ($orden === 'asc' ? 'ASC' : 'DESC');

    $is_guest = ($ver_usuario_id === 0);

    if ($is_guest && $filtro_ip) {
        $stmt_stats = $conn->prepare("SELECT COUNT(id) as total_acciones, MAX(fecha) as max_f, MIN(fecha) as min_f, COUNT(DISTINCT ip_usuario) as total_ips, MAX(es_bot) as fue_bot, COUNT(DISTINCT url) as urls_unicas FROM historial_actividad WHERE (usuario_id IS NULL OR usuario_id = 0) AND ip_usuario = ?");
        $stmt_stats->bind_param("s", $filtro_ip);
    } else {
        $stmt_stats = $conn->prepare("SELECT COUNT(id) as total_acciones, MAX(fecha) as max_f, MIN(fecha) as min_f, COUNT(DISTINCT ip_usuario) as total_ips, MAX(es_bot) as fue_bot, COUNT(DISTINCT url) as urls_unicas FROM historial_actividad WHERE usuario_id = ?");
        $stmt_stats->bind_param("i", $ver_usuario_id);
    }
    $stmt_stats->execute();
    $stats = $stmt_stats->get_result()->fetch_assoc() ?? ['total_acciones' => 0, 'max_f' => null, 'fue_bot' => 0];
    $stmt_stats->close();
    
    if ($is_guest && $filtro_ip) {
        $stmt_fav = $conn->prepare("SELECT accion, COUNT(id) as freq FROM historial_actividad WHERE (usuario_id IS NULL OR usuario_id = 0) AND ip_usuario = ? GROUP BY accion ORDER BY freq DESC LIMIT 1");
        $stmt_fav->bind_param("s", $filtro_ip);
    } else {
        $stmt_fav = $conn->prepare("SELECT accion, COUNT(id) as freq FROM historial_actividad WHERE usuario_id = ? GROUP BY accion ORDER BY freq DESC LIMIT 1");
        $stmt_fav->bind_param("i", $ver_usuario_id);
    }
    $stmt_fav->execute();
    $res_fav = $stmt_fav->get_result();
    $accion_fav = ($res_fav && $res_fav->num_rows > 0) ? $res_fav->fetch_assoc()['accion'] : 'N/A';
    $stmt_fav->close();

    if ($is_guest && $filtro_ip) {
        $target_ip = $filtro_ip;
    } else {
        $stmt_ip = $conn->prepare("SELECT ip_usuario FROM historial_actividad WHERE usuario_id = ? AND ip_usuario IS NOT NULL AND ip_usuario != '' ORDER BY id DESC LIMIT 1");
        $stmt_ip->bind_param("i", $ver_usuario_id);
        $stmt_ip->execute();
        $res_ip = $stmt_ip->get_result();
        $target_ip = ($res_ip && $res_ip->num_rows > 0) ? $res_ip->fetch_assoc()['ip_usuario'] : null;
        $stmt_ip->close();
    }

    $max_f = $stats['max_f'] ?? null;
    $online_detalle = $max_f ? ((time() - strtotime($max_f)) < 300) : false;
    $fue_bot = (int)($stats['fue_bot'] ?? 0);

    if ($is_guest) {
        $usuario_target = [
            'nombre' => $filtro_ip ? ($fue_bot ? '🤖 Bot/Crawler' : 'Invitado ' . strtoupper(substr(md5($filtro_ip), 0, 5))) : 'Tráfico Anónimo',
            'correo' => $filtro_ip ? 'Huella: ' . htmlspecialchars($filtro_ip) : 'Usuarios sin cuenta registrada',
            'foto_perfil' => null,
            'institucion' => 'Tráfico público'
        ];
        
        if ($filtro_ip) {
            $stmt_h = $conn->prepare("SELECT * FROM historial_actividad WHERE (usuario_id IS NULL OR usuario_id = 0) AND ip_usuario = ? $sql_sort LIMIT 500");
            $stmt_h->bind_param("s", $filtro_ip);
        } else {
            $stmt_h = $conn->prepare("SELECT * FROM historial_actividad WHERE (usuario_id IS NULL OR usuario_id = 0) $sql_sort LIMIT 500");
        }
        $stmt_h->execute();
        $historial = $stmt_h->get_result();
        
    } else {
        $stmt_u = $conn->prepare("SELECT * FROM alumnos WHERE id = ?");
        $stmt_u->bind_param("i", $ver_usuario_id);
        $stmt_u->execute();
        $usuario_target = $stmt_u->get_result()->fetch_assoc() ?? ['nombre' => 'Usuario Desconocido'];
        $stmt_u->close();

        $stmt_h = $conn->prepare("SELECT * FROM historial_actividad WHERE usuario_id = ? $sql_sort LIMIT 300");
        $stmt_h->bind_param("i", $ver_usuario_id);
        $stmt_h->execute();
        $historial = $stmt_h->get_result();
    }
    $total_eventos_detalle = $stats['total_acciones'];

    // === BLOQUE RESUMEN: queries adicionales ===

    // 1. Primer referrer / utm_source registrado
    $primer_referrer = null;
    $primer_utm = null;
    if (!$is_guest) {
        $stmt_ref = $conn->prepare("SELECT referrer, utm_source FROM historial_actividad WHERE usuario_id = ? AND (referrer IS NOT NULL OR utm_source IS NOT NULL) ORDER BY fecha ASC LIMIT 1");
        $stmt_ref->bind_param("i", $ver_usuario_id);
        $stmt_ref->execute();
        $res_ref = $stmt_ref->get_result()->fetch_assoc();
        if ($res_ref) { $primer_referrer = $res_ref['referrer']; $primer_utm = $res_ref['utm_source']; }
        $stmt_ref->close();
    } elseif ($filtro_ip) {
        $stmt_ref = $conn->prepare("SELECT referrer, utm_source FROM historial_actividad WHERE (usuario_id IS NULL OR usuario_id = 0) AND ip_usuario = ? AND (referrer IS NOT NULL OR utm_source IS NOT NULL) ORDER BY fecha ASC LIMIT 1");
        $stmt_ref->bind_param("s", $filtro_ip);
        $stmt_ref->execute();
        $res_ref = $stmt_ref->get_result()->fetch_assoc();
        if ($res_ref) { $primer_referrer = $res_ref['referrer']; $primer_utm = $res_ref['utm_source']; }
        $stmt_ref->close();
    }

    // 2. Conversión: primer CONTACTO y primer PUBLICAR_APUNTE (solo usuarios registrados)
    $conv = ['primer_contacto' => null, 'primer_apunte' => null];
    if (!$is_guest) {
        $stmt_conv = $conn->prepare("SELECT MIN(CASE WHEN accion = 'CONTACTO' THEN fecha END) as primer_contacto, MIN(CASE WHEN accion = 'PUBLICAR_APUNTE' THEN fecha END) as primer_apunte FROM historial_actividad WHERE usuario_id = ?");
        $stmt_conv->bind_param("i", $ver_usuario_id);
        $stmt_conv->execute();
        $conv = $stmt_conv->get_result()->fetch_assoc() ?? $conv;
        $stmt_conv->close();
    }

    // 3. Último user_agent (para detectar dispositivo)
    $ultimo_ua = null;
    if (!$is_guest) {
        $stmt_ua = $conn->prepare("SELECT user_agent FROM historial_actividad WHERE usuario_id = ? AND user_agent IS NOT NULL AND user_agent != '' ORDER BY fecha DESC LIMIT 1");
        $stmt_ua->bind_param("i", $ver_usuario_id);
        $stmt_ua->execute();
        $res_ua = $stmt_ua->get_result()->fetch_assoc();
        if ($res_ua) $ultimo_ua = $res_ua['user_agent'];
        $stmt_ua->close();
    } elseif ($filtro_ip) {
        $stmt_ua = $conn->prepare("SELECT user_agent FROM historial_actividad WHERE ip_usuario = ? AND user_agent IS NOT NULL AND user_agent != '' ORDER BY fecha DESC LIMIT 1");
        $stmt_ua->bind_param("s", $filtro_ip);
        $stmt_ua->execute();
        $res_ua = $stmt_ua->get_result()->fetch_assoc();
        if ($res_ua) $ultimo_ua = $res_ua['user_agent'];
        $stmt_ua->close();
    }

    // 4. Días desde la primera visita (calculado en PHP)
    $dias_desde_primera = $stats['min_f'] ? (int)floor((time() - strtotime($stats['min_f'])) / 86400) : 0;

// ============================================================
// TAB: TOP PÁGINAS
// ============================================================
} elseif ($tab_activa === 'paginas') {
    $res_paginas = $conn->query("SELECT url,
                    COUNT(*) AS hits,
                    COUNT(DISTINCT COALESCE(usuario_id, ip_usuario)) AS uniques
                 FROM historial_actividad
                 WHERE es_bot = 0 AND fecha > DATE_SUB(NOW(), INTERVAL 14 DAY)
                 GROUP BY url
                 ORDER BY hits DESC
                 LIMIT 50");
    $total_hits_paginas = $conn->query("SELECT COUNT(*) as total FROM historial_actividad WHERE es_bot = 0 AND fecha > DATE_SUB(NOW(), INTERVAL 14 DAY)")->fetch_assoc()['total'] ?? 1;

// ============================================================
// TAB: BÚSQUEDAS FALLIDAS
// ============================================================
} elseif ($tab_activa === 'fallidas') {
    $sql_demandas = "SELECT termino, COUNT(*) as total_intentos, MAX(fecha) as ultima_busqueda 
                     FROM busquedas_fallidas GROUP BY termino ORDER BY total_intentos DESC, ultima_busqueda DESC LIMIT 50";
    $res_demandas = $conn->query($sql_demandas);

// ============================================================
// TAB: BOTS / CRAWLERS
// ============================================================
} elseif ($tab_activa === 'bots') {
    $sql_bots = "SELECT 
                    ip_usuario,
                    SUBSTRING_INDEX(user_agent, ' ', 3) as ua_corto,
                    user_agent,
                    COUNT(id) as total_hits,
                    MAX(fecha) as ultima_visita,
                    MIN(fecha) as primera_visita,
                    COUNT(DISTINCT url) as urls_unicas
                 FROM historial_actividad
                 WHERE es_bot = 1 AND fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY ip_usuario, user_agent
                 ORDER BY total_hits DESC, ultima_visita DESC
                 LIMIT 100";
    $res_bots = $conn->query($sql_bots);
    
    $stats_bots = $conn->query("SELECT 
                    COUNT(id) as total_eventos,
                    COUNT(DISTINCT ip_usuario) as ips_unicas,
                    COUNT(DISTINCT user_agent) as bots_unicos
                 FROM historial_actividad
                 WHERE es_bot = 1 AND fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc() ?? ['total_eventos' => 0, 'ips_unicas' => 0, 'bots_unicos' => 0];

// ============================================================
// TAB: TRÁFICO GLOBAL (USUARIOS REALES)
// ============================================================
} else {
    // Query mejorada: excluye bots desde el origen
    $sql_list = "SELECT h1.usuario_id, h1.ip_usuario, h1.ultima_actividad, h1.total_acciones, 
                        h2.url as ultima_url, h2.accion as ultima_accion_txt,
                        a.nombre, a.foto_perfil, a.institucion, a.correo
                 FROM (
                     SELECT IFNULL(usuario_id, 0) as usuario_id, ip_usuario, MAX(fecha) as ultima_actividad, COUNT(id) as total_acciones
                     FROM historial_actividad
                     WHERE fecha >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                       AND es_bot = 0
                     GROUP BY IFNULL(usuario_id, 0), CASE WHEN IFNULL(usuario_id, 0) = 0 THEN ip_usuario ELSE '1' END
                 ) h1
                 LEFT JOIN historial_actividad h2 ON 
                      IFNULL(h2.usuario_id, 0) = h1.usuario_id AND 
                      (h1.usuario_id != 0 OR h2.ip_usuario = h1.ip_usuario) AND 
                      h2.fecha = h1.ultima_actividad AND
                      h2.es_bot = 0
                 LEFT JOIN alumnos a ON h1.usuario_id = a.id
                 ORDER BY h1.ultima_actividad DESC LIMIT 150";
    
    $conn->query("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
    $lista_usuarios = $conn->query($sql_list);
    
    // Contadores rápidos para los pills
    $contadores = $conn->query("SELECT 
                    SUM(CASE WHEN es_bot = 0 AND usuario_id IS NOT NULL AND usuario_id > 0 THEN 1 ELSE 0 END) as alumnos,
                    SUM(CASE WHEN es_bot = 0 AND (usuario_id IS NULL OR usuario_id = 0) THEN 1 ELSE 0 END) as invitados,
                    SUM(CASE WHEN es_bot = 1 THEN 1 ELSE 0 END) as bots
                 FROM historial_actividad
                 WHERE fecha >= DATE_SUB(NOW(), INTERVAL 14 DAY)")->fetch_assoc() ?? ['alumnos' => 0, 'invitados' => 0, 'bots' => 0];
}

function getBadge($accion) {
    $accion = strtoupper($accion);
    if (strpos($accion, 'LOGIN') !== false) return ['bg' => 'bg-emerald-50', 'txt' => 'text-emerald-600', 'icon' => 'fa-door-open'];
    if (strpos($accion, 'GUEST') !== false) return ['bg' => 'bg-gray-100', 'txt' => 'text-gray-500', 'icon' => 'fa-mask'];
    if (strpos($accion, 'VITRINA') !== false) return ['bg' => 'bg-indigo-50', 'txt' => 'text-indigo-600', 'icon' => 'fa-eye'];
    if (strpos($accion, 'BUSQUEDA') !== false) return ['bg' => 'bg-amber-50', 'txt' => 'text-amber-600', 'icon' => 'fa-magnifying-glass'];
    if (strpos($accion, 'VER_SERVICIO') !== false || strpos($accion, 'VER_APUNTE') !== false) return ['bg' => 'bg-sky-50', 'txt' => 'text-[#54A6D8]', 'icon' => 'fa-layer-group'];
    if (strpos($accion, 'CONTACTO') !== false) return ['bg' => 'bg-purple-50', 'txt' => 'text-purple-600', 'icon' => 'fa-handshake'];
    return ['bg' => 'bg-gray-50', 'txt' => 'text-gray-500', 'icon' => 'fa-bolt'];
}

function nombreBotCorto($ua) {
    if (empty($ua)) return 'Sin User-Agent';
    $patrones = [
        '/googlebot/i' => '🔍 Googlebot',
        '/bingbot|bingpreview/i' => '🔍 Bingbot',
        '/yandex/i' => '🔍 YandexBot',
        '/baidu/i' => '🔍 Baidu',
        '/duckduckbot/i' => '🦆 DuckDuckBot',
        '/applebot/i' => '🍎 Applebot',
        '/facebookexternalhit/i' => '📘 Facebook',
        '/whatsapp/i' => '💬 WhatsApp',
        '/telegram/i' => '✈️ Telegram',
        '/linkedinbot/i' => '💼 LinkedIn',
        '/ahrefs/i' => '📊 Ahrefs (SEO)',
        '/semrush/i' => '📊 SEMrush (SEO)',
        '/mj12bot/i' => '🤖 MJ12 (Majestic)',
        '/dotbot/i' => '🤖 DotBot (Moz)',
        '/petalbot/i' => '🌸 PetalBot',
        '/python-requests/i' => '🐍 Python Script',
        '/curl/i' => '⚡ curl',
        '/wget/i' => '⚡ wget',
        '/scrapy/i' => '🕷️ Scrapy',
        '/headlesschrome|puppeteer|playwright/i' => '🎭 Headless Browser',
    ];
    foreach ($patrones as $p => $nombre) {
        if (preg_match($p, $ua)) return $nombre;
    }
    return '🤖 Bot Genérico';
}

function categorizarReferrer($referrer) {
    if (empty($referrer)) return ['label' => 'Directo / Desconocido', 'icon' => 'fa-solid fa-link', 'color_text' => 'text-gray-500', 'color_bg' => 'bg-gray-100'];
    if (preg_match('/google\./i', $referrer))    return ['label' => 'Google',    'icon' => 'fa-brands fa-google',    'color_text' => 'text-blue-600',  'color_bg' => 'bg-blue-50'];
    if (preg_match('/facebook\.|fb\.com/i', $referrer)) return ['label' => 'Facebook',  'icon' => 'fa-brands fa-facebook',  'color_text' => 'text-blue-700',  'color_bg' => 'bg-blue-50'];
    if (preg_match('/instagram\./i', $referrer)) return ['label' => 'Instagram', 'icon' => 'fa-brands fa-instagram', 'color_text' => 'text-pink-600',  'color_bg' => 'bg-pink-50'];
    if (preg_match('/whatsapp\./i', $referrer))  return ['label' => 'WhatsApp',  'icon' => 'fa-brands fa-whatsapp',  'color_text' => 'text-green-600', 'color_bg' => 'bg-green-50'];
    return ['label' => 'Otro', 'icon' => 'fa-solid fa-arrow-up-right-from-square', 'color_text' => 'text-gray-500', 'color_bg' => 'bg-gray-100'];
}

function detectarDispositivo($ua) {
    if (empty($ua)) return ['label' => 'Desconocido', 'icon' => 'fa-solid fa-question'];
    if (preg_match('/iPhone|iPod/i', $ua))        return ['label' => 'iPhone',         'icon' => 'fa-solid fa-mobile-screen-button'];
    if (preg_match('/iPad/i', $ua))               return ['label' => 'iPad',           'icon' => 'fa-solid fa-tablet-screen-button'];
    if (preg_match('/Android.*Mobile/i', $ua))    return ['label' => 'Android Móvil',  'icon' => 'fa-solid fa-mobile-screen-button'];
    if (preg_match('/Android/i', $ua))            return ['label' => 'Android Tablet', 'icon' => 'fa-solid fa-tablet-screen-button'];
    if (preg_match('/Windows/i', $ua))            return ['label' => 'Windows',        'icon' => 'fa-solid fa-desktop'];
    if (preg_match('/Macintosh/i', $ua))          return ['label' => 'Mac',            'icon' => 'fa-solid fa-desktop'];
    if (preg_match('/Linux/i', $ua))              return ['label' => 'Linux',          'icon' => 'fa-solid fa-desktop'];
    return ['label' => 'Otro', 'icon' => 'fa-solid fa-desktop'];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <title>Monitor Analítico | Nubira</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f9fafb; -webkit-tap-highlight-color: transparent;}
        .force-no-shadow * { text-shadow: none !important; }
        .toggle-checkbox:checked { right: 0; border-color: #54A6D8; }
        .toggle-checkbox:checked + .toggle-label { background-color: #54A6D8; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in-up { animation: fadeInUp 0.4s ease-out forwards; }
    </style>
</head>
<body class="text-gray-800 antialiased overflow-x-hidden force-no-shadow bg-gray-50">

<?php 
$header_path = $_SERVER['DOCUMENT_ROOT'] . '/app/componentes/header.php';
$sidebar_path = $_SERVER['DOCUMENT_ROOT'] . '/app/componentes/sidebar.php';
if (file_exists($header_path)) include $header_path;
if (file_exists($sidebar_path)) include $sidebar_path; 
?>

<main class="pt-16 pb-32 md:pb-16 lg:ml-64 px-4 md:px-6 w-full md:w-[calc(100%-16rem)]">
  <div class="max-w-[1400px] mx-auto space-y-6">

    <div class="sticky top-16 bg-gray-50/95 backdrop-blur-md z-30 border-b border-gray-100 py-4 flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-xl md:text-2xl font-extrabold text-gray-900 tracking-tight flex items-center gap-2">
                <i class="fa-solid fa-chart-pie text-[#54A6D8]"></i> Analíticas
            </h1>
            <p class="text-sm text-gray-500 font-medium mt-0.5">Auditoría, Tráfico y Demandas geolocalizadas.</p>
        </div>
        
        <?php if ($ver_usuario_id === null): ?>
        <div class="flex items-center gap-4 bg-white p-1.5 rounded-2xl border border-gray-100 shadow-sm">
            <div class="flex items-center gap-2 px-3 py-1">
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">En Vivo</span>
                <div class="relative inline-block w-9 mr-1 align-middle select-none transition duration-200 ease-in">
                    <input type="checkbox" name="toggle" id="live-toggle" class="toggle-checkbox absolute block w-4 h-4 rounded-full bg-white border-[3px] appearance-none cursor-pointer border-gray-300 top-0.5 left-0.5"/>
                    <label for="live-toggle" class="toggle-label block overflow-hidden h-5 rounded-full bg-gray-200 cursor-pointer"></label>
                </div>
            </div>
            <div class="h-6 w-px bg-gray-200"></div>
            <a href="?exportar=1" class="text-gray-500 active:text-emerald-600 active:bg-emerald-50 transition-colors text-[11px] font-bold uppercase tracking-widest flex items-center gap-2 px-4 py-2 rounded-xl">
                <i class="fa-solid fa-cloud-arrow-down"></i> Exportar
            </a>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($ver_usuario_id === null): ?>
    <div class="flex gap-4 md:gap-6 mb-6 border-b border-gray-100 overflow-x-auto custom-scrollbar bg-gray-50 sticky top-[104px] md:top-[90px] z-20">
        <a href="?tab=trafico" class="pb-3 px-1 border-b-2 font-bold text-xs uppercase tracking-widest whitespace-nowrap transition-colors <?= $tab_activa === 'trafico' ? 'border-[#54A6D8] text-[#54A6D8]' : 'border-transparent text-gray-400 hover:text-gray-600' ?>">
            <i class="fa-solid fa-users-viewfinder mr-1.5"></i> Tráfico Real
        </a>
        <a href="?tab=bots" class="pb-3 px-1 border-b-2 font-bold text-xs uppercase tracking-widest whitespace-nowrap transition-colors <?= $tab_activa === 'bots' ? 'border-purple-500 text-purple-600' : 'border-transparent text-gray-400 hover:text-gray-600' ?>">
            <i class="fa-solid fa-robot mr-1.5"></i> Bots / Crawlers
        </a>
        <a href="?tab=paginas" class="pb-3 px-1 border-b-2 font-bold text-xs uppercase tracking-widest whitespace-nowrap transition-colors <?= $tab_activa === 'paginas' ? 'border-[#54A6D8] text-[#54A6D8]' : 'border-transparent text-gray-400 hover:text-gray-600' ?>">
            <i class="fa-solid fa-chart-bar mr-1.5"></i> Top Páginas
        </a>
        <a href="?tab=fallidas" class="pb-3 px-1 border-b-2 font-bold text-xs uppercase tracking-widest whitespace-nowrap transition-colors <?= $tab_activa === 'fallidas' ? 'border-orange-500 text-orange-500' : 'border-transparent text-gray-400 hover:text-gray-600' ?>">
            <i class="fa-solid fa-search-minus mr-1.5"></i> Búsquedas Fallidas
        </a>
    </div>
    <?php endif; ?>

    <?php if ($ver_usuario_id !== null): ?>
        <!-- ===== VISTA DETALLE USUARIO ===== -->
        <div class="mb-6 animate-fade-in-up">
            <div class="flex items-center justify-between mb-4">
                <a href="?tab=trafico" class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-gray-400 hover:text-[#54A6D8] transition-colors"><i class="fa-solid fa-arrow-left"></i> Volver</a>
                <a href="?exportar=1&uid=<?= $ver_usuario_id ?><?= $filtro_ip ? '&ip='.urlencode($filtro_ip) : '' ?>" class="text-white bg-[#54A6D8] hover:bg-sky-500 transition-colors text-[10px] font-bold uppercase tracking-widest flex items-center gap-1.5 px-4 py-2 rounded-xl shadow-sm">
                    <i class="fa-solid fa-cloud-arrow-down"></i> Exportar Registro
                </a>
            </div>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div class="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm flex flex-col gap-4 col-span-1 lg:col-span-2">
                    <div class="flex items-center gap-5">
                        <div class="w-16 h-16 rounded-2xl <?= $fue_bot ? 'bg-purple-100 text-purple-600' : ($ver_usuario_id === 0 ? 'bg-gray-100 text-gray-400' : 'bg-gray-50 text-gray-300') ?> relative border border-gray-100 flex items-center justify-center shrink-0">
                            <?php if ($fue_bot): ?>
                                <i class="fa-solid fa-robot text-2xl"></i>
                            <?php elseif (!empty($usuario_target['foto_perfil'])): ?>
                                <img src="/app/perfil/fotos/<?= htmlspecialchars($usuario_target['foto_perfil']) ?>" class="w-full h-full object-cover rounded-2xl">
                            <?php else: ?>
                                <div class="text-2xl font-bold"><?= $ver_usuario_id === 0 ? '<i class="fa-solid fa-mask"></i>' : strtoupper(substr($usuario_target['nombre']??'U',0,1)) ?></div>
                            <?php endif; ?>
                            <?php if($online_detalle && !$fue_bot): ?><span class="absolute -bottom-1 -right-1 w-4 h-4 bg-emerald-500 border-[3px] border-white rounded-full animate-pulse"></span><?php endif; ?>
                        </div>
                        <div>
                            <h1 class="text-2xl font-extrabold text-gray-900 flex items-center gap-2 tracking-tight">
                                <?= htmlspecialchars($usuario_target['nombre'] ?? 'Usuario') ?>
                                <?php if($fue_bot): ?><span class="text-[9px] bg-purple-50 text-purple-600 border border-purple-100 px-2 py-0.5 rounded-md font-bold uppercase tracking-widest">Bot</span><?php endif; ?>
                                <?php if($online_detalle && !$fue_bot): ?><span class="text-[9px] bg-emerald-50 text-emerald-600 border border-emerald-100 px-2 py-0.5 rounded-md font-bold uppercase tracking-widest">Online</span><?php endif; ?>
                            </h1>
                            <p class="text-sm text-gray-500 font-medium"><?= htmlspecialchars($usuario_target['correo'] ?? 'Sin correo') ?></p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mt-4 pt-4 border-t border-gray-50">
                        <div>
                            <div class="flex items-center gap-2 mb-1">
                                <i class="fa-solid fa-bolt text-[#54A6D8] text-xs"></i>
                                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Total Eventos</span>
                            </div>
                            <p class="text-xl font-black text-gray-900 tracking-tight"><?= number_format($total_eventos_detalle, 0, ',', '.') ?></p>
                        </div>
                        <div>
                            <div class="flex items-center gap-2 mb-1">
                                <i class="fa-solid fa-star text-amber-500 text-xs"></i>
                                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Top Acción</span>
                            </div>
                            <p class="text-sm font-bold text-gray-900 truncate mt-1" title="<?= htmlspecialchars($accion_fav) ?>"><?= htmlspecialchars($accion_fav) ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl p-1 border border-gray-100 shadow-sm relative overflow-hidden flex flex-col group detail-card" data-ip="<?= htmlspecialchars($target_ip ?? '0.0.0.0') ?>">
                    <div class="map-container w-full h-32 bg-gray-50 rounded-xl overflow-hidden flex items-center justify-center animate-pulse">
                        <i class="fa-solid fa-map-location-dot text-gray-300 text-3xl"></i>
                    </div>
                    
                    <div class="p-4 bg-white z-10 flex-1 flex flex-col justify-between">
                        <div>
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Geolocalización (IP)</p>
                            <p class="text-sm font-bold text-gray-800 flex items-center flex-wrap gap-2 loc-text truncate">
                                <span class="animate-pulse bg-gray-200 h-4 w-24 rounded inline-block"></span>
                            </p>
                        </div>
                        <div class="mt-2 pt-2 border-t border-gray-50 flex justify-between items-center">
                            <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Última Conex.</span>
                            <span class="text-xs font-mono text-gray-600 font-medium"><?= $max_f ? date('d/m/Y H:i', strtotime($max_f)) : 'N/A' ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== BLOQUE RESUMEN TRAYECTORIA ===== -->
            <?php
                $ref_data      = categorizarReferrer($primer_referrer);
                $dispositivo   = detectarDispositivo($ultimo_ua);
                $hizo_contacto = !empty($conv['primer_contacto']);
                $hizo_apunte   = !empty($conv['primer_apunte']);
                $urls_unicas   = (int)($stats['urls_unicas'] ?? 0);
            ?>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 mb-6 animate-fade-in-up" style="animation-delay: 0.15s;">
                <div class="flex items-center gap-2 mb-5">
                    <i class="fa-solid fa-route text-[#54A6D8] text-sm"></i>
                    <h3 class="font-bold text-gray-900 text-sm uppercase tracking-widest">Resumen de Trayectoria</h3>
                    <?php if ($hizo_contacto): ?>
                        <span class="ml-2 inline-flex items-center gap-1 text-[9px] bg-emerald-500 text-white px-2.5 py-1 rounded-full font-black uppercase tracking-widest">
                            <i class="fa-solid fa-check"></i> Convirtió
                        </span>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">

                    <!-- Card 1: Origen -->
                    <div class="bg-gray-50 rounded-xl p-4">
                        <div class="flex items-center gap-1.5 mb-3">
                            <i class="<?= $ref_data['icon'] ?> text-[10px] <?= $ref_data['color_text'] ?>"></i>
                            <span class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Origen</span>
                        </div>
                        <p class="text-sm font-bold text-gray-800"><?= htmlspecialchars($ref_data['label']) ?></p>
                        <?php if (!empty($primer_referrer)): ?>
                            <p class="text-[10px] font-mono text-gray-400 mt-1 truncate" title="<?= htmlspecialchars($primer_referrer) ?>">
                                <?= htmlspecialchars(parse_url($primer_referrer, PHP_URL_HOST) ?? $primer_referrer) ?>
                            </p>
                        <?php elseif (!empty($primer_utm)): ?>
                            <p class="text-[10px] font-mono text-gray-400 mt-1 truncate">UTM: <?= htmlspecialchars($primer_utm) ?></p>
                        <?php else: ?>
                            <p class="text-[10px] text-gray-400 mt-1">Sin datos de origen</p>
                        <?php endif; ?>
                    </div>

                    <!-- Card 2: Comportamiento -->
                    <div class="bg-gray-50 rounded-xl p-4">
                        <div class="flex items-center gap-1.5 mb-3">
                            <i class="fa-solid fa-chart-line text-[10px] text-[#54A6D8]"></i>
                            <span class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Comportamiento</span>
                        </div>
                        <div class="space-y-2">
                            <div class="flex justify-between items-center">
                                <span class="text-[10px] text-gray-500">Total visitas</span>
                                <span class="text-xs font-black text-gray-900"><?= number_format($total_eventos_detalle, 0, ',', '.') ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-[10px] text-gray-500">Págs. únicas</span>
                                <span class="text-xs font-black text-gray-900"><?= number_format($urls_unicas, 0, ',', '.') ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-[10px] text-gray-500">Días activo</span>
                                <span class="text-xs font-black text-gray-900"><?= $dias_desde_primera === 0 ? 'Hoy' : $dias_desde_primera . 'd' ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Card 3: Conversión -->
                    <div class="bg-gray-50 rounded-xl p-4">
                        <div class="flex items-center gap-1.5 mb-3">
                            <i class="fa-solid fa-handshake text-[10px] text-purple-500"></i>
                            <span class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Conversión</span>
                        </div>
                        <?php if ($is_guest): ?>
                            <p class="text-[10px] text-gray-400 mt-1">No aplica para visitantes</p>
                        <?php else: ?>
                        <div class="space-y-2.5">
                            <div class="flex items-center gap-2">
                                <?php if ($hizo_contacto): ?>
                                    <span class="w-4 h-4 bg-emerald-500 text-white rounded-full flex items-center justify-center shrink-0"><i class="fa-solid fa-check text-[7px]"></i></span>
                                    <div class="min-w-0">
                                        <span class="text-[10px] font-bold text-gray-800 block">Contacto</span>
                                        <span class="text-[9px] font-mono text-gray-400"><?= date('d M, H:i', strtotime($conv['primer_contacto'])) ?></span>
                                    </div>
                                <?php else: ?>
                                    <span class="w-4 h-4 bg-gray-200 text-gray-400 rounded-full flex items-center justify-center shrink-0"><i class="fa-solid fa-minus text-[7px]"></i></span>
                                    <span class="text-[10px] text-gray-400">Sin contacto aún</span>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center gap-2">
                                <?php if ($hizo_apunte): ?>
                                    <span class="w-4 h-4 bg-[#54A6D8] text-white rounded-full flex items-center justify-center shrink-0"><i class="fa-solid fa-check text-[7px]"></i></span>
                                    <div class="min-w-0">
                                        <span class="text-[10px] font-bold text-gray-800 block">Publicó Apunte</span>
                                        <span class="text-[9px] font-mono text-gray-400"><?= date('d M, H:i', strtotime($conv['primer_apunte'])) ?></span>
                                    </div>
                                <?php else: ?>
                                    <span class="w-4 h-4 bg-gray-200 text-gray-400 rounded-full flex items-center justify-center shrink-0"><i class="fa-solid fa-minus text-[7px]"></i></span>
                                    <span class="text-[10px] text-gray-400">Sin apuntes publicados</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Card 4: Dispositivo + fechas -->
                    <div class="bg-gray-50 rounded-xl p-4">
                        <div class="flex items-center gap-1.5 mb-3">
                            <i class="<?= $dispositivo['icon'] ?> text-[10px] text-amber-500"></i>
                            <span class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Dispositivo</span>
                        </div>
                        <p class="text-sm font-bold text-gray-800 mb-3"><?= htmlspecialchars($dispositivo['label']) ?></p>
                        <?php if ($stats['min_f']): ?>
                        <div class="space-y-1.5 border-t border-gray-200 pt-2.5">
                            <div class="flex justify-between items-center">
                                <span class="text-[9px] text-gray-400">Primera visita</span>
                                <span class="text-[9px] font-mono text-gray-600"><?= date('d M Y', strtotime($stats['min_f'])) ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-[9px] text-gray-400">Última visita</span>
                                <span class="text-[9px] font-mono text-gray-600"><?= date('d M Y', strtotime($stats['max_f'])) ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
            <!-- ===== FIN BLOQUE RESUMEN ===== -->

            <form method="POST" id="form-acciones">
                <input type="hidden" name="accion_global" value="eliminar">
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden animate-fade-in-up" style="animation-delay: 0.2s;">
                    <div class="px-6 py-4 border-b border-gray-50 flex justify-between items-center bg-white">
                        <h3 class="font-bold text-gray-900 text-sm flex items-center gap-2"><i class="fa-solid fa-list-ul text-[#54A6D8]"></i> Línea de Tiempo Detallada</h3>
                        <button type="submit" class="text-red-500 hover:text-red-700 text-[10px] font-black uppercase tracking-widest disabled:opacity-30 flex items-center gap-1.5 transition-opacity" id="btn-eliminar" disabled>
                            <i class="fa-solid fa-trash-can"></i> Eliminar Selección
                        </button>
                    </div>
                    <div class="max-h-[600px] overflow-y-auto custom-scrollbar">
                        <table class="w-full text-left border-collapse" id="tabla-registros">
                            <thead class="bg-gray-50 sticky top-0 z-10 text-[10px] font-bold text-gray-400 uppercase tracking-widest border-b border-gray-100 backdrop-blur-md">
                                <tr>
                                    <th class="px-5 py-3.5 w-12"><input type="checkbox" id="select-all" class="rounded border-gray-300 text-[#54A6D8] focus:ring-0"></th>
                                    <th class="px-4 py-3.5">Evento</th>
                                    <th class="px-4 py-3.5">Descripción</th>
                                    <th class="px-4 py-3.5">Ubicación de Red</th>
                                    <th class="px-5 py-3.5 text-right">Timestamp</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 text-sm">
                                <?php if($historial): while ($h = $historial->fetch_assoc()): 
                                    $style = getBadge($h['accion']); 
                                ?>
                                <tr class="hover:bg-gray-50/50 transition-colors group align-middle row-historial <?= $h['es_bot'] ? 'bg-purple-50/30' : '' ?>" data-ip="<?= htmlspecialchars($h['ip_usuario']) ?>">
                                    <td class="px-5 py-3"><input type="checkbox" name="ids[]" value="<?= $h['id'] ?>" class="item-check rounded border-gray-300 text-[#54A6D8] focus:ring-0"></td>
                                    
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md text-[9px] font-black <?= $style['bg'] ?> <?= $style['txt'] ?> uppercase tracking-widest">
                                            <i class="fa-solid <?= $style['icon'] ?>"></i> <?= htmlspecialchars($h['accion']) ?>
                                        </span>
                                        <?php if($h['es_bot']): ?>
                                            <span class="inline-flex items-center gap-1 ml-1 px-1.5 py-0.5 rounded text-[8px] font-black bg-purple-50 text-purple-600 uppercase tracking-widest">
                                                <i class="fa-solid fa-robot"></i> Bot
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="px-4 py-3">
                                        <p class="text-gray-700 text-xs font-medium max-w-xs xl:max-w-md truncate" title="<?= htmlspecialchars($h['detalle'] ?? '-') ?>"><?= htmlspecialchars($h['detalle'] ?? '-') ?></p>
                                        <?php if (!empty($h['url'])): ?>
                                            <a href="<?= htmlspecialchars($h['url']) ?>" target="_blank" class="text-[10px] font-mono text-[#54A6D8] hover:underline truncate block mt-0.5"><i class="fa-solid fa-link"></i> <?= htmlspecialchars($h['url']) ?></a>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="px-4 py-3">
                                        <div class="text-[11px] text-gray-500 font-medium loc-text flex items-center truncate">
                                            <span class="animate-pulse bg-gray-200 h-3 w-16 rounded inline-block"></span>
                                        </div>
                                        <div class="text-[9px] font-mono text-gray-400 mt-0.5"><?= htmlspecialchars($h['ip_usuario']) ?></div>
                                    </td>

                                    <td class="px-5 py-3 text-right whitespace-nowrap">
                                        <span class="text-[11px] font-mono font-medium text-gray-600 block"><?= date('H:i:s', strtotime($h['fecha'])) ?></span>
                                        <span class="text-[9px] font-bold text-gray-400 uppercase tracking-widest"><?= date('d M, y', strtotime($h['fecha'])) ?></span>
                                    </td>
                                </tr>
                                <?php endwhile; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>
        </div>

    <?php elseif ($tab_activa === 'bots'): ?>
        <!-- ===== TAB BOTS / CRAWLERS ===== -->
        <section class="animate-fade-in-up">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Eventos de Bots (30d)</p>
                    <p class="text-2xl font-black text-gray-900 tracking-tight"><?= number_format($stats_bots['total_eventos'], 0, ',', '.') ?></p>
                </div>
                <div class="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">IPs Únicas</p>
                    <p class="text-2xl font-black text-gray-900 tracking-tight"><?= number_format($stats_bots['ips_unicas'], 0, ',', '.') ?></p>
                </div>
                <div class="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Bots Distintos</p>
                    <p class="text-2xl font-black text-gray-900 tracking-tight"><?= number_format($stats_bots['bots_unicos'], 0, ',', '.') ?></p>
                </div>
            </div>

            <div class="flex items-center justify-between mb-4 px-2">
                <div>
                    <h2 class="text-lg font-extrabold text-gray-900 tracking-tight">Bots y Crawlers Activos</h2>
                    <p class="text-xs text-gray-500 font-medium">Detectados por User-Agent · Últimos 30 días</p>
                </div>
                <form method="POST" onsubmit="return confirm('¿Eliminar registros de bots de más de 30 días? Esta acción es irreversible.')">
                    <input type="hidden" name="accion_global" value="purgar_bots">
                    <button type="submit" class="text-purple-600 hover:text-purple-800 text-[10px] font-black uppercase tracking-widest flex items-center gap-1.5 px-3 py-2 rounded-xl bg-purple-50 border border-purple-100 hover:bg-purple-100 transition-colors">
                        <i class="fa-solid fa-broom"></i> Purgar Bots Antiguos
                    </button>
                </form>
            </div>

            <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
                <div class="overflow-x-auto custom-scrollbar max-h-[600px]">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 text-[10px] uppercase text-gray-400 font-bold tracking-widest border-b border-gray-100 sticky top-0 z-10 backdrop-blur-md">
                            <tr>
                                <th class="px-6 py-4">Bot / Crawler</th>
                                <th class="px-6 py-4">IP</th>
                                <th class="px-6 py-4 text-center">Hits</th>
                                <th class="px-6 py-4 text-center">URLs</th>
                                <th class="px-6 py-4 text-right">Última visita</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php if (isset($res_bots) && $res_bots && $res_bots->num_rows > 0): ?>
                                <?php while ($b = $res_bots->fetch_assoc()): ?>
                                    <tr class="hover:bg-purple-50/30 transition-colors align-middle">
                                        <td class="px-6 py-4">
                                            <p class="font-bold text-gray-800 text-sm"><?= htmlspecialchars(nombreBotCorto($b['user_agent'])) ?></p>
                                            <p class="text-[10px] font-mono text-gray-400 mt-0.5 truncate max-w-md" title="<?= htmlspecialchars($b['user_agent']) ?>">
                                                <?= htmlspecialchars(mb_strimwidth($b['user_agent'] ?? '', 0, 80, '...')) ?>
                                            </p>
                                        </td>
                                        <td class="px-6 py-4">
                                            <a href="?uid=0&ip=<?= urlencode($b['ip_usuario']) ?>" class="font-mono text-xs text-[#54A6D8] hover:underline"><?= htmlspecialchars($b['ip_usuario']) ?></a>
                                        </td>
                                        <td class="px-6 py-4 text-center">
                                            <span class="inline-flex items-center justify-center bg-purple-50 text-purple-700 font-black text-xs px-2.5 py-1 rounded-full border border-purple-100">
                                                <?= number_format($b['total_hits'], 0, ',', '.') ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-center text-xs font-bold text-gray-600">
                                            <?= number_format($b['urls_unicas'], 0, ',', '.') ?>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <span class="text-[11px] font-mono font-medium text-gray-500 block"><?= date('H:i', strtotime($b['ultima_visita'])) ?></span>
                                            <span class="text-[9px] font-bold text-gray-400 uppercase tracking-widest"><?= date('d M, Y', strtotime($b['ultima_visita'])) ?></span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-16 text-center bg-white">
                                        <i class="fa-solid fa-shield-check text-3xl mb-3 text-emerald-400 block"></i>
                                        <p class="font-bold text-gray-600 text-sm">No hay actividad de bots en los últimos 30 días.</p>
                                        <p class="text-xs mt-1 text-gray-400 font-medium">Los nuevos bots se detectarán automáticamente por User-Agent.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

    <?php elseif ($tab_activa === 'paginas'): ?>
        <!-- ===== TAB TOP PÁGINAS ===== -->
        <section class="animate-fade-in-up">
            <div class="flex items-center justify-between mb-4 px-2">
                <div>
                    <h2 class="text-lg font-extrabold text-gray-900 tracking-tight">Top Páginas</h2>
                    <p class="text-xs text-gray-500 font-medium">Últimos 14 días · Tráfico real (sin bots) · Top 50</p>
                </div>
                <span class="bg-sky-50 text-[#54A6D8] text-[9px] font-black px-2.5 py-1.5 rounded-lg uppercase tracking-widest border border-sky-100">
                    <?= number_format($total_hits_paginas, 0, ',', '.') ?> eventos totales
                </span>
            </div>

            <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
                <div class="overflow-x-auto custom-scrollbar max-h-[600px]">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 text-[10px] uppercase text-gray-400 font-bold tracking-widest border-b border-gray-100 sticky top-0 z-10 backdrop-blur-md">
                            <tr>
                                <th scope="col" class="px-6 py-4">URL</th>
                                <th scope="col" class="px-6 py-4 text-center">Hits</th>
                                <th scope="col" class="px-6 py-4 text-center">Visitantes únicos</th>
                                <th scope="col" class="px-6 py-4 text-right">% del total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php if (isset($res_paginas) && $res_paginas && $res_paginas->num_rows > 0): ?>
                                <?php while ($row = $res_paginas->fetch_assoc()):
                                    $pct = $total_hits_paginas > 0 ? round($row['hits'] / $total_hits_paginas * 100, 1) : 0;
                                    $url_display = htmlspecialchars($row['url']);
                                    $url_corta = mb_strimwidth($row['url'], 0, 60, '…');
                                ?>
                                <tr class="hover:bg-sky-50/30 transition-colors group align-middle">
                                    <td class="px-6 py-4 max-w-xs xl:max-w-lg">
                                        <a href="<?= $url_display ?>" target="_blank" rel="noopener noreferrer"
                                           class="font-mono text-xs text-[#54A6D8] hover:underline truncate block"
                                           title="<?= $url_display ?>">
                                            <?= htmlspecialchars($url_corta) ?>
                                        </a>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="inline-flex items-center justify-center bg-sky-50 text-[#54A6D8] font-black text-xs px-2.5 py-1 rounded-full border border-sky-100">
                                            <?= number_format($row['hits'], 0, ',', '.') ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-center text-xs font-bold text-gray-600">
                                        <?= number_format($row['uniques'], 0, ',', '.') ?>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <div class="w-16 bg-gray-100 rounded-full h-1.5 overflow-hidden">
                                                <div class="bg-[#54A6D8] h-1.5 rounded-full" style="width: <?= min($pct * 2, 100) ?>%"></div>
                                            </div>
                                            <span class="text-xs font-bold text-gray-700 w-10 text-right"><?= $pct ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="px-6 py-16 text-center bg-white">
                                        <i class="fa-solid fa-chart-bar text-3xl mb-3 text-gray-300 block"></i>
                                        <p class="font-bold text-gray-600 text-sm">Sin datos de páginas en los últimos 14 días.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

    <?php elseif ($tab_activa === 'fallidas'): ?>
        <!-- ===== TAB BÚSQUEDAS FALLIDAS ===== -->
        <section class="animate-fade-in-up">
            <div class="flex items-center justify-between mb-4 px-2">
                <div>
                    <h2 class="text-lg font-extrabold text-gray-900 tracking-tight">Oportunidades de Contenido</h2>
                    <p class="text-xs text-gray-500 font-medium">Búsquedas sin resultados (Zero-Results)</p>
                </div>
                <span class="bg-orange-50 text-orange-600 text-[9px] font-black px-2.5 py-1.5 rounded-lg uppercase tracking-widest border border-orange-100">
                    Alta Demanda
                </span>
            </div>

            <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
                <div class="overflow-x-auto custom-scrollbar max-h-[600px]">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 text-[10px] uppercase text-gray-400 font-bold tracking-widest border-b border-gray-100 sticky top-0 z-10 backdrop-blur-md">
                            <tr>
                                <th scope="col" class="px-6 py-4">Término Buscado</th>
                                <th scope="col" class="px-6 py-4 text-center">Intentos</th>
                                <th scope="col" class="px-6 py-4 text-right">Última Búsqueda</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php if (isset($res_demandas) && $res_demandas && $res_demandas->num_rows > 0): ?>
                                <?php while ($row = $res_demandas->fetch_assoc()): ?>
                                    <tr class="hover:bg-orange-50/30 transition-colors group align-middle">
                                        <td class="px-6 py-4 font-bold text-gray-800 text-sm">
                                            <i class="fa-solid fa-magnifying-glass text-[#54A6D8] mr-2 text-xs"></i>
                                            <?= htmlspecialchars(ucfirst(strtolower($row['termino']))) ?>
                                        </td>
                                        <td class="px-6 py-4 text-center">
                                            <span class="inline-flex items-center justify-center bg-gray-50 text-gray-700 font-black text-xs w-8 h-8 rounded-full border border-gray-200">
                                                <?= (int)$row['total_intentos'] ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <span class="text-[11px] font-mono font-medium text-gray-500 block"><?= date('H:i', strtotime($row['ultima_busqueda'])) ?></span>
                                            <span class="text-[9px] font-bold text-gray-400 uppercase tracking-widest"><?= date('d M, Y', strtotime($row['ultima_busqueda'])) ?></span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="px-6 py-16 text-center bg-white">
                                        <i class="fa-regular fa-face-smile text-3xl mb-3 text-gray-300 block"></i>
                                        <p class="font-bold text-gray-600 text-sm">Todo el contenido está cubierto.</p>
                                        <p class="text-xs mt-1 text-gray-400 font-medium">No hay búsquedas fallidas registradas.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

    <?php else: ?>
        <!-- ===== TAB TRÁFICO REAL ===== -->
        <div class="flex items-center gap-3 overflow-x-auto custom-scrollbar pb-4 mb-2 animate-fade-in-up" id="filtros-monitor">
            <button data-filter="todos" class="filter-btn bg-gray-900 text-white border-gray-900 border rounded-full px-4 py-1.5 text-xs font-bold whitespace-nowrap transition-transform hover:scale-[1.02]">Todos</button>
            <button data-filter="alumnos" class="filter-btn bg-white text-gray-600 border-gray-200 border hover:border-gray-300 rounded-full px-4 py-1.5 text-xs font-bold whitespace-nowrap transition-all shadow-sm hover:shadow-md">🙋‍♂️ Alumnos</button>
            <button data-filter="invitados" class="filter-btn bg-white text-gray-600 border-gray-200 border hover:border-gray-300 rounded-full px-4 py-1.5 text-xs font-bold whitespace-nowrap transition-all shadow-sm hover:shadow-md">🕵️ Invitados</button>
            <button data-filter="online" class="filter-btn bg-emerald-50 text-emerald-700 border-emerald-200 border hover:border-emerald-300 rounded-full px-4 py-1.5 text-xs font-bold whitespace-nowrap transition-all shadow-sm hover:shadow-md flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> Online Ahora
            </button>
            <?php if (isset($contadores)): ?>
            <div class="ml-auto text-[10px] font-bold text-gray-400 uppercase tracking-widest whitespace-nowrap pr-2">
                <i class="fa-solid fa-chart-simple"></i>
                <?= number_format($contadores['alumnos'], 0, ',', '.') ?> alumnos · 
                <?= number_format($contadores['invitados'], 0, ',', '.') ?> invitados · 
                <span class="text-purple-500"><?= number_format($contadores['bots'], 0, ',', '.') ?> bots</span>
            </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5 animate-fade-in-up" id="grid-monitor">
            <?php if (isset($lista_usuarios) && $lista_usuarios && $lista_usuarios->num_rows > 0): ?>
                <?php while ($u = $lista_usuarios->fetch_assoc()): 
                    $es_invitado = ($u['usuario_id'] == 0);
                    $diff = time() - strtotime($u['ultima_actividad']);
                    $online = ($diff < 300);
                    $ip_str = htmlspecialchars($u['ip_usuario'] ?? '0.0.0.0');
                    $guest_id = 'GST-' . strtoupper(substr(md5($ip_str), 0, 5));
                    $url_detalle = $es_invitado ? "?uid=0&ip=".urlencode($ip_str) : "?uid={$u['usuario_id']}";
                    
                    $last_url = $u['ultima_url'] ?? '/';
                    $url_corta = parse_url($last_url, PHP_URL_PATH) ?? '/';
                    if (strlen($url_corta) > 25) $url_corta = substr($url_corta, 0, 22) . '...';

                    $card_bg = 'border-gray-100 hover:border-[#54A6D8] bg-white';
                    if ($es_invitado) {
                        $card_bg = 'border-gray-200 bg-gray-50/50';
                    }

                    $tipo_usuario = $es_invitado ? 'invitado' : 'alumno';
                    $is_online = $online ? 'true' : 'false';
                    $eventos = (int)$u['total_acciones'];
                    
                    $badge_eventos = 'text-gray-800';
                    if ($es_invitado) {
                        if ($eventos >= 50) $badge_eventos = 'text-red-600 bg-red-50 border border-red-100 px-2 py-0.5 rounded-md';
                        elseif ($eventos >= 10) $badge_eventos = 'text-amber-600 bg-amber-50 border border-amber-100 px-2 py-0.5 rounded-md';
                        else $badge_eventos = 'text-gray-500';
                    }
                ?>
               <?php
                    $tiene_perfil_real = ($u['usuario_id'] > 0 && !empty($u['nombre']));
                    $url_perfil = $tiene_perfil_real ? '/perfil/' . nubira_encriptar_id($u['usuario_id']) : '';
               ?>
               <div onclick="location.href='<?= $url_detalle ?>'"
                   data-ip="<?= $ip_str ?>"
                   data-tipo="<?= $tipo_usuario ?>"
                   data-online="<?= $is_online ?>"
                   data-region="pendiente"
                   class="monitor-card block rounded-2xl p-5 border <?= $card_bg ?> transition-all hover:shadow-md shadow-sm hover:scale-[1.01] relative flex flex-col h-full group bg-white cursor-pointer">
                    <div class="flex items-start gap-4 mb-4">
                        <?php if ($tiene_perfil_real): ?>
                        <a href="<?= $url_perfil ?>" target="_blank" onclick="event.stopPropagation()" class="w-12 h-12 rounded-xl <?= $es_invitado ? 'bg-gray-200 text-gray-500' : 'bg-gray-100 text-gray-400' ?> relative flex items-center justify-center border border-transparent group-hover:border-[#54A6D8] transition-colors shrink-0 mt-1 avatar-container">
                        <?php else: ?>
                        <div class="w-12 h-12 rounded-xl <?= $es_invitado ? 'bg-gray-200 text-gray-500' : 'bg-gray-100 text-gray-400' ?> relative flex items-center justify-center border border-transparent group-hover:border-[#54A6D8] transition-colors shrink-0 mt-1 avatar-container">
                        <?php endif; ?>
                            <?php if (!empty($u['foto_perfil']) && !$es_invitado): ?>
                                <img src="/app/perfil/fotos/<?= htmlspecialchars($u['foto_perfil']) ?>" class="w-full h-full object-cover rounded-xl">
                            <?php elseif ($es_invitado): ?>
                                <i class="fa-solid fa-mask text-xl icon-avatar"></i>
                            <?php else: ?>
                                <div class="font-bold text-lg"><?= strtoupper(substr($u['nombre']??'U',0,1)) ?></div>
                            <?php endif; ?>
                            <?php if($online): ?><span class="absolute -bottom-1 -right-1 w-3.5 h-3.5 bg-emerald-500 border-2 border-white rounded-full animate-pulse z-10"></span><?php endif; ?>
                        <?= $tiene_perfil_real ? '</a>' : '</div>' ?>
                        <div class="min-w-0 flex-1">
                            <?php if ($tiene_perfil_real): ?>
                            <a href="<?= $url_perfil ?>" target="_blank" onclick="event.stopPropagation()" class="font-extrabold text-gray-900 group-hover:text-[#54A6D8] hover:underline transition-colors truncate text-sm title-text block"><?= htmlspecialchars($u['nombre']) ?></a>
                            <?php else: ?>
                            <h3 class="font-extrabold <?= $es_invitado ? 'text-gray-600' : 'text-gray-900 group-hover:text-[#54A6D8]' ?> transition-colors truncate text-sm title-text"><?= $es_invitado ? $guest_id : htmlspecialchars($u['nombre']) ?></h3>
                            <?php endif; ?>
                            
                            <p class="text-[10px] text-gray-500 font-medium truncate mt-0.5 flex items-center loc-text" title="<?= $ip_str ?>">
                                <span class="animate-pulse bg-gray-200 h-2.5 w-20 rounded inline-block"></span>
                            </p>
                            
                            <div class="mt-3 flex items-center justify-between border-t border-gray-50 pt-2">
                                <div class="flex items-center gap-1.5 text-[10px] w-full">
                                    <span class="font-bold text-gray-500 uppercase tracking-widest truncate max-w-[40%]" title="<?= htmlspecialchars($u['ultima_accion_txt'] ?? 'N/A') ?>">
                                        <?= htmlspecialchars(str_replace('_', ' ', $u['ultima_accion_txt'] ?? 'N/A')) ?>
                                    </span>
                                    <span class="text-gray-300">•</span>
                                    <span class="font-mono text-[#54A6D8] truncate flex-1" title="<?= htmlspecialchars($last_url) ?>">
                                        <?= htmlspecialchars($url_corta) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-auto flex justify-between items-end pt-3 border-t border-gray-50">
                        <div>
                            <span class="text-[9px] text-gray-400 uppercase font-bold tracking-widest block mb-1">Últ. Conexión</span>
                            <span class="text-[11px] text-gray-600 font-mono font-medium"><?= date('H:i, d M', strtotime($u['ultima_actividad'])) ?></span>
                        </div>
                        <div class="text-right">
                            <span class="text-[10px] text-gray-400 uppercase font-bold tracking-widest block mb-0.5">Eventos</span>
                            <span class="text-lg font-black leading-none inline-block <?= $badge_eventos ?>"><?= $eventos ?></span>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-span-full text-center py-16 text-gray-400">
                    <i class="fa-solid fa-users-slash text-3xl mb-3 block"></i>
                    <p class="text-sm font-bold">Sin tráfico real en los últimos 14 días.</p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

  </div>
</main>

<div id="geo-tooltip" 
     class="fixed hidden z-50 w-72 bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden pointer-events-none transition-opacity duration-200 opacity-0">
    <div class="px-4 pt-4 pb-3 flex items-center gap-3 border-b border-gray-50">
        <img id="geo-tooltip-flag" src="" alt="" class="w-8 h-6 rounded shadow-sm flex-shrink-0 hidden">
        <div class="min-w-0 flex-1">
            <p id="geo-tooltip-ciudad" class="text-sm font-extrabold text-gray-900 truncate tracking-tight">—</p>
            <p id="geo-tooltip-pais" class="text-[11px] text-gray-500 font-medium truncate">—</p>
        </div>
    </div>
    <div id="geo-tooltip-map" class="w-full h-32 bg-gray-50 flex items-center justify-center">
        <i class="fa-solid fa-map-location-dot text-gray-300 text-2xl animate-pulse"></i>
    </div>
    <div class="px-4 py-3 bg-gray-50/50 border-t border-gray-50 space-y-1.5">
        <div class="flex items-center gap-2">
            <i class="fa-solid fa-signal text-[#54A6D8] text-[10px] w-3"></i>
            <span id="geo-tooltip-isp" class="text-[11px] text-gray-700 font-semibold truncate flex-1">—</span>
        </div>
        <div class="flex items-center gap-2">
            <i class="fa-solid fa-fingerprint text-gray-400 text-[10px] w-3"></i>
            <span id="geo-tooltip-ip" class="text-[10px] text-gray-500 font-mono truncate flex-1">—</span>
        </div>
    </div>
</div>

<?php 
$nav_bottom_path = $_SERVER['DOCUMENT_ROOT'] . '/app/componentes/nav_bottom.php';
$modal_pub_path = $_SERVER['DOCUMENT_ROOT'] . '/app/componentes/modal_publicar.php';
$modal_exp_path = $_SERVER['DOCUMENT_ROOT'] . '/app/componentes/modal_explora.php';

if (file_exists($nav_bottom_path)) require_once $nav_bottom_path; 
if (file_exists($modal_pub_path)) require_once $modal_pub_path; 
if (file_exists($modal_exp_path)) require_once $modal_exp_path; 
?>

<script>
    const NubiraModales = {
        setup(triggerId, modalId, cardId, closeId) {
            const btn = document.getElementById(triggerId);
            const modal = document.getElementById(modalId);
            const card = document.getElementById(cardId);
            const close = document.getElementById(closeId);
            if(!btn || !modal) return;
            
            const open = () => { 
                modal.classList.remove('hidden'); 
                requestAnimationFrame(() => { card.classList.remove('translate-y-full', 'opacity-0'); card.classList.add('translate-y-0', 'opacity-100'); });
                document.body.style.overflow = 'hidden'; 
            };
            const shut = () => { 
                card.classList.remove('translate-y-0', 'opacity-100');
                card.classList.add('translate-y-full', 'opacity-0'); 
                setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); 
            };
            
            btn.onclick = (e) => { e.preventDefault(); open(); }; 
            if(close) close.onclick = shut; 
            modal.onclick = (e) => { if(e.target === modal) shut(); };
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        NubiraModales.setup('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
        NubiraModales.setup('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
    });

    const toggle = document.getElementById('live-toggle');
    let timer = null; let isHovering = false;

    if (localStorage.getItem('admin_live_mode') === 'true') {
        if(toggle) toggle.checked = true; startLive();
    }
    if(toggle) {
        toggle.addEventListener('change', (e) => {
            localStorage.setItem('admin_live_mode', e.target.checked);
            e.target.checked ? startLive() : stopLive();
        });
    }

    function startLive() {
        if(timer) clearInterval(timer);
        timer = setInterval(() => {
            const checks = document.querySelectorAll('.item-check:checked');
            if(checks.length === 0 && !isHovering) window.location.reload();
        }, 30000);
    }
    function stopLive() { if(timer) clearInterval(timer); }

    const selectAll = document.getElementById('select-all');
    const items = document.querySelectorAll('.item-check');
    const btnDel = document.getElementById('btn-eliminar');

    if (selectAll) {
        selectAll.addEventListener('change', (e) => {
            items.forEach(c => c.checked = e.target.checked);
            if(btnDel) btnDel.disabled = document.querySelectorAll('.item-check:checked').length === 0;
        });
        items.forEach(c => c.addEventListener('change', () => {
            if(btnDel) btnDel.disabled = document.querySelectorAll('.item-check:checked').length === 0;
        }));
    }

    document.addEventListener('DOMContentLoaded', () => {
        const filterBtns = document.querySelectorAll('.filter-btn');
        if (!filterBtns.length) return;

        filterBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                filterBtns.forEach(b => {
                    b.classList.remove('bg-gray-900', 'text-white', 'border-gray-900', 'shadow-none');
                    b.classList.add('bg-white', 'text-gray-600', 'border-gray-200');
                    if(b.dataset.filter === 'online') {
                        b.classList.add('bg-emerald-50', 'text-emerald-700', 'border-emerald-200');
                        b.classList.remove('bg-white', 'text-gray-600', 'border-gray-200');
                    }
                });

                const target = e.currentTarget;
                if(target.dataset.filter === 'online') {
                    target.classList.remove('bg-emerald-50', 'text-emerald-700', 'border-emerald-200');
                } else {
                    target.classList.remove('bg-white', 'text-gray-600', 'border-gray-200');
                }
                target.classList.add('bg-gray-900', 'text-white', 'border-gray-900', 'shadow-none');

                const filter = target.dataset.filter;
                const monitorCards = document.querySelectorAll('.monitor-card'); 
                
                monitorCards.forEach(card => {
                    card.style.transition = 'opacity 0.2s ease';
                    card.style.opacity = '0';
                    
                    setTimeout(() => {
                        let show = false;
                        if (filter === 'todos') show = true;
                        else if (filter === 'alumnos') show = card.dataset.tipo === 'alumno';
                        else if (filter === 'invitados') show = card.dataset.tipo === 'invitado';
                        else if (filter === 'online') show = card.dataset.online === 'true';

                        card.style.display = show ? 'flex' : 'none';
                        if (show) setTimeout(() => card.style.opacity = '1', 50);
                    }, 200);
                });
            });
        });
    });

    // Motor de geolocalización + tooltip (sin cambios respecto al original)
    document.addEventListener('DOMContentLoaded', async () => {
        const cards = document.querySelectorAll('.monitor-card[data-ip], .detail-card[data-ip], .row-historial[data-ip]');
        if (!cards.length) return;

        const ipsLocales = ['0.0.0.0', '::1', '127.0.0.1'];
        const todasLasIps = [...new Set(Array.from(cards).map(c => c.dataset.ip))].filter(ip => ip && !ipsLocales.includes(ip));

        if (todasLasIps.length === 0) {
            cards.forEach(card => {
                const el = card.querySelector('.loc-text');
                if (el) el.innerHTML = '<i class="fa-solid fa-server text-gray-400 mr-1"></i> Localhost / Red Interna';
            });
            return;
        }

        const renderUbicacion = (card, info) => {
            if (!info) return;
            const esMonitorCard = card.classList.contains('monitor-card');
            const esDetailCard  = card.classList.contains('detail-card');
            const esFila        = card.classList.contains('row-historial');

            const bandera = info.pais_codigo
                ? `<img src="https://flagcdn.com/16x12/${info.pais_codigo.toLowerCase()}.png" class="inline-block mr-1.5 rounded-[2px] shadow-sm" alt="${info.pais_codigo}" loading="lazy">`
                : '<i class="fa-solid fa-location-dot text-[#54A6D8] mr-1"></i>';

            const ciudad = info.ciudad || 'Ubicación desconocida';
            const pais   = info.pais   || '';

            if (info.lat && info.lon) {
                card.dataset.lat    = info.lat;
                card.dataset.lon    = info.lon;
                card.dataset.ciudad = ciudad;
                card.dataset.pais   = pais;
                card.dataset.isp    = info.isp || '';
            }

            if (esMonitorCard || esFila) {
                const locEl = card.querySelector('.loc-text');
                if (locEl) locEl.innerHTML = `${bandera} <span class="truncate">${ciudad}, ${esFila ? (info.pais_codigo || '') : pais}</span>`;
            } else if (esDetailCard) {
                const locEl = card.querySelector('.loc-text');
                if (locEl) locEl.innerHTML = `${bandera} <span>${ciudad}, ${pais}</span>`;

                const mapEl = card.querySelector('.map-container');
                if (mapEl && info.lat && info.lon) {
                    mapEl.innerHTML = `<iframe width="100%" height="100%" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src="https://maps.google.com/maps?q=${info.lat},${info.lon}&hl=es&z=12&output=embed" class="pointer-events-none opacity-90" loading="lazy"></iframe>`;
                    mapEl.classList.remove('animate-pulse');
                }
            }
        };

        try {
            const respuesta = await fetch('/app/api/geolocalizar_ip.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ips: todasLasIps })
            });
            if (!respuesta.ok) throw new Error(`HTTP ${respuesta.status}`);
            const json = await respuesta.json();
            if (!json.ok || !json.data) throw new Error('Respuesta inválida del endpoint');

            cards.forEach(card => {
                const ip = card.dataset.ip;
                const info = json.data[ip];
                if (info) renderUbicacion(card, info);
                else {
                    const el = card.querySelector('.loc-text');
                    if (el) el.innerHTML = '<i class="fa-solid fa-question-circle text-gray-300 mr-1"></i> <span class="text-gray-400">Sin datos</span>';
                }
            });
            inicializarTooltipGeo();
        } catch (err) {
            cards.forEach(card => {
                const el = card.querySelector('.loc-text');
                if (el) el.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-amber-500 mr-1"></i> <span class="text-gray-400">Error de geolocalización</span>';
            });
        }
    });

    function inicializarTooltipGeo() {
        const tooltip = document.getElementById('geo-tooltip');
        if (!tooltip || tooltip.dataset.inicializado === '1') return;
        tooltip.dataset.inicializado = '1';
        
        const flagEl = document.getElementById('geo-tooltip-flag');
        const ciudadEl = document.getElementById('geo-tooltip-ciudad');
        const paisEl = document.getElementById('geo-tooltip-pais');
        const mapEl = document.getElementById('geo-tooltip-map');
        const ispEl = document.getElementById('geo-tooltip-isp');
        const ipEl = document.getElementById('geo-tooltip-ip');
        
        let hideTimer = null;
        let currentIp = null;

        const posicionar = (evento) => {
            const margen = 16, tooltipAncho = 288, tooltipAlto = 240;
            let x = evento.clientX + margen, y = evento.clientY + margen;
            if (x + tooltipAncho > window.innerWidth - margen) x = evento.clientX - tooltipAncho - margen;
            if (y + tooltipAlto > window.innerHeight - margen) y = evento.clientY - tooltipAlto - margen;
            tooltip.style.left = `${Math.max(margen, x)}px`;
            tooltip.style.top = `${Math.max(margen, y)}px`;
        };

        const mostrar = (card, evento) => {
            const lat = card.dataset.lat, lon = card.dataset.lon;
            if (!lat || !lon) return;
            if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
            ciudadEl.textContent = card.dataset.ciudad || 'Ubicación desconocida';
            paisEl.textContent = card.dataset.pais || '—';
            ispEl.textContent = card.dataset.isp || 'ISP no identificado';
            ipEl.textContent = card.dataset.ip || '—';
            const codigo = card.querySelector('.loc-text img')?.alt || '';
            if (codigo) {
                flagEl.src = `https://flagcdn.com/32x24/${codigo.toLowerCase()}.png`;
                flagEl.classList.remove('hidden');
            } else flagEl.classList.add('hidden');
            if (currentIp !== card.dataset.ip) {
                currentIp = card.dataset.ip;
                mapEl.innerHTML = `<iframe width="100%" height="100%" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src="https://maps.google.com/maps?q=${lat},${lon}&hl=es&z=11&output=embed" class="pointer-events-none opacity-90" loading="lazy"></iframe>`;
            }
            posicionar(evento);
            tooltip.classList.remove('hidden');
            requestAnimationFrame(() => { tooltip.classList.remove('opacity-0'); tooltip.classList.add('opacity-100'); });
        };

        const ocultar = () => {
            tooltip.classList.remove('opacity-100');
            tooltip.classList.add('opacity-0');
            hideTimer = setTimeout(() => tooltip.classList.add('hidden'), 200);
        };

        if ('ontouchstart' in window) return;
        document.querySelectorAll('.monitor-card[data-ip]').forEach(card => {
            card.addEventListener('mouseenter', (e) => mostrar(card, e));
            card.addEventListener('mousemove', posicionar);
            card.addEventListener('mouseleave', ocultar);
        });
    }
</script>
</body>
</html>
