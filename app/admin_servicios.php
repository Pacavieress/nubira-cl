<?php
/**
 * VISTA: ADMIN SERVICIOS (OPTIMIZADO + EDICIÓN NUBIRA 2.0)
 * ESTADO: VELOCIDAD MEJORADA + UI NATIVA (FLAT DESIGN)
 */
session_start();

// 1. SEGURIDAD Y RUTAS
$app_dir = dirname(__DIR__) . '/app'; 
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__ . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__;

require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';
require_once $app_dir . '/helpers/imagen_servicio.php'; // [BANCO] resolver unificado

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /vitrina'); exit;
}

// -------------------------------------------------------------------------
// LÓGICA DE GUARDADO DE IMAGEN EDITADA (CENSURA)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_imagen_editada') {
    header('Content-Type: application/json');
    try {
        $id_servicio = (int)$_POST['id_servicio'];
        $img_data = $_POST['imagen_base64'];

        $stmt_img = $conn->prepare("SELECT imagen FROM servicios WHERE id = ?");
        $stmt_img->bind_param("i", $id_servicio);
        $stmt_img->execute();
        $servicio = $stmt_img->get_result()->fetch_assoc();
        $stmt_img->close();

        if (!$servicio || empty($servicio['imagen'])) throw new Exception("No se encontró la imagen original.");

        // Extraer la data en base64 real
        $image_parts = explode(";base64,", $img_data);
        if (count($image_parts) < 2) throw new Exception("Formato de imagen inválido.");
        $image_base64 = base64_decode($image_parts[1]);
        
        $ruta_destino = $_SERVER['DOCUMENT_ROOT'] . '/upload/servicios/' . $servicio['imagen'];
        
        if (file_put_contents($ruta_destino, $image_base64)) {
            clearstatcache(true, $ruta_destino); 
            echo json_encode(['status' => 'ok', 'msg' => 'Imagen censurada correctamente.']);
        } else {
            throw new Exception("Error al escribir el archivo en el servidor.");
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// 2. LÓGICA DE BÚSQUEDA
$busqueda = trim($_GET['q'] ?? '');
$where = '';
$params = [];
$types  = '';

// LIMIT 100 PARA NO SATURAR MEMORIA
$limit_clause = "LIMIT 100"; 

if ($busqueda !== '') {
    $where = "WHERE s.titulo LIKE ? OR s.nombre_oferente LIKE ?";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $types = "ss";
}

$sql = "SELECT s.id, s.titulo, s.nombre_oferente, s.categoria, s.imagen, s.imagen_banco_id, s.estado, s.fecha_publicacion, s.alumno_id, s.motivo_rechazo, s.visible,
               a.nombre AS nombre_alumno, bi.archivo AS banco_archivo
        FROM servicios s
        LEFT JOIN alumnos a ON s.alumno_id = a.id
        LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
        $where
        ORDER BY s.id DESC $limit_clause";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$servicios = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$page_title = "Gestión de Servicios";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Admin Servicios | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
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
    
    .img-zoomable { position: relative; cursor: zoom-in; }
    .img-zoomable::after {
        content: "\f00e"; font-family: "Font Awesome 6 Free"; font-weight: 900;
        position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
        font-size: 12px; background: rgba(15, 23, 42, 0.7); color: white; border-radius: 50%; width: 26px; height: 26px;
        display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s ease;
    }
    .img-zoomable:hover::after { opacity: 1; }
    /* Prevenir scroll del canvas en móviles */
    #editor-canvas { touch-action: none; }
  </style>
</head>

<body class="text-slate-800 antialiased overflow-x-hidden force-no-shadow bg-white">

<div id="loader" class="fixed inset-0 bg-white flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-8 w-8 border-4 border-slate-100 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
require_once $app_dir . '/componentes/header.php'; 
require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-16 pb-32 md:pb-16 lg:ml-64 px-4 md:px-6 w-full md:w-[calc(100%-16rem)]">
  <div class="max-w-7xl mx-auto space-y-6">

    <div class="sticky top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-slate-100 py-4 flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-6">
            <div>
                <h1 class="text-xl md:text-2xl font-extrabold text-slate-900 tracking-tight">Servicios</h1>
                <p class="text-slate-400 text-xs font-medium mt-0.5">Auditoría y moderación (Últimos 100).</p>
            </div>
        </div>
        
        <form class="flex flex-col md:flex-row gap-2 w-full md:w-auto" method="GET">
            <div class="relative w-full md:w-64">
                <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar título u oferente..." 
                       class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-4 pr-10 py-2.5 text-sm focus:ring-0 focus:border-[#54A6D8] focus:bg-white transition-colors outline-none font-medium placeholder-slate-400">
                <button type="submit" class="absolute right-3 top-2.5 text-slate-400 active:text-[#54A6D8]">
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
               <th class="px-6 py-4 font-bold text-center w-16">ID</th>
               <th class="px-6 py-4 font-bold w-16 text-center">Imagen</th>
               <th class="px-6 py-4 font-bold">Título del Servicio</th>
               <th class="px-6 py-4 font-bold">Oferente</th>
               <th class="px-6 py-4 font-bold">Categoría</th>
               <th class="px-6 py-4 font-bold text-center w-28">Estado</th>
               <th class="px-6 py-4 font-bold text-center w-24">Visibilidad</th>
               <th class="px-6 py-4 font-bold text-right w-40">Acciones</th>
             </tr>
           </thead>
           <tbody class="divide-y divide-slate-50">
          <?php foreach ($servicios as $row): 
     $esVisible = !isset($row['visible']) || $row['visible'] == 1; 
?>
  <tr id="fila-<?= $row['id'] ?>" class="hover:bg-slate-50 transition-all align-middle group <?= !$esVisible ? 'opacity-60 bg-slate-50' : '' ?>">
               
               <td class="px-6 py-4 text-center text-slate-400 font-mono text-xs">#<?= $row['id'] ?></td>
               
               <td class="px-6 py-4 text-center">
                  <?php
                     $rutaDisplay = url_portada($row); // [BANCO] banco → legacy → placeholder
                  ?>
                  <div class='relative img-zoomable inline-block group/img bg-slate-100 rounded-xl w-14 h-10 animate-pulse-once' data-id="<?= $row['id'] ?>" data-src="<?= htmlspecialchars($rutaDisplay) ?>">
                    <img src='<?= htmlspecialchars($rutaDisplay) ?>' 
                         alt='Portada' 
                         loading="lazy"
                         decoding="async"
                         class='w-14 h-10 object-cover rounded-xl border border-slate-200 transition-opacity group-hover/img:opacity-80'
                         onload="this.parentElement.classList.remove('animate-pulse-once', 'bg-slate-100')"
                         onerror="this.onerror=null;this.src='<?= $rutaPorDefecto ?>';">
                  </div>
               </td>

               <td class="px-6 py-4">
                   <p class="font-bold text-slate-900 text-sm truncate max-w-[250px]" title="<?= htmlspecialchars($row['titulo']) ?>">
                       <?= htmlspecialchars($row['titulo']) ?>
                   </p>
                   <p class="text-[10px] text-slate-400 font-medium mt-0.5"><?= date('d/m/Y', strtotime($row['fecha_publicacion'])) ?></p>
               </td>
               
               <td class="px-6 py-4 text-xs text-slate-600 font-medium truncate max-w-[150px]">
                   <?= htmlspecialchars($row['nombre_oferente'] ?? $row['nombre_alumno']) ?>
               </td>
               
               <td class="px-6 py-4">
                   <span class="bg-slate-100 text-slate-500 px-2 py-1 rounded-md text-[9px] font-black uppercase tracking-widest">
                       <?= htmlspecialchars($row['categoria']) ?>
                   </span>
               </td>

               <td class="px-6 py-4 text-center estado-cell">
                 <?php
                   $est = $row['estado'];
                   if ($est === 'pendiente') echo '<span class="bg-amber-50 text-amber-600 px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest">Pendiente</span>';
                   elseif ($est === 'aprobado') echo '<span class="bg-emerald-50 text-emerald-600 px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest">Aprobado</span>';
                   elseif ($est === 'rechazado') {
                       echo '<span class="bg-red-50 text-red-600 px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest">Rechazado</span>';
                       if (!empty($row['motivo_rechazo'])) echo '<div class="text-[10px] text-red-500 mt-1.5 truncate max-w-[100px] font-medium" title="'.htmlspecialchars($row['motivo_rechazo']).'">Ver motivo</div>';
                   }
                 ?>
               </td>
<td class="px-6 py-4 text-center visibilidad-cell">
                 <?php if ($esVisible): ?>
                     <span class="bg-indigo-50 text-indigo-500 px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest">Visible</span>
                 <?php else: ?>
                     <span class="bg-slate-200 text-slate-500 px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest">Oculto</span>
                 <?php endif; ?>
               </td>

                     
               <td class="px-6 py-4 text-right acciones-cell">
                 <div class="flex items-center justify-end gap-1.5 opacity-100 md:opacity-60 md:group-hover:opacity-100 transition-opacity">
                     
                     <?php if ($row['estado'] === 'pendiente'): ?>
                       <button onclick="aprobarServicio(<?= $row['id'] ?>)" class="bg-emerald-50 active:bg-emerald-100 text-emerald-600 p-2 rounded-xl transition-colors text-xs" title="Aprobar">
                           <i class="fa-solid fa-check"></i>
                       </button>
                       <button onclick="abrirModalRechazo(<?= $row['id'] ?>)" class="bg-amber-50 active:bg-amber-100 text-amber-600 p-2 rounded-xl transition-colors text-xs" title="Rechazar">
                           <i class="fa-solid fa-xmark"></i>
                       </button>
                     <?php endif; ?>

                     <a href="/detalle-servicio/<?= $row['id'] ?>" target="_blank" class="bg-blue-50 active:bg-blue-100 text-[#54A6D8] p-2 rounded-xl transition-colors text-xs" title="Ver Detalle">
                         <i class="fa-solid fa-arrow-up-right-from-square"></i>
                     </a>
<form method="POST" action="/app/admin_servicios_accion.php" class="inline form-toggle-visibilidad">
   <input type="hidden" name="id_servicio" value="<?= $row['id'] ?>">
   <input type="hidden" name="accion" value="toggle_visibilidad">
   <input type="hidden" name="estado_actual" value="<?= $esVisible ? 1 : 0 ?>">
   <button type="submit" class="<?= $esVisible ? 'bg-slate-100 text-slate-500' : 'bg-indigo-50 text-indigo-500' ?> active:bg-slate-200 p-2 rounded-xl transition-colors text-xs btn-toggle" title="<?= $esVisible ? 'Ocultar Servicio' : 'Mostrar Servicio' ?>">
       <i class="fa-solid <?= $esVisible ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
   </button>
</form>
                     <form method="POST" action="/app/admin_servicios_accion.php" class="inline form-eliminar">
                        <input type="hidden" name="id_servicio" value="<?= $row['id'] ?>">
                        <input type="hidden" name="accion" value="eliminar">
                        <button type="submit" class="bg-red-50 active:bg-red-100 text-red-500 p-2 rounded-xl transition-colors text-xs" title="Eliminar">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                     </form>
                     
                 </div>
               </td>
             </tr>
           <?php endforeach; ?>

           <?php if (empty($servicios)): ?>
             <tr><td colspan="7" class="text-center py-16 text-slate-400">
                <i class="fa-solid fa-file-circle-xmark text-4xl mb-3 text-slate-200"></i><br>
                <span class="font-medium text-sm">No se encontraron servicios recientes.</span>
             </td></tr>
           <?php endif; ?>
           </tbody>
         </table>
       </div>
    </div>

  </div>
</main>

<div id="modal-rechazo" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[70] hidden flex items-center justify-center transition-opacity p-4">
  <form id="form-rechazo" method="POST" class="bg-white p-6 md:p-8 rounded-3xl w-full max-w-sm relative">
    <button type="button" onclick="cerrarModalRechazo()" class="absolute top-5 right-5 text-slate-400 active:text-slate-600 w-8 h-8 bg-slate-50 rounded-full flex items-center justify-center transition-colors">
        <i class="fa-solid fa-xmark text-sm"></i>
    </button>
    <h3 class="text-lg font-bold text-slate-900 mb-6 flex items-center gap-2">
        <i class="fa-solid fa-circle-xmark text-red-500"></i> Rechazar Servicio
    </h3>
    <input type="hidden" id="modal_id_servicio">
    
    <div class="space-y-1.5">
        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest pl-1">Motivo del rechazo</label>
        <textarea id="modal_motivo_rechazo" required class="w-full border border-slate-200 bg-slate-50 px-4 py-3.5 rounded-2xl text-sm focus:ring-0 focus:border-red-400 focus:bg-white outline-none font-medium text-slate-800 transition-colors resize-none" rows="3" placeholder="Explica detalladamente por qué..."></textarea>
    </div>
    
    <div class="flex justify-end gap-3 mt-8 pt-6 border-t border-slate-100">
      <button type="button" onclick="cerrarModalRechazo()" class="px-5 py-3 rounded-2xl text-xs font-bold text-slate-500 active:bg-slate-50 transition-colors">Cancelar</button>
      <button type="submit" class="bg-red-500 active:bg-red-600 text-white px-6 py-3 rounded-2xl text-xs font-bold transition-colors border border-transparent">Confirmar Rechazo</button>
    </div>
  </form>
</div>

<div id="zoom-modal" class="fixed inset-0 bg-slate-900/95 backdrop-blur-sm z-[80] hidden flex flex-col items-center justify-center">
  <div id="editor-toolbar" class="absolute top-0 w-full flex justify-between items-center px-4 md:px-6 py-4 bg-slate-900/50 border-b border-slate-700/50 z-[81]">
      <div class="text-white">
          <span id="zoom-info" class="font-bold text-sm tracking-wide text-slate-200"></span>
      </div>
      <div class="flex gap-2 md:gap-3 items-center">
          <button id="btn-activar-edicion" onclick="activarEdicion()" class="bg-slate-800 active:bg-slate-700 text-white text-xs font-bold px-5 py-2.5 rounded-xl flex items-center gap-2 transition-colors border border-slate-700">
              <i class="fa-solid fa-pen-to-square"></i> <span class="hidden sm:inline">Censurar Contenido</span>
          </button>
          
          <div id="tools-panel" class="hidden flex items-center gap-2">
              <span class="text-slate-400 text-xs hidden md:inline-block mr-2 font-medium"><i class="fa-solid fa-hand-pointer mr-1"></i> Dibuja un recuadro para desenfocar</span>
              <button id="btn-guardar-edicion" onclick="guardarEdicion()" class="bg-[#54A6D8] active:bg-blue-600 text-white text-xs font-bold px-5 py-2.5 rounded-xl transition-colors">
                 Guardar Cambios
              </button>
              <button onclick="cancelarEdicion()" class="bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-bold px-5 py-2.5 rounded-xl transition-colors border border-slate-700">
                 Cancelar
              </button>
          </div>
          
          <div class="h-6 w-px bg-slate-700 mx-1 md:mx-2"></div>
          
          <button onclick="cerrarZoom()" class="text-slate-400 hover:text-white active:bg-slate-800 w-10 h-10 rounded-full flex items-center justify-center transition-colors">
             <i class="fa-solid fa-xmark text-lg"></i>
          </button>
      </div>
  </div>
  
  <div class="relative w-full h-full flex items-center justify-center p-4 pt-20">
      <img id="zoom-img" src="" class="max-w-full max-h-[85vh] object-contain rounded-2xl border border-slate-700/50">
      <canvas id="editor-canvas" class="hidden max-w-full max-h-[85vh] rounded-2xl cursor-crosshair border border-slate-700/50"></canvas>
  </div>
</div>

<div id="toast" class="fixed bottom-6 md:top-24 right-1/2 translate-x-1/2 md:translate-x-0 md:right-5 px-5 py-3 rounded-xl hidden text-white text-sm font-bold z-[90] flex items-center gap-3 animate-bounce"></div>

<?php 
require_once $app_dir . '/componentes/nav_bottom.php'; 
require_once $app_dir . '/componentes/modal_publicar.php';
require_once $app_dir . '/componentes/modal_explora.php';
?>

<script>
window.onload = () => { const l = document.getElementById('loader'); if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } };

function mostrarToast(msg, tipo='ok') {
  const toast = document.getElementById('toast');
  toast.innerHTML = (tipo==='ok' ? '<i class="fa-solid fa-circle-check text-emerald-400"></i> ' : '<i class="fa-solid fa-circle-exclamation text-red-400"></i> ') + msg;
  toast.className = 'fixed bottom-6 md:top-24 right-1/2 translate-x-1/2 md:translate-x-0 md:right-5 px-5 py-3 rounded-xl text-white z-[90] flex items-center gap-3 animate-bounce ' + (tipo==='ok' ? 'bg-slate-800' : 'bg-slate-800');
  toast.classList.remove('hidden');
  setTimeout(() => { toast.classList.add('hidden'); }, 3000);
}

// LOGICA MODALES DEL NAV
function setupModal(triggerId, modalId, cardId, closeId) {
    const btn=document.getElementById(triggerId), modal=document.getElementById(modalId), card=document.getElementById(cardId), close=document.getElementById(closeId);
    if(!btn||!modal) return;
    const open=()=>{modal.classList.remove('hidden'); requestAnimationFrame(()=>card.classList.remove('translate-y-full','opacity-0')); document.body.style.overflow='hidden';};
    const shut=()=>{card.classList.add('translate-y-full','opacity-0'); setTimeout(()=>{modal.classList.add('hidden');document.body.style.overflow='';},300);};
    btn.onclick=(e)=>{e.preventDefault();open();}; 
    if(close) close.onclick=shut; 
    modal.onclick=(e)=>{if(e.target===modal)shut();};
}
setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');

// LÓGICA DE GESTIÓN (Aprobar/Rechazar/Eliminar)
async function aprobarServicio(id) {
  if(!confirm('¿Aprobar este servicio?')) return;
  try {
      const res = await fetch('/app/admin_servicios_accion.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({accion:'aprobar', id_servicio:id})
      });
      if (res.ok) {
        const fila = document.getElementById('fila-'+id);
        if (fila) {
          fila.querySelector('.estado-cell').innerHTML = '<span class="bg-emerald-50 text-emerald-600 px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest">Aprobado</span>';
          fila.querySelector('.acciones-cell').innerHTML = `
              <div class="flex items-center justify-end gap-1.5 opacity-100 md:opacity-60 md:group-hover:opacity-100 transition-opacity">
                 <a href="/detalle-servicio/${id}" target="_blank" class="bg-blue-50 active:bg-blue-100 text-[#54A6D8] p-2 rounded-xl transition-colors text-xs" title="Ver Detalle">
                     <i class="fa-solid fa-arrow-up-right-from-square"></i>
                 </a>
                 <form method="POST" action="/app/admin_servicios_accion.php" class="inline form-eliminar">
                    <input type="hidden" name="id_servicio" value="${id}">
                    <input type="hidden" name="accion" value="eliminar">
                    <button type="submit" class="bg-red-50 active:bg-red-100 text-red-500 p-2 rounded-xl transition-colors text-xs" title="Eliminar">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                 </form>
              </div>`;
        }
        mostrarToast('Servicio aprobado');
      } else throw new Error();
  } catch(e) { mostrarToast('Error al aprobar', 'error'); }
}

function abrirModalRechazo(id){
  document.getElementById('modal_id_servicio').value = id;
  document.getElementById('modal_motivo_rechazo').value = '';
  document.getElementById('modal-rechazo').classList.remove('hidden');
}
function cerrarModalRechazo(){ document.getElementById('modal-rechazo').classList.add('hidden'); }

document.getElementById('form-rechazo').addEventListener('submit', async e=>{
  e.preventDefault();
  const id = document.getElementById('modal_id_servicio').value;
  const motivo = document.getElementById('modal_motivo_rechazo').value;
  try {
      const res = await fetch('/app/admin_servicios_accion.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({accion:'rechazar', id_servicio:id, motivo_rechazo:motivo})
      });
      if (res.ok) {
        cerrarModalRechazo();
        const fila = document.getElementById('fila-'+id);
        if (fila) {
          fila.querySelector('.estado-cell').innerHTML = '<span class="bg-red-50 text-red-600 px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest">Rechazado</span>';
          fila.querySelector('.acciones-cell').innerHTML = `
              <div class="flex items-center justify-end gap-1.5 opacity-100 md:opacity-60 md:group-hover:opacity-100 transition-opacity">
                 <a href="/detalle-servicio/${id}" target="_blank" class="bg-blue-50 active:bg-blue-100 text-[#54A6D8] p-2 rounded-xl transition-colors text-xs" title="Ver Detalle">
                     <i class="fa-solid fa-arrow-up-right-from-square"></i>
                 </a>
                 <form method="POST" action="/app/admin_servicios_accion.php" class="inline form-eliminar">
                    <input type="hidden" name="id_servicio" value="${id}">
                    <input type="hidden" name="accion" value="eliminar">
                    <button type="submit" class="bg-red-50 active:bg-red-100 text-red-500 p-2 rounded-xl transition-colors text-xs" title="Eliminar">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                 </form>
              </div>`;
        }
        mostrarToast('Servicio rechazado');
      } else throw new Error();
  } catch(e) { mostrarToast('Error al rechazar', 'error'); }
});

