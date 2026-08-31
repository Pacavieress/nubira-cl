<?php
session_start();

$app_dir = dirname(__DIR__) . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__ . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__;

require_once $app_dir . '/conexion.php';
require_once $app_dir . '/correo.php';
require_once $app_dir . '/helpers/campanas.php';
require_once $app_dir . '/iconos.php';

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /vitrina'); exit;
}

date_default_timezone_set('America/Santiago');
if (!defined('LOG_PATH')) define('LOG_PATH', $app_dir . '/log_correos.txt');

if (!isset($_SESSION['csrf_leads_gmail'])) {
    $_SESSION['csrf_leads_gmail'] = bin2hex(random_bytes(32));
}
$csrf_token   = $_SESSION['csrf_leads_gmail'];

// [NUBIRA CSRF] Token separado para el trigger de enviar_recuperar_gmails.php
// (script externo, requiere su propio token — no reusar csrf_leads_gmail).
if (!isset($_SESSION['csrf_recuperar_gmails'])) {
    $_SESSION['csrf_recuperar_gmails'] = bin2hex(random_bytes(32));
}
$csrf_token_recuperar = $_SESSION['csrf_recuperar_gmails'];
$admin_nombre = 'recuperar_gmails_jun2026';
$asunto       = 'Ya puedes registrarte en Nubira con cualquier email';

// ── POST: envío selectivo a leads marcados ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    set_time_limit(600);

    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token inválido.']);
        exit;
    }

    $codigo_cupon = strtoupper(trim($_POST['codigo'] ?? ''));
    $cupon_info   = null;
    if ($codigo_cupon !== '') {
        $cupon_info = nb_consultar_cupon_global($conn, $codigo_cupon);
        if (!$cupon_info['ok']) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $cupon_info['error']]);
            exit;
        }
    }

    $correos_raw          = $_POST['correos'] ?? [];
    $correos_forzados_raw = $_POST['correos_forzados'] ?? [];
    if ((!is_array($correos_raw) || empty($correos_raw)) && (!is_array($correos_forzados_raw) || empty($correos_forzados_raw))) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Sin destinatarios seleccionados.']);
        exit;
    }

    $limpiar = fn(array $raw) => array_values(array_unique(array_filter(
        array_map(fn($c) => strtolower(trim((string)$c)), is_array($raw) ? $raw : []),
        fn($c) => filter_var($c, FILTER_VALIDATE_EMAIL)
    )));
    $candidatos          = $limpiar($correos_raw);
    $candidatos_forzados = $limpiar($correos_forzados_raw);
    if (empty($candidatos) && empty($candidatos_forzados)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Correos inválidos.']);
        exit;
    }

    // Re-validar contra la BD: misma regla de elegibilidad que enviar_recuperar_gmails.php.
    // Nunca confiamos en el estado que venía marcado en el HTML del panel.
    // $con_exclusion_enviado=true -> flujo normal (bloquea 'enviado'); false -> reenvío
    // forzado (salta ESA exclusión puntual, pero unsubscribed/registrado siguen intactos).
    $validar = function (array $lista, bool $con_exclusion_enviado) use ($conn, $admin_nombre) {
        if (empty($lista)) return [];
        $placeholders = implode(',', array_fill(0, count($lista), '?'));
        $tipos        = str_repeat('s', count($lista));
        $sql = "
            SELECT DISTINCT LOWER(TRIM(ir.correo)) AS correo
            FROM interesados_registro ir
            WHERE LOWER(TRIM(ir.correo)) IN ($placeholders)
              AND ir.correo LIKE '%@gmail.com'
              AND LOWER(TRIM(ir.correo)) NOT IN (
                  SELECT LOWER(TRIM(correo)) FROM unsubscribed
              )
              AND LOWER(TRIM(ir.correo)) NOT IN (
                  SELECT LOWER(TRIM(correo)) FROM alumnos WHERE visible = 1
              )"
              . ($con_exclusion_enviado ? "
              AND LOWER(TRIM(ir.correo)) NOT IN (
                  SELECT LOWER(TRIM(destinatario)) FROM correos_admin
                   WHERE admin_nombre = ? AND exito = 1
              )" : "") . "
        ";
        $params = $lista;
        if ($con_exclusion_enviado) $params[] = $admin_nombre;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($tipos . ($con_exclusion_enviado ? 's' : ''), ...$params);
        $stmt->execute();
        $out = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'correo');
        $stmt->close();
        return $out;
    };

    $validos          = $validar($candidatos, true);
    $validos_forzados = array_diff($validar($candidatos_forzados, false), $validos);

    $omitidos = (count($candidatos) - count($validos)) + (count($candidatos_forzados) - count($validos_forzados));

    if (empty($validos) && empty($validos_forzados)) {
        echo json_encode(['ok' => true, 'enviados' => 0, 'fallidos' => 0, 'omitidos' => $omitidos, 'forzados' => 0]);
        exit;
    }

    $admin_id = (int)$_SESSION['usuario_id'];
    $stmt_log = $conn->prepare(
        "INSERT INTO correos_admin (admin_id, admin_nombre, destinatario, asunto, mensaje, exito, forzado)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    $envios = [];
    foreach ($validos as $c) $envios[] = [$c, false];
    foreach ($validos_forzados as $c) $envios[] = [$c, true];

    $enviados = 0; $fallidos = 0; $forzados_ok = 0;

    foreach ($envios as [$correo, $forzado]) {
        $unsubUrl    = generarUnsubUrl($correo);
        $bloqueCupon = $cupon_info ? nb_bloque_cupon_html($codigo_cupon, $cupon_info['porcentaje'], $cupon_info['fecha_expiracion']) : '';
        $html        = generarHtmlEmailRecuperarGmail($unsubUrl, $bloqueCupon);
        $exito       = enviarDormidoConUnsubscribe($correo, $asunto, $html, $unsubUrl, 'noreply');
        $exito_int   = $exito ? 1 : 0;
        $forzado_int = $forzado ? 1 : 0;

        $stmt_log->bind_param('issssii', $admin_id, $admin_nombre, $correo, $asunto, $html, $exito_int, $forzado_int);
        $stmt_log->execute();
        logCampana('[RECUPERAR' . ($forzado ? ' FORZADO' : '') . ' ' . ($exito ? 'OK' : 'FAIL') . '] ' . $correo);

        if ($exito) { $enviados++; if ($forzado) $forzados_ok++; } else { $fallidos++; }
        sleep(2);
    }

    $stmt_log->close();
    $conn->close();

    echo json_encode(['ok' => true, 'enviados' => $enviados, 'fallidos' => $fallidos, 'omitidos' => $omitidos, 'forzados' => $forzados_ok]);
    exit;
}

