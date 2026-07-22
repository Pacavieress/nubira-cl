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

// 2.5 CANDADO DE ROL ADMIN
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header("Location: /login"); exit;
}

// 3. CONEXIÓN A LA BASE DE DATOS
if (!isset($conn)) require_once $app_dir . '/conexion.php';

// 4. OBTENER LOS DATOS
$mostrar_todos = ($_GET['mostrar_todos'] ?? '') === '1';

$where = $mostrar_todos ? '' : 'WHERE a.bloqueado = 0 AND a.visible = 1';
$sql = "SELECT a.id AS ID_Usuario, a.nombre AS Nombre, a.correo AS Correo,
               a.bloqueado, a.visible,
               d.banco, d.tipo_cuenta, d.numero_cuenta, d.titular_nombre, d.rut,
               d.fecha_registro AS Fecha_Configuracion
        FROM alumnos a
        INNER JOIN datos_pago_usuario d ON a.id = d.usuario_id
        $where
        ORDER BY d.id DESC";

$resultado = $conn->query($sql);
$filas = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
$total_registros = count($filas);

// Bancos únicos (para el dropdown de filtro)
$bancos_unicos = array_values(array_unique(array_filter(array_map(function($f){ return $f['banco']; }, $filas))));
sort($bancos_unicos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Admin - Cuentas Bancarias | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0, viewport-fit=cover" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
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

    <!-- Barra de controles: buscar + filtros -->
    <?php if ($total_registros > 0): ?>
    <div class="flex flex-col md:flex-row gap-3 mb-4">
        <div class="relative flex-1">
            <input id="cta-buscar" type="search" placeholder="Buscar por nombre, correo, RUT o banco…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"><?= icon('search','w-4 h-4') ?></span>
        </div>
        <select id="cta-filtro-banco" class="px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
            <option value="">Todos los bancos</option>
            <?php foreach($bancos_unicos as $b): ?><option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option><?php endforeach; ?>
        </select>
        <select id="cta-filtro-tipo" class="px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
            <option value="">Todos los tipos</option>
            <option value="Cuenta Corriente">Cuenta Corriente</option>
            <option value="Cuenta Vista">Cuenta Vista</option>
            <option value="Cuenta Rut">Cuenta Rut</option>
        </select>
    </div>
    <?php endif; ?>

    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-xs uppercase text-gray-500 tracking-wide">
                        <th class="p-4 w-10"></th>
                        <th class="p-4 font-semibold cursor-pointer select-none" data-sort="nombre">Estudiante <span class="cta-ind text-gray-300"></span></th>
                        <th class="p-4 font-semibold">Correo</th>
                        <th class="p-4 font-semibold cursor-pointer select-none" data-sort="banco">Banco <span class="cta-ind text-gray-300"></span></th>
                        <th class="p-4 font-semibold text-right cursor-pointer select-none" data-sort="fecha">Fecha <span class="cta-ind text-gray-300"></span></th>
                    </tr>
                </thead>
                <tbody id="cta-tbody" class="divide-y divide-gray-100">

                    <?php if ($total_registros > 0): ?>
                        <?php foreach($filas as $i => $f):
                            $is_eliminado = (int)($f['visible']   ?? 1) === 0;
                            $is_bloqueado = (int)($f['bloqueado'] ?? 0) === 1;
                            $ts = strtotime($f['Fecha_Configuracion']);
                        ?>
                            <tr class="cta-row hover:bg-blue-50/50 transition-colors <?= ($is_eliminado || $is_bloqueado) ? 'opacity-60' : '' ?>"
                                data-nombre="<?= htmlspecialchars(mb_strtolower($f['Nombre'], 'UTF-8')) ?>"
                                data-correo="<?= htmlspecialchars(mb_strtolower($f['Correo'], 'UTF-8')) ?>"
                                data-rut="<?= htmlspecialchars(mb_strtolower($f['rut'], 'UTF-8')) ?>"
                                data-banco="<?= htmlspecialchars($f['banco']) ?>"
                                data-tipo="<?= htmlspecialchars($f['tipo_cuenta']) ?>"
                                data-fecha="<?= (int)$ts ?>">
                                <td class="p-4 align-top">
                                    <button type="button" class="cta-toggle w-7 h-7 rounded-full bg-gray-50 border border-gray-200 flex items-center justify-center text-gray-500 hover:bg-gray-100 transition" aria-label="Ver detalle">
                                        <?= icon('chevron-down', 'w-4 h-4 transition-transform cta-chevron') ?>
                                    </button>
                                </td>
                                <td class="p-4">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($f['Nombre']) ?></span>
                                        <?php if ($is_eliminado): ?>
                                            <span class="inline-flex items-center bg-red-50 text-red-600 px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest border border-red-100">Eliminado</span>
                                        <?php elseif ($is_bloqueado): ?>
                                            <span class="inline-flex items-center bg-red-50 text-red-500 px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest border border-red-100">Suspendido</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="p-4"><div class="text-sm text-gray-500"><?= htmlspecialchars($f['Correo']) ?></div></td>
                                <td class="p-4"><div class="text-sm text-gray-700"><?= htmlspecialchars($f['banco']) ?></div></td>
                                <td class="p-4 text-right">
                                    <div class="text-sm text-gray-500">
                                        <?= date('d/m/Y', $ts) ?>
                                        <span class="text-xs text-gray-400 block"><?= date('H:i', $ts) ?> hrs</span>
                                    </div>
                                </td>
                            </tr>
                            <tr class="cta-detail hidden bg-gray-50/70">
                                <td colspan="5" class="px-6 py-4">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-2 text-sm max-w-2xl">
                                        <div><span class="text-gray-400">Banco:</span> <span class="font-medium text-gray-800"><?= htmlspecialchars($f['banco']) ?></span></div>
                                        <div><span class="text-gray-400">Tipo de cuenta:</span> <span class="font-medium text-gray-800"><?= htmlspecialchars($f['tipo_cuenta']) ?></span></div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-gray-400">N° cuenta:</span>
                                            <span class="font-mono font-semibold text-gray-900"><?= htmlspecialchars($f['numero_cuenta']) ?></span>
                                            <button type="button" class="cta-copy text-gray-400 hover:text-[#54A6D8] transition" data-copy="<?= htmlspecialchars($f['numero_cuenta']) ?>" title="Copiar número"><?= icon('copy','w-4 h-4') ?></button>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-gray-400">RUT:</span>
                                            <span class="font-mono font-semibold text-gray-900"><?= htmlspecialchars($f['rut']) ?></span>
                                            <button type="button" class="cta-copy text-gray-400 hover:text-[#54A6D8] transition" data-copy="<?= htmlspecialchars($f['rut']) ?>" title="Copiar RUT"><?= icon('copy','w-4 h-4') ?></button>
                                        </div>
                                        <div><span class="text-gray-400">Titular:</span> <span class="font-medium text-gray-800"><?= htmlspecialchars($f['titular_nombre']) ?></span></div>
                                        <div><span class="text-gray-400">Registrado:</span> <span class="font-medium text-gray-800"><?= date('d/m/Y H:i', $ts) ?> hrs</span></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="cta-sin-resultados" class="hidden"><td colspan="5" class="p-8 text-center text-gray-400 text-sm">No hay coincidencias con tu búsqueda.</td></tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="p-8 text-center text-gray-500 text-sm">
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
// === Cuentas bancarias: expandir/colapsar + copiar + buscar + filtrar + ordenar ===
(function(){
    const tbody = document.getElementById('cta-tbody');
    if(!tbody) return;
    const sinResultados = document.getElementById('cta-sin-resultados');
    const pares = () => [...tbody.querySelectorAll('.cta-row')].map(r => ({ row: r, det: r.nextElementSibling }));

    // Expandir / colapsar (chevron rota)
    tbody.addEventListener('click', (e) => {
        const btn = e.target.closest('.cta-toggle');
        if(!btn) return;
        const row = btn.closest('.cta-row');
        const det = row.nextElementSibling;
        if(det && det.classList.contains('cta-detail')) det.classList.toggle('hidden');
        const chev = btn.querySelector('.cta-chevron');
        if(chev) chev.classList.toggle('rotate-180');
    });

    // Copiar (con fallback execCommand para contextos sin clipboard API)
    tbody.addEventListener('click', async (e) => {
        const b = e.target.closest('.cta-copy');
        if(!b) return;
        const txt = b.dataset.copy || '';
        try { await navigator.clipboard.writeText(txt); }
        catch(_) { const t=document.createElement('textarea'); t.value=txt; t.style.position='fixed'; t.style.opacity='0'; document.body.appendChild(t); t.select(); try{document.execCommand('copy');}catch(e){} t.remove(); }
        b.classList.add('text-green-600'); b.classList.remove('text-gray-400');
        setTimeout(()=>{ b.classList.remove('text-green-600'); b.classList.add('text-gray-400'); }, 1200);
    });

    // Buscar + filtrar en vivo
    const inputBuscar = document.getElementById('cta-buscar');
    const selBanco = document.getElementById('cta-filtro-banco');
    const selTipo = document.getElementById('cta-filtro-tipo');
    function aplicarFiltros(){
        const q = (inputBuscar?.value || '').trim().toLowerCase();
        const banco = selBanco?.value || '';
        const tipo = selTipo?.value || '';
        let visibles = 0;
        pares().forEach(({row, det}) => {
            const match = (!q || row.dataset.nombre.includes(q) || row.dataset.correo.includes(q) || row.dataset.rut.includes(q) || row.dataset.banco.toLowerCase().includes(q))
                       && (!banco || row.dataset.banco === banco)
                       && (!tipo  || row.dataset.tipo === tipo);
            row.classList.toggle('hidden', !match);
            if(!match && det) det.classList.add('hidden');
            if(match) visibles++;
        });
        if(sinResultados) sinResultados.classList.toggle('hidden', visibles !== 0);
    }
    [inputBuscar, selBanco, selTipo].forEach(el => el && el.addEventListener('input', aplicarFiltros));

    // Ordenar por header (mueve el par fila+detalle junto)
    const sortDir = {};
    document.querySelectorAll('th[data-sort]').forEach(th => {
        th.addEventListener('click', () => {
            const k = th.dataset.sort;
            const dir = sortDir[k] = (sortDir[k] === 'asc' ? 'desc' : 'asc');
            document.querySelectorAll('th[data-sort] .cta-ind').forEach(s => s.textContent = '');
            const ind = th.querySelector('.cta-ind'); if(ind) ind.textContent = dir === 'asc' ? '▲' : '▼';
            const arr = pares().sort((a, b) => {
                let va, vb;
                if(k === 'fecha'){ va = +a.row.dataset.fecha; vb = +b.row.dataset.fecha; }
                else { va = a.row.dataset[k] || ''; vb = b.row.dataset[k] || ''; }
                return (va < vb ? -1 : va > vb ? 1 : 0) * (dir === 'asc' ? 1 : -1);
            });
            arr.forEach(({row, det}) => { tbody.appendChild(row); if(det) tbody.appendChild(det); });
            if(sinResultados) tbody.appendChild(sinResultados);
        });
    });
})();
</script>

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