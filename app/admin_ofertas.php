<?php
/**
 * VISTA ADMIN: GESTOR DE SUBSIDIOS Y OFERTAS (NUBIRA 2.0)
 * OBJETIVO: Control centralizado del producto gancho (Loss Leader)
 */
session_start();

// 1. CONFIGURACIÓN Y SEGURIDAD ESTRICTA (Solo Admins)
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') { 
    header('Location: /login'); 
    exit; 
}

// Buscador de rutas robusto Nubira 2.0
$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    $app_dir = dirname(__DIR__) . '/app';
    if (!file_exists($app_dir . '/conexion.php')) {
        $app_dir = $_SERVER['DOCUMENT_ROOT'] . '/app';
    }
}
require_once $app_dir . '/conexion.php';

// Helper Iconos (Fallback si no existe)
if (file_exists($app_dir . '/iconos.php')) {
    require_once $app_dir . '/iconos.php';
} else {
    if (!function_exists('icon')) { function icon($n, $c=''){ return "<i class='fa-solid fa-$n $c'></i>"; } }
}

$mensaje = '';

// 2. MOTOR CRUD: PROCESAR FORMULARIOS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Validar CSRF básico
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $mensaje = '<div class="bg-red-50 text-red-600 p-4 rounded-xl mb-6 text-sm font-bold border border-red-200">Error de seguridad: Token inválido.</div>';
    } else {
        $servicio_id = (int)($_POST['servicio_id'] ?? 0);

        if ($_POST['action'] === 'aplicar_oferta') {
            $precio_oferta = (int)$_POST['precio_oferta'];
            $cupos = (int)$_POST['cupos'];
            
            if ($servicio_id > 0 && $precio_oferta >= 0 && $cupos > 0) {
                $stmt = $conn->prepare("UPDATE servicios SET precio_oferta = ?, cupos_oferta = ?, is_subvencionado = 1 WHERE id = ?");
                $stmt->bind_param("iii", $precio_oferta, $cupos, $servicio_id);
                if ($stmt->execute()) {
                    $mensaje = '<div class="bg-green-50 text-green-700 p-4 rounded-xl mb-6 text-sm font-bold border border-green-200 flex items-center gap-2"><i class="fa-solid fa-circle-check"></i> Oferta inyectada con éxito.</div>';
                }
                $stmt->close();
            } else {
                $mensaje = '<div class="bg-red-50 text-red-600 p-4 rounded-xl mb-6 text-sm font-bold border border-red-200">Datos inválidos. Verifica el precio y los cupos.</div>';
            }
        } 
        
        elseif ($_POST['action'] === 'quitar_oferta') {
            if ($servicio_id > 0) {
                $stmt = $conn->prepare("UPDATE servicios SET precio_oferta = NULL, cupos_oferta = 0, is_subvencionado = 0 WHERE id = ?");
                $stmt->bind_param("i", $servicio_id);
                if ($stmt->execute()) {
                    $mensaje = '<div class="bg-gray-100 text-gray-700 p-4 rounded-xl mb-6 text-sm font-bold border border-gray-200 flex items-center gap-2"><i class="fa-solid fa-power-off"></i> Oferta desactivada. El servicio volvió a su estado normal.</div>';
                }
                $stmt->close();
            }
        }
    }
}

// Generar Token CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf_token'];

// 3. OBTENER SERVICIOS
// Traemos los servicios activos. Primero los que tienen oferta, luego los más recientes.
$servicios = [];
$res = $conn->query("SELECT s.id, s.titulo, s.precio, s.precio_oferta, s.cupos_oferta, s.is_subvencionado, a.nombre AS tutor_nombre 
                     FROM servicios s 
                     JOIN alumnos a ON s.alumno_id = a.id 
                     WHERE s.estado = 'aprobado' 
                     ORDER BY s.is_subvencionado DESC, s.id DESC 
                     LIMIT 50");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $servicios[] = $row;
    }
}

$page_title = "Admin: Subsidios";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title ?> | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f9fafb; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        /* Ocultar flechas de input number */
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden">

