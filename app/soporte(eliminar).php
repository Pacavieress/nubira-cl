<?php
/**
 * VISTA: CENTRO DE AYUDA (NUBIRA 2.0)
 * ROL: Lead Full Stack & UX/UI
 * ESTADO: Mobile-First Real, Ledger List, Long Press Haptic, Soft Delete, Hilo de Chat Nativo.
 */
session_start();

// 1. SEGURIDAD Y RUTAS
if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit; }

$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists($app_dir . '/app/conexion.php')) $app_dir = $app_dir . '/app';
    elseif (file_exists(dirname($app_dir) . '/app')) $app_dir = dirname($app_dir) . '/app';
}
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

// 2. DATOS DE SESIÓN
$usuario_id     = (int)$_SESSION['usuario_id'];
$email_user     = $_SESSION['correo'] ?? ($_SESSION['email'] ?? '');
$rol            = $_SESSION['rol'] ?? 'alumno';
$nombre_usuario = $_SESSION['usuario_nombre'] ?? 'Usuario';

// Catálogo Maestro de Categorías
$CATEGORIAS = [
    'tecnico'    => ['label' => 'Error técnico',     'color' => 'red',     'icon' => 'fa-bug'],
    'chat'       => ['label' => 'Problema con chat', 'color' => 'indigo',  'icon' => 'fa-comments'],
    'pago'       => ['label' => 'Pago o cobro',      'color' => 'emerald', 'icon' => 'fa-credit-card'],
    'apunte'     => ['label' => 'Apunte o servicio', 'color' => 'amber',   'icon' => 'fa-book'],
    'cuenta'     => ['label' => 'Mi cuenta',         'color' => 'sky',     'icon' => 'fa-user'],
    'sugerencia' => ['label' => 'Sugerencia',        'color' => 'violet',  'icon' => 'fa-lightbulb'],
    'otro'       => ['label' => 'Otra consulta',     'color' => 'slate',   'icon' => 'fa-circle-question'],
];
$CATEGORIAS_VALIDAS = array_keys($CATEGORIAS);

$page_title = "Centro de Ayuda";

// 3. CSRF & LÓGICA DE FORMULARIOS
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

// 3.0 ENDPOINT AJAX: MARCAR TICKET COMO LEÍDO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marcar_leido'])) {
    header('Content-Type: application/json');
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }
    $tid = (int)($_POST['ticket_id'] ?? 0);
    if ($tid > 0) {
        $stmt = $conn->prepare("UPDATE reclamos_sugerencias SET revisado_usuario = 0 WHERE id = ? AND usuario_id = ?");
        $stmt->bind_param('ii', $tid, $usuario_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['ok' => true]); exit;
    }
    echo json_encode(['ok' => false, 'error' => 'invalid_id']); exit;
}

$mensaje_feedback = '';
$error = false;

// 3A. CREAR NUEVO TICKET
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_reclamo'])) {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $mensaje_feedback = 'Sesión inválida. Recarga la página.';
        $error = true;
    } else {
        $asunto    = trim($_POST['asunto'] ?? '');
        $mensaje   = trim($_POST['mensaje'] ?? '');
        $categoria = trim($_POST['categoria'] ?? '');
        if (!in_array($categoria, $CATEGORIAS_VALIDAS, true)) $categoria = 'otro';

        if (!$asunto || !$mensaje) {
            $mensaje_feedback = 'Debes completar el asunto y el mensaje.';
            $error = true;
        } else {
            try {
                $texto_completo = strtoupper($asunto) . ":\n" . $mensaje;
                $stmt = $conn->prepare("INSERT INTO reclamos_sugerencias (usuario_id, texto, categoria, fecha, estado, revisado_usuario) VALUES (?, ?, ?, NOW(), 'pendiente', 1)");
                $stmt->bind_param("iss", $usuario_id, $texto_completo, $categoria);
                if ($stmt->execute()) {
                    $mensaje_feedback = 'Tu solicitud ha sido enviada. Te ayudaremos pronto.';
                    $error = false;
                } else { throw new Exception($stmt->error); }
                $stmt->close();
            } catch (Exception $e) {
                $mensaje_feedback = 'Error al enviar. Intenta más tarde.';
                $error = true;
            }
        }
    }
}

