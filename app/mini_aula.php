<?php
session_start();

// 1. INCLUSIONES Y SEGURIDAD
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/iconos.php';

// Verificación de sesión
if (!isset($_SESSION['usuario_id'])) { 
    header("Location: /login"); 
    exit; 
}

$usuario_id = (int)$_SESSION['usuario_id'];
$id_contrato = (int)($_GET['id'] ?? 0);
$rol_global = $_SESSION['rol'] ?? 'alumno';
$es_admin = ($rol_global === 'admin');

if ($id_contrato <= 0) {
    header("Location: /dashboard");
    exit;
}

// 2. CARGA DE DATOS
$sql = "SELECT c.*, s.titulo AS servicio_titulo FROM contratos c 
        JOIN servicios s ON s.id = c.servicio_id WHERE c.id = ?";

if (!$es_admin) {
    $sql .= " AND (c.comprador_id = ? OR c.vendedor_id = ?)";
}

$stmt = $conn->prepare($sql);
if ($es_admin) {
    $stmt->bind_param("i", $id_contrato);
} else {
    $stmt->bind_param("iii", $id_contrato, $usuario_id, $usuario_id);
}
$stmt->execute();
$contrato = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contrato) { 
    header("Location: /app/mis_contratos.php"); 
    exit; 
}

// =========================================================================
// [NUBIRA 2.0] MARCAR MENSAJES PRE-CONTRATO COMO LEÍDOS
// =========================================================================
$stmt_leido = $conn->prepare(
    "UPDATE mensajes m 
     INNER JOIN conversaciones c ON m.conversacion_id = c.id 
     SET m.leido = 1 
     WHERE c.comprador_id = ? 
     AND c.vendedor_id = ? 
     AND c.servicio_id = ? 
     AND m.remitente_id != ?
     AND m.leido = 0"
);
if ($stmt_leido) {
    $stmt_leido->bind_param("iiii", $contrato['comprador_id'], $contrato['vendedor_id'], $contrato['servicio_id'], $usuario_id);
    $stmt_leido->execute();
    $stmt_leido->close();
}

// =========================================================================
// [NUBIRA 2.0] LÓGICA DE VENTANA DE TIEMPO DEL AULA
// =========================================================================
date_default_timezone_set('America/Santiago');

$slot = null;
$stmt_slot = $conn->prepare("SELECT fecha_clase, duracion_minutos, estado AS estado_slot FROM reservas_slots WHERE contrato_id = ? LIMIT 1");
$stmt_slot->bind_param("i", $id_contrato);
$stmt_slot->execute();
$slot = $stmt_slot->get_result()->fetch_assoc();
$stmt_slot->close();

$BUFFER_ANTES_MIN = 5;
$ahora_ts = time();
$tiene_reserva = !empty($slot);

if ($tiene_reserva) {
    $clase_ini_ts = strtotime($slot['fecha_clase']);
    $duracion_min = (int)$slot['duracion_minutos'];
    $clase_fin_ts = $clase_ini_ts + ($duracion_min * 60);
    $ventana_apertura_ts = $clase_ini_ts - ($BUFFER_ANTES_MIN * 60);
} else {
    $clase_ini_ts = $ahora_ts;
    $clase_fin_ts = $ahora_ts + (3600 * 24 * 365);
    $ventana_apertura_ts = $ahora_ts;
}

$es_pre_clase    = ($ahora_ts < $ventana_apertura_ts);
$es_aula_activa  = ($ahora_ts >= $ventana_apertura_ts && $ahora_ts <= $clase_fin_ts);
$es_post_clase   = ($ahora_ts > $clase_fin_ts);
$video_habilitado = $es_aula_activa;

if ($es_admin) {
    $es_pre_clase = false;
    $es_aula_activa = true;
    $es_post_clase = false;
    $video_habilitado = true;
}

$dias_es = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
$meses_es = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$fecha_amigable = '';
if ($tiene_reserva) {
    $d = new DateTime($slot['fecha_clase']);
    $fecha_amigable = ucfirst($dias_es[$d->format('w')]) . ' ' . $d->format('j') . ' de ' . $meses_es[$d->format('n')-1] . ' a las ' . $d->format('H:i');
}

// MICRO-ENDPOINT PARA POLLING
if (isset($_GET['ajax_status'])) {
    header('Content-Type: application/json');
    echo json_encode(['finalizado_comprador' => !empty($contrato['finalizado_comprador'])]);
    exit;
}

// 3. LÓGICA DE ESTADOS Y ROLES
$es_vendedor_real = ($usuario_id === (int)$contrato['vendedor_id']);
$es_comprador_real = ($usuario_id === (int)$contrato['comprador_id']);

if ($es_vendedor_real) {
    $rol_en_contrato = 'vendedor';
} elseif ($es_comprador_real) {
    $rol_en_contrato = 'comprador';
} else {
    $rol_en_contrato = 'espectador_admin';
}