<?php 
// Intentar cargar componentes de UI si existen en el entorno admin
if(file_exists($app_dir . '/componentes/header.php')) require_once $app_dir . '/componentes/header.php'; 
if(file_exists($app_dir . '/componentes/sidebar.php')) require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-24 pb-32 md:pb-16 lg:ml-64 px-4 md:px-8">
    <div class="w-full max-w-[1400px] mx-auto">
        
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
            <div>
                <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">Centro de Subsidios Nubira</h1>
                <p class="text-sm text-gray-500 mt-1 font-medium">Inyecta capital estratégico para potenciar tutores y atraer alumnos.</p>
            </div>
            <div class="bg-white px-4 py-2 rounded-xl border border-gray-200 shadow-sm flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-orange-100 flex items-center justify-center text-orange-500">
                    <i class="fa-solid fa-fire"></i>
                </div>
                <div>
                    <p class="text-[10px] uppercase font-bold text-gray-400">Ofertas Activas</p>
                    <p class="text-lg font-black text-gray-900 leading-none">
                        <?= count(array_filter($servicios, fn($s) => $s['is_subvencionado'] == 1)) ?>
                    </p>
                </div>
            </div>
        </div>

        <?= $mensaje ?>

        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm whitespace-nowrap">
                    <thead class="bg-gray-50/80 text-gray-500 text-xs uppercase font-bold tracking-wider border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">Servicio y Tutor</th>
                            <th class="px-6 py-4">Tarifa Normal</th>
                            <th class="px-6 py-4">Estado Actual</th>
                            <th class="px-6 py-4 text-right">Panel de Control</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php foreach($servicios as $s): ?>
                        <tr class="hover:bg-gray-50/50 transition-colors <?= $s['is_subvencionado'] ? 'bg-orange-50/10' : '' ?>">
                            
                            <td class="px-6 py-4">
                                <p class="font-bold text-gray-900 line-clamp-1 max-w-[300px] whitespace-normal leading-tight">
                                    <?= htmlspecialchars($s['titulo']) ?>
                                </p>
                                <p class="text-xs text-gray-500 mt-0.5">Por <?= htmlspecialchars($s['tutor_nombre']) ?></p>
                            </td>
                            
                            <td class="px-6 py-4 font-bold text-gray-600">
                                $<?= number_format($s['precio'], 0, ',', '.') ?>
                            </td>
                            
                            <td class="px-6 py-4">
                                <?php if($s['is_subvencionado'] && $s['cupos_oferta'] > 0): ?>
                                    <div class="inline-flex flex-col gap-1">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-wide bg-gradient-to-r from-orange-400 to-orange-500 text-white shadow-sm shadow-orange-200">
                                            <i class="fa-solid fa-bolt"></i> Subvencionado
                                        </span>
                                        <span class="text-xs font-bold text-[#54A6D8]">$<?= number_format($s['precio_oferta'], 0, ',', '.') ?> <span class="text-gray-400 font-medium">| Quedan <?= $s['cupos_oferta'] ?> cupos</span></span>
                                    </div>
                                <?php elseif($s['is_subvencionado'] && $s['cupos_oferta'] <= 0): ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wide bg-red-100 text-red-600">
                                        Agotado
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wide bg-gray-100 text-gray-500">
                                        Normal
                                    </span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="px-6 py-4 text-right">
                                <?php if($s['is_subvencionado']): ?>
                                    <form method="POST" class="inline-block" onsubmit="return confirm('¿Seguro que deseas retirar el subsidio y devolver el servicio a su precio normal?');">
                                        <input type="hidden" name="csrf_token" value="<?= $CSRF ?>">
                                        <input type="hidden" name="action" value="quitar_oferta">
                                        <input type="hidden" name="servicio_id" value="<?= $s['id'] ?>">
                                        <button type="submit" class="text-red-500 hover:text-white hover:bg-red-500 border border-red-200 hover:border-red-500 font-bold text-xs px-4 py-2 rounded-xl transition-all shadow-sm">
                                            Apagar Oferta
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" class="flex items-center justify-end gap-2">
                                        <input type="hidden" name="csrf_token" value="<?= $CSRF ?>">
                                        <input type="hidden" name="action" value="aplicar_oferta">
                                        <input type="hidden" name="servicio_id" value="<?= $s['id'] ?>">
                                        
                                        <div class="relative">
                                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 font-bold text-xs">$</span>
                                            <input type="number" name="precio_oferta" placeholder="0" required min="0" max="<?= $s['precio'] - 1 ?>"
                                                   class="w-24 pl-6 pr-3 py-2 text-sm font-bold text-gray-900 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent transition-all shadow-sm"
                                                   title="Precio subsidiado">
                                        </div>
                                        
                                        <div class="relative">
                                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"><i class="fa-solid fa-users"></i></span>
                                            <input type="number" name="cupos" placeholder="Cupos" required min="1"
                                                   class="w-24 pl-8 pr-3 py-2 text-sm font-bold text-gray-900 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent transition-all shadow-sm"
                                                   title="Cantidad de usos">
                                        </div>

                                        <button type="submit" class="bg-gradient-to-r from-sky-400 to-[#54A6D8] text-white px-4 py-2 rounded-xl text-xs font-bold shadow-md hover:shadow-lg hover:shadow-blue-200 transform hover:scale-[1.02] active:scale-95 transition-all">
                                            Inyectar
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if(empty($servicios)): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-gray-400 font-medium">
                                No hay servicios aprobados en la plataforma aún.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    </div>
</main>

<?php 
if(file_exists($app_dir . '/componentes/nav_bottom.php')) require_once $app_dir . '/componentes/nav_bottom.php'; 
if(file_exists($app_dir . '/componentes/modal_publicar.php')) require_once $app_dir . '/componentes/modal_publicar.php'; 
if(file_exists($app_dir . '/componentes/modal_explora.php')) require_once $app_dir . '/componentes/modal_explora.php'; 
?>

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

    document.addEventListener('DOMContentLoaded', () => {
        NubiraModales.setup('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
        NubiraModales.setup('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
    });
</script>
</body>
</html>