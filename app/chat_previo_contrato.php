<?php
/**
 * VISTA: SALA DE CHAT (CORREGIDO: ANONIMATO + TOAST FIJO + INACTIVIDAD 48HRS)
 * UBICACIÓN: public_html/app/chat_previo_contrato.php
 */

// 1. CONFIGURACIÓN
ini_set('display_errors', 0);
session_start();

// 2. CONEXIÓN
$app_path = __DIR__;
$conn_paths = [$app_path . '/conexion.php', dirname($app_path) . '/conexion.php'];
$conn_loaded = false;
foreach ($conn_paths as $cp) {
    if (file_exists($cp)) { require_once $cp; $conn_loaded = true; break; }
}
if (!$conn_loaded) die("Error de sistema.");
$conn->set_charset("utf8mb4");
require_once __DIR__ . '/iconos.php';
require_once __DIR__ . '/logger.php';

// 3. SEGURIDAD
if (!isset($_SESSION['usuario_id'])) { 
    header("Location: /login?redir=" . urlencode($_SERVER['REQUEST_URI'])); 
    exit; 
}

$my_id   = (int)$_SESSION['usuario_id'];
$chat_id = (int)($_GET['id'] ?? 0);

if ($chat_id <= 0) die("Chat no válido.");

// 4. DATOS (SQL TRAE NOMBRES Y FOTOS)
$sql = "
    SELECT 
        c.*,
        s.titulo as servicio_titulo,
        s.categoria,
        s.precio,
        s.precio_oferta,
        s.is_subvencionado,
        s.cupos_oferta,
        s.duracion_minutos,
        s.modalidad,
        s.imagen as servicio_imagen,
        -- Vendedor
        v.nombre as nombre_vendedor,
        v.foto_perfil as foto_vendedor,
        v.id as id_vendedor,
        -- Comprador
        a.nombre as nombre_comprador,
        a.foto_perfil as foto_comprador,
        a.id as id_comprador
    FROM conversaciones c
    JOIN servicios s ON c.servicio_id = s.id
    JOIN alumnos a ON c.comprador_id = a.id
    JOIN alumnos v ON c.vendedor_id = v.id
    WHERE c.id = ? AND (c.comprador_id = ? OR c.vendedor_id = ?)
    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $chat_id, $my_id, $my_id);
$stmt->execute();
$chat = $stmt->get_result()->fetch_assoc();

if (!$chat) { header("Location: /app/bandeja_entrada.php"); exit; }
registrar_actividad($conn, $my_id, 'VER_CHAT', 'Chat con servicio ID: ' . (int)$chat['servicio_id']);

// 5. PREPARAR DATOS VISUALES
$esVendedor = ($chat['vendedor_id'] == $my_id);

$modal_precio        = (int)$chat['precio'];
$modal_es_oferta     = ($chat['is_subvencionado'] == 1 && $chat['cupos_oferta'] > 0);
$modal_precio_oferta = $modal_es_oferta ? (int)$chat['precio_oferta'] : $modal_precio;
$modal_duracion      = (int)($chat['duracion_minutos'] ?: 60);

if ($esVendedor) {
    $raw_nombre = $chat['nombre_comprador'];
    $raw_foto   = $chat['foto_comprador']; 
} else {
    $raw_nombre = $chat['nombre_vendedor'];
    $raw_foto   = $chat['foto_vendedor']; 
}

// A. ANONIMATO: Nombre + Inicial del apellido (Ej: Pablo C.)
$partes = explode(' ', trim($raw_nombre));
$primer_nombre = ucfirst(strtolower($partes[0]));

$nombre_mostrar = $primer_nombre;
$iniciales_avatar = mb_strtoupper(mb_substr($partes[0], 0, 1, 'UTF-8'));

if (count($partes) > 1) {
    $inicial_apellido = mb_strtoupper(mb_substr($partes[1], 0, 1, 'UTF-8'));
    $nombre_mostrar = $primer_nombre . ' ' . $inicial_apellido . '.';
    $iniciales_avatar .= $inicial_apellido; 
}

// B. FOTO DE PERFIL 
$tiene_foto = false;
$ruta_foto_url = "";

if (!empty($raw_foto)) {
    $ruta_foto_url = "/app/perfil/fotos/" . $raw_foto;
    $tiene_foto = true;
}

// C. ESTADO ONLINE DEL INTERLOCUTOR
$id_otro = $esVendedor ? (int)$chat['comprador_id'] : (int)$chat['vendedor_id'];
$otro_online = false;
$stmt_online = $conn->prepare("SELECT ultima_sesion, bloqueado FROM alumnos WHERE id = ? LIMIT 1");
if ($stmt_online) {
    $stmt_online->bind_param("i", $id_otro);
    $stmt_online->execute();
    $row_online = $stmt_online->get_result()->fetch_assoc();
    $stmt_online->close();
    $ultima_sesion_otro = $row_online['ultima_sesion'] ?? null;
    $otro_online = ($ultima_sesion_otro && strtotime($ultima_sesion_otro) > (time() - 300));
}
$destinatario_suspendido = !empty($row_online['bloqueado']);

// ========================================================================
// NUBIRA 2.0: CAPTURA DE INTENCIÓN (SMART DISCOVERY)
// ========================================================================
if (!empty($chat['categoria']) && !$esVendedor) {
    // Si es el comprador, guardamos la categoría de este chat en su sesión
    $_SESSION['ultimo_interes_categoria'] = $chat['categoria'];
}

// ========================================================================
// 5.5 DETECTAR INACTIVIDAD DEL TUTOR (Regla de 48 horas - A PRUEBA DE ERRORES)
// ========================================================================
$tutor_inactivo = false;
if (!$esVendedor) {
    // Usamos SELECT * para evitar error 500 si el nombre de la columna de fecha es distinto
    $stmt_last = $conn->prepare("SELECT * FROM mensajes WHERE conversacion_id = ? ORDER BY id DESC LIMIT 1");
    
    if ($stmt_last) {
        $stmt_last->bind_param("i", $chat_id);
        $stmt_last->execute();
        $last_msg = $stmt_last->get_result()->fetch_assoc();
        $stmt_last->close();

        if ($last_msg && $last_msg['remitente_id'] == $my_id) {
            // Buscamos la fecha sea cual sea el nombre de tu columna
            $fecha_msj = $last_msg['fecha_envio'] ?? $last_msg['fecha'] ?? $last_msg['created_at'] ?? $last_msg['fecha_creacion'] ?? null;
            
            if ($fecha_msj) {
                $horas_inactivo = (time() - strtotime($fecha_msj)) / 3600;
                if ($horas_inactivo >= 48) { 
                    $tutor_inactivo = true;
                }
            }
        }
    }
}
// ========================================================================

