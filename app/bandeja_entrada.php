<?php
/**
 * VISTA: BANDEJA DE ENTRADA (ESTÁNDAR NUBIRA 2.0)
 * UBICACIÓN: public_html/app/bandeja_entrada.php
 * CORRECCIÓN: Avatares circulares humanos, rutas correctas (/app/perfil/fotos/) y cache-busting.
 */

ini_set('display_errors', 0); 
session_start();

require_once __DIR__ . '/conexion.php'; 
require_once __DIR__ . '/iconos.php'; 

if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit; }
$my_id = (int)$_SESSION['usuario_id'];

// =============================================================================
// ESTRATEGIA: CONSULTAS SEPARADAS + LEFT JOIN + VISIBILIDAD INDIVIDUAL
// =============================================================================

$todos_los_chats = [];

// --- 1. NEGOCIACIONES (CHATS PREVIOS) ---
$sql1 = "
    SELECT 
        c.id, 
        'negociacion' as tipo,
        COALESCE(c.ultima_interaccion, c.creado_en) as fecha_sort,
        COALESCE(CONVERT(s.titulo USING utf8mb4), 'Servicio no disponible') as servicio_titulo,
        -- Extraemos la foto de perfil del OTRO usuario
        CONVERT(CASE WHEN c.comprador_id = $my_id THEN v.foto_perfil ELSE a.foto_perfil END USING utf8mb4) as otro_foto,
        CONVERT(CASE WHEN c.comprador_id = $my_id THEN v.nombre ELSE a.nombre END USING utf8mb4) as otro_nombre,
        CASE WHEN c.comprador_id = $my_id THEN v.id ELSE a.id END as otro_id,
        CONVERT((SELECT mensaje FROM mensajes WHERE conversacion_id = c.id ORDER BY enviado_en DESC LIMIT 1) USING utf8mb4) as ultimo_mensaje,
        (SELECT COUNT(*) FROM mensajes WHERE conversacion_id = c.id AND remitente_id != $my_id AND leido = 0) as sin_leer
    FROM conversaciones c
    LEFT JOIN servicios s ON c.servicio_id = s.id
    LEFT JOIN alumnos a ON c.comprador_id = a.id
    LEFT JOIN alumnos v ON c.vendedor_id = v.id
    WHERE (
        (c.comprador_id = $my_id AND c.oculto_comprador = 0) 
        OR 
        (c.vendedor_id = $my_id AND c.oculto_vendedor = 0)
    )
";

$res1 = $conn->query($sql1);
if ($res1) {
    while($row = $res1->fetch_assoc()) {
        $todos_los_chats[] = $row;
    }
}

// --- 2. AULAS (CONTRATOS) ---
$sql2 = "
    SELECT 
        k.id, 
        'aula' as tipo,
        k.fecha_creacion as fecha_sort, 
        COALESCE(CONVERT(s.titulo USING utf8mb4), 'Clase agendada') as servicio_titulo,
        -- Extraemos la foto de perfil del OTRO usuario
        CONVERT(CASE WHEN k.comprador_id = $my_id THEN v.foto_perfil ELSE a.foto_perfil END USING utf8mb4) as otro_foto,
        CONVERT(CASE WHEN k.comprador_id = $my_id THEN v.nombre ELSE a.nombre END USING utf8mb4) as otro_nombre,
        CASE WHEN k.comprador_id = $my_id THEN v.id ELSE a.id END as otro_id,
        CONVERT((SELECT mensaje FROM chat_aula WHERE contrato_id = k.id ORDER BY fecha DESC LIMIT 1) USING utf8mb4) as ultimo_mensaje,
        (SELECT COUNT(*) FROM chat_aula WHERE contrato_id = k.id AND remitente_id != $my_id AND visto = 0) as sin_leer
    FROM contratos k
    LEFT JOIN servicios s ON k.servicio_id = s.id
    LEFT JOIN alumnos a ON k.comprador_id = a.id
    LEFT JOIN alumnos v ON k.vendedor_id = v.id
    WHERE (
        (k.comprador_id = $my_id AND k.oculto_comprador = 0) 
        OR 
        (k.vendedor_id = $my_id AND k.oculto_vendedor = 0)
    )
    AND k.estado IN ('en_progreso', 'liberado')
";

$res2 = $conn->query($sql2);
if ($res2) {
    while($row = $res2->fetch_assoc()) {
        $todos_los_chats[] = $row;
    }
}