// ── GET: consultar datos reales de un cupón ─────────────────────
if (isset($_GET['consultar_cupon'])) {
    header('Content-Type: application/json');
    echo json_encode(nb_consultar_cupon_global($conn, (string)($_GET['codigo'] ?? '')));
    exit;
}

// ── GET: preview del correo, con o sin cupón según el código dado ────
if (isset($_GET['preview_cupon'])) {
    $codigo_pv      = strtoupper(trim((string)($_GET['codigo'] ?? '')));
    $unsubUrl_pv    = generarUnsubUrl('preview@nubira.cl');
    $bloqueCupon_pv = '';

    if ($codigo_pv !== '') {
        $cupon_pv = nb_consultar_cupon_global($conn, $codigo_pv);
        if (!$cupon_pv['ok']) {
            echo '<p style="font-family:sans-serif;padding:20px;color:#999;">' . htmlspecialchars($cupon_pv['error'], ENT_QUOTES, 'UTF-8') . '</p>';
            exit;
        }
        $bloqueCupon_pv = nb_bloque_cupon_html($codigo_pv, $cupon_pv['porcentaje'], $cupon_pv['fecha_expiracion']);
    }

    $html_pv = generarHtmlEmailRecuperarGmail($unsubUrl_pv, $bloqueCupon_pv);
    echo plantillaMaestra($asunto, $html_pv);
    exit;
}