// ========================================================================
// [NUBIRA 2.0] LÍMITE DE 6 MENSAJES ANTES DE CONTRATAR
// Mismo criterio que enviar_mensaje.php: solo cuenta visible=1, no aplica
// si ya existe contrato_id.
// ========================================================================
$limite_mensajes_alcanzado = false;
if (empty($chat['contrato_id'])) {
    $stmt_cnt_limite = $conn->prepare("SELECT COUNT(*) AS total FROM mensajes WHERE conversacion_id = ? AND visible = 1");
    $stmt_cnt_limite->bind_param("i", $chat_id);
    $stmt_cnt_limite->execute();
    $total_mensajes_chat = (int)($stmt_cnt_limite->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt_cnt_limite->close();
    $limite_mensajes_alcanzado = ($total_mensajes_chat >= 6);
}

// 6. MARCAR LEÍDO
$stmt_leido = $conn->prepare("UPDATE mensajes SET leido = 1 WHERE conversacion_id = ? AND remitente_id != ?");
if ($stmt_leido) {
    $stmt_leido->bind_param("ii", $chat_id, $my_id);
    $stmt_leido->execute();
    $stmt_leido->close();
}

// ========================================================================
// 7. [NUBIRA 2.0] PRE-CARGA DE MENSAJES (renderizado server-side inicial)
// Carga los mensajes directamente en el HTML para apertura instantánea.
// El polling posterior solo trae deltas.
// ========================================================================
$sql_msgs = "SELECT *, enviado_en AS fecha_real, leido AS estado_visto
             FROM mensajes
             WHERE conversacion_id = ? AND (visible = 1 OR remitente_id = ?)
             ORDER BY enviado_en ASC";
$stmt_msgs = $conn->prepare($sql_msgs);
$stmt_msgs->bind_param("ii", $chat_id, $my_id);
$stmt_msgs->execute();
$res_msgs = $stmt_msgs->get_result();

$mensajes_iniciales = [];
while ($m = $res_msgs->fetch_assoc()) {
    $mensajes_iniciales[] = $m;
}
$stmt_msgs->close();

// Capturamos el HTML pre-renderizado en un buffer (para inyectarlo después)
ob_start();
$mensajes = $mensajes_iniciales;
$usuario_id = $my_id;
require __DIR__ . '/render_mensajes.php';
$html_mensajes_iniciales = ob_get_clean();

// [NUBIRA 2.0 PERF] Liberar la sesión para que el polling de 3 seg no congele el envío de mensajes
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
session_write_close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Chat con <?= htmlspecialchars($nombre_mostrar) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f9fafb; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
        .bubble-me { background-color: #54A6D8; color: white; border-radius: 18px 18px 4px 18px; }
        .bubble-other { background-color: white; color: #1f2937; border-radius: 18px 18px 18px 4px; border: 1px solid #f3f4f6; }
        
        /* Animaciones del Toast */
        .fade-in-down { animation: fadeInDown 0.3s ease-out forwards; }
        .fade-out-up { animation: fadeOutUp 0.3s ease-in forwards; }
        @keyframes fadeInDown { from { opacity: 0; transform: translate(-50%, -20px); } to { opacity: 1; transform: translate(-50%, 0); } }
        @keyframes fadeOutUp { from { opacity: 1; transform: translate(-50%, 0); } to { opacity: 0; transform: translate(-50%, -20px); } }
        /* Mensaje optimista */
        .bubble-pending { opacity: 0.75; }
        .bubble-failed { 
            background: #fee2e2 !important; 
            color: #991b1b !important;
            border: 1px solid #fecaca;
        }
        .bubble-failed .retry-btn { display: inline-flex; }
        .retry-btn { display: none; margin-left: 6px; cursor: pointer; }
        .fade-in { animation: fadeIn 0.25s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
        
        /* [NUBIRA 2.0] Sistema de badges "Nuevo" reutilizable */
        .feature-badge {
            position: absolute;
            top: -4px;
            right: -8px;
            background-color: #54A6D8;
            color: white;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.3px;
            padding: 2px 6px;
            border-radius: 999px;
            box-shadow: 0 2px 6px rgba(84, 166, 216, 0.45);
            border: 1.5px solid white;
            line-height: 1;
            text-transform: uppercase;
            pointer-events: none;
            white-space: nowrap;
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        .feature-badge.is-hidden {
            opacity: 0;
            transform: scale(0.5);
        }
        .feature-host {
            position: relative;
        }
        
        /* [NUBIRA 2.0] Reservar altura para imágenes mientras descargan */
        /* Evita el "salto" del scroll cuando la imagen pasa de 0 a su altura real */
        .bubble-me img,
        .bubble-other img,
        [class*="bubble"] img {
            min-height: 180px;
            background: linear-gradient(110deg, #f0f0f0 30%, #f8f8f8 50%, #f0f0f0 70%);
            background-size: 200% 100%;
            animation: shimmer-img 1.4s linear infinite;
        }
        .bubble-me img.loaded,
        .bubble-other img.loaded,
        [class*="bubble"] img.loaded {
            min-height: auto;
            background: none;
            animation: none;
        }
        @keyframes shimmer-img {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        /* [NUBIRA 2.0] Typing indicator - puntos animados estilo nativo */
#typing-indicator { display: none; }
#typing-indicator.is-visible { display: flex; }

.typing-dot {
    width: 7px;
    height: 7px;
    background-color: #9ca3af;
    border-radius: 50%;
    display: inline-block;
    animation: typing-bounce 1.3s infinite ease-in-out;
}
.typing-dot:nth-child(1) { animation-delay: 0s; }
.typing-dot:nth-child(2) { animation-delay: 0.18s; }
.typing-dot:nth-child(3) { animation-delay: 0.36s; }

@keyframes typing-bounce {
    0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
    30% { transform: translateY(-4px); opacity: 1; }
}
        
        /* [NUBIRA 2.0] Fix teclado móvil - Chat nativo feel */
/* [NUBIRA 2.0] Fix teclado móvil - Chat nativo feel v2 */
html, body {
    overflow: hidden;
    overscroll-behavior: none;
    -webkit-overflow-scrolling: touch;
    height: 100%;
}
/* Evitar que iOS empuje el body al tocar inputs */
body {
    position: relative;
    width: 100%;
}
/* Solo el contenedor de mensajes puede scrollear */
#chat-container {
    -webkit-overflow-scrolling: touch;
    overscroll-behavior: contain;
}
/* El footer se queda quieto, nunca lo tapa el teclado */
footer { 
    flex-shrink: 0;
    position: relative;
    z-index: 40;
}
/* Textarea: evita zoom automático en iOS */
textarea, input { 
    font-size: 16px !important; 
}


    </style>
</head>

<body class="w-full flex flex-col overflow-hidden text-gray-900 bg-gray-50 relative" style="height: calc(var(--vh, 1vh) * 100);">

<?php
$redir_express = urlencode('/app/chat_previo_contrato.php?id=' . $chat_id);
?>
<!-- Banner sutil express (msg 3) — activado por JS -->
<div id="banner-express" class="hidden bg-amber-100 border-b border-amber-200 text-amber-900 text-xs md:text-sm">
  <div class="max-w-[1600px] mx-auto px-4 py-2 flex flex-wrap items-center justify-between gap-2">
    <div class="flex items-center gap-2">
      <span>💬</span>
      <span class="font-medium">Crea tu contraseña para guardar esta conversación.</span>
    </div>
    <a href="/completar-registro?redir=<?= $redir_express ?>" class="font-bold underline hover:no-underline whitespace-nowrap">
      Crear contraseña →
    </a>
  </div>
</div>

<!-- Modal hard gate express (msg 4+) — activado por JS -->
<div id="modal-express" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4">
  <div class="bg-white rounded-2xl shadow-xl max-w-sm w-full p-6 text-center">
    <div class="text-3xl mb-3">🔐</div>
    <h2 class="text-xl font-bold text-gray-900 mb-2">Completa tu registro</h2>
    <p class="text-gray-500 text-sm mb-6">Para enviar más mensajes, crea una contraseña y protege tu cuenta.</p>
    <a href="/completar-registro?redir=<?= $redir_express ?>"
       class="block w-full bg-[#54A6D8] text-white font-bold py-3 rounded-2xl mb-3 hover:bg-[#4895c3] transition-all">
      Crear contraseña
    </a>
    <button onclick="cerrarModalExpress()"
            class="block w-full text-gray-400 text-sm hover:text-gray-600 transition-all py-1">
      Más tarde
    </button>
  </div>
</div>

    <div id="toast-container" class="fixed top-20 left-1/2 z-50 hidden w-[90%] max-w-sm transform -translate-x-1/2 transition-all duration-300">
        <div class="bg-red-500 text-white px-4 py-3 rounded-2xl shadow-xl flex items-start justify-between gap-3 border border-red-600">
            <div class="flex items-start gap-3">
                <?= icon('exclamation-triangle', 'w-5 h-5 shrink-0 mt-0.5') ?>
                <p id="toast-msg" class="text-[13px] font-medium leading-snug"></p>
            </div>
            <button type="button" onclick="hideToast()" class="text-white hover:text-red-200 shrink-0 p-1 transition-colors">
                <?= icon('x-mark', 'w-5 h-5') ?>
            </button>
        </div>
    </div>

    <header class="h-16 bg-white/95 backdrop-blur-md shrink-0 flex items-center justify-between px-3 border-b border-gray-100 shadow-sm z-30 relative">
        <div class="flex items-center gap-2 overflow-hidden">
           
<a href="/app/bandeja_entrada.php" class="text-gray-500 hover:text-[#54A6D8] w-10 h-10 flex items-center justify-center rounded-full hover:bg-gray-50 transition-colors -ml-1">
    <?= icon('arrow-left', 'w-5 h-5') ?>
</a>

            <div class="relative shrink-0">
                <?php if ($tiene_foto): ?>
                    <img src="<?= htmlspecialchars($ruta_foto_url) ?>" class="w-10 h-10 rounded-full object-cover border border-gray-200 shadow-sm bg-gray-100">
                <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center font-bold text-[#54A6D8] border border-blue-100 shadow-sm text-lg select-none tracking-wide">
                        <?= htmlspecialchars($iniciales_avatar) ?>
                    </div>
                <?php endif; ?>
                <?php if ($otro_online): ?>
                <span class="absolute bottom-0 right-0 w-2.5 h-2.5 bg-green-500 border-2 border-white rounded-full"></span>
                <?php endif; ?>
            </div>
            
            <div class="leading-tight overflow-hidden pl-1">
                <h1 class="font-bold text-gray-900 text-[15px] truncate">
                    <?= htmlspecialchars($nombre_mostrar) ?>
                </h1>
                <p class="text-[11px] text-gray-500 truncate max-w-[160px] opacity-90">
                    <?= htmlspecialchars($chat['servicio_titulo']) ?>
                </p>
            </div>
        </div>

        <div class="shrink-0 pl-2">
            <?php if(!$esVendedor): ?>
                <div class="flex items-center gap-1.5">
                    <button type="button" id="btn-abrir-modal-cupon"
                            class="w-9 h-9 flex items-center justify-center rounded-full text-gray-400 hover:text-[#54A6D8] hover:bg-blue-50 transition-colors shrink-0"
                            title="¿Tienes un código de beca?">
                        <?= icon('ticket', 'w-4 h-4') ?>
                    </button>
                    <a href="/app/contratar_servicio.php?servicio_id=<?= (int)$chat['servicio_id'] ?>"
                       id="btn-contratar-chat"
                       data-href-base="/app/contratar_servicio.php?servicio_id=<?= (int)$chat['servicio_id'] ?>"
                       class="flex items-center gap-1 bg-gradient-to-r from-[#54A6D8] to-blue-600 hover:to-blue-700 text-white px-3 py-2.5 rounded-full text-xs font-bold shadow-md shadow-blue-200 transition transform active:scale-95">
                        <span id="txt-btn-contratar-chat">Contratar</span>
                    </a>
                </div>
            <?php else: ?>
                <button type="button" id="btn-generar-reserva" class="flex items-center gap-1 bg-gray-50 hover:bg-gray-100 text-gray-700 border border-gray-200 px-3 py-2.5 rounded-full text-xs font-bold transition active:scale-95">
                    Generar Reserva
                </button>
            <?php endif; ?>
        </div>
    </header>

<main id="chat-container" class="flex-1 overflow-y-auto p-4 pb-4 space-y-3 w-full relative no-scrollbar">
        
        <div class="flex justify-center mb-6 mt-2">
            <?php if (!$esVendedor): ?>
            <div class="bg-amber-50 text-amber-900 text-[11px] px-4 py-3 rounded-xl max-w-[85%] border border-amber-200 leading-snug">
                <div class="flex items-center gap-1.5 mb-2">
                    <?= icon('shield-check', 'w-4 h-4 text-amber-700 shrink-0') ?>
                    <span class="font-bold">¿Cómo contratar?</span>
                </div>
                <div class="space-y-1 text-amber-800">
                    <p>1. Acuerda día, hora y precio aquí</p>
                    <p>2. Pulsa <b>Contratar</b> arriba ↑</p>
                    <p>3. Pago en custodia — solo se libera al tutor cuando confirmes la clase</p>
                </div>
                <p class="mt-2 font-bold text-amber-900">Solo pagando por Nubira tu dinero queda protegido.</p>
            </div>
            <?php else: ?>
            <div class="bg-amber-50 text-amber-900 text-[11px] px-4 py-2.5 rounded-xl max-w-[85%] border border-amber-200 leading-snug flex items-start gap-1.5">
                <?= icon('shield-check', 'w-4 h-4 text-amber-700 shrink-0 mt-px') ?>
                <span><b>Cobra de forma segura:</b> Acepta el pago solo por Nubira. Si lo haces por fuera, no tienes garantía si el estudiante no aparece o pide reembolso.</span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- [NUBIRA 2.0] Mensajes pre-renderizados desde PHP — apertura instantánea -->
        <div id="mensajes-wrapper"><?= $html_mensajes_iniciales ?></div>

<!-- [NUBIRA 2.0] Typing indicator - estilo WhatsApp/Airbnb -->
<div id="typing-indicator" class="justify-start mb-2 fade-in">
    <div class="bubble-other relative max-w-[85%] md:max-w-[70%] px-4 py-3 shadow-sm flex items-center gap-1.5">
        <span class="typing-dot"></span>
        <span class="typing-dot"></span>
        <span class="typing-dot"></span>
    </div>
</div>

<div id="scroll-anchor" class="h-1 w-full"></div>
        
      
    </main>

    <footer class="bg-white px-3 py-2 border-t border-gray-100 shrink-0 w-full z-20 pb-safe shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)]">
        
        <?php if ($tutor_inactivo): ?>
            <div class="max-w-4xl mx-auto w-full bg-orange-50 border border-orange-200 rounded-2xl p-3 flex flex-col md:flex-row items-center justify-between gap-3 text-center md:text-left mb-3">
                <div class="flex items-center gap-3">
                     <div class="w-8 h-8 bg-orange-100 text-orange-500 rounded-full flex items-center justify-center shrink-0">
                        <?= icon('user', 'w-4 h-4') ?>
                    </div>
                    <div>
                        <h4 class="text-[12px] font-bold text-orange-800 tracking-tight">Tutor inactivo (más de 48 hrs)</h4>
                        <p class="text-[11px] text-orange-600 mt-0.5">El chat ha sido pausado para evitar esperas. Te sugerimos buscar otra opción.</p>
                    </div>
                </div>
                <a href="/vitrina" class="bg-white hover:bg-orange-100 text-orange-600 border border-orange-200 px-3 py-1.5 rounded-xl text-xs font-bold transition-colors shrink-0 shadow-sm whitespace-nowrap">
                    Buscar otro servicio <?= icon('arrow-right', 'w-3.5 h-3.5 ml-1 inline') ?>
                </a>
            </div>
        <?php endif; ?>

        <?php if ($destinatario_suspendido): ?>
            <div class="max-w-4xl mx-auto w-full bg-gray-50 border border-gray-200 rounded-2xl p-3 flex items-center gap-3 text-center md:text-left mb-3">
                <div class="w-8 h-8 bg-gray-100 text-gray-500 rounded-full flex items-center justify-center shrink-0">
                    <?= icon('user', 'w-4 h-4') ?>
                </div>
                <div>
                    <h4 class="text-[12px] font-bold text-gray-700 tracking-tight">Cuenta no disponible</h4>
                    <p class="text-[11px] text-gray-500 mt-0.5">Esta persona no está disponible temporalmente.</p>
                </div>
            </div>
        <?php endif; ?>

        <div id="banner-limite-mensajes" class="max-w-4xl mx-auto w-full bg-sky-50 border border-sky-200 rounded-2xl p-3 flex flex-col md:flex-row items-center justify-between gap-3 text-center md:text-left mb-3 <?= $limite_mensajes_alcanzado ? '' : 'hidden' ?>">
            <div class="flex items-center gap-3">
                 <div class="w-8 h-8 bg-sky-100 text-[#54A6D8] rounded-full flex items-center justify-center shrink-0">
                    <?= icon('chat-bubble', 'w-4 h-4') ?>
                </div>
                <div>
                    <h4 class="text-[12px] font-bold text-sky-800 tracking-tight">Llegaste al límite de mensajes</h4>
                    <p class="text-[11px] text-sky-600 mt-0.5">Llegaste al límite de mensajes antes de contratar. Si quieres seguir conversando, avanza con la contratación del servicio.</p>
                </div>
            </div>
        </div>

        <?php $chat_bloqueado = $tutor_inactivo || $destinatario_suspendido || $limite_mensajes_alcanzado; ?>
        <form id="form-chat" class="flex items-end gap-2 max-w-4xl mx-auto w-full relative <?= $chat_bloqueado ? 'opacity-50 pointer-events-none grayscale-[50%]' : '' ?>">
    <input type="hidden" name="conversacion_id" value="<?= $chat_id ?>">
    
    <!-- ============================================ -->
    <!-- NUBIRA 2.0 — Botón adjuntar archivo -->
    <!-- ============================================ -->
    <input 
        type="file" 
        id="input-archivo" 
        accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"
        class="hidden"
    >
    <div class="feature-host shrink-0">
        <button 
            type="button" 
            id="btn-adjuntar"
            class="w-11 h-11 rounded-full flex items-center justify-center text-gray-500 hover:text-[#54A6D8] hover:bg-blue-50 transition-all active:scale-90"
            title="Adjuntar archivo"
        >
            <?= icon('paperclip', 'w-5 h-5') ?>
        </button>
        <span
            class="feature-badge" 
            id="badge-adjuntar"
            data-feature-key="adjuntar_archivos"
            data-feature-launch="2026-04-25"
        >Nuevo</span>
    </div>
    <!-- ============================================ -->
    
    <div class="relative flex-1 bg-gray-100 rounded-[24px] flex items-center px-4 py-1 border border-transparent focus-within:border-blue-200 focus-within:bg-white focus-within:shadow-sm transition-all duration-200">
                <?php
                    $placeholder_bloqueo = 'Chat pausado por inactividad...';
                    if ($destinatario_suspendido) $placeholder_bloqueo = 'Esta persona no está disponible...';
                    elseif ($limite_mensajes_alcanzado) $placeholder_bloqueo = 'Límite de mensajes alcanzado...';
                ?>
                <textarea
                    name="mensaje"
                    id="input-msg"
                    rows="1"
                    <?= $chat_bloqueado ? 'disabled placeholder="' . $placeholder_bloqueo . '"' : 'placeholder="Escribe un mensaje..."' ?>
                    class="w-full bg-transparent text-gray-900 text-sm focus:outline-none resize-none max-h-32 py-1 leading-relaxed <?= $chat_bloqueado ? 'placeholder-gray-500 font-medium' : 'placeholder-gray-400' ?>"
                ></textarea>
            </div>

            <button type="submit" id="btn-enviar" disabled class="bg-[#54A6D8] text-white w-11 h-11 rounded-full flex items-center justify-center hover:bg-blue-600 hover:shadow-md transition-all shadow-sm shrink-0 disabled:opacity-50 disabled:cursor-not-allowed transform active:scale-90 disabled:active:scale-100">
                <?= icon('paper-airplane', 'w-5 h-5') ?>
            </button>
        </form>
        
    </footer>

<!-- ============================================ -->
<!-- NUBIRA 2.0 — Modal preview de archivo -->
<!-- ============================================ -->
<div 
    id="modal-archivo" 
    class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm items-center justify-center p-4 transition-opacity duration-200"
>
    <div 
        id="modal-archivo-card" 
        class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden transform translate-y-4 opacity-0 transition-all duration-200"
    >
        <!-- Header -->
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 bg-blue-50 text-[#54A6D8] rounded-full flex items-center justify-center shrink-0">
                    <?= icon('paperclip', 'w-4 h-4') ?>
                </div>
                <h3 class="font-bold text-gray-900 text-[15px]">Enviar archivo</h3>
            </div>
            <button 
                type="button" 
                id="btn-cerrar-modal-archivo" 
                class="text-gray-400 hover:text-gray-600 w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-50 transition-colors"
            >
                <?= icon('x-mark', 'w-5 h-5') ?>
            </button>
        </div>

        <!-- Preview del archivo -->
        <div class="p-5">
            <!-- Preview imagen -->
            <div id="preview-imagen" class="hidden mb-4">
                <img 
                    id="preview-imagen-tag" 
                    src="" 
                    class="w-full max-h-72 object-contain rounded-xl bg-gray-50 border border-gray-100"
                >
            </div>

            <!-- Preview PDF/genérico -->
            <div id="preview-archivo" class="hidden mb-4 bg-gray-50 rounded-xl border border-gray-100 p-4 flex items-center gap-3">
                <div class="w-12 h-12 bg-red-50 text-red-500 rounded-xl flex items-center justify-center shrink-0">
                    <?= icon('publish-doc', 'w-8 h-8') ?>
                </div>
                <div class="flex-1 min-w-0">
                    <p id="preview-archivo-nombre" class="font-medium text-gray-900 text-sm truncate"></p>
                    <p id="preview-archivo-peso" class="text-xs text-gray-500 mt-0.5"></p>
                </div>
            </div>

            <!-- Mensaje opcional -->
            <textarea 
                id="caption-archivo"
                rows="2"
                placeholder="Agrega un mensaje (opcional)..."
                class="w-full bg-gray-50 border border-gray-100 rounded-xl px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:border-blue-200 focus:bg-white resize-none"
                maxlength="500"
            ></textarea>
        </div>

        <!-- Footer con botones -->
        <div class="px-5 pb-5 flex items-center gap-2">
            <button 
                type="button" 
                id="btn-cancelar-archivo" 
                class="flex-1 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-xl transition-colors"
            >
                Cancelar
            </button>
            <button 
                type="button" 
                id="btn-enviar-archivo" 
                class="flex-1 py-2.5 text-sm font-bold text-white bg-[#54A6D8] hover:bg-blue-600 rounded-xl shadow-sm hover:shadow-md transition-all active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed"
            >
                <?= icon('paper-airplane', 'w-4 h-4 mr-1 inline') ?>
                Enviar
            </button>
        </div>
    </div>
</div>
<!-- ============================================ -->
    <script>
        const chatContainer = document.getElementById('chat-container');
        const loader = document.getElementById('loader');
        const form = document.getElementById('form-chat');
        const input = document.getElementById('input-msg');
        const btnSend = document.getElementById('btn-enviar');
        // ============================================
        // [NUBIRA 2.0] Refs para sistema de archivos adjuntos
        // ============================================
        const inputArchivo = document.getElementById('input-archivo');
        const btnAdjuntar = document.getElementById('btn-adjuntar');
        const modalArchivo = document.getElementById('modal-archivo');
        const modalArchivoCard = document.getElementById('modal-archivo-card');
        const btnCerrarModalArchivo = document.getElementById('btn-cerrar-modal-archivo');
        const btnCancelarArchivo = document.getElementById('btn-cancelar-archivo');
        const btnEnviarArchivo = document.getElementById('btn-enviar-archivo');
        const previewImagen = document.getElementById('preview-imagen');
        const previewImagenTag = document.getElementById('preview-imagen-tag');
        const previewArchivo = document.getElementById('preview-archivo');
        const previewArchivoNombre = document.getElementById('preview-archivo-nombre');
        const previewArchivoPeso = document.getElementById('preview-archivo-peso');
        const captionArchivo = document.getElementById('caption-archivo');

        let archivoSeleccionado = null;

        // Constantes de validación (deben coincidir con el backend)
        const PESO_MAX_BYTES = 10 * 1024 * 1024; // 10 MB
        const EXT_PERMITIDAS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        
        // Elementos del Toast
        const toastContainer = document.getElementById('toast-container');
        const toastMsg = document.getElementById('toast-msg');

        const chatId = <?= $chat_id ?>;
        let esPrimeraCarga = true; // [NUBIRA 2.0] Distingue apertura del chat vs polling
        
        // [NUBIRA 2.0] TYPING INDICATOR - Sistema de notificación "está escribiendo..."
const typingIndicator = document.getElementById('typing-indicator');
let typingLastPing = 0;      // Timestamp del último ping enviado
let typingEnviando = false;  // Lock para no duplicar requests

function pingTyping() {
    const ahora = Date.now();
    // Throttle: enviamos un ping como máximo cada 2 segundos
    if (ahora - typingLastPing < 2000 || typingEnviando) return;
    typingLastPing = ahora;
    typingEnviando = true;

    const fd = new FormData();
    fd.append('conversacion_id', chatId);

    fetch('/app/typing_set.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .catch(() => {}) // Fallos silenciosos: no es crítico
        .finally(() => { typingEnviando = false; });
}

function mostrarTyping(visible) {
    if (!typingIndicator) return;
    const estaVisible = typingIndicator.classList.contains('is-visible');
    if (visible && !estaVisible) {
        typingIndicator.classList.add('is-visible');
        // Si el usuario estaba al fondo, lo mantenemos abajo
        if (isUserAtBottom()) scrollToBottom(true);
    } else if (!visible && estaVisible) {
        typingIndicator.classList.remove('is-visible');
    }
}
        
        // FUNCIÓN: Mostrar Toast y dejarlo fijo
        function showToast(message) {
            toastMsg.innerText = message;
            toastContainer.classList.remove('hidden', 'fade-out-up');
            toastContainer.classList.add('fade-in-down');
        }

        // FUNCIÓN: Ocultar Toast
        function hideToast() {
            toastContainer.classList.remove('fade-in-down');
            toastContainer.classList.add('fade-out-up');
            setTimeout(() => {
                toastContainer.classList.add('hidden');
            }, 300);
        }

        input.addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = (this.scrollHeight) + 'px';
    if(btnSend) btnSend.disabled = this.value.trim() === '';
    
    // [NUBIRA 2.0] Notificar al otro que estoy escribiendo (solo si hay texto)
    if (this.value.trim().length > 0) {
        pingTyping();
    }
});

      input.addEventListener('focus', () => {
    if (!toastContainer.classList.contains('hidden')) {
        hideToast();
    }
    
    // [NUBIRA 2.0] iOS Safari fix V1: 
    // Forzamos el recálculo del viewport ANTES de scrollear al fondo.
    // Sin esto, iOS Safari calcula con el teclado cerrado y el footer queda tapado.
    const corregirViewport = () => {
        if (window.visualViewport) {
            const vh = window.visualViewport.height * 0.01;
            document.documentElement.style.setProperty('--vh', `${vh}px`);
        }
        // Anclar el body al top (iOS intenta scrollearlo solo)
        window.scrollTo(0, 0);
        document.body.scrollTop = 0;
        // Scroll al fondo del chat (donde está el último mensaje)
        chatContainer.scrollTop = chatContainer.scrollHeight;
    };
    
    // Triple golpe: iOS hace varios intentos de "ayudar" durante 300ms
    requestAnimationFrame(corregirViewport);
    setTimeout(corregirViewport, 100);
    setTimeout(corregirViewport, 300);
    setTimeout(corregirViewport, 500);
});
// [NUBIRA 2.0] Al perder el foco (teclado cierra), reanclar otra vez
input.addEventListener('blur', () => {
    setTimeout(() => {
        window.scrollTo(0, 0);
        document.body.scrollTop = 0;
    }, 50);
});
// [NUBIRA 2.0] Control total del viewport en móvil cuando abre/cierra teclado
function actualizarAlturaVH() {
    // visualViewport da la altura REAL disponible (sin teclado)
    const vh = window.visualViewport
        ? window.visualViewport.height * 0.01
        : window.innerHeight * 0.01;
    document.documentElement.style.setProperty('--vh', `${vh}px`);
}

// Setup inicial
actualizarAlturaVH();

if ('visualViewport' in window) {
    let resizeTimer;
    window.visualViewport.addEventListener('resize', () => {
        actualizarAlturaVH();

        // Si el usuario está escribiendo, scroll al fondo cuando el teclado abra
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    if (document.activeElement === input) scrollToBottom(false);
                });
            });
        }, 50);
    });

    // Cuando el teclado cierra, también refrescamos
    window.visualViewport.addEventListener('scroll', () => {
        if (document.activeElement === input) {
            scrollToBottom(false);
        }
    });
}

// Respaldo: si el usuario rota el teléfono
window.addEventListener('orientationchange', () => {
    setTimeout(actualizarAlturaVH, 200);
});

        input.addEventListener('keydown', (e) => {
            if(e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if(btnSend && !btnSend.disabled) form.requestSubmit();
            }
        });

        function scrollToBottom(smooth = false) {
            // Doble estrategia: scrollIntoView Y scrollTop directo (más confiable en móvil)
            const anchor = document.getElementById('scroll-anchor');
            if (anchor) {
                anchor.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'end' });
            }
            // Fallback directo: forzamos scrollTop al máximo
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }

        // Escapar HTML para prevenir XSS en mensaje optimista
        function escapeHTML(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // Pintar mensaje optimista en el DOM
        function pintarOptimista(tempId, texto) {
            const wrapper = document.getElementById('mensajes-wrapper');
            if (!wrapper) return;
            
            const ahora = new Date();
            const hora = ahora.getHours().toString().padStart(2,'0') + ':' + ahora.getMinutes().toString().padStart(2,'0');
            
            const html = `
                <div class="flex w-full justify-end fade-in mb-2 group" data-temp-id="${tempId}">
                    <div class="bubble-me bubble-pending relative max-w-[85%] md:max-w-[70%] px-4 py-2 shadow-sm text-[14px] leading-snug break-words">
                        ${escapeHTML(texto).replace(/\n/g, '<br>')}
                        <div class="text-[10px] flex items-center justify-end gap-1 mt-1 select-none opacity-80">
                            <span class="text-blue-50">${hora}</span>
                            <span class="text-blue-200/60">
                                <i class="fa-regular fa-clock"></i>
                            </span>
                            <span class="retry-btn" onclick="reintentarMensaje('${tempId}')">
                                <i class="fa-solid fa-rotate-right text-red-600"></i>
                            </span>
                        </div>
                    </div>
                </div>
            `;
            wrapper.insertAdjacentHTML('beforeend', html);
            scrollToBottom(false);
        }

        // Marcar un mensaje optimista como fallido
        function marcarFallido(tempId) {
            const elem = document.querySelector(`[data-temp-id="${tempId}"] .bubble-me`);
            if (elem) {
                elem.classList.remove('bubble-pending');
                elem.classList.add('bubble-failed');
            }
        }

        // Quitar un mensaje optimista (cuando llega el real por polling)
        function removerOptimistas() {
            document.querySelectorAll('[data-temp-id]').forEach(el => el.remove());
        }

        // Reintentar envío
        const mensajesFallidos = {};
        async function reintentarMensaje(tempId) {
            const data = mensajesFallidos[tempId];
            if (!data) return;
            delete mensajesFallidos[tempId];
            document.querySelector(`[data-temp-id="${tempId}"]`)?.remove();
            await enviarMensaje(data.texto);
        }

        // Lógica de envío reusable
        async function enviarMensaje(texto) {
            const tempId = 'tmp-' + Date.now() + '-' + Math.random().toString(36).substr(2, 5);
            pintarOptimista(tempId, texto);

            const formData = new FormData();
            formData.append('conversacion_id', chatId);
            formData.append('mensaje', texto);

            try {
                const res = await fetch('/app/enviar_mensaje.php', { method: 'POST', body: formData });
                const data = await res.json();
                
                if(data.success) {
                    if (data.mostrar_banner_express) mostrarBannerExpress();
                    // Forzamos polling inmediato para traer el mensaje real
                    pollIntervalo = POLL_MIN;
                    const huboCambio = await actualizarChat();
                    if (huboCambio) {
                        // actualizarChat ya refrescó el wrapper, los optimistas quedaron borrados
                    } else {
                        // Si no llegó todavía (raro), dejamos el optimista pero con check
                        const elem = document.querySelector(`[data-temp-id="${tempId}"] .bubble-pending`);
                        if (elem) {
                            elem.classList.remove('bubble-pending');
                            const iconPending = elem.querySelector('.fa-clock');
                            if (iconPending) {
                                iconPending.classList.remove('fa-regular', 'fa-clock');
                                iconPending.classList.add('fa-solid', 'fa-check');
                            }
                        }
                    }
                } else {
                    if (data.requiere_completar) { mostrarModalExpress(); return; }
                    if (data.limite_alcanzado) {
                        document.getElementById('banner-limite-mensajes')?.classList.remove('hidden');
                        input.disabled = true;
                        input.placeholder = 'Límite de mensajes alcanzado...';
                        form.classList.add('opacity-50', 'pointer-events-none', 'grayscale-[50%]');
                    }
                    marcarFallido(tempId);
                    mensajesFallidos[tempId] = { texto: texto };
                    showToast(data.error || 'Error al enviar. Toca el ícono de reintentar.');
                }
            } catch (err) { 
                marcarFallido(tempId);
                mensajesFallidos[tempId] = { texto: texto };
                showToast('Error de conexión. Toca el ícono de reintentar.');
            }
        }

// Handler del form
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const txt = input.value.trim();
    if(!txt) return;

    input.value = '';
    input.style.height = '';
    if(btnSend) btnSend.disabled = true;

    // [NUBIRA 2.0] iOS Safari fix: tras enviar, mantener el teclado abierto Y el viewport correcto
    // El truco: NO cerramos el foco. Solo recalculamos --vh por si iOS lo movió.
    const reanclarViewport = () => {
        if (window.visualViewport) {
            const vh = window.visualViewport.height * 0.01;
            document.documentElement.style.setProperty('--vh', `${vh}px`);
        }
        window.scrollTo(0, 0);
        document.body.scrollTop = 0;
        chatContainer.scrollTop = chatContainer.scrollHeight;
    };
    
    // Mantener el foco SIN re-disparar el evento (preventScroll evita que iOS scrollee solo)
    input.focus({ preventScroll: true });
    
    // Triple corrección durante los primeros 300ms post-envío
    requestAnimationFrame(reanclarViewport);
    setTimeout(reanclarViewport, 100);
    setTimeout(reanclarViewport, 300);

    await enviarMensaje(txt);
});
        // Exponer reintentar al scope global (para el onclick)
        window.reintentarMensaje = reintentarMensaje;

        function mostrarBannerExpress() {
            const b = document.getElementById('banner-express');
            if (b) {
                b.classList.remove('hidden');
                requestAnimationFrame(() => { chatContainer.scrollTop = chatContainer.scrollHeight; });
            }
        }
        function mostrarModalExpress() {
            const m = document.getElementById('modal-express');
            if (m) m.classList.remove('hidden');
        }
        function cerrarModalExpress() {
            const m = document.getElementById('modal-express');
            if (m) m.classList.add('hidden');
        }
        window.cerrarModalExpress = cerrarModalExpress;

        // [NUBIRA 2.0] Detector de posición (Smart Scroll)
        // [NUBIRA 2.0] Detector de posición (Smart Scroll)
        // Tolerancia mayor para que archivos grandes no rompan el "auto-scroll al fondo"
        function isUserAtBottom() {
            return chatContainer.scrollHeight - chatContainer.scrollTop - chatContainer.clientHeight < 300;
        }

        async function actualizarChat() {
            try {
                const res = await fetch(`/app/cargar_mensajes.php?id=${chatId}&contexto=conversacion`);
                const html = await res.text();
                
                // [NUBIRA 2.0] Leer estado de typing del otro usuario
                mostrarTyping(res.headers.get('X-Typing-Otro') === '1');
                
                const currentHtml = chatContainer.getAttribute('data-last-html');
                
                // Sin cambios → salimos rápido
                if (html.trim() === currentHtml) {
                    return false;
                }
                
                const estabaAbajo = isUserAtBottom();
                
                // Renderizar nuevo HTML
                const wrapper = document.getElementById('mensajes-wrapper');
                if (wrapper) {
                    wrapper.innerHTML = html;
                }
                chatContainer.setAttribute('data-last-html', html.trim());
                
                // Ocultar loader
                if (loader) { 
                    loader.classList.add('opacity-0'); 
                    setTimeout(() => loader.style.display = 'none', 300); 
                }
                
                // Marcar imágenes ya cargadas (quita shimmer) y enganchar las pendientes
                if (wrapper) {
                    wrapper.querySelectorAll('img').forEach(img => {
                        if (img.complete && img.naturalHeight > 0) {
                            img.classList.add('loaded');
                        } else {
                            img.addEventListener('load', () => {
                                img.classList.add('loaded');
                                // Si el usuario sigue al fondo, lo mantenemos pegado abajo
                                if (isUserAtBottom()) {
                                    chatContainer.scrollTop = chatContainer.scrollHeight;
                                }
                            }, { once: true });
                            img.addEventListener('error', () => img.classList.add('loaded'), { once: true });
                        }
                    });
                }
                
                // Decisión de scroll: solo si era primera carga o el usuario estaba abajo
                if (esPrimeraCarga || estabaAbajo) {
                    // [NUBIRA 2.0] Scroll instantáneo al fondo (estilo WhatsApp)
                    // Triple RAF para garantizar que el layout se aplicó tras innerHTML
                    chatContainer.scrollTop = chatContainer.scrollHeight;
                    requestAnimationFrame(() => {
                        chatContainer.scrollTop = chatContainer.scrollHeight;
                        requestAnimationFrame(() => {
                            chatContainer.scrollTop = chatContainer.scrollHeight;
                        });
                    });
                    
                    if (esPrimeraCarga) {
                        esPrimeraCarga = false;
                    }
                }
                
                return true; // Hubo cambios
                
            } catch(e) {
                console.error('Error en actualizarChat:', e);
                return false;
            }
        }
        
       // [NUBIRA 2.0] Polling adaptativo + pausa en background
        let pollTimer = null;
        let pollIntervalo = 3000;
        const POLL_MIN = 3000;
        const POLL_MAX = 20000;

        function agendarPoll() {
            clearTimeout(pollTimer);
            if (document.hidden) return; // Tab oculto: no programamos nada
            pollTimer = setTimeout(async () => {
                const huboCambio = await actualizarChat();
                if (huboCambio) {
                    pollIntervalo = POLL_MIN;
                } else {
                    pollIntervalo = Math.min(pollIntervalo + 2000, POLL_MAX);
                }
                agendarPoll();
            }, pollIntervalo);
        }

        // Cuando el tab cambia de visibilidad
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                clearTimeout(pollTimer);
            } else {
                pollIntervalo = POLL_MIN; // Al volver, arrancamos rápido
                actualizarChat().then(agendarPoll);
            }
        });

        // Cuando el usuario escribe, probablemente viene respuesta: acelerar
        input.addEventListener('input', () => {
            pollIntervalo = POLL_MIN;
        });

      // [NUBIRA 2.0] Arranque inicial:
        // Los mensajes ya están pre-renderizados desde PHP (apertura instantánea).
        // 1) Inicializamos el "snapshot" del HTML para que el polling detecte cambios.
        // 2) Hacemos scroll instantáneo al fondo (estilo WhatsApp).
        // 3) Marcamos imágenes ya cargadas para quitar el shimmer.
        // 4) Arrancamos el polling normal — que solo actuará cuando haya cambios reales.
        (function arranqueInicialNubira() {
            const wrapper = document.getElementById('mensajes-wrapper');
            
            // 1) Snapshot del HTML inicial para que el polling no lo "redetecte" como cambio
            if (wrapper) {
                chatContainer.setAttribute('data-last-html', wrapper.innerHTML.trim());
            }
            
            // 2) Scroll instantáneo al fondo (triple RAF para garantizar el layout)
            chatContainer.scrollTop = chatContainer.scrollHeight;
            requestAnimationFrame(() => {
                chatContainer.scrollTop = chatContainer.scrollHeight;
                requestAnimationFrame(() => {
                    chatContainer.scrollTop = chatContainer.scrollHeight;
                });
            });
            
            // 3) Marcar imágenes ya cargadas (quita shimmer) y enganchar las pendientes
            if (wrapper) {
                wrapper.querySelectorAll('img').forEach(img => {
                    if (img.complete && img.naturalHeight > 0) {
                        img.classList.add('loaded');
                    } else {
                        img.addEventListener('load', () => {
                            img.classList.add('loaded');
                            if (isUserAtBottom()) {
                                chatContainer.scrollTop = chatContainer.scrollHeight;
                            }
                        }, { once: true });
                        img.addEventListener('error', () => img.classList.add('loaded'), { once: true });
                    }
                });
            }
            
            // 4) La primera carga ya ocurrió en el HTML — marcamos esPrimeraCarga como false
            esPrimeraCarga = false;
            
            // 5) Arrancar polling para mensajes nuevos
            agendarPoll();
        })();
        
        if(window.innerWidth > 768) input.focus();
        // ============================================
        // [NUBIRA 2.0] SISTEMA DE ARCHIVOS ADJUNTOS
        // ============================================

        // Helper: formatear bytes a "1.2 MB" / "245 KB"
        function formatPeso(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        }

        // Abrir el selector de archivos al tocar el clip
        btnAdjuntar.addEventListener('click', () => {
            // Reset por si quedó algo de antes
            inputArchivo.value = '';
            inputArchivo.click();
        });

        // Cuando el usuario elige un archivo
        inputArchivo.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;

            // Validación 1: extensión
            const ext = file.name.split('.').pop().toLowerCase();
            if (!EXT_PERMITIDAS.includes(ext)) {
                showToast('Solo se permiten imágenes (JPG, PNG, WebP) y PDF.');
                inputArchivo.value = '';
                return;
            }

            // Validación 2: peso
            if (file.size > PESO_MAX_BYTES) {
                showToast('El archivo no debe superar los 10 MB.');
                inputArchivo.value = '';
                return;
            }
            if (file.size === 0) {
                showToast('El archivo está vacío.');
                inputArchivo.value = '';
                return;
            }

            archivoSeleccionado = file;
            mostrarPreview(file);
        });

        // Mostrar preview en el modal según tipo
        function mostrarPreview(file) {
            const esImagen = file.type.startsWith('image/');

            if (esImagen) {
                // Preview visual de imagen
                const reader = new FileReader();
                reader.onload = (ev) => {
                    previewImagenTag.src = ev.target.result;
                    previewImagen.classList.remove('hidden');
                    previewArchivo.classList.add('hidden');
                };
                reader.readAsDataURL(file);
            } else {
                // Preview de PDF/genérico (icono + nombre + peso)
                previewArchivoNombre.textContent = file.name;
                previewArchivoPeso.textContent = formatPeso(file.size);
                previewArchivo.classList.remove('hidden');
                previewImagen.classList.add('hidden');
            }

            captionArchivo.value = '';
            abrirModalArchivo();
        }

        // Abrir modal con animación
        function abrirModalArchivo() {
            modalArchivo.classList.remove('hidden');
            modalArchivo.classList.add('flex');
            // Forzar reflow para que la animación arranque
            void modalArchivoCard.offsetWidth;
            requestAnimationFrame(() => {
                modalArchivoCard.classList.remove('translate-y-4', 'opacity-0');
                modalArchivoCard.classList.add('translate-y-0', 'opacity-100');
            });
        }

        // Cerrar modal con animación
        function cerrarModalArchivo() {
            modalArchivoCard.classList.add('translate-y-4', 'opacity-0');
            modalArchivoCard.classList.remove('translate-y-0', 'opacity-100');
            setTimeout(() => {
                modalArchivo.classList.add('hidden');
                modalArchivo.classList.remove('flex');
                archivoSeleccionado = null;
                inputArchivo.value = '';
                previewImagenTag.src = '';
            }, 200);
        }

        // Listeners de cierre
        btnCerrarModalArchivo.addEventListener('click', cerrarModalArchivo);
        btnCancelarArchivo.addEventListener('click', cerrarModalArchivo);
        modalArchivo.addEventListener('click', (e) => {
            if (e.target === modalArchivo) cerrarModalArchivo();
        });

        // Enviar el archivo
        btnEnviarArchivo.addEventListener('click', async () => {
            if (!archivoSeleccionado) return;

            const file = archivoSeleccionado;
            const caption = captionArchivo.value.trim();

            // Bloqueamos el botón para evitar doble click
            btnEnviarArchivo.disabled = true;
            btnEnviarArchivo.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-sm mr-1"></i> Enviando...';

            // Cerramos el modal de inmediato (UX rápida estilo WhatsApp)
            cerrarModalArchivo();

            try {
                const formData = new FormData();
                formData.append('conversacion_id', chatId);
                formData.append('archivo', file);
                if (caption) formData.append('caption', caption);

                const res = await fetch('/app/enviar_archivo.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });

                const data = await res.json();

                if (data.success) {
                    // Forzamos polling inmediato para traer el mensaje real
                    pollIntervalo = POLL_MIN;
                    await actualizarChat();
                } else {
                    showToast(data.error || 'No se pudo enviar el archivo.');
                }
            } catch (err) {
                showToast('Error de conexión al enviar el archivo.');
            } finally {
                // Restauramos el botón (aunque el modal ya esté cerrado)
                btnEnviarArchivo.disabled = false;
                btnEnviarArchivo.innerHTML = '<i class="fa-solid fa-paper-plane text-xs mr-1"></i> Enviar';
            }
        });

        // ============================================
        // FIN SISTEMA DE ARCHIVOS ADJUNTOS
        // ============================================
        // ============================================
        // [NUBIRA 2.0] SISTEMA DE BADGES "NUEVO" REUTILIZABLE
        // ============================================
        // Funcionamiento:
        // - Lee todos los .feature-badge del DOM
        // - Auto-oculta badges si han pasado >14 días desde data-feature-launch
        // - Auto-oculta badges que el usuario ya vio (localStorage)
        // - Marca como "visto" cuando el usuario interactúa con el botón asociado
        // ============================================
        (function inicializarFeatureBadges() {
            const DIAS_VIDA_BADGE = 14;
            const STORAGE_KEY = 'nubira_features_vistas';

            // Helper: leer features vistas desde localStorage
            function leerVistas() {
                try {
                    const raw = localStorage.getItem(STORAGE_KEY);
                    return raw ? JSON.parse(raw) : {};
                } catch (e) {
                    return {};
                }
            }

            // Helper: marcar feature como vista
            function marcarVista(key) {
                try {
                    const vistas = leerVistas();
                    vistas[key] = Date.now();
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(vistas));
                } catch (e) {
                    // Si localStorage falla (modo privado, etc.), simplemente no persiste
                }
            }

            // Helper: ocultar badge con animación
            function ocultarBadge(badge) {
                if (!badge || badge.classList.contains('is-hidden')) return;
                badge.classList.add('is-hidden');
                // Lo removemos del DOM tras la animación para limpiar
                setTimeout(() => badge.remove(), 350);
            }

            // Procesar todos los badges del DOM
            const badges = document.querySelectorAll('.feature-badge[data-feature-key]');
            const vistas = leerVistas();
            const ahora = Date.now();

            badges.forEach(badge => {
                const key = badge.dataset.featureKey;
                const launch = badge.dataset.featureLaunch;

                // Caso 1: ya fue visto por este usuario
                if (vistas[key]) {
                    ocultarBadge(badge);
                    return;
                }

                // Caso 2: pasó la ventana de 14 días desde el lanzamiento
                if (launch) {
                    const launchTime = new Date(launch + 'T00:00:00').getTime();
                    const diasTranscurridos = (ahora - launchTime) / (1000 * 60 * 60 * 24);
                    if (diasTranscurridos > DIAS_VIDA_BADGE) {
                        ocultarBadge(badge);
                        return;
                    }
                }

                // Caso 3: badge activo → conectar al botón hermano para auto-marcar como visto
                const host = badge.closest('.feature-host');
                if (!host) return;

                const trigger = host.querySelector('button, a');
                if (!trigger) return;

                trigger.addEventListener('click', () => {
                    marcarVista(key);
                    ocultarBadge(badge);
                }, { once: true });
            });
        })();
    </script>

