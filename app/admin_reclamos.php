<?php
/**
 * VISTA ADMIN: GESTIÓN DE RECLAMOS (NUBIRA 2.0)
 * ROL: Full Stack Senior & Lead UX/UI
 * ESTÁNDAR: Clean Design, Mobile-First, Ledger List, PRG Pattern, Hard Delete en Papelera.
 */
ini_set('display_errors', 0);
session_start();

require_once __DIR__ . '/conexion.php';
if (file_exists(__DIR__ . '/iconos.php')) require_once __DIR__ . '/iconos.php';

// 1. SEGURIDAD EXTREMA
if (empty($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /login');
    exit;
}

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Mensajes Flash (Feedback temporal de la sesión)
$mensaje_feedback = $_SESSION['flash_msg'] ?? '';
$error = $_SESSION['flash_err'] ?? false;
unset($_SESSION['flash_msg'], $_SESSION['flash_err']);

// Whitelist de estados (default: 'activos' = pendiente + en_proceso)
$estados_validos = ['activos', 'resuelto', 'todos', 'eliminado'];
$estado_get = $_GET['estado'] ?? 'activos';
if (!in_array($estado_get, $estados_validos, true)) {
    $estado_get = 'activos';
}
$estado_filtro = $estado_get;

// Helpers para redirección PRG
$current_url = strtok($_SERVER["REQUEST_URI"], '?') . '?estado=' . urlencode($estado_filtro);
function flash_redirect($msg, $is_error, $url) {
    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_err'] = $is_error;
    header("Location: " . $url);
    exit;
}

// Helper: Privacidad de Nombre
function format_name_privacy($fullname) {
    $parts = array_filter(explode(' ', trim((string)$fullname)));
    if (empty($parts)) return 'Usuario';
    $first = ucfirst(strtolower($parts[0]));
    $last_initial = count($parts) > 1 ? ' ' . strtoupper(substr($parts[count($parts)-1], 0, 1)) . '.' : '';
    return $first . $last_initial;
}

// 2. LÓGICA DE PROCESAMIENTO (POST - PRG PATTERN)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, $_POST['csrf'] ?? '')) {
        flash_redirect('Sesión expirada. Intenta de nuevo.', true, $current_url);
    }

    // A. RESOLVER TICKET O RESPONDER
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        if (isset($_POST['resolver'])) {
            $stmt = $conn->prepare("UPDATE reclamos_sugerencias SET estado='resuelto', revisado_usuario=0 WHERE id=?");
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) flash_redirect('Ticket cerrado exitosamente.', false, $current_url);
        }
        if (isset($_POST['responder']) && !empty(trim($_POST['respuesta_admin'] ?? ''))) {
            $respuesta = trim($_POST['respuesta_admin']);
            $conn->begin_transaction();
            try {
                $stmt_msg = $conn->prepare("INSERT INTO reclamos_mensajes (reclamo_id, remitente, mensaje, fecha) VALUES (?, 'admin', ?, NOW())");
                $stmt_msg->bind_param('is', $id, $respuesta);
                $stmt_msg->execute();
                
              $stmt_up = $conn->prepare("UPDATE reclamos_sugerencias SET estado='en_proceso', respuesta_admin=COALESCE(respuesta_admin, ?), revisado_usuario=0 WHERE id=?");
                $stmt_up->bind_param('si', $respuesta, $id);
                $stmt_up->execute();

                $conn->commit();
                flash_redirect('Respuesta enviada correctamente.', false, $current_url);
            } catch (Exception $e) {
                $conn->rollback();
                flash_redirect('Error al enviar la respuesta.', true, $current_url);
            }
        }
    }

    // B. ACCIÓN SIMPLE (1 a 1)
    if (!empty($_POST['ticket_id']) && !empty($_POST['accion_simple'])) {
        $tid = (int)$_POST['ticket_id'];
        $act = $_POST['accion_simple'];
        if ($tid > 0) {
            if ($act === 'eliminar_hard') {
                $conn->prepare("DELETE FROM reclamos_mensajes WHERE reclamo_id = ?")->execute([$tid]);
                $stmt = $conn->prepare("DELETE FROM reclamos_sugerencias WHERE id = ?");
                $stmt->bind_param('i', $tid);
                if ($stmt->execute()) flash_redirect('Ticket borrado de la BD.', false, $current_url);
            } elseif ($act === 'papelera') {
                $stmt = $conn->prepare("UPDATE reclamos_sugerencias SET estado='eliminado' WHERE id=?");
                $stmt->bind_param('i', $tid);
                if ($stmt->execute()) flash_redirect('Enviado a papelera.', false, $current_url);
            } elseif ($act === 'restaurar') {
                $stmt = $conn->prepare("UPDATE reclamos_sugerencias SET estado='pendiente' WHERE id=?");
                $stmt->bind_param('i', $tid);
                if ($stmt->execute()) flash_redirect('Ticket restaurado.', false, $current_url);
            }
        }
    }

    // C. ACCIÓN EN LOTE (Múltiples)
    if (!empty($_POST['tickets_seleccionados']) && !empty($_POST['accion_lote'])) {
        $ids = json_decode($_POST['tickets_seleccionados'], true);
        $accion = $_POST['accion_lote'];

        if (is_array($ids)) {
            $ids_limpios = array_filter(array_map('intval', $ids), fn($v) => $v > 0);
            if (!empty($ids_limpios)) {
                $placeholders = implode(',', array_fill(0, count($ids_limpios), '?'));
                $types = str_repeat('i', count($ids_limpios));
                
                if ($accion === 'eliminar_hard') {
                    // Borrado Físico: Primero mensajes, luego el ticket
                    $stmt_msg = $conn->prepare("DELETE FROM reclamos_mensajes WHERE reclamo_id IN ($placeholders)");
                    $stmt_msg->bind_param($types, ...$ids_limpios);
                    $stmt_msg->execute();
                    
                    $stmt_del = $conn->prepare("DELETE FROM reclamos_sugerencias WHERE id IN ($placeholders)");
                    $stmt_del->bind_param($types, ...$ids_limpios);
                    if ($stmt_del->execute()) flash_redirect(count($ids_limpios) . ' tickets borrados de la BD.', false, $current_url);
                } elseif ($accion === 'restaurar') {
                    $stmt_up = $conn->prepare("UPDATE reclamos_sugerencias SET estado='pendiente' WHERE id IN ($placeholders)");
                    $stmt_up->bind_param($types, ...$ids_limpios);
                    if ($stmt_up->execute()) flash_redirect(count($ids_limpios) . ' tickets restaurados.', false, $current_url);
                } elseif ($accion === 'papelera') {
                    $stmt_up = $conn->prepare("UPDATE reclamos_sugerencias SET estado='eliminado' WHERE id IN ($placeholders)");
                    $stmt_up->bind_param($types, ...$ids_limpios);
                    if ($stmt_up->execute()) flash_redirect(count($ids_limpios) . ' tickets a papelera.', false, $current_url);
                }
            }
        }
    }
    
    flash_redirect('Acción no procesada.', true, $current_url);
}