// DELEGACIÓN DE EVENTOS PARA ELIMINAR Y OCULTAR/MOSTRAR
document.addEventListener('submit', async e => {
  
  // LÓGICA PARA ELIMINAR
  if (e.target.closest('.form-eliminar')) {
    e.preventDefault();
    const form = e.target.closest('.form-eliminar');
    if (!confirm('¿Eliminar definitivamente?')) return;
    
    const id = form.querySelector('[name="id_servicio"]').value;
    try {
        const res = await fetch('/app/admin_servicios_accion.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: new URLSearchParams({accion:'eliminar', id_servicio:id})
        });
        if (res.ok) {
          document.getElementById('fila-'+id)?.remove();
          mostrarToast('Servicio eliminado');
        } else throw new Error();
    } catch(err) { mostrarToast('Error al eliminar', 'error'); }
  }

  // LÓGICA PARA OCULTAR/MOSTRAR (TOGGLE VISIBILIDAD)
  if (e.target.closest('.form-toggle-visibilidad')) {
    e.preventDefault();
    const form = e.target.closest('.form-toggle-visibilidad');
    const id = form.querySelector('[name="id_servicio"]').value;
    const estadoActual = form.querySelector('[name="estado_actual"]').value;
    const nuevoEstado = estadoActual === '1' ? '0' : '1';

    try {
        const res = await fetch('/app/admin_servicios_accion.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: new URLSearchParams({accion:'toggle_visibilidad', id_servicio:id, visible: nuevoEstado})
        });
        
        if (res.ok) {
          form.querySelector('[name="estado_actual"]').value = nuevoEstado;
          const btn = form.querySelector('.btn-toggle');
          const icon = btn.querySelector('i');
          const fila = document.getElementById('fila-'+id);
          const cellVisibilidad = fila.querySelector('.visibilidad-cell'); // Seleccionamos la celda de visibilidad

          if (nuevoEstado === '1') {
              btn.className = 'bg-slate-100 text-slate-500 active:bg-slate-200 p-2 rounded-xl transition-colors text-xs btn-toggle';
              btn.title = 'Ocultar Servicio';
              icon.className = 'fa-solid fa-eye-slash';
              fila.classList.remove('opacity-60', 'bg-slate-50');
              // Actualizamos el badge a Visible
              cellVisibilidad.innerHTML = '<span class="bg-indigo-50 text-indigo-500 px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest">Visible</span>';
          } else {
              btn.className = 'bg-indigo-50 text-indigo-500 active:bg-indigo-100 p-2 rounded-xl transition-colors text-xs btn-toggle';
              btn.title = 'Mostrar Servicio';
              icon.className = 'fa-solid fa-eye';
              fila.classList.add('opacity-60', 'bg-slate-50');
              // Actualizamos el badge a Oculto
              cellVisibilidad.innerHTML = '<span class="bg-slate-200 text-slate-500 px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest">Oculto</span>';
          }
          mostrarToast(nuevoEstado === '1' ? 'Servicio visible' : 'Servicio ocultado');
        } else throw new Error();
    } catch(err) { mostrarToast('Error al cambiar visibilidad', 'error'); }
  }
});


