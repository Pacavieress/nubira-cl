<?php
/**
 * VISTA: MODERACIÓN DE ARCHIVO — NUBIRA 2.0
 * Permite al admin revisar una imagen pendiente y aplicar censura visual
 * dibujando rectángulos sobre el canvas. La censura se graba en el archivo físico.
 */
session_start();

require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header("Location: /dashboard");
    exit;
}

$msg_id = (int)($_GET['m'] ?? 0);
if ($msg_id <= 0) {
    header("Location: /admin/chats?estado=moderacion");
    exit;
}

$stmt = $conn->prepare("
    SELECT m.id, m.conversacion_id, m.archivo_ruta, m.archivo_nombre, m.archivo_tipo, m.archivo_peso, m.enviado_en,
           a.nombre AS remitente_nombre
    FROM mensajes m
    JOIN alumnos a ON m.remitente_id = a.id
    WHERE m.id = ? AND m.visible = 0 AND m.archivo_ruta IS NOT NULL
    LIMIT 1
");
$stmt->bind_param("i", $msg_id);
$stmt->execute();
$msg = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$msg || strpos($msg['archivo_tipo'] ?? '', 'image/') !== 0) {
    header("Location: /admin/chats?estado=moderacion");
    exit;
}

$url_imagen = '/app/ver_archivo_chat.php?m=' . $msg_id;
$nombre_r   = htmlspecialchars($msg['remitente_nombre'] ?? 'Desconocido', ENT_QUOTES, 'UTF-8');
$peso_kb    = round(($msg['archivo_peso'] ?? 0) / 1024);
$fecha_r    = $msg['enviado_en'] ? date('d/m/Y H:i', strtotime($msg['enviado_en'])) : '--';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Moderar Archivo | Nubira Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        #canvas-wrapper { position: relative; display: inline-block; line-height: 0; cursor: crosshair; }
        #overlay-canvas { position: absolute; top: 0; left: 0; cursor: crosshair; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 antialiased min-h-screen p-6">

<div class="max-w-5xl mx-auto">
    <div class="flex items-center gap-4 mb-6">
        <a href="/admin/chats?estado=moderacion" class="text-[#54A6D8] hover:bg-sky-50 p-2 rounded-full transition-colors shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
        </a>
        <div>
            <h1 class="text-xl font-extrabold tracking-tight">Moderar archivo</h1>
            <p class="text-xs text-gray-400 font-medium"><?php echo $nombre_r; ?> · Chat #<?php echo (int)$msg['conversacion_id']; ?> · <?php echo $fecha_r; ?> · <?php echo $peso_kb; ?> KB</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Canvas -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                <p class="text-xs font-bold text-gray-500 mb-3 uppercase tracking-wider">Arrastra para marcar zonas a censurar</p>
                <div class="rounded-xl bg-gray-100 flex items-center justify-center overflow-auto" style="min-height:300px">
                    <div id="canvas-wrapper">
                        <img id="img-preview" src="<?php echo $url_imagen; ?>" alt="Imagen a moderar"
                             class="block max-w-full rounded-xl"
                             style="max-height:68vh"
                             draggable="false">
                        <canvas id="overlay-canvas"></canvas>
                    </div>
                </div>
                <p class="text-[10px] text-gray-400 mt-2 font-medium">Haz clic y arrastra para marcar una zona. Puedes agregar varias regiones.</p>
            </div>
        </div>

        <!-- Panel acciones -->
        <div class="flex flex-col gap-4">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                <p class="text-xs font-bold text-gray-700 mb-3 uppercase tracking-wider">Regiones marcadas</p>
                <div id="rect-list" class="flex flex-col gap-2">
                    <p class="text-xs text-gray-400 italic" id="rect-empty">Ninguna región marcada.</p>
                </div>
            </div>

            <div class="flex flex-col gap-3">
                <button id="btn-aplicar" disabled
                    class="w-full bg-amber-400 hover:bg-amber-500 text-white font-bold py-3 px-4 rounded-xl transition-all shadow-sm text-sm disabled:opacity-40 disabled:cursor-not-allowed">
                    Aplicar censura
                </button>
                <button id="btn-aprobar"
                    class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-bold py-3 px-4 rounded-xl transition-all shadow-sm text-sm">
                    Aprobar sin censurar
                </button>
                <button id="btn-rechazar"
                    class="w-full bg-red-500 hover:bg-red-600 text-white font-bold py-3 px-4 rounded-xl transition-all shadow-sm text-sm">
                    Rechazar
                </button>
            </div>

            <div id="status-msg" class="hidden text-xs font-bold text-center py-2 px-3 rounded-xl"></div>
        </div>
    </div>