// ── Filtro ────────────────────────────────────────────────────
$filtro = $_GET['filtro'] ?? 'todos';
$filtros_validos = ['todos', 'registrado', 'enviado', 'fallo', 'sin_contacto', 'baja'];
if (!in_array($filtro, $filtros_validos, true)) $filtro = 'todos';

// ── Query ─────────────────────────────────────────────────────
$sql = "
    SELECT
        correos.correo,
        correos.fecha_original,
        correos.fecha_email,
        correos.email_exito,
        (SELECT id FROM alumnos
          WHERE LOWER(TRIM(correo)) = correos.correo AND visible = 1 LIMIT 1) AS alumno_id,
        (SELECT 1 FROM unsubscribed
          WHERE LOWER(TRIM(correo)) = correos.correo LIMIT 1) AS dado_baja
    FROM (
        SELECT
            LOWER(TRIM(ir.correo))  AS correo,
            MIN(ir.fecha)           AS fecha_original,
            MAX(ca.fecha_envio)     AS fecha_email,
            MAX(ca.exito)           AS email_exito
        FROM interesados_registro ir
        LEFT JOIN correos_admin ca
            ON  LOWER(TRIM(ca.destinatario)) = LOWER(TRIM(ir.correo))
            AND ca.admin_nombre = 'recuperar_gmails_jun2026'
        WHERE ir.correo LIKE '%@gmail.com'
        GROUP BY LOWER(TRIM(ir.correo))
    ) correos
    ORDER BY correos.fecha_original ASC
";

$stmt = $conn->prepare($sql);
$stmt->execute();
$todos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

// ── Clasificación de estado ───────────────────────────────────
function leadEstado(array $row): string {
    if (!empty($row['dado_baja']))  return 'baja';
    if (!empty($row['alumno_id'])) return 'registrado';
    if (!is_null($row['email_exito'])) {
        return (int)$row['email_exito'] === 1 ? 'enviado' : 'fallo';
    }
    return 'sin_contacto';
}