// 3. CONTADORES OPTIMIZADOS
$contadores = ['activos' => 0, 'resuelto' => 0, 'eliminado' => 0, 'todos' => 0];
$res_count = $conn->query("SELECT estado, COUNT(*) AS total FROM reclamos_sugerencias GROUP BY estado");
if ($res_count) {
    while ($row = $res_count->fetch_assoc()) {
        // Pendientes y en_proceso van al mismo contador "activos"
        if (in_array($row['estado'], ['pendiente', 'en_proceso'], true)) {
            $contadores['activos'] += (int)$row['total'];
        } elseif (isset($contadores[$row['estado']])) {
            $contadores[$row['estado']] = (int)$row['total'];
        }
        if ($row['estado'] !== 'eliminado') $contadores['todos'] += (int)$row['total'];
    }
}

// 4. EXTRAER TICKETS MAESTROS
$sql_base = "SELECT r.id, r.fecha, r.texto, r.estado, r.respuesta_admin, a.nombre AS usuario_raw, a.foto_perfil 
             FROM reclamos_sugerencias r JOIN alumnos a ON r.usuario_id = a.id ";

if ($estado_filtro === 'todos') {
    $sql_where = "WHERE r.estado != 'eliminado'";
    $stmt = $conn->prepare($sql_base . $sql_where . " ORDER BY r.fecha DESC");
} elseif ($estado_filtro === 'activos') {
    // "Activos" = pendientes + en_proceso (tickets vivos en conversación)
    $sql_where = "WHERE r.estado IN ('pendiente', 'en_proceso')";
    $stmt = $conn->prepare($sql_base . $sql_where . " ORDER BY r.fecha DESC");
} else {
    $sql_where = "WHERE r.estado = ?";
    $stmt = $conn->prepare($sql_base . $sql_where . " ORDER BY r.fecha DESC");
    $stmt->bind_param('s', $estado_filtro);
}
$stmt->execute();
$tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 5. MAPEO DE HILOS Y TOLERANCIA A FALLOS
if (!empty($tickets)) {
    $ids = array_column($tickets, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $stmt_msg = $conn->prepare("SELECT * FROM reclamos_mensajes WHERE reclamo_id IN ($placeholders) ORDER BY fecha ASC");
    $stmt_msg->bind_param($types, ...$ids);
    $stmt_msg->execute();
    $res_msg = $stmt_msg->get_result();

    $mensajes_bd = [];
    while ($row = $res_msg->fetch_assoc()) {
        $mensajes_bd[$row['reclamo_id']][] = $row;
    }
    $stmt_msg->close();

    foreach ($tickets as &$t) {
        $hilo = [['remitente' => 'usuario', 'mensaje' => $t['texto'], 'fecha' => $t['fecha']]];
        
        if (!empty($t['respuesta_admin'])) {
            $is_dup = false;
            if (!empty($mensajes_bd[$t['id']])) {
                foreach ($mensajes_bd[$t['id']] as $mt) {
                    if ($mt['remitente'] === 'admin' && trim($mt['mensaje']) === trim($t['respuesta_admin'])) { $is_dup = true; break; }
                }
            }
            if (!$is_dup) $hilo[] = ['remitente' => 'admin', 'mensaje' => $t['respuesta_admin'], 'fecha' => $t['fecha']];
        }
        
        if (!empty($mensajes_bd[$t['id']])) { foreach ($mensajes_bd[$t['id']] as $m) $hilo[] = $m; }
        
        usort($hilo, fn($a, $b) => strtotime($a['fecha']) <=> strtotime($b['fecha']));
        $t['chat_thread'] = $hilo;
        $t['urgente'] = ($t['estado'] === 'pendiente' && (time() - strtotime($t['fecha'])) > 86400);
    }
    unset($t);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
    <title>Gestión de Tickets | Nubira 2.0</title>
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; -webkit-tap-highlight-color: transparent; background-color: #f8fafc; overflow-x: hidden; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        @media screen and (max-width: 768px) { input, select, textarea { font-size: 16px !important; } }
        
        /* Acordeón */
        .acordeon-contenido { transition: max-height 0.35s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease; max-height: 0; opacity: 0; overflow: hidden; }
        .acordeon-contenido.open { opacity: 1; }
        
        /* Bottom Bar */
        .bottom-action-bar { transform: translateY(120%); transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
        .bottom-action-bar.show { transform: translateY(0); }

        /* Animación iOS Slide-in para Checkboxes */
        .select-mode-cb { width: 0; opacity: 0; overflow: hidden; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); transform: translateX(-10px); }
        body.selection-mode .select-mode-cb { width: 1.5rem; opacity: 1; margin-right: 0.75rem; transform: translateX(0); }
        body.selection-mode .chevron-arrow { display: none; }
        
        /* Select All Bar Top */
        #select-all-bar { display: none; }
        body.selection-mode #select-all-bar { display: flex; }
    </style>
</head>
<body class="text-gray-800 antialiased pb-28 md:pb-10">

    <?php 
    if (file_exists(__DIR__ . '/componentes/header.php')) require_once __DIR__ . '/componentes/header.php'; 
    if (file_exists(__DIR__ . '/componentes/sidebar.php')) require_once __DIR__ . '/componentes/sidebar.php'; 
    ?>

    <main class="pt-20 md:pt-24 lg:ml-64 px-0 md:px-6 w-full max-w-[1000px] mx-auto">
        
        <div class="px-4 md:px-0 mb-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Gestión de Reclamos</h1>
                <p class="text-xs font-medium text-gray-500 mt-0.5">Soporte y resoluciones administrativas</p>
            </div>
            <div class="flex items-center gap-3 shrink-0">
                <button type="button" id="btn-toggle-select" class="bg-white hover:bg-gray-50 text-gray-700 font-semibold text-sm px-4 py-2.5 rounded-xl border border-gray-200 transition-all active:scale-95 flex items-center gap-2">
                    <i class="fa-solid fa-list-check"></i><span class="hidden sm:inline">Seleccionar</span>
                </button>
            </div>
        </div>

        <?php if ($mensaje_feedback): ?>
          <div id="toast" class="mx-4 md:mx-0 mb-4 rounded-xl px-4 py-3 border flex items-center gap-3 <?= !$error ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200'; ?> transition-all duration-300">
            <?= icon(!$error ? 'check-circle' : 'exclamation', 'w-5 h-5 shrink-0') ?>
            <span class="font-semibold text-sm flex-1"><?= htmlspecialchars($mensaje_feedback) ?></span>
            <button onclick="document.getElementById('toast').remove()" class="text-xs font-bold uppercase tracking-wider underline hover:no-underline shrink-0">Cerrar</button>
          </div>
        <?php endif; ?>

        <!-- Filtros y Búsqueda (Flat Design) -->
        <div class="px-4 md:px-0 mb-4 grid grid-cols-1 md:grid-cols-2 gap-4 items-center">
            <div class="flex gap-2 overflow-x-auto no-scrollbar">
                <?php
             $opciones = [
    'activos'   => ['label' => 'Activos',    'count' => $contadores['activos']],
    'resuelto'  => ['label' => 'Resueltos',  'count' => $contadores['resuelto']],
    'todos'     => ['label' => 'Todos',      'count' => $contadores['todos']],
    'eliminado' => ['label' => 'Papelera',   'count' => $contadores['eliminado']],
];
                foreach ($opciones as $val => $opt):
                    $active = ($estado_filtro === $val);
                    $cls = $active ? 'bg-gray-900 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50';
                ?>
                <a href="?estado=<?= $val ?>" class="shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wide transition-all <?= $cls ?>">
                    <?= $opt['label'] ?>
                    <?php if ($opt['count'] > 0): ?>
                        <span class="<?= $active ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-500' ?> text-[10px] px-1.5 py-0.5 rounded-md"><?= $opt['count'] ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
            
            <div class="relative w-full">
                <input type="search" id="search-input" placeholder="Buscar ticket o usuario..." class="w-full bg-gray-50 border border-gray-200 rounded-xl pl-10 pr-4 py-2.5 text-sm font-medium text-gray-900 placeholder:text-gray-400 focus:ring-1 focus:ring-[#54A6D8] focus:border-[#54A6D8] outline-none transition-all" />
                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
            </div>
        </div>

        <!-- LEDGER LIST -->
        <div class="bg-white md:rounded-2xl border-y md:border border-gray-100 overflow-hidden mb-4">
            
            <!-- CAJA "SELECCIONAR TODOS" (Dinámica) -->
            <div id="select-all-bar" class="items-center px-4 md:px-5 py-3 border-b border-gray-100 bg-blue-50/50 transition-all">
                <div class="flex items-center gap-3 cursor-pointer select-none" onclick="toggleSelectAll()">
                    <input type="checkbox" id="cb-select-all" class="w-4 h-4 text-[#54A6D8] border-gray-300 rounded focus:ring-[#54A6D8] pointer-events-none accent-[#54A6D8]">
                    <span class="text-xs font-bold text-gray-800 uppercase tracking-wide select-all-text">Seleccionar Todos</span>
                </div>
            </div>

            <?php if (empty($tickets)): ?>
                <div class="p-12 text-center flex flex-col items-center">
                    <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center text-gray-300 mb-3"><i class="fa-solid fa-inbox text-2xl"></i></div>
                    <h3 class="text-gray-900 font-bold text-sm">Bandeja limpia</h3>
                    <p class="text-xs text-gray-500 mt-1">No hay tickets en este estado.</p>
                </div>
            <?php else: ?>
                <ul class="divide-y divide-gray-100" id="tickets-list">
                    <?php foreach ($tickets as $t): 
                        $nombre_privado = format_name_privacy($t['usuario_raw']);
                        
                        $ultimo_msg = end($t['chat_thread']);
                        $prefijo = ($ultimo_msg['remitente'] === 'admin') ? 'Tú: ' : '';
                        $texto_bruto = str_replace("\n", " ", $ultimo_msg['mensaje']);
                        
                        $asunto = "Soporte General";
                        if (strpos($t['texto'], ":\n") !== false) {
                            $asunto = explode(":\n", $t['texto'], 2)[0];
                            if ($ultimo_msg === $t['chat_thread'][0]) {
                                $texto_bruto = explode(":\n", $texto_bruto, 2)[1] ?? $texto_bruto;
                            }
                        }

                        $preview_final = $prefijo . trim($texto_bruto);
                        $search_data = strtolower($t['usuario_raw'] . ' ' . $t['texto'] . ' ' . $preview_final);
                    ?>
                    <li class="ledger-row relative group bg-white transition-colors" data-id="<?= $t['id'] ?>" data-search="<?= htmlspecialchars($search_data) ?>">
                        
                        <!-- FILA TOUCH / LONG PRESS -->
                        <div class="flex items-center w-full px-4 md:px-5 py-4 cursor-pointer select-none touch-area active:bg-gray-50 md:hover:bg-gray-50 transition-colors">
                            
                            <div class="select-mode-cb shrink-0 flex items-center justify-center">
                                <input type="checkbox" value="<?= $t['id'] ?>" class="ticket-cb w-4 h-4 text-[#54A6D8] border-gray-300 rounded focus:ring-[#54A6D8] cursor-pointer pointer-events-none accent-[#54A6D8]">
                            </div>

                            <div class="relative shrink-0 mr-3">
                                <?php if (!empty($t['foto_perfil'])): ?>
                                    <img src="<?= htmlspecialchars($t['foto_perfil']) ?>" class="w-10 h-10 rounded-xl object-cover border border-gray-100" onerror="this.outerHTML='<div class=\'w-10 h-10 rounded-xl bg-[#54A6D8] flex items-center justify-center text-white font-bold\'><?= substr($t['usuario_raw'],0,1) ?></div>'">
                                <?php else: ?>
                                    <div class="w-10 h-10 rounded-xl bg-[#54A6D8] flex items-center justify-center text-white font-bold"><?= substr($t['usuario_raw'],0,1) ?></div>
                                <?php endif; ?>
                                <?php if ($t['urgente']): ?><span class="absolute -top-1 -right-1 w-3 h-3 rounded-full bg-red-500 border-2 border-white"></span><?php endif; ?>
                            </div>

                            <div class="flex-1 min-w-0 pr-4">
                                <div class="flex justify-between items-end mb-0.5">
                                    <h4 class="text-sm font-bold text-gray-900 truncate"><?= htmlspecialchars($nombre_privado) ?></h4>
                                    <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wide shrink-0"><?= date('d M, H:i', strtotime($ultimo_msg['fecha'])) ?></span>
                                </div>
                                <p class="text-xs text-gray-900 font-semibold truncate"><?= htmlspecialchars($asunto) ?></p>
                                <p class="text-xs <?= $ultimo_msg['remitente'] === 'usuario' ? 'text-gray-800 font-medium' : 'text-gray-500 font-normal' ?> truncate">
                                    <?= htmlspecialchars($preview_final) ?>
                                </p>
                            </div>

                            <div class="shrink-0 flex items-center justify-end">
                                <!-- Botones de acción rápida por fila -->
                                <?php if ($estado_filtro === 'eliminado'): ?>
                                    <form method="POST" class="m-0 p-0 prevent-click" onsubmit="return confirm('¿Restaurar este ticket?');">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                                        <input type="hidden" name="accion_simple" value="restaurar">
                                        <button type="submit" class="w-8 h-8 rounded-xl mr-1 text-gray-400 hover:text-green-600 hover:bg-green-50 flex items-center justify-center transition-colors active:scale-95" title="Restaurar ticket">
                                            <i class="fa-solid fa-recycle text-sm"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="m-0 p-0 prevent-click" onsubmit="return confirm('¿ELIMINAR DEFINITIVAMENTE DE LA BD? Esto borrará el ticket y sus respuestas sin posibilidad de recuperación.');">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                                        <input type="hidden" name="accion_simple" value="eliminar_hard">
                                        <button type="submit" class="w-8 h-8 rounded-xl text-gray-400 hover:text-red-600 hover:bg-red-50 flex items-center justify-center transition-colors active:scale-95" title="Borrado físico de BD">
                                            <i class="fa-solid fa-trash-can text-sm"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" class="m-0 p-0 prevent-click" onsubmit="return confirm('¿Mover este ticket a la papelera?');">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                                        <input type="hidden" name="accion_simple" value="papelera">
                                        <button type="submit" class="w-8 h-8 rounded-xl text-gray-400 hover:text-red-600 hover:bg-red-50 flex items-center justify-center transition-colors active:scale-95" title="Eliminar ticket">
                                            <i class="fa-solid fa-trash-can text-sm"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ACORDEÓN -->
                        <div class="acordeon-contenido bg-gray-50/50">
                            <div class="p-4 md:p-6 border-t border-gray-100">
                                <div class="chat-container flex flex-col gap-4 max-h-[400px] overflow-y-auto no-scrollbar pb-4 scroll-smooth" id="scroll-<?= $t['id'] ?>">
                                    <?php foreach ($t['chat_thread'] as $msg): 
                                        $es_admin = $msg['remitente'] === 'admin';
                                        
                                        $texto_burbuja = $msg['mensaje'];
                                        if (!$es_admin && $msg === $t['chat_thread'][0] && strpos($texto_burbuja, ":\n") !== false) {
                                            $texto_burbuja = explode(":\n", $texto_burbuja, 2)[1];
                                        }
                                    ?>
                                        <div class="flex flex-col <?= $es_admin ? 'items-end' : 'items-start' ?> w-full">
                                            <span class="text-[10px] <?= $es_admin ? 'text-[#54A6D8]' : 'text-gray-500' ?> font-bold mb-1 uppercase tracking-wide px-1">
                                                <?= $es_admin ? 'Tú' : htmlspecialchars($nombre_privado) ?> • <?= date('H:i', strtotime($msg['fecha'])) ?>
                                            </span>
                                            <div class="<?= $es_admin ? 'bg-[#54A6D8] text-white rounded-tr-sm' : 'bg-white border border-gray-200 text-gray-700 rounded-tl-sm' ?> p-4 rounded-2xl text-[13px] font-medium max-w-[90%] md:max-w-[80%] break-words">
                                                <?= nl2br(htmlspecialchars(trim($texto_burbuja))) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <form method="post" class="mt-2 bg-white rounded-xl border border-gray-200 focus-within:border-[#54A6D8] focus-within:ring-1 focus-within:ring-[#54A6D8] transition-all flex flex-col">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">

                                    <textarea name="respuesta_admin" rows="1" placeholder="Respuesta oficial..." required class="auto-resize w-full bg-transparent border-none px-4 py-3 text-sm text-gray-900 outline-none resize-none placeholder-gray-400 max-h-[120px]"></textarea>
                                    
                                    <div class="flex items-center justify-between px-3 pb-2 pt-1 mt-1">
                                        <button name="resolver" type="submit" formnovalidate class="text-xs font-bold text-gray-500 hover:text-green-600 uppercase tracking-wide px-3 py-2 transition-colors rounded-lg">Cerrar ticket</button>
                                        
                                        <button name="responder" type="submit" class="bg-gray-900 hover:bg-black text-white font-bold py-2 px-5 rounded-lg text-xs uppercase tracking-wide transition-all active:scale-95 flex items-center justify-center gap-2">
                                            Enviar <i class="fa-solid fa-paper-plane text-[10px]"></i>
                                        </button>
                                    </div>
                                </form>

                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </main>

    <!-- BARRA ELIMINACIÓN MÚLTIPLE (Flat Design) -->
    <form method="post" id="bulk-form" class="bottom-action-bar fixed bottom-20 md:bottom-6 left-4 right-4 md:left-auto md:right-6 md:w-auto z-40 m-0">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="tickets_seleccionados" id="bulk-ids" value="">

        <div class="bg-gray-900 text-white rounded-2xl shadow-lg pl-4 pr-4 py-3 flex items-center justify-between gap-4 md:gap-6 md:max-w-md md:ml-auto border border-gray-800">
            <div class="flex items-center gap-3">
                <button type="button" onclick="exitSelectionMode()" class="w-10 h-10 flex items-center justify-center rounded-xl bg-white/10 hover:bg-white/20 transition-colors active:scale-95"><i class="fa-solid fa-xmark"></i></button>
                <div class="hidden sm:block">
                    <div class="text-sm font-bold"><span id="selected-count">0</span> items</div>
                    <div class="text-[10px] text-gray-400 font-bold uppercase tracking-wide">Seleccionados</div>
                </div>
            </div>
            
            <div class="flex items-center gap-2">
                <?php if ($estado_filtro === 'eliminado'): ?>
                    <button type="submit" name="accion_lote" value="restaurar" onclick="return confirm('¿Restaurar los tickets seleccionados?');" class="text-green-400 hover:text-green-300 text-xs font-bold uppercase tracking-wide px-3 py-2 transition-colors active:scale-95 flex items-center gap-2 bg-white/10 hover:bg-white/20 rounded-xl">
                        Restaurar <i class="fa-solid fa-recycle text-base"></i>
                    </button>
                    <button type="submit" name="accion_lote" value="eliminar_hard" onclick="return confirm('⚠️ ATENCIÓN: ¿Borrar DEFINITIVAMENTE los tickets seleccionados de la base de datos? Esto no se puede deshacer.');" class="text-red-400 hover:text-red-300 text-xs font-bold uppercase tracking-wide px-3 py-2 transition-colors active:scale-95 flex items-center gap-2 bg-white/10 hover:bg-white/20 rounded-xl">
                        Borrar DB <i class="fa-solid fa-trash-can text-base"></i>
                    </button>
                <?php else: ?>
                    <button type="submit" name="accion_lote" value="papelera" onclick="return confirm('¿Mover tickets seleccionados a la papelera?');" class="text-red-400 hover:text-red-300 text-xs font-bold uppercase tracking-wide px-3 py-2 transition-colors active:scale-95 flex items-center gap-2 bg-white/10 hover:bg-white/20 rounded-xl">
                        A Papelera <i class="fa-solid fa-trash-can text-base"></i>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <?php if (file_exists(__DIR__ . '/componentes/nav_bottom.php')) require_once __DIR__ . '/componentes/nav_bottom.php'; ?>

    <script>
    const searchInput = document.getElementById('search-input');
    if(searchInput) {
        searchInput.addEventListener('input', (e) => {
            const term = e.target.value.toLowerCase().trim();
            document.querySelectorAll('.ledger-row').forEach(row => {
                const match = term === '' || row.dataset.search.includes(term);
                row.style.display = match ? 'block' : 'none';
            });
            // Deseleccionar al buscar para evitar inconsistencias
            exitSelectionMode();
        });
    }

    document.querySelectorAll('.auto-resize').forEach(ta => {
        ta.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });
    });

    let pressTimer;
    let isSelectionMode = false;
    const body = document.body;

    // BOTÓN EXPLÍCITO DE SELECCIÓN EN CABECERA
    document.getElementById('btn-toggle-select').addEventListener('click', () => {
        if (isSelectionMode) {
            exitSelectionMode();
        } else {
            isSelectionMode = true;
            body.classList.add('selection-mode');
            document.querySelectorAll('.acordeon-contenido').forEach(c => { c.style.maxHeight = '0px'; c.classList.remove('open'); });
            updateSelectionCount();
        }
    });

    // CAJA CHECK "SELECCIONAR TODOS"
    function toggleSelectAll() {
        const selectAllCb = document.getElementById('cb-select-all');
        const isChecked = !selectAllCb.checked;
        selectAllCb.checked = isChecked;

        document.querySelectorAll('.ledger-row').forEach(row => {
            // Solo marcar los visibles (respeta el buscador)
            if(row.style.display !== 'none') {
                const cb = row.querySelector('.ticket-cb');
                cb.checked = isChecked;
                if (isChecked) row.classList.add('bg-blue-50/50');
                else row.classList.remove('bg-blue-50/50');
            }
        });
        
        document.querySelector('.select-all-text').textContent = isChecked ? 'Deseleccionar Todos' : 'Seleccionar Todos';
        updateSelectionCount();
    }

    document.querySelectorAll('.touch-area').forEach(area => {
        area.querySelectorAll('.prevent-click').forEach(frm => {
            frm.addEventListener('click', e => e.stopPropagation());
        });

        area.addEventListener('touchstart', (e) => {
            if (isSelectionMode || e.target.closest('.prevent-click')) return;
            pressTimer = setTimeout(() => { triggerSelectionMode(area.closest('li')); }, 400);
        }, {passive: true});
        area.addEventListener('touchend', () => clearTimeout(pressTimer));
        area.addEventListener('touchmove', () => clearTimeout(pressTimer));

        area.addEventListener('mousedown', (e) => {
            if (isSelectionMode || e.target.closest('.prevent-click')) return;
            pressTimer = setTimeout(() => { triggerSelectionMode(area.closest('li')); }, 400);
        });
        area.addEventListener('mouseup', () => clearTimeout(pressTimer));
        area.addEventListener('mouseleave', () => clearTimeout(pressTimer));

        area.addEventListener('click', (e) => {
            if (e.target.closest('.prevent-click')) return;
            e.preventDefault();
            const row = area.closest('li');
            
            if (isSelectionMode) {
                const cb = row.querySelector('.ticket-cb');
                cb.checked = !cb.checked;
                row.classList.toggle('bg-blue-50/50', cb.checked);
                updateSelectionCount();
            } else {
                toggleAccordion(row);
            }
        });
    });

    function triggerSelectionMode(firstRow) {
        if (navigator.vibrate) navigator.vibrate(50);
        isSelectionMode = true;
        body.classList.add('selection-mode');
        
        document.querySelectorAll('.acordeon-contenido').forEach(c => {
            c.style.maxHeight = '0px';
            c.classList.remove('open');
        });

        const cb = firstRow.querySelector('.ticket-cb');
        cb.checked = true;
        firstRow.classList.add('bg-blue-50/50');
        updateSelectionCount();
    }

    function exitSelectionMode() {
        isSelectionMode = false;
        body.classList.remove('selection-mode');
        
        // Reset check "Todos"
        const selectAllCb = document.getElementById('cb-select-all');
        if(selectAllCb) { selectAllCb.checked = false; document.querySelector('.select-all-text').textContent = 'Seleccionar Todos'; }

        document.querySelectorAll('.ticket-cb').forEach(cb => cb.checked = false);
        document.querySelectorAll('.ledger-row').forEach(row => row.classList.remove('bg-blue-50/50'));
        updateSelectionCount();
    }

    function updateSelectionCount() {
        const checked = document.querySelectorAll('.ticket-cb:checked');
        const count = checked.length;
        const countDisplay = document.getElementById('selected-count');
        if(countDisplay) countDisplay.textContent = count;
        
        document.getElementById('bulk-ids').value = JSON.stringify(Array.from(checked).map(cb => cb.value));
        
        const bar = document.getElementById('bulk-form');
        if (count > 0) bar.classList.add('show');
        else bar.classList.remove('show');
    }

    function toggleAccordion(row) {
        const content = row.querySelector('.acordeon-contenido');
        const scrollArea = content.querySelector('.chat-container');
        const isOpen = content.classList.contains('open');

        document.querySelectorAll('.acordeon-contenido').forEach(c => {
            c.style.maxHeight = '0px';
            c.classList.remove('open');
        });

        if (!isOpen) {
            content.classList.add('open');
            content.style.maxHeight = (content.scrollHeight + 400) + 'px';
            
            if(scrollArea) {
                setTimeout(() => scrollArea.scrollTop = scrollArea.scrollHeight, 150);
            }
        }
    }
    </script>
</body>
</html>