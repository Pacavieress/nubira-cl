<?php
/**
 * VISTA: MIS ARCHIVOS SUBIDOS
 * UBICACIÓN: public_html/app/mis_archivos_subidos.php
 */
session_start();

// 1. SEGURIDAD
if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit; }

// 2. RUTAS BLINDADAS
$app_dir = __DIR__;
// Si no encuentra conexion.php aquí, busca en /app
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists($app_dir . '/app/conexion.php')) $app_dir = $app_dir . '/app';
    elseif (file_exists(dirname($app_dir) . '/app')) $app_dir = dirname($app_dir) . '/app';
}

require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

// 3. DATOS SESIÓN
$usuario_id     = (int)$_SESSION['usuario_id'];
$nombre_usuario = $_SESSION['usuario_nombre'] ?? 'Usuario';
$rol            = $_SESSION['rol'] ?? 'alumno';
$es_admin       = ($rol === 'admin');

// Variables Header
$institucion_session = strtolower(trim($_SESSION['institucion'] ?? ''));
$nombres_inst = ['uc'=>'UC','aiep'=>'AIEP','uss'=>'USS','udp'=>'UDP'];
$nombre_institucion = $nombres_inst[$institucion_session] ?? ucfirst($institucion_session);
$display_carrera = $_SESSION['carrera'] ?? 'Estudiante';

// 4. LÓGICA DE BORRADO
$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_id'])) {
    $eliminar_id = intval($_POST['eliminar_id']);
    
    // Verificar propiedad (o admin)
    $checkSql = $es_admin ? "SELECT id, archivo FROM apuntes WHERE id = ?" : "SELECT id, archivo FROM apuntes WHERE id = ? AND id_alumno = ?";
    $stmt = $conn->prepare($checkSql);
    if ($es_admin) $stmt->bind_param("i", $eliminar_id);
    else $stmt->bind_param("ii", $eliminar_id, $usuario_id);
    
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        // Borrar archivo físico
        $ruta_archivo = $_SERVER['DOCUMENT_ROOT'] . "/upload/apuntes/" . $row['archivo'];
        if (file_exists($ruta_archivo)) @unlink($ruta_archivo);
        
        // Borrar registro
        $del = $conn->prepare("DELETE FROM apuntes WHERE id = ?");
        $del->bind_param("i", $eliminar_id);
        $del->execute();
        $del->close();
        
        $mensaje = "✅ Archivo eliminado correctamente.";
    }
    $stmt->close();
}

// 5. CONSULTA APUNTES
if ($es_admin) {
    $sql = "SELECT a.*, u.nombre AS alumno FROM apuntes a JOIN alumnos u ON a.id_alumno=u.id ORDER BY a.fecha_subida DESC";
    $stmt = $conn->prepare($sql);
} else {
    $sql = "SELECT * FROM apuntes WHERE id_alumno=? ORDER BY fecha_subida DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $usuario_id);
}
$stmt->execute();
$resultado = $stmt->get_result();
$apuntes = [];
while ($a = $resultado->fetch_assoc()) $apuntes[] = $a;
$stmt->close();

