<?php
// === MODO DEBUG (Desactivar en producción) ===
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * VISTA: MONITOR DE ACTIVIDAD AVANZADO (CRUD + EXPORT + LIVE + RANKING)
 * ESTADO: Nubira 2.0 (App Nativa, Flat Design, Mapas API-First, Sentencias Preparadas)
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// 1. SEGURIDAD & RUTAS BLINDADAS
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

// Verificación estricta Nubira 2.0
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') { 
    header("Location: /login"); 
    exit; 
}

if (!isset($conn) || $conn->connect_error) {
    die("Error Crítico [Base de Datos]: No se pudo establecer conexión.");
}

// =============================================================================
// LÓGICA DE PROCESAMIENTO (POST/GET ACCIONES) - SEGURIDAD CON PREPARED STATEMENTS
// =============================================================================

if (isset($_POST['accion_global']) && $_POST['accion_global'] === 'eliminar' && !empty($_POST['ids'])) {
    $ids = array_map('intval', $_POST['ids']);
    $stmt = $conn->prepare("DELETE FROM historial_actividad WHERE id = ?");
    foreach ($ids as $id) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
    $stmt->close();
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

if (isset($_GET['exportar'])) {
    $uid_export = isset($_GET['uid']) ? (int)$_GET['uid'] : null;
    $fecha_export = isset($_GET['fecha']) ? $_GET['fecha'] : null;
    
    $filename = "nubira_actividad_" . date('Y-m-d_H-i') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Usuario ID', 'Nombre', 'Accion', 'Detalle', 'URL', 'IP', 'Fecha']);

    $sql_ex = "SELECT h.*, a.nombre FROM historial_actividad h LEFT JOIN alumnos a ON h.usuario_id = a.id WHERE 1=1";
    $params = [];
    $types = "";

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
                $row['accion'], $row['detalle'], $row['url'], $row['ip_usuario'], $row['fecha']
            ]);
        }
    }
    fclose($output);
    exit;
}

// 2. SISTEMA DE PESTAÑAS Y RUTAS
$tab_activa = $_GET['tab'] ?? 'trafico'; 
$ver_usuario_id = isset($_GET['uid']) ? (int)$_GET['uid'] : null;

// =============================================================================
// LÓGICA DE DATOS SEGÚN VISTA (Sentencias Preparadas)
// =============================================================================