<!-- ============================================ -->
<!-- NUBIRA 2.0 — Lightbox visor de imágenes chat -->
<!-- ============================================ -->
<div id="lightbox-img" class="hidden fixed inset-0 z-[100] bg-black/90 items-center justify-center p-4 opacity-0 transition-opacity duration-200">
    <button id="lightbox-cerrar" class="absolute top-4 right-4 w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-colors">
        <?= icon('x-mark', 'w-6 h-6') ?>
    </button>
    <img id="lightbox-contenido" src="" alt="" class="max-w-full max-h-full object-contain rounded-lg">
</div>

<script>
(function(){
    const lb = document.getElementById('lightbox-img');
    const lbImg = document.getElementById('lightbox-contenido');
    const lbClose = document.getElementById('lightbox-cerrar');

    function abrir(src){
        lbImg.src = src;
        lb.classList.remove('hidden');
        lb.classList.add('flex');
        requestAnimationFrame(()=> lb.classList.remove('opacity-0'));
    }
    function cerrar(){
        lb.classList.add('opacity-0');
        setTimeout(()=>{ lb.classList.add('hidden'); lb.classList.remove('flex'); lbImg.src=''; }, 200);
    }
    // Delegación: las imágenes del chat se cargan dinámicamente por polling
    document.addEventListener('click', function(e){
        const img = e.target.closest('.js-chat-imagen');
        if (img && img.dataset.lightboxSrc){ e.preventDefault(); abrir(img.dataset.lightboxSrc); }
    });
    lbClose.addEventListener('click', cerrar);
    lb.addEventListener('click', function(e){ if (e.target === lb) cerrar(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !lb.classList.contains('hidden')) cerrar(); });
})();
</script>

