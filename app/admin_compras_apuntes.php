<?php
/**
 * VISTA ADMIN: COMPRAS DE APUNTES
 * OBJETIVO: Visibilidad total de transacciones de apuntes (solo lectura)
 */
session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header('Location: /login');
    exit;
}

$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    $app_dir = dirname(__DIR__) . '/app';
    if (!file_exists($app_dir . '/conexion.php')) {
        $app_dir = $_SERVER['DOCUMENT_ROOT'] . '/app';
    }
}
require_once $app_dir . '/conexion.php';
if (file_exists($app_dir . '/iconos.php')) {
    require_once $app_dir . '/iconos.php';
} else {
    if (!function_exists('icon')) { function icon($n, $c = '') { return "<i class='fa-solid fa-$n $c'></i>"; } }
}

// Marca como vistas todas las compras pendientes de revisión del admin (badge del panel
// de gestión). Mismo filtro que contar_alertas_sistema.php (precio > 0), no toca la
// columna 'revisado' (esa es la notificación propia del vendedor, semántica distinta).
$conn->query("UPDATE ventas_apuntes SET revisado_por_admin = 1 WHERE precio > 0 AND revisado_por_admin = 0");

// ── Filtros ────────────────────────────────────────────────────────────────
$q_apunte    = trim($_GET['q_apunte']    ?? '');
$q_comprador = trim($_GET['q_comprador'] ?? '');
$q_vendedor  = trim($_GET['q_vendedor']  ?? '');
$estado_pago = $_GET['estado_pago'] ?? '';   // '' | '1' | '0'
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';

if (!in_array($estado_pago, ['', '0', '1'], true)) $estado_pago = '';

// ── Ordenamiento ───────────────────────────────────────────────────────────
$orden_param = $_GET['orden'] ?? 'mayor_monto';
$orden_map = [
    'mayor_monto' => 'total_monto DESC',
    'mas_ventas'  => 'total_ventas DESC, total_monto DESC',
    'recientes'   => 'ultima_venta DESC',
    'menor_monto' => 'total_monto ASC',
    'alfabetico'  => 'vend.nombre ASC',
];
$orden_sql = $orden_map[$orden_param] ?? $orden_map['mayor_monto'];

// ── WHERE dinámico ─────────────────────────────────────────────────────────
$conditions = [];
$bind_types = '';
$bind_vals  = [];

if ($q_apunte !== '') {
    $conditions[] = 'a.titulo LIKE ?';
    $bind_types  .= 's';
    $bind_vals[]  = '%' . $q_apunte . '%';
}
if ($q_comprador !== '') {
    $conditions[] = 'comp.correo LIKE ?';
    $bind_types  .= 's';
    $bind_vals[]  = '%' . $q_comprador . '%';
}
if ($q_vendedor !== '') {
    $conditions[] = 'vend.correo LIKE ?';
    $bind_types  .= 's';
    $bind_vals[]  = '%' . $q_vendedor . '%';
}
if ($estado_pago !== '') {
    $conditions[] = 'va.pagado_al_vendedor = ?';
    $bind_types  .= 'i';
    $bind_vals[]  = (int)$estado_pago;
}
if ($fecha_desde !== '') {
    $conditions[] = 'DATE(va.fecha) >= ?';
    $bind_types  .= 's';
    $bind_vals[]  = $fecha_desde;
}
if ($fecha_hasta !== '') {
    $conditions[] = 'DATE(va.fecha) <= ?';
    $bind_types  .= 's';
    $bind_vals[]  = $fecha_hasta;
}

$where_sql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// ── KPIs ───────────────────────────────────────────────────────────────────
$total_compras = 0;
$total_monto   = 0;
$total_tutores = 0;

$sql_kpi = "
    SELECT
        COUNT(va.id)                  AS total,
        COALESCE(SUM(va.precio), 0)   AS suma,
        COUNT(DISTINCT va.vendedor_id) AS total_tutores
    FROM ventas_apuntes va
    JOIN apuntes  a    ON va.apunte_id    = a.id
    JOIN alumnos  comp ON va.comprador_id = comp.id
    JOIN alumnos  vend ON va.vendedor_id  = vend.id
    {$where_sql}";

$stmt_kpi = $conn->prepare($sql_kpi);
if ($stmt_kpi) {
    if ($bind_types) $stmt_kpi->bind_param($bind_types, ...$bind_vals);
    $stmt_kpi->execute();
    $row_kpi       = $stmt_kpi->get_result()->fetch_assoc();
    $total_compras = (int)$row_kpi['total'];
    $total_monto   = (int)$row_kpi['suma'];
    $total_tutores = (int)$row_kpi['total_tutores'];
    $stmt_kpi->close();
}

