<?php
/**
 * VISTA: MONITOR DE CHATS AULA VIRTUAL (ADMIN)
 * ARQUITECTURA: NUBIRA 2.0 (Estricto)
 */
session_start();

require_once __DIR__ . '/conexion.php'; 
require_once __DIR__ . '/iconos.php';

// Verificación estricta de Admin
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') { 
    header("Location: /dashboard"); 
    exit; 
}

$orden_param = $_GET['orden'] ?? 'desc';
$orden_sql = ($orden_param === 'asc') ? 'ASC' : 'DESC';

// --- MOTOR DE AVATARES NATIVO ---
if (!function_exists('avatar_nativo')) {
    function avatar_nativo($nombre, $foto, $clases = "w-10 h-10 border-2 border-white text-xs") {
        if (!empty($foto)) {
            return '<img src="/app/perfil/fotos/' . htmlspecialchars($foto, ENT_QUOTES, 'UTF-8') . '" class="rounded-full object-cover shadow-sm bg-gray-50 shrink-0 ' . $clases . '" loading="lazy" alt="Avatar">';
        }
        $p = array_values(array_filter(explode(' ', trim($nombre))));
        $ini = strtoupper(substr($p[0]??'U', 0, 1) . substr($p[1]??'', 0, 1));
        $colors = ['bg-sky-100 text-sky-600', 'bg-emerald-100 text-emerald-600', 'bg-orange-100 text-orange-600', 'bg-blue-100 text-[#54A6D8]', 'bg-indigo-100 text-indigo-600'];
        $col = $colors[abs(crc32($nombre)) % count($colors)];
        return '<div class="rounded-full shadow-sm flex items-center justify-center font-bold shrink-0 ' . $col . ' ' . $clases . '">' . $ini . '</div>';
    }
}

