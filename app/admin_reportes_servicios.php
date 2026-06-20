<?php
/**
 * VISTA ADMINISTRACIÓN: REPORTES DE SERVICIOS
 * ARQUITECTURA: NUBIRA 2.0
 */

// --- 1. CONFIGURACIÓN Y DEBUG ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

// Rutas de inclusión robustas
$rutas_posibles = [__DIR__ . '/../app/conexion.php', __DIR__ . '/conexion.php'];
$conexion_cargada = false;
foreach ($rutas_posibles as $ruta) { if (file_exists($ruta)) { require_once $ruta; $conexion_cargada = true; break; } }
if (!$conexion_cargada) die("Error Crítico: No se encuentra conexion.php");

$ruta_iconos = __DIR__ . '/../app/iconos.php';
if(file_exists($ruta_iconos)) require_once $ruta_iconos;

// Seguridad Admin
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /login');
    exit;
}

// Helper Nav (Para mantener activo el sidebar)
$ruta_actual = $_SERVER['REQUEST_URI'] ?? '/';
if (!function_exists('nav_class')) {
    function nav_class($path) {
        global $ruta_actual;
        $base = 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent';
        $activo = ' bg-blue-50 text-[#54A6D8] border-blue-100'; 
        $inactivo = ' text-gray-500 hover:bg-gray-50 hover:text-gray-900';
        return $base . (strpos($ruta_actual, $path) !== false ? $activo : $inactivo);
    }
}

// --- 2. LÓGICA DE ACCIONES (POST) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion_global = $_POST['accion_global'] ?? '';
    $estado_actual = urlencode($_GET['estado'] ?? 'pendientes');

    // A. BLOQUEAR / DESBLOQUEAR USUARIO
    if ($accion_global === 'bloquear_usuario') {
        $id_usuario = intval($_POST['id_usuario']);
        $tipo_bloqueo = $_POST['tipo_bloqueo']; // 'bloquear' o 'desbloquear'
        $nuevo_estado = ($tipo_bloqueo === 'bloquear') ? 1 : 0;

        $stmt = $conn->prepare("UPDATE alumnos SET bloqueado = ? WHERE id = ? LIMIT 1");
        $stmt->bind_param("ii", $nuevo_estado, $id_usuario);
        
        if ($stmt->execute()) {
            $msg = ($nuevo_estado === 1) ? 'usuario_bloqueado' : 'usuario_desbloqueado';
            header("Location: ?estado=$estado_actual&msg=$msg");
            exit;
        }
    }

    // B. MARCAR COMO REVISADO + EMAILS
    if ($accion_global === 'marcar_revisado') {
        $id_reporte = intval($_POST['id_reporte']);
        
        // 1. Actualizar estado
        $conn->query("UPDATE reportes_servicio SET revisado=1 WHERE id=$id_reporte LIMIT 1");

        // 2. Obtener datos para emails
        $stmt = $conn->prepare("
            SELECT r.*, s.titulo AS titulo_servicio, s.id AS servicio_id, s.alumno_id AS id_usuario_reportado, 
                   a.nombre AS usuario_reporta, a.correo AS correo_reporta,
                   b.nombre AS nombre_reportado, b.correo AS correo_reportado
            FROM reportes_servicio r
            JOIN servicios s ON r.servicio_id = s.id
            JOIN alumnos a ON r.usuario_id = a.id
            JOIN alumnos b ON s.alumno_id = b.id
            WHERE r.id = ? LIMIT 1
        ");
        $stmt->bind_param("i", $id_reporte);
        $stmt->execute();
        $datos = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($datos) {
            // Historial
            $admin_id = $_SESSION['usuario_id'];
            $obs = "Reporte revisado por administración.";
            $stmtHist = $conn->prepare("INSERT INTO historial_reportes_servicio (reporte_id, admin_id, usuario_reportado_id, accion, observacion) VALUES (?, ?, ?, 'revisado', ?)");
            $stmtHist->bind_param("iiis", $id_reporte, $admin_id, $datos['id_usuario_reportado'], $obs);
            $stmtHist->execute();

            // Lógica de Correos (Mantenemos tu HTML original limpiando la estructura PHP)
            require_once 'correo.php'; // Asegurar que existe

            // Email al Reportado
            $subject = 'Tu servicio fue revisado en Nubira';
            $bodyHtml = '
                <div style="max-width:480px;margin:auto;padding:32px 24px;background:#fff;border-radius:12px;font-family:sans-serif;box-shadow:0 4px 20px #0001;">
                  <h2 style="color:#2563eb;text-align:center;">Tu servicio fue revisado</h2>
                  <p>Hola <strong>' . htmlspecialchars($datos['nombre_reportado']) . '</strong>,</p>
                  <p>Tu servicio <b>' . htmlspecialchars($datos['titulo_servicio']) . '</b> fue reportado por: <span style="color:#e11d48;">' . htmlspecialchars($datos['motivo']) . '</span>.</p>
                  <p>Ha sido revisado por nuestro equipo. Si infringe políticas, se tomarán medidas.</p>
                  <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
                  <div style="font-size:12px;color:#555;text-align:center;">Equipo Nubira</div>
                </div>';
            
            enviarCorreoConRotacion($datos['correo_reportado'], $datos['nombre_reportado'], $subject, $bodyHtml);

            // Email al Reportante
            if (!empty($datos['correo_reporta'])) {
                $subject_rep = 'Tu reporte fue revisado';
                $bodyHtml_rep = '
                    <div style="max-width:480px;margin:auto;padding:32px 24px;background:#fff;border-radius:12px;font-family:sans-serif;box-shadow:0 4px 20px #0001;">
                      <h2 style="color:#2563eb;text-align:center;">¡Reporte Revisado!</h2>
                      <p>Hola <strong>' . htmlspecialchars($datos['usuario_reporta']) . '</strong>,</p>
                      <p>Hemos revisado tu reporte sobre <b>' . htmlspecialchars($datos['titulo_servicio']) . '</b>.</p>
                      <p>Gracias por ayudar a la comunidad.</p>
                      <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
                      <div style="font-size:12px;color:#555;text-align:center;">Equipo Nubira</div>
                    </div>';
                
                enviarCorreoConRotacion($datos['correo_reporta'], $datos['usuario_reporta'], $subject_rep, $bodyHtml_rep);
            }
        }

        header("Location: ?estado=$estado_actual&msg=reporte_revisado");
        exit;
    }
}