// LÓGICA DE ZOOM Y EDICIÓN (Mejorada con Soporte Touch Mobile)
let zoomImg = document.getElementById('zoom-img');
let zoomModal = document.getElementById('zoom-modal');
let editorCanvas = document.getElementById('editor-canvas');
let btnActivar = document.getElementById('btn-activar-edicion');
let toolsPanel = document.getElementById('tools-panel');

let isEditing = false;
let currentServiceId = null;
let ctx = null;
let isDrawing = false;
let startX, startY;
let snapshot;

document.addEventListener('click', e => {
  const wrapper = e.target.closest('.img-zoomable');
  if (wrapper) {
    const src = wrapper.dataset.src;
    currentServiceId = wrapper.dataset.id;
    const fila = wrapper.closest('tr');
    const titulo = fila.querySelector('td:nth-child(3) p')?.innerText || ''; // Ajustado índice por nueva columna
    
    zoomImg.src = src;
    zoomImg.classList.remove('hidden');
    editorCanvas.classList.add('hidden');
    btnActivar.classList.remove('hidden');
    toolsPanel.classList.add('hidden');
    isEditing = false;

    document.getElementById('zoom-info').textContent = `#${currentServiceId} - ${titulo}`;
    document.body.style.overflow = 'hidden'; 
    zoomModal.classList.remove('hidden');
  }
});