// ── Stats + filtrado en PHP (dataset ~93 filas) ───────────────
$stats = ['registrado' => 0, 'enviado' => 0, 'fallo' => 0, 'sin_contacto' => 0, 'baja' => 0];
$leads = [];
foreach ($todos as $row) {
    $estado = leadEstado($row);
    $stats[$estado]++;
    $row['_estado'] = $estado;
    if ($filtro === 'todos' || $filtro === $estado) {
        $leads[] = $row;
    }
}
$total = count($todos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Leads Gmail | Nubira Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <?php require_once $app_dir . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
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

<main class="pt-20 pb-32 md:pb-16 lg:ml-64 px-4 md:px-8 w-auto">
  <div class="w-full max-w-[1400px] mx-auto space-y-6">

    <!-- Cabecera -->
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 tracking-tight">
          Leads Gmail
          <span class="ml-2 text-base font-normal text-gray-400">— Campaña jun 2026</span>
        </h1>
        <p class="text-sm text-gray-500 mt-0.5">Seguimiento de los ~93 Gmails históricos invitados a registrarse.</p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <button type="button" id="btn-preview-cupon"
                class="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm font-bold text-gray-600 hover:border-[#54A6D8] hover:text-[#54A6D8] transition shadow-sm">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          Ver preview del email
        </button>
        <form action="/app/enviar_recuperar_gmails.php" method="POST"
              onsubmit="return confirm('¿Reenviar la campaña a los próximos 5 leads pendientes?');">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token_recuperar) ?>">
          <input type="hidden" name="limite" value="5">
          <button type="submit"
                  class="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm font-bold text-gray-600 hover:border-[#54A6D8] hover:text-[#54A6D8] transition shadow-sm">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>
            Reenviar campaña
          </button>
        </form>
      </div>
    </div>

    <!-- Cards de estadísticas -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Total leads</p>
        <p class="text-3xl font-extrabold text-gray-900"><?= $total ?></p>
      </div>

      <div class="bg-white rounded-2xl border border-green-100 shadow-sm p-5">
        <p class="text-xs font-bold text-green-500 uppercase tracking-wider mb-1">Registrados</p>
        <p class="text-3xl font-extrabold text-green-700"><?= $stats['registrado'] ?></p>
        <?php if ($total > 0): ?>
        <p class="text-xs text-gray-400 mt-1"><?= round($stats['registrado'] / $total * 100, 1) ?>% conversión</p>
        <?php endif; ?>
      </div>

      <div class="bg-white rounded-2xl border border-blue-100 shadow-sm p-5">
        <p class="text-xs font-bold text-blue-500 uppercase tracking-wider mb-1">Email enviado</p>
        <p class="text-3xl font-extrabold text-blue-700"><?= $stats['enviado'] ?></p>
        <p class="text-xs text-gray-400 mt-1">Sin registro aún</p>
      </div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Sin contacto</p>
        <p class="text-3xl font-extrabold text-gray-700"><?= $stats['sin_contacto'] ?></p>
        <p class="text-xs text-gray-400 mt-1">+ <?= $stats['fallo'] ?> con fallo SMTP</p>
      </div>

    </div>

    <!-- Cupón opcional -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
      <label class="flex items-center gap-2 cursor-pointer mb-3">
        <input type="checkbox" id="chk-incluir-cupon" class="w-4 h-4 rounded accent-[#54A6D8] cursor-pointer">
        <span class="text-xs font-bold text-gray-400 uppercase tracking-widest">Incluir cupón de descuento</span>
      </label>
      <div id="bloque-cupon" class="hidden">
        <label class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">
          Código de cupón (ya creado en /admin/cupones, Global)
        </label>
        <div class="flex items-center gap-2">
          <input type="text" id="input-codigo" placeholder="REACTIVACION-LEADS-JUL26"
                 class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl font-mono uppercase text-sm focus:border-[#54A6D8] focus:ring-1 focus:ring-[#54A6D8]/30 outline-none">
        </div>
        <p id="info-cupon" class="text-xs text-gray-400 mt-2 hidden"></p>
      </div>
    </div>

    <!-- Filtros -->
    <div class="flex flex-wrap gap-2">
      <?php
      $opciones_filtro = [
          'todos'        => ['Todos',        $total,                  'bg-gray-800 text-white border-gray-800',    'bg-white text-gray-600 border-gray-200'],
          'registrado'   => ['Registrados',  $stats['registrado'],    'bg-green-600 text-white border-green-600',  'bg-white text-gray-600 border-gray-200'],
          'enviado'      => ['Email enviado', $stats['enviado'],       'bg-blue-600 text-white border-blue-600',    'bg-white text-gray-600 border-gray-200'],
          'fallo'        => ['Email falló',  $stats['fallo'],          'bg-amber-500 text-white border-amber-500',  'bg-white text-gray-600 border-gray-200'],
          'sin_contacto' => ['Sin contacto', $stats['sin_contacto'],   'bg-gray-500 text-white border-gray-500',    'bg-white text-gray-600 border-gray-200'],
          'baja'         => ['Bajas',        $stats['baja'],           'bg-red-600 text-white border-red-600',      'bg-white text-gray-600 border-gray-200'],
      ];
      foreach ($opciones_filtro as $key => [$label, $cnt, $cls_activo, $cls_inactivo]):
      ?>
      <a href="?filtro=<?= $key ?>"
         class="px-4 py-2 rounded-xl text-sm font-bold border transition flex items-center gap-1.5
                <?= $filtro === $key ? $cls_activo : $cls_inactivo ?>">
        <?= $label ?>
        <span class="<?= $filtro === $key ? 'bg-white/25 text-white' : 'bg-gray-100 text-gray-500' ?> text-[10px] font-bold px-1.5 py-0.5 rounded-full">
          <?= $cnt ?>
        </span>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Tabla -->
    <?php if (empty($leads)): ?>
    <div class="bg-white border border-dashed border-gray-200 rounded-2xl p-16 text-center">
      <p class="text-gray-400 text-sm font-medium">No hay leads en este estado.</p>
    </div>
    <?php else: ?>

    <!-- Filtro correo + fecha (client-side, sobre el set ya filtrado por estado) -->
    <div class="flex flex-wrap gap-3 items-center">
      <input type="text" id="filtro-correo" placeholder="Buscar por correo..."
             class="px-4 py-2 border border-gray-200 rounded-xl text-sm font-mono focus:border-[#54A6D8] focus:ring-1 focus:ring-[#54A6D8]/30 outline-none flex-1 min-w-[200px]">
      <div class="flex items-center gap-2 text-sm text-gray-500">
        <span class="text-xs font-bold uppercase tracking-wide text-gray-400">Último email</span>
        <input type="date" id="filtro-fecha-desde" class="px-3 py-2 border border-gray-200 rounded-xl text-sm">
        <span>—</span>
        <input type="date" id="filtro-fecha-hasta" class="px-3 py-2 border border-gray-200 rounded-xl text-sm">
      </div>
    </div>

    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100 text-gray-500 text-xs font-semibold uppercase tracking-wider">
          <tr>
            <th class="px-4 py-3.5 w-10 text-center">
              <input type="checkbox" id="check-all" class="w-4 h-4 rounded accent-[#54A6D8] cursor-pointer">
            </th>
            <th class="px-5 py-3.5 text-left">Correo</th>
            <th class="px-5 py-3.5 text-left">Estado</th>
            <th class="px-5 py-3.5 text-left hidden md:table-cell">Intento original</th>
            <th class="px-5 py-3.5 text-left hidden md:table-cell">Último email</th>
            <th class="px-5 py-3.5 text-right">Acción</th>
          </tr>
        </thead>
        <tbody id="tabla-leads-tbody" class="divide-y divide-gray-50">
          <?php foreach ($leads as $lead):
            $estado = $lead['_estado'];

            $badge = match($estado) {
                'registrado'   => ['bg-green-100 text-green-700 border-green-200', 'Registrado'],
                'enviado'      => ['bg-blue-100 text-blue-700 border-blue-200',    'Email enviado'],
                'fallo'        => ['bg-amber-100 text-amber-700 border-amber-200', 'Email falló'],
                'baja'         => ['bg-red-100 text-red-700 border-red-200',       'Dado de baja'],
                default        => ['bg-gray-100 text-gray-500 border-gray-200',    'Sin contacto'],
            };

            $perfil_url = !empty($lead['alumno_id'])
                ? '/perfil/' . rtrim(base64_encode($lead['alumno_id'] . '-nubira_secreto'), '=')
                : null;
          ?>
          <tr class="hover:bg-gray-50/70 transition-colors"
              data-correo="<?= htmlspecialchars(strtolower($lead['correo'])) ?>"
              data-fecha-email="<?= $lead['fecha_email'] ? date('Y-m-d', strtotime($lead['fecha_email'])) : '' ?>">

            <td class="px-4 py-3.5 text-center">
              <?php if (in_array($estado, ['sin_contacto', 'fallo'], true)): ?>
                <input type="checkbox" class="row-check w-4 h-4 rounded accent-[#54A6D8] cursor-pointer"
                       value="<?= htmlspecialchars($lead['correo']) ?>">
              <?php elseif ($estado === 'enviado'): ?>
                <input type="checkbox" class="forced-check w-4 h-4 rounded accent-amber-500 cursor-pointer"
                       value="<?= htmlspecialchars($lead['correo']) ?>" title="Ya se envió — marcar para forzar reenvío">
              <?php else: ?>
                <span class="text-gray-200">—</span>
              <?php endif; ?>
            </td>

            <td class="px-5 py-3.5 font-mono text-xs text-gray-800 font-medium">
              <?= htmlspecialchars($lead['correo']) ?>
            </td>

            <td class="px-5 py-3.5">
              <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold border <?= $badge[0] ?>">
                <?= $badge[1] ?>
              </span>
            </td>

            <td class="px-5 py-3.5 text-xs text-gray-400 hidden md:table-cell">
              <?= $lead['fecha_original'] ? date('d/m/Y H:i', strtotime($lead['fecha_original'])) : '—' ?>
            </td>

            <td class="px-5 py-3.5 text-xs text-gray-400 hidden md:table-cell">
              <?= $lead['fecha_email'] ? date('d/m/Y H:i', strtotime($lead['fecha_email'])) : '—' ?>
            </td>

            <td class="px-5 py-3.5 text-right">
              <?php if ($perfil_url): ?>
                <a href="<?= $perfil_url ?>" target="_blank"
                   class="inline-flex items-center gap-1 text-xs font-bold text-[#54A6D8] hover:text-sky-600 transition px-3 py-1.5 rounded-lg border border-sky-100 hover:bg-sky-50">
                  Ver perfil
                  <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                  </svg>
                </a>
              <?php else: ?>
                <span class="text-xs text-gray-300">—</span>
              <?php endif; ?>
            </td>

          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 text-xs text-gray-400 text-right">
        <span id="contador-visibles"><?= count($leads) ?></span> de <?= count($leads) ?> leads
      </div>
    </div>
    <?php endif; ?>

  </div>
</main>

<!-- Barra de acción fija -->
<div id="action-bar"
     class="fixed bottom-0 left-0 right-0 lg:left-64 z-50 bg-white border-t border-gray-200 shadow-xl
            px-6 py-4 flex items-center justify-between gap-4
            transform translate-y-full transition-transform duration-300">
  <p class="text-sm font-bold text-gray-700">
    <span id="bar-count">0</span> seleccionado<span id="bar-plural">s</span>
  </p>
  <button id="btn-enviar" disabled
          class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold shadow-sm transition
                 bg-[#54A6D8] hover:bg-sky-500 text-white
                 disabled:bg-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
    </svg>
    Enviar a seleccionados
  </button>
</div>

<!-- Toast -->
<div id="toast"
     class="fixed bottom-24 right-6 px-5 py-3 rounded-xl shadow-xl text-white z-[90] hidden text-sm font-bold">
</div>

<!-- Modal preview -->
<div id="modal-preview" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[70] hidden flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden">
    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
      <h3 class="text-base font-bold text-gray-900 tracking-tight">Preview del email</h3>
      <button id="btn-cerrar-preview" class="text-gray-400 hover:text-gray-600 transition p-1 rounded-lg hover:bg-gray-100">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
        </svg>
      </button>
    </div>
    <div class="overflow-y-auto flex-1 p-4">
      <iframe id="preview-iframe" class="w-full border-0 rounded-lg" style="height:580px;"></iframe>
    </div>
  </div>
</div>

<?php
require_once $app_dir . '/componentes/nav_bottom.php';
require_once $app_dir . '/componentes/modal_publicar.php';
require_once $app_dir . '/componentes/modal_explora.php';
?>

<script>
const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;

// Filtro de correo + rango de fechas ("Último email"), 100% client-side sobre
// las filas ya filtradas por estado en el servidor — no toca la query PHP.
const filtroCorreo     = document.getElementById('filtro-correo');
const filtroFechaDesde = document.getElementById('filtro-fecha-desde');
const filtroFechaHasta = document.getElementById('filtro-fecha-hasta');
const filasLeads       = [...document.querySelectorAll('#tabla-leads-tbody tr')];
const contadorVisibles = document.getElementById('contador-visibles');

function aplicarFiltrosTabla() {
  const q     = filtroCorreo.value.trim().toLowerCase();
  const desde = filtroFechaDesde.value; // 'YYYY-MM-DD' o ''
  const hasta = filtroFechaHasta.value;
  const hayFiltroFecha = !!(desde || hasta);
  let visibles = 0;

  filasLeads.forEach(tr => {
    const correo      = tr.dataset.correo || '';
    const fechaEmail  = tr.dataset.fechaEmail || '';

    const matchCorreo = !q || correo.includes(q);
    // Sin filtro de fecha activo: no restringe. Con filtro activo: un lead que
    // nunca recibió email (fechaEmail vacío) no puede matchear ningún rango.
    const matchFecha = !hayFiltroFecha || (
      fechaEmail !== '' &&
      (!desde || fechaEmail >= desde) &&
      (!hasta || fechaEmail <= hasta)
    );

    const visible = matchCorreo && matchFecha;
    tr.classList.toggle('hidden', !visible);
    if (visible) visibles++;
  });

  contadorVisibles.textContent = visibles;
}

filtroCorreo?.addEventListener('input', aplicarFiltrosTabla);
filtroFechaDesde?.addEventListener('change', aplicarFiltrosTabla);
filtroFechaHasta?.addEventListener('change', aplicarFiltrosTabla);

const checkAll     = document.getElementById('check-all');
const rowChecks    = [...document.querySelectorAll('.row-check')];
const forcedChecks = [...document.querySelectorAll('.forced-check')];
const actionBar = document.getElementById('action-bar');
const barCount  = document.getElementById('bar-count');
const barPlural = document.getElementById('bar-plural');
const btnEnviar = document.getElementById('btn-enviar');

function syncBar() {
  const n = rowChecks.filter(c => c.checked).length + forcedChecks.filter(c => c.checked).length;
  barCount.textContent = n;
  barPlural.textContent = n === 1 ? '' : 's';
  btnEnviar.disabled = n === 0;
  actionBar.classList.toggle('translate-y-full', n === 0);
  actionBar.classList.toggle('translate-y-0',    n > 0);
}

checkAll?.addEventListener('change', () => {
  // "Seleccionar todos" nunca toca los checkboxes de reenvío forzado — esos son
  // opt-in individual únicamente, para que un clic acá no dispare reenvíos sin querer.
  rowChecks.forEach(cb => cb.checked = checkAll.checked);
  checkAll.indeterminate = false;
  syncBar();
});

rowChecks.forEach(cb => cb.addEventListener('change', () => {
  const all  = rowChecks.every(c => c.checked);
  const some = rowChecks.some(c => c.checked);
  checkAll.checked       = all;
  checkAll.indeterminate = !all && some;
  syncBar();
}));

forcedChecks.forEach(cb => cb.addEventListener('change', syncBar));

btnEnviar?.addEventListener('click', async () => {
  const checked        = rowChecks.filter(c => c.checked);
  const checkedForzado = forcedChecks.filter(c => c.checked);
  const nNormal   = checked.length;
  const nForzados = checkedForzado.length;
  if (!nNormal && !nForzados) return;

  // Confirmación explícita y distinta cuando hay reenvíos forzados incluidos —
  // nunca el mismo texto genérico del envío normal.
  let msg;
  if (nForzados > 0 && nNormal > 0) {
    msg = `Vas a enviar a ${nNormal} lead${nNormal !== 1 ? 's' : ''} nuevo${nNormal !== 1 ? 's' : ''} y REENVIAR a ${nForzados} lead${nForzados !== 1 ? 's' : ''} que ya recibió este correo. ¿Confirmas?`;
  } else if (nForzados > 0) {
    msg = `Est${nForzados !== 1 ? 'os' : 'e'} ${nForzados} lead${nForzados !== 1 ? 's' : ''} ya recibi${nForzados !== 1 ? 'eron' : 'ó'} este correo. ¿Reenviar de todas formas?`;
  } else {
    msg = `¿Confirmas el envío del correo a ${nNormal} lead${nNormal !== 1 ? 's' : ''}?`;
  }
  if (!confirm(msg)) return;

  btnEnviar.disabled = true;
  btnEnviar.innerHTML = `
    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
    </svg> Enviando…`;

  const body = new URLSearchParams();
  body.append('csrf_token', CSRF_TOKEN);
  checked.forEach(cb => body.append('correos[]', cb.value));
  checkedForzado.forEach(cb => body.append('correos_forzados[]', cb.value));
  const codigoEnvio = chkIncluirCupon.checked ? document.getElementById('input-codigo').value.trim() : '';
  if (codigoEnvio) body.append('codigo', codigoEnvio);

  try {
    const res  = await fetch(window.location.pathname + window.location.search, { method: 'POST', body });
    const data = await res.json();
    if (data.ok) {
      let msg = `${data.enviados} enviado${data.enviados !== 1 ? 's' : ''}`;
      if (data.forzados > 0) msg += ` (${data.forzados} forzado${data.forzados !== 1 ? 's' : ''})`;
      if (data.fallidos > 0) msg += `, ${data.fallidos} fallido${data.fallidos !== 1 ? 's' : ''}`;
      if (data.omitidos > 0) msg += `, ${data.omitidos} omitido${data.omitidos !== 1 ? 's' : ''} (ya no elegible)`;
      mostrarToast(msg, 'ok');
      setTimeout(() => location.reload(), 2500);
    } else {
      mostrarToast(data.error || 'Error al enviar', 'error');
      resetBtn();
    }
  } catch {
    mostrarToast('Error de conexión', 'error');
    resetBtn();
  }
});

function resetBtn() {
  btnEnviar.disabled = false;
  btnEnviar.innerHTML = `
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round"
            d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
    </svg> Enviar a seleccionados`;
}

function mostrarToast(msg, tipo = 'ok') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'fixed bottom-24 right-6 px-5 py-3 rounded-xl shadow-xl text-white z-[90] text-sm font-bold transition-all duration-300 '
    + (tipo === 'ok' ? 'bg-green-600' : 'bg-red-600');
  t.classList.remove('hidden');
  setTimeout(() => t.classList.add('hidden'), 4000);
}

const chkIncluirCupon = document.getElementById('chk-incluir-cupon');
const bloqueCupon = document.getElementById('bloque-cupon');

chkIncluirCupon?.addEventListener('change', () => {
    bloqueCupon.classList.toggle('hidden', !chkIncluirCupon.checked);
    if (!chkIncluirCupon.checked) {
        document.getElementById('input-codigo').value = '';
        document.getElementById('info-cupon').classList.add('hidden');
    }
});

document.getElementById('btn-preview-cupon')?.addEventListener('click', async () => {
    const codigo = document.getElementById('input-codigo').value.trim();
    const infoEl = document.getElementById('info-cupon');

    if (codigo) {
        try {
            const res = await fetch(`${window.location.pathname}?consultar_cupon=1&codigo=${encodeURIComponent(codigo)}`);
            const data = await res.json();
            if (!data.ok) {
                mostrarToast(data.error || 'Código inválido', 'error');
                infoEl.classList.add('hidden');
                return;
            }
            const vigencia = data.fecha_expiracion
                ? `Vence ${new Date(data.fecha_expiracion + 'T00:00:00').toLocaleDateString('es-CL')}`
                : 'Sin fecha límite';
            infoEl.textContent = `${data.porcentaje}% de descuento · ${vigencia}`;
            infoEl.classList.remove('hidden');
        } catch {
            mostrarToast('Error de conexión', 'error');
            return;
        }
    } else {
        infoEl.classList.add('hidden');
    }

    document.getElementById('preview-iframe').src =
        `${window.location.pathname}?preview_cupon=1&codigo=${encodeURIComponent(codigo)}`;
    document.getElementById('modal-preview').classList.remove('hidden');
});

document.getElementById('btn-cerrar-preview')?.addEventListener('click', () => {
    document.getElementById('modal-preview').classList.add('hidden');
});
document.getElementById('modal-preview')?.addEventListener('click', e => {
    if (e.target.id === 'modal-preview') document.getElementById('modal-preview').classList.add('hidden');
});

window.onload = () => {
  const l = document.getElementById('loader');
  if (l) { l.classList.add('opacity-0'); setTimeout(() => l.classList.add('hidden'), 300); }
};

document.addEventListener('DOMContentLoaded', () => {
  const NubiraModales = {
    setup(triggerId, modalId, cardId, closeId) {
      const btn = document.getElementById(triggerId), modal = document.getElementById(modalId);
      const card = document.getElementById(cardId), close = document.getElementById(closeId);
      if (!btn || !modal) return;
      const open = () => { modal.classList.remove('hidden'); requestAnimationFrame(() => card?.classList.remove('translate-y-full','opacity-0')); document.body.style.overflow='hidden'; };
      const shut = () => { card?.classList.add('translate-y-full','opacity-0'); setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow=''; }, 300); };
      btn.onclick = e => { e.preventDefault(); open(); };
      if (close) close.onclick = shut;
      modal.onclick = e => { if (e.target === modal) shut(); };
    }
  };
  NubiraModales.setup('btn-publicar', 'modal-quick',   'quick-card',   'quick-close');
  NubiraModales.setup('btn-explora',  'modal-explora', 'explora-card', 'explora-close');
});
</script>

</body>
</html>