// 3B. RESPONDER A UN TICKET
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['responder_ticket'])) {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $mensaje_feedback = 'Sesión inválida. Recarga la página.';
        $error = true;
    } else {
        $ticket_id       = (int)$_POST['ticket_id'];
        $mensaje_usuario = trim($_POST['mensaje_usuario'] ?? '');

        if ($ticket_id > 0 && !empty($mensaje_usuario)) {
            $stmt_val = $conn->prepare("SELECT id FROM reclamos_sugerencias WHERE id = ? AND usuario_id = ?");
            $stmt_val->bind_param('ii', $ticket_id, $usuario_id);
            $stmt_val->execute();
            if ($stmt_val->get_result()->num_rows > 0) {
                $conn->begin_transaction();
                try {
                    $stmt_in = $conn->prepare("INSERT INTO reclamos_mensajes (reclamo_id, remitente, mensaje, fecha) VALUES (?, 'usuario', ?, NOW())");
                    $stmt_in->bind_param('is', $ticket_id, $mensaje_usuario);
                    $stmt_in->execute();
                    $stmt_in->close();

                    $stmt_up = $conn->prepare("UPDATE reclamos_sugerencias SET estado = 'pendiente', revisado_usuario = 1 WHERE id = ?");
                    $stmt_up->bind_param('i', $ticket_id);
                    $stmt_up->execute();
                    $stmt_up->close();

                    $conn->commit();
                    $mensaje_feedback = 'Tu respuesta ha sido enviada a soporte.';
                    $error = false;
                } catch (Exception $e) {
                    $conn->rollback();
                }
            }
            $stmt_val->close();
        }
    }
}

// 3C. ELIMINAR TICKET(S) (Soft Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_eliminar'])) {
    if (hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $ids_a_eliminar = [];
        if (!empty($_POST['ticket_id'])) {
            $ids_a_eliminar[] = (int)$_POST['ticket_id'];
        } elseif (!empty($_POST['tickets_seleccionados'])) {
            $ids_a_eliminar = json_decode($_POST['tickets_seleccionados'], true);
        }

        if (is_array($ids_a_eliminar) && count($ids_a_eliminar) > 0) {
            $ids_limpios = array_filter(array_map('intval', $ids_a_eliminar), fn($v) => $v > 0);
            if (!empty($ids_limpios)) {
                $placeholders = implode(',', array_fill(0, count($ids_limpios), '?'));
                $types = str_repeat('i', count($ids_limpios)) . 'i'; // +1 para el usuario_id (Seguridad)
                
                $stmt_del = $conn->prepare("UPDATE reclamos_sugerencias SET estado = 'eliminado' WHERE id IN ($placeholders) AND usuario_id = ?");
                $bind_params = array_merge([$types], $ids_limpios, [$usuario_id]);
                $tmp = [];
                foreach ($bind_params as $key => $value) $tmp[$key] = &$bind_params[$key];
                call_user_func_array([$stmt_del, 'bind_param'], $tmp);
                
                if ($stmt_del->execute()) {
                    $mensaje_feedback = '🗑️ ' . count($ids_limpios) . ' ticket(s) eliminado(s) correctamente.';
                    $error = false;
                }
                $stmt_del->close();
            }
        }
    }
}

// 3D. MARCAR COMO RESUELTO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marcar_resuelto'])) {
    if (hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $ticket_id = (int)$_POST['ticket_id'];
        if ($ticket_id > 0) {
            $stmt = $conn->prepare("UPDATE reclamos_sugerencias SET estado = 'resuelto', revisado_usuario = 0 WHERE id = ? AND usuario_id = ?");
            $stmt->bind_param('ii', $ticket_id, $usuario_id);
            $stmt->execute();
            $stmt->close();
            $mensaje_feedback = 'Ticket cerrado correctamente. ¡Gracias!';
            $error = false;
        }
    }
}

// 4. OBTENER HISTORIAL DE TICKETS MAESTROS
$sql_history = "SELECT id, fecha AS fecha_creacion, categoria, texto AS mensaje, respuesta_admin AS respuesta, estado, revisado_usuario 
                FROM reclamos_sugerencias 
                WHERE usuario_id = ? AND estado != 'eliminado' 
                ORDER BY fecha DESC";