// --- ORDENAR POR FECHA (Lo más nuevo primero) ---
usort($todos_los_chats, function($a, $b) {
    $tA = !empty($a['fecha_sort']) ? strtotime($a['fecha_sort']) : 0;
    $tB = !empty($b['fecha_sort']) ? strtotime($b['fecha_sort']) : 0;
    return $tB - $tA;
});

$chats = $todos_los_chats;

// --- HELPERS ---
function tiempo_transcurrido($fecha) {
    if(empty($fecha)) return '';
    $timestamp = strtotime($fecha);
    if(!$timestamp) return '';
    $diferencia = time() - $timestamp;
    if ($diferencia < 60) return 'Ahora';
    if ($diferencia < 3600) return floor($diferencia / 60) . ' min';
    if ($diferencia < 86400) return floor($diferencia / 3600) . ' h';
    if ($diferencia < 604800) return floor($diferencia / 86400) . ' d';
    return date('d/m', $timestamp);
}

function formatear_nombre_corto($nombre_completo) {
    $nombre_limpio = trim($nombre_completo ?? '');
    if (empty($nombre_limpio)) return 'Usuario';
    
    $partes = explode(' ', $nombre_limpio);
    $primer_nombre = ucfirst(strtolower($partes[0]));
    
    if (count($partes) > 1) {
        $inicial_apellido = mb_strtoupper(mb_substr($partes[1], 0, 1, 'UTF-8'));
        return $primer_nombre . ' ' . $inicial_apellido . '.';
    }
    
    return $primer_nombre;
}