</div>

<script>
(function () {
    const MSG_ID    = <?php echo (int)$msg_id; ?>;
    const canvas    = document.getElementById('overlay-canvas');
    const ctx       = canvas.getContext('2d');
    const img       = document.getElementById('img-preview');
    const rectList  = document.getElementById('rect-list');
    const rectEmpty = document.getElementById('rect-empty');
    const btnAplicar = document.getElementById('btn-aplicar');

    let rects = [];
    let drawing = false;
    let startX = 0, startY = 0;

    function sincronizarCanvas() {
        canvas.width  = img.offsetWidth;
        canvas.height = img.offsetHeight;
        redraw();
    }

    img.addEventListener('load', sincronizarCanvas);
    window.addEventListener('resize', sincronizarCanvas);
    if (img.complete && img.naturalWidth > 0) sincronizarCanvas();

    function redraw() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        rects.forEach((r, i) => {
            const x = r.x_pct * canvas.width;
            const y = r.y_pct * canvas.height;
            const w = r.w_pct * canvas.width;
            const h = r.h_pct * canvas.height;
            ctx.fillStyle = 'rgba(0,0,0,0.82)';
            ctx.fillRect(x, y, w, h);
            ctx.strokeStyle = '#f59e0b';
            ctx.lineWidth = 2;
            ctx.strokeRect(x, y, w, h);
            ctx.fillStyle = '#f59e0b';
            ctx.font = 'bold 12px Inter, sans-serif';
            ctx.fillText(i + 1, x + 5, y + 15);
        });
    }

    function getPos(e) {
        const rect = canvas.getBoundingClientRect();
        const src  = e.touches ? e.touches[0] : e;
        return { x: src.clientX - rect.left, y: src.clientY - rect.top };
    }

    canvas.addEventListener('mousedown', e => {
        const p = getPos(e); startX = p.x; startY = p.y; drawing = true;
    });
    canvas.addEventListener('touchstart', e => {
        e.preventDefault(); const p = getPos(e); startX = p.x; startY = p.y; drawing = true;
    }, { passive: false });

    function onMove(e) {
        if (!drawing) return;
        if (e.touches) e.preventDefault();
        const p = getPos(e);
        redraw();
        ctx.fillStyle = 'rgba(0,0,0,0.5)';
        ctx.fillRect(startX, startY, p.x - startX, p.y - startY);
        ctx.strokeStyle = '#f59e0b';
        ctx.lineWidth = 2;
        ctx.strokeRect(startX, startY, p.x - startX, p.y - startY);
    }
    canvas.addEventListener('mousemove', onMove);
    canvas.addEventListener('touchmove', onMove, { passive: false });

    function onEnd(e) {
        if (!drawing) return;
        drawing = false;
        const src = e.changedTouches ? e.changedTouches[0] : e;
        const p   = getPos({ clientX: src.clientX, clientY: src.clientY });
        const w   = p.x - startX;
        const h   = p.y - startY;
        if (Math.abs(w) < 5 || Math.abs(h) < 5) { redraw(); return; }
        rects.push({
            x_pct: Math.min(startX, p.x) / canvas.width,
            y_pct: Math.min(startY, p.y) / canvas.height,
            w_pct: Math.abs(w) / canvas.width,
            h_pct: Math.abs(h) / canvas.height
        });
        redraw();
        actualizarLista();
    }
    canvas.addEventListener('mouseup',    onEnd);
    canvas.addEventListener('mouseleave', () => { if (drawing) { drawing = false; redraw(); } });
    canvas.addEventListener('touchend',   onEnd);

    function actualizarLista() {
        rectList.querySelectorAll('.rect-item').forEach(el => el.remove());
        if (rects.length === 0) {
            rectEmpty.style.display = '';
            btnAplicar.disabled = true;
            return;
        }
        rectEmpty.style.display = 'none';
        btnAplicar.disabled = false;
        rects.forEach((r, i) => {
            const div = document.createElement('div');
            div.className = 'rect-item flex items-center justify-between bg-amber-50 border border-amber-100 rounded-xl px-3 py-2';
            div.innerHTML = `<span class="text-xs font-bold text-amber-700">Región ${i + 1}</span><button class="text-red-400 hover:text-red-600 text-xs font-bold px-2 py-1 rounded-lg hover:bg-red-50 transition-all">Eliminar</button>`;
            div.querySelector('button').addEventListener('click', () => {
                rects.splice(i, 1); redraw(); actualizarLista();
            });
            rectList.appendChild(div);
        });
    }

    function mostrarStatus(msg, tipo) {
        const el = document.getElementById('status-msg');
        el.textContent = msg;
        el.className = `text-xs font-bold text-center py-2 px-3 rounded-xl ${tipo === 'ok' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`;
        el.classList.remove('hidden');
    }

    function postAdmin(accion) {
        const fd = new FormData();
        fd.append('ajax_accion', accion);
        fd.append('msg_id', MSG_ID);
        return fetch('/app/admin_chats.php', { method: 'POST', body: fd }).then(r => r.json());
    }

    btnAplicar.addEventListener('click', () => {
        if (rects.length === 0) return;
        btnAplicar.disabled = true;
        btnAplicar.textContent = 'Aplicando...';
        const fd = new FormData();
        fd.append('msg_id', MSG_ID);
        fd.append('rects', JSON.stringify(rects));
        fetch('/app/aplicar_censura.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    mostrarStatus('Censura aplicada correctamente', 'ok');
                    setTimeout(() => window.location.href = '/admin/chats?estado=moderacion', 1200);
                } else {
                    mostrarStatus(data.msg || 'Error al aplicar censura', 'error');
                    btnAplicar.disabled = false;
                    btnAplicar.textContent = 'Aplicar censura';
                }
            })
            .catch(() => {
                mostrarStatus('Error de conexión', 'error');
                btnAplicar.disabled = false;
                btnAplicar.textContent = 'Aplicar censura';
            });
    });

    document.getElementById('btn-aprobar').addEventListener('click', () => {
        if (!confirm('¿Aprobar este archivo sin aplicar censura?')) return;
        postAdmin('aprobar_archivo').then(data => {
            if (data.ok) {
                mostrarStatus('Archivo aprobado', 'ok');
                setTimeout(() => window.location.href = '/admin/chats?estado=moderacion', 1000);
            } else {
                mostrarStatus(data.msg || 'Error', 'error');
            }
        }).catch(() => mostrarStatus('Error de conexión', 'error'));
    });

    document.getElementById('btn-rechazar').addEventListener('click', () => {
        if (!confirm('¿Rechazar y eliminar este archivo permanentemente?')) return;
        postAdmin('rechazar_archivo').then(data => {
            if (data.ok) {
                mostrarStatus('Archivo rechazado y eliminado', 'ok');
                setTimeout(() => window.location.href = '/admin/chats?estado=moderacion', 1000);
            } else {
                mostrarStatus(data.msg || 'Error', 'error');
            }
        }).catch(() => mostrarStatus('Error de conexión', 'error'));
    });
})();
</script>
</body>
</html>