function cerrarZoom() {
    zoomModal.classList.add('hidden');
    document.body.style.overflow = '';
    isEditing = false;
}

function activarEdicion() {
    isEditing = true;
    zoomImg.classList.add('hidden');
    editorCanvas.classList.remove('hidden');
    btnActivar.classList.add('hidden');
    toolsPanel.classList.remove('hidden');
    toolsPanel.classList.add('flex');

    ctx = editorCanvas.getContext('2d');
    editorCanvas.width = zoomImg.naturalWidth;
    editorCanvas.height = zoomImg.naturalHeight;
    ctx.drawImage(zoomImg, 0, 0);
    snapshot = ctx.getImageData(0, 0, editorCanvas.width, editorCanvas.height);
}

function cancelarEdicion() {
    isEditing = false;
    zoomImg.classList.remove('hidden');
    editorCanvas.classList.add('hidden');
    btnActivar.classList.remove('hidden');
    toolsPanel.classList.add('hidden');
    toolsPanel.classList.remove('flex');
}

function getPointerPos(e) {
    const rect = editorCanvas.getBoundingClientRect();
    const scaleX = editorCanvas.width / rect.width;
    const scaleY = editorCanvas.height / rect.height;
    const clientX = e.touches ? e.touches[0].clientX : e.clientX;
    const clientY = e.touches ? e.touches[0].clientY : e.clientY;
    return {
        x: (clientX - rect.left) * scaleX,
        y: (clientY - rect.top) * scaleY
    };
}