$es_activo = in_array($contrato['estado'], ['activo', 'en_progreso']);
$es_finalizado = ($contrato['estado'] === 'finalizado');

$yo_ya_finalice = ($es_comprador_real && $contrato['finalizado_comprador']) || 
                  ($es_vendedor_real && $contrato['finalizado_vendedor']);

$el_otro_finalizo = ($es_vendedor_real && $contrato['finalizado_comprador']) || 
                    ($es_comprador_real && $contrato['finalizado_vendedor']);

$mi_calificacion = null;
if ($es_comprador_real) $mi_calificacion = $contrato['calificacion_comprador'];
if ($es_vendedor_real) $mi_calificacion = $contrato['calificacion_vendedor'];

$comprador_puede_finalizar = (($es_comprador_real || $es_admin) && $es_activo && empty($contrato['finalizado_comprador']));
$comprador_esperando_inicio = (($es_comprador_real || $es_admin) && !$es_activo && empty($contrato['finalizado_comprador']));

$vendedor_esperando_alumno = ($es_vendedor_real && empty($contrato['finalizado_comprador']));
$vendedor_puede_confirmar = ($es_vendedor_real && !empty($contrato['finalizado_comprador']) && empty($contrato['finalizado_vendedor']));

$default_tab = $_GET['tab'] ?? 'archivos';

// MOTOR DE VIDEO DAILY.CO
$daily_api_key = DAILY_API_KEY;
$dominio_daily = "https://nubira-cl.daily.co/";
$hash_seguridad = substr(md5($id_contrato . "nubira_secreto_2026"), 0, 8);
$nombre_sala_unica = "aula-" . $id_contrato . "-" . $hash_seguridad;
$url_sala_oficial = $dominio_daily . $nombre_sala_unica;

// =========================================================================
// [NUBIRA 2.0] PIZARRA INTERACTIVA (Excalidraw)
// =========================================================================
$pizarra_room_id = substr(md5("nubira_pizarra_" . $id_contrato . "_" . $hash_seguridad), 0, 20);
$pizarra_key = substr(base64_encode(md5("key_" . $id_contrato . "_" . $hash_seguridad, true)), 0, 22);
$pizarra_key = strtr($pizarra_key, ['+' => '-', '/' => '_', '=' => '']);
$url_pizarra = "https://excalidraw.com/#room=" . $pizarra_room_id . "," . $pizarra_key;

if (!$es_pre_clase) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.daily.co/v1/rooms",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            "name" => $nombre_sala_unica,
            "privacy" => "public",
            "properties" => [
                "exp" => time() + (86400 * 30),
                "enable_prejoin_ui" => false,
                "enable_network_ui" => false,
                "enable_screenshare" => true,
                "enable_chat" => false
            ]
        ]),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer " . $daily_api_key
        ],
    ]);
    $respuesta_api = curl_exec($curl);
    curl_close($curl);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Aula #<?= $contrato['id'] ?> | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0, viewport-fit=cover">
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script crossorigin src="https://unpkg.com/@daily-co/daily-js"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #F9FAFB; overflow: hidden; height: 100dvh; }
        .skeleton { background: linear-gradient(90deg, #f0f0f0 25%, #f8f8f8 50%, #f0f0f0 75%); background-size: 200% 100%; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        .view-section { transition: opacity 0.3s ease, transform 0.3s ease; }
        .view-hidden { display: none !important; }
        #tools-panel { transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1), transform 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        @media (max-width: 767px) {
            #tools-panel { 
                position: fixed !important; 
                left: 0 !important; right: 0 !important; 
                top: 0 !important; bottom: 0 !important;
                width: 100% !important; height: 100dvh !important;
                transform: translateX(100%) !important;
                border-radius: 0 !important; 
                z-index: 99999 !important; 
                display: flex !important; flex-direction: column !important;
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            }
            #tools-panel.open { transform: translateX(0) !important; }
        }
        .pip-mode {
            display: block !important;
            position: fixed !important;
            bottom: 24px !important;
            right: 24px !important;
            width: 320px !important;
            height: 240px !important;
            margin: 0 !important;
            padding: 0 !important;
            z-index: 100 !important;
            border-radius: 1.5rem !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
            opacity: 1 !important;
            transform: translateY(0) !important;
            pointer-events: auto !important;
            background-color: #000;
        }
        @media (max-width: 767px) {
            .pip-mode { bottom: 80px !important; right: 16px !important; width: 140px !important; height: 190px !important; }
        }
    </style>
</head>
<body class="flex flex-col">