// =================================================================================
// 1. BLOQUE AJAX: BÚSQUEDA EN VIVO Y LISTA LATERAL
// =================================================================================
if (isset($_GET['ajax_search'])) {
    $busqueda = trim($_GET['q'] ?? '');
    $chat_actual = isset($_GET['current_id']) ? (int)$_GET['current_id'] : 0;

    $sql = "SELECT c.id, c.estado, c.fecha_creacion,
                   u1.nombre as n1, u1.foto_perfil as f1, 
                   u2.nombre as n2, u2.foto_perfil as f2, 
                   (SELECT mensaje FROM chat_aula WHERE contrato_id = c.id ORDER BY id DESC LIMIT 1) as msg_aula,
                   (SELECT fecha FROM chat_aula WHERE contrato_id = c.id ORDER BY id DESC LIMIT 1) as fecha_aula
            FROM contratos c
            LEFT JOIN alumnos u1 ON c.comprador_id = u1.id
            LEFT JOIN alumnos u2 ON c.vendedor_id = u2.id
            WHERE 1=1 ";

    $params = [];
    $types = "";
    
    if ($busqueda !== '') {
        $sql .= " AND (u1.nombre LIKE ? OR u2.nombre LIKE ?) ";
        $b_param = "%{$busqueda}%";
        $params[] = $b_param;
        $params[] = $b_param;
        $types .= "ss";
    }

    $sql .= " ORDER BY COALESCE((SELECT MAX(fecha) FROM chat_aula WHERE contrato_id = c.id), c.fecha_creacion) $orden_sql";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $res->num_rows > 0) {
        while($c = $res->fetch_assoc()) {
            $n1_parts = explode(' ', trim($c['n1'] ?? 'Usuario'));
            $n1_display = $n1_parts[0] . (isset($n1_parts[1]) ? ' ' . mb_substr($n1_parts[1], 0, 1) . '.' : '');
            
            $n2_parts = explode(' ', trim($c['n2'] ?? 'Usuario'));
            $n2_display = $n2_parts[0] . (isset($n2_parts[1]) ? ' ' . mb_substr($n2_parts[1], 0, 1) . '.' : '');
            
            $u_msg = $c['msg_aula'] ?? 'Sin mensajes...';
            $fecha_raw = $c['fecha_aula'] ?? $c['fecha_creacion'];
            $fecha = $fecha_raw ? date('d/m', strtotime($fecha_raw)) : '--';
            
            $es_en_vivo = ($fecha_raw && (time() - strtotime($fecha_raw) <= 120));
            $cerrado = in_array($c['estado'], ['finalizado', 'cancelado', 'disputa']);

            $avatar1 = avatar_nativo($n1_display, $c['f1']);
            $avatar2 = avatar_nativo($n2_display, $c['f2']);
            
            $activo = ($chat_actual == $c['id']);
            $bg_class = $activo ? 'bg-blue-50/50' : 'bg-white hover:bg-gray-50';
            $border_active = $activo ? '<div class="absolute left-0 top-0 bottom-0 w-1 bg-[#54A6D8] z-10 rounded-l-2xl"></div>' : '';
            
            $badge_estado = '';
            if ($c['estado'] === 'finalizado') $badge_estado = '<span class="text-[9px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full uppercase font-bold tracking-wider ml-1">Fin</span>';
            if ($c['estado'] === 'disputa') $badge_estado = '<span class="text-[9px] bg-red-100 text-red-600 px-2 py-0.5 rounded-full uppercase font-bold tracking-wider ml-1">Disputa</span>';

            $dot_en_vivo = ($es_en_vivo && !$cerrado) ? '<span class="absolute -top-1 -right-1 flex h-3 w-3 z-20"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500 border border-white"></span></span>' : '';

            echo '
            <div class="group flex items-center border border-gray-100 mx-4 mb-2 rounded-2xl transition-all hover:shadow-md hover:scale-[1.01] relative '.$bg_class.' cursor-pointer" onclick="window.location.href=\'?id='.$c['id'].'&orden='.$orden_param.'\'">
                '.$border_active.'
                <div class="flex-1 flex items-center gap-3 p-4 min-w-0 '.($cerrado ? 'opacity-60' : 'opacity-100').'">
                    <div class="relative flex -space-x-3 min-w-[3.5rem] flex-shrink-0">
                        '.$dot_en_vivo.'
                        '.$avatar1.'
                        '.$avatar2.'
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex justify-between items-center mb-0.5">
                            <p class="text-sm font-bold text-gray-900 truncate tracking-tight">C-'.$c['id'].' '.htmlspecialchars($n1_display, ENT_QUOTES, 'UTF-8').' '.$badge_estado.'</p>
                            <span class="text-[10px] text-gray-400 whitespace-nowrap ml-1 font-medium">'.$fecha.'</span>
                        </div>
                        <p class="text-xs text-gray-500 truncate group-hover:text-[#54A6D8] transition-colors">'.htmlspecialchars(strip_tags($u_msg), ENT_QUOTES, 'UTF-8').'</p>
                    </div>
                </div>
            </div>';
        }
    } else {
        echo '<div class="p-8 text-center bg-gray-50 border-2 border-dashed border-gray-200 rounded-3xl mx-4 mt-6">
                <div class="w-12 h-12 mx-auto bg-white rounded-2xl shadow-sm flex items-center justify-center mb-3">
                    <i class="fa-solid fa-ghost text-xl text-gray-300"></i>
                </div>
                <p class="text-sm font-bold text-gray-700">No hay aulas activas</p>
                <p class="text-xs text-gray-500 mt-1">Las aulas aparecerán cuando existan contratos.</p>
              </div>';
    }
    exit;
}