// Helper Nav (Para Sidebar)
$page_title = "Mis Apuntes Publicados";
if (!function_exists('nav_class')) {
    function nav_class(string $path): string {
        $ruta_actual = $_SERVER['REQUEST_URI'] ?? '/';
        $base = 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent';
        $activo = ' bg-blue-50 text-[#54A6D8] border-blue-100';
        $inactivo = ' text-gray-500 hover:bg-gray-50 hover:text-gray-900';
        // Mantener activo "Mi Perfil" o "Dashboard" cuando estamos aquí
        if ($path === '/dashboard') return $base . $activo; 
        return $base . $inactivo;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Mis Apuntes Publicados | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #ffffff; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
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
  <div class="w-full max-w-[1600px] mx-auto">
    
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Mis Apuntes Publicados</h1>
            <p class="text-gray-500 text-sm mt-1">Gestiona los apuntes que has compartido con la comunidad.</p>
        </div>
       
    </div>

    <?php if ($mensaje): ?>
        <div id="toast" class="mb-6 px-4 py-3 rounded-xl bg-green-50 text-green-700 border border-green-200 flex items-center gap-3 shadow-sm">
            <?= icon('check-circle', 'w-5 h-5 text-green-500') ?>
            <span class="text-sm font-medium flex-1"><?= htmlspecialchars($mensaje) ?></span>
            <button onclick="this.parentElement.remove()" class="text-xs underline hover:no-underline">Cerrar</button>
        </div>
    <?php endif; ?>

    <div class="mb-6 relative">
        <div class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
            <?= icon('search', 'w-5 h-5') ?>
        </div>
        <input id="filtroInput" type="text" placeholder="Buscar por título, asignatura..." 
               class="w-full pl-11 pr-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent outline-none transition text-sm">
    </div>

    <?php if (count($apuntes) > 0): ?>
        <div id="apuntesContainer" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <?php foreach ($apuntes as $a): 
                $id = $a['id'];
                $titulo = htmlspecialchars($a['titulo']);
                $fecha = date("d/m/Y", strtotime($a['fecha_subida']));
                $file = $a['archivo'];
                $estado = $a['publico'] ? 'Publicado' : 'Pendiente';
                $colorEstado = $a['publico'] ? 'text-green-600 bg-green-50 border-green-100' : 'text-yellow-600 bg-yellow-50 border-yellow-100';
                
                // Determinar el tipo de archivo para el icono
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $esImagen = in_array($ext, ['jpg','jpeg','png','webp','bmp']);
                $rutaImg = null;
                
                // Buscar si existe un preview público o una portada
                if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/upload/preview/' . $id . '.webp')) {
                    $rutaImg = '/upload/preview/' . $id . '.webp';
                } elseif (!empty($a['portada']) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/upload/portadas/' . $a['portada'])) {
                    $rutaImg = '/upload/portadas/' . $a['portada'];
                }

                // Configuración de iconos por extensión
                $iconClass = 'fa-file-lines';
                $iconColor = 'text-gray-500';
                $bgIcon = 'bg-gray-100';

                if ($ext === 'pdf') {
                    $iconClass = 'fa-file-pdf';
                    $iconColor = 'text-red-500';
                    $bgIcon = 'bg-red-50';
                } elseif ($esImagen) {
                    $iconClass = 'fa-image';
                    $iconColor = 'text-emerald-500';
                    $bgIcon = 'bg-emerald-50';
                } elseif (in_array($ext, ['doc', 'docx'])) {
                    $iconClass = 'fa-file-word';
                    $iconColor = 'text-blue-600';
                    $bgIcon = 'bg-blue-50';
                } elseif (in_array($ext, ['xls', 'xlsx'])) {
                    $iconClass = 'fa-file-excel';
                    $iconColor = 'text-green-600';
                    $bgIcon = 'bg-green-50';
                } elseif (in_array($ext, ['ppt', 'pptx'])) {
                    $iconClass = 'fa-file-powerpoint';
                    $iconColor = 'text-orange-500';
                    $bgIcon = 'bg-orange-50';
                }
            ?>
            <div class="apunte-card bg-white rounded-2xl p-5 border border-gray-100 shadow-sm hover:shadow-md transition-all flex flex-col group h-full"
                 data-titulo="<?= strtolower($titulo) ?>">
                
                <div class="flex justify-between items-start mb-3">
                    <div class="w-10 h-10 rounded-lg <?= $bgIcon ?> flex items-center justify-center <?= $iconColor ?> transition-colors overflow-hidden shrink-0">
                        <?php if($rutaImg): ?>
                             <img src="<?= htmlspecialchars($rutaImg) ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                             <i class="fa-solid <?= $iconClass ?> text-lg"></i>
                        <?php endif; ?>
                    </div>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border <?= $colorEstado ?>">
                        <?= $estado ?>
                    </span>
                </div>

                <h3 class="font-bold text-gray-900 text-sm leading-snug line-clamp-2 mb-1" title="<?= $titulo ?>">
                    <?= $titulo ?>
                </h3>
                <p class="text-xs text-gray-400 mb-4"><?= $fecha ?></p>

                <div class="mt-auto flex gap-2 pt-4 border-t border-gray-50">
                    <a href="/ver-apunte?archivo=<?= urlencode($file) ?>" target="_blank" class="flex-1 flex items-center justify-center gap-1 bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-bold py-2 rounded-lg text-center transition">
                        <?= icon('search', 'w-3 h-3') ?> Ver
                    </a>
                    <form method="POST" onsubmit="return confirm('¿Estás seguro de eliminar este apunte? No se puede deshacer.');" class="flex-1">
                        <input type="hidden" name="eliminar_id" value="<?= $id ?>">
                        <button type="submit" class="w-full flex items-center justify-center gap-1 bg-red-50 hover:bg-red-100 text-red-600 text-xs font-bold py-2 rounded-lg transition">
                            <?= icon('trash', 'w-3 h-3') ?> Eliminar
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-center py-20 bg-white rounded-2xl border border-dashed border-gray-200">
            <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mx-auto mb-4 text-gray-300">
                <?= icon('publish-doc', 'w-8 h-8') ?>
            </div>
            <h3 class="text-lg font-bold text-gray-900">No has subido apuntes aún</h3>
            <p class="text-gray-500 text-sm mt-1 mb-6">Comparte tus resúmenes y ayuda a la comunidad.</p>
            <a href="/formulario-subir-apunte" class="text-sm font-bold text-[#54A6D8] hover:underline">Subir mi primer apunte</a>
        </div>
    <?php endif; ?>

  </div>
</main>

<?php 
require_once $app_dir . '/componentes/nav_bottom.php'; 
require_once $app_dir . '/componentes/modal_publicar.php'; 
require_once $app_dir . '/componentes/modal_explora.php'; 
?>

<script>
window.onload = () => { const l = document.getElementById('loader'); if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } };

// Modales Standard
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

// Filtro Cliente
document.getElementById('filtroInput')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.apunte-card').forEach(card => {
        const title = card.getAttribute('data-titulo') || '';
        card.style.display = title.includes(q) ? '' : 'none';
    });
});

// Chats
function abrirMisChats() { window.open("/app/mis_chats.php", "mis_chats", "width=440,height=640,resizable=yes,scrollbars=yes"); }
</script>

</body>
</html>