// ── Desincronización compras ↔ ventas_apuntes ──────────────────────────────
$desync = 0;
$stmtSync = $conn->prepare("
    SELECT COUNT(*) AS total FROM compras c
    WHERE c.id_apunte > 0
      AND c.estado_pago = 'pagado'
      AND NOT EXISTS (
          SELECT 1 FROM ventas_apuntes va
          WHERE va.apunte_id   = c.id_apunte
            AND va.comprador_id = c.usuario_id
      )
");
if ($stmtSync) {
    $stmtSync->execute();
    $desync = (int)$stmtSync->get_result()->fetch_assoc()['total'];
    $stmtSync->close();
}

// ── Query A: agrupada por tutor ────────────────────────────────────────────
$tutores = [];

$sql_grupo = "
    SELECT
        va.vendedor_id,
        vend.nombre                        AS vendedor_nombre,
        vend.correo                        AS vendedor_correo,
        COUNT(va.id)                       AS total_ventas,
        COALESCE(SUM(va.precio), 0)        AS total_monto,
        MAX(va.fecha)                      AS ultima_venta,
        SUM(va.pagado_al_vendedor = 1)     AS pagadas,
        SUM(va.pagado_al_vendedor = 0)     AS pendientes
    FROM ventas_apuntes va
    JOIN apuntes  a    ON va.apunte_id    = a.id
    JOIN alumnos  comp ON va.comprador_id = comp.id
    JOIN alumnos  vend ON va.vendedor_id  = vend.id
    {$where_sql}
    GROUP BY va.vendedor_id, vend.nombre, vend.correo
    ORDER BY {$orden_sql}";

$stmt_grupo = $conn->prepare($sql_grupo);
if ($stmt_grupo) {
    if ($bind_types) $stmt_grupo->bind_param($bind_types, ...$bind_vals);
    $stmt_grupo->execute();
    $res = $stmt_grupo->get_result();
    while ($row = $res->fetch_assoc()) $tutores[] = $row;
    $stmt_grupo->close();
}

// ── Query B: detalle individual (agrupado en PHP por vendedor_id) ──────────
$detalle_por_tutor = [];

$sql_detalle = "
    SELECT
        va.id,
        va.vendedor_id,
        va.fecha,
        a.titulo       AS apunte_titulo,
        a.asignatura,
        comp.nombre    AS comprador_nombre,
        comp.correo    AS comprador_correo,
        va.precio,
        va.pagado_al_vendedor,
        (SELECT c.payment_id
         FROM   compras c
         WHERE  c.id_apunte   = va.apunte_id
           AND  c.usuario_id  = va.comprador_id
           AND  c.estado_pago = 'pagado'
         ORDER BY c.id DESC LIMIT 1) AS payment_id
    FROM ventas_apuntes va
    JOIN apuntes  a    ON va.apunte_id    = a.id
    JOIN alumnos  comp ON va.comprador_id = comp.id
    JOIN alumnos  vend ON va.vendedor_id  = vend.id
    {$where_sql}
    ORDER BY va.vendedor_id ASC, va.fecha DESC
    LIMIT 1000";

$stmt_det = $conn->prepare($sql_detalle);
if ($stmt_det) {
    if ($bind_types) $stmt_det->bind_param($bind_types, ...$bind_vals);
    $stmt_det->execute();
    $res = $stmt_det->get_result();
    while ($row = $res->fetch_assoc()) {
        $detalle_por_tutor[(int)$row['vendedor_id']][] = $row;
    }
    $stmt_det->close();
}

$hay_filtros = ($q_apunte || $q_comprador || $q_vendedor || $estado_pago !== '' || $fecha_desde || $fecha_hasta);

$page_title = "Admin: Compras de Apuntes";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title ?> | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f9fafb; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .detalle-panel { max-height: 0; overflow: hidden; transition: max-height 0.28s ease-out; }
        .detalle-panel.open { max-height: 9999px; transition: max-height 0.4s ease-in; }
        .tutor-row { cursor: pointer; }
        .toggle-icon { transition: transform 0.2s ease; display: inline-block; }
        .tutor-row.open .toggle-icon { transform: rotate(45deg); }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden">

<?php
if (file_exists($app_dir . '/componentes/header.php'))  require_once $app_dir . '/componentes/header.php';
if (file_exists($app_dir . '/componentes/sidebar.php')) require_once $app_dir . '/componentes/sidebar.php';
?>

<main class="pt-24 pb-32 md:pb-16 lg:ml-64 px-4 md:px-8">
    <div class="w-full max-w-[1400px] mx-auto">

        <!-- Encabezado + KPIs -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
            <div>
                <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">Compras de Apuntes</h1>
                <p class="text-sm text-gray-500 mt-1 font-medium">Todas las transacciones de apuntes en la plataforma.</p>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                <div class="bg-white px-4 py-2 rounded-xl border border-gray-200 shadow-sm flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-blue-50 flex items-center justify-center text-[#54A6D8]">
                        <i class="fa-solid fa-receipt text-sm"></i>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-gray-400">Total Compras</p>
                        <p class="text-lg font-black text-gray-900 leading-none"><?= number_format($total_compras, 0, ',', '.') ?></p>
                    </div>
                </div>
                <div class="bg-white px-4 py-2 rounded-xl border border-gray-200 shadow-sm flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-green-50 flex items-center justify-center text-green-600">
                        <i class="fa-solid fa-circle-dollar-to-slot text-sm"></i>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-gray-400">Monto Total</p>
                        <p class="text-lg font-black text-gray-900 leading-none">$<?= number_format($total_monto, 0, ',', '.') ?></p>
                    </div>
                </div>
                <div class="bg-white px-4 py-2 rounded-xl border border-gray-200 shadow-sm flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-purple-50 flex items-center justify-center text-purple-500">
                        <i class="fa-solid fa-chalkboard-user text-sm"></i>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-gray-400">Tutores con Ventas</p>
                        <p class="text-lg font-black text-gray-900 leading-none"><?= number_format($total_tutores, 0, ',', '.') ?></p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($desync > 0): ?>
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700 font-semibold flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <?= $desync ?> compra<?= $desync > 1 ? 's' : '' ?> confirmada<?= $desync > 1 ? 's' : '' ?> sin registro en ventas_apuntes.
        </div>
        <?php endif; ?>

        <!-- Filtros -->
        <form method="GET" class="bg-white border border-gray-100 rounded-2xl shadow-sm p-4 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">

                <div>
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wide mb-1">Título del apunte</label>
                    <input type="text" name="q_apunte" value="<?= htmlspecialchars($q_apunte) ?>"
                           placeholder="Buscar por título..."
                           class="w-full text-sm text-gray-700 border border-gray-200 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[#54A6D8] bg-white">
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wide mb-1">Correo comprador</label>
                    <input type="text" name="q_comprador" value="<?= htmlspecialchars($q_comprador) ?>"
                           placeholder="comprador@correo.cl"
                           class="w-full text-sm text-gray-700 border border-gray-200 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[#54A6D8] bg-white">
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wide mb-1">Correo vendedor</label>
                    <input type="text" name="q_vendedor" value="<?= htmlspecialchars($q_vendedor) ?>"
                           placeholder="vendedor@correo.cl"
                           class="w-full text-sm text-gray-700 border border-gray-200 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[#54A6D8] bg-white">
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wide mb-1">Estado pago al vendedor</label>
                    <select name="estado_pago"
                            class="w-full text-sm text-gray-700 border border-gray-200 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[#54A6D8] bg-white">
                        <option value=""  <?= $estado_pago === ''  ? 'selected' : '' ?>>Todos</option>
                        <option value="1" <?= $estado_pago === '1' ? 'selected' : '' ?>>Pagado al vendedor</option>
                        <option value="0" <?= $estado_pago === '0' ? 'selected' : '' ?>>Pendiente</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wide mb-1">Desde</label>
                    <input type="date" name="fecha_desde" value="<?= htmlspecialchars($fecha_desde) ?>"
                           class="w-full text-sm text-gray-700 border border-gray-200 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[#54A6D8] bg-white">
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wide mb-1">Hasta</label>
                    <input type="date" name="fecha_hasta" value="<?= htmlspecialchars($fecha_hasta) ?>"
                           class="w-full text-sm text-gray-700 border border-gray-200 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[#54A6D8] bg-white">
                </div>

            </div>

            <div class="flex items-center justify-between mt-4 gap-3 flex-wrap">
                <div class="flex items-center gap-2">
                    <label class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Ordenar por</label>
                    <select name="orden"
                            class="text-sm font-medium text-gray-700 border border-gray-200 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[#54A6D8] bg-white shadow-sm cursor-pointer">
                        <option value="mayor_monto" <?= $orden_param === 'mayor_monto' ? 'selected' : '' ?>>Mayor monto vendido</option>
                        <option value="mas_ventas"  <?= $orden_param === 'mas_ventas'  ? 'selected' : '' ?>>Más ventas</option>
                        <option value="recientes"   <?= $orden_param === 'recientes'   ? 'selected' : '' ?>>Más recientes</option>
                        <option value="menor_monto" <?= $orden_param === 'menor_monto' ? 'selected' : '' ?>>Menor monto vendido</option>
                        <option value="alfabetico"  <?= $orden_param === 'alfabetico'  ? 'selected' : '' ?>>Alfabético por tutor</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <?php if ($q_apunte || $q_comprador || $q_vendedor || $estado_pago !== '' || $fecha_desde || $fecha_hasta): ?>
                        <a href="/app/admin_compras_apuntes.php"
                           class="text-sm text-gray-400 hover:text-gray-600 font-medium transition-colors">
                            Limpiar filtros
                        </a>
                    <?php endif; ?>
                    <button type="submit"
                            class="bg-gradient-to-r from-sky-400 to-[#54A6D8] text-white px-5 py-2 rounded-xl text-sm font-bold shadow-md hover:shadow-lg hover:shadow-blue-200 transform hover:scale-[1.02] active:scale-95 transition-all">
                        Aplicar
                    </button>
                </div>
            </div>
        </form>

        <!-- Acordeón por tutor -->
        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden divide-y divide-gray-50">

            <?php if (empty($tutores)): ?>
            <div class="px-6 py-16 text-center">
                <div class="flex flex-col items-center gap-3 text-gray-400">
                    <div class="w-14 h-14 rounded-2xl bg-gray-50 border border-dashed border-gray-200 flex items-center justify-center">
                        <i class="fa-regular fa-folder-open text-2xl text-gray-300"></i>
                    </div>
                    <?php if ($hay_filtros): ?>
                        <p class="font-semibold text-sm text-gray-500">Ningún tutor coincide con los filtros aplicados.</p>
                        <p class="text-xs">Prueba ampliando o quitando algún filtro.</p>
                    <?php else: ?>
                        <p class="font-semibold text-sm text-gray-500">Aún no hay compras de apuntes en la plataforma.</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php else: foreach ($tutores as $t):
                $vid = (int)$t['vendedor_id'];
                $det = $detalle_por_tutor[$vid] ?? [];
            ?>

            <!-- Fila tutor (header del acordeón) -->
            <div class="tutor-row flex items-center gap-4 px-6 py-4 hover:bg-gray-50/60 transition-colors select-none"
                 data-target="det-<?= $vid ?>">

                <!-- Botón +/− -->
                <div class="w-7 h-7 rounded-lg bg-gray-100 flex items-center justify-center shrink-0">
                    <span class="toggle-icon text-gray-500 font-bold text-base leading-none">+</span>
                </div>

                <!-- Nombre + correo -->
                <div class="min-w-0 flex-1">
                    <p class="font-bold text-gray-900 text-sm leading-tight truncate"><?= htmlspecialchars($t['vendedor_nombre']) ?></p>
                    <p class="text-[11px] text-gray-400 truncate"><?= htmlspecialchars($t['vendedor_correo']) ?></p>
                </div>

                <!-- Stats -->
                <div class="hidden md:flex items-center gap-6 shrink-0 text-right">
                    <div>
                        <p class="text-[10px] uppercase font-bold text-gray-400 tracking-wide">Ventas</p>
                        <p class="text-sm font-black text-gray-800"><?= $t['total_ventas'] ?></p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-gray-400 tracking-wide">Total</p>
                        <p class="text-sm font-black text-gray-800">$<?= number_format((int)$t['total_monto'], 0, ',', '.') ?></p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase font-bold text-gray-400 tracking-wide">Estado</p>
                        <?php if ((int)$t['pendientes'] === 0): ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide bg-green-50 text-green-700">
                                <?= $t['pagadas'] ?>/<?= $t['total_ventas'] ?> pagadas
                            </span>
                        <?php elseif ((int)$t['pagadas'] === 0): ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide bg-yellow-50 text-yellow-700">
                                0/<?= $t['total_ventas'] ?> pagadas
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide bg-blue-50 text-[#54A6D8]">
                                <?= $t['pagadas'] ?>/<?= $t['total_ventas'] ?> pagadas
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Stats móvil -->
                <div class="flex md:hidden items-center gap-3 shrink-0 text-right">
                    <p class="text-sm font-black text-gray-800">$<?= number_format((int)$t['total_monto'], 0, ',', '.') ?></p>
                    <p class="text-xs text-gray-400"><?= $t['total_ventas'] ?> ventas</p>
                </div>

            </div>

            <!-- Panel detalle (colapsado por defecto) -->
            <div id="det-<?= $vid ?>" class="detalle-panel">
                <div class="overflow-x-auto no-scrollbar bg-gray-50/40 border-t border-gray-100">
                    <table class="w-full text-left text-xs whitespace-nowrap">
                        <thead class="text-gray-400 font-bold uppercase tracking-wider border-b border-gray-100">
                            <tr>
                                <th class="px-6 py-3">#</th>
                                <th class="px-6 py-3">Fecha</th>
                                <th class="px-6 py-3">Apunte</th>
                                <th class="px-6 py-3">Comprador</th>
                                <th class="px-6 py-3">Monto</th>
                                <th class="px-6 py-3">Estado</th>
                                <th class="px-6 py-3">Payment ID</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($det as $d): ?>
                            <tr class="hover:bg-white/70 transition-colors">

                                <td class="px-6 py-3 text-gray-400 font-mono"><?= $d['id'] ?></td>

                                <td class="px-6 py-3">
                                    <p class="font-semibold text-gray-700"><?= date('d/m/Y', strtotime($d['fecha'])) ?></p>
                                    <p class="text-[10px] text-gray-400"><?= date('H:i', strtotime($d['fecha'])) ?></p>
                                </td>

                                <td class="px-6 py-3 max-w-[240px]">
                                    <p class="font-bold text-gray-800 whitespace-normal leading-tight line-clamp-2"><?= htmlspecialchars($d['apunte_titulo']) ?></p>
                                    <?php if (!empty($d['asignatura'])): ?>
                                        <p class="text-[10px] text-gray-400 mt-0.5"><?= htmlspecialchars($d['asignatura']) ?></p>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-3">
                                    <p class="font-semibold text-gray-700"><?= htmlspecialchars($d['comprador_nombre']) ?></p>
                                    <p class="text-[10px] text-gray-400"><?= htmlspecialchars($d['comprador_correo']) ?></p>
                                </td>

                                <td class="px-6 py-3 font-bold text-gray-700">
                                    $<?= number_format((int)$d['precio'], 0, ',', '.') ?>
                                </td>

                                <td class="px-6 py-3">
                                    <?php if ($d['pagado_al_vendedor']): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide bg-green-50 text-green-700">Pagado</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide bg-yellow-50 text-yellow-700">Pendiente</span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-3 font-mono text-[10px] text-gray-400 max-w-[160px] truncate"
                                    title="<?= htmlspecialchars($d['payment_id'] ?? '') ?>">
                                    <?= !empty($d['payment_id']) ? htmlspecialchars($d['payment_id']) : '<span class="text-gray-300">—</span>' ?>
                                </td>

                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php endforeach; endif; ?>

        </div>

        <?php if (count($detalle_por_tutor) > 0 && array_sum(array_map('count', $detalle_por_tutor)) >= 1000): ?>
        <div class="mt-3 px-4 py-3 bg-yellow-50 border border-yellow-100 rounded-xl text-xs text-yellow-700 font-medium">
            Se muestran los primeros 1.000 registros de detalle. Usa los filtros para acotar la búsqueda.
        </div>
        <?php endif; ?>

    </div>
</main>

<?php
if (file_exists($app_dir . '/componentes/nav_bottom.php'))     require_once $app_dir . '/componentes/nav_bottom.php';
if (file_exists($app_dir . '/componentes/modal_publicar.php')) require_once $app_dir . '/componentes/modal_publicar.php';
if (file_exists($app_dir . '/componentes/modal_explora.php'))  require_once $app_dir . '/componentes/modal_explora.php';
?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Acordeón de tutores
    document.querySelectorAll('.tutor-row').forEach(row => {
        row.addEventListener('click', () => {
            const panel = document.getElementById(row.dataset.target);
            if (!panel) return;
            const isOpen = panel.classList.contains('open');
            panel.classList.toggle('open', !isOpen);
            row.classList.toggle('open', !isOpen);
        });
    });

    // Modales del nav
    if (typeof NubiraModales !== 'undefined') {
        NubiraModales.setup('btn-publicar', 'modal-quick',   'quick-card',   'quick-close');
        NubiraModales.setup('btn-explora',  'modal-explora', 'explora-card', 'explora-close');
    }
});
</script>
</body>
</html>