function startDrawing(e) {
    if(!isEditing) return;
    const pos = getPointerPos(e);
    startX = pos.x;
    startY = pos.y;
    isDrawing = true;
    snapshot = ctx.getImageData(0,0, editorCanvas.width, editorCanvas.height);
}

function draw(e) {
    if(!isDrawing || !isEditing) return;
    e.preventDefault(); 
    const pos = getPointerPos(e);

    ctx.putImageData(snapshot, 0, 0);
    
    ctx.beginPath();
    ctx.rect(startX, startY, pos.x - startX, pos.y - startY);
    ctx.strokeStyle = '#54A6D8';
    ctx.lineWidth = 4;
    ctx.stroke();
    ctx.fillStyle = 'rgba(84, 166, 216, 0.2)';
    ctx.fill();
}

function stopDrawing(e) {
    if(!isDrawing || !isEditing) return;
    isDrawing = false;
    
    const clientX = e.changedTouches ? e.changedTouches[0].clientX : e.clientX;
    const clientY = e.changedTouches ? e.changedTouches[0].clientY : e.clientY;
    
    const rect = editorCanvas.getBoundingClientRect();
    const scaleX = editorCanvas.width / rect.width;
    const scaleY = editorCanvas.height / rect.height;
    const currentX = (clientX - rect.left) * scaleX;
    const currentY = (clientY - rect.top) * scaleY;
    
    const width = currentX - startX;
    const height = currentY - startY;

    if(Math.abs(width) < 5 || Math.abs(height) < 5) {
        ctx.putImageData(snapshot, 0, 0);
        return;
    }

    if(confirm('¿Aplicar desenfoque a esta área?')) {
        ctx.putImageData(snapshot, 0, 0);
        const tempCanvas = document.createElement('canvas');
        const tCtx = tempCanvas.getContext('2d');
        tempCanvas.width = Math.abs(width);
        tempCanvas.height = Math.abs(height);
        
        tCtx.drawImage(editorCanvas, 
            Math.min(startX, currentX), Math.min(startY, currentY), Math.abs(width), Math.abs(height),
            0, 0, Math.abs(width), Math.abs(height)
        );
        
        ctx.save();
        ctx.filter = 'blur(15px)';
        ctx.drawImage(tempCanvas, Math.min(startX, currentX), Math.min(startY, currentY));
        ctx.restore();
    } else {
        ctx.putImageData(snapshot, 0, 0);
    }
}