// =================================================================================
// 2. BLOQUE AJAX: SMART POLLING (MENSAJES)
// =================================================================================
if (isset($_GET['ajax_messages']) && isset($_GET['id'])) {
    $chat_id = (int)$_GET['id'];
    
    $stmt_c = $conn->prepare("SELECT comprador_id, vendedor_id FROM contratos WHERE id = ?");
    $stmt_c->bind_param("i", $chat_id);
    $stmt_c->execute();
    $info_c = $stmt_c->get_result()->fetch_assoc();
    
    if(!$info_c) exit;
    $c_id = $info_c['comprador_id'];

    $query = "SELECT remitente_id, mensaje, fecha as enviado_en FROM chat_aula WHERE contrato_id = ? ORDER BY fecha ASC";
    $stmt_m = $conn->prepare($query);
    $stmt_m->bind_param("i", $chat_id);
    $stmt_m->execute();
    $mensajes = $stmt_m->get_result();

    if ($mensajes->num_rows === 0) {
        echo '<div class="flex flex-col items-center justify-center h-full text-center p-8 bg-gray-50 m-6 rounded-3xl border-2 border-dashed border-gray-200">
                <div class="w-16 h-16 bg-white rounded-2xl flex items-center justify-center mb-4 shadow-sm border border-gray-100">
                    <i class="fa-regular fa-comments text-2xl text-gray-300"></i>
                </div>
                <p class="text-sm font-bold text-gray-700">El aula no tiene mensajes.</p>
              </div>';
        exit;
    }

    while($m = $mensajes->fetch_assoc()) {
        $is_blue = ($m['remitente_id'] == $c_id); 
        $flex_dir = $is_blue ? 'flex-row-reverse' : 'flex-row';
        
        echo '
        <div class="flex '.$flex_dir.' items-end gap-2 animate-fade-in-up mb-3">
            <div class="max-w-[85%] md:max-w-[70%] px-4 py-2.5 rounded-2xl shadow-sm text-sm relative leading-relaxed '
                .($is_blue ? 'bg-[#54A6D8] text-white rounded-tr-sm' : 'bg-white text-gray-800 rounded-tl-sm border border-gray-100').'">
                '.nl2br(htmlspecialchars($m['mensaje'], ENT_QUOTES, 'UTF-8')).'
                <div class="flex items-center justify-end mt-1 opacity-80 text-[10px] font-medium">
                    <span>'.date('d/m H:i', strtotime($m['enviado_en'])).'</span>
                </div>
            </div>
        </div>';
    }
    echo '<div id="scroll-bottom" class="h-4"></div>';
    exit;
}

// =================================================================================
// 3. CARGA INICIAL COMPLETA
// =================================================================================
$sql_lista = "SELECT c.id, c.estado, c.fecha_creacion, u1.nombre as n1, u1.foto_perfil as f1, u2.nombre as n2, u2.foto_perfil as f2, 
              (SELECT mensaje FROM chat_aula WHERE contrato_id = c.id ORDER BY id DESC LIMIT 1) as msg_aula,
              (SELECT fecha FROM chat_aula WHERE contrato_id = c.id ORDER BY id DESC LIMIT 1) as fecha_aula
              FROM contratos c
              LEFT JOIN alumnos u1 ON c.comprador_id = u1.id
              LEFT JOIN alumnos u2 ON c.vendedor_id = u2.id
              ORDER BY COALESCE((SELECT MAX(fecha) FROM chat_aula WHERE contrato_id = c.id), c.fecha_creacion) $orden_sql";

$res_listado = $conn->query($sql_lista);

$chat_seleccionado = isset($_GET['id']) ? (int)$_GET['id'] : null;
$info_chat = null;

