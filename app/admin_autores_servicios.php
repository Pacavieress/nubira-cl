<?php
/**
 * VISTA: ADMIN AUTORES DE SERVICIOS
 * ESTADO: NUBIRA 2.0 (App Nativa, Flat Design, Header/Nav Modulares)
 */
session_start();

// 1. SEGURIDAD Y RUTAS
$app_dir = dirname(__DIR__) . '/app'; 
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__ . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__;

require_once $app_dir . '/conexion.php';
// Fallback Iconos
if (file_exists($app_dir . '/iconos.php')) require_once $app_dir . '/iconos.php';
elseif (!function_exists('icon')) { function icon($n,$c='') { return "<i class='fa-solid fa-$n $c'></i>"; } }

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
  header('Location: /login');
  exit;
}

$usuario = $_SESSION['usuario_nombre'] ?? 'Admin';
$correo  = $_SESSION['email'] ?? '';
$page_title = "Autores de Servicios";

/* ─────────────────────────────
   CONSULTA PRINCIPAL
────────────────────────────── */
$busqueda = trim($_GET['q'] ?? '');
$sql = "
SELECT 
    a.id AS id_usuario,
    a.nombre AS nombre_usuario,
    a.correo,
    a.institucion,
    COUNT(DISTINCT s.id) AS cantidad_servicios,
    MAX(s.fecha_publicacion) AS ultima_publicacion,

    (
        SELECT COUNT(*) FROM conversaciones c 
        WHERE c.comprador_id = a.id OR c.vendedor_id = a.id
    ) AS total_conversaciones,

    (
        SELECT asunto FROM correos_admin ca 
        WHERE ca.destinatario = a.correo 
        ORDER BY ca.fecha_envio DESC LIMIT 1
    ) AS ultimo_asunto,

    (
        SELECT mensaje FROM correos_admin ca 
        WHERE ca.destinatario = a.correo 
        ORDER BY ca.fecha_envio DESC LIMIT 1
    ) AS ultimo_mensaje,

       (
        SELECT fecha_envio FROM correos_admin ca 
        WHERE ca.destinatario = a.correo 
        ORDER BY ca.fecha_envio DESC LIMIT 1
    ) AS fecha_ultimo_correo,

    (
        SELECT exito FROM correos_admin ca 
        WHERE ca.destinatario = a.correo 
        ORDER BY ca.fecha_envio DESC LIMIT 1
    ) AS exito_ultimo,

  (
    SELECT s2.imagen
    FROM servicios s2
    WHERE s2.alumno_id = a.id
      AND s2.imagen IS NOT NULL
      AND s2.imagen != ''
    ORDER BY s2.fecha_publicacion DESC
    LIMIT 1
) AS portada_servicio

FROM servicios s
INNER JOIN alumnos a ON a.id = s.alumno_id
";