<?php if ($esVendedor): ?>
<!-- MODAL: Generar Reserva -->
<div id="modal-reserva" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm items-center justify-center p-4">
    <div id="modal-reserva-card" class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden transform translate-y-4 opacity-0 transition-all duration-200">

        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 bg-blue-50 text-[#54A6D8] rounded-full flex items-center justify-center shrink-0">
                    <?= icon('calendar', 'w-4 h-4') ?>
                </div>
                <h3 class="font-bold text-gray-900 text-[15px]">Generar Reserva</h3>
            </div>
            <button type="button" id="btn-cerrar-modal-reserva" class="text-gray-400 hover:text-gray-600 w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-50 transition-colors">
                <?= icon('x-mark', 'w-5 h-5') ?>
            </button>
        </div>

        <div class="px-5 pt-4 pb-3 bg-gray-50 border-b border-gray-100">
            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Servicio</p>
            <p class="text-sm font-bold text-gray-900 truncate"><?= htmlspecialchars($chat['servicio_titulo'], ENT_QUOTES, 'UTF-8') ?></p>
            <div class="flex items-center gap-6 mt-2">
                <div>
                    <p class="text-[10px] text-gray-400 uppercase tracking-wide">Duración</p>
                    <p class="text-sm font-bold text-gray-700"><?= $modal_duracion ?> min</p>
                </div>
                <div>
                    <p class="text-[10px] text-gray-400 uppercase tracking-wide">Precio</p>
                    <?php if ($modal_es_oferta): ?>
                        <?php $modal_pct = round((1 - $modal_precio_oferta / $modal_precio) * 100); ?>
                        <p class="text-sm font-bold">
                            <span class="line-through text-gray-400 mr-1">$<?= number_format($modal_precio, 0, ',', '.') ?></span>
                            <span class="text-orange-500">-<?= $modal_pct ?>%</span>
                            <span class="text-gray-900 ml-1">→ $<?= number_format($modal_precio_oferta, 0, ',', '.') ?></span>
                        </p>
                    <?php else: ?>
                        <p class="text-sm font-bold text-gray-700">$<?= number_format($modal_precio, 0, ',', '.') ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="p-5">
            <div class="grid grid-cols-2 gap-3 mb-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Fecha</label>
                    <input type="date" id="reserva-fecha" min="<?= date('Y-m-d') ?>"
                           class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5 text-sm font-medium text-gray-900 focus:outline-none focus:border-[#54A6D8] focus:bg-white transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Hora</label>
                    <input type="time" id="reserva-hora" min="07:00"
                           class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5 text-sm font-medium text-gray-900 focus:outline-none focus:border-[#54A6D8] focus:bg-white transition">
                </div>
            </div>
            <p class="text-[11px] text-gray-400 mb-4 leading-snug">El estudiante recibirá un enlace de pago válido por 24 horas. El precio no se puede modificar.</p>
            <div class="flex gap-2">
                <button type="button" id="btn-cancelar-modal-reserva" class="flex-1 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50 rounded-xl transition-colors">
                    Cancelar
                </button>
                <button type="button" id="btn-enviar-reserva" class="flex-1 py-2.5 text-sm font-bold text-white bg-[#54A6D8] hover:bg-blue-600 rounded-xl shadow-sm hover:shadow-md transition-all active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed">
                    Generar enlace
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const btnAbrir    = document.getElementById('btn-generar-reserva');
    const modal       = document.getElementById('modal-reserva');
    const card        = document.getElementById('modal-reserva-card');
    const btnCerrar   = document.getElementById('btn-cerrar-modal-reserva');
    const btnCancelar = document.getElementById('btn-cancelar-modal-reserva');
    const btnEnviar   = document.getElementById('btn-enviar-reserva');
    const inputFecha  = document.getElementById('reserva-fecha');
    const inputHora   = document.getElementById('reserva-hora');

    function abrirModal() {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        void card.offsetWidth;
        requestAnimationFrame(() => {
            card.classList.remove('translate-y-4', 'opacity-0');
            card.classList.add('translate-y-0', 'opacity-100');
        });
    }

    function cerrarModal() {
        card.classList.add('translate-y-4', 'opacity-0');
        card.classList.remove('translate-y-0', 'opacity-100');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            inputFecha.value = '';
            inputHora.value  = '';
        }, 200);
    }

    btnAbrir.addEventListener('click', abrirModal);
    btnCerrar.addEventListener('click', cerrarModal);
    btnCancelar.addEventListener('click', cerrarModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) cerrarModal(); });

    btnEnviar.addEventListener('click', async () => {
        const fecha = inputFecha.value;
        const hora  = inputHora.value;

        if (!fecha) { showToast('Elige una fecha para la reserva.'); return; }
        if (!hora)  { showToast('Elige una hora para la reserva.'); return; }

        const hh = parseInt(hora.split(':')[0], 10);
        if (hh < 7) { showToast('Elige una hora válida (desde las 07:00).'); return; }

        btnEnviar.disabled = true;
        btnEnviar.textContent = 'Generando...';

        const fd = new FormData();
        fd.append('conversacion_id', '<?= $chat_id ?>');
        fd.append('fecha', fecha);
        fd.append('hora', hora);
        fd.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>');

        try {
            const res  = await fetch('/app/generar_slot_excepcion.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            if (data.success) {
                cerrarModal();
                const tc      = document.getElementById('toast-container');
                const tcInner = tc.querySelector('div');
                tcInner.classList.replace('bg-red-500',    'bg-emerald-500');
                tcInner.classList.replace('border-red-600','border-emerald-600');
                showToast('Enlace de pago enviado al chat.');
                setTimeout(() => {
                    tcInner.classList.replace('bg-emerald-500',    'bg-red-500');
                    tcInner.classList.replace('border-emerald-600','border-red-600');
                    hideToast();
                }, 4000);
                pollIntervalo = 1000;
            } else {
                showToast(data.error || 'No se pudo generar la reserva.');
            }
        } catch (e) {
            showToast('Error de conexión. Intenta nuevamente.');
        } finally {
            btnEnviar.disabled = false;
            btnEnviar.textContent = 'Generar enlace';
        }
    });
})();
</script>
<?php endif; ?>

