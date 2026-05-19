<?php
/**
 * VISTA: MIS CONTRATOS
 * UBICACIÓN: public_html/app/mis_contratos.php
 */
session_start();

// 1. SEGURIDAD Y RUTAS
if (!isset($_SESSION['usuario_id'])) { header('Location: /login'); exit; }

$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists($app_dir . '/app/conexion.php')) $app_dir = $app_dir . '/app';
    elseif (file_exists(dirname($app_dir) . '/app')) $app_dir = dirname($app_dir) . '/app';
}
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

// 2. DATOS DE SESIÓN
$uid            = (int)$_SESSION['usuario_id'];
$nombre_usuario = $_SESSION['usuario_nombre'] ?? 'Usuario';
$rol            = $_SESSION['rol'] ?? 'alumno';

// Header Vars
$institucion_session = strtolower(trim($_SESSION['institucion'] ?? ''));
$nombres_inst = ['uc'=>'UC','aiep'=>'AIEP','uss'=>'USS','udp'=>'UDP'];
$nombre_institucion = $nombres_inst[$institucion_session] ?? ucfirst($institucion_session);
$display_carrera = $_SESSION['carrera'] ?? 'Estudiante';

// 3. CONSULTA COMPRAS (Soy Alumno)
$stmt = $conn->prepare("
  SELECT c.id, c.estado, c.monto, c.fecha_creacion, c.fecha_estimada,
         r.fecha_clase, r.duracion_minutos,
         s.titulo AS servicio_titulo, s.imagen, s.categoria,
         v.nombre AS vendedor_nombre
  FROM contratos c
  JOIN servicios s ON c.servicio_id = s.id
  JOIN alumnos v  ON c.vendedor_id = v.id
  LEFT JOIN reservas_slots r ON r.contrato_id = c.id
  WHERE c.comprador_id = ?
  ORDER BY COALESCE(r.fecha_clase, c.fecha_estimada, c.fecha_creacion) ASC
");
$stmt->bind_param("i", $uid);
$stmt->execute();
$res_compras = $stmt->get_result();

// 4. CONSULTA VENTAS (Soy Profesor)
$stmt = $conn->prepare("
  SELECT c.id, c.estado, c.monto, c.fecha_creacion, c.fecha_estimada,
         r.fecha_clase, r.duracion_minutos,
         s.titulo AS servicio_titulo, s.imagen, s.categoria,
         a.nombre AS comprador_nombre
  FROM contratos c
  JOIN servicios s ON c.servicio_id = s.id
  JOIN alumnos a  ON c.comprador_id = a.id
  LEFT JOIN reservas_slots r ON r.contrato_id = c.id
  WHERE c.vendedor_id = ?
  ORDER BY COALESCE(r.fecha_clase, c.fecha_estimada, c.fecha_creacion) ASC
");
$stmt->bind_param("i", $uid);
$stmt->execute();
$res_ventas = $stmt->get_result();

// Helper Estado
function get_estilo_estado($estado) {
    switch ($estado) {
        case 'activo':
        case 'en_progreso':
            return ['bg'=>'bg-green-50 border-green-100', 'text'=>'text-green-700', 'icon'=>'check-circle', 'label'=>'En Curso'];
        case 'pendiente_pago':
            return ['bg'=>'bg-yellow-50 border-yellow-100', 'text'=>'text-yellow-700', 'icon'=>'cart', 'label'=>'Pendiente Pago'];
        case 'finalizado':
        case 'finalizado_vendedor':
        case 'finalizado_comprador':
        case 'liberado':
            return ['bg'=>'bg-blue-50 border-blue-100', 'text'=>'text-blue-700', 'icon'=>'check-circle', 'label'=>'Finalizado'];
        case 'cancelado':
            return ['bg'=>'bg-red-50 border-red-100', 'text'=>'text-red-700', 'icon'=>'x', 'label'=>'Cancelado'];
        default:
            return ['bg'=>'bg-gray-50 border-gray-100', 'text'=>'text-gray-500', 'icon'=>'info', 'label'=>ucfirst($estado)];
    }
}

/**
 * [NUBIRA 2.0] Determina el grupo temporal de una clase según su fecha
 * Devuelve: 'pasada', 'hoy', 'esta_semana', 'mas_adelante', 'sin_fecha'
 */
function get_grupo_temporal($fecha_str) {
    if (empty($fecha_str)) return 'sin_fecha';
    
    $ts = strtotime($fecha_str);
    $ahora = time();
    
    // Inicio y fin de hoy
    $inicio_hoy = strtotime('today');
    $fin_hoy = strtotime('tomorrow') - 1;
    
    // Fin de esta semana (próximo domingo a las 23:59:59)
    $fin_semana = strtotime('sunday 23:59:59');
    if ($fin_semana < $ahora) $fin_semana = strtotime('next sunday 23:59:59');
    
    if ($ts < $inicio_hoy) return 'pasada';
    if ($ts >= $inicio_hoy && $ts <= $fin_hoy) return 'hoy';
    if ($ts > $fin_hoy && $ts <= $fin_semana) return 'esta_semana';
    return 'mas_adelante';
}

/**
 * [NUBIRA 2.0] Formatea fecha de clase en texto amigable
 * Ej: "Hoy a las 14:00", "Mañana a las 10:00", "Vie 8 May, 18:00"
 */
function formatear_fecha_clase($fecha_str) {
    if (empty($fecha_str)) return null;
    
    $ts = strtotime($fecha_str);
    $hoy = strtotime('today');
    $manana = strtotime('tomorrow');
    $pasado = strtotime('+2 days', $hoy);
    
    $hora = date('H:i', $ts);
    $es_hora_legacy = ($hora === '23:59'); // Contratos viejos sin hora real
    
    if ($ts >= $hoy && $ts < $manana) return $es_hora_legacy ? 'Hoy' : "Hoy a las $hora";
    if ($ts >= $manana && $ts < $pasado) return $es_hora_legacy ? 'Mañana' : "Mañana a las $hora";
    
    $dias = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
    $meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    $base = $dias[date('w', $ts)] . ' ' . date('j', $ts) . ' ' . $meses[date('n', $ts)-1];
    return $es_hora_legacy ? $base : "$base, $hora";
}

/**
 * [NUBIRA 2.0] Calcula tiempo restante hasta la clase en formato amigable
 * Ej: "En 30 min", "En 2 hrs", "En 3 días"
 */
function tiempo_hasta_clase($fecha_str) {
    if (empty($fecha_str)) return null;
    $diff = strtotime($fecha_str) - time();
    if ($diff <= 0) return null;
    
    if ($diff < 3600) return 'En ' . max(1, round($diff / 60)) . ' min';
    if ($diff < 86400) return 'En ' . round($diff / 3600) . ' hr' . (round($diff/3600) > 1 ? 's' : '');
    return 'En ' . round($diff / 86400) . ' día' . (round($diff/86400) > 1 ? 's' : '');
}

// Helper Nav
$page_title = "Mis Contratos";
if (!function_exists('nav_class')) {
    function nav_class(string $path): string {
        $ruta_actual = $_SERVER['REQUEST_URI'] ?? '/';
        $base = 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent';
        $activo = ' bg-blue-50 text-[#54A6D8] border-blue-100';
        $inactivo = ' text-gray-500 hover:bg-gray-50 hover:text-gray-900';
        if ($path === '/dashboard') return $base . $activo; 
        return $base . $inactivo;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Mis Contratos | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    .tab-btn.active { color: #54A6D8; border-bottom: 2px solid #54A6D8; background-color: #f0f9ff; }
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
  <div class="w-full max-w-[1600px] mx-auto space-y-8">
    
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Mis Contratos</h1>
        <p class="text-gray-500 text-sm mt-0.5">Gestiona el progreso de tus clases y servicios.</p>
    </div>

    <?php
// Pre-calcular contadores de "activas" (no pasadas)
function contar_activas($result) {
    $activas = 0;
    $rows = [];
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
        $fecha_ref = $r['fecha_clase'] ?? $r['fecha_estimada'] ?? null;
        if (get_grupo_temporal($fecha_ref) !== 'pasada') $activas++;
    }
    return ['activas' => $activas, 'rows' => $rows];
}
$_compras = contar_activas($res_compras);
$_ventas  = contar_activas($res_ventas);
?>

<div class="flex border-b border-gray-200 mb-6 bg-white rounded-t-2xl overflow-hidden shadow-sm">
    <button class="tab-btn active flex-1 md:flex-none px-6 py-4 font-bold text-sm text-gray-500 hover:bg-gray-50 transition flex items-center justify-center gap-2 outline-none" data-target="compras">
        <?= icon('user', 'w-4 h-4') ?> Soy Alumno 
        <?php if ($_compras['activas'] > 0): ?>
            <span class="bg-[#54A6D8] text-white px-2 py-0.5 rounded-full text-xs ml-1 font-bold"><?= $_compras['activas'] ?></span>
        <?php endif; ?>
    </button>
    <button class="tab-btn flex-1 md:flex-none px-6 py-4 font-bold text-sm text-gray-500 hover:bg-gray-50 transition flex items-center justify-center gap-2 outline-none" data-target="ventas">
        <?= icon('publish-class', 'w-4 h-4') ?> Soy Profesor 
        <?php if ($_ventas['activas'] > 0): ?>
            <span class="bg-[#54A6D8] text-white px-2 py-0.5 rounded-full text-xs ml-1 font-bold"><?= $_ventas['activas'] ?></span>
        <?php endif; ?>
    </button>
</div>

   <?php
// =========================================================================
// [NUBIRA 2.0] Helper unificado para renderizar tarjeta de contrato
// =========================================================================
function render_card_contrato($row, $tipo_vista) {
    $est = get_estilo_estado($row['estado']);
    $img = !empty($row['imagen']) ? "/upload/servicios/".basename($row['imagen']) : null;
    
    $fecha_clase   = $row['fecha_clase'] ?? null;
    $fecha_amigable = formatear_fecha_clase($fecha_clase);
    $tiempo_restante = tiempo_hasta_clase($fecha_clase);
    $persona_label = $tipo_vista === 'comprador' ? 'con' : 'Alumno:';
    $persona_nombre = $tipo_vista === 'comprador' ? $row['vendedor_nombre'] : $row['comprador_nombre'];
    
    ob_start();
    ?>
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 flex flex-col md:flex-row gap-4 md:items-center hover:shadow-md transition group">
        
        <div class="w-full md:w-20 h-20 bg-gray-50 rounded-xl overflow-hidden shrink-0 border border-gray-100 relative flex items-center justify-center">
            <?php if($img): ?>
                <img src="<?= $img ?>" class="w-full h-full object-cover">
            <?php else: ?>
                <div class="text-gray-300"><?= icon('publish-class', 'w-7 h-7') ?></div>
            <?php endif; ?>
        </div>
        
        <div class="flex-1 w-full text-center md:text-left min-w-0">
            <h3 class="font-bold text-gray-900 text-base leading-tight mb-1 truncate">
                <?= htmlspecialchars($row['servicio_titulo']) ?>
            </h3>
            
            <p class="text-sm text-gray-500 mb-2">
                <?= $persona_label ?> <span class="font-medium text-gray-700"><?= htmlspecialchars(explode(' ', $persona_nombre)[0]) ?></span>
                <?php if ($row['monto'] > 0): ?>
                    <span class="text-gray-300 mx-1">·</span>
                    <span class="font-bold text-gray-900">$<?= number_format($row['monto'],0,',','.') ?></span>
                <?php endif; ?>
            </p>
            
            <div class="flex flex-wrap items-center gap-2 justify-center md:justify-start">
                <?php if ($fecha_amigable): ?>
                    <span class="inline-flex items-center gap-1.5 text-xs text-gray-600 font-medium">
                        <i class="fa-regular fa-calendar text-[#54A6D8] text-[11px]"></i>
                        <?= htmlspecialchars($fecha_amigable) ?>
                    </span>
                <?php endif; ?>
                
                <?php if ($tiempo_restante): ?>
                    <span class="text-gray-300">·</span>
                    <span class="text-xs text-emerald-600 font-bold">
                        <?= htmlspecialchars($tiempo_restante) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="w-full md:w-auto shrink-0">
            <?php if($tipo_vista === 'comprador' && $row['estado'] === 'pendiente_pago'): ?>
                <a href="/app/iniciar_pago_contrato.php?id_contrato=<?= $row['id'] ?>" class="flex items-center justify-center gap-2 bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-2.5 px-5 rounded-xl text-sm transition shadow-md shadow-yellow-100 w-full md:w-auto">
                    <?= icon('cart', 'w-4 h-4') ?> Pagar
                </a>
            <?php else: ?>
                <a href="/app/mini_aula.php?id=<?= $row['id'] ?>" class="flex items-center justify-center gap-2 bg-[#54A6D8] hover:bg-blue-600 text-white font-bold py-2.5 px-5 rounded-xl text-sm transition shadow-md shadow-blue-100 w-full md:w-auto">
                    Ir al Aula <i class="fa-solid fa-arrow-right text-xs"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
// =========================================================================
// [NUBIRA 2.0] Helper para agrupar contratos por bloque temporal
// =========================================================================
function agrupar_contratos_array($rows) {
    $grupos = ['hoy' => [], 'esta_semana' => [], 'mas_adelante' => [], 'sin_fecha' => [], 'pasada' => []];
    foreach ($rows as $r) {
        $fecha_ref = $r['fecha_clase'] ?? $r['fecha_estimada'] ?? null;
        $grupo = get_grupo_temporal($fecha_ref);
        $grupos[$grupo][] = $r;
    }
    return $grupos;
}

/**
 * [NUBIRA 2.0] Card compacta para clases pasadas (1 línea, sin foto, link sutil)
 */
function render_card_compacta($row, $tipo_vista) {
    $estado = $row['estado'];
    
    // Color de barra según estado (saturado, no fondo claro)
    $barra_color = 'bg-gray-300';
    if (in_array($estado, ['finalizado','liberado','finalizado_vendedor','finalizado_comprador'])) {
        $barra_color = 'bg-blue-400';
    } elseif ($estado === 'cancelado') {
        $barra_color = 'bg-rose-400';
    } elseif (in_array($estado, ['activo','en_progreso'])) {
        $barra_color = 'bg-emerald-400';
    }
    
    $fecha_clase   = $row['fecha_clase'] ?? $row['fecha_estimada'] ?? null;
    $fecha_amigable = formatear_fecha_clase($fecha_clase);
    $persona_nombre = $tipo_vista === 'comprador' ? $row['vendedor_nombre'] : $row['comprador_nombre'];
    
    ob_start();
    ?>
    <a href="/app/mini_aula.php?id=<?= $row['id'] ?>" 
       class="flex items-center justify-between gap-3 px-4 py-3 bg-white border border-gray-100 rounded-xl hover:bg-gray-50 hover:border-gray-200 transition-all group">
        <div class="flex items-center gap-3 min-w-0 flex-1">
            <div class="w-1 h-10 rounded-full <?= $barra_color ?> flex-shrink-0"></div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-bold text-gray-800 truncate"><?= htmlspecialchars($row['servicio_titulo']) ?></p>
                <div class="flex items-center gap-2 text-xs text-gray-500 mt-0.5 flex-wrap">
                    <span class="truncate"><?= htmlspecialchars(explode(' ', $persona_nombre)[0]) ?></span>
                    <?php if ($fecha_amigable): ?>
                        <span class="text-gray-300">·</span>
                        <span class="truncate"><?= htmlspecialchars($fecha_amigable) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <span class="text-[#54A6D8] text-xs font-bold opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-1 flex-shrink-0">
            Ver <i class="fa-solid fa-chevron-right text-[10px]"></i>
        </span>
    </a>
    <?php
    return ob_get_clean();
}
function render_seccion($titulo, $contratos, $tipo_vista, $color_titulo = 'text-gray-900') {
    if (empty($contratos)) return;
    ?>
    <div class="mb-8">
        <h2 class="text-xs font-extrabold <?= $color_titulo ?> uppercase tracking-widest mb-3 flex items-center gap-2">
            <?= htmlspecialchars($titulo) ?>
            <span class="bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full text-[10px] font-bold normal-case tracking-normal"><?= count($contratos) ?></span>
        </h2>
        <div class="space-y-3">
            <?php foreach ($contratos as $c): ?>
                <?= render_card_contrato($c, $tipo_vista) ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
?>

<!-- ========== TAB COMPRAS (ALUMNO) ========== -->
<div id="tab-compras" class="tab-content space-y-4">
    <?php if (count($_compras['rows']) > 0):
        $grupos_c = agrupar_contratos_array($_compras['rows']);
        $tiene_activas_c = ($_compras['activas'] > 0);
    ?>
        <?php if ($tiene_activas_c): ?>
            <?php render_seccion('Hoy', $grupos_c['hoy'], 'comprador', 'text-emerald-600'); ?>
            <?php render_seccion('Esta semana', $grupos_c['esta_semana'], 'comprador', 'text-[#54A6D8]'); ?>
            <?php render_seccion('Más adelante', $grupos_c['mas_adelante'], 'comprador'); ?>
            <?php render_seccion('Sin fecha agendada', $grupos_c['sin_fecha'], 'comprador', 'text-amber-600'); ?>
        <?php else: ?>
            <div class="bg-blue-50 border border-blue-100 rounded-2xl p-5 text-center mb-6">
                <p class="text-sm font-bold text-gray-800 mb-1">No tienes clases próximas</p>
                <p class="text-xs text-gray-500 mb-3">Explora servicios y agenda tu siguiente clase.</p>
                <a href="/clases-servicios" class="inline-flex bg-[#54A6D8] text-white text-xs font-bold px-4 py-2 rounded-full hover:bg-blue-600 transition">Explorar</a>
            </div>
        <?php endif; ?>

        <?php if (count($grupos_c['pasada']) > 0): ?>
            <details class="group" <?= !$tiene_activas_c ? 'open' : '' ?>>
                <summary class="cursor-pointer flex items-center justify-between py-3 border-t border-gray-100 mt-4">
                    <h2 class="text-xs font-extrabold text-gray-400 uppercase tracking-widest flex items-center gap-2">
                        Historial
                        <span class="bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full text-[10px] font-bold normal-case tracking-normal"><?= count($grupos_c['pasada']) ?></span>
                    </h2>
                    <i class="fa-solid fa-chevron-down text-gray-400 text-xs transition-transform group-open:rotate-180"></i>
                </summary>
                <div class="space-y-2 pt-3">
                    <?php foreach ($grupos_c['pasada'] as $c): ?>
                        <?= render_card_compacta($c, 'comprador') ?>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    <?php else: ?>
        <div class="flex flex-col items-center justify-center py-16 bg-white rounded-3xl border border-dashed border-gray-200">
            <div class="w-16 h-16 bg-gray-50 text-gray-300 rounded-full flex items-center justify-center mb-4 text-3xl">
                <?= icon('publish-class', 'w-8 h-8') ?>
            </div>
            <h3 class="text-lg font-bold text-gray-900">No tienes clases contratadas</h3>
            <p class="text-gray-500 text-sm mt-1 mb-6">Busca un profesor y comienza a aprender.</p>
            <a href="/clases-servicios" class="bg-[#54A6D8] text-white font-bold py-2.5 px-6 rounded-xl text-sm hover:bg-blue-600 transition shadow-md shadow-blue-200">
                Explorar Profesores
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- ========== TAB VENTAS (PROFESOR) ========== -->
<div id="tab-ventas" class="tab-content space-y-4 hidden">
    <?php if (count($_ventas['rows']) > 0):
        $grupos_v = agrupar_contratos_array($_ventas['rows']);
        $tiene_activas_v = ($_ventas['activas'] > 0);
    ?>
        <?php if ($tiene_activas_v): ?>
            <?php render_seccion('Hoy', $grupos_v['hoy'], 'vendedor', 'text-emerald-600'); ?>
            <?php render_seccion('Esta semana', $grupos_v['esta_semana'], 'vendedor', 'text-[#54A6D8]'); ?>
            <?php render_seccion('Más adelante', $grupos_v['mas_adelante'], 'vendedor'); ?>
            <?php render_seccion('Sin fecha agendada', $grupos_v['sin_fecha'], 'vendedor', 'text-amber-600'); ?>
        <?php else: ?>
            <div class="bg-blue-50 border border-blue-100 rounded-2xl p-5 text-center mb-6">
                <p class="text-sm font-bold text-gray-800 mb-1">No tienes clases agendadas próximamente</p>
                <p class="text-xs text-gray-500">Cuando un alumno agende una clase, aparecerá aquí.</p>
            </div>
        <?php endif; ?>

        <?php if (count($grupos_v['pasada']) > 0): ?>
            <details class="group" <?= !$tiene_activas_v ? 'open' : '' ?>>
                <summary class="cursor-pointer flex items-center justify-between py-3 border-t border-gray-100 mt-4">
                    <h2 class="text-xs font-extrabold text-gray-400 uppercase tracking-widest flex items-center gap-2">
                        Historial
                        <span class="bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full text-[10px] font-bold normal-case tracking-normal"><?= count($grupos_v['pasada']) ?></span>
                    </h2>
                    <i class="fa-solid fa-chevron-down text-gray-400 text-xs transition-transform group-open:rotate-180"></i>
                </summary>
                <div class="space-y-2 pt-3">
                    <?php foreach ($grupos_v['pasada'] as $v): ?>
                        <?= render_card_compacta($v, 'vendedor') ?>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    <?php else: ?>
        <div class="flex flex-col items-center justify-center py-16 bg-white rounded-3xl border border-dashed border-gray-200">
            <div class="w-16 h-16 bg-yellow-50 text-yellow-500 rounded-full flex items-center justify-center mb-4 text-3xl">
                <?= icon('publish-doc', 'w-8 h-8') ?>
            </div>
            <h3 class="text-lg font-bold text-gray-900">No tienes alumnos activos</h3>
            <p class="text-gray-500 text-sm mt-1 mb-6">Publica un nuevo servicio para atraer estudiantes.</p>
            <a href="/publicar-servicio" class="bg-[#54A6D8] text-white font-bold py-2.5 px-6 rounded-xl text-sm hover:bg-blue-600 transition shadow-md shadow-blue-200">
                Crear Servicio
            </a>
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

// Pestañas
const tabBtns = document.querySelectorAll('.tab-btn');
const tabContents = document.querySelectorAll('.tab-content');

tabBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        tabBtns.forEach(t => t.classList.remove('active'));
        tabContents.forEach(c => c.classList.add('hidden'));
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.target).classList.remove('hidden');
    });
});

function setupModal(triggerId, modalId, cardId, closeId) {
    const btn = document.getElementById(triggerId), modal = document.getElementById(modalId), card = document.getElementById(cardId), close = document.getElementById(closeId);
    if(!btn || !modal) return;
    const open = () => { modal.classList.remove('hidden'); requestAnimationFrame(() => card.classList.remove('translate-y-full', 'opacity-0')); document.body.style.overflow = 'hidden'; };
    const shut = () => { card.classList.add('translate-y-full', 'opacity-0'); setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); };
    btn.onclick = (e) => { e.preventDefault(); open(); }; 
    if(close) close.onclick = shut; 
    modal.onclick = (e) => { if(e.target === modal) shut(); };
}
setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');

function abrirMisChats() { window.open("/app/mis_chats.php", "mis_chats", "width=440,height=640,resizable=yes,scrollbars=yes"); }
</script>

</body>
</html>