function obtener_iniciales($nombre_completo) {
    $nombre_limpio = trim($nombre_completo ?? '');
    if (empty($nombre_limpio)) return '?';
    
    $partes = explode(' ', $nombre_limpio);
    $iniciales = mb_substr($partes[0], 0, 1, 'UTF-8');
    
    if (count($partes) > 1) {
        $iniciales .= mb_substr($partes[1], 0, 1, 'UTF-8');
    }
    
    return mb_strtoupper($iniciales, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>Mensajes | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no" />
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #ffffff; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .pb-safe { padding-bottom: env(safe-area-inset-bottom); }

        /* MODO EDICIÓN */
        .chat-content { transition: transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1); }
        .check-container { 
            position: absolute; left: 0; top: 0; bottom: 0; width: 40px; 
            display: flex; align-items: center; justify-content: center;
            opacity: 0; transform: translateX(-20px); transition: all 0.3s cubic-bezier(0.2, 0.8, 0.2, 1);
            pointer-events: none;
        }
        .editing .chat-content { transform: translateX(30px); pointer-events: none; }
        .editing .check-container { opacity: 1; transform: translateX(10px); pointer-events: auto; }
        .custom-check { width: 22px; height: 22px; border: 2px solid #cbd5e1; border-radius: 50%; display: grid; place-items: center; transition: all 0.2s; }
        .row-checkbox:checked + .custom-check { background-color: #54A6D8; border-color: #54A6D8; }
        .row-checkbox:checked + .custom-check i { transform: scale(1); }
        
        /* NUBIRA 2.0: ANIMACIONES NATIVAS */
        @keyframes popIn { 
            0% { transform: scale(0); } 
            80% { transform: scale(1.1); } 
            100% { transform: scale(1); } 
        }
    </style>
</head>

<body class="bg-white text-gray-900 antialiased overflow-x-hidden selection:bg-blue-100">

    <div id="loader" class="fixed inset-0 bg-white/95 flex items-center justify-center z-[60] transition-opacity duration-300">
        <div class="animate-spin h-10 w-10 border-4 border-blue-200 border-t-[#54A6D8] rounded-full"></div>
    </div>

    <?php 
    require_once __DIR__ . '/componentes/header.php'; 
    require_once __DIR__ . '/componentes/sidebar.php'; 
    ?>

    <main class="pt-20 pb-28 md:pb-10 lg:ml-64 px-4 max-w-4xl mx-auto min-h-screen">
        
        <div class="sticky top-16 md:top-0 z-30 bg-white/95 backdrop-blur py-2 mb-4">
            
            <div id="header-normal" class="flex items-center justify-between header-section">
                <div>
                    <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Bandeja de Entrada</h1>
                </div>
                <?php if (!empty($chats)): ?>
                <button onclick="toggleEditMode(true)" class="text-[15px] font-semibold text-[#54A6D8] bg-blue-50 hover:bg-blue-100 px-4 py-1.5 rounded-lg transition-colors">
                    Editar
                </button>
                <?php endif; ?>
            </div>

            <div id="header-edit" class="hidden flex items-center justify-between header-section bg-gray-50 rounded-xl p-1.5 border border-gray-100">
                <button onclick="toggleEditMode(false)" class="text-[15px] font-medium text-gray-500 hover:text-gray-800 px-3 py-1">Cancelar</button>
                <span class="text-sm font-bold text-gray-800"><span id="selected-count">0</span> seleccionados</span>
                <button onclick="borrarSeleccionados()" id="btn-trash" class="text-[14px] font-bold text-red-500 hover:text-red-600 bg-white border border-gray-200 px-4 py-1.5 rounded-lg shadow-sm disabled:opacity-50 disabled:cursor-not-allowed transition-all" disabled>Eliminar</button>
            </div>
            
             <div id="select-all-box" class="hidden mt-2 border-b border-gray-100 pb-2 animate-pulse">
                <label class="flex items-center gap-3 cursor-pointer pl-2">
                    <input type="checkbox" id="check-all" class="peer sr-only" onchange="toggleSelectAll(this)">
                    <span class="text-sm font-medium text-[#54A6D8]">Seleccionar todos</span>
                </label>
            </div>
        </div>

        <div id="lista-chats" class="space-y-1">
            
            <?php if (empty($chats)): ?>
                <div class="flex flex-col items-center justify-center py-20 text-center">
                    <div class="w-20 h-20 bg-gray-50 rounded-full flex items-center justify-center text-gray-300 mb-4">
                        <i class="fa-regular fa-comments text-3xl"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900">Sin mensajes</h3>
                    <p class="text-sm text-gray-500 max-w-xs mx-auto mt-2">No hay conversaciones activas.</p>
                </div>
            <?php else: ?>

                <?php foreach($chats as $chat): 
                    $uniqueId = $chat['tipo'] . '_' . $chat['id'];
                    $esAula = ($chat['tipo'] === 'aula');
                    $link = $esAula ? "/app/mini_aula.php?id=" . $chat['id'] : "/app/chat_previo_contrato.php?id=" . $chat['id'];
                    $sinLeer = (int)$chat['sin_leer'];
                    
                    // Nombres y avatar
                    $nombre_corto = formatear_nombre_corto($chat['otro_nombre']);
                    $iniciales_avatar = obtener_iniciales($chat['otro_nombre']);
                    $tiempo = tiempo_transcurrido($chat['fecha_sort']);

                    // ==========================================
                    // LÓGICA DE FOTO DE PERFIL (ESTÁNDAR HEADER)
                    // ==========================================
                    $fotoPerfil = $chat['otro_foto'] ?? '';
                    $rutaImg = "";
                    if (!empty($fotoPerfil)) {
                        $foto_path_fisico = $_SERVER['DOCUMENT_ROOT'] . "/app/perfil/fotos/" . $fotoPerfil;
                        // Evitar caché de imágenes usando la fecha de modificación
                        $foto_version = file_exists($foto_path_fisico) ? filemtime($foto_path_fisico) : time();
                        $rutaImg = "/app/perfil/fotos/" . $fotoPerfil . "?v=" . $foto_version;
                    }
                ?>
                
             <div id="row-<?= $uniqueId ?>" class="group relative hover:bg-gray-50 rounded-xl overflow-hidden transition-all duration-200 border-b border-gray-100 last:border-0 <?= $sinLeer > 0 ? 'bg-blue-50/40' : 'bg-white' ?>">
                    
                    <div class="check-container z-20">
                        <label class="cursor-pointer p-2">
                            <input type="checkbox" value="<?= $uniqueId ?>" class="row-checkbox sr-only" onchange="updateCounter()">
                            <div class="custom-check">
                                <i class="fa-solid fa-check text-white text-[10px] transform scale-0 transition-transform"></i>
                            </div>
                        </label>
                    </div>

                    <a href="<?= $link ?>" class="chat-content flex items-center p-3 gap-3 relative z-10 block w-full">
                        
                        <div class="relative shrink-0 w-12 h-12">
                            <?php if($rutaImg): ?>
                                <img src="<?= $rutaImg ?>" class="w-12 h-12 rounded-full object-cover bg-gray-100 border border-gray-100 shadow-sm" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="hidden w-12 h-12 rounded-full bg-blue-50 flex items-center justify-center text-[#54A6D8] font-bold text-lg tracking-wide border border-blue-100">
                                    <?= htmlspecialchars($iniciales_avatar) ?>
                                </div>
                            <?php else: ?>
                                <div class="w-12 h-12 rounded-full bg-blue-50 flex items-center justify-center text-[#54A6D8] font-bold text-lg tracking-wide border border-blue-100">
                                    <?= htmlspecialchars($iniciales_avatar) ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="absolute -bottom-0.5 -right-0.5 w-5 h-5 rounded-full border-[1.5px] border-white flex items-center justify-center bg-white shadow-sm">
                                <?php if ($esAula): ?>
                                    <?= icon('academic-cap', 'w-3 h-3 text-[#54A6D8]') ?>
                                <?php else: ?>
                                    <?= icon('chat-bubble', 'w-3 h-3 text-[#54A6D8]') ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-baseline mb-0.5">
                                <h3 class="text-[15px] truncate pr-2 <?= $sinLeer > 0 ? 'font-extrabold text-gray-900' : 'font-medium text-gray-700' ?>">
                                    <?= htmlspecialchars($nombre_corto) ?>
                                </h3>
                                <span class="text-[11px] shrink-0 <?= $sinLeer > 0 ? 'font-bold text-[#54A6D8]' : 'font-medium text-gray-400' ?>">
                                    <?= $tiempo ?>
                                </span>
                            </div>
                            <p class="text-[11px] truncate mb-0.5 <?= $sinLeer > 0 ? 'font-semibold text-[#54A6D8]' : 'font-medium text-gray-400 opacity-80' ?>">
                                <?= htmlspecialchars($chat['servicio_titulo'] ?? 'Servicio') ?>
                            </p>
                            <p class="text-[13px] truncate leading-snug <?= $sinLeer > 0 ? 'font-bold text-gray-900' : 'font-normal text-gray-500' ?>">
                                <?= $chat['ultimo_mensaje'] ? htmlspecialchars($chat['ultimo_mensaje']) : 'Inicia la conversación...' ?>
                            </p>
                        </div>

                        <?php if($sinLeer > 0): ?>
    <div class="shrink-0 pl-2">
        <span class="bg-red-500 text-white text-[10px] font-bold h-5 min-w-[20px] px-1.5 rounded-full flex items-center justify-center shadow-sm animate-[popIn_0.3s_ease-out_forwards]">
            <?= $sinLeer > 99 ? '99+' : $sinLeer ?>
        </span>
    </div>
<?php else: ?>
                            <div class="shrink-0 pl-2">
                                <?= icon('chevron-right', 'w-3.5 h-3.5 text-gray-300') ?>
                            </div>
                        <?php endif; ?>

                    </a>
                </div>

                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </main>

    <?php require_once __DIR__ . '/componentes/nav_bottom.php'; ?>
    <?php require_once __DIR__ . '/componentes/modal_publicar.php'; ?>
    <?php require_once __DIR__ . '/componentes/modal_explora.php'; ?>

    <script>
        window.onload = () => { const l = document.getElementById('loader'); if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } };

        function setupModal(triggerId, modalId, cardId, closeId) {
            const btn=document.getElementById(triggerId), modal=document.getElementById(modalId), card=document.getElementById(cardId), close=document.getElementById(closeId);
            if(!btn||!modal) return;
            const open=()=>{ modal.classList.remove('hidden'); requestAnimationFrame(()=>card.classList.remove('translate-y-full','opacity-0')); document.body.style.overflow='hidden'; };
            const shut=()=>{ card.classList.add('translate-y-full','opacity-0'); setTimeout(()=>{modal.classList.add('hidden');document.body.style.overflow='';},300); };
            btn.onclick=(e)=>{e.preventDefault();open();}; 
            if(close) close.onclick=shut; 
            modal.onclick=(e)=>{if(e.target===modal)shut();};
        }
        setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
        setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');

        function toggleEditMode(active) {
            const headerNormal = document.getElementById('header-normal');
            const headerEdit = document.getElementById('header-edit');
            const lista = document.getElementById('lista-chats');
            const selectAllBox = document.getElementById('select-all-box');
            
            if (active) {
                headerNormal.classList.add('hidden');
                headerEdit.classList.remove('hidden');
                lista.classList.add('editing');
                selectAllBox.classList.remove('hidden');
            } else {
                headerNormal.classList.remove('hidden');
                headerEdit.classList.add('hidden');
                lista.classList.remove('editing');
                selectAllBox.classList.add('hidden');
                document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
                document.getElementById('check-all').checked = false;
                updateCounter();
            }
        }

        function toggleSelectAll(source) {
            const checkboxes = document.querySelectorAll('.row-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
            updateCounter();
        }

        function updateCounter() {
            const count = document.querySelectorAll('.row-checkbox:checked').length;
            document.getElementById('selected-count').innerText = count;
            const btn = document.getElementById('btn-trash');
            btn.disabled = count === 0;
            btn.style.opacity = count === 0 ? '0.5' : '1';
        }

       async function borrarSeleccionados() {
            const selected = Array.from(document.querySelectorAll('.row-checkbox:checked')).map(cb => cb.value);
            if(selected.length === 0) return;
            if(!confirm(`¿Eliminar ${selected.length} chats?`)) return;

            // 1. Animación y remoción del DOM
            selected.forEach(id => {
                const row = document.getElementById('row-' + id);
                if(row) { 
                    row.style.transform = 'translateX(-100%)'; 
                    row.style.opacity = '0'; 
                    setTimeout(() => row.remove(), 300); 
                }
            });

            // 2. Envío al backend
            try {
                const formData = new FormData();
                selected.forEach(id => formData.append('ids[]', id));
                
                await fetch('/app/eliminar_conversacion.php', { 
                    method: 'POST', 
                    body: formData 
                });
            } catch (e) { 
                console.error(e); 
            }

            // 3. Salir del modo edición
            toggleEditMode(false);
        }
        // =========================================================
        // NUBIRA 2.0: SMART POLLING (Bandeja en Tiempo Real)
        // =========================================================
        function syncBandejaSilencioso() {
            const lista = document.getElementById('lista-chats');
            
            // 1. Regla de Oro Mobile: Si la pestaña no está visible (o la app está minimizada), no gastar batería/datos.
            if (document.hidden) return;
            
            // 2. Regla de UX: Si el usuario está marcando checks para eliminar chats (Modo Edición), pausamos el sync para no borrarle lo que seleccionó.
            if (lista.classList.contains('editing')) return;

            // 3. Fetch silencioso a la misma URL actual
            fetch(window.location.href, { cache: "no-store" }) // Evitamos caché del navegador
                .then(response => response.text())
                .then(htmlString => {
                    // Convertimos el texto a DOM para poder recortar lo que necesitamos
                    const doc = new DOMParser().parseFromString(htmlString, 'text/html');
                    const nuevaLista = doc.getElementById('lista-chats');

                   if (nuevaLista) {
                        if (lista.innerHTML !== nuevaLista.innerHTML) {
                            
                            // 1. NUBIRA 2.0: CÁLCULO OPTIMISTA (0ms) REAL
                            // Leemos los números directamente del "nuevaLista" ANTES de inyectarlos al DOM
                            let totalBandeja = 0;
                            const globitos = nuevaLista.querySelectorAll('.bg-red-500');
                            
                            globitos.forEach(g => {
                                // Usamos textContent en lugar de innerText para evitar bugs de renderizado
                                const texto = g.textContent || '';
                                const num = parseInt(texto.trim());
                                if (!isNaN(num)) totalBandeja += num;
                            });

                            // Forzamos el sidebar visualmente al mismo milisegundo exacto
                            const badgeSidebar = document.getElementById('badge-chats-sidebar');
                            if (badgeSidebar) {
                                if (totalBandeja > 0) {
                                    badgeSidebar.textContent = totalBandeja > 99 ? '99+' : totalBandeja;
                                    badgeSidebar.classList.remove('hidden', 'scale-0');
                                } else {
                                    badgeSidebar.classList.add('scale-0');
                                    setTimeout(() => badgeSidebar.classList.add('hidden'), 300);
                                }
                            }

                            // 2. Actualizamos los chats visualmente en la pantalla
                            lista.innerHTML = nuevaLista.innerHTML;

                            // 3. Ejecutamos el header en segundo plano solo por si hay otras notificaciones distintas al chat
                            if (typeof checkHeaderAlerts === 'function') checkHeaderAlerts();
                            
                            // 4. Vibración nativa
                            if (navigator.vibrate) navigator.vibrate(200); 
                        }
                    }
                })
                .catch(err => console.log('Error silente en sync de bandeja:', err));
        }

        // Ejecutar cada 12 segundos (Suficientemente rápido para un chat, sin matar el servidor)
        setInterval(syncBandejaSilencioso, 12000);

        // Cuando el usuario vuelve a abrir la pestaña del navegador (o desminimiza la app), 
        // forzamos una actualización inmediata sin esperar los 12 segundos.
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                syncBandejaSilencioso();
            }
        });
    </script>
</body>
</html>