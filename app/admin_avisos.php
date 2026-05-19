<?php
/**
 * PANEL ADMIN: GESTIÓN DE AVISOS A USUARIOS (NUBIRA 2.0)
 * ESTADO: BLINDADO (CSRF + RBAC + Soporte imágenes hasta 3)
 */
require_once __DIR__ . '/init_sesion.php';
require_once __DIR__ . '/iconos.php';

if (($_SESSION['rol'] ?? '') !== 'admin') {
    header("Location: /"); exit;
}

// Métricas globales
$stats = $conn->query("SELECT COUNT(*) total, SUM(total_destinatarios) destinatarios FROM avisos_campanas")->fetch_assoc();

// Historial de campañas con métricas
$campanas = [];
$res = $conn->query("
    SELECT c.*, 
           (SELECT COUNT(*) FROM avisos_admin WHERE campana_id = c.id AND leido = 1) AS leidos
    FROM avisos_campanas c 
    ORDER BY c.fecha_creacion DESC 
    LIMIT 50
");
while ($r = $res->fetch_assoc()) $campanas[] = $r;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Avisos | Admin Nubira</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:'Inter',sans-serif;background:#f8fafc}</style>
</head>
<body class="text-gray-900">

<?php require_once __DIR__ . '/componentes/header.php'; ?>
<?php require_once __DIR__ . '/componentes/sidebar.php'; ?>

<main class="pt-20 pb-28 md:pb-10 md:ml-64 px-4 max-w-[1100px] mx-auto md:px-8">

    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center gap-2 mb-1">
            <span class="w-1.5 h-1.5 rounded-full bg-[#54A6D8]"></span>
            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Admin · Comunicaciones</p>
        </div>
        <h1 class="text-2xl font-semibold tracking-tight">Avisos a usuarios</h1>
    </div>

    <!-- Métricas -->
    <div class="grid grid-cols-2 gap-3 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1">Campañas enviadas</p>
            <p class="text-2xl font-bold"><?= (int)($stats['total'] ?? 0) ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1">Total destinatarios</p>
            <p class="text-2xl font-bold"><?= (int)($stats['destinatarios'] ?? 0) ?></p>
        </div>
    </div>

    <!-- Formulario nueva campaña -->
    <section class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <h2 class="text-base font-semibold mb-4">Nueva campaña</h2>
        
        <div class="space-y-4">
            <div>
             <label class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Título interno</label>
<input id="f-titulo" type="text" maxlength="40" placeholder="Ej: Configura tus horarios" 
       class="w-full mt-1 px-4 py-2.5 border border-gray-200 rounded-lg focus:border-[#54A6D8] focus:ring-2 focus:ring-[#54A6D8]/10 outline-none text-sm"
       oninput="document.getElementById('f-counter-titulo').textContent=this.value.length+' / 40'">
<p id="f-counter-titulo" class="text-[11px] text-gray-400 text-right mt-1">0 / 40</p>
            </div>

            <div>
              <label class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Mensaje al usuario</label>
<textarea id="f-mensaje" maxlength="350" rows="4" placeholder="Escribe el mensaje que verán los usuarios..."
          class="w-full mt-1 p-4 border border-gray-200 rounded-lg focus:border-[#54A6D8] focus:ring-2 focus:ring-[#54A6D8]/10 outline-none text-sm resize-none"
          oninput="document.getElementById('f-counter').textContent=this.value.length+' / 350'"></textarea>
<p id="f-counter" class="text-[11px] text-gray-400 text-right mt-1">0 / 350</p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Tipo</label>
                    <select id="f-tipo" class="w-full mt-1 px-4 py-2.5 border border-gray-200 rounded-lg outline-none text-sm bg-white">
                        <option value="info">Info</option>
                        <option value="novedad">Novedad</option>
                        <option value="importante">Importante</option>
                    </select>
                </div>
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Enviar a</label>
                    <select id="f-segmento" class="w-full mt-1 px-4 py-2.5 border border-gray-200 rounded-lg outline-none text-sm bg-white" onchange="toggleBuscador()">
                        <option value="todos">Todos los usuarios</option>
                        <option value="tutores">Solo tutores (con publicaciones)</option>
                        <option value="no_tutores">Solo no-tutores</option>
                        <option value="usuario">Usuario específico</option>
                    </select>
                </div>
            </div>

            <!-- Buscador de usuario (oculto por defecto) -->
            <div id="buscador-usuario" class="hidden">
                <label class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Buscar usuario</label>
                <div class="relative mt-1">
                    <input id="f-buscar" type="text" placeholder="Nombre o correo..." autocomplete="off"
                           oninput="buscarUsuarios()"
                           class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:border-[#54A6D8] focus:ring-2 focus:ring-[#54A6D8]/10 outline-none text-sm">
                    
                    <div id="resultados-busqueda" class="hidden absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-64 overflow-y-auto z-10"></div>
                </div>
                
                <!-- Usuario seleccionado -->
                <div id="usuario-seleccionado" class="hidden mt-2 flex items-center justify-between gap-3 px-3 py-2 bg-sky-50 border border-sky-200 rounded-lg">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium truncate" id="sel-nombre"></p>
                        <p class="text-[11px] text-gray-500 truncate" id="sel-correo"></p>
                    </div>
                    <button onclick="limpiarSeleccion()" class="text-gray-400 hover:text-rose-500 shrink-0"><i class="fa-solid fa-xmark"></i></button>
                </div>
            </div>

            <!-- Uploader de imágenes -->
            <div>
                <label class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Imágenes (opcional, hasta 3)</label>
                
                <div id="dropzone" 
                     class="mt-1 border-2 border-dashed border-gray-200 rounded-lg p-6 text-center cursor-pointer hover:border-[#54A6D8] hover:bg-sky-50/30 transition-all"
                     onclick="document.getElementById('input-imagen').click()">
                    <i class="fa-solid fa-cloud-arrow-up text-gray-400 text-2xl mb-2"></i>
                    <p class="text-sm text-gray-600">Arrastra o haz click para subir</p>
                    <p class="text-[11px] text-gray-400 mt-1">JPG, PNG o WebP · Máx 3 MB c/u</p>
                </div>
                <input type="file" id="input-imagen" class="hidden" accept="image/jpeg,image/png,image/webp" multiple onchange="manejarArchivos(this.files)">

                <!-- Previews -->
                <div id="previews" class="hidden mt-3 grid grid-cols-3 gap-2"></div>
            </div>

            <div class="flex items-center justify-between pt-2">
                <p id="f-error" class="text-rose-500 text-[12px] hidden"></p>
                <button id="btn-enviar" onclick="enviarCampana()" class="ml-auto px-6 py-2.5 bg-gray-900 hover:bg-black text-white text-[13px] font-medium rounded-lg transition-all active:scale-[0.98]">
                    Enviar campaña
                </button>
            </div>
        </div>
    </section>

    <!-- Historial -->
    <section class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="text-base font-semibold">Historial</h2>
        </div>
        
        <?php if (empty($campanas)): ?>
            <div class="px-6 py-12 text-center text-gray-400 text-sm">Aún no has enviado campañas.</div>
        <?php else: ?>
            <div class="divide-y divide-gray-100">
               <?php foreach($campanas as $c): 
    $pct = $c['total_destinatarios'] > 0 ? round(($c['leidos'] / $c['total_destinatarios']) * 100) : 0;
    $color_tipo = ['info'=>'bg-gray-100 text-gray-700', 'novedad'=>'bg-sky-50 text-sky-700', 'importante'=>'bg-rose-50 text-rose-700'][$c['tipo']];
?>
<div class="campana-item" data-id="<?= (int)$c['id'] ?>">
    
    <!-- Header colapsado (siempre visible) -->
    <button type="button" onclick="toggleCampana(<?= (int)$c['id'] ?>)" 
            class="w-full px-6 py-4 hover:bg-gray-50 transition-colors text-left flex items-center gap-3">
        
        <!-- Icono + / − -->
        <span class="campana-toggle-icon w-6 h-6 rounded-full bg-gray-100 flex items-center justify-center shrink-0 transition-transform">
            <i class="fa-solid fa-plus text-[10px] text-gray-500"></i>
        </span>
        
        <!-- Título + tipo -->
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                <h3 class="font-semibold text-sm truncate"><?= htmlspecialchars($c['titulo']) ?></h3>
                <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded <?= $color_tipo ?>"><?= $c['tipo'] ?></span>
            </div>
            <p class="text-[11px] text-gray-400 mt-0.5"><?= date('d/m/Y H:i', strtotime($c['fecha_creacion'])) ?> · <?= (int)$c['leidos'] ?>/<?= (int)$c['total_destinatarios'] ?> leídos (<?= $pct ?>%)</p>
        </div>
    </button>
    
    <!-- Detalle expandible (oculto por defecto) -->
    <div class="campana-detalle hidden px-6 pb-4">
        <p class="text-xs text-gray-600 mb-3 whitespace-pre-line"><?= htmlspecialchars($c['mensaje']) ?></p>
        
        <div class="flex items-center gap-4 text-[11px] text-gray-500 mb-2">
            <span>Segmento: <strong><?= ucfirst($c['segmento']) ?></strong></span>
        </div>
        
        <div class="w-full bg-gray-100 rounded-full h-1 overflow-hidden mb-3">
            <div class="bg-[#54A6D8] h-full rounded-full transition-all" style="width:<?= $pct ?>%"></div>
        </div>
        
       <div class="flex items-center justify-between gap-2">
    <button onclick="verDetalle(<?= (int)$c['id'] ?>)" class="text-[12px] font-medium text-[#54A6D8] hover:underline">
        Ver lectores
    </button>
    <div class="flex items-center gap-3">
        <button onclick="duplicarCampana(<?= (int)$c['id'] ?>)" 
                class="text-[12px] font-medium text-gray-600 hover:text-gray-900 flex items-center gap-1.5">
            <i class="fa-solid fa-copy text-[10px]"></i>
            Duplicar y editar
        </button>
        <button onclick="eliminarCampana(<?= (int)$c['id'] ?>, this)" 
                class="text-[12px] font-medium text-rose-500 hover:text-rose-600 flex items-center gap-1.5">
            <i class="fa-solid fa-trash text-[10px]"></i>
            Eliminar
        </button>
    </div>
</div>
    </div>
</div>
<?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</main>

<!-- Modal detalle de lectores -->
<div id="modal-detalle" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4 bg-gray-900/40 backdrop-blur-md">
    <div class="bg-white w-full max-w-lg rounded-2xl shadow-xl border border-gray-200 max-h-[80vh] flex flex-col">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold">Lectores de la campaña</h3>
            <button onclick="document.getElementById('modal-detalle').classList.add('hidden')" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="detalle-body" class="overflow-y-auto p-2"></div>
    </div>
</div>

<?php require_once __DIR__ . '/componentes/nav_bottom.php'; ?>

<script>
const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';

// ============================================
// BÚSQUEDA DE USUARIOS
// ============================================
let usuarioSeleccionadoId = null;
let busquedaTimeout = null;

function toggleBuscador() {
    const seg = document.getElementById('f-segmento').value;
    const buscador = document.getElementById('buscador-usuario');
    if (seg === 'usuario') {
        buscador.classList.remove('hidden');
    } else {
        buscador.classList.add('hidden');
        limpiarSeleccion();
    }
}

function buscarUsuarios() {
    clearTimeout(busquedaTimeout);
    const q = document.getElementById('f-buscar').value.trim();
    const cont = document.getElementById('resultados-busqueda');
    
    if (q.length < 2) {
        cont.classList.add('hidden');
        return;
    }
    
    busquedaTimeout = setTimeout(async () => {
        try {
            const r = await fetch('/app/admin_buscar_usuarios.php?q=' + encodeURIComponent(q));
            const d = await r.json();
            
            if (!d.success || d.usuarios.length === 0) {
                cont.innerHTML = '<div class="px-4 py-3 text-sm text-gray-400">Sin resultados</div>';
                cont.classList.remove('hidden');
                return;
            }
            
            cont.innerHTML = d.usuarios.map(u => `
                <div onclick="seleccionarUsuario(${u.id}, '${u.nombre.replace(/'/g, "\\'")}', '${u.correo.replace(/'/g, "\\'")}')" 
                     class="px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-0">
                    <p class="text-sm font-medium">${u.nombre}</p>
                    <p class="text-[11px] text-gray-500">${u.correo} · ${u.institucion}</p>
                </div>
            `).join('');
            cont.classList.remove('hidden');
        } catch(e) {
            cont.innerHTML = '<div class="px-4 py-3 text-sm text-rose-500">Error de búsqueda</div>';
            cont.classList.remove('hidden');
        }
    }, 300);
}

function seleccionarUsuario(id, nombre, correo) {
    usuarioSeleccionadoId = id;
    document.getElementById('sel-nombre').textContent = nombre;
    document.getElementById('sel-correo').textContent = correo;
    document.getElementById('usuario-seleccionado').classList.remove('hidden');
    document.getElementById('resultados-busqueda').classList.add('hidden');
    document.getElementById('f-buscar').value = '';
}

function limpiarSeleccion() {
    usuarioSeleccionadoId = null;
    document.getElementById('usuario-seleccionado').classList.add('hidden');
    document.getElementById('f-buscar').value = '';
}

// ============================================
// UPLOADER DE IMÁGENES
// ============================================
let imagenesSubidas = []; // [{archivo, url_preview}, ...]

function manejarArchivos(files) {
    if (!files || files.length === 0) return;
    
    const disponibles = 3 - imagenesSubidas.length;
    if (disponibles <= 0) {
        alert('Máximo 3 imágenes por aviso.');
        return;
    }
    
    const aSubir = Array.from(files).slice(0, disponibles);
    aSubir.forEach(subirImagen);
}

async function subirImagen(file) {
    const previewsEl = document.getElementById('previews');
    previewsEl.classList.remove('hidden');
    
    // Placeholder con spinner
    const tempId = 'temp-' + Date.now() + '-' + Math.random();
    const placeholder = document.createElement('div');
    placeholder.id = tempId;
    placeholder.className = 'relative aspect-square bg-gray-100 rounded-lg flex items-center justify-center';
    placeholder.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-gray-400"></i>';
    previewsEl.appendChild(placeholder);
    
    try {
        const fd = new FormData();
        fd.append('imagen', file);
        fd.append('csrf_token', CSRF_TOKEN);
        
        const r = await fetch('/app/admin_subir_imagen_aviso.php', { method: 'POST', body: fd });
        const d = await r.json();
        
        placeholder.remove();
        
        if (!d.success) {
            alert(d.error);
            return;
        }
        
        imagenesSubidas.push({ archivo: d.archivo, url_preview: d.url_preview });
        renderizarPreviews();
    } catch (e) {
        placeholder.remove();
        alert('Error de conexión al subir.');
    }
}

function renderizarPreviews() {
    const previewsEl = document.getElementById('previews');
    
    if (imagenesSubidas.length === 0) {
        previewsEl.classList.add('hidden');
        previewsEl.innerHTML = '';
        return;
    }
    
    previewsEl.classList.remove('hidden'); // ← FIX
    previewsEl.innerHTML = imagenesSubidas.map((img, idx) => `
        <div class="relative aspect-square bg-gray-100 rounded-lg overflow-hidden group">
            <img src="${img.url_preview}" class="w-full h-full object-cover">
            <div class="absolute top-1 left-1 bg-black/60 text-white text-[10px] font-bold rounded px-1.5 py-0.5">${idx + 1}</div>
            <button onclick="quitarImagen(${idx})" class="absolute top-1 right-1 w-6 h-6 rounded-full bg-black/60 hover:bg-rose-500 text-white text-xs opacity-0 group-hover:opacity-100 transition-opacity">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    `).join('');
}

function quitarImagen(idx) {
    imagenesSubidas.splice(idx, 1);
    renderizarPreviews();
}

// Drag & drop nativo
document.addEventListener('DOMContentLoaded', () => {
    const dz = document.getElementById('dropzone');
    if (!dz) return;
    
    ['dragenter', 'dragover'].forEach(ev => {
        dz.addEventListener(ev, e => {
            e.preventDefault();
            dz.classList.add('border-[#54A6D8]', 'bg-sky-50/30');
        });
    });
    
    ['dragleave', 'drop'].forEach(ev => {
        dz.addEventListener(ev, e => {
            e.preventDefault();
            dz.classList.remove('border-[#54A6D8]', 'bg-sky-50/30');
        });
    });
    
    dz.addEventListener('drop', e => {
        const files = e.dataTransfer.files;
        manejarArchivos(files);
    });
});

// ============================================
// ENVÍO DE CAMPAÑA
// ============================================
async function enviarCampana() {
    const titulo = document.getElementById('f-titulo').value.trim();
    const mensaje = document.getElementById('f-mensaje').value.trim();
    const tipo = document.getElementById('f-tipo').value;
    const segmento = document.getElementById('f-segmento').value;
    const err = document.getElementById('f-error');
    const btn = document.getElementById('btn-enviar');

    err.classList.add('hidden');

    if (titulo.length < 3 || mensaje.length < 5) {
        err.textContent = 'Título mínimo 3 caracteres, mensaje mínimo 5.';
        err.classList.remove('hidden');
        return;
    }

    if (segmento === 'usuario' && !usuarioSeleccionadoId) {
        err.textContent = 'Selecciona un usuario primero.';
        err.classList.remove('hidden');
        return;
    }

    if (!confirm(`¿Enviar a segmento "${segmento}"? Esta acción no se puede deshacer.`)) return;

    btn.disabled = true;
    btn.textContent = 'Enviando...';

    try {
        const fd = new FormData();
        fd.append('titulo', titulo);
        fd.append('mensaje', mensaje);
        fd.append('tipo', tipo);
        fd.append('segmento', segmento);
        if (segmento === 'usuario') fd.append('usuario_id', usuarioSeleccionadoId);
        
        // Imágenes subidas (archivos en /upload/avisos/temp/{admin_id}/)
       imagenesSubidas.forEach(img => {
    fd.append('imagenes[]', img.archivo);
    // Si la imagen viene de una campaña existente, mandamos su origen
    fd.append('imagenes_origen[]', img.origen_campana_id || 'temp');
});
        fd.append('csrf_token', CSRF_TOKEN);

        const r = await fetch('/app/admin_enviar_aviso_masivo.php', {method:'POST', body:fd});
        const d = await r.json();

        if (d.success) {
            imagenesSubidas = [];
            alert(`✓ ${d.mensaje}`);
            location.reload();
        } else {
            err.textContent = d.error;
            err.classList.remove('hidden');
        }
    } catch(e) {
        err.textContent = 'Error de conexión.';
        err.classList.remove('hidden');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Enviar campaña';
    }
}

// ============================================
// DETALLE DE LECTORES
// ============================================
async function verDetalle(campanaId) {
    const modal = document.getElementById('modal-detalle');
    const body = document.getElementById('detalle-body');
    body.innerHTML = '<div class="text-center text-gray-400 py-8 text-sm">Cargando...</div>';
    modal.classList.remove('hidden');

    try {
        const r = await fetch('/app/admin_avisos_detalle.php?campana_id=' + campanaId);
        const d = await r.json();
        if (!d.success) throw new Error(d.error);

        if (d.lectores.length === 0) {
            body.innerHTML = '<div class="text-center text-gray-400 py-8 text-sm">Aún nadie ha leído esta campaña.</div>';
            return;
        }

        body.innerHTML = d.lectores.map(l => `
            <div class="px-4 py-3 border-b border-gray-100 last:border-0 flex items-center justify-between">
                <div class="min-w-0">
                    <p class="font-medium text-sm truncate">${l.nombre}</p>
                    <p class="text-[11px] text-gray-500">${l.institucion || 'Sin institución'}</p>
                </div>
                <p class="text-[11px] text-gray-400 shrink-0">${l.fecha_leido}</p>
            </div>
        `).join('');
    } catch(e) {
        body.innerHTML = '<div class="text-center text-rose-500 py-8 text-sm">Error al cargar.</div>';
    }
}
// ============================================
// ACORDEÓN HISTORIAL
// ============================================
function toggleCampana(id) {
    const item = document.querySelector(`.campana-item[data-id="${id}"]`);
    if (!item) return;
    const detalle = item.querySelector('.campana-detalle');
    const icono = item.querySelector('.campana-toggle-icon i');
    
    if (detalle.classList.contains('hidden')) {
        detalle.classList.remove('hidden');
        icono.classList.remove('fa-plus');
        icono.classList.add('fa-minus');
    } else {
        detalle.classList.add('hidden');
        icono.classList.remove('fa-minus');
        icono.classList.add('fa-plus');
    }
}

// ============================================
// ELIMINAR CAMPAÑA
// ============================================
async function eliminarCampana(id, btn) {
    if (!confirm('¿Eliminar esta campaña? Se borrarán también los avisos enviados a los usuarios y las imágenes. Esta acción es permanente.')) return;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-[10px]"></i> Eliminando...';
    
    try {
        const fd = new FormData();
        fd.append('campana_id', id);
        fd.append('csrf_token', CSRF_TOKEN);
        
        const r = await fetch('/app/admin_eliminar_campana.php', { method: 'POST', body: fd });
        const d = await r.json();
        
        if (d.success) {
            const item = document.querySelector(`.campana-item[data-id="${id}"]`);
            if (item) {
                item.style.transition = 'opacity 0.3s ease';
                item.style.opacity = '0';
                setTimeout(() => item.remove(), 300);
            }
        } else {
            alert(d.error || 'Error al eliminar.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-trash text-[10px]"></i> Eliminar';
        }
    } catch (e) {
        alert('Error de conexión.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-trash text-[10px]"></i> Eliminar';
    }
}

// ============================================
// DUPLICAR CAMPAÑA Y EDITAR
// ============================================
async function duplicarCampana(id) {
    try {
        const r = await fetch('/app/admin_obtener_campana.php?id=' + id);
        const d = await r.json();
        
        if (!d.success) {
            alert(d.error || 'No se pudo cargar la campaña.');
            return;
        }
        
        // Cargar datos en el formulario
        document.getElementById('f-titulo').value = d.campana.titulo;
        document.getElementById('f-mensaje').value = d.campana.mensaje;
        document.getElementById('f-tipo').value = d.campana.tipo;
        document.getElementById('f-segmento').value = 'todos'; // por defecto al duplicar va a todos
        
        // Disparar evento para actualizar contadores
        document.getElementById('f-counter-titulo').textContent = d.campana.titulo.length + ' / 40';
        document.getElementById('f-counter').textContent = d.campana.mensaje.length + ' / 350';
        
        // Resetear buscador de usuario
        toggleBuscador();
        
        // Cargar imágenes (si las tiene)
imagenesSubidas = (d.campana.imagenes || []).map(img => ({
    archivo: img.archivo,
    url_preview: img.url_preview,
    origen_campana_id: d.campana.id // ← marca de origen al duplicar
}));
        renderizarPreviews();
        
        // Scroll al formulario
        document.querySelector('section').scrollIntoView({ behavior: 'smooth', block: 'start' });
        
        // Highlight visual del formulario
        const seccion = document.querySelector('section');
        seccion.classList.add('ring-2', 'ring-[#54A6D8]', 'ring-offset-2');
        setTimeout(() => seccion.classList.remove('ring-2', 'ring-[#54A6D8]', 'ring-offset-2'), 1500);
        
    } catch (e) {
        alert('Error de conexión.');
    }
}
</script>

</body>
</html>