<?php if (!$esVendedor): ?>
<!-- MODAL: Cupón de beca -->
<div id="modal-cupon" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm items-center justify-center p-4">
    <div id="modal-cupon-card" class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden transform translate-y-4 opacity-0 transition-all duration-200">

        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 bg-blue-50 text-[#54A6D8] rounded-full flex items-center justify-center shrink-0">
                    <?= icon('ticket', 'w-4 h-4') ?>
                </div>
                <h3 class="font-bold text-gray-900 text-[15px]">¿Tienes un código de beca?</h3>
            </div>
            <button type="button" id="btn-cerrar-modal-cupon" class="text-gray-400 hover:text-gray-600 w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-50 transition-colors">
                <?= icon('x-mark', 'w-5 h-5') ?>
            </button>
        </div>

        <div class="p-5">
            <div class="flex gap-2">
                <div class="relative flex-1 min-w-0">
                    <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-[10px]"><?= icon('ticket') ?></span>
                    <input type="text" id="input-cupon-chat" placeholder="Ingresa tu código"
                           class="w-full bg-gray-50 border border-gray-100 text-gray-900 text-[16px] rounded-xl pl-9 pr-3 py-3 focus:border-[#54A6D8] focus:bg-white focus:ring-2 focus:ring-[#54A6D8]/20 outline-none uppercase font-bold transition-all placeholder:font-normal placeholder:normal-case placeholder:text-gray-400">
                </div>
                <button type="button" id="btn-validar-cupon-chat"
                        class="shrink-0 bg-slate-900 text-white text-[11px] uppercase tracking-widest font-extrabold px-4 rounded-xl transition-all shadow-sm hover:bg-slate-800 active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                    <span id="txt-btn-validar-cupon-chat">Validar</span>
                    <svg id="spinner-cupon-chat" class="animate-spin h-3.5 w-3.5 text-white hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                </button>
            </div>
            <div id="msg-cupon-chat" class="hidden mt-3 p-3 rounded-xl text-xs font-bold flex items-start gap-2 transition-all duration-300"></div>
        </div>
    </div>
</div>

<script>
(function () {
    const btnAbrir        = document.getElementById('btn-abrir-modal-cupon');
    const modal           = document.getElementById('modal-cupon');
    const card            = document.getElementById('modal-cupon-card');
    const btnCerrar       = document.getElementById('btn-cerrar-modal-cupon');
    const inputCupon      = document.getElementById('input-cupon-chat');
    const btnValidar      = document.getElementById('btn-validar-cupon-chat');
    const txtBtnValidar   = document.getElementById('txt-btn-validar-cupon-chat');
    const spinnerCupon    = document.getElementById('spinner-cupon-chat');
    const msgCupon        = document.getElementById('msg-cupon-chat');
    const btnContratar    = document.getElementById('btn-contratar-chat');
    const txtBtnContratar = document.getElementById('txt-btn-contratar-chat');
    const hrefBase        = btnContratar ? btnContratar.dataset.hrefBase : null;

    if (!btnAbrir || !modal || !btnContratar || !hrefBase) return;

    function abrirModal() {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        void card.offsetWidth;
        requestAnimationFrame(() => {
            card.classList.remove('translate-y-4', 'opacity-0');
            card.classList.add('translate-y-0', 'opacity-100');
        });
        inputCupon.focus();
    }

    function cerrarModal() {
        card.classList.add('translate-y-4', 'opacity-0');
        card.classList.remove('translate-y-0', 'opacity-100');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }, 200);
    }

    // [NUBIRA] Reconstruye el href del botón "Contratar" SIEMPRE desde la base
    // (solo servicio_id) + el código actual — nunca concatena sobre el href
    // existente, así validar un segundo código distinto no deja el primero pegado.
    function aplicarCodigoAHref(codigo) {
        btnContratar.href = codigo
            ? hrefBase + '&codigo_beca=' + encodeURIComponent(codigo)
            : hrefBase;
    }

    btnAbrir.addEventListener('click', abrirModal);
    btnCerrar.addEventListener('click', cerrarModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) cerrarModal(); });

    btnValidar.addEventListener('click', async () => {
        const code = inputCupon.value.trim().toUpperCase();
        if (!code) { inputCupon.focus(); return; }

        btnValidar.disabled = true;
        inputCupon.disabled = true;
        txtBtnValidar.textContent = 'Procesando...';
        spinnerCupon.classList.remove('hidden');
        msgCupon.classList.add('hidden', 'opacity-0');

        try {
            const urlFetch = `/app/validar_cupon.php?codigo_beca=${encodeURIComponent(code)}&servicio_id=<?= (int)$chat['servicio_id'] ?>`;
            const res = await fetch(urlFetch);
            if (!res.ok) throw new Error('Error HTTP: ' + res.status);

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
                aplicarCodigoAHref(code);

                btnValidar.classList.remove('bg-slate-900', 'hover:bg-slate-800');
                btnValidar.classList.add('bg-emerald-500', 'hover:bg-emerald-600');
                txtBtnValidar.textContent = 'Beca Activada';

                if (txtBtnContratar) txtBtnContratar.textContent = 'Contratar con beca';
                btnContratar.classList.add('ring-2', 'ring-emerald-300');
            } else {
                msgCupon.innerHTML = `<i class="fa-solid fa-triangle-exclamation mt-0.5"></i> <span>${data.mensaje}</span>`;
                aplicarCodigoAHref(null);

                btnValidar.classList.remove('bg-emerald-500', 'hover:bg-emerald-600');
                btnValidar.classList.add('bg-slate-900', 'hover:bg-slate-800');
                txtBtnValidar.textContent = 'Reintentar';

                if (txtBtnContratar) txtBtnContratar.textContent = 'Contratar';
                btnContratar.classList.remove('ring-2', 'ring-emerald-300');
            }
        } catch (e) {
            console.error("🚨 Error en validación de cupón:", e.message);
            msgCupon.className = 'mt-3 p-3 rounded-xl text-xs font-bold flex items-start gap-2 bg-rose-50 text-rose-700 border border-rose-100 transition-all duration-300 opacity-100';
            msgCupon.innerHTML = '<i class="fa-solid fa-triangle-exclamation mt-0.5"></i> <span>Hubo un problema técnico. Intenta nuevamente.</span>';

            aplicarCodigoAHref(null);
            txtBtnValidar.textContent = 'Validar';
            if (txtBtnContratar) txtBtnContratar.textContent = 'Contratar';
            btnContratar.classList.remove('ring-2', 'ring-emerald-300');
        } finally {
            // Reactivamos siempre (a diferencia del cupón de detalle_servicio.php) para
            // permitir validar un segundo código distinto sin recargar la página.
            btnValidar.disabled = false;
            inputCupon.disabled = false;
            spinnerCupon.classList.add('hidden');
            msgCupon.classList.remove('hidden');
        }
    });

    inputCupon.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            btnValidar.click();
        }
    });
})();
</script>
<?php endif; ?>

</body>
</html>