editorCanvas.addEventListener('mousedown', startDrawing);
editorCanvas.addEventListener('mousemove', draw);
editorCanvas.addEventListener('mouseup', stopDrawing);

editorCanvas.addEventListener('touchstart', startDrawing, {passive: false});
editorCanvas.addEventListener('touchmove', draw, {passive: false});
editorCanvas.addEventListener('touchend', stopDrawing);

async function guardarEdicion() {
    if(!confirm('¿Sobrescribir la imagen original con estos cambios? Esta acción no se puede deshacer.')) return;
    
    const btnGuardar = document.getElementById('btn-guardar-edicion');
    btnGuardar.innerText = 'Guardando...';
    btnGuardar.disabled = true;
    btnGuardar.classList.add('opacity-50', 'cursor-not-allowed');

    const dataUrl = editorCanvas.toDataURL('image/jpeg', 0.85);
    try {
        const formData = new URLSearchParams();
        formData.append('accion', 'guardar_imagen_editada');
        formData.append('id_servicio', currentServiceId);
        formData.append('imagen_base64', dataUrl);

        const res = await fetch(window.location.href, { 
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        
        if(data.status === 'ok') {
            mostrarToast('Imagen editada correctamente');
            const imgTabla = document.querySelector(`#fila-${currentServiceId} .img-zoomable img`);
            if(imgTabla) {
                const cleanSrc = imgTabla.src.split('?')[0];
                const newSrc = cleanSrc + '?t=' + new Date().getTime();
                imgTabla.src = newSrc;
                imgTabla.closest('.img-zoomable').dataset.src = newSrc;
            }
            cerrarZoom();
        } else {
            throw new Error(data.msg);
        }
    } catch(e) {
        alert('Error al guardar: ' + e.message);
    } finally {
        btnGuardar.innerText = 'Guardar Cambios';
        btnGuardar.disabled = false;
        btnGuardar.classList.remove('opacity-50', 'cursor-not-allowed');
    }
}
</script>

</body>
</html>