$stmt = $conn->prepare($sql_history);
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Construcción del hilo relacional
if (!empty($tickets)) {
    $ids = array_column($tickets, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $stmt_msg = $conn->prepare("SELECT * FROM reclamos_mensajes WHERE reclamo_id IN ($placeholders) ORDER BY fecha ASC");
    $stmt_msg->bind_param($types, ...$ids);
    $stmt_msg->execute();
    $res_msg = $stmt_msg->get_result();

    $mensajes_bd = [];
    while ($row = $res_msg->fetch_assoc()) $mensajes_bd[$row['reclamo_id']][] = $row;
    $stmt_msg->close();

    foreach ($tickets as &$t) {
        $hilo = [['remitente' => 'usuario', 'mensaje' => $t['mensaje'], 'fecha' => $t['fecha_creacion']]];
        $mensajes_ticket = $mensajes_bd[$t['id']] ?? [];

        // Legacy Fallback
        if (!empty($t['respuesta'])) {
            $is_dup = false;
            foreach ($mensajes_ticket as $mt) {
                if ($mt['remitente'] === 'admin' && trim($mt['mensaje']) === trim($t['respuesta'])) { $is_dup = true; break; }
            }
            if (!$is_dup) $hilo[] = ['remitente' => 'admin', 'mensaje' => $t['respuesta'], 'fecha' => $t['fecha_creacion']];
        }

        foreach ($mensajes_ticket as $mt) $hilo[] = $mt;
        usort($hilo, fn($a, $b) => strtotime($a['fecha']) <=> strtotime($b['fecha']));
        $t['chat_thread'] = $hilo;
    }
    unset($t);
}

// Contadores para chips
$cnt_total = count($tickets);
$cnt_activos = 0; $cnt_resueltos = 0; $cnt_no_leidos = 0;
foreach ($tickets as $t) {
    $est = $t['estado'] ?? 'pendiente';
    if ($est === 'resuelto' || $est === 'cerrado') $cnt_resueltos++;
    else $cnt_activos++;
    
    $ultimo = end($t['chat_thread']);
    if ($ultimo['remitente'] === 'admin' && (int)$t['revisado_usuario'] === 1) $cnt_no_leidos++;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <title>Centro de Ayuda | Nubira</title>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #f8fafc; -webkit-tap-highlight-color: transparent; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    .force-no-shadow * { text-shadow: none !important; }
    select { -webkit-appearance: none; -moz-appearance: none; appearance: none; }
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

    /* Modal & Chips */
    .modal-backdrop { background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); }
    .modal-card-anim { transition: transform 0.35s cubic-bezier(0.32, 0.72, 0, 1), opacity 0.25s ease; }
    .cat-chip.is-active { background-color: #54A6D8; color: white; border-color: #54A6D8; }
    .cat-chip.is-active .cat-icon { color: white; }

    /* NUBIRA 2.0 - Ledger List y Gestos Nativos */
    .acordeon-contenido { transition: max-height 0.35s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease; max-height: 0; opacity: 0; overflow: hidden; }
    .acordeon-contenido.open { opacity: 1; }
    .bottom-action-bar { transform: translateY(150%); transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
    .bottom-action-bar.show { transform: translateY(0); }

    .select-mode-cb { display: none; opacity: 0; transform: scale(0.8); transition: all 0.2s ease; }
    body.selection-mode .select-mode-cb { display: block; opacity: 1; transform: scale(1); }
    body.selection-mode .chevron-arrow { display: none; }
    body.selection-mode .ledger-row { padding-left: 0.5rem; }

    @keyframes nubiraPulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(84, 166, 216, 0.5); }
      50%      { box-shadow: 0 0 0 6px rgba(84, 166, 216, 0); }
    }
    .pulse-new { animation: nubiraPulse 1.8s ease-in-out infinite; }
  </style>
</head>

<body class="text-slate-800 antialiased overflow-x-hidden force-no-shadow bg-slate-50 pb-28 md:pb-10">

<div id="loader" class="fixed inset-0 bg-white flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-8 w-8 border-4 border-slate-100 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
require_once $app_dir . '/componentes/header.php'; 
require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-16 md:pt-24 md:ml-64 px-0 md:px-8 w-full max-w-4xl mx-auto">
  <div class="space-y-6 md:mt-6">

    <div class="px-4 md:px-0 py-4 mb-2 flex items-start justify-between gap-4">
      <div class="flex-1 min-w-0">
        <h1 class="text-2xl md:text-3xl font-extrabold text-slate-900 tracking-tight leading-tight">Centro de Ayuda</h1>
        <p class="text-slate-500 text-xs md:text-sm font-medium mt-1">Resolvemos tus dudas y problemas.</p>
      </div>
      <button type="button" id="btn-nuevo-ticket" class="shrink-0 bg-slate-900 hover:bg-slate-800 text-white font-bold text-sm px-5 py-3 rounded-2xl transition-all hover:shadow-md active:scale-95 flex items-center gap-2 shadow-sm">
        <i class="fa-solid fa-plus text-xs"></i><span class="hidden sm:inline">Nuevo ticket</span><span class="sm:hidden">Nuevo</span>
      </button>
    </div>

    <?php if ($mensaje_feedback): ?>
      <div id="toast" class="mx-4 md:mx-0 rounded-2xl px-5 py-4 border flex items-center gap-3 <?= !$error ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-red-50 text-red-700 border-red-100'; ?> shadow-sm transition-all duration-300">
        <?= icon(!$error ? 'check-circle' : 'exclamation', 'w-5 h-5 shrink-0') ?>
        <span class="font-bold text-sm flex-1"><?= htmlspecialchars($mensaje_feedback) ?></span>
        <button onclick="document.getElementById('toast').remove()" class="text-xs font-bold uppercase tracking-wider underline hover:no-underline shrink-0">Cerrar</button>
      </div>
    <?php endif; ?>

    <?php if ($cnt_total > 0): ?>
    <div class="px-4 md:px-0">
      <div class="flex items-center gap-2 overflow-x-auto no-scrollbar pb-1">
        <button type="button" data-filtro="todos" class="filtro-chip is-active shrink-0 px-4 py-2 rounded-2xl text-[11px] font-extrabold uppercase tracking-widest border transition-all" style="background-color:#54A6D8; color:white; border-color:#54A6D8;">Todos · <?= $cnt_total ?></button>
        <button type="button" data-filtro="activos" class="filtro-chip shrink-0 px-4 py-2 rounded-2xl text-[11px] font-extrabold uppercase tracking-widest border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 transition-all">Activos · <?= $cnt_activos ?></button>
        <button type="button" data-filtro="resueltos" class="filtro-chip shrink-0 px-4 py-2 rounded-2xl text-[11px] font-extrabold uppercase tracking-widest border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 transition-all">Resueltos · <?= $cnt_resueltos ?></button>
        <?php if ($cnt_no_leidos > 0): ?>
        <button type="button" data-filtro="no_leidos" class="filtro-chip shrink-0 px-4 py-2 rounded-2xl text-[11px] font-extrabold uppercase tracking-widest border border-[#54A6D8]/30 bg-[#54A6D8]/10 text-[#54A6D8] transition-all flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-[#54A6D8] animate-pulse"></span>Sin leer · <?= $cnt_no_leidos ?></button>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- LEDGER LIST EDGE-TO-EDGE -->
    <div class="mt-2">
      <?php if (empty($tickets)): ?>
        <div class="bg-white border-y md:border border-slate-200 md:rounded-3xl p-10 text-center shadow-sm">
          <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-300"><i class="fa-solid fa-headset text-2xl"></i></div>
          <h3 class="text-base font-bold text-slate-800">Aún no tienes tickets</h3>
          <p class="text-slate-500 text-sm mt-1 font-medium max-w-sm mx-auto">Si necesitas ayuda, abre un ticket y te respondemos pronto.</p>
          <button type="button" id="btn-nuevo-ticket-empty" class="mt-5 inline-flex items-center gap-2 bg-slate-900 hover:bg-slate-800 text-white font-bold text-sm px-5 py-3 rounded-2xl transition-all hover:shadow-md active:scale-95"><i class="fa-solid fa-plus text-xs"></i> Crear mi primer ticket</button>
        </div>
      <?php else: ?>
        <div class="bg-white md:rounded-3xl border-y md:border border-slate-200 shadow-sm overflow-hidden">
          <ul class="divide-y divide-slate-100" id="lista-tickets">
            <?php foreach ($tickets as $idx => $t):
              $estado       = $t['estado'] ?? 'pendiente';
              $es_resuelto  = ($estado === 'resuelto' || $estado === 'cerrado');
              $idAcordeon   = 'ticket-' . $t['id'];
              $cat_key      = in_array($t['categoria'], $CATEGORIAS_VALIDAS, true) ? $t['categoria'] : 'otro';
              $cat          = $CATEGORIAS[$cat_key];
              $color        = $cat['color'];

              $ultimo       = end($t['chat_thread']);
              $tiene_nueva  = ($ultimo['remitente'] === 'admin' && (int)$t['revisado_usuario'] === 1);

              $claseEstado = match($estado) {
                  'pendiente'           => 'bg-amber-50 text-amber-700',
                  'en_proceso'          => 'bg-indigo-50 text-indigo-700',
                  'resuelto', 'cerrado' => 'bg-emerald-50 text-emerald-700',
                  default               => 'bg-slate-100 text-slate-600'
              };

              $data_filtro = $es_resuelto ? 'resueltos' : 'activos';
              $mostrar_asunto = '(Sin asunto)';
              if (strpos($t['mensaje'], ":\n") !== false) {
                  $mostrar_asunto = ucfirst(strtolower(explode(":\n", $t['mensaje'], 2)[0]));
              }
            ?>
            <li class="ticket-item ledger-row relative group transition-colors" data-estado="<?= $data_filtro ?>" data-no-leido="<?= $tiene_nueva ? '1' : '0' ?>" data-ticket-id="<?= (int)$t['id'] ?>">
              
              <!-- FILA TOUCH / LONG PRESS -->
              <div class="w-full p-4 md:p-5 flex flex-wrap sm:flex-nowrap items-center gap-3 md:gap-4 hover:bg-slate-50 transition-colors cursor-pointer select-none touch-area">
                
                <div class="select-mode-cb shrink-0">
                    <input type="checkbox" value="<?= (int)$t['id'] ?>" class="ticket-cb w-5 h-5 rounded-md border-slate-300 text-[#54A6D8] focus:ring-[#54A6D8]/20 cursor-pointer pointer-events-none accent-[#54A6D8]">
                </div>

                <div class="flex-1 min-w-0 flex items-center gap-3">
                    <div class="shrink-0 w-10 h-10 rounded-2xl bg-<?= $color ?>-50 flex items-center justify-center text-<?= $color ?>-600 relative">
                        <i class="fa-solid <?= $cat['icon'] ?> text-sm"></i>
                        <?php if ($tiene_nueva): ?><span class="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-[#54A6D8] pulse-new border-2 border-white" id="badge-<?= $t['id'] ?>"></span><?php endif; ?>
                    </div>
                    
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-0.5 flex-wrap">
                            <span class="<?= $claseEstado ?> px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-wider shrink-0 border border-current/10"><?= ucfirst(str_replace('_', ' ', $estado)) ?></span>
                            <?php if ($tiene_nueva): ?><span class="bg-[#54A6D8] text-white px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-wider shrink-0" id="txt-badge-<?= $t['id'] ?>">Nueva respuesta</span><?php endif; ?>
                            <span class="text-[10px] text-slate-400 font-bold uppercase tracking-widest truncate"><?= date('d M Y, H:i', strtotime($t['fecha_creacion'])) ?></span>
                        </div>
                        <h4 class="font-bold text-slate-900 text-[13px] truncate"><?= htmlspecialchars($mostrar_asunto) ?></h4>
                    </div>
                </div>

                <div class="shrink-0 flex items-center gap-1 sm:gap-2 w-full sm:w-auto justify-end mt-2 sm:mt-0 border-t sm:border-0 border-slate-100 pt-2 sm:pt-0">
                    <form method="POST" class="m-0 p-0 prevent-click" onsubmit="return confirm('¿Eliminar este ticket permanentemente?');">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
                        <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                        <input type="hidden" name="accion_eliminar" value="1">
                        <button type="submit" class="w-8 h-8 rounded-full text-slate-300 hover:text-rose-500 hover:bg-rose-50 flex items-center justify-center transition-colors active:scale-95" title="Eliminar ticket">
                            <i class="fa-solid fa-trash-can text-sm"></i>
                        </button>
                    </form>
                    
                    <div class="w-8 h-8 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center group-hover:bg-white transition-colors">
                        <i id="icon-<?= $idAcordeon ?>" class="fa-solid fa-chevron-down text-slate-400 text-[10px] chevron-arrow transition-transform duration-300"></i>
                    </div>
                </div>
              </div>

              <!-- HILO DE CHAT NATIVO -->
              <div id="<?= $idAcordeon ?>" class="acordeon-contenido bg-slate-50/50">
                <div class="p-4 md:p-6 border-t border-slate-100">
                  <div class="chat-container flex flex-col gap-4 max-h-[500px] overflow-y-auto no-scrollbar pb-4 scroll-smooth" id="scroll-<?= $idAcordeon ?>">
                    <?php foreach ($t['chat_thread'] as $msg):
                      $es_usuario = $msg['remitente'] === 'usuario';
                      $texto_burbuja = $msg['mensaje'];
                      if ($es_usuario && $msg === $t['chat_thread'][0] && strpos($texto_burbuja, ":\n") !== false) {
                          $texto_burbuja = explode(":\n", $texto_burbuja, 2)[1];
                      }
                    ?>
                      <div class="flex flex-col <?= $es_usuario ? 'items-end' : 'items-start' ?> w-full">
                        <span class="text-[9px] font-extrabold <?= $es_usuario ? 'text-[#54A6D8]' : 'text-slate-400' ?> uppercase tracking-widest mb-1 px-1">
                          <?= $es_usuario ? 'Tú' : 'Soporte Nubira' ?> · <?= date('d M, H:i', strtotime($msg['fecha'])) ?>
                        </span>
                        <div class="p-4 rounded-3xl text-[13px] font-medium leading-relaxed max-w-[90%] md:max-w-[80%] break-words shadow-sm <?= $es_usuario ? 'bg-[#54A6D8] text-white rounded-tr-sm shadow-[#54A6D8]/20' : 'bg-white text-slate-700 border border-slate-200/80 rounded-tl-sm' ?>">
                          <?= nl2br(htmlspecialchars(trim($texto_burbuja), ENT_QUOTES, 'UTF-8')) ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <?php if (!$es_resuelto): ?>
                    <form method="POST" class="mt-2 bg-white p-2 rounded-3xl border border-slate-200 shadow-sm focus-within:border-[#54A6D8] focus-within:ring-4 focus-within:ring-[#54A6D8]/10 transition-all">
                      <input type="hidden" name="responder_ticket" value="1">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
                      <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                      <div class="flex items-end gap-2">
                        <textarea name="mensaje_usuario" rows="1" placeholder="Escribe tu respuesta a soporte..." required maxlength="2000" class="auto-resize w-full bg-transparent border-none px-4 py-3 text-sm outline-none resize-none font-medium text-slate-800 placeholder-slate-400 max-h-[150px]"></textarea>
                        <button type="submit" class="shrink-0 bg-slate-900 text-white w-10 h-10 rounded-full flex items-center justify-center hover:bg-slate-800 transition-colors active:scale-95 shadow-md mb-1 mr-1">
                          <i class="fa-solid fa-paper-plane text-xs -ml-0.5 mt-0.5"></i>
                        </button>
                      </div>
                    </form>
                    <form method="POST" class="mt-3 text-center">
                      <input type="hidden" name="marcar_resuelto" value="1">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
                      <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                      <button type="submit" onclick="return confirm('¿Marcar este ticket como resuelto? Se cerrará de forma permanente.')" class="text-[10px] font-extrabold text-emerald-600 hover:text-emerald-700 uppercase tracking-widest transition-colors">
                        <i class="fa-solid fa-check mr-1"></i> Dar por resuelto
                      </button>
                    </form>
                  <?php else: ?>
                    <div class="mt-4 text-center p-3 bg-emerald-50/50 rounded-2xl border border-emerald-200 border-dashed text-xs font-bold text-emerald-600 uppercase tracking-widest">
                      <i class="fa-solid fa-check-circle mr-1"></i> Este ticket fue resuelto
                    </div>
                  <?php endif; ?>

                </div>
              </div>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <!-- Empty filtro state -->
        <div id="empty-filtro" class="hidden mt-4 mx-4 md:mx-0 bg-white border border-slate-200 rounded-2xl p-8 text-center">
          <p class="text-sm font-bold text-slate-600">No hay tickets aquí</p>
          <p class="text-xs text-slate-400 mt-1">Prueba con otro filtro</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<!-- MODAL NUEVO TICKET -->
<div id="modal-ticket" class="hidden fixed inset-0 z-[80] modal-backdrop flex items-end md:items-center justify-center p-0 md:p-4">
  <div id="ticket-card" class="modal-card-anim w-full md:max-w-xl bg-white md:rounded-3xl rounded-t-3xl shadow-2xl translate-y-full opacity-0 max-h-[92vh] flex flex-col overflow-hidden">
    <div class="px-5 md:px-6 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
      <div>
        <h2 class="text-lg font-extrabold text-slate-900 tracking-tight">Nuevo ticket</h2>
        <p class="text-xs text-slate-400 font-medium">Cuéntanos qué sucede</p>
      </div>
      <button type="button" id="ticket-close" class="w-9 h-9 rounded-full bg-slate-50 hover:bg-slate-100 flex items-center justify-center transition-colors">
        <i class="fa-solid fa-xmark text-slate-500"></i>
      </button>
    </div>
    <form method="POST" id="form-ticket" class="flex-1 overflow-y-auto no-scrollbar p-5 md:p-6 space-y-5">
      <input type="hidden" name="enviar_reclamo" value="1">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
      <input type="hidden" name="categoria" id="input-categoria" value="otro">

      <div class="space-y-2">
        <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-1">¿Qué tipo de problema es?</label>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
          <?php foreach ($CATEGORIAS as $key => $cat): ?>
            <button type="button" data-cat="<?= $key ?>" class="cat-chip <?= $key === 'otro' ? 'is-active' : '' ?> px-3 py-2.5 rounded-2xl border border-slate-200 bg-white transition-all flex items-center gap-2 text-left">
              <i class="cat-icon fa-solid <?= $cat['icon'] ?> text-xs text-<?= $cat['color'] ?>-500"></i>
              <span class="text-xs font-bold text-slate-700 truncate"><?= htmlspecialchars($cat['label']) ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="space-y-2">
        <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-1">Asunto</label>
        <input type="text" name="asunto" required maxlength="100" class="w-full border border-slate-200 bg-slate-50 px-4 py-3.5 rounded-2xl focus:ring-2 focus:ring-[#54A6D8]/20 focus:border-[#54A6D8] outline-none text-sm font-medium" placeholder="Resume tu problema...">
      </div>
      <div class="space-y-2">
        <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-1">Descripción</label>
        <textarea name="mensaje" required rows="5" maxlength="2000" class="w-full border border-slate-200 bg-slate-50 px-4 py-3.5 rounded-2xl focus:ring-2 focus:ring-[#54A6D8]/20 focus:border-[#54A6D8] outline-none resize-none text-sm font-medium" placeholder="Cuéntanos con detalle..."></textarea>
      </div>
    </form>
    <div class="px-5 md:px-6 py-4 border-t border-slate-100 shrink-0 flex items-center gap-2">
      <button type="button" id="ticket-cancel" class="flex-1 sm:flex-none px-5 py-3 rounded-2xl bg-slate-100 text-slate-700 text-sm font-bold transition-colors">Cancelar</button>
      <button type="submit" form="form-ticket" class="flex-1 px-5 py-3 rounded-2xl bg-slate-900 text-white text-sm font-bold transition-all active:scale-95 flex items-center justify-center gap-2">Enviar ticket <i class="fa-solid fa-paper-plane text-xs"></i></button>
    </div>
  </div>
</div>

<!-- BARRA ELIMINACIÓN MÚLTIPLE -->
<form method="POST" id="bulk-action-bar" class="bottom-action-bar fixed bottom-20 md:bottom-6 left-4 right-4 md:left-auto md:right-6 md:w-auto z-50 m-0" onsubmit="return confirm('¿Eliminar todos los tickets seleccionados?');">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
    <input type="hidden" name="accion_eliminar" value="1">
    <input type="hidden" name="tickets_seleccionados" id="bulk-ids" value="">

    <div class="bg-rose-600 text-white rounded-3xl shadow-2xl shadow-rose-900/30 pl-3 pr-4 py-3 flex items-center justify-between gap-6 md:max-w-md md:ml-auto">
        <div class="flex items-center gap-3">
            <button type="button" onclick="exitSelectionMode()" class="w-10 h-10 flex items-center justify-center rounded-full bg-white/20 hover:bg-white/30 transition-colors active:scale-95"><i class="fa-solid fa-xmark"></i></button>
            <div>
                <div class="text-sm font-extrabold"><span id="selected-count">0</span> items</div>
                <div class="text-[9px] text-rose-200 font-bold uppercase tracking-widest">Seleccionados</div>
            </div>
        </div>
        <button type="submit" class="text-white hover:text-rose-200 text-xs font-extrabold uppercase tracking-widest p-2 transition-colors active:scale-95 flex items-center gap-2">
            Eliminar <i class="fa-solid fa-trash-can text-lg"></i>
        </button>
    </div>
</form>

<?php 
require_once $app_dir . '/componentes/nav_bottom.php'; 
require_once $app_dir . '/componentes/modal_publicar.php'; 
require_once $app_dir . '/componentes/modal_explora.php'; 
?>

<script>
window.onload = () => { const l = document.getElementById('loader'); if (l) { l.style.opacity='0'; setTimeout(()=>l.style.display='none', 300); }};
const CSRF_TOKEN = <?= json_encode($CSRF) ?>;

// --- GESTIÓN TOUCH Y LONG PRESS ---
let pressTimer;
let isSelectionMode = false;
const body = document.body;

document.querySelectorAll('.touch-area').forEach(area => {
    // Aislar clicks dentro del form de borrado simple (Evita que el basurero dispare el acordeón)
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
        if (e.target.closest('.prevent-click')) return; // Respetar basurero
        e.preventDefault();
        const row = area.closest('li');
        
        if (isSelectionMode) {
            const cb = row.querySelector('.ticket-cb');
            cb.checked = !cb.checked;
            row.classList.toggle('bg-indigo-50/50', cb.checked);
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
    
    document.querySelectorAll('.acordeon-contenido').forEach(c => { c.style.maxHeight = '0px'; c.classList.remove('open'); });
    document.querySelectorAll('.chevron-arrow').forEach(i => i.style.transform = 'rotate(0)');

    const cb = firstRow.querySelector('.ticket-cb');
    cb.checked = true;
    firstRow.classList.add('bg-indigo-50/50');
    updateSelectionCount();
}

function exitSelectionMode() {
    isSelectionMode = false;
    body.classList.remove('selection-mode');
    document.querySelectorAll('.ticket-cb').forEach(cb => cb.checked = false);
    document.querySelectorAll('.ledger-row').forEach(row => row.classList.remove('bg-indigo-50/50'));
    updateSelectionCount();
}

function updateSelectionCount() {
    const checked = document.querySelectorAll('.ticket-cb:checked');
    const count = checked.length;
    const bar = document.getElementById('bulk-action-bar');
    
    document.getElementById('selected-count').textContent = count;
    document.getElementById('bulk-ids').value = JSON.stringify(Array.from(checked).map(cb => cb.value));
    
    count > 0 ? bar.classList.add('show') : bar.classList.remove('show');
}

// --- ACORDEÓN ---
function toggleAccordion(row) {
    const content = row.querySelector('.acordeon-contenido');
    const icon = row.querySelector('.chevron-arrow');
    const scrollArea = content.querySelector('.chat-container');
    const isOpen = content.classList.contains('open');

    document.querySelectorAll('.acordeon-contenido').forEach(c => { c.style.maxHeight = '0px'; c.classList.remove('open'); });
    document.querySelectorAll('.chevron-arrow').forEach(i => i.style.transform = 'rotate(0)');

    if (!isOpen) {
        content.classList.add('open');
        content.style.maxHeight = (content.scrollHeight + 400) + 'px';
        icon.style.transform = 'rotate(180deg)';
        
        if(scrollArea) setTimeout(() => scrollArea.scrollTop = scrollArea.scrollHeight, 150);
        
        if (row.dataset.noLeido === '1') marcarComoLeido(row);
    }
}

function marcarComoLeido(row) {
    const tid = row.dataset.ticketId;
    const fd = new FormData();
    fd.append('marcar_leido', '1'); fd.append('ticket_id', tid); fd.append('csrf', CSRF_TOKEN);
    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                row.dataset.noLeido = '0';
                const badge1 = document.getElementById('badge-'+tid);
                const badge2 = document.getElementById('txt-badge-'+tid);
                if(badge1) badge1.remove();
                if(badge2) badge2.remove();
            }
        }).catch(()=>{});
}

