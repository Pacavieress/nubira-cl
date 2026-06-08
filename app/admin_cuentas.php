<?php
/**
 * NUBIRA 2.0 - ADMIN: REVISIÓN DE CUENTAS BANCARIAS
 */

// 1. DETECCIÓN INTELIGENTE DE RUTA
if (file_exists(__DIR__ . '/init_sesion.php')) {
    require_once __DIR__ . '/init_sesion.php';
    $app_dir = __DIR__; 
} else {
    require_once __DIR__ . '/app/init_sesion.php';
    $app_dir = __DIR__ . '/app';
}

require_once $app_dir . '/iconos.php';

// 2. CANDADO ESTRICTO DE SESIÓN
if (function_exists('proteger_ruta')) {
    proteger_ruta(); 
} else {
    die("Error de seguridad: No se pudo cargar el control de sesión.");
}

// 3. CONEXIÓN A LA BASE DE DATOS
if (!isset($conn)) require_once $app_dir . '/conexion.php';

// 4. OBTENER LOS DATOS
$mostrar_todos = ($_GET['mostrar_todos'] ?? '') === '1';

$where = $mostrar_todos ? '' : 'WHERE a.bloqueado = 0 AND a.visible = 1';
$sql = "SELECT a.id AS ID_Usuario, a.nombre AS Nombre, a.correo AS Correo,
               a.bloqueado, a.visible, d.fecha_registro AS Fecha_Configuracion
        FROM alumnos a
        INNER JOIN datos_pago_usuario d ON a.id = d.usuario_id
        $where
        ORDER BY d.id DESC";

$resultado = $conn->query($sql);
$total_registros = $resultado->num_rows;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Admin - Cuentas Bancarias | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0, viewport-fit=cover" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #F9FAFB; }
  </style>
</head>

<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden selection:bg-blue-100 selection:text-blue-700">

<?php 
// Cargamos la navegación de Nubira
require_once $app_dir . '/componentes/header.php'; 
require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-20 pb-32 md:pb-12 lg:ml-64 px-4 max-w-[1100px] mx-auto md:px-8">

    <div class="mb-6 flex flex-col sm:flex-row sm:items-end justify-between gap-4">
        <div>
            <span class="inline-block py-1 px-3 rounded-full bg-blue-50 text-[#54A6D8] text-[10px] md:text-xs font-bold mb-2 border border-blue-100">
                🛡️ Panel de Administración
            </span>
            <h1 class="text-3xl font-bold text-gray-900 tracking-tight">Cuentas Bancarias Configuradas</h1>
            <p class="text-gray-500 text-sm mt-1">
                <?php if ($mostrar_todos): ?>
                    Total de cuentas registradas: <strong><?= $total_registros ?></strong> <span class="text-xs text-gray-400">(incluye suspendidos y eliminados)</span>
                <?php else: ?>
                    Total de usuarios listos para recibir pagos: <strong><?= $total_registros ?></strong>
                <?php endif; ?>
            </p>
        </div>
        <div class="shrink-0">
            <?php $url_toggle = $mostrar_todos ? '/admin/cuentas-bancarias' : '/admin/cuentas-bancarias?mostrar_todos=1'; ?>
            <a href="<?= $url_toggle ?>"
               class="inline-flex items-center gap-2.5 px-4 py-2 rounded-xl border text-xs font-bold transition-colors
                      <?= $mostrar_todos ? 'bg-slate-800 text-white border-slate-800 hover:bg-slate-700' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
                <span class="w-8 h-4 rounded-full relative flex items-center transition-colors <?= $mostrar_todos ? 'bg-[#54A6D8]' : 'bg-slate-200' ?>">
                    <span class="w-3 h-3 rounded-full bg-white absolute shadow-sm transition-all <?= $mostrar_todos ? 'left-[18px]' : 'left-[2px]' ?>"></span>
                </span>
                Mostrar suspendidos y eliminados
            </a>
        </div>
    </div>

    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-xs uppercase text-gray-500 tracking-wide">
                        <th class="p-4 font-semibold">ID</th>
                        <th class="p-4 font-semibold">Estudiante</th>
                        <th class="p-4 font-semibold">Correo Institucional</th>
                        <th class="p-4 font-semibold text-right">Fecha de Registro</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    
                    <?php if ($total_registros > 0): ?>
                        <?php while($fila = $resultado->fetch_assoc()):
                            $is_eliminado = (int)($fila['visible']   ?? 1) === 0;
                            $is_bloqueado = (int)($fila['bloqueado'] ?? 0) === 1;
                        ?>
                            <tr class="hover:bg-blue-50/50 transition-colors <?= ($is_eliminado || $is_bloqueado) ? 'opacity-60' : '' ?>">
                                <td class="p-4 text-sm font-bold text-gray-900">
                                    #<?= htmlspecialchars($fila['ID_Usuario']) ?>
                                </td>
                                <td class="p-4">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($fila['Nombre']) ?></span>
                                        <?php if ($is_eliminado): ?>
                                            <span class="inline-flex items-center bg-red-50 text-red-600 px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest border border-red-100">Eliminado</span>
                                        <?php elseif ($is_bloqueado): ?>
                                            <span class="inline-flex items-center bg-red-50 text-red-500 px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest border border-red-100">Suspendido</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="p-4">
                                    <div class="text-sm text-gray-500"><?= htmlspecialchars($fila['Correo']) ?></div>
                                </td>
                                <td class="p-4 text-right">
                                    <div class="text-sm text-gray-500">
                                        <?= date('d/m/Y', strtotime($fila['Fecha_Configuracion'])) ?>
                                        <span class="text-xs text-gray-400 block"><?= date('H:i', strtotime($fila['Fecha_Configuracion'])) ?> hrs</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="p-8 text-center text-gray-500 text-sm">
                                Aún no hay usuarios con datos bancarios registrados.
                            </td>
                        </tr>
                    <?php endif; ?>

                </tbody>
            </table>
        </div>
    </div>

</main>

<?php 
if(file_exists($app_dir . '/componentes/nav_bottom.php')) require_once $app_dir . '/componentes/nav_bottom.php'; 
if(file_exists($app_dir . '/componentes/modal_publicar.php')) require_once $app_dir . '/componentes/modal_publicar.php'; 
if(file_exists($app_dir . '/componentes/modal_explora.php')) require_once $app_dir . '/componentes/modal_explora.php'; 
?>



<?php 
$rutas_footer = [
    $app_dir . '/includes/footer.php',
    __DIR__ . '/includes/footer.php',
    $_SERVER['DOCUMENT_ROOT'] . '/app/includes/footer.php',
    $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'
];
foreach ($rutas_footer as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        break;
    }
}
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