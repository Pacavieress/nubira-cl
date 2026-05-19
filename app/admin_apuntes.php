<?php
/**
 * VISTA: ADMIN APUNTES (OPTIMIZADO + EDICIÓN NUBIRA 2.0)
 * ESTADO: VELOCIDAD MEJORADA (LIMIT + AJAX + CANVAS BLUR CENSURA MULTIPAGINA)
 */
session_start();

// 1. SEGURIDAD Y RUTAS
$app_dir = dirname(__DIR__) . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__ . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__;

require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

// Validación Admin
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /vitrina'); exit;
}

// -------------------------------------------------------------------------
// LÓGICA DE GUARDADO DE IMAGEN EDITADA (CENSURA DE PREVIEWS)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_imagen_editada') {
    header('Content-Type: application/json');
    try {
        $id_apunte = (int)$_POST['id_apunte'];
        $img_data = $_POST['imagen_base64'];
        $ruta_relativa = $_POST['ruta_imagen']; 

        if (strpos($ruta_relativa, '/upload/') !== 0) throw new Exception("Ruta de archivo no permitida.");

        $image_parts = explode(";base64,", $img_data);
        if (count($image_parts) < 2) throw new Exception("Formato de imagen inválido.");
        $image_base64 = base64_decode($image_parts[1]);
        
        $ruta_destino = $_SERVER['DOCUMENT_ROOT'] . explode('?', $ruta_relativa)[0];
        
        if (file_put_contents($ruta_destino, $image_base64)) {
            clearstatcache(true, $ruta_destino); 
            echo json_encode(['status' => 'ok', 'msg' => 'Miniatura censurada correctamente.']);
        } else {
            throw new Exception("Error al escribir el archivo en el servidor.");
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// 2. LÓGICA DE BÚSQUEDA Y PAGINACIÓN
$busqueda = trim($_GET['q'] ?? '');
$where = '';
$params = [];
$types  = '';
$limit_clause = "LIMIT 100"; 

if ($busqueda !== '') {
    $where = "WHERE a.titulo LIKE ? OR u.nombre LIKE ? OR a.asignatura LIKE ?";
    $params = ["%$busqueda%", "%$busqueda%", "%$busqueda%"];
    $types = "sss";
}

$sql = "SELECT a.*, u.nombre AS autor 
        FROM apuntes a 
        JOIN alumnos u ON a.id_alumno = u.id 
        $where
        ORDER BY a.fecha_subida DESC $limit_clause";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$resultado = $stmt->get_result();
$apuntes_lista = $resultado->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$page_title = "Gestión de Apuntes";

if (!function_exists('nav_class')) {
    function nav_class(string $path): string {
        $ruta_actual = $_SERVER['REQUEST_URI'] ?? '/';
        $base = 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent';
        $activo = ' bg-blue-50 text-[#54A6D8] border-blue-100';
        $inactivo = ' text-gray-500 hover:bg-gray-50 hover:text-gray-900';
        if ($path === '/admin/apuntes') return $base . $activo; 
        return $base . $inactivo;
    }
}

function icon_eye_slash($class='w-4 h-4') {
    return '<svg class="'.$class.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>';
}

function obtener_ruta_miniatura($id, $portadaBD, $archivo) {
    $root = $_SERVER['DOCUMENT_ROOT'];
    
    // 1. Portada oficial
    if (!empty($portadaBD)) {
        $pathPort = "/upload/portadas/" . basename($portadaBD);
        if (file_exists($root . $pathPort)) return $pathPort;
    }
    
    // 2. Previews automáticos
    if (file_exists($root . "/upload/preview/" . $id . ".webp")) return "/upload/preview/" . $id . ".webp";
    if (file_exists($root . "/upload/preview/" . $id . ".jpg")) return "/upload/preview/" . $id . ".jpg";
    if (file_exists($root . "/upload/preview/" . $id . ".png")) return "/upload/preview/" . $id . ".png";
    
    // 3. Archivo original (si es una imagen)
    $ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
        if (file_exists($root . "/upload/apuntes/" . $archivo)) return "/upload/apuntes/" . $archivo;
    }
    
    return "/img/logo2.webp";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Admin Apuntes | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    .img-zoomable { position: relative; cursor: zoom-in; }
    .img-zoomable::after {
        content: "🔍"; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
        font-size: 12px; background: rgba(0, 0, 0, 0.6); color: white; border-radius: 50%; width: 24px; height: 24px;
        display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s ease;
    }
    .img-zoomable:hover::after { opacity: 1; }
    #editor-canvas { touch-action: none; }
  </style>
</head>

<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden">

<div id="loader" class="fixed inset-0 bg-white/95 flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-10 w-10 border-4 border-blue-200 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
require_once $app_dir . '/componentes/header.php'; 
require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-20 pb-32 md:pb-16 md:ml-64 px-4 md:px-8 w-auto">
  <div class="w-full max-w-[1600px] mx-auto space-y-6">

    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Gestión de Apuntes</h1>
            <p class="text-sm text-gray-500 mt-0.5">Mostrando los últimos 100 apuntes.</p>
        </div>
        
        <form class="flex gap-2 w-full md:w-auto" method="GET">
            <div class="relative w-full md:w-64 group">
                <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar título, autor o ramo..." 
                       class="w-full bg-white border border-gray-200 rounded-xl pl-4 pr-4 py-2.5 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none transition shadow-sm">
            </div>
            <button type="submit" class="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold px-5 py-2.5 rounded-xl text-sm transition shadow-sm hover:shadow-md hover:scale-[1.02]">Buscar</button>
        </form>
    </div>

    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden min-h-[400px]">
       
       <div class="hidden md:block overflow-x-auto">
         <table class="w-full text-sm text-left">
           <thead class="text-xs text-gray-500 uppercase bg-gray-50 border-b border-gray-100">
             <tr>
               <th class="px-6 py-4 font-bold">Título / Archivo</th>
               <th class="px-6 py-4 font-bold">Info</th>
               <th class="px-6 py-4 font-bold text-center">Estado</th>
               <th class="px-6 py-4 font-bold text-right">Acciones</th>
             </tr>
           </thead>
           <tbody class="divide-y divide-gray-50">
           <?php if (!empty($apuntes_lista)): ?>
             <?php foreach ($apuntes_lista as $apunte):
                $id_apunte = (int)$apunte['id'];
                $archivo   = $apunte['archivo'] ?? '';
                $visible   = (int)$apunte['publico'];
                $estado    = $apunte['estado'] ?? 'pendiente';
                
                $rutaMiniatura = obtener_ruta_miniatura($id_apunte, $apunte['portada'] ?? '', $archivo);
                $ruta_fisica = $_SERVER['DOCUMENT_ROOT'] . $rutaMiniatura;
                $version = file_exists($ruta_fisica) ? filemtime($ruta_fisica) : '1';
                $rutaDisplay = $rutaMiniatura . '?v=' . $version;
             ?>
             <tr id="fila-<?= $id_apunte ?>" class="hover:bg-gray-50 transition align-middle">
               
               <td class="px-6 py-4">
                   <div class="flex items-center gap-4">
                       <div class='relative img-zoomable inline-block group/img bg-gray-100 rounded border border-gray-200 shadow-sm w-12 h-16 shrink-0 animate-pulse-once' 
                            data-id="<?= $id_apunte ?>" data-src="<?= htmlspecialchars($rutaDisplay) ?>" data-path="<?= htmlspecialchars($rutaMiniatura) ?>">
                           <img src="<?= htmlspecialchars($rutaDisplay) ?>" 
                                alt="Miniatura" 
                                loading="lazy" decoding="async"
                                class="w-12 h-16 object-cover rounded transition group-hover/img:opacity-80" 
                                onload="this.parentElement.classList.remove('animate-pulse-once', 'bg-gray-100')"
                                onerror="this.src='/img/logo2.webp'">
                       </div>
                       <div class="min-w-0">
                           <div class="font-bold text-gray-900 truncate max-w-xs" title="<?= htmlspecialchars($apunte['titulo']) ?>">
                               <?= htmlspecialchars($apunte['titulo']) ?>
                           </div>
                          <a href="/app/ver_pdf_apunte.php?id=<?= $id_apunte ?>" target="_blank" class="text-xs text-[#54A6D8] hover:underline font-medium flex items-center gap-1 mt-0.5">
                               Ver documento <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                           </a>
                       </div>
                   </div>
               </td>

               <td class="px-6 py-4">
                   <div class="flex flex-col">
                       <span class="text-gray-900 text-xs font-bold uppercase tracking-tight bg-gray-100 inline-block px-2 py-0.5 rounded w-fit mb-1"><?= htmlspecialchars($apunte['asignatura']) ?></span>
                       <span class="text-gray-500 text-xs">Por: <?= htmlspecialchars($apunte['autor']) ?></span>
                       <span class="text-gray-400 text-[10px]"><?= date("d/m/Y", strtotime($apunte['fecha_subida'])) ?></span>
                   </div>
               </td>

               <td class="px-6 py-4 text-center">
                 <div class="estado-cell mb-1.5">
                    <?php
                      if ($estado === 'aprobado') echo '<span class="bg-green-100 text-green-700 border border-green-200 px-2 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider">Aprobado</span>';
                      elseif ($estado === 'rechazado') echo '<span class="bg-red-100 text-red-700 border border-red-200 px-2 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider">Rechazado</span>';
                      else echo '<span class="bg-yellow-100 text-yellow-700 border border-yellow-200 px-2 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider">Pendiente</span>';
                    ?>
                 </div>
                 <div class="visibilidad-cell">
                     <?php if ($visible): ?>
                        <span class="text-[10px] font-bold text-[#54A6D8] bg-blue-50 px-2 py-0.5 rounded border border-blue-100 uppercase tracking-wide">Público</span>
                     <?php else: ?>
                        <span class="text-[10px] font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded border border-gray-200 uppercase tracking-wide">Oculto</span>
                     <?php endif; ?>
                 </div>
               </td>

               <td class="px-6 py-4 text-right">
                 <div class="flex items-center justify-end gap-2 actions-wrapper">
                   
                   <?php if ($estado === 'pendiente'): ?>
                        <div class="acciones-estado flex gap-2 items-center">
                          <a href="/app/ver_pdf_apunte.php?id=<?= $id_apunte ?>" target="_blank" 
                             class="bg-blue-50 text-[#54A6D8] hover:bg-blue-100 px-3 py-2 rounded-lg font-bold text-xs transition flex items-center gap-1.5 shadow-sm hover:scale-[1.02]" 
                             title="Revisar documento">
                               <i class="fa-solid fa-magnifying-glass"></i> Revisar
                           </a>

                           <div id="decisiones-desktop-<?= $id_apunte ?>" class="flex items-center gap-2">
                               <button onclick="aprobarApunte(<?= $id_apunte ?>)" class="bg-green-50 text-green-600 hover:bg-green-100 hover:scale-105 p-2 rounded-lg transition" title="Aprobar Documento">
                                   <?= icon('check-circle', 'w-4 h-4') ?>
                               </button>
                               <button onclick="abrirModalRechazo(<?= $id_apunte ?>)" class="bg-red-50 text-red-600 hover:bg-red-100 hover:scale-105 p-2 rounded-lg transition" title="Rechazar Documento">
                                   <?= icon('xmark', 'w-4 h-4') ?>
                               </button>
                           </div>
                           <div class="w-px h-6 bg-gray-200 mx-1 self-center"></div>
                        </div>
                    <?php endif; ?>
                   
                    <form method="POST" action="/admin/acciones-apunte" class="inline form-ajax form-alternar">
                        <input type="hidden" name="id" value="<?= $id_apunte ?>">
                        <input type="hidden" name="accion" value="alternar">
                        <button type="submit" class="p-2 rounded-lg transition hover:scale-105 btn-visibilidad <?= $visible ? 'bg-blue-50 text-[#54A6D8] hover:bg-blue-100' : 'bg-gray-50 text-gray-400 hover:bg-gray-100' ?>" title="<?= $visible ? 'Ocultar Apunte' : 'Hacer Visible' ?>">
                             <?php if($visible): ?>
                                <?= icon('eye', 'w-4 h-4') ?>
                             <?php else: ?>
                                <?= icon_eye_slash('w-4 h-4') ?>
                             <?php endif; ?>
                        </button>
                    </form>

                    <form method="POST" action="/admin/acciones-apunte" class="inline form-ajax form-eliminar">
                        <input type="hidden" name="id" value="<?= $id_apunte ?>">
                        <input type="hidden" name="accion" value="eliminar">
                        <button type="submit" class="bg-gray-50 text-gray-400 hover:bg-red-50 hover:text-red-600 p-2 rounded-lg transition hover:scale-105" title="Eliminar Permanentemente">
                            <?= icon('trash', 'w-4 h-4') ?>
                        </button>
                    </form>

                 </div>
               </td>
             </tr>
             <?php endforeach; ?>
           <?php else: ?>
             <tr><td colspan="4" class="text-center py-16 text-gray-400 bg-gray-50 border-dashed border-2 border-gray-100 m-4 rounded-xl">No se encontraron apuntes.</td></tr>
           <?php endif; ?>
           </tbody>
         </table>
       </div>

       <div class="md:hidden p-4 space-y-4 bg-gray-50">
          <?php if (!empty($apuntes_lista)): ?>
             <?php foreach ($apuntes_lista as $apunte): 
                $id_apunte = (int)$apunte['id'];
                $archivo   = $apunte['archivo'] ?? '';
                $visible   = (int)$apunte['publico'];
                $estado    = $apunte['estado'] ?? 'pendiente';
                
                $rutaMiniatura = obtener_ruta_miniatura($id_apunte, $apunte['portada'] ?? '', $archivo);
                $ruta_fisica = $_SERVER['DOCUMENT_ROOT'] . $rutaMiniatura;
                $version = file_exists($ruta_fisica) ? filemtime($ruta_fisica) : '1';
                $rutaDisplay = $rutaMiniatura . '?v=' . $version;
             ?>
             <div id="card-<?= $id_apunte ?>" class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex flex-col gap-3">
                 <div class="flex gap-4">
                     <div class='relative img-zoomable inline-block group/img bg-gray-100 rounded border border-gray-100 shadow-sm w-16 h-20 shrink-0 animate-pulse-once' 
                          data-id="<?= $id_apunte ?>" data-src="<?= htmlspecialchars($rutaDisplay) ?>" data-path="<?= htmlspecialchars($rutaMiniatura) ?>">
                         <img src="<?= htmlspecialchars($rutaDisplay) ?>" loading="lazy" class="w-16 h-20 object-cover rounded transition group-hover/img:opacity-80" onerror="this.src='/img/logo2.webp'" onload="this.parentElement.classList.remove('animate-pulse-once', 'bg-gray-100')">
                     </div>
                     <div class="flex-1 min-w-0">
                         <h3 class="font-bold text-gray-900 text-sm truncate"><?= htmlspecialchars($apunte['titulo']) ?></h3>
                         <p class="text-xs text-gray-500 mb-1"><?= htmlspecialchars($apunte['autor']) ?></p>
                         <div class="flex gap-2 mb-2 estado-vis-container">
                             <span class="estado-badge text-[10px] font-bold px-2 py-0.5 rounded uppercase border tracking-wider
                                 <?= $estado==='aprobado'?'bg-green-50 text-green-700 border-green-100':($estado==='rechazado'?'bg-red-50 text-red-700 border-red-100':'bg-yellow-50 text-yellow-700 border-yellow-100') ?>">
                                 <?= ucfirst($estado) ?>
                             </span>
                             <span class="visibilidad-badge text-[10px] font-bold px-2 py-0.5 rounded border tracking-wider uppercase <?= $visible ? 'bg-blue-50 text-[#54A6D8] border-blue-100' : 'bg-gray-100 text-gray-500 border-gray-200' ?>">
                                 <?= $visible ? 'Público' : 'Oculto' ?>
                             </span>
                         </div>
                     </div>
                 </div>
                 
                 <div class="flex gap-2 justify-end border-t border-gray-50 pt-3 actions-wrapper-mobile">
                    <?php if ($estado === 'pendiente'): ?>
                        <div class="acciones-estado-mobile flex flex-col gap-2 w-full">
                           <a href="/app/ver_pdf_apunte.php?id=<?= $id_apunte ?>" target="_blank" 
                              class="bg-blue-50 text-[#54A6D8] border border-blue-100 px-3 py-2.5 rounded-xl text-xs font-bold w-full text-center flex justify-center items-center gap-2 shadow-sm active:scale-95 transition-transform">
                                <i class="fa-solid fa-magnifying-glass"></i> Leer Documento Completo
                            </a>

                            <div id="decisiones-mobile-<?= $id_apunte ?>" class="flex gap-2 w-full">
                                <button onclick="aprobarApunte(<?= $id_apunte ?>)" class="bg-green-50 text-green-600 border border-green-100 px-3 py-2 rounded-xl text-xs font-bold flex-1 shadow-sm active:scale-95 transition-transform">Aprobar</button>
                                <button onclick="abrirModalRechazo(<?= $id_apunte ?>)" class="bg-red-50 text-red-600 border border-red-100 px-3 py-2 rounded-xl text-xs font-bold flex-1 shadow-sm active:scale-95 transition-transform">Rechazar</button>
                            </div>
                        </div>
                     <?php endif; ?>

                     <form method="POST" action="/admin/acciones-apunte" class="contents form-ajax form-alternar">
                         <input type="hidden" name="id" value="<?= $id_apunte ?>">
                         <input type="hidden" name="accion" value="alternar">
                         <button type="submit" class="px-3 py-2 rounded-xl border btn-visibilidad <?= $visible ? 'bg-blue-50 border-blue-100 text-[#54A6D8]' : 'bg-gray-50 border-gray-200 text-gray-400' ?>">
                             <?php if($visible): ?><?= icon('eye', 'w-4 h-4') ?><?php else: ?><?= icon_eye_slash('w-4 h-4') ?><?php endif; ?>
                         </button>
                     </form>

                     <form method="POST" action="/admin/acciones-apunte" class="contents form-ajax form-eliminar">
                         <input type="hidden" name="id" value="<?= $id_apunte ?>">
                         <input type="hidden" name="accion" value="eliminar">
                         <button type="submit" class="px-3 py-2 rounded-xl bg-gray-50 border border-gray-200 text-gray-400 hover:bg-red-50 hover:text-red-500 hover:border-red-100">
                             <?= icon('trash', 'w-4 h-4') ?>
                         </button>
                     </form>
                 </div>
             </div>
             <?php endforeach; ?>
          <?php endif; ?>
       </div>

    </div>
  </div>
</main>

<div id="modal-rechazo" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[70] hidden flex items-center justify-center transition-opacity">
  <form id="form-rechazo" method="POST" class="bg-white p-6 rounded-2xl shadow-2xl w-full max-w-sm transform scale-100 transition-transform">
    <h3 class="text-lg font-bold text-gray-900 mb-4 tracking-tight">Rechazar Apunte</h3>
    <input type="hidden" id="rechazo_id_apunte">
    <label class="block mb-2 text-xs font-bold text-gray-700 uppercase">Motivo del rechazo</label>
    <textarea id="rechazo_motivo" required class="w-full border border-gray-200 rounded-xl px-4 py-3 mb-4 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none transition" rows="3" placeholder="Explica por qué..."></textarea>
    <div class="flex justify-end gap-2">
      <button type="button" onclick="cerrarModalRechazo()" class="px-4 py-2 rounded-xl text-sm font-bold text-gray-600 hover:bg-gray-100 transition">Cancelar</button>
      <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-5 py-2 rounded-xl text-sm font-bold shadow-sm transition hover:scale-[1.02]">Confirmar</button>
    </div>
  </form>
</div>

<div id="zoom-modal" class="fixed inset-0 bg-gray-900/95 backdrop-blur-sm z-[80] hidden flex flex-col items-center justify-center">
  <div id="editor-toolbar" class="absolute top-0 w-full flex justify-between items-center px-4 md:px-6 py-4 bg-gray-900/80 border-b border-gray-700/50 shadow-sm z-[81]">
      <div class="text-white">
          <span id="zoom-info" class="font-medium text-sm opacity-90"></span>
      </div>
      <div class="flex gap-2 md:gap-3 items-center">
          <button id="btn-activar-edicion" onclick="activarEdicion()" class="bg-gray-700 hover:bg-gray-600 text-white text-xs font-bold px-4 py-2.5 rounded-xl flex items-center gap-2 transition hover:scale-[1.02]">
              <i class="fa-solid fa-pen-to-square"></i> Editar / Censurar
          </button>
          
          <div id="tools-panel" class="hidden flex items-center gap-2">
              <span class="text-white/50 text-xs hidden md:inline-block mr-2">Dibuja un recuadro para censurar</span>
              <button id="btn-guardar-edicion" onclick="guardarEdicion()" class="bg-[#54A6D8] hover:bg-blue-600 text-white text-xs font-bold px-4 py-2.5 rounded-xl shadow-lg shadow-blue-500/20 transition hover:scale-[1.02]">
                 Guardar Cambios
              </button>
              <button onclick="cancelarEdicion()" class="bg-red-500/20 text-red-400 hover:bg-red-500 hover:text-white text-xs font-bold px-4 py-2.5 rounded-xl transition">
                 Cancelar
              </button>
          </div>
          
          <button onclick="cerrarZoom()" class="text-gray-400 hover:text-white bg-gray-800 hover:bg-gray-700 p-2.5 rounded-full transition ml-2 md:ml-4">
             <i class="fa-solid fa-xmark w-4 h-4 flex items-center justify-center"></i>
          </button>
      </div>
  </div>
  
  <div class="relative w-full h-full flex items-center justify-center p-4 pt-20 pb-20">
      <img id="zoom-img" src="" class="max-w-full max-h-[80vh] object-contain rounded-xl shadow-2xl transition-opacity duration-300">
      <canvas id="editor-canvas" class="hidden max-w-full max-h-[80vh] rounded-xl shadow-2xl cursor-crosshair border border-gray-700"></canvas>
  </div>

  <div id="page-selector" class="absolute bottom-6 left-1/2 -translate-x-1/2 flex items-center gap-2 bg-gray-800/80 backdrop-blur-md p-2 rounded-2xl shadow-xl z-[81]">
      <button id="btn-pag-1" class="page-btn bg-[#54A6D8] text-white px-4 py-1.5 rounded-xl text-xs font-bold shadow-sm transition-all" onclick="cargarPagina(1)">Pág 1</button>
      <button id="btn-pag-2" class="page-btn bg-gray-700 text-gray-400 hover:text-white hover:bg-gray-600 px-4 py-1.5 rounded-xl text-xs font-bold transition-all" onclick="cargarPagina(2)">Pág 2</button>
      <button id="btn-pag-3" class="page-btn bg-gray-700 text-gray-400 hover:text-white hover:bg-gray-600 px-4 py-1.5 rounded-xl text-xs font-bold transition-all" onclick="cargarPagina(3)">Pág 3</button>
  </div>
</div>

<div id="toast" class="fixed bottom-6 right-6 px-4 py-3 rounded-xl shadow-lg hidden text-white text-sm font-bold z-[90] flex items-center gap-2 transform translate-y-10 transition-all duration-300"></div>

<?php 
require_once $app_dir . '/componentes/nav_bottom.php'; 
require_once $app_dir . '/componentes/modal_publicar.php'; 
require_once $app_dir . '/componentes/modal_explora.php'; 
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script>
window.onload = () => { const l = document.getElementById('loader'); if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } };

// SISTEMA DE MODALES NUBIRA 2.0 (Para el Nav Bottom)
const NubiraModales = {
    setup(triggerId, modalId, cardId, closeId) {
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
        
        btn.onclick = (e) => { 
            e.preventDefault(); 
            open(); 
        }; 
        if(close) close.onclick = shut; 
        modal.onclick = (e) => { if(e.target === modal) shut(); };
    }
};

document.addEventListener('DOMContentLoaded', () => {
    NubiraModales.setup('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
    NubiraModales.setup('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
});

function mostrarToast(msg, tipo='ok') {
  const toast = document.getElementById('toast');
  toast.innerHTML = (tipo==='ok' ? '✅ ' : '❌ ') + msg;
  toast.className = 'fixed bottom-6 right-6 px-5 py-3 rounded-xl shadow-xl text-white z-[90] flex items-center gap-2 transform transition-all duration-300 ' + (tipo==='ok' ? 'bg-green-600 translate-y-0' : 'bg-red-600 translate-y-0');
  toast.classList.remove('hidden');
  setTimeout(() => { toast.classList.add('translate-y-10', 'opacity-0'); setTimeout(()=>toast.classList.add('hidden'), 300); }, 3000);
}

// -----------------------------------------------------
// LÓGICA AJAX: SIN RECARGAR LA PÁGINA (NUBIRA 2.0)
// -----------------------------------------------------
async function aprobarApunte(id){
    if(!confirm('¿Aprobar este apunte para toda la comunidad?')) return;
    try {
        const res = await fetch('/admin/acciones-apunte', {
          method:'POST',
          headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body: new URLSearchParams({accion:'aprobar', id: id})
        });
        
        if(res.ok){
          // 1. Actualizar DOM Desktop
          const fila = document.getElementById('fila-'+id);
          if (fila) {
             const estadoCell = fila.querySelector('.estado-cell');
             if (estadoCell) estadoCell.innerHTML = '<span class="bg-green-100 text-green-700 border border-green-200 px-2 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider">Aprobado</span>';
             
             const btnArea = fila.querySelector('.acciones-estado');
             if(btnArea) btnArea.remove();
          }
          
          // 2. Actualizar DOM Móvil
          const card = document.getElementById('card-'+id);
          if (card) {
             const badge = card.querySelector('.estado-badge');
             if(badge) {
                 badge.className = 'estado-badge text-[10px] font-bold px-2 py-0.5 rounded uppercase border tracking-wider bg-green-50 text-green-700 border-green-100';
                 badge.innerText = 'Aprobado';
             }
             const btnAreaMob = card.querySelector('.acciones-estado-mobile');
             if(btnAreaMob) btnAreaMob.remove();
          }
          
          mostrarToast('Apunte aprobado correctamente');
        } else {
            throw new Error('Error HTTP: ' + res.status);
        }
    } catch(e) { 
        console.error('Error JS:', e);
        mostrarToast('Error al aprobar el apunte', 'error'); 
    }
}

function abrirModalRechazo(id){
    document.getElementById('rechazo_id_apunte').value = id;
    document.getElementById('rechazo_motivo').value = '';
    document.getElementById('modal-rechazo').classList.remove('hidden');
}

function cerrarModalRechazo(){ 
    document.getElementById('modal-rechazo').classList.add('hidden'); 
}

document.getElementById('form-rechazo').addEventListener('submit', async (e)=>{
    e.preventDefault();
    const id = document.getElementById('rechazo_id_apunte').value;
    const motivo = document.getElementById('rechazo_motivo').value;
    try {
        const res = await fetch('/admin/acciones-apunte', {
          method:'POST',
          headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body: new URLSearchParams({accion:'rechazar', id: id, motivo_rechazo: motivo})
        });
        
        if(res.ok){
          cerrarModalRechazo();
          
          // 1. Actualizar DOM Desktop
          const fila = document.getElementById('fila-'+id);
          if (fila) {
             const estadoCell = fila.querySelector('.estado-cell');
             if (estadoCell) estadoCell.innerHTML = '<span class="bg-red-100 text-red-700 border border-red-200 px-2 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider">Rechazado</span>';
             
             const btnArea = fila.querySelector('.acciones-estado');
             if(btnArea) btnArea.remove();
          }
          
          // 2. Actualizar DOM Móvil
          const card = document.getElementById('card-'+id);
          if (card) {
             const badge = card.querySelector('.estado-badge');
             if(badge) {
                 badge.className = 'estado-badge text-[10px] font-bold px-2 py-0.5 rounded uppercase border tracking-wider bg-red-50 text-red-700 border-red-100';
                 badge.innerText = 'Rechazado';
             }
             const btnAreaMob = card.querySelector('.acciones-estado-mobile');
             if(btnAreaMob) btnAreaMob.remove();
          }
          
          mostrarToast('Apunte rechazado');
        } else {
            throw new Error('Error HTTP: ' + res.status);
        }
    } catch(e) { 
        console.error('Error JS:', e);
        mostrarToast('Error al rechazar', 'error'); 
    }
});

// DELEGACIÓN DE EVENTOS PARA FORMULARIOS (Memoria Frontend Optimizado)
document.addEventListener('submit', async e => {
    const form = e.target.closest('.form-ajax');
    if (!form) return;
    e.preventDefault();
    
    const isEliminar = form.classList.contains('form-eliminar');
    if (isEliminar && !confirm('⚠️ ¿Eliminar definitivamente? Esta acción no se puede deshacer.')) return;

    const id = form.querySelector('[name="id"]').value;
    const accion = form.querySelector('[name="accion"]').value;
    
    try {
        const res = await fetch('/admin/acciones-apunte', {
          method: 'POST',
          body: new FormData(form)
        });
        
        if (res.ok) {
            if (isEliminar) {
                document.getElementById('fila-'+id)?.remove();
                document.getElementById('card-'+id)?.remove();
                mostrarToast('Apunte eliminado');
            } else if (accion === 'alternar') {
                // Desktop
                const fila = document.getElementById('fila-'+id);
                if(fila) {
                    const vCell = fila.querySelector('.visibilidad-cell');
                    const btn = fila.querySelector('.btn-visibilidad');
                    const isNowVisible = !vCell.innerHTML.includes('Público');
                    
                    vCell.innerHTML = isNowVisible 
                        ? '<span class="text-[10px] font-bold text-[#54A6D8] bg-blue-50 px-2 py-0.5 rounded border border-blue-100 uppercase tracking-wide">Público</span>'
                        : '<span class="text-[10px] font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded border border-gray-200 uppercase tracking-wide">Oculto</span>';
                    
                    btn.className = `p-2 rounded-lg transition hover:scale-105 btn-visibilidad ${isNowVisible ? 'bg-blue-50 text-[#54A6D8] hover:bg-blue-100' : 'bg-gray-50 text-gray-400 hover:bg-gray-100'}`;
                    btn.innerHTML = isNowVisible ? '<i class="fa-solid fa-eye w-4 h-4"></i>' : '<i class="fa-solid fa-eye-slash w-4 h-4"></i>';
                }
                // Móvil
                const card = document.getElementById('card-'+id);
                if(card) {
                    const badge = card.querySelector('.visibilidad-badge');
                    const btn = card.querySelector('.btn-visibilidad');
                    const isNowVisible = !badge.innerText.includes('Público');
                    
                    badge.className = `visibilidad-badge text-[10px] font-bold px-2 py-0.5 rounded border tracking-wider uppercase ${isNowVisible ? 'bg-blue-50 text-[#54A6D8] border-blue-100' : 'bg-gray-100 text-gray-500 border-gray-200'}`;
                    badge.innerText = isNowVisible ? 'Público' : 'Oculto';
                    
                    btn.className = `px-3 py-2 rounded-xl border btn-visibilidad ${isNowVisible ? 'bg-blue-50 border-blue-100 text-[#54A6D8]' : 'bg-gray-50 border-gray-200 text-gray-400'}`;
                    btn.innerHTML = isNowVisible ? '<i class="fa-solid fa-eye w-4 h-4"></i>' : '<i class="fa-solid fa-eye-slash w-4 h-4"></i>';
                }
                mostrarToast('Visibilidad actualizada');
            }
        } else throw new Error();
    } catch(err) { mostrarToast('Error en la operación', 'error'); }
});

// -----------------------------------------------------
// LÓGICA DE ZOOM Y EDICIÓN MULTIPÁGINA (NUBIRA STUDOCU MODE)
// -----------------------------------------------------
let zoomImg = document.getElementById('zoom-img');
let zoomModal = document.getElementById('zoom-modal');
let editorCanvas = document.getElementById('editor-canvas');
let btnActivar = document.getElementById('btn-activar-edicion');
let toolsPanel = document.getElementById('tools-panel');

let isEditing = false;
let currentApunteId = null;
let currentImagePath = null;
let originalThumbPath = null;
let currentPageNum = 1;
let ctx = null;
let isDrawing = false;
let startX, startY;
let snapshot;

document.addEventListener('click', e => {
  const wrapper = e.target.closest('.img-zoomable');
  if (wrapper) {
    const src = wrapper.dataset.src;
    currentApunteId = wrapper.dataset.id;
    originalThumbPath = wrapper.dataset.path;
    
    if(originalThumbPath === '/img/logo2.webp') {
        mostrarToast('Esta miniatura no se puede editar', 'error');
        return;
    }

    const fila = wrapper.closest('tr') || wrapper.closest('div[id^="card-"]');
    const titulo = fila.querySelector('.truncate')?.innerText || '';
    document.getElementById('zoom-info').textContent = `#${currentApunteId} - ${titulo}`;
    
    // Iniciar siempre en la página 1
    cargarPagina(1);

    zoomModal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
  }
});

function cargarPagina(num) {
    currentPageNum = num;
    
    // Actualizar UI Botones
    document.querySelectorAll('.page-btn').forEach(btn => {
        btn.classList.remove('bg-[#54A6D8]', 'text-white');
        btn.classList.add('bg-gray-700', 'text-gray-400');
    });
    const btnActivo = document.getElementById('btn-pag-' + num);
    if(btnActivo) {
        btnActivo.classList.remove('bg-gray-700', 'text-gray-400');
        btnActivo.classList.add('bg-[#54A6D8]', 'text-white');
    }

    // Calcular nueva ruta
    let newPath = `/upload/preview_paginas/${currentApunteId}_${num}.webp`;
    currentImagePath = newPath;
    
    // Si estábamos editando, pausar la edición temporalmente
    let reanudarEdicion = isEditing;
    cancelarEdicion();
    
    zoomImg.classList.add('opacity-50'); // Efecto de carga
    
    // Tratar de cargar la página extraída
    let tempImg = new Image();
    tempImg.onload = function() {
        zoomImg.src = this.src;
        zoomImg.classList.remove('opacity-50');
        if (reanudarEdicion) activarEdicion();
    };
    
    // Si no existe (apunte antiguo o PDF corto)
    tempImg.onerror = function() {
        if (num === 1) {
            // Fallback a la miniatura original
            zoomImg.src = originalThumbPath + '?v=' + new Date().getTime();
            currentImagePath = originalThumbPath.split('?')[0];
            zoomImg.classList.remove('opacity-50');
            if (reanudarEdicion) activarEdicion();
        } else {
            mostrarToast(`La página ${num} no está disponible en este apunte.`, 'error');
            cargarPagina(1); // Devolver a la página 1
        }
    };
    
    tempImg.src = newPath + '?v=' + new Date().getTime();
}

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
    return { x: (clientX - rect.left) * scaleX, y: (clientY - rect.top) * scaleY };
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
    if(!confirm(`¿Sobrescribir la Página ${currentPageNum} original con estos cambios?`)) return;
    
    const btnGuardar = document.getElementById('btn-guardar-edicion');
    btnGuardar.innerText = 'Guardando...';
    btnGuardar.disabled = true;
    btnGuardar.classList.add('opacity-50', 'cursor-not-allowed');

    let mimeType = 'image/webp';
    if (currentImagePath.toLowerCase().endsWith('.png')) mimeType = 'image/png';
    if (currentImagePath.toLowerCase().endsWith('.jpg') || currentImagePath.toLowerCase().endsWith('.jpeg')) mimeType = 'image/jpeg';

    const dataUrl = editorCanvas.toDataURL(mimeType, 0.9);
    
    try {
        const formData = new URLSearchParams();
        formData.append('accion', 'guardar_imagen_editada');
        formData.append('id_apunte', currentApunteId);
        formData.append('ruta_imagen', currentImagePath);
        formData.append('imagen_base64', dataUrl);

        const res = await fetch(window.location.href, { 
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        
        if(data.status === 'ok') {
            mostrarToast(`Página ${currentPageNum} censurada correctamente`);
            
            // Actualizar caché forzada de la imagen actual y de la vitrina
            const cleanPath = currentImagePath.split('?')[0];
            const newTimestamp = new Date().getTime();
            
            document.querySelectorAll('img').forEach(img => {
                if (img.src.includes(cleanPath) || img.src.includes(originalThumbPath.split('?')[0])) {
                    img.src = img.src.split('?')[0] + '?v=' + newTimestamp;
                    const zoomable = img.closest('.img-zoomable');
                    if (zoomable) {
                        zoomable.dataset.src = zoomable.dataset.src.split('?')[0] + '?v=' + newTimestamp;
                    }
                }
            });
            
            cancelarEdicion();
            cargarPagina(currentPageNum); // Recargar la página fresca
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