// --- CHIPS CATEGORÍAS MODAL ---
document.querySelectorAll('.cat-chip').forEach(chip => {
    chip.addEventListener('click', () => {
        document.querySelectorAll('.cat-chip').forEach(c => c.classList.remove('is-active'));
        chip.classList.add('is-active');
        document.getElementById('input-categoria').value = chip.dataset.cat;
    });
});

// --- FILTROS LISTA ---
const filtroChips = document.querySelectorAll('.filtro-chip');
const ticketItems = document.querySelectorAll('.ticket-item');
const emptyFiltro = document.getElementById('empty-filtro');

filtroChips.forEach(chip => {
    chip.addEventListener('click', () => {
        const filtro = chip.dataset.filtro;
        filtroChips.forEach(c => {
            c.classList.remove('is-active');
            c.style.backgroundColor = ''; c.style.color = ''; c.style.borderColor = '';
            c.classList.add('bg-white', 'text-slate-500', 'border-slate-200');
        });

        chip.classList.add('is-active');
        chip.classList.remove('bg-white', 'text-slate-500', 'border-slate-200');
        chip.style.backgroundColor = '#54A6D8'; chip.style.color = 'white'; chip.style.borderColor = '#54A6D8';

        let visibles = 0;
        ticketItems.forEach(item => {
            const estado = item.dataset.estado;
            const noLeido = item.dataset.noLeido === '1';
            let mostrar = (filtro === 'todos') ? true : 
                          (filtro === 'no_leidos') ? noLeido : (estado === filtro);
            item.style.display = mostrar ? '' : 'none';
            if (mostrar) visibles++;
        });
        if (emptyFiltro) emptyFiltro.classList.toggle('hidden', visibles > 0);
    });
});