if ($chat_seleccionado) {
   $stmt = $conn->prepare("SELECT u1.nombre as n1, u1.foto_perfil as f1, u2.nombre as n2, u2.foto_perfil as f2, c.estado, c.comprador_id FROM contratos c LEFT JOIN alumnos u1 ON c.comprador_id = u1.id LEFT JOIN alumnos u2 ON c.vendedor_id = u2.id WHERE c.id = ?");
   $stmt->bind_param("i", $chat_seleccionado);
   $stmt->execute();
   $info_chat = $stmt->get_result()->fetch_assoc();
   if (!$info_chat) $chat_seleccionado = null;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>Monitor Aulas | Nubira 2.0</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0, viewport-fit=cover" />
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f9fafb; overscroll-behavior-y: none; }
        
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 16px; }
        .custom-scrollbar:hover::-webkit-scrollbar-thumb { background: #d1d5db; }
        @media (max-width: 768px) { .custom-scrollbar::-webkit-scrollbar { display: none; } }
        
        .animate-fade-in-up { animation: fadeInUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        
        .resizer-handle { cursor: col-resize; transition: background-color 0.2s; }
        .resizer-handle:hover, .resizer-handle.active { background-color: #54A6D8; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden h-screen flex flex-col">

    <div id="loader" class="fixed inset-0 bg-white/80 backdrop-blur-md flex items-center justify-center z-[70] transition-all duration-300">
        <div class="animate-spin h-10 w-10 border-4 border-blue-100 border-t-[#54A6D8] rounded-full"></div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const loader = document.getElementById('loader');
            if(loader) { setTimeout(() => { loader.style.opacity = '0'; setTimeout(() => loader.style.display = 'none', 300); }, 300); }
        });
    </script>

    <?php 
    $page_title = "Auditoría Aulas"; 
    require_once __DIR__ . '/componentes/header.php'; 
    require_once __DIR__ . '/componentes/sidebar.php'; 
    ?>

    <main class="pt-20 md:pt-16 pb-20 md:pb-0 lg:ml-64 h-full flex overflow-hidden bg-white w-full max-w-[1600px] mx-auto border-x border-gray-100 shadow-sm" id="main-container">
       
       <div id="sidebar-panel" class="bg-white border-r border-gray-100 flex flex-col h-full z-10 <?php echo $chat_seleccionado ? 'hidden md:flex' : 'flex w-full md:w-auto'; ?>" style="width: 100%; md:width: 40%; min-width: 340px;">
            <div class="px-5 pt-5 pb-3">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 mb-4">Aulas Activas</h1>
                <div class="relative group">
                    <i class="fa-solid fa-search absolute left-4 top-3.5 text-gray-400 text-sm group-focus-within:text-[#54A6D8] transition-colors"></i>
                    <input type="text" id="searchInput" placeholder="Buscar ID o usuario..." class="w-full pl-11 pr-4 py-3 bg-gray-50 border border-gray-100 rounded-2xl text-sm font-medium focus:ring-2 focus:ring-[#54A6D8]/20 focus:border-[#54A6D8] transition-all outline-none shadow-sm placeholder-gray-400">
                </div>
            </div>
            
            <div class="px-5 py-3 flex justify-between items-center bg-white gap-2 flex-shrink-0 border-b border-gray-50 mb-2">
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Historial Reciente</span>
                <form method="GET" class="flex items-center">
                    <?php if($chat_seleccionado): ?><input type="hidden" name="id" value="<?php echo $chat_seleccionado; ?>"><?php endif; ?>
                    <select name="orden" onchange="this.form.submit()" class="bg-gray-50 text-xs font-bold text-gray-600 border border-gray-100 rounded-xl py-1.5 px-3 cursor-pointer outline-none hover:bg-gray-100 transition-colors">
                        <option value="desc" <?php echo ($orden_param == 'desc') ? 'selected' : ''; ?>>Recientes</option>
                        <option value="asc" <?php echo ($orden_param == 'asc') ? 'selected' : ''; ?>>Antiguos</option>
                    </select>
                </form>
            </div>
            
            <div id="chatsListContainer" class="flex-1 overflow-y-auto custom-scrollbar pb-4">
                <?php while($c = $res_listado->fetch_assoc()): 
                    $n1_parts = explode(' ', trim($c['n1'] ?? 'Usuario'));
                    $n1_display = $n1_parts[0] . (isset($n1_parts[1]) ? ' ' . mb_substr($n1_parts[1], 0, 1) . '.' : '');
                    
                    $n2_parts = explode(' ', trim($c['n2'] ?? 'Usuario'));
                    $n2_display = $n2_parts[0] . (isset($n2_parts[1]) ? ' ' . mb_substr($n2_parts[1], 0, 1) . '.' : '');
                    
                    $u_msg = $c['msg_aula'] ?? 'Sin mensajes...';
                    $fecha_raw = $c['fecha_aula'] ?? $c['fecha_creacion'];

                    $es_en_vivo = ($fecha_raw && (time() - strtotime($fecha_raw) <= 120));
                    $cerrado = in_array($c['estado'], ['finalizado', 'cancelado', 'disputa']);
                    $activo = ($chat_seleccionado == $c['id']);
                ?>
                <div class="group flex items-center border border-gray-100 mx-4 mb-2 rounded-2xl transition-all hover:shadow-md hover:scale-[1.01] relative cursor-pointer <?php echo $activo ? 'bg-blue-50/50' : 'bg-white hover:bg-gray-50'; ?>" onclick="window.location.href='?id=<?php echo $c['id']; ?>&orden=<?php echo $orden_param; ?>'">
                    <?php if($activo): ?><div class="absolute left-0 top-0 bottom-0 w-1 bg-[#54A6D8] z-10 rounded-l-2xl"></div><?php endif; ?>
                    <div class="flex-1 flex items-center gap-3 p-4 min-w-0 <?php echo $cerrado ? 'opacity-60' : ''; ?>">
                        <div class="relative flex -space-x-3 min-w-[4rem] flex-shrink-0">
                            <?php if($es_en_vivo && !$cerrado): ?><span class="absolute -top-1 -right-1 flex h-3.5 w-3.5 z-20"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full h-3.5 w-3.5 bg-emerald-500 border-2 border-white"></span></span><?php endif; ?>
                            <?php echo avatar_nativo($n1_display, $c['f1']); echo avatar_nativo($n2_display, $c['f2']); ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-center mb-1">
                                <p class="text-sm font-bold text-gray-900 truncate tracking-tight">C-<?php echo $c['id']; ?> <?php echo htmlspecialchars($n1_display, ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if($c['estado'] === 'finalizado') echo '<span class="text-[9px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full uppercase font-bold ml-1">Fin</span>'; ?>
                                    <?php if($c['estado'] === 'disputa') echo '<span class="text-[9px] bg-red-100 text-red-600 px-2 py-0.5 rounded-full uppercase font-bold ml-1">Disputa</span>'; ?>
                                </p>
                                <span class="text-[10px] text-gray-400 whitespace-nowrap ml-2 font-medium"><?php echo $fecha_raw ? date('d/m', strtotime($fecha_raw)) : '--'; ?></span>
                            </div>
                            <p class="text-xs text-gray-500 truncate group-hover:text-[#54A6D8] transition-colors">
                                <?php echo htmlspecialchars(strip_tags($u_msg), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>

        <div id="drag-handle" class="hidden md:block w-[4px] bg-gray-50 border-x border-gray-100 h-full resizer-handle z-20 flex-shrink-0 hover:bg-[#54A6D8]/20 transition-colors"></div>

        <div class="flex-1 bg-white flex flex-col h-full min-w-0 relative <?php echo $chat_seleccionado ? 'flex fixed inset-0 md:static z-50' : 'hidden md:flex'; ?>">
            <?php if ($chat_seleccionado && $info_chat): 
                $d_n1 = explode(' ', trim($info_chat['n1'] ?? 'Usuario'))[0]; 
                $d_n2 = explode(' ', trim($info_chat['n2'] ?? 'Usuario'))[0];
            ?>
                <div class="px-5 py-4 bg-white/90 backdrop-blur-md border-b border-gray-100 flex justify-between items-center sticky top-0 z-20 h-[72px]">
                    <div class="flex items-center gap-3">
                        <a href="?orden=<?php echo $orden_param; ?>" class="md:hidden text-[#54A6D8] hover:bg-sky-50 p-2 rounded-full transition-colors flex items-center justify-center w-10 h-10">
                            <i class="fa-solid fa-chevron-left text-lg"></i>
                        </a>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="font-bold text-gray-900 text-lg leading-tight tracking-tight">Auditoría Aula</h3>
                                <span class="bg-blue-50 text-[#54A6D8] text-[10px] px-2 py-0.5 rounded-full font-bold border border-blue-100 uppercase tracking-wider">MOD</span>
                            </div>
                            <p class="text-xs text-gray-500 truncate mt-0.5 font-medium">Contrato #<?php echo $chat_seleccionado; ?> &middot; <?php echo htmlspecialchars($d_n1, ENT_QUOTES, 'UTF-8'); ?> y <?php echo htmlspecialchars($d_n2, ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    </div>
                </div>

               <?php 
                    $c_id = $info_chat['comprador_id'];
                    
                    // 1. CARGAR HISTORIAL PRE-VENTA (Si existe)
                    $query_prev = "SELECT m.remitente_id, m.mensaje, m.enviado_en 
                                   FROM conversaciones c 
                                   JOIN mensajes m ON c.id = m.conversacion_id 
                                   WHERE c.contrato_id = ? 
                                   ORDER BY m.enviado_en ASC";
                    $stmt_prev = $conn->prepare($query_prev);
                    $stmt_prev->bind_param("i", $chat_seleccionado);
                    $stmt_prev->execute();
                    $msgs_prev = $stmt_prev->get_result();
                    
                    $tiene_historial = $msgs_prev->num_rows > 0;

                    if ($tiene_historial) {
                        // Renderizar mensajes antiguos
                        while($m = $msgs_prev->fetch_assoc()): 
                            $is_blue = ($m['remitente_id'] == $c_id);
                        ?>
                            <div class="flex <?php echo $is_blue ? 'flex-row-reverse' : 'flex-row'; ?> items-end gap-2 mb-3 opacity-75 grayscale-[20%]">
                                <div class="max-w-[85%] md:max-w-[70%] px-4 py-3 rounded-2xl shadow-sm text-sm leading-relaxed <?php echo $is_blue ? 'bg-[#54A6D8] text-white rounded-tr-sm' : 'bg-white text-gray-800 rounded-tl-sm border border-gray-100'; ?>">
                                    <?php echo nl2br(htmlspecialchars($m['mensaje'], ENT_QUOTES, 'UTF-8')); ?>
                                    <div class="flex items-center justify-end mt-1.5 opacity-80 text-[10px] font-medium">
                                        <span><?php echo date('d/m H:i', strtotime($m['enviado_en'])); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                        
                        <!-- Separador UX Oficial -->
                        <div class="flex items-center justify-center my-6">
                            <div class="bg-gray-200 h-px flex-1"></div>
                            <span class="px-4 text-[10px] font-bold text-gray-400 uppercase tracking-widest bg-gray-50/50">Servicio Contratado</span>
                            <div class="bg-gray-200 h-px flex-1"></div>
                        </div>
                    <?php } ?>

                    <?php
                    // 2. CARGAR MENSAJES DEL AULA VIRTUAL (Post-pago)
                    $query_aula = "SELECT remitente_id, mensaje, fecha as enviado_en
                                   FROM chat_aula
                                   WHERE contrato_id = ?
                                   ORDER BY fecha ASC";
                    $stmt_aula = $conn->prepare($query_aula);
                    $stmt_aula->bind_param("i", $chat_seleccionado);
                    $stmt_aula->execute();
                    $msgs_aula = $stmt_aula->get_result();

                    if (!$tiene_historial && $msgs_aula->num_rows === 0): ?>
                        <div class="flex flex-col items-center justify-center h-full text-center p-8 bg-gray-50 m-6 rounded-3xl border-2 border-dashed border-gray-200">
                            <div class="w-16 h-16 bg-white rounded-2xl shadow-sm flex items-center justify-center mb-4 border border-gray-100">
                                <i class="fa-regular fa-comments text-2xl text-gray-300"></i>
                            </div>
                            <p class="text-sm font-bold text-gray-700">El aula no tiene mensajes.</p>
                        </div>
                    <?php endif;

                    while($m = $msgs_aula->fetch_assoc()): 
                        $is_blue = ($m['remitente_id'] == $c_id);
                    ?>
                        <div class="flex <?php echo $is_blue ? 'flex-row-reverse' : 'flex-row'; ?> items-end gap-2 mb-3">
                            <div class="max-w-[85%] md:max-w-[70%] px-4 py-3 rounded-2xl shadow-sm text-sm leading-relaxed <?php echo $is_blue ? 'bg-[#54A6D8] text-white rounded-tr-sm' : 'bg-white text-gray-800 rounded-tl-sm border border-gray-100'; ?>">
                                <?php echo nl2br(htmlspecialchars($m['mensaje'], ENT_QUOTES, 'UTF-8')); ?>
                                <div class="flex items-center justify-end mt-1.5 opacity-80 text-[10px] font-medium">
                                    <span><?php echo date('d/m H:i', strtotime($m['enviado_en'])); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
            <?php else: ?>
                <div class="flex flex-col items-center justify-center h-full text-center p-8 bg-gray-50 m-6 rounded-3xl border-2 border-dashed border-gray-200">
                    <div class="w-20 h-20 bg-white rounded-2xl flex items-center justify-center mb-6 shadow-sm border border-gray-100 rotate-3 transition-transform hover:rotate-0">
                        <i class="fa-solid fa-shield-halved w-8 h-8 text-[#54A6D8]/60"></i>
                    </div>
                    <h2 class="text-xl font-bold text-gray-900 mb-2 tracking-tight">Centro de Auditoría</h2>
                    <p class="text-gray-500 text-sm max-w-sm mx-auto leading-relaxed">Selecciona un aula en el panel lateral para inspeccionar el historial. Todo el tráfico se audita bajo estrictos protocolos de privacidad Nubira.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php require_once __DIR__ . '/componentes/nav_bottom.php'; ?>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const searchInput = document.getElementById('searchInput');
            const chatsList = document.getElementById('chatsListContainer');
            const currentChatId = '<?php echo $chat_seleccionado ?? 0; ?>';
            const currentOrden = '<?php echo $orden_param; ?>';
            const requestUri = window.location.pathname; 
            let timeoutId;

            const showSkeletons = () => {
                let sk = '';
                for(let i=0; i<4; i++) {
                    sk += `<div class="flex items-center border border-gray-100 mx-4 mb-2 p-4 rounded-2xl animate-pulse bg-white"><div class="w-10 h-10 mr-3 rounded-full bg-gray-200"></div><div class="flex-1"><div class="h-3 bg-gray-200 rounded-full w-24 mb-2"></div><div class="h-2 bg-gray-100 rounded-full w-3/4"></div></div></div>`;
                }
                chatsList.innerHTML = sk;
            };

            if(searchInput) {
                searchInput.addEventListener('input', function() {
                    clearTimeout(timeoutId);
                    showSkeletons();
                    timeoutId = setTimeout(() => {
                        fetch(`${requestUri}?ajax_search=1&q=${encodeURIComponent(this.value)}&current_id=${currentChatId}&orden=${currentOrden}`)
                        .then(r => r.text())
                        .then(html => { 
                            chatsList.style.opacity = 0;
                            chatsList.innerHTML = html; 
                            setTimeout(() => { chatsList.style.opacity = 1; }, 50);
                        });
                    }, 400);
                });
            }

            const c = document.getElementById('chat-container');
            const scrollAlFondo = () => { if(c) { c.scrollTop = c.scrollHeight; } };
            scrollAlFondo();
            
            if (currentChatId !== '0' && c) {
                let currentMessageCount = c.children.length;
                setInterval(() => {
                    if (document.hidden) return; 
                    fetch(`${requestUri}?id=${currentChatId}&ajax_messages=1`)
                    .then(r => r.text())
                    .then(html => {
                        const tempDiv = document.createElement('div'); 
                        tempDiv.innerHTML = html;
                        if (tempDiv.children.length > currentMessageCount) {
                            c.innerHTML = html; 
                            currentMessageCount = tempDiv.children.length;
                            const sb = document.getElementById('scroll-bottom'); 
                            if(sb) sb.scrollIntoView({ behavior: 'smooth' });
                        }
                    });
                }, 5000); 
            }
        });
    </script>
</body>
</html>