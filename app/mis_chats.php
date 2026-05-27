<?php
/**
 * VISTA: MIS CHATS (NUBIRA 2.0)
 * UBICACIÓN: public_html/app/mis_chats.php
 * MEJORA: Detecta mensajes no leídos por conversación para resaltarlos.
 */

// 1. CONFIGURACIÓN
ini_set('display_errors', 0); 
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();
date_default_timezone_set('America/Santiago');

// 2. RUTAS Y CONEXIÓN
$app_path = __DIR__; 
$conn_paths = [$app_path . '/conexion.php', dirname($app_path) . '/conexion.php'];
$conn_loaded = false;
foreach ($conn_paths as $cp) {
    if (file_exists($cp)) { require_once $cp; $conn_loaded = true; break; }
}
if (!$conn_loaded) die("Error 500: Sistema no disponible.");

// Iconos
$icons_path = $app_path . '/iconos.php';
if (file_exists($icons_path)) require_once $icons_path;

// 3. SEGURIDAD
if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit; }
$uid = (int)$_SESSION['usuario_id']; // Usamos $uid para acortar

// 4. CONSULTA SQL ROBUSTA
// Recuperamos: Datos básicos, Último mensaje, Fecha y CANTIDAD DE NO LEÍDOS
$conn->set_charset("utf8mb4");

$sql = "
    (SELECT 
        c.id, 'conversacion' as tipo, c.comprador_id, c.vendedor_id, 
        CAST(s.titulo AS CHAR) AS servicio_titulo,
        CAST(a.nombre AS CHAR) AS nombre_comprador, CAST(v.nombre AS CHAR) AS nombre_vendedor,
        CAST((SELECT mensaje FROM mensajes WHERE conversacion_id = c.id ORDER BY enviado_en DESC LIMIT 1) AS CHAR) as ultimo_mensaje,
        (SELECT enviado_en FROM mensajes WHERE conversacion_id = c.id ORDER BY enviado_en DESC LIMIT 1) as fecha_ult,
        (SELECT COUNT(*) FROM mensajes WHERE conversacion_id = c.id AND leido = 0 AND remitente_id != ?) as no_leidos
     FROM conversaciones c
     JOIN servicios s ON c.servicio_id = s.id
     JOIN alumnos a ON c.comprador_id = a.id
     JOIN alumnos v ON c.vendedor_id = v.id
     WHERE 
       (
         (c.comprador_id = ? AND (c.visible_comprador = 1 OR c.visible_comprador IS NULL)) 
         OR 
         (c.vendedor_id = ? AND (c.visible_vendedor = 1 OR c.visible_vendedor IS NULL))
       )
       AND (c.estado != 'archivada' OR c.estado IS NULL)
       AND c.eliminado = 0)
    
    UNION ALL

    (SELECT 
        c.id, 'aula' as tipo, c.comprador_id, c.vendedor_id, 
        CAST(CONCAT('Aula: ', s.titulo) AS CHAR) AS servicio_titulo,
        CAST(a.nombre AS CHAR) AS nombre_comprador, CAST(v.nombre AS CHAR) AS nombre_vendedor,
        CAST((SELECT mensaje FROM chat_aula WHERE contrato_id = c.id ORDER BY fecha DESC LIMIT 1) AS CHAR) as ultimo_mensaje,
        (SELECT fecha FROM chat_aula WHERE contrato_id = c.id ORDER BY fecha DESC LIMIT 1) as fecha_ult,
        (SELECT COUNT(*) FROM chat_aula WHERE contrato_id = c.id AND visto = 0 AND remitente_id != ?) as no_leidos
     FROM contratos c
     JOIN servicios s ON c.servicio_id = s.id
     JOIN alumnos a ON c.comprador_id = a.id
     JOIN alumnos v ON c.vendedor_id = v.id
     WHERE (c.comprador_id = ? OR c.vendedor_id = ?) AND c.estado != 'cancelado')

    ORDER BY fecha_ult DESC
";