// --- TEXTAREA & TOAST ---
document.querySelectorAll('.auto-resize').forEach(ta => {
    ta.addEventListener('input', function() { this.style.height = 'auto'; this.style.height = Math.min(this.scrollHeight, 150) + 'px'; });
});
setTimeout(() => { const t = document.getElementById('toast'); if(t) { t.style.opacity = '0'; setTimeout(()=>t.remove(),300); }}, 4000);

// --- MODALES NUBIRA ---
document.addEventListener('DOMContentLoaded', () => {
    function setupModal(triggerIds, modalId, cardId, closeIds) {
        const modal = document.getElementById(modalId); const card = document.getElementById(cardId);
        if (!modal || !card) return;
        const open = () => { modal.classList.remove('hidden'); requestAnimationFrame(() => card.classList.remove('translate-y-full', 'opacity-0')); document.body.style.overflow = 'hidden'; };
        const shut = () => { card.classList.add('translate-y-full', 'opacity-0'); setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); };
        triggerIds.forEach(id => { const btn = document.getElementById(id); if (btn) btn.onclick = (e) => { e.preventDefault(); open(); }; });
        closeIds.forEach(id => { const btn = document.getElementById(id); if (btn) btn.onclick = shut; });
        modal.onclick = (e) => { if (e.target === modal) shut(); };
    }
    setupModal(['btn-nuevo-ticket', 'btn-nuevo-ticket-empty'], 'modal-ticket', 'ticket-card', ['ticket-close', 'ticket-cancel']);
    
    // Auto-scroll primer no leído
    const primerNoLeido = document.querySelector('.ticket-item[data-no-leido="1"]');
    if (primerNoLeido) setTimeout(() => primerNoLeido.scrollIntoView({ behavior: 'smooth', block: 'center' }), 600);
});
</script>
</body>
</html>