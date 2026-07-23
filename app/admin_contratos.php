<?php
/**
 * VISTA ADMINISTRACIÓN: CONTRATOS
 * ARQUITECTURA: NUBIRA 2.0 (Final con Notificaciones UX + Enlace Chat)
 */

// --- 1. CONFIGURACIÓN ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

$rutas_posibles = [__DIR__ . '/../app/conexion.php', __DIR__ . '/conexion.php'];
$conexion_cargada = false;
foreach ($rutas_posibles as $ruta) { if (file_exists($ruta)) { require_once $ruta; $conexion_cargada = true; break; } }
if (!$conexion_cargada) die("Error Crítico: No se encuentra conexion.php");

$ruta_iconos = __DIR__ . '/../app/iconos.php';
if(file_exists($ruta_iconos)) require_once $ruta_iconos;

// Seguridad
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') { header("Location: /login"); exit; }

// Helper Nav
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

// --- 2. DATOS ---
$estado = $_GET['estado'] ?? '';
$estados_validos = ['pendiente_pago','en_progreso','liberado','cancelado'];
$filtro_sql = ""; $params = [];

if (in_array($estado, $estados_validos, true)) {
    $filtro_sql = "WHERE c.estado = ?";
    $params[] = $estado;
}

// Stats
$stats = [];
foreach ($estados_validos as $e) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM contratos WHERE estado = ?");
        $stmt->bind_param("s", $e);
        $stmt->execute();
        $stats[$e] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    } catch (Exception $ex) {}
}
$total = array_sum($stats);

// Consulta Principal (Agregado: conversacion_id)
$sql = "SELECT c.id, c.estado, c.monto, c.fecha_creacion, c.fecha_estimada, c.fecha_cierre, c.conversacion_id,
               COALESCE(s.titulo, '[Servicio Eliminado]') AS servicio_titulo,
               COALESCE(comp.nombre, '[Usuario Eliminado]') AS comprador_nombre,
               COALESCE(vend.nombre, '[Usuario Eliminado]') AS vendedor_nombre
        FROM contratos c
        LEFT JOIN servicios s ON c.servicio_id = s.id
        LEFT JOIN alumnos comp ON c.comprador_id = comp.id
        LEFT JOIN alumnos vend ON c.vendedor_id = vend.id
        $filtro_sql
        ORDER BY c.fecha_creacion DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param("s", $params[0]);
$stmt->execute();
$res = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>Admin Contratos | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #ffffff; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    </style>
</head>