<header id="app-header" class="fixed top-0 left-0 w-full bg-white/90 backdrop-blur-md z-50 border-b border-gray-100 shrink-0">
    <div class="hidden md:block h-20 w-full">
        <?php if(file_exists(__DIR__ . '/componentes/header_aula.php')) require_once __DIR__ . '/componentes/header_aula.php'; ?>
    </div>
    <div class="md:hidden flex items-center justify-between px-4 h-16">
        <button type="button" onclick="window.location.href='/app/mis_contratos.php'" class="w-9 h-9 flex items-center justify-center rounded-full bg-gray-50 border border-gray-200 text-gray-600 hover:bg-gray-100 active:scale-95 transition-all shadow-sm">
            <i class="fa-solid fa-arrow-left text-sm"></i>
        </button>
        <div class="font-bold text-gray-900 text-[15px] truncate px-3 flex-1 text-center">
            Aula #<?= $contrato['id'] ?>
        </div>
        <div class="w-9 h-9"></div>
    </div>
    <div class="absolute bottom-0 left-0 h-[2px] bg-sky-100 w-full">
        <div class="h-full bg-[#54A6D8] transition-all duration-1000" style="width: <?= $es_finalizado ? '100%' : ($yo_ya_finalice ? '75%' : '40%') ?>;"></div>
    </div>
</header>

<aside id="app-sidebar" class="hidden md:flex flex-col fixed left-0 top-20 w-64 h-[calc(100vh-5rem)] border-r border-gray-100 bg-white z-40 overflow-y-auto transition-transform duration-300 ease-in-out translate-x-0">
    <?php require_once __DIR__ . '/componentes/sidebar.php'; ?>
</aside>

<main id="main-layout" class="pt-16 md:pt-20 lg:ml-64 h-[100dvh] w-full flex relative overflow-hidden bg-gray-50 max-w-[1600px] mx-auto transition-all duration-300 ease-in-out">

<?php if ($es_pre_clase): ?>
    <!-- ============================================== -->
    <!-- [NUBIRA 2.0] SALA DE ESPERA: Cuenta regresiva -->
    <!-- ============================================== -->
    <div class="flex-1 flex items-center justify-center p-6">
        <div class="max-w-md w-full text-center">
            <div class="w-20 h-20 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-6 text-[#54A6D8]">
                <i class="fa-regular fa-calendar-check text-3xl"></i>
            </div>
            <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight mb-2">Tu clase aún no comienza</h1>
            <p class="text-sm text-gray-500 mb-8"><?= htmlspecialchars($fecha_amigable) ?></p>
            <div class="bg-white border border-gray-100 rounded-3xl p-6 shadow-sm mb-6" 
                 data-clase-ini="<?= $clase_ini_ts ?>" 
                 data-ventana-apertura="<?= $ventana_apertura_ts ?>"
                 id="countdown-wrapper">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-3">Faltan</p>
                <div class="grid grid-cols-4 gap-2" id="countdown-grid">
                    <div>
                        <div class="text-3xl md:text-4xl font-black text-[#54A6D8] tabular-nums" id="cd-days">00</div>
                        <div class="text-[10px] text-gray-400 font-bold uppercase mt-1">Días</div>
                    </div>
                    <div>
                        <div class="text-3xl md:text-4xl font-black text-[#54A6D8] tabular-nums" id="cd-hours">00</div>
                        <div class="text-[10px] text-gray-400 font-bold uppercase mt-1">Horas</div>
                    </div>
                    <div>
                        <div class="text-3xl md:text-4xl font-black text-[#54A6D8] tabular-nums" id="cd-mins">00</div>
                        <div class="text-[10px] text-gray-400 font-bold uppercase mt-1">Min</div>
                    </div>
                    <div>
                        <div class="text-3xl md:text-4xl font-black text-[#54A6D8] tabular-nums" id="cd-secs">00</div>
                        <div class="text-[10px] text-gray-400 font-bold uppercase mt-1">Seg</div>
                    </div>
                </div>
            </div>
            <p class="text-xs text-gray-400 mb-6">Podrás entrar al aula 5 minutos antes del inicio.</p>
            <div class="flex flex-col sm:flex-row gap-2 justify-center">
                <a href="/app/mis_contratos.php" class="inline-flex items-center justify-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold text-xs px-5 py-2.5 rounded-full transition-all">
                    <i class="fa-solid fa-arrow-left"></i> Volver a mis clases
                </a>
            </div>
        </div>
    </div>
    
    <script>
    (function() {
        const wrap = document.getElementById('countdown-wrapper');
        const tsApertura = parseInt(wrap.dataset.ventanaApertura, 10) * 1000;
        function tick() {
            const ahora = Date.now();
            let diff = Math.floor((tsApertura - ahora) / 1000);
            if (diff <= 0) { window.location.reload(); return; }
            const d = Math.floor(diff / 86400);
            diff -= d * 86400;
            const h = Math.floor(diff / 3600);
            diff -= h * 3600;
            const m = Math.floor(diff / 60);
            const s = diff - (m * 60);
            document.getElementById('cd-days').textContent  = String(d).padStart(2, '0');
            document.getElementById('cd-hours').textContent = String(h).padStart(2, '0');
            document.getElementById('cd-mins').textContent  = String(m).padStart(2, '0');
            document.getElementById('cd-secs').textContent  = String(s).padStart(2, '0');
        }
        tick();
        setInterval(tick, 1000);
    })();
    </script>

