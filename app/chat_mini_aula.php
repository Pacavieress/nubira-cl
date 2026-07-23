<?php
/**
 * VISTA: CHAT DE AULA (ESPEJO VISUAL Y FUNCIONAL DE chat_previo_contrato.php)
 * UBICACIÓN: public_html/app/chat_mini_aula.php
 * CONTEXTO: Renderizado dentro de iframe en mini_aula.php
 * 
 * DIFERENCIAS CLAVE vs chat_previo_contrato:
 *   - Usa tabla `contratos` en lugar de `conversaciones`
 *   - Endpoints _mini_aula propios (typing, cargar, enviar)
 *   - Sin adjuntar archivos
 *   - Sin lógica de inactividad 48hrs
 *   - Cierre vía window.parent.toggleChat() (iframe)
 *   - FIX teclado móvil: html+body fixed + pre-anclaje en touchstart
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

// 3. SEGURIDAD
if (!isset($_SESSION['usuario_id'])) {
    die('<div class="p-4 text-red-500 font-bold">Sesión expirada</div>');
}

$my_id       = (int)$_SESSION['usuario_id'];
$es_admin    = (($_SESSION['rol'] ?? '') === 'admin');
$id_contrato = (int)($_GET['id'] ?? 0);

if ($id_contrato <= 0) die("Chat no válido.");

// 4. DATOS DEL CONTRATO + USUARIOS
$sql_base = "
    SELECT
        c.id, c.estado, c.comprador_id, c.vendedor_id, c.servicio_id,
        s.titulo AS servicio_titulo,
        v.nombre AS nombre_vendedor, v.foto_perfil AS foto_vendedor, v.id AS id_vendedor,
        a.nombre AS nombre_comprador, a.foto_perfil AS foto_comprador, a.id AS id_comprador
    FROM contratos c
    JOIN servicios s ON c.servicio_id = s.id
    JOIN alumnos a ON c.comprador_id = a.id
    JOIN alumnos v ON c.vendedor_id = v.id
    WHERE c.id = ?
";

if ($es_admin) {
    $stmt = $conn->prepare($sql_base . " LIMIT 1");
    $stmt->bind_param("i", $id_contrato);
} else {
    $stmt = $conn->prepare($sql_base . " AND (c.comprador_id = ? OR c.vendedor_id = ?) LIMIT 1");
    $stmt->bind_param("iii", $id_contrato, $my_id, $my_id);
}
$stmt->execute();
$chat = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$chat) die('<div class="p-4 text-gray-500">Acceso denegado.</div>');

// Admin entra en modo solo-lectura (no puede enviar mensajes como participante)
$bloqueado  = $es_admin || in_array($chat['estado'], ['cancelado', 'finalizado', 'disputa']);
$esVendedor = ($chat['id_vendedor'] == $my_id);

// 5. PREPARAR DATOS VISUALES
if ($esVendedor) {
    $raw_nombre = $chat['nombre_comprador'];
    $raw_foto   = $chat['foto_comprador'];
} else {
    $raw_nombre = $chat['nombre_vendedor'];
    $raw_foto   = $chat['foto_vendedor'];
}

// A. ANONIMATO: Nombre + Inicial del apellido (ej: Pablo C.)
$partes        = explode(' ', trim($raw_nombre));
$primer_nombre = ucfirst(strtolower($partes[0]));

$nombre_mostrar   = $primer_nombre;
$iniciales_avatar = mb_strtoupper(mb_substr($partes[0], 0, 1, 'UTF-8'));

if (count($partes) > 1) {
    $inicial_apellido  = mb_strtoupper(mb_substr($partes[1], 0, 1, 'UTF-8'));
    $nombre_mostrar    = $primer_nombre . ' ' . $inicial_apellido . '.';
    $iniciales_avatar .= $inicial_apellido;
}

// B. FOTO DE PERFIL
$tiene_foto    = false;
$ruta_foto_url = "";
if (!empty($raw_foto)) {
    $ruta_foto_url = "/app/perfil/fotos/" . $raw_foto;
    $tiene_foto    = true;
}

// 6. PRE-CARGA DE MENSAJES (renderizado server-side inicial)
ob_start();
require __DIR__ . '/cargar_mensajes_chat_mini_aula.php';
$html_mensajes_iniciales = ob_get_clean();

// Liberar la sesión para no bloquear el polling
session_write_close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Aula Chat #<?= (int)$chat['id'] ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        /* ==================================================
         * [NUBIRA 2.0] FIX TECLADO MÓVIL EN IFRAME
         * Técnica calcada de chat_previo_contrato.php: la altura real del body
         * se fuerza vía --vh (JS, actualizado desde postMessage del padre o desde
         * el visualViewport propio), en vez de confiar en que position:fixed
         * resuelva solo el tamaño bajo el teclado (inconsistente entre iOS/Safari).
         * ================================================== */
        html, body {
            overflow: hidden;
            overscroll-behavior: none;
            -webkit-overflow-scrolling: touch;
            height: 100%;
        }
        body {
            position: relative;
            width: 100%;
            font-family: 'Inter', sans-serif;
            background-color: #f0f2f5;
        }

        /* Layout flex column: header + main(scroll) + footer */
        body > header,
        body > main,
        body > footer { flex-shrink: 0; }
        body > main { flex: 1 1 auto; min-height: 0; }

        /* Solo el contenedor de mensajes scrollea */
        #chat-container {
            -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain;
            overflow-y: auto;
        }

        /* Footer siempre anclado abajo */
        footer {
            flex-shrink: 0;
            position: relative;
            z-index: 40;
        }

        /* Evitar zoom automático en iOS al enfocar inputs */
        textarea, input { font-size: 16px !important; }

        /* Utilidades */
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .pb-safe { padding-bottom: env(safe-area-inset-bottom); }

        /* Burbujas */
        .bubble-me { background: linear-gradient(135deg, #54A6D8 0%, #4092c4 100%); color: white; border-radius: 18px 18px 4px 18px; }
        .bubble-other { background-color: white; color: #1f2937; border-radius: 18px 18px 18px 4px; border: 1px solid #f3f4f6; }

        /* Toast */
        .fade-in-down { animation: fadeInDown 0.3s ease-out forwards; }
        .fade-out-up  { animation: fadeOutUp 0.3s ease-in forwards; }
        @keyframes fadeInDown { from { opacity: 0; transform: translate(-50%, -20px); } to { opacity: 1; transform: translate(-50%, 0); } }
        @keyframes fadeOutUp  { from { opacity: 1; transform: translate(-50%, 0); }    to { opacity: 0; transform: translate(-50%, -20px); } }

        /* Mensaje optimista */
        .bubble-pending { opacity: 0.75; }
        .bubble-failed  { background: #fee2e2 !important; color: #991b1b !important; border: 1px solid #fecaca; }
        .bubble-failed .retry-btn { display: inline-flex; }
        .retry-btn { display: none; margin-left: 6px; cursor: pointer; }
        .fade-in { animation: fadeIn 0.25s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

        /* Imágenes con shimmer mientras descargan */
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
        [class*="bubble"] img.loaded { min-height: auto; background: none; animation: none; }
        @keyframes shimmer-img { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

        /* Typing indicator */
        #typing-indicator { display: none; }
        #typing-indicator.is-visible { display: flex; }
        .typing-dot {
            width: 7px; height: 7px; background-color: #9ca3af; border-radius: 50%;
            display: inline-block; animation: typing-bounce 1.3s infinite ease-in-out;
        }
        .typing-dot:nth-child(1) { animation-delay: 0s; }
        .typing-dot:nth-child(2) { animation-delay: 0.18s; }
        .typing-dot:nth-child(3) { animation-delay: 0.36s; }
        @keyframes typing-bounce {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
            30% { transform: translateY(-4px); opacity: 1; }
        }
    </style>
</head>

<body class="w-full flex flex-col text-gray-900 bg-[#EFEAE2] bg-opacity-30" style="height: calc(var(--vh, 1vh) * 100);">

    <div id="toast-container" class="fixed top-20 left-1/2 z-50 hidden w-[90%] max-w-sm transform -translate-x-1/2 transition-all duration-300">
        <div class="bg-red-500 text-white px-4 py-3 rounded-2xl shadow-xl flex items-start justify-between gap-3 border border-red-600">
            <div class="flex items-start gap-3">
                <i class="fa-solid fa-triangle-exclamation text-lg shrink-0 mt-0.5"></i>
                <p id="toast-msg" class="text-[13px] font-medium leading-snug"></p>
            </div>
            <button type="button" onclick="hideToast()" class="text-white hover:text-red-200 shrink-0 p-1 transition-colors">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>
    </div>

    <header class="h-16 bg-white/95 backdrop-blur-md shrink-0 flex items-center justify-between px-3 border-b border-gray-100 shadow-sm z-30 relative">
        <div class="flex items-center gap-2 overflow-hidden flex-1">
            <div class="relative shrink-0 ml-1">
                <?php if ($tiene_foto): ?>
                    <img src="<?= htmlspecialchars($ruta_foto_url) ?>" class="w-10 h-10 rounded-full object-cover border border-gray-200 shadow-sm bg-gray-100">
                <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center font-bold text-[#54A6D8] border border-blue-100 shadow-sm text-lg select-none tracking-wide">
                        <?= htmlspecialchars($iniciales_avatar) ?>
                    </div>
                <?php endif; ?>
                <span class="absolute bottom-0 right-0 w-2.5 h-2.5 <?= $bloqueado ? 'bg-gray-400' : 'bg-green-500' ?> border-2 border-white rounded-full"></span>
            </div>

            <div class="leading-tight overflow-hidden pl-1 flex-1">
                <h1 class="font-bold text-gray-900 text-[15px] truncate w-full">
                    <?= htmlspecialchars($nombre_mostrar) ?>
                </h1>
                <p class="text-[11px] text-gray-500 truncate w-full opacity-90">
                    <i class="fa-solid fa-tag text-[9px] mr-0.5"></i> <?= htmlspecialchars($chat['servicio_titulo']) ?>
                </p>
            </div>
        </div>

        <button type="button" id="btn-colgar-chat" onclick="colgarDesdeChatPanel()"
                class="hidden text-red-600 bg-red-50 hover:bg-red-600 hover:text-white w-10 h-10 flex items-center justify-center rounded-full transition-colors shrink-0 active:scale-95 z-50 ml-1"
                title="Terminar clase">
            <i class="fa-solid fa-phone-slash text-[16px]"></i>
        </button>

        <button type="button" onclick="cerrarChatPanel()" class="text-gray-400 hover:text-[#54A6D8] w-10 h-10 flex items-center justify-center rounded-full hover:bg-gray-50 transition-colors shrink-0 active:scale-95 z-50 ml-2">
            <i class="fa-solid fa-arrow-right text-[18px]"></i>
        </button>
    </header>

    <main id="chat-container" class="flex-1 overflow-y-auto p-4 pb-8 space-y-3 w-full relative no-scrollbar">

        <?php if ($bloqueado): ?>
            <div class="flex justify-center mb-6 mt-2">
                <div class="bg-gray-100 text-gray-600 text-[11px] px-4 py-2.5 rounded-xl max-w-[85%] text-center border border-gray-200 shadow-sm font-bold leading-snug">
                    <i class="fa-solid fa-lock mr-1"></i> Este chat de aula está cerrado.
                </div>
            </div>
        <?php else: ?>
            <div class="flex justify-center mb-6 mt-2">
                <div class="bg-sky-50 text-sky-800 text-[11px] px-4 py-2.5 rounded-xl max-w-[85%] text-center border border-sky-100 shadow-sm leading-snug">
                    <i class="fa-solid fa-graduation-cap mr-1"></i> <b>Aula Virtual Activa.</b> Coordina la reunión, comparte recursos y resuelve dudas.
                </div>
            </div>
        <?php endif; ?>

        <!-- Mensajes pre-renderizados desde PHP — apertura instantánea -->
        <div id="mensajes-wrapper"><?= $html_mensajes_iniciales ?></div>

        <!-- Typing indicator -->
        <div id="typing-indicator" class="hidden justify-start mb-2 fade-in">
            <div class="bubble-other relative max-w-[85%] md:max-w-[70%] px-4 py-3 shadow-sm flex items-center gap-1.5">
                <span class="typing-dot"></span>
                <span class="typing-dot"></span>
                <span class="typing-dot"></span>
            </div>
        </div>

        <div id="scroll-anchor" class="h-1 w-full"></div>
    </main>

    <footer class="bg-white px-3 py-2 border-t border-gray-100 shrink-0 w-full z-20 pb-safe shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)]">
        <form id="form-chat" class="flex items-end gap-2 max-w-4xl mx-auto w-full relative <?= $bloqueado ? 'opacity-50 pointer-events-none grayscale-[50%]' : '' ?>">
            <input type="hidden" name="id_contrato" value="<?= (int)$id_contrato ?>">

            <div class="relative flex-1 bg-gray-100 rounded-[24px] flex items-center px-4 py-1 border border-transparent focus-within:border-blue-200 focus-within:bg-white focus-within:shadow-sm transition-all duration-200">
                <textarea
                    name="mensaje"
                    id="input-msg"
                    rows="1"
                    <?= $bloqueado ? 'disabled placeholder="Chat cerrado..."' : 'placeholder="Escribe en el aula..."' ?>
                    class="w-full bg-transparent text-gray-900 text-sm focus:outline-none resize-none max-h-32 py-1 leading-relaxed <?= $bloqueado ? 'placeholder-gray-500 font-medium' : 'placeholder-gray-400' ?>"
                ></textarea>
            </div>

            <button type="submit" id="btn-enviar" disabled class="bg-[#54A6D8] text-white w-11 h-11 rounded-full flex items-center justify-center hover:bg-blue-600 hover:shadow-md transition-all shadow-sm shrink-0 disabled:opacity-50 disabled:cursor-not-allowed transform active:scale-90 disabled:active:scale-100">
                <i class="fa-solid fa-paper-plane text-sm ml-0.5"></i>
            </button>
        </form>
    </footer>

    <script>
        const chatContainer = document.getElementById('chat-container');
        const form          = document.getElementById('form-chat');
        const input         = document.getElementById('input-msg');
        const btnSend       = document.getElementById('btn-enviar');

        // Toast
        const toastContainer = document.getElementById('toast-container');
        const toastMsg       = document.getElementById('toast-msg');

        const idContrato = <?= (int)$id_contrato ?>;
        let esPrimeraCarga = true;

        // ==========================================
        // [NUBIRA 2.0] BLOQUEO DE SCROLL DEL VIEWPORT
        // El navegador puede intentar scrollear el viewport al enfocar inputs.
        // Aunque html+body están en position:fixed, iOS a veces insiste.
        // Listener pasivo que reanca el viewport a (0,0) si algo se cuela.
        // ==========================================
        function bloquearScrollViewport() {
            window.scrollTo(0, 0);
            document.documentElement.scrollTop = 0;
            document.body.scrollTop = 0;
        }
        window.addEventListener('scroll', bloquearScrollViewport, { passive: true });
        document.addEventListener('scroll', bloquearScrollViewport, { passive: true });

        // ==========================================
        // CERRAR PANEL EN MINI AULA (iframe)
        // ==========================================
        function cerrarChatPanel() {
            if (window.parent && typeof window.parent.toggleChat === 'function') {
                window.parent.toggleChat();
            } else {
                window.history.back();
            }
        }

        // ==========================================
        // BOTÓN DE COLGAR DENTRO DEL PANEL DE CHAT
        // El chat vive por encima de todo (z-index 99999 en móvil) y tapa el
        // #btn-colgar del padre mientras está abierto — este botón evita tener
        // que cerrar el chat primero para terminar la clase.
        // ==========================================
        function actualizarBotonColgarChat() {
            const btn = document.getElementById('btn-colgar-chat');
            if (!btn) return;
            let activa = false;
            try { activa = !!(window.parent && window.parent.enLlamada); } catch (e) { activa = false; }
            btn.classList.toggle('hidden', !activa);
        }
        actualizarBotonColgarChat();

        function colgarDesdeChatPanel() {
            try {
                if (window.parent && typeof window.parent.colgarLlamada === 'function') {
                    window.parent.colgarLlamada();
                }
            } catch (e) {}
            cerrarChatPanel();
        }

        // ==========================================
        // TYPING INDICATOR
        // ==========================================
        const typingIndicator = document.getElementById('typing-indicator');
        let typingLastPing = 0;
        let typingEnviando = false;

        function pingTyping() {
            const ahora = Date.now();
            if (ahora - typingLastPing < 2000 || typingEnviando) return;
            typingLastPing = ahora;
            typingEnviando = true;

            const fd = new FormData();
            fd.append('id_contrato', idContrato);

            fetch('/app/typing_set_mini_aula.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .catch(() => {})
                .finally(() => { typingEnviando = false; });
        }

        function mostrarTyping(visible) {
            if (!typingIndicator) return;
            const estaVisible = typingIndicator.classList.contains('is-visible');
            if (visible && !estaVisible) {
                typingIndicator.classList.add('is-visible');
                if (isUserAtBottom()) scrollToBottom(true);
            } else if (!visible && estaVisible) {
                typingIndicator.classList.remove('is-visible');
            }
        }

        // ==========================================
        // TOAST
        // ==========================================
        function showToast(message) {
            toastMsg.innerText = message;
            toastContainer.classList.remove('hidden', 'fade-out-up');
            toastContainer.classList.add('fade-in-down');
        }
        function hideToast() {
            toastContainer.classList.remove('fade-in-down');
            toastContainer.classList.add('fade-out-up');
            setTimeout(() => toastContainer.classList.add('hidden'), 300);
        }

        // ==========================================
        // SCROLL HELPERS
        // ==========================================
        function scrollToBottom(smooth = false) {
            const anchor = document.getElementById('scroll-anchor');
            if (anchor) anchor.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'end' });
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }

        function isUserAtBottom() {
            return chatContainer.scrollHeight - chatContainer.scrollTop - chatContainer.clientHeight < 300;
        }

        // ==========================================
        // [NUBIRA 2.0] CONTROL DE ALTURA REAL DEL VIEWPORT (--vh)
        // Prioridad: postMessage del padre (mide desde el visualViewport de nivel
        // superior, más confiable) > visualViewport propio del iframe (respaldo si
        // el mensaje nunca llegó) > innerHeight (último recurso).
        // ==========================================
        let alturaDesdeParent = null;

        function actualizarAlturaVH() {
            const vh = (alturaDesdeParent !== null)
                ? alturaDesdeParent * 0.01
                : (window.visualViewport ? window.visualViewport.height * 0.01 : window.innerHeight * 0.01);
            document.documentElement.style.setProperty('--vh', `${vh}px`);
        }
        actualizarAlturaVH();

        window.addEventListener('message', (event) => {
            if (event.source !== window.parent) return;
            if (!event.data || event.data.type !== 'nubira:keyboard-resize') return;
            if (typeof event.data.height !== 'number') return;
            alturaDesdeParent = event.data.height;
            actualizarAlturaVH();
            if (document.activeElement === input) scrollToBottom(false);
            // Este mensaje ya llega justo al abrir el panel (ver mini_aula.php toggleChat) —
            // aprovechamos el mismo evento para revalidar si hay una llamada activa.
            actualizarBotonColgarChat();
        });

        if ('visualViewport' in window) {
            window.visualViewport.addEventListener('resize', () => {
                actualizarAlturaVH();
                if (document.activeElement === input) scrollToBottom(false);
            });
            window.visualViewport.addEventListener('scroll', () => {
                if (document.activeElement === input) scrollToBottom(false);
            });
        }

        window.addEventListener('orientationchange', () => setTimeout(actualizarAlturaVH, 200));

        // ==========================================
        // INPUT (auto-resize + typing)
        // ==========================================
        input.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
            if (btnSend) btnSend.disabled = this.value.trim() === '';
            if (this.value.trim().length > 0) pingTyping();
            pollIntervalo = POLL_MIN;
        });

        // ==========================================
        // [NUBIRA 2.0] PRE-ANCLAJE EN TOUCHSTART
        // El touchstart ocurre ANTES del focus, antes de que iOS dispare
        // su scrollIntoView automático. Ahí pre-anclamos el viewport.
        // Esto elimina el "salto" del primer toque sobre el textarea.
        // ==========================================
        input.addEventListener('touchstart', () => {
            // Pre-anclar el viewport y el chat al fondo ANTES de que iOS reaccione
            bloquearScrollViewport();
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }, { passive: true });

        // ==========================================
        // FOCUS: anclar viewport durante toda la animación del teclado
        // ==========================================
        input.addEventListener('focus', () => {
            if (!toastContainer.classList.contains('hidden')) hideToast();

            const anclarTodo = () => {
                actualizarAlturaVH();
                bloquearScrollViewport();
                chatContainer.scrollTop = chatContainer.scrollHeight;
            };

            // Anclaje inmediato + 5 puntos durante los 500ms de animación del teclado
            anclarTodo();
            requestAnimationFrame(anclarTodo);
            setTimeout(anclarTodo, 50);
            setTimeout(anclarTodo, 150);
            setTimeout(anclarTodo, 300);
            setTimeout(anclarTodo, 500);
        });

        // ==========================================
        // BLUR: reanclar al cerrar el teclado
        // ==========================================
        input.addEventListener('blur', () => {
            setTimeout(bloquearScrollViewport, 50);
            setTimeout(bloquearScrollViewport, 200);
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (btnSend && !btnSend.disabled) form.requestSubmit();
            }
        });

        // ==========================================
        // MENSAJES OPTIMISTAS
        // ==========================================
        function escapeHTML(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function pintarOptimista(tempId, texto) {
            const wrapper = document.getElementById('mensajes-wrapper');
            if (!wrapper) return;

            const ahora = new Date();
            const hora = ahora.getHours().toString().padStart(2, '0') + ':' + ahora.getMinutes().toString().padStart(2, '0');

            const html = `
                <div class="flex w-full justify-end fade-in mb-2 group" data-temp-id="${tempId}">
                    <div class="bubble-me bubble-pending relative max-w-[85%] md:max-w-[70%] px-4 py-2 shadow-sm text-[14px] leading-snug break-words">
                        ${escapeHTML(texto).replace(/\n/g, '<br>')}
                        <div class="text-[10px] flex items-center justify-end gap-1 mt-1 select-none opacity-80">
                            <span class="text-blue-50">${hora}</span>
                            <span class="text-blue-200/60"><i class="fa-regular fa-clock"></i></span>
                            <span class="retry-btn" onclick="reintentarMensaje('${tempId}')">
                                <i class="fa-solid fa-rotate-right text-red-600"></i>
                            </span>
                        </div>
                    </div>
                </div>
            `;
            wrapper.insertAdjacentHTML('beforeend', html);
            scrollToBottom(true);
        }

        function marcarFallido(tempId) {
            const elem = document.querySelector(`[data-temp-id="${tempId}"] .bubble-me`);
            if (elem) {
                elem.classList.remove('bubble-pending');
                elem.classList.add('bubble-failed');
            }
        }

        const mensajesFallidos = {};
        async function reintentarMensaje(tempId) {
            const data = mensajesFallidos[tempId];
            if (!data) return;
            delete mensajesFallidos[tempId];
            document.querySelector(`[data-temp-id="${tempId}"]`)?.remove();
            await enviarMensaje(data.texto);
        }
        window.reintentarMensaje = reintentarMensaje;

        async function enviarMensaje(texto) {
            const tempId = 'tmp-' + Date.now() + '-' + Math.random().toString(36).substr(2, 5);
            pintarOptimista(tempId, texto);

            const formData = new FormData();
            formData.append('id_contrato', idContrato);
            formData.append('mensaje', texto);

            try {
                const res  = await fetch('/app/enviar_mensajes_chat_mini_aula.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.success) {
                    pollIntervalo = POLL_MIN;
                    const huboCambio = await actualizarChat();
                    if (!huboCambio) {
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

        // ==========================================
        // ENVÍO DEL FORM
        // ==========================================
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const txt = input.value.trim();
            if (!txt) return;

            input.value = '';
            input.style.height = '';
            if (btnSend) btnSend.disabled = true;

            // Mantener teclado abierto + viewport anclado tras el envío
            input.focus({ preventScroll: true });

            const anclarPostEnvio = () => {
                bloquearScrollViewport();
                chatContainer.scrollTop = chatContainer.scrollHeight;
            };
            anclarPostEnvio();
            requestAnimationFrame(anclarPostEnvio);
            setTimeout(anclarPostEnvio, 100);
            setTimeout(anclarPostEnvio, 300);

            await enviarMensaje(txt);
        });

        // ==========================================
        // POLLING DE MENSAJES
        // ==========================================
        async function actualizarChat() {
            try {
                const res = await fetch(`/app/cargar_mensajes_chat_mini_aula.php?id=${idContrato}&t=${Date.now()}`);
                if (!res.ok) return false;

                const html = await res.text();
                mostrarTyping(res.headers.get('X-Typing-Otro') === '1');

                const currentHtml = chatContainer.getAttribute('data-last-html');
                if (html.trim() === currentHtml) return false;

                const estabaAbajo = isUserAtBottom();

                const wrapper = document.getElementById('mensajes-wrapper');
                if (wrapper) wrapper.innerHTML = html;
                chatContainer.setAttribute('data-last-html', html.trim());

                // Hidratar imágenes (shimmer → loaded)
                if (wrapper) {
                    wrapper.querySelectorAll('img').forEach(img => {
                        if (img.complete && img.naturalHeight > 0) {
                            img.classList.add('loaded');
                        } else {
                            img.addEventListener('load', () => {
                                img.classList.add('loaded');
                                if (isUserAtBottom()) chatContainer.scrollTop = chatContainer.scrollHeight;
                            }, { once: true });
                            img.addEventListener('error', () => img.classList.add('loaded'), { once: true });
                        }
                    });
                }

                if (esPrimeraCarga || estabaAbajo) {
                    chatContainer.scrollTop = chatContainer.scrollHeight;
                    requestAnimationFrame(() => {
                        chatContainer.scrollTop = chatContainer.scrollHeight;
                        requestAnimationFrame(() => {
                            chatContainer.scrollTop = chatContainer.scrollHeight;
                        });
                    });
                    if (esPrimeraCarga) esPrimeraCarga = false;
                }

                return true;
            } catch (e) {
                console.error('Error en actualizarChat:', e);
                return false;
            }
        }

        // Polling adaptativo
        let pollTimer = null;
        let pollIntervalo = 3000;
        const POLL_MIN = 3000;
        const POLL_MAX = 20000;

        function agendarPoll() {
            clearTimeout(pollTimer);
            if (document.hidden) return;
            pollTimer = setTimeout(async () => {
                const huboCambio = await actualizarChat();
                pollIntervalo = huboCambio ? POLL_MIN : Math.min(pollIntervalo + 2000, POLL_MAX);
                agendarPoll();
            }, pollIntervalo);
        }

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                clearTimeout(pollTimer);
            } else {
                pollIntervalo = POLL_MIN;
                actualizarChat().then(agendarPoll);
            }
        });

        // ==========================================
        // ARRANQUE INICIAL
        // ==========================================
        (function arranqueInicialNubira() {
            const wrapper = document.getElementById('mensajes-wrapper');

            if (wrapper) chatContainer.setAttribute('data-last-html', wrapper.innerHTML.trim());

            // Triple RAF para garantizar el scroll al fondo en la primera carga
            chatContainer.scrollTop = chatContainer.scrollHeight;
            requestAnimationFrame(() => {
                chatContainer.scrollTop = chatContainer.scrollHeight;
                requestAnimationFrame(() => {
                    chatContainer.scrollTop = chatContainer.scrollHeight;
                });
            });

            // Hidratar imágenes ya cargadas
            if (wrapper) {
                wrapper.querySelectorAll('img').forEach(img => {
                    if (img.complete && img.naturalHeight > 0) {
                        img.classList.add('loaded');
                    } else {
                        img.addEventListener('load', () => {
                            img.classList.add('loaded');
                            if (isUserAtBottom()) chatContainer.scrollTop = chatContainer.scrollHeight;
                        }, { once: true });
                        img.addEventListener('error', () => img.classList.add('loaded'), { once: true });
                    }
                });
            }

            esPrimeraCarga = false;
            agendarPoll();
        })();

        // Auto-focus en escritorio
        if (window.innerWidth > 768) input.focus();
    </script>
</body>
</html>