// --- 3. LÓGICA DE DATOS (GET) ---
$filtro_estado = $_GET['estado'] ?? 'pendientes';
$where = "";

// Stats Rápidos
$res_pen = $conn->query("SELECT COUNT(*) as c FROM reportes_servicio WHERE revisado=0");
$count_pen = $res_pen->fetch_assoc()['c'];

if ($filtro_estado === "pendientes") $where = "WHERE r.revisado=0";
elseif ($filtro_estado === "revisados") $where = "WHERE r.revisado=1";

$sql = "SELECT r.*, 
               s.titulo AS titulo_servicio, 
               a.nombre AS usuario_reporta, a.correo AS correo_reporta, 
               b.nombre AS usuario_reportado, b.correo AS correo_reportado, 
               b.id AS id_usuario_reportado, 
               b.bloqueado AS bloqueado_reportado
        FROM reportes_servicio r
        JOIN servicios s ON r.servicio_id = s.id
        JOIN alumnos a ON r.usuario_id = a.id
        JOIN alumnos b ON s.alumno_id = b.id
        $where
        ORDER BY r.id DESC";

$res = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>Admin Reportes | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f9fafb; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    </style>
</head>

<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden lg:ml-64 pt-20 pb-24">

    <div id="toast-container" class="fixed top-24 right-5 z-[100] space-y-3 pointer-events-none"></div>

    <?php 
    if(file_exists(__DIR__ . '/componentes/header.php')) {
        $page_title = "Gestión de Reportes"; 
        require_once __DIR__ . '/componentes/header.php'; 
    } else {
        echo '<header class="fixed top-0 left-0 right-0 h-16 bg-white/90 backdrop-blur border-b z-50 flex items-center px-6 font-bold text-[#54A6D8] lg:ml-64">Nubira Admin</header>';
    }
    
    if(file_exists(__DIR__ . '/componentes/sidebar.php')) require_once __DIR__ . '/componentes/sidebar.php';
    ?>

    <main class="max-w-[1600px] mx-auto px-4 md:px-8">

        <div class="flex flex-col md:flex-row justify-between items-end gap-4 mb-8">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-gray-900">Reportes de Servicios</h1>
                <p class="text-gray-500 mt-1">Supervisión de contenido reportado por la comunidad.</p>
            </div>

            <div class="bg-white p-1 rounded-xl border border-gray-200 shadow-sm flex gap-1">
                <a href="?estado=pendientes" class="px-4 py-2 rounded-lg text-sm font-bold transition-all <?= $filtro_estado==='pendientes' ? 'bg-red-50 text-red-600 shadow-sm' : 'text-gray-500 hover:bg-gray-50' ?>">
                    Pendientes <span class="ml-1 bg-red-100 text-red-700 px-1.5 py-0.5 rounded-full text-[10px]"><?= $count_pen ?></span>
                </a>
                <a href="?estado=revisados" class="px-4 py-2 rounded-lg text-sm font-bold transition-all <?= $filtro_estado==='revisados' ? 'bg-green-50 text-green-600 shadow-sm' : 'text-gray-500 hover:bg-gray-50' ?>">
                    Revisados
                </a>
                <a href="?estado=todos" class="px-4 py-2 rounded-lg text-sm font-bold transition-all <?= $filtro_estado==='todos' ? 'bg-blue-50 text-[#54A6D8] shadow-sm' : 'text-gray-500 hover:bg-gray-50' ?>">
                    Historial Completo
                </a>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <?php if ($res && $res->num_rows > 0): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-gray-50/80 border-b border-gray-100 text-xs font-bold text-gray-500 uppercase tracking-wider">
                                <th class="px-6 py-4">Servicio Reportado</th>
                                <th class="px-6 py-4">Motivo / Mensaje</th>
                                <th class="px-6 py-4">Reportado Por</th>
                                <th class="px-6 py-4">Usuario Reportado</th>
                                <th class="px-6 py-4">Fecha</th>
                                <th class="px-6 py-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm">
                            <?php while($r = $res->fetch_assoc()): ?>
                            <tr class="group hover:bg-gray-50 transition-colors">
                                
                                <td class="px-6 py-4">
                                    <a href="/detalle-servicio/<?= (int)$r['servicio_id'] ?>" target="_blank" class="font-bold text-[#54A6D8] hover:underline line-clamp-2 max-w-[200px]">
                                        <?= htmlspecialchars($r['titulo_servicio']) ?>
                                        <svg class="w-3 h-3 inline ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                    </a>
                                    <div class="text-xs text-gray-400 mt-1">ID: #<?= $r['servicio_id'] ?></div>
                                </td>

                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 mb-1">
                                        <?= htmlspecialchars($r['motivo']) ?>
                                    </span>
                                    <?php if($r['mensaje']): ?>
                                        <div class="text-xs text-gray-600 bg-gray-50 p-2 rounded border border-gray-100 mt-1 max-w-[250px] italic">
                                            "<?= htmlspecialchars(substr($r['mensaje'], 0, 100)) ?>..."
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-900"><?= htmlspecialchars($r['usuario_reporta']) ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($r['correo_reporta']) ?></div>
                                </td>

                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <div>
                                            <div class="font-medium text-gray-900"><?= htmlspecialchars($r['usuario_reportado']) ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($r['correo_reportado']) ?></div>
                                        </div>
                                        <?php if($r['bloqueado_reportado']): ?>
                                            <span class="text-[10px] bg-red-600 text-white px-2 py-0.5 rounded-full font-bold">BLOQUEADO</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <form method="POST" class="mt-2">
                                        <input type="hidden" name="accion_global" value="bloquear_usuario">
                                        <input type="hidden" name="id_usuario" value="<?= $r['id_usuario_reportado'] ?>">
                                        <input type="hidden" name="tipo_bloqueo" value="<?= $r['bloqueado_reportado'] ? 'desbloquear' : 'bloquear' ?>">
                                        
                                        <?php if(!$r['bloqueado_reportado']): ?>
                                            <button type="submit" onclick="return confirm('¿Bloquear acceso a este usuario?')" class="text-xs font-semibold text-red-500 hover:text-red-700 flex items-center gap-1">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                                Bloquear Usuario
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" onclick="return confirm('¿Desbloquear usuario?')" class="text-xs font-semibold text-green-600 hover:text-green-800 flex items-center gap-1">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                                                Desbloquear
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?= date('d M Y', strtotime($r['fecha'])) ?></div>
                                    <div class="text-xs text-gray-400"><?= date('H:i', strtotime($r['fecha'])) ?></div>
                                </td>

                                <td class="px-6 py-4 text-right">
                                    <?php if(!$r['revisado']): ?>
                                        <form method="POST">
                                            <input type="hidden" name="accion_global" value="marcar_revisado">
                                            <input type="hidden" name="id_reporte" value="<?= $r['id'] ?>">
                                            <button type="submit" onclick="return confirm('¿Marcar como revisado y notificar a los usuarios?')" 
                                                    class="bg-green-50 text-green-700 hover:bg-green-100 border border-green-200 font-bold py-2 px-4 rounded-xl text-xs transition-all shadow-sm flex items-center gap-2 ml-auto">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                Marcar Revisado
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-3 py-1 bg-gray-100 text-gray-500 rounded-lg text-xs font-bold border border-gray-200 cursor-default">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            Revisado
                                        </span>
                                    <?php endif; ?>
                                </td>

                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="p-16 text-center">
                    <div class="inline-flex bg-gray-50 p-4 rounded-full mb-4 text-gray-300">
                        <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                    <p class="text-gray-500 font-medium">No hay reportes <?= htmlspecialchars($filtro_estado) ?>.</p>
                    <a href="?estado=" class="text-sm text-[#54A6D8] font-bold mt-2 hover:underline">Ver todo el historial</a>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <?php 
    if(file_exists(__DIR__ . '/componentes/nav_bottom.php')) require_once __DIR__ . '/componentes/nav_bottom.php'; 
    if(file_exists(__DIR__ . '/componentes/modal_publicar.php')) require_once __DIR__ . '/componentes/modal_publicar.php'; 
    ?>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const params = new URLSearchParams(window.location.search);
            const msg = params.get('msg');
            if (msg) {
                let text = 'Operación realizada.';
                let type = 'success';
                
                if(msg === 'usuario_bloqueado') text = 'Usuario bloqueado correctamente.';
                if(msg === 'usuario_desbloqueado') text = 'Usuario desbloqueado.';
                if(msg === 'reporte_revisado') text = 'Reporte marcado como revisado y correos enviados.';

                showToast(text, type);
                
                // Limpiar URL
                const newUrl = window.location.pathname + (params.get('estado') ? '?estado='+params.get('estado') : '');
                window.history.replaceState({}, document.title, newUrl);
            }
        });

        function showToast(text, type) {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            const colors = type === 'success' ? 'bg-white border-green-500 text-green-800' : 'bg-white border-red-500 text-red-800';
            const icon = type === 'success' ? '✅' : '⚠️';
            
            toast.className = `pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg border-l-4 transform translate-x-full transition-all duration-300 ${colors} min-w-[300px] bg-white`;
            toast.innerHTML = `<span class="text-xl">${icon}</span><span class="font-medium text-sm">${text}</span>`;
            
            container.appendChild(toast);
            requestAnimationFrame(() => toast.classList.remove('translate-x-full'));
            setTimeout(() => {
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }
    </script>

</body>
</html>