<?php else: ?>

<button onclick="toggleSidebarAula()" id="btn-toggle-sidebar" class="hidden md:flex fixed top-[calc(50%+40px)] left-64 -translate-y-1/2 -translate-x-1/2 z-50 w-8 h-8 bg-white border border-gray-200 rounded-full items-center justify-center shadow-md text-gray-400 hover:text-[#54A6D8] hover:shadow-lg hover:scale-110 transition-all duration-300">
    <i class="fa-solid fa-chevron-left text-xs transition-transform duration-300" id="icon-toggle-sidebar"></i>
</button>

    <div class="flex-1 flex relative overflow-hidden">
        <div class="flex-1 relative flex flex-col min-w-0">
            
            <div id="app-tabs-container" class="sticky top-0 z-40 w-full flex items-center gap-2 p-4 md:absolute md:top-4 md:left-1/2 md:-translate-x-1/2 md:w-auto">
                <div class="bg-white/90 backdrop-blur-md border border-gray-200 rounded-full p-1.5 shadow-lg flex items-center gap-1">
                    <button onclick="switchTab('archivos')" id="btn-archivos" class="px-5 py-2 rounded-full text-xs font-bold flex items-center gap-2 transition-all relative">
                        <?= icon('folder', 'w-4 h-4') ?> Material
                        <span id="badge-archivos" class="hidden absolute -top-1 -right-1 w-3 h-3 bg-red-500 rounded-full border-2 border-white animate-pulse shadow-sm"></span>
                    </button>
                    <button onclick="switchTab('video')" id="btn-video" class="px-5 py-2 rounded-full text-xs font-bold flex items-center gap-2 transition-all text-gray-500 hover:bg-gray-100 relative">
                        <?= icon('video', 'w-4 h-4') ?> Reunión
                        <span id="badge-reunion" class="hidden absolute -top-1 -right-1 w-3 h-3 bg-red-500 rounded-full border-2 border-white animate-pulse shadow-sm"></span>
                    </button>
                    <?php if ($es_vendedor_real): ?>
                    <button onclick="switchTab('pizarra')" id="btn-pizarra" class="px-5 py-2 rounded-full text-xs font-bold flex items-center gap-2 transition-all text-gray-500 hover:bg-gray-100 relative">
                        <i class="fa-solid fa-chalkboard text-xs"></i> Pizarra
                    </button>
                    <div class="w-px h-4 bg-gray-200 mx-1"></div>
                    <?php endif; ?>
                    <button onclick="toggleChat()" class="px-5 py-2 rounded-full text-xs font-bold text-gray-600 hover:bg-gray-100 relative">
                        Chat
                        <span id="badge-chat" class="hidden absolute -top-1 -right-1 w-3 h-3 bg-red-500 rounded-full border-2 border-white animate-pulse shadow-sm"></span>
                    </button>
                </div>

                <?php if ($es_comprador_real || $es_admin): ?>
                    <?php if ($comprador_puede_finalizar): ?>
                        <button onclick="confirmarFinalizacion()" class="ml-2 bg-yellow-400 hover:bg-yellow-500 text-yellow-900 px-5 py-2.5 rounded-full text-xs font-bold shadow-md transition-transform active:scale-95 flex items-center gap-2">
                            <?= icon('thumbs-up', 'w-3 h-3') ?> Finalizar y Pagar
                        </button>
                    <?php elseif ($comprador_esperando_inicio): ?>
                        <button disabled class="ml-2 bg-gray-50 border border-gray-200 text-gray-400 px-5 py-2.5 rounded-full text-xs font-bold cursor-not-allowed flex items-center gap-2 shadow-sm">
                            <i class="fa-solid fa-lock text-xs"></i> Esperando inicio
                        </button>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($es_vendedor_real && !$es_admin): ?>
                    <?php if ($vendedor_esperando_alumno): ?>
                        <button id="btn-tutor-esperando" disabled class="ml-2 bg-gray-50 border border-gray-200 text-gray-400 px-5 py-2.5 rounded-full text-xs font-bold cursor-not-allowed flex items-center gap-2 shadow-sm transition-all duration-300">
                            <?= icon('clock', 'w-3 h-3') ?> Esperando al alumno
                        </button>
                        <button id="btn-tutor-confirmar" onclick="confirmarVendedor()" class="hidden ml-2 bg-[#54A6D8] hover:bg-sky-500 text-white px-5 py-2.5 rounded-full text-xs font-bold shadow-md transition-all active:scale-95 items-center gap-2">
                            <?= icon('check', 'w-3 h-3') ?> Confirmar Cierre
                        </button>
                    <?php elseif ($vendedor_puede_confirmar): ?>
                        <button id="btn-tutor-confirmar" onclick="confirmarVendedor()" class="ml-2 bg-[#54A6D8] hover:bg-sky-500 text-white px-5 py-2.5 rounded-full text-xs font-bold shadow-md transition-transform active:scale-95 flex items-center gap-2">
                            <?= icon('check', 'w-3 h-3') ?> Confirmar Cierre
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div id="view-archivos" class="view-section flex-1 p-4 md:p-6 mt-16 md:mt-12">
                <div class="w-full h-full bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden relative">
                    <div id="skeleton-archivos" class="absolute inset-0 p-8 space-y-4 bg-white z-10">
                        <div class="h-8 w-48 skeleton rounded-lg"></div>
                        <div class="grid grid-cols-3 gap-4">
                            <div class="h-32 skeleton rounded-2xl"></div>
                            <div class="h-32 skeleton rounded-2xl"></div>
                            <div class="h-32 skeleton rounded-2xl"></div>
                        </div>
                    </div>
                    <iframe id="iframe-material" src="/app/entregas_servicio.php?id=<?= $id_contrato ?>&admin=<?= $es_admin?'1':'0' ?>" onload="document.getElementById('skeleton-archivos').classList.add('hidden')" class="w-full h-full border-0"></iframe>
                </div>
            </div>

            <div id="view-video" class="view-section view-hidden flex-1 p-4 md:p-6 mt-16 md:mt-12">
                <div class="w-full h-full bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden relative flex items-center justify-center">
                    <?php if(!$es_finalizado): ?>
                        <div id="video-placeholder" class="text-center transition-opacity duration-300">
                            <div class="w-20 h-20 bg-sky-50 rounded-full flex items-center justify-center mx-auto mb-4 text-[#54A6D8] animate-bounce-slow">
                                <?= icon('video', 'w-8 h-8') ?>
                            </div>
                            <h2 class="text-xl font-bold mb-2 text-gray-800">Sala de Reunión</h2>
                            <?php if ($es_post_clase): ?>
                                <p class="text-gray-500 text-sm mb-6 max-w-xs mx-auto">Esta clase ya finalizó.</p>
                            <?php elseif ($tiene_reserva): ?>
                                <p class="text-gray-500 text-sm mb-6 max-w-xs mx-auto">Clase agendada: <strong class="text-gray-700"><?= htmlspecialchars($fecha_amigable) ?></strong></p>
                            <?php else: ?>
                                <p class="text-gray-500 text-sm mb-6 max-w-xs mx-auto">Videollamada segura e integrada.</p>
                            <?php endif; ?>
                            
                            <?php if ($video_habilitado): ?>
                                <button onclick="iniciarClase()" class="bg-[#54A6D8] text-white px-8 py-3 rounded-2xl font-bold shadow-lg shadow-sky-100 hover:scale-105 transition-transform">
                                    Entrar a la Sala
                                </button>
                            <?php else: ?>
                                <button disabled class="bg-gray-200 text-gray-400 px-8 py-3 rounded-2xl font-bold cursor-not-allowed shadow-sm">
                                    <i class="fa-solid fa-lock mr-2"></i> Sala cerrada
                                </button>
                                <p class="text-xs text-gray-400 mt-3 max-w-xs mx-auto">
                                    El horario de la videollamada finalizó. Puedes seguir usando el chat y el material para coordinar.
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <div id="video-container" class="hidden absolute inset-0 bg-black z-20"></div>
                        <div id="video-timer" class="hidden absolute top-6 right-6 z-30 bg-black/60 backdrop-blur-md text-white px-3.5 py-1.5 rounded-full flex items-center gap-2 font-mono text-sm shadow-lg border border-white/10 transition-all duration-500 opacity-0 -translate-y-2 pointer-events-none">
                            <span class="w-2 h-2 rounded-full bg-red-500 animate-pulse shadow-[0_0_8px_rgba(239,68,68,0.8)]"></span>
                            <span id="timer-text" class="tracking-wider">00:00</span>
                        </div>
                        <button id="btn-colgar" onclick="colgarLlamada()" class="hidden absolute bottom-8 left-8 z-50 bg-red-600 hover:bg-red-700 text-white w-14 h-14 rounded-full shadow-lg flex items-center justify-center border-4 border-white transition-transform active:scale-90">
                            <i class="fa-solid fa-phone-slash text-xl"></i>
                        </button>
                    <?php else: ?>
                        <div class="text-center opacity-50 grayscale">
                            <i class="fa-solid fa-lock text-4xl mb-2"></i>
                            <p class="font-bold">Aula Cerrada</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($es_vendedor_real): ?>
            <!-- ============================================ -->
            <!-- [NUBIRA 2.0] PIZARRA INTERACTIVA EXCALIDRAW -->
            <!-- ============================================ -->
            <div id="view-pizarra" class="view-section view-hidden flex-1 p-4 md:p-6 mt-16 md:mt-12">
           <div class="w-full h-full bg-white rounded-3xl border-2 border-gray-200 shadow-md overflow-hidden relative">
                    <div id="skeleton-pizarra" class="absolute inset-0 flex items-center justify-center bg-white z-10">
                        <div class="text-center">
                            <div class="w-16 h-16 bg-purple-50 rounded-full flex items-center justify-center mx-auto mb-3 text-purple-500">
                                <i class="fa-solid fa-chalkboard text-2xl"></i>
                            </div>
                            <p class="text-sm font-bold text-gray-700">Cargando pizarra colaborativa...</p>
                            <p class="text-xs text-gray-400 mt-1">Powered by Excalidraw</p>
                        </div>
                    </div>
                    <iframe
                        id="iframe-pizarra"
                        data-src="<?= htmlspecialchars($url_pizarra) ?>"
                        onload="document.getElementById('skeleton-pizarra').classList.add('hidden')"
                        class="w-full h-full border-0"
                        allow="clipboard-read; clipboard-write"
                    ></iframe>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <aside id="tools-panel" class="w-0 bg-white border-l border-gray-100 flex flex-col shadow-2xl md:shadow-none overflow-hidden">
            <iframe data-src="/app/chat_mini_aula.php?id=<?= $id_contrato ?>" id="chat-iframe" class="flex-1 w-full h-full min-h-0 border-0 bg-[#f0f2f5]"></iframe>
        </aside>
    </div>