if ($ver_usuario_id !== null) {
    // === VISTA A: DETALLE DE UN USUARIO ===
    $filtro_ip = isset($_GET['ip']) ? $_GET['ip'] : null;
    $orden = $_GET['ord'] ?? 'desc';
    $col_orden = $_GET['col'] ?? 'id';
    $valid_cols = ['fecha', 'accion', 'id'];
    if (!in_array($col_orden, $valid_cols)) $col_orden = 'id';
    $sql_sort = " ORDER BY $col_orden " . ($orden === 'asc' ? 'ASC' : 'DESC');

    $is_guest = ($ver_usuario_id === 0);

    if ($is_guest && $filtro_ip) {
        $stmt_stats = $conn->prepare("SELECT COUNT(id) as total_acciones, MAX(fecha) as max_f, MIN(fecha) as min_f, COUNT(DISTINCT ip_usuario) as total_ips FROM historial_actividad WHERE (usuario_id IS NULL OR usuario_id = 0) AND ip_usuario = ?");
        $stmt_stats->bind_param("s", $filtro_ip);
    } else {
        $stmt_stats = $conn->prepare("SELECT COUNT(id) as total_acciones, MAX(fecha) as max_f, MIN(fecha) as min_f, COUNT(DISTINCT ip_usuario) as total_ips FROM historial_actividad WHERE usuario_id = ?");
        $stmt_stats->bind_param("i", $ver_usuario_id);
    }
    $stmt_stats->execute();
    $stats = $stmt_stats->get_result()->fetch_assoc() ?? ['total_acciones' => 0, 'max_f' => null];
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

    if ($is_guest) {
        $usuario_target = [
            'nombre' => $filtro_ip ? 'Invitado ' . strtoupper(substr(md5($filtro_ip), 0, 5)) : 'Tráfico Anónimo',
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

} elseif ($tab_activa === 'fallidas') {
    // === VISTA E: BÚSQUEDAS FALLIDAS ===
    $sql_demandas = "SELECT termino, COUNT(*) as total_intentos, MAX(fecha) as ultima_busqueda 
                     FROM busquedas_fallidas GROUP BY termino ORDER BY total_intentos DESC, ultima_busqueda DESC LIMIT 50";
    $res_demandas = $conn->query($sql_demandas);

} else {
    // === VISTA C: TRÁFICO GENERAL ===
    $sql_list = "SELECT h1.usuario_id, h1.ip_usuario, h1.ultima_actividad, h1.total_acciones, 
                        h2.url as ultima_url, h2.accion as ultima_accion_txt,
                        a.nombre, a.foto_perfil, a.institucion, a.correo
                 FROM (
                     SELECT IFNULL(usuario_id, 0) as usuario_id, ip_usuario, MAX(fecha) as ultima_actividad, COUNT(id) as total_acciones
                     FROM historial_actividad
                     WHERE fecha >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                     GROUP BY IFNULL(usuario_id, 0), CASE WHEN IFNULL(usuario_id, 0) = 0 THEN ip_usuario ELSE '1' END
                 ) h1
                 LEFT JOIN historial_actividad h2 ON 
                      IFNULL(h2.usuario_id, 0) = h1.usuario_id AND 
                      (h1.usuario_id != 0 OR h2.ip_usuario = h1.ip_usuario) AND 
                      h2.fecha = h1.ultima_actividad
                 LEFT JOIN alumnos a ON h1.usuario_id = a.id
                 ORDER BY h1.ultima_actividad DESC LIMIT 150";
    
    $conn->query("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
    $lista_usuarios = $conn->query($sql_list);
}

// Helper Badges
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
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor Analítico | Nubira</title>
    <meta name="theme-color" content="#ffffff" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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

<main class="pt-16 pb-32 md:pb-16 md:ml-64 px-4 md:px-6 w-full md:w-[calc(100%-16rem)]">
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
            <i class="fa-solid fa-users-viewfinder mr-1.5"></i> Tráfico Global
        </a>
        <a href="?tab=fallidas" class="pb-3 px-1 border-b-2 font-bold text-xs uppercase tracking-widest whitespace-nowrap transition-colors <?= $tab_activa === 'fallidas' ? 'border-orange-500 text-orange-500' : 'border-transparent text-gray-400 hover:text-gray-600' ?>">
            <i class="fa-solid fa-search-minus mr-1.5"></i> Búsquedas Fallidas
        </a>
    </div>
    <?php endif; ?>

    <?php if ($ver_usuario_id !== null): ?>
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
                        <div class="w-16 h-16 rounded-2xl <?= $ver_usuario_id === 0 ? 'bg-gray-100 text-gray-400' : 'bg-gray-50 text-gray-300' ?> relative border border-gray-100 flex items-center justify-center shrink-0">
                            <?php if (!empty($usuario_target['foto_perfil'])): ?>
                                <img src="/app/perfil/fotos/<?= htmlspecialchars($usuario_target['foto_perfil']) ?>" class="w-full h-full object-cover rounded-2xl">
                            <?php else: ?>
                                <div class="text-2xl font-bold"><?= $ver_usuario_id === 0 ? '<i class="fa-solid fa-mask"></i>' : strtoupper(substr($usuario_target['nombre']??'U',0,1)) ?></div>
                            <?php endif; ?>
                            <?php if($online_detalle): ?><span class="absolute -bottom-1 -right-1 w-4 h-4 bg-emerald-500 border-[3px] border-white rounded-full animate-pulse"></span><?php endif; ?>
                        </div>
                        <div>
                            <h1 class="text-2xl font-extrabold text-gray-900 flex items-center gap-2 tracking-tight">
                                <?= htmlspecialchars($usuario_target['nombre'] ?? 'Usuario') ?>
                                <?php if($online_detalle): ?><span class="text-[9px] bg-emerald-50 text-emerald-600 border border-emerald-100 px-2 py-0.5 rounded-md font-bold uppercase tracking-widest">Online</span><?php endif; ?>
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
                                <tr class="hover:bg-gray-50/50 transition-colors group align-middle row-historial" data-ip="<?= htmlspecialchars($h['ip_usuario']) ?>">
                                    <td class="px-5 py-3"><input type="checkbox" name="ids[]" value="<?= $h['id'] ?>" class="item-check rounded border-gray-300 text-[#54A6D8] focus:ring-0"></td>
                                    
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md text-[9px] font-black <?= $style['bg'] ?> <?= $style['txt'] ?> uppercase tracking-widest">
                                            <i class="fa-solid <?= $style['icon'] ?>"></i> <?= htmlspecialchars($h['accion']) ?>
                                        </span>
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

    <?php elseif ($tab_activa === 'fallidas'): ?>
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
        <div class="flex items-center gap-3 overflow-x-auto custom-scrollbar pb-4 mb-2 animate-fade-in-up" id="filtros-monitor">
            <button data-filter="todos" class="filter-btn bg-gray-900 text-white border-gray-900 border rounded-full px-4 py-1.5 text-xs font-bold whitespace-nowrap transition-transform hover:scale-[1.02]">Todos</button>
            <button data-filter="alumnos" class="filter-btn bg-white text-gray-600 border-gray-200 border hover:border-gray-300 rounded-full px-4 py-1.5 text-xs font-bold whitespace-nowrap transition-all shadow-sm hover:shadow-md">🙋‍♂️ Alumnos</button>
            <button data-filter="invitados" class="filter-btn bg-white text-gray-600 border-gray-200 border hover:border-gray-300 rounded-full px-4 py-1.5 text-xs font-bold whitespace-nowrap transition-all shadow-sm hover:shadow-md">🕵️ Invitados</button>
            <button data-filter="online" class="filter-btn bg-emerald-50 text-emerald-700 border-emerald-200 border hover:border-emerald-300 rounded-full px-4 py-1.5 text-xs font-bold whitespace-nowrap transition-all shadow-sm hover:shadow-md flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> Online Ahora
            </button>
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
                    
                    $es_fantasma = !empty($u['fantasma_inst']);
                    if ($es_fantasma) { $guest_id = '🕵️ Ghost: ' . htmlspecialchars($u['fantasma_inst']); }
                    
                    $last_url = $u['ultima_url'] ?? '/';
                    $url_corta = parse_url($last_url, PHP_URL_PATH) ?? '/';
                    if (strlen($url_corta) > 25) $url_corta = substr($url_corta, 0, 22) . '...';

                    $card_bg = 'border-gray-100 hover:border-[#54A6D8] bg-white';
                    if ($es_invitado) {
                        if ($es_fantasma) $card_bg = 'border-indigo-200 bg-indigo-50/30';
                        else $card_bg = 'border-gray-200 bg-gray-50/50';
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
               <a href="<?= $url_detalle ?>" 
                   data-ip="<?= $ip_str ?>"
                   data-tipo="<?= $tipo_usuario ?>" 
                   data-online="<?= $is_online ?>" 
                   data-region="pendiente"
                   class="monitor-card block rounded-2xl p-5 border <?= $card_bg ?> transition-all hover:shadow-md shadow-sm hover:scale-[1.01] relative flex flex-col h-full group bg-white">
                    <div class="flex items-start gap-4 mb-4">
                        <div class="w-12 h-12 rounded-xl <?= $es_invitado ? ($es_fantasma ? 'bg-indigo-100 text-indigo-600' : 'bg-gray-200 text-gray-500') : 'bg-gray-100 text-gray-400' ?> relative flex items-center justify-center border border-transparent group-hover:border-[#54A6D8] transition-colors shrink-0 mt-1 avatar-container">
                            <?php if (!empty($u['foto_perfil']) && !$es_invitado): ?>
                                <img src="/app/perfil/fotos/<?= htmlspecialchars($u['foto_perfil']) ?>" class="w-full h-full object-cover rounded-xl">
                            <?php elseif ($es_invitado): ?>
                                <i class="fa-solid <?= $es_fantasma ? 'fa-user-secret' : 'fa-mask' ?> text-xl icon-avatar"></i>
                            <?php else: ?>
                                <div class="font-bold text-lg"><?= strtoupper(substr($u['nombre']??'U',0,1)) ?></div>
                            <?php endif; ?>
                            <?php if($online): ?><span class="absolute -bottom-1 -right-1 w-3.5 h-3.5 bg-emerald-500 border-2 border-white rounded-full animate-pulse z-10"></span><?php endif; ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="font-extrabold <?= $es_invitado ? ($es_fantasma ? 'text-indigo-800' : 'text-gray-600') : 'text-gray-900 group-hover:text-[#54A6D8]' ?> transition-colors truncate text-sm title-text"><?= $es_invitado ? $guest_id : htmlspecialchars($u['nombre']) ?></h3>
                            
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
                </a>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

  </div>
</main>

<!-- ════════════════════════════════════════════════════ -->
<!-- NUBIRA 2.0 · Tooltip Hover Geográfico (Airbnb Style)  -->
<!-- ════════════════════════════════════════════════════ -->
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
            <span id="geo-tooltip-bot" class="hidden text-[8px] bg-purple-50 text-purple-600 border border-purple-100 px-1.5 py-0.5 rounded font-black uppercase tracking-widest">
                <i class="fa-solid fa-robot"></i> Bot
            </span>
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
    // ════════════════════════════════════════════════════════════════
    // SISTEMA DE MODALES NUBIRA 2.0
    // ════════════════════════════════════════════════════════════════
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

    // ════════════════════════════════════════════════════════════════
    // SISTEMA LIVE / TOGGLE
    // ════════════════════════════════════════════════════════════════
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

    // ════════════════════════════════════════════════════════════════
    // CHECKBOXES Y ELIMINACIÓN
    // ════════════════════════════════════════════════════════════════
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

    // ════════════════════════════════════════════════════════════════
    // FILTROS DE TRÁFICO
    // ════════════════════════════════════════════════════════════════
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

    // ════════════════════════════════════════════════════════════════
    // NUBIRA 2.0 · MOTOR DE GEOLOCALIZACIÓN (API-FIRST)
    // Consume: /app/api/geolocalizar_ip.php
    // Reutilizable: Web actual + Futura App Flutter (2027)
    // ════════════════════════════════════════════════════════════════
    document.addEventListener('DOMContentLoaded', async () => {
        const cards = document.querySelectorAll(
            '.monitor-card[data-ip], .detail-card[data-ip], .row-historial[data-ip]'
        );
        if (!cards.length) return;

        const ipsLocales = ['0.0.0.0', '::1', '127.0.0.1'];
        const todasLasIps = [...new Set(
            Array.from(cards).map(c => c.dataset.ip)
        )].filter(ip => ip && !ipsLocales.includes(ip));

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

            const esBot = info.es_hosting || info.es_proxy;

            const bandera = info.pais_codigo
                ? `<img src="https://flagcdn.com/16x12/${info.pais_codigo.toLowerCase()}.png" class="inline-block mr-1.5 rounded-[2px] shadow-sm" alt="${info.pais_codigo}" loading="lazy">`
                : '<i class="fa-solid fa-location-dot text-[#54A6D8] mr-1"></i>';

            const ciudad = info.ciudad || 'Ubicación desconocida';
            const pais   = info.pais   || '';

            // Guardar coordenadas en TODAS las cards para el tooltip
            if (info.lat && info.lon) {
                card.dataset.lat    = info.lat;
                card.dataset.lon    = info.lon;
                card.dataset.ciudad = ciudad;
                card.dataset.pais   = pais;
                card.dataset.isp    = info.isp || '';
                card.dataset.esBot  = esBot ? '1' : '0';
            }

            if (esMonitorCard) {
                if (esBot && card.dataset.tipo === 'invitado') {
                    card.style.transition = 'opacity 0.3s';
                    card.style.opacity = '0';
                    setTimeout(() => card.remove(), 300);
                    return;
                }

                const locEl = card.querySelector('.loc-text');
                if (locEl) {
                    locEl.innerHTML = `${bandera} <span class="truncate">${ciudad}, ${pais}</span>`;
                }
            }

            else if (esDetailCard) {
                const badgeBot = esBot
                    ? `<span class="bg-purple-50 text-purple-600 border border-purple-100 px-2 py-0.5 rounded-md text-[9px] uppercase tracking-widest font-black ml-2" title="ISP: ${info.isp || ''}"><i class="fa-solid fa-robot"></i> Bot</span>`
                    : '';

                const locEl = card.querySelector('.loc-text');
                if (locEl) {
                    locEl.innerHTML = `${bandera} <span>${ciudad}, ${pais}</span> ${badgeBot}`;
                }

                const mapEl = card.querySelector('.map-container');
                if (mapEl && info.lat && info.lon) {
                    mapEl.innerHTML = `
                        <iframe
                            width="100%" height="100%"
                            frameborder="0" scrolling="no"
                            marginheight="0" marginwidth="0"
                            src="https://maps.google.com/maps?q=${info.lat},${info.lon}&hl=es&z=12&output=embed"
                            class="pointer-events-none opacity-90"
                            loading="lazy">
                        </iframe>`;
                    mapEl.classList.remove('animate-pulse');
                }
            }

            else if (esFila) {
                const locEl = card.querySelector('.loc-text');
                if (locEl) {
                    locEl.innerHTML = `${bandera} <span class="truncate">${ciudad}, ${info.pais_codigo || ''}</span>`;
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