$params = [];
if ($busqueda !== '') {
    $sql .= " WHERE a.nombre LIKE ? OR a.correo LIKE ? OR a.institucion LIKE ? ";
    $like = "%$busqueda%";
    $params = [$like, $like, $like];
}
$sql .= " GROUP BY a.id ORDER BY cantidad_servicios DESC";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param("sss", ...$params);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Autores de Servicios | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="theme-color" content="#ffffff" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #ffffff; -webkit-tap-highlight-color: transparent;}
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    .force-no-shadow * { text-shadow: none !important; }
    .scrollbar-hide::-webkit-scrollbar { height: 6px; }
    .scrollbar-hide::-webkit-scrollbar-thumb { background-color: #e2e8f0; border-radius: 10px; }
  </style>
</head>
<body class="text-slate-800 antialiased overflow-x-hidden force-no-shadow bg-white">

<div id="loader" class="fixed inset-0 bg-white flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-8 w-8 border-4 border-slate-100 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
// INTEGRACIÓN DE MODULOS OFICIALES
require_once $app_dir . '/componentes/header.php'; 
require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-16 pb-32 md:pb-16 lg:ml-64 px-4 md:px-6 w-full md:w-[calc(100%-16rem)]">
  <div class="max-w-7xl mx-auto space-y-6">

    <div class="sticky top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-slate-100 py-4 flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-xl md:text-2xl font-extrabold text-slate-900 tracking-tight">Autores de Servicios</h1>
            <p class="text-slate-400 text-xs font-medium mt-0.5">Gestión de creadores de contenido y comunicación.</p>
        </div>
        
        <form method="get" class="flex w-full md:w-auto">
            <div class="relative w-full md:w-72">
                <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por nombre o correo..." 
                       class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-4 pr-10 py-2.5 text-sm focus:ring-0 focus:border-[#54A6D8] focus:bg-white transition-colors outline-none font-medium placeholder-slate-400">
                <button type="submit" class="absolute right-3 top-2.5 text-slate-400 active:text-[#54A6D8] transition-colors">
                    <i class="fa-solid fa-search"></i>
                </button>
            </div>
        </form>
    </div>

    <div class="bg-white border border-slate-100 rounded-3xl overflow-hidden min-h-[400px]">
      <div class="overflow-x-auto scrollbar-hide">
        <table class="w-full min-w-[1000px] text-sm text-left">
          <thead class="text-[11px] text-slate-400 uppercase tracking-widest bg-slate-50 border-b border-slate-100">
            <tr>
              <th class="px-6 py-4 font-bold text-center w-12">#</th>
              <th class="px-6 py-4 font-bold">Autor</th>
              <th class="px-6 py-4 font-bold">Institución</th>
              <th class="px-6 py-4 font-bold text-center">Rendimiento</th>
              <th class="px-6 py-4 font-bold text-center">Última Pub.</th>
              <th class="px-6 py-4 font-bold text-right">Comunicación</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-50">
          <?php if ($result->num_rows > 0):
            $i = 1;
            while ($r = $result->fetch_assoc()): 
                // Lógica Imagen
                $img = $r['portada_servicio'];
                $ruta = "/upload/servicios/" . ($img ?: "default_clases.webp"); // Update default name
                $es_default = empty($img) || $img === 'default.webp' || $img === 'default_clases.webp';
                $border_img = $es_default ? "border-amber-200" : "border-slate-200";
          ?>
            <tr class="hover:bg-slate-50 transition-colors align-middle group">
              <td class="px-6 py-4 text-center text-slate-400 font-mono text-xs"><?= $i++ ?></td>
              
              <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                      <img src="<?= htmlspecialchars($ruta) ?>" 
                           class="w-12 h-10 rounded-xl object-cover border <?= $border_img ?>" 
                           loading="lazy" decoding="async" alt="Portada"
                           onerror="this.src='/upload/servicios/default_clases.webp';">
                      <div class="min-w-0">
                          <p class="font-bold text-slate-900 text-sm truncate max-w-[200px]">
                              <?= htmlspecialchars($r['nombre_usuario']) ?>
                          </p>
                          <p class="text-[10px] font-medium text-slate-500 truncate max-w-[200px] mt-0.5">
                              <?= htmlspecialchars($r['correo']) ?>
                          </p>
                      </div>
                  </div>
              </td>
              
              <td class="px-6 py-4 text-xs font-medium text-slate-600 truncate max-w-[150px]">
                  <?= htmlspecialchars($r['institucion'] ?? '-') ?>
              </td>
              
              <td class="px-6 py-4 text-center">
                  <div class="flex items-center justify-center gap-2">
                      <div class="flex items-center gap-1 bg-blue-50 text-[#54A6D8] px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-widest" title="Servicios Publicados">
                          <i class="fa-solid fa-layer-group"></i> <?= $r['cantidad_servicios'] ?>
                      </div>
                      <?php if($r['total_conversaciones'] > 0): ?>
                          <div class="flex items-center gap-1 bg-emerald-50 text-emerald-600 px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-widest" title="Chats Activos">
                              <i class="fa-solid fa-comments"></i> <?= $r['total_conversaciones'] ?>
                          </div>
                      <?php else: ?>
                          <div class="flex items-center gap-1 bg-slate-50 text-slate-400 px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-widest" title="Sin Chats">
                              <i class="fa-regular fa-comments"></i> 0
                          </div>
                      <?php endif; ?>
                  </div>
              </td>
              
              <td class="px-6 py-4 text-center text-xs font-medium text-slate-500">
                <?= $r['ultima_publicacion'] ? date('d/m/Y', strtotime($r['ultima_publicacion'])) . '<br><span class="text-[10px] text-slate-400">'.date('H:i', strtotime($r['ultima_publicacion'])).'</span>' : '<span class="text-slate-300">-</span>' ?>
              </td>

              <td class="px-6 py-4 text-right">
                  <div class="flex flex-col items-end gap-2">
                      <button onclick="abrirModalCorreo('<?= htmlspecialchars($r['correo']) ?>', '<?= htmlspecialchars($r['nombre_usuario']) ?>')" 
                              class="bg-blue-50 active:bg-blue-100 text-[#54A6D8] px-3 py-1.5 rounded-xl font-bold text-[10px] uppercase tracking-widest transition-colors flex items-center gap-1.5">
                          <i class="fa-solid fa-paper-plane"></i> Escribir
                      </button>
                      
                      <?php if ($r['fecha_ultimo_correo']): ?>
                          <button class="text-slate-400 hover:text-slate-600 text-[10px] font-bold uppercase tracking-widest flex items-center gap-1 transition-colors"
                                  data-asunto="<?= htmlspecialchars($r['ultimo_asunto'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                  data-mensaje="<?= htmlspecialchars(strip_tags($r['ultimo_mensaje'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                  data-estado="<?= $r['exito_ultimo'] ? 'Exitoso' : 'Fallido' ?>"
                                  data-fecha="<?= date('d M, H:i', strtotime($r['fecha_ultimo_correo'])) ?>"
                                  onclick="abrirDetalleDesdeData(this)">
                              <i class="fa-solid fa-clock-rotate-left"></i> Historial
                          </button>
                      <?php else: ?>
                          <span class="text-slate-300 text-[9px] font-bold uppercase tracking-widest">Sin envíos</span>
                      <?php endif; ?>
                  </div>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="6" class="text-center py-16 text-slate-400">
                <i class="fa-solid fa-users-slash text-4xl mb-3 text-slate-200"></i><br>
                <span class="font-medium text-sm">No se encontraron autores registrados.</span>
            </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<div id="modalCorreo" class="hidden fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[70] flex items-center justify-center p-4 transition-opacity">
  <div class="bg-white rounded-3xl w-full max-w-md relative p-6 md:p-8">
    <button onclick="cerrarModalCorreo()" class="absolute top-5 right-5 text-slate-400 active:text-slate-600 w-8 h-8 bg-slate-50 rounded-full flex items-center justify-center transition-colors">
        <i class="fa-solid fa-xmark text-sm"></i>
    </button>
    
    <h2 class="text-lg font-bold text-slate-900 mb-6 flex items-center gap-2">
        <i class="fa-solid fa-envelope-open-text text-[#54A6D8]"></i> Nuevo Mensaje
    </h2>

    <form id="formCorreo" class="space-y-4">
      <input type="hidden" id="correo_destino" name="correo">

      <div class="space-y-1.5">
        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest pl-1">Destinatario</label>
        <input type="text" id="correo_para" readonly class="w-full border border-slate-200 bg-slate-100 px-4 py-3.5 rounded-2xl text-slate-500 font-medium outline-none cursor-not-allowed text-sm">
      </div>

      <div class="space-y-1.5">
        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest pl-1">Asunto</label>
        <input type="text" id="correo_asunto" name="asunto" placeholder="Asunto del correo..." required
               class="w-full border border-slate-200 bg-slate-50 px-4 py-3.5 rounded-2xl focus:ring-0 focus:border-[#54A6D8] focus:bg-white text-slate-800 font-medium transition-colors placeholder-slate-300 outline-none text-sm">
      </div>

      <div class="space-y-1.5">
        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest pl-1">Mensaje</label>
        <textarea id="correo_mensaje" name="mensaje" rows="4" required
                  placeholder="Escribe el mensaje principal..."
                  class="w-full border border-slate-200 bg-slate-50 px-4 py-3.5 rounded-2xl focus:ring-0 focus:border-[#54A6D8] focus:bg-white text-slate-800 font-medium transition-colors placeholder-slate-300 outline-none resize-none text-sm"></textarea>
      </div>

      <div class="space-y-1.5">
        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest pl-1">Firma</label>
        <textarea id="correo_firma" name="firma" rows="2"
                  placeholder="Ej: — Equipo Nubira.cl"
                  class="w-full border border-slate-200 bg-slate-50 px-4 py-3.5 rounded-2xl focus:ring-0 focus:border-[#54A6D8] focus:bg-white text-slate-800 font-medium transition-colors placeholder-slate-300 outline-none resize-none text-sm"></textarea>
      </div>

      <div class="pt-4 mt-2">
          <button type="submit" id="btn-submit-correo" class="w-full bg-[#54A6D8] active:bg-blue-600 text-white font-bold py-3.5 rounded-2xl transition-colors text-sm shadow-none border border-transparent flex items-center justify-center gap-2">
            <i class="fa-solid fa-paper-plane"></i> Enviar Correo
          </button>
      </div>
    </form>
  </div>
</div>

<div id="modalDetalleCorreo" class="hidden fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[70] flex items-center justify-center p-4 transition-opacity">
  <div class="bg-white rounded-3xl w-full max-w-md relative p-6 md:p-8">
    <button onclick="cerrarDetalleCorreo()" class="absolute top-5 right-5 text-slate-400 active:text-slate-600 w-8 h-8 bg-slate-50 rounded-full flex items-center justify-center transition-colors">
        <i class="fa-solid fa-xmark text-sm"></i>
    </button>
    <h2 class="text-lg font-bold text-slate-900 mb-5 flex items-center gap-2">
        <i class="fa-solid fa-clock-rotate-left text-[#54A6D8]"></i> Detalle de Envío
    </h2>

    <div class="space-y-4">
        <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Asunto</p>
            <p id="detalleAsunto" class="font-bold text-slate-800 text-sm"></p>
        </div>
        
        <div class="flex gap-4">
            <div class="flex-1 bg-slate-50 p-4 rounded-2xl border border-slate-100">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Fecha</p>
                <p id="detalleFecha" class="font-bold text-slate-800 text-sm"></p>
            </div>
            <div class="flex-1 bg-slate-50 p-4 rounded-2xl border border-slate-100">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Estado</p>
                <p id="detalleEstado" class="font-bold text-sm"></p>
            </div>
        </div>

        <div class="bg-white border border-slate-200 p-4 rounded-2xl max-h-60 overflow-y-auto">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Cuerpo del Mensaje</p>
            <div id="detalleMensaje" class="text-sm text-slate-600 font-medium leading-relaxed"></div>
        </div>
    </div>
  </div>
</div>

<div id="toast" class="fixed bottom-6 md:top-24 right-1/2 translate-x-1/2 md:translate-x-0 md:right-5 px-5 py-3 rounded-xl hidden text-white text-sm font-bold z-[90] flex items-center gap-3 animate-bounce"></div>

<?php 
// INYECCIÓN MODULAR OFICIAL DE NUBIRA 2.0
require_once $app_dir . '/componentes/nav_bottom.php'; 
require_once $app_dir . '/componentes/modal_publicar.php'; 
require_once $app_dir . '/componentes/modal_explora.php'; 
?>

<script>
window.onload = () => { const l = document.getElementById('loader'); if(l) { l.style.opacity='0'; setTimeout(()=>l.style.display='none',300); } };

function mostrarToast(msg, tipo='ok') {
  const toast = document.getElementById('toast');
  toast.innerHTML = (tipo==='ok' ? '<i class="fa-solid fa-circle-check text-emerald-400"></i> ' : '<i class="fa-solid fa-circle-exclamation text-red-400"></i> ') + msg;
  toast.className = 'fixed bottom-6 md:top-24 right-1/2 translate-x-1/2 md:translate-x-0 md:right-5 px-5 py-3 rounded-xl text-white z-[90] flex items-center gap-3 animate-bounce ' + (tipo==='ok' ? 'bg-slate-800' : 'bg-slate-800');
  toast.classList.remove('hidden');
  setTimeout(() => { toast.classList.add('hidden'); }, 3000);
}

/* ─────────────────────────────
   MODAL: ABRIR / CERRAR (CORREO)
────────────────────────────── */
function abrirModalCorreo(correo, nombre) {
  const modal = document.getElementById('modalCorreo');
  modal.style.display = 'flex';
  modal.classList.remove('hidden');

  document.getElementById('correo_para').value = nombre + ' <' + correo + '>';
  document.getElementById('correo_destino').value = correo;

  const asuntoDefault = "Actualiza la imagen de tu servicio";
  const mensajeDefault = `Hola ${nombre},\n\nTu servicio ya está publicado, pero sigue usando la imagen genérica por defecto. Eso hace que tu aviso se vea menos atractivo y recibe menos visitas.\n\nTe recomiendo cambiarla por una foto propia (tu espacio de estudio, pizarra, cuaderno, o algo que represente tu servicio). Una buena imagen aumenta la confianza y mejora las probabilidades de que te contacten.\n\nPara cambiar la imagen, entra a tu perfil y abre “Mis Servicios”.\n`;
  const firmaDefault = `Atentamente,\nEquipo Nubira.cl  \n🌐 https://nubira.cl  \n📸 Instagram: @nubira.cl  \n📘 Facebook: Nubira.cl`;

  document.getElementById('correo_asunto').value  = asuntoDefault;
  document.getElementById('correo_mensaje').value = mensajeDefault;
  document.getElementById('correo_firma').value   = firmaDefault;
}

function cerrarModalCorreo() {
  const modal = document.getElementById('modalCorreo');
  modal.classList.add('hidden');
  modal.style.display = 'none';
}

/* ENVÍO AJAX CORREO */
document.getElementById('formCorreo').addEventListener('submit', async e => {
  e.preventDefault();
  const btn = document.getElementById('btn-submit-correo');
  const txtOriginal = btn.innerHTML;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';
  btn.disabled = true;

  const data = new FormData(e.target);

  try {
      const res = await fetch('/app/enviar_correo_autor.php', { method: 'POST', body: data });
      const json = await res.json();

      if (json.status === 'ok') {
        mostrarToast("Correo enviado con éxito");
        setTimeout(() => { cerrarModalCorreo(); location.reload(); }, 1000);
      } else {
        mostrarToast("Error al enviar el correo", "error");
      }
  } catch(error) {
      mostrarToast("Error de conexión", "error");
  } finally {
      btn.innerHTML = txtOriginal;
      btn.disabled = false;
  }
});

/* ─────────────────────────────
   MODAL: DETALLE HISTORIAL
────────────────────────────── */
function verDetalleCorreo(asunto, mensaje, estado, fecha) {
    const modal = document.getElementById("modalDetalleCorreo");

    document.getElementById("detalleAsunto").innerText = asunto || "—";
    
    const estadoEl = document.getElementById("detalleEstado");
    if(estado.includes('Exitoso')) {
        estadoEl.className = "font-bold text-sm text-emerald-600";
        estadoEl.innerHTML = '<i class="fa-solid fa-check"></i> ' + estado;
    } else {
        estadoEl.className = "font-bold text-sm text-red-500";
        estadoEl.innerHTML = '<i class="fa-solid fa-xmark"></i> ' + estado;
    }
    
    document.getElementById("detalleFecha").innerText = fecha || "—";

    const mensajeHTML = mensaje ? mensaje.replace(/\n/g, "<br>") : "—";
    document.getElementById("detalleMensaje").innerHTML = mensajeHTML;

    modal.style.display = "flex";
    modal.classList.remove("hidden");
}

function cerrarDetalleCorreo() {
    const modal = document.getElementById("modalDetalleCorreo");
    modal.classList.add("hidden");
    modal.style.display = "none";
}

function abrirDetalleDesdeData(btn) {
  const asunto  = btn.dataset.asunto || "—";
  const mensaje = btn.dataset.mensaje || "—";
  const estado  = btn.dataset.estado || "—";
  const fecha   = btn.dataset.fecha || "—";

  verDetalleCorreo(asunto, mensaje, estado, fecha);
}

// --- LÓGICA DE MODALES NAV NUBIRA 2.0 ---
document.addEventListener('DOMContentLoaded', () => {
    function setupModal(triggerId, modalId, cardId, closeId) {
        const btn = document.getElementById(triggerId);
        const modal = document.getElementById(modalId);
        const card = document.getElementById(cardId);
        const close = document.getElementById(closeId);

        if(!btn || !modal) return;

        const open = () => { 
            modal.classList.remove('hidden'); 
            requestAnimationFrame(() => { card.classList.remove('translate-y-full', 'opacity-0'); });
            document.body.style.overflow = 'hidden'; 
        };

        const shut = () => { 
            card.classList.add('translate-y-full', 'opacity-0'); 
            setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); 
        };

        btn.onclick = (e) => { e.preventDefault(); open(); }; 
        if(close) close.onclick = shut; 
        modal.onclick = (e) => { if(e.target === modal) shut(); };
    }

    setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
    setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
});
</script>

</body>
</html>