<?php endif; // cierra if ($es_pre_clase) ?>

</main>

<?php if (!$es_pre_clase): ?>
<script>
    window.enLlamada = false;

    function switchTab(tab) {
        const vA = document.getElementById('view-archivos');
        const vV = document.getElementById('view-video');
        const vP = document.getElementById('view-pizarra');
        const bA = document.getElementById('btn-archivos');
        const bV = document.getElementById('btn-video');
        const bP = document.getElementById('btn-pizarra');

        const cInactiva = "px-5 py-2 rounded-full text-xs font-bold flex items-center gap-2 transition-all text-gray-500 hover:bg-gray-100 relative";
        const cActiva   = "px-5 py-2 rounded-full text-xs font-bold flex items-center gap-2 transition-all bg-[#54A6D8] text-white shadow-md relative";

        bA.className = cInactiva;
        bV.className = cInactiva;
        if (bP) bP.className = cInactiva;

        vA.classList.add('view-hidden');
        if (vP) vP.classList.add('view-hidden');
        vV.classList.add('view-hidden');
        vV.classList.remove('pip-mode');

        if (tab === 'archivos') {
            vA.classList.remove('view-hidden');
            bA.className = cActiva;
            document.getElementById('badge-archivos').classList.add('hidden');
            if (window.enLlamada) {
                vV.classList.remove('view-hidden');
                vV.classList.add('pip-mode');
            }
        } 
        else if (tab === 'video') {
            vV.classList.remove('view-hidden');
            bV.className = cActiva;
        } 
        else if (tab === 'pizarra') {
            if (vP && bP) {
                vP.classList.remove('view-hidden');
                bP.className = cActiva;
                const iframePizarra = document.getElementById('iframe-pizarra');
                if (iframePizarra && (!iframePizarra.src || iframePizarra.src === window.location.href)) {
                    iframePizarra.src = iframePizarra.getAttribute('data-src');
                }
                if (window.enLlamada) {
                    vV.classList.remove('view-hidden');
                    vV.classList.add('pip-mode');
                }
            }
        }
    }

    let lastFileCount = null;
    let isFirstLoad = true;
    async function checkNewFiles() {
        try {
            const res = await fetch(`count_files.php?id=<?= $id_contrato ?>`);
            if (!res.ok) return; 
            const data = await res.json();
            if (isFirstLoad) {
                lastFileCount = data.count;
                isFirstLoad = false;
                return;
            }
            if (data.count > lastFileCount) {
                const iframe = document.getElementById('iframe-material');
                iframe.contentWindow.location.reload();
                lastFileCount = data.count;
                if (document.getElementById('view-archivos').classList.contains('view-hidden')) {
                    document.getElementById('badge-archivos').classList.remove('hidden');
                }
            }
        } catch (e) {}
    }
    setInterval(checkNewFiles, 7000);

    <?php if ($es_vendedor_real && $vendedor_esperando_alumno): ?>
    setInterval(async () => {
        try {
            const res = await fetch(`?id=<?= $id_contrato ?>&ajax_status=1`);
            const data = await res.json();
            if (data.finalizado_comprador) {
                const btnEsperando = document.getElementById('btn-tutor-esperando');
                const btnConfirmar = document.getElementById('btn-tutor-confirmar');
                if (btnEsperando && !btnEsperando.classList.contains('hidden')) {
                    btnEsperando.classList.add('hidden');
                    btnConfirmar.classList.remove('hidden');
                    btnConfirmar.classList.add('flex', 'animate-pulse');
                    confetti({ particleCount: 60, spread: 50, origin: { y: 0.8 }, colors: ['#10b981', '#54A6D8'] });
                }
            }
        } catch(e) {}
    }, 5000);
    <?php endif; ?>

    let callFrame = null;
    function iniciarClase() {
        const placeholder = document.getElementById('video-placeholder');
        const container = document.getElementById('video-container');
        const btnColgar = document.getElementById('btn-colgar');
        placeholder.classList.add('opacity-0');
        setTimeout(() => {
            placeholder.classList.add('hidden');
            container.classList.remove('hidden');
            if (!callFrame) {
                callFrame = window.DailyIframe.createFrame(container, {
                    iframeStyle: { width: '100%', height: '100%', border: '0', borderRadius: '1.5rem' },
                    showLeaveButton: false, 
                    showFullscreenButton: true,
                    userName: '<?= addslashes($_SESSION['usuario_nombre'] ?? 'Usuario') ?>',
                    lang: 'es'
                });
                callFrame.on('left-meeting', colgarLlamada);
                callFrame.on('participant-joined', checkParticipantsTimer);
                callFrame.on('joined-meeting', checkParticipantsTimer);
                callFrame.on('track-stopped', function(event) {
                    if (event.participant && event.participant.screen) {
                        window.dispatchEvent(new Event('resize'));
                    }
                });
            }
            const roomUrl = "<?= $url_sala_oficial ?>";
            callFrame.join({ url: roomUrl })
                .then(() => {
                    window.enLlamada = true;
                    btnColgar.classList.remove('hidden');
                    btnColgar.classList.add('animate-bounce');
                    setTimeout(() => btnColgar.classList.remove('animate-bounce'), 2000);
                })
                .catch(err => {
                    console.error("Error al conectar con Daily:", err);
                    alert("Hubo un problema al conectar. Contacta a soporte.");
                    colgarLlamada();
                });
            fetch(`/app/ping_reunion.php?id=<?= $id_contrato ?>&accion=entrar`).catch(()=>{});
            document.getElementById('badge-reunion').classList.add('hidden');
            window.reunionPinger = setInterval(() => {
                fetch(`/app/ping_reunion.php?id=<?= $id_contrato ?>&accion=ping`).catch(()=>{});
            }, 15000);
        }, 300);
    }

    function colgarLlamada() {
        stopCallTimer();
        window.enLlamada = false;
        document.getElementById('view-video').classList.remove('pip-mode');
        if (!document.getElementById('view-archivos').classList.contains('view-hidden')) {
            document.getElementById('view-video').classList.add('view-hidden');
        }
        fetch(`/app/ping_reunion.php?id=<?= $id_contrato ?>&accion=salir`).catch(()=>{});
        if(window.reunionPinger) clearInterval(window.reunionPinger);
        if(callFrame) callFrame.leave();
        const placeholder = document.getElementById('video-placeholder');
        const container = document.getElementById('video-container');
        const btnColgar = document.getElementById('btn-colgar');
        container.classList.add('hidden');
        btnColgar.classList.add('hidden');
        placeholder.classList.remove('hidden');
        setTimeout(() => placeholder.classList.remove('opacity-0'), 50);
    }

    function toggleChat() {
        const p = document.getElementById('tools-panel');
        const f = document.getElementById('chat-iframe');
        if (window.innerWidth >= 768) {
            p.classList.remove('open');
            const isClosed = p.style.width === '0px' || p.style.width === '';
            p.style.width = isClosed ? '380px' : '0px';
        } else {
            p.style.width = '';
            p.classList.toggle('open');
        }
        if (!f.src || f.src === window.location.href) {
            f.src = f.getAttribute('data-src');
        }
        document.getElementById('badge-chat').classList.add('hidden');
    }

    function confirmarFinalizacion() {
        if(confirm('¿Confirmas que el servicio fue entregado y deseas liberar el pago?')) {
            confetti({ particleCount: 150, spread: 70, origin: { y: 0.6 }, colors: ['#54A6D8', '#fbbf24', '#ffffff'] });
            setTimeout(() => {
                const form = document.createElement('form');
                form.method = 'POST'; form.action = '/app/finalizar_servicio.php';
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = 'contrato_id'; input.value = '<?= $id_contrato ?>';
                form.appendChild(input); document.body.appendChild(form); form.submit();
            }, 1200);
        }
    }

    function confirmarVendedor() {
        if(confirm('El alumno ya liberó el pago. ¿Confirmas el cierre del contrato por tu parte?')) {
            confetti({ particleCount: 100, spread: 60, origin: { y: 0.8 }, colors: ['#54A6D8', '#ffffff'] });
            const formData = new URLSearchParams();
            formData.append('contrato_id', '<?= $id_contrato ?>');
            fetch('/app/finalizar_servicio_tutor.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            }).then(() => {
                setTimeout(() => {
                    window.location.href = '/app/evaluar_servicio.php?id=<?= $id_contrato ?>';
                }, 1200);
            }).catch(() => {
                window.location.href = '/app/evaluar_servicio.php?id=<?= $id_contrato ?>';
            });
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        switchTab('<?= $default_tab ?>');
        checkNewFiles();
    });

    setInterval(async () => {
        try {
            const r = await fetch('/app/notificaciones_chat_mini_aula.php?id=<?= $id_contrato ?>');
            const d = await r.json();
            if(d.unread > 0) document.getElementById('badge-chat').classList.remove('hidden');
        } catch(e){}
    }, 8000);

    setInterval(async () => {
        const jitsiHidden = document.getElementById('video-container').classList.contains('hidden');
        if (jitsiHidden) {
            try {
                const res = await fetch(`/app/ping_reunion.php?id=<?= $id_contrato ?>&accion=estado`);
                const data = await res.json();
                const badge = document.getElementById('badge-reunion');
                if (data.activo && data.usuario_id != <?= $usuario_id ?>) {
                    badge.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                }
            } catch(e){}
        }
    }, 8000);

    let sidebarAbierto = true;
    function toggleSidebarAula() {
        sidebarAbierto = !sidebarAbierto;
        const sidebar = document.getElementById('app-sidebar');
        const main = document.getElementById('main-layout');
        const btn = document.getElementById('btn-toggle-sidebar');
        const icon = document.getElementById('icon-toggle-sidebar');
        if (sidebarAbierto) {
            sidebar.classList.remove('-translate-x-full');
            main.classList.add('lg:ml-64');
            btn.classList.add('left-64');
            btn.classList.remove('left-0', 'translate-x-4');
            icon.classList.remove('rotate-180');
        } else {
            sidebar.classList.add('-translate-x-full');
            main.classList.remove('lg:ml-64');
            btn.classList.remove('left-64');
            btn.classList.add('left-0', 'translate-x-4');
            icon.classList.add('rotate-180');
        }
    }

    let timerInterval = null;
    let callStartTime = null;
    function checkParticipantsTimer() {
        if (!callFrame) return;
        const participantes = callFrame.participants();
        if (Object.keys(participantes).length > 1) {
            startCallTimer();
        }
    }
    function startCallTimer() {
        if (timerInterval) return;
        const timerEl = document.getElementById('video-timer');
        const timerText = document.getElementById('timer-text');
        timerEl.classList.remove('hidden');
        setTimeout(() => timerEl.classList.remove('opacity-0', '-translate-y-2'), 50);
        if (!callStartTime) callStartTime = Date.now();
        timerInterval = setInterval(() => {
            const diff = Math.floor((Date.now() - callStartTime) / 1000);
            const m = String(Math.floor(diff / 60)).padStart(2, '0');
            const s = String(diff % 60).padStart(2, '0');
            if (diff >= 3600) {
                const h = String(Math.floor(diff / 3600)).padStart(2, '0');
                timerText.innerText = `${h}:${m}:${s}`;
            } else {
                timerText.innerText = `${m}:${s}`;
            }
        }, 1000);
    }
    function stopCallTimer() {
        clearInterval(timerInterval);
        timerInterval = null;
        callStartTime = null;
        const timerEl = document.getElementById('video-timer');
        if (timerEl) {
            timerEl.classList.add('opacity-0', '-translate-y-2');
            setTimeout(() => timerEl.classList.add('hidden'), 500);
        }
        document.getElementById('timer-text').innerText = '00:00';
    }
    
    (function fixTecladoIframeChat() {
        if (window.innerWidth >= 768) return;
        if (!('visualViewport' in window)) return;
        const panel = document.getElementById('tools-panel');
        const iframe = document.getElementById('chat-iframe');
        if (!panel || !iframe) return;
        function ajustarPanelPorTeclado() {
            if (!panel.classList.contains('open')) return;
            const alturaVisible = window.visualViewport.height;
            const offsetTop = window.visualViewport.offsetTop || 0;
            document.documentElement.style.setProperty('--panel-height', alturaVisible + 'px');
            panel.style.top = offsetTop + 'px';
            if (iframe.contentWindow) {
                iframe.contentWindow.postMessage({ type: 'nubira:keyboard-resize', height: alturaVisible }, '*');
            }
        }
        function resetearPanel() {
            document.documentElement.style.setProperty('--panel-height', '100dvh');
            panel.style.top = '';
            if (iframe.contentWindow) {
                iframe.contentWindow.postMessage({ type: 'nubira:keyboard-resize', height: window.innerHeight }, '*');
            }
        }
        window.visualViewport.addEventListener('resize', ajustarPanelPorTeclado);
        window.visualViewport.addEventListener('scroll', ajustarPanelPorTeclado);
        window.addEventListener('orientationchange', () => { setTimeout(resetearPanel, 200); });
        const toggleOriginal = window.toggleChat;
        window.toggleChat = function() {
            toggleOriginal.apply(this, arguments);
            setTimeout(() => { if (!panel.classList.contains('open')) resetearPanel(); }, 100);
        };
    })();
</script>
<?php endif; ?>

</body>
</html>