// Bind Param:
// Parte 1 (Conversaciones): $uid (no_leidos), $uid (comprador), $uid (vendedor)
// Parte 2 (Aulas): $uid (no_leidos), $uid (comprador), $uid (vendedor)
// Total: 6 enteros
$stmt = $conn->prepare($sql);
$stmt->bind_param("iiiiii", $uid, $uid, $uid, $uid, $uid, $uid);
$stmt->execute();
$res = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mis Mensajes | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #F9FAFB; }
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .menu-enter { opacity: 0; transform: scale(0.95); pointer-events: none; }
    .menu-enter-active { opacity: 1; transform: scale(1); pointer-events: auto; transition: all 0.1s ease-out; }
  </style>
</head>

<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden" onclick="cerrarTodosMenus()">

<?php 
$comps = $app_path . '/componentes';
if (file_exists($comps . '/header.php')) require_once $comps . '/header.php';
if (file_exists($comps . '/sidebar.php')) require_once $comps . '/sidebar.php';
?>

<main class="pt-24 pb-32 md:pb-16 lg:ml-64 px-4 md:px-8 w-auto min-h-screen">
    <div class="w-full max-w-[850px] mx-auto">
        
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Mis Mensajes</h1>
                <p class="text-sm text-gray-500">Gestiona tus comunicaciones activas</p>
            </div>
        </div>

        <?php if ($res->num_rows === 0): ?>
            <div class="bg-white rounded-3xl border-2 border-dashed border-gray-200 p-12 text-center shadow-sm mt-4">
                <div class="w-16 h-16 bg-blue-50 rounded-full flex items-center justify-center mb-4 text-[#54A6D8] mx-auto">
                     <i class="fa-regular fa-comments text-3xl"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Bandeja de entrada vacía</h3>
                <p class="text-sm text-gray-500 mb-6 max-w-xs mx-auto">
                    Cuando contactes a un profesor o contrates un servicio, tus chats aparecerán aquí.
                </p>
                <a href="/clases-servicios" class="inline-flex items-center gap-2 bg-[#54A6D8] hover:bg-sky-500 text-white px-6 py-3 rounded-xl text-sm font-bold shadow-sm transition-all hover:scale-[1.02]">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    Explorar Servicios
                </a>
            </div>
        <?php else: ?>
            
            <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-visible divide-y divide-gray-50">
                <?php while ($c = $res->fetch_assoc()): 
                    $soyVendedor = ($uid == $c['vendedor_id']);
                    $nombreOtro  = $soyVendedor ? $c['nombre_comprador'] : $c['nombre_vendedor'];
                    $inicial     = strtoupper(substr($nombreOtro ?? 'U', 0, 1));
                    $esAula      = ($c['tipo'] === 'aula');
                    
                    // URL
                    $urlDestino = $esAula ? "/app/mini_aula.php?id=" . $c['id'] : "/app/chat_previo_contrato.php?id=" . $c['id'];
                    
                    // Lógica de "No Leído"
                    $numNoLeidos = (int)$c['no_leidos'];
                    $esNoLeido   = ($numNoLeidos > 0);
                    
                    // Clases visuales según estado
                    $claseFondo  = $esNoLeido ? 'bg-blue-50/50' : 'hover:bg-gray-50';
                    $claseNombre = $esNoLeido ? 'font-black text-gray-900' : 'font-bold text-gray-700';
                    $claseMsg    = $esNoLeido ? 'font-semibold text-gray-900' : 'font-normal text-gray-500';
                    
                    // Fecha
                    if (!empty($c['fecha_ult'])) {
                        $ts = strtotime($c['fecha_ult']);
                        $hoy = strtotime('today');
                        $ayer = strtotime('yesterday');
                        if ($ts >= $hoy) $fecha = date('H:i', $ts);
                        elseif ($ts >= $ayer) $fecha = 'Ayer';
                        else $fecha = date('d/m', $ts);
                    } else {
                        $fecha = ''; 
                    }
                    
                    $msgPreview = htmlspecialchars($c['ultimo_mensaje'] ?? 'Chat iniciado');
                    $chatID = $c['tipo'] . '-' . $c['id'];
                ?>
                
                <div class="relative group p-4 transition-all duration-200 cursor-pointer <?= $claseFondo ?>" onclick="window.location.href='<?= $urlDestino ?>'">
                    
                    <div class="flex items-start gap-4">
                        <div class="relative">
                            <div class="w-12 h-12 rounded-full <?= $esAula ? 'bg-emerald-100 text-emerald-600' : 'bg-blue-100 text-[#54A6D8]' ?> flex items-center justify-center font-bold text-lg shrink-0 border border-black/5">
                                <?= $inicial ?>
                            </div>
                            <?php if($esAula): ?>
                                <div class="absolute -bottom-1 -right-1 bg-emerald-500 rounded-full p-1 border-2 border-white shadow-sm" title="Aula Activa">
                                    <svg class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7"></path></svg>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="flex-1 min-w-0 py-0.5 pr-8"> 
                            <div class="flex justify-between items-baseline mb-1">
                                <h2 class="<?= $claseNombre ?> truncate pr-2 text-[15px]">
                                    <?= htmlspecialchars($nombreOtro) ?>
                                </h2>
                                <span class="text-xs <?= $esNoLeido ? 'text-[#54A6D8] font-bold' : 'text-gray-400 font-medium' ?> shrink-0">
                                    <?= $fecha ?>
                                </span>
                            </div>
                            
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1 truncate flex items-center gap-1">
                                <?= htmlspecialchars($c['servicio_titulo'] ?? 'Servicio') ?>
                            </p>

                            <div class="flex justify-between items-center">
                                <p class="text-sm <?= $claseMsg ?> truncate opacity-90 leading-snug flex-1">
                                    <?= $msgPreview ?>
                                </p>
                                
                                <?php if($esNoLeido): ?>
                                    <span class="ml-2 w-2.5 h-2.5 bg-[#54A6D8] rounded-full shrink-0"></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="absolute top-4 right-2 z-10" onclick="event.stopPropagation();">
                        <button onclick="toggleMenu(event, 'menu-<?= $chatID ?>')" class="p-2 rounded-full text-gray-300 hover:text-gray-600 hover:bg-black/5 transition-colors outline-none">
                            <i class="fa-solid fa-ellipsis-vertical"></i>
                        </button>
                        
                        <div id="menu-<?= $chatID ?>" class="dropdown-menu hidden absolute right-0 top-10 w-40 bg-white rounded-xl shadow-xl border border-gray-100 py-1.5 z-20 menu-enter">
                            <button onclick="accionChat('archivar', '<?= $c['id'] ?>', '<?= $c['tipo'] ?>')" class="w-full text-left px-4 py-2.5 text-xs font-medium text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                                <i class="fa-solid fa-box-archive text-gray-400"></i> Archivar
                            </button>
                            <button onclick="accionChat('eliminar', '<?= $c['id'] ?>', '<?= $c['tipo'] ?>')" class="w-full text-left px-4 py-2.5 text-xs font-bold text-red-500 hover:bg-red-50 flex items-center gap-2">
                                <i class="fa-solid fa-trash text-red-400"></i> Eliminar
                            </button>
                        </div>
                    </div>

                </div>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php 