<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden">

    <div id="toast-container" class="fixed top-24 right-5 z-[100] space-y-3 pointer-events-none"></div>

    <?php 
    if(file_exists(__DIR__ . '/componentes/header.php')) {
        $page_title = "Admin Contratos"; 
        require_once __DIR__ . '/componentes/header.php'; 
    } else {
        echo '<header class="fixed top-0 w-full h-16 bg-white border-b z-50 flex items-center px-4 font-bold text-[#54A6D8]">Nubira Admin</header>';
    }
    
    if(file_exists(__DIR__ . '/componentes/sidebar.php')) require_once __DIR__ . '/componentes/sidebar.php';
    ?>

    <main class="pt-20 pb-28 md:pb-10 lg:ml-64 px-4 max-w-full mx-auto overflow-hidden md:px-10">

        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8 max-w-[1600px] mx-auto">
            <div>
                <h1 class="text-2xl md:text-3xl font-extrabold text-gray-900 tracking-tight">Administración de Contratos</h1>
                <p class="text-sm text-gray-500 mt-1">Gestión financiera y resolución de disputas.</p>
            </div>
            
            <form method="GET" class="flex bg-white px-1 py-1 rounded-xl shadow-sm border border-gray-200 items-center">
                <span class="pl-3 text-sm text-gray-400 font-medium">Filtro:</span>
                <select name="estado" onchange="this.form.submit()" class="bg-transparent text-sm font-bold text-gray-700 py-2 pl-2 pr-8 border-none focus:ring-0 cursor-pointer outline-none">
                    <option value="">Todos</option>
                    <option value="pendiente_pago" <?= $estado==='pendiente_pago'?'selected':'' ?>>Pendiente Pago</option>
                    <option value="en_progreso" <?= $estado==='en_progreso'?'selected':'' ?>>En Progreso</option>
                    <option value="liberado" <?= $estado==='liberado'?'selected':'' ?>>Liberados</option>
                    <option value="cancelado" <?= $estado==='cancelado'?'selected':'' ?>>Cancelados</option>
                </select>
            </form>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-8 max-w-[1600px] mx-auto">
            <a href="?" class="bg-white p-4 rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-all group">
                <div class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Total</div>
                <div class="text-2xl font-bold text-gray-900 group-hover:text-[#54A6D8]"><?= $total ?></div>
            </a>
            <a href="?estado=pendiente_pago" class="bg-white p-4 rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-all group <?= $estado==='pendiente_pago'?'ring-2 ring-yellow-400':'' ?>">
                <div class="text-xs font-bold text-yellow-600 uppercase tracking-wider mb-1">Pendientes</div>
                <div class="text-2xl font-bold text-gray-900"><?= $stats['pendiente_pago'] ?></div>
            </a>
            <a href="?estado=en_progreso" class="bg-white p-4 rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-all group <?= $estado==='en_progreso'?'ring-2 ring-sky-400':'' ?>">
                <div class="text-xs font-bold text-[#54A6D8] uppercase tracking-wider mb-1">En Curso</div>
                <div class="text-2xl font-bold text-gray-900"><?= $stats['en_progreso'] ?></div>
            </a>
            <a href="?estado=liberado" class="bg-white p-4 rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-all group <?= $estado==='liberado'?'ring-2 ring-green-400':'' ?>">
                <div class="text-xs font-bold text-green-600 uppercase tracking-wider mb-1">Liberados</div>
                <div class="text-2xl font-bold text-gray-900"><?= $stats['liberado'] ?></div>
            </a>
            <a href="?estado=cancelado" class="bg-white p-4 rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-all group <?= $estado==='cancelado'?'ring-2 ring-red-400':'' ?>">
                <div class="text-xs font-bold text-red-500 uppercase tracking-wider mb-1">Conflictos</div>
                <div class="text-2xl font-bold text-gray-900"><?= $stats['cancelado'] ?></div>
            </a>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden max-w-[1600px] mx-auto">
            <?php if ($res->num_rows === 0): ?>
                <div class="p-16 text-center">
                    <div class="inline-flex bg-gray-50 p-4 rounded-full mb-4 text-gray-300">
                        <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    </div>
                    <p class="text-gray-500 font-medium">No se encontraron contratos.</p>
                    <a href="?" class="text-sm text-[#54A6D8] font-bold mt-2 hover:underline">Ver todos los contratos</a>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-100 text-xs font-bold text-gray-500 uppercase tracking-wider">
                                <th class="px-6 py-4">ID / Fecha</th>
                                <th class="px-6 py-4">Servicio</th>
                                <th class="px-6 py-4">Involucrados</th>
                                <th class="px-6 py-4">Monto</th>
                                <th class="px-6 py-4">Estado</th>
                                <th class="px-6 py-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm">
                            <?php while($c = $res->fetch_assoc()): 
                                $st = $c['estado'];
                                $colores = [
                                    'pendiente_pago' => 'bg-yellow-50 text-yellow-700 border border-yellow-200',
                                    'en_progreso'    => 'bg-sky-50 text-[#54A6D8] border border-sky-200',
                                    'liberado'       => 'bg-green-50 text-green-700 border border-green-200',
                                    'cancelado'      => 'bg-red-50 text-red-700 border border-red-200',
                                ];
                                $badge = $colores[$st] ?? 'bg-gray-50 text-gray-600';
                                $chatId = (int)($c['conversacion_id'] ?? 0);
                            ?>
                            <tr class="group hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="font-mono text-gray-400 text-xs">#<?= $c['id'] ?></div>
                                    <div class="text-xs font-medium text-gray-500 mt-0.5"><?= date('d M', strtotime($c['fecha_creacion'])) ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-bold text-gray-900 line-clamp-1 max-w-[200px]"><?= htmlspecialchars($c['servicio_titulo']) ?></div>
                                    <?php if($chatId > 0): ?>
                                        <a href="/admin/chats?id=<?= $chatId ?>" target="_blank" class="text-[10px] text-purple-500 font-bold hover:underline flex items-center gap-1 mt-1">
                                            <i class="fa-solid fa-comments"></i> Ver Chat
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-col gap-1 text-xs">
                                        <div class="flex items-center gap-2">
                                            <span class="w-1.5 h-1.5 rounded-full bg-blue-400"></span> 
                                            <span class="text-gray-600">C: <b><?= htmlspecialchars($c['comprador_nombre']) ?></b></span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="w-1.5 h-1.5 rounded-full bg-orange-400"></span> 
                                            <span class="text-gray-600">V: <?= htmlspecialchars($c['vendedor_nombre']) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-bold text-gray-900">
                                    $<?= number_format($c['monto'], 0, ',', '.') ?>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold <?= $badge ?>">
                                        <?= ucfirst(str_replace('_',' ', $st)) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex justify-end items-center gap-2">
                                        <button onclick="abrirModal(<?= htmlspecialchars(json_encode($c)) ?>)" class="p-2 text-gray-400 hover:text-[#54A6D8] hover:bg-sky-50 rounded-lg transition-all" title="Ver detalle">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                        </button>

                                        <?php if($st === 'en_progreso'): ?>
                                            <a href="/app/liberar_contrato.php?id=<?= $c['id'] ?>" onclick="return confirm('¿CONFIRMAR? Se liberará el dinero al vendedor.')" class="p-2 text-green-500 hover:bg-green-50 rounded-lg transition-all" title="Liberar fondos">
                                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                            </a>
                                            <a href="/app/cancelar_contrato.php?id=<?= $c['id'] ?>" onclick="return confirm('¿CONFIRMAR? Se cancelará y reembolsará al comprador.')" class="p-2 text-red-400 hover:bg-red-50 rounded-lg transition-all" title="Cancelar contrato">
                                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if($st === 'liberado' || $st === 'cancelado'): ?>
                                             <a href="/app/revertir_contrato.php?id=<?= $c['id'] ?>" onclick="return confirm('¿Revertir estado a EN PROGRESO?')" class="p-2 text-orange-400 hover:bg-orange-50 rounded-lg transition-all" title="Revertir estado">
                                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" /></svg>
                                             </a>
                                        <?php endif; ?>

                                        <span class="w-px h-5 bg-gray-200 mx-1"></span>

                                        <a href="/app/eliminar_contrato.php?id=<?= $c['id'] ?>" onclick="return confirm('⚠️ ALERTA: Esto borrará el contrato para SIEMPRE.')" class="p-2 text-gray-300 hover:bg-red-50 hover:text-red-600 rounded-lg transition-all" title="Eliminar definitivamente">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

  <?php 
    if(file_exists(__DIR__ . '/componentes/nav_bottom.php')) require_once __DIR__ . '/componentes/nav_bottom.php'; 
    if(file_exists(__DIR__ . '/componentes/modal_publicar.php')) require_once __DIR__ . '/componentes/modal_publicar.php'; 
    if(file_exists(__DIR__ . '/componentes/modal_explora.php')) require_once __DIR__ . '/componentes/modal_explora.php'; 
    ?>

    <div id="modal-detalle" class="fixed inset-0 z-[70] hidden" aria-hidden="true">
        <div class="absolute inset-0 bg-gray-900/40 backdrop-blur-sm transition-opacity opacity-0" id="m-backdrop"></div>
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white w-full max-w-md rounded-3xl shadow-2xl transform scale-95 opacity-0 transition-all duration-300 relative overflow-hidden" id="m-card">
                <div class="bg-gradient-to-r from-sky-50 to-white px-6 py-4 border-b border-gray-100 flex justify-between items-center">
                    <h3 class="font-bold text-gray-800 text-lg">Detalle Contrato</h3>
                    <button onclick="cerrarModal()" class="w-8 h-8 flex items-center justify-center bg-white rounded-full shadow-sm text-gray-400 hover:text-red-500 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <div class="p-6 space-y-5">
                    <div class="text-center">
                        <div class="inline-block px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider mb-2" id="m-badge"></div>
                        <h2 class="text-3xl font-extrabold text-[#54A6D8] tracking-tight" id="m-monto"></h2>
                        <p class="text-gray-500 text-sm mt-1" id="m-servicio"></p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-4 border border-gray-100 space-y-3 text-sm">
                        <div class="flex justify-between border-b border-gray-200/50 pb-2">
                            <span class="text-gray-500 font-medium">ID Referencia</span>
                            <span class="font-mono font-bold text-gray-700" id="m-id"></span>
                        </div>
                        <div class="flex justify-between border-b border-gray-200/50 pb-2">
                            <span class="text-gray-500 font-medium">Comprador</span>
                            <span class="font-bold text-gray-900" id="m-comprador"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500 font-medium">Vendedor</span>
                            <span class="font-bold text-gray-900" id="m-vendedor"></span>
                        </div>
                    </div>
                    <a href="#" id="btn-aula" class="block w-full py-3.5 bg-[#54A6D8] hover:bg-[#4a95c5] text-white text-center rounded-xl font-bold shadow-md shadow-blue-200 transition-transform active:scale-95">Ir al Aula Virtual</a>
                </div>
            </div>
        </div>
    </div>

    <script>
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

        // 1. Inicialización Principal (Modales y Toasts)
        document.addEventListener('DOMContentLoaded', () => {
            // Modales del Nav
            NubiraModales.setup('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
            NubiraModales.setup('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
            
            // Lógica existente de Toasts (Alertas Flotantes)
            const params = new URLSearchParams(window.location.search);
            const msg = params.get('msg');
            const error = params.get('error');

            if (msg) showToast(getMsgText(msg), 'success');
            if (error) showToast(getErrorText(error), 'error');

            // Limpiar URL para no repetir mensaje al recargar
            if (msg || error) {
                const newUrl = window.location.pathname + (params.get('estado') ? '?estado='+params.get('estado') : '');
                window.history.replaceState({}, document.title, newUrl);
            }
        });

        // 2. Funciones de Toasts
        function showToast(text, type) {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            const colors = type === 'success' ? 'bg-white border-green-500 text-green-800' : 'bg-white border-red-500 text-red-800';
            const icon = type === 'success' ? '✅' : '❌';
            
            toast.className = `pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg border-l-4 transform translate-x-full transition-all duration-300 ${colors} min-w-[300px]`;
            toast.innerHTML = `<span class="text-xl">${icon}</span><span class="font-medium text-sm">${text}</span>`;
            
            container.appendChild(toast);

            // Animación Entrada
            requestAnimationFrame(() => { toast.classList.remove('translate-x-full'); });

            // Animación Salida
            setTimeout(() => {
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        function getMsgText(code) {
            const msgs = {
                'eliminado_ok': 'Contrato eliminado correctamente.',
                'liberado_ok': 'Fondos liberados al vendedor.',
                'cancelado_ok': 'Contrato cancelado y reembolsado.',
                'revertido_ok': 'Estado revertido a En Progreso.'
            };
            return msgs[code] || 'Operación realizada con éxito.';
        }

        function getErrorText(code) {
            const errs = {
                'sql_error': 'Error de base de datos.',
                'id_invalido': 'ID de contrato no válido.',
                'no_admin': 'No tienes permisos.'
            };
            return errs[code] || 'Ocurrió un error inesperado.';
        }

        // 3. Loader
        window.onload = () => { const l = document.getElementById('loader'); if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } };
        
        // 4. Modales de Detalles
        const modal = document.getElementById('modal-detalle');
        const backdrop = document.getElementById('m-backdrop');
        const card = document.getElementById('m-card');

        function abrirModal(data) {
            document.getElementById('m-id').innerText = '#' + data.id;
            document.getElementById('m-servicio').innerText = data.servicio_titulo;
            document.getElementById('m-comprador').innerText = data.comprador_nombre;
            document.getElementById('m-vendedor').innerText = data.vendedor_nombre;
            document.getElementById('m-monto').innerText = '$' + new Intl.NumberFormat('es-CL').format(data.monto);
            document.getElementById('btn-aula').href = '/app/mini_aula.php?id=' + data.id;
            
            const badge = document.getElementById('m-badge');
            badge.innerText = data.estado.replace('_', ' ');
            badge.className = 'inline-block px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider mb-2 ';
            if(data.estado === 'en_progreso') badge.classList.add('bg-sky-100', 'text-sky-700');
            else if(data.estado === 'pendiente_pago') badge.classList.add('bg-yellow-100', 'text-yellow-700');
            else if(data.estado === 'liberado') badge.classList.add('bg-green-100', 'text-green-700');
            else badge.classList.add('bg-red-100', 'text-red-700');

            modal.classList.remove('hidden');
            setTimeout(() => {
                backdrop.classList.remove('opacity-0');
                card.classList.remove('scale-95', 'opacity-0');
                card.classList.add('scale-100', 'opacity-100');
            }, 10);
        }

        function cerrarModal() {
            backdrop.classList.add('opacity-0');
            card.classList.remove('scale-100', 'opacity-100');
            card.classList.add('scale-95', 'opacity-0');
            setTimeout(() => { modal.classList.add('hidden'); }, 300);
        }
    </script>
</body>
</html>