if (file_exists($comps . '/nav_bottom.php')) require_once $comps . '/nav_bottom.php';
if (file_exists($comps . '/modal_publicar.php')) require_once $comps . '/modal_publicar.php';
if (file_exists($comps . '/modal_explora.php')) require_once $comps . '/modal_explora.php';
?>

<script>
// Sistema de Menús
function toggleMenu(event, menuId) {
    event.stopPropagation();
    const menu = document.getElementById(menuId);
    const estAbierto = !menu.classList.contains('hidden');
    cerrarTodosMenus(); // Cierra otros
    if (!estAbierto) {
        menu.classList.remove('hidden');
        menu.classList.add('menu-enter-active');
    }
}

function cerrarTodosMenus() {
    document.querySelectorAll('.dropdown-menu').forEach(el => {
        el.classList.add('hidden');
        el.classList.remove('menu-enter-active');
    });
}

// Acción Asíncrona (Archivar/Eliminar)
async function accionChat(accion, id, tipo) {
    const texto = accion === 'eliminar' 
        ? '¿Eliminar chat para siempre? No podrás recuperarlo.' 
        : '¿Archivar chat?';
        
    if(!confirm(texto)) return;

    try {
        const formData = new FormData();
        formData.append('accion', accion);
        formData.append('id', id);
        formData.append('tipo', tipo);

        // Asegúrate de que este archivo exista en /app/accion_chat.php
        const res = await fetch('/app/accion_chat.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            location.reload(); 
        } else {
            alert('Error: ' + (data.error || 'Fallo desconocido'));
        }
    } catch (e) {
        console.error(e);
        alert('Error de conexión.');
    }
}
</script>

</body>
</html>