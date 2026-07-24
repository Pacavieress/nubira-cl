<?php
/**
 * Panel de campaña — Despertar usuarios dormidos (confirmados, nunca usaron la plataforma).
 *
 * MODO CLI: php app/enviar_despertar_dormidos.php [limite]
 * MODO WEB GET:  Panel de selección manual
 * MODO WEB POST: Envío a IDs seleccionados (CSRF + JSON response)
 */

// ── Función compartida (CLI + web) ────────────────────────────
function generarHtmlEmailDespertarDormidos(string $primer_nombre): string {
    $nombre_safe = htmlspecialchars($primer_nombre, ENT_QUOTES, 'UTF-8');
    return "
<p>Hola <strong>{$nombre_safe}</strong>,</p>

<p>Hace un tiempo te registraste en Nubira buscando ayuda académica.</p>

<p>Si este semestre todavía necesitas apoyo con un ramo, preparar una prueba, avanzar en tu tesis o encontrar otro servicio académico, tu cuenta sigue activa.</p>

<p><strong>Lo que hace distinta a Nubira:</strong></p>

<ul style=\"padding-left:20px; line-height:2.2;\">
  <li>Tu dinero queda protegido en la plataforma hasta que confirmes que recibiste lo contratado. Si algo sale mal, no lo pierdes.</li>
  <li>Puedes conversar con los tutores sin compartir tu WhatsApp ni contactos de redes sociales.</li>
  <li>Las clases se hacen dentro de Nubira, sin instalar Zoom ni Meet.</li>
</ul>

<p>Hoy hay estudiantes y tutores activos en la plataforma resolviendo dudas y agendando clases particulares.</p>

<p style=\"text-align:center; margin:32px 0;\">
  <a href=\"https://nubira.cl/explorar\"
     style=\"background:#54A6D8;color:white;padding:13px 28px;
            text-decoration:none;border-radius:8px;font-weight:bold;
            font-size:16px;display:inline-block;\">
    Buscar tutor o servicio
  </a>
</p>

<p>Equipo Nubira<br><span style=\"color:#9CA3AF; font-size:14px;\">Nubira.cl</span></p>

<p style=\"text-align:center;margin-top:26px;margin-bottom:6px;font-size:13px;color:#555;\">
  Síguenos en redes sociales:
</p>
<p style=\"text-align:center;margin-bottom:24px;\">
  <a href=\"https://instagram.com/nubira.cl\" target=\"_blank\" style=\"margin:0 8px;display:inline-block;\">
    <img src=\"https://nubira.cl/upload/email/icon-instagram.png\" alt=\"Instagram Nubira\" width=\"26\" style=\"display:inline-block;border:0;\">
  </a>
  <a href=\"https://facebook.com/nubira.cl\" target=\"_blank\" style=\"margin:0 8px;display:inline-block;\">
    <img src=\"https://nubira.cl/upload/email/icon-facebook.png\" alt=\"Facebook Nubira\" width=\"26\" style=\"display:inline-block;border:0;\">
  </a>
</p>
";
}

// ── CLI mode ──────────────────────────────────────────────────
if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/conexion.php';
    require_once __DIR__ . '/correo.php';
    require_once __DIR__ . '/helpers/campanas.php';
    date_default_timezone_set('America/Santiago');
    if (!defined('LOG_PATH')) define('LOG_PATH', __DIR__ . '/log_correos.txt');
    set_time_limit(600);

    $LIMITE       = isset($argv[1]) ? (int)$argv[1] : 5;
    $admin_id_cli = 0;
    $admin_nombre = 'despertar_dormidos_jun2026';
    $asunto       = '¿Todavía necesitas un tutor?';

    $sql_cli = "
        SELECT a.id AS alumno_id, a.nombre, LOWER(TRIM(a.correo)) AS correo
        FROM alumnos a
        WHERE a.visible = 1
          AND a.bloqueado = 0
          AND a.confirmado = 1
          AND a.recibir_emails = 1
          AND a.id != 1
          AND a.correo NOT LIKE 'testpablo%'
          AND NOT EXISTS (SELECT 1 FROM servicios s WHERE s.alumno_id = a.id)
          AND NOT EXISTS (SELECT 1 FROM contratos c WHERE c.comprador_id = a.id)
          AND LOWER(TRIM(a.correo)) NOT IN (
              SELECT LOWER(TRIM(destinatario)) FROM correos_admin
              WHERE admin_nombre = 'despertar_dormidos_jun2026' AND exito = 1
          )
        ORDER BY a.id ASC
    ";

    if ($LIMITE > 0) {
        $stmt = $conn->prepare($sql_cli . " LIMIT ?");
        $stmt->bind_param('i', $LIMITE);
    } else {
        $stmt = $conn->prepare($sql_cli);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if (!$res || $res->num_rows === 0) {
        echo "Sin destinatarios pendientes para despertar_dormidos_jun2026.\n";
        $conn->close(); exit;
    }

    $stmt_log = $conn->prepare(
        "INSERT INTO correos_admin (admin_id, admin_nombre, destinatario, asunto, mensaje, exito)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    $enviados = 0; $fallidos = 0;
    while ($row = $res->fetch_assoc()) {
        $correo        = $row['correo'];
        $primer_nombre = explode(' ', trim($row['nombre']))[0];
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            logCampana('[DESPERTAR_DORMIDOS SKIP] ' . $correo); continue;
        }
        $html      = generarHtmlEmailDespertarDormidos($primer_nombre);
        $html_full = plantillaMaestra($asunto, $html, null, null, 'Encuentra al tutor ideal en Chile con pago protegido.');
        $exito     = _enviarEmailBase($correo, $asunto, $html_full, '', false);
        $exito_int = $exito ? 1 : 0;
        $stmt_log->bind_param('issssi', $admin_id_cli, $admin_nombre, $correo, $asunto, $html, $exito_int);
        $stmt_log->execute();
        logCampana('[DESPERTAR_DORMIDOS ' . ($exito ? 'OK' : 'FAIL') . '] ' . $correo . ' (' . $primer_nombre . ')');
        if ($exito) $enviados++; else $fallidos++;
        sleep(2);
    }
    $res->free(); $stmt_log->close(); $conn->close();
    echo "Completado. Enviados: {$enviados}, Fallidos: {$fallidos}\n";
    exit;
}

// ── WEB mode ──────────────────────────────────────────────────
session_start();

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /vitrina'); exit;
}

$app_dir = dirname(__DIR__) . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__ . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__;

require_once $app_dir . '/conexion.php';
require_once $app_dir . '/correo.php';
require_once $app_dir . '/helpers/campanas.php';
require_once $app_dir . '/iconos.php';

date_default_timezone_set('America/Santiago');
if (!defined('LOG_PATH')) define('LOG_PATH', $app_dir . '/log_correos.txt');

if (!isset($_SESSION['csrf_despertar_dormidos'])) {
    $_SESSION['csrf_despertar_dormidos'] = bin2hex(random_bytes(32));
}
$csrf_token   = $_SESSION['csrf_despertar_dormidos'];
$admin_nombre = 'despertar_dormidos_jun2026';
$asunto       = '¿Todavía necesitas un tutor?';

// ── POST: envío ───────────────────────────────────────────────
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

    $ids_raw = $_POST['alumno_ids'] ?? [];
    if (!is_array($ids_raw) || empty($ids_raw)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Sin destinatarios seleccionados.']);
        exit;
    }

    $ids = array_values(array_unique(
        array_filter(array_map('intval', $ids_raw), fn($id) => $id > 0)
    ));
    if (empty($ids)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'IDs inválidos.']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
        SELECT a.id, a.nombre, LOWER(TRIM(a.correo)) AS correo
        FROM alumnos a
        WHERE a.id IN ($placeholders)
          AND a.visible = 1
          AND a.confirmado = 1
        ORDER BY a.id ASC
    ");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $usuarios = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $admin_id = (int)$_SESSION['usuario_id'];
    $stmt_log = $conn->prepare(
        "INSERT INTO correos_admin (admin_id, admin_nombre, destinatario, asunto, mensaje, exito)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    $enviados = 0; $fallidos = 0;

    foreach ($usuarios as $row) {
        $correo        = strtolower(trim($row['correo']));
        $primer_nombre = explode(' ', trim($row['nombre']))[0];

        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            logCampana('[DESPERTAR_DORMIDOS SKIP] correo inválido: ' . $correo);
            continue;
        }

        if ($cupon_info) {
            $asunto_final = "Un {$cupon_info['porcentaje']}% de descuento para tu próxima clase en Nubira";
            $html         = nb_generar_email_cupon_promocional(
                $primer_nombre, $codigo_cupon, $cupon_info['porcentaje'], $cupon_info['fecha_expiracion'],
                'Hace un tiempo te registraste en Nubira. Te dejamos un cupón para volver:'
            );
        } else {
            $asunto_final = $asunto;
            $html         = generarHtmlEmailDespertarDormidos($primer_nombre);
        }
        $html_full = plantillaMaestra($asunto_final, $html, null, null, 'Encuentra al tutor ideal en Chile con pago protegido.');
        $exito     = _enviarEmailBase($correo, $asunto_final, $html_full, '', false);
        $exito_int = $exito ? 1 : 0;

        $stmt_log->bind_param('issssi', $admin_id, $admin_nombre, $correo, $asunto_final, $html, $exito_int);
        $stmt_log->execute();
        logCampana('[DESPERTAR_DORMIDOS ' . ($exito ? 'OK' : 'FAIL') . '] ' . $correo . ' (' . $primer_nombre . ')');

        if ($exito) $enviados++; else $fallidos++;
        sleep(2);
    }

    $stmt_log->close();
    $conn->close();

    echo json_encode(['ok' => true, 'enviados' => $enviados, 'fallidos' => $fallidos]);
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
    $codigo_pv = strtoupper(trim((string)($_GET['codigo'] ?? '')));

    if ($codigo_pv !== '') {
        $cupon_pv = nb_consultar_cupon_global($conn, $codigo_pv);
        if (!$cupon_pv['ok']) {
            echo '<p style="font-family:sans-serif;padding:20px;color:#999;">' . htmlspecialchars($cupon_pv['error'], ENT_QUOTES, 'UTF-8') . '</p>';
            exit;
        }
        $asunto_pv = "Un {$cupon_pv['porcentaje']}% de descuento para tu próxima clase en Nubira";
        $html_pv   = nb_generar_email_cupon_promocional(
            'Estudiante', $codigo_pv, $cupon_pv['porcentaje'], $cupon_pv['fecha_expiracion'],
            'Hace un tiempo te registraste en Nubira. Te dejamos un cupón para volver:'
        );
    } else {
        $asunto_pv = $asunto;
        $html_pv   = generarHtmlEmailDespertarDormidos('Estudiante');
    }

    echo plantillaMaestra($asunto_pv, $html_pv, null, null, 'Encuentra al tutor ideal en Chile con pago protegido.');
    exit;
}

// ── GET: listado ──────────────────────────────────────────────
$filtro = $_GET['filtro'] ?? 'pendiente';
if (!in_array($filtro, ['pendiente', 'enviado', 'todos'], true)) $filtro = 'pendiente';

$orden = $_GET['orden'] ?? 'id_asc';
if (!in_array($orden, ['id_asc','id_desc','correo_asc','correo_desc','nombre_asc','nombre_desc','estado'], true)) $orden = 'id_asc';

$order_map = [
    'id_asc'     => 'a.id ASC',
    'id_desc'    => 'a.id DESC',
    'correo_asc' => 'LOWER(a.correo) ASC',
    'correo_desc'=> 'LOWER(a.correo) DESC',
    'nombre_asc' => 'LOWER(a.nombre) ASC',
    'nombre_desc'=> 'LOWER(a.nombre) DESC',
    'estado'     => '(estado_envio IS NOT NULL) ASC, estado_envio ASC, a.id ASC',
];
$order_clause = $order_map[$orden];

$sql = "
    SELECT
        a.id AS alumno_id,
        a.nombre,
        LOWER(TRIM(a.correo)) AS correo,
        (SELECT MAX(ca.fecha_envio) FROM correos_admin ca
            WHERE LOWER(TRIM(ca.destinatario)) = LOWER(TRIM(a.correo))
              AND ca.admin_nombre = 'despertar_dormidos_jun2026'
              AND ca.exito = 1) AS fecha_enviado,
        (SELECT MAX(ca.exito) FROM correos_admin ca
            WHERE LOWER(TRIM(ca.destinatario)) = LOWER(TRIM(a.correo))
              AND ca.admin_nombre = 'despertar_dormidos_jun2026') AS estado_envio
    FROM alumnos a
    WHERE a.visible = 1
      AND a.bloqueado = 0
      AND a.confirmado = 1
      AND a.recibir_emails = 1
      AND a.id != 1
      AND a.correo NOT LIKE 'testpablo%'
      AND NOT EXISTS (SELECT 1 FROM servicios s WHERE s.alumno_id = a.id)
      AND NOT EXISTS (SELECT 1 FROM contratos c WHERE c.comprador_id = a.id)
    GROUP BY a.id
    ORDER BY {$order_clause}
";

$stmt = $conn->prepare($sql);
$stmt->execute();
$todos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

function clasificar_proveedor(string $correo): string {
    $c = strtolower(trim($correo));
    if (str_ends_with($c, '@gmail.com')) return 'gmail';
    if (preg_match('/@(outlook|hotmail|live)\./i', $c)) return 'outlook';
    if (preg_match('/@yahoo\./i', $c)) return 'yahoo';
    static $inst = [
        'uc.cl','usach.cl','uandes.cl','unab.cl','uandresbello.edu','duoc.cl',
        'aiep.cl','santotomas.cl','fen.uchile.cl','sansano.usm.cl','ing.puc.cl',
        'utem.cl','umayor.cl','uai.cl','uchile.cl','uv.cl','udec.cl',
        'pucv.cl','ulagos.cl','ucn.cl','uach.cl','umag.cl','utalca.cl',
    ];
    $dominio = substr($c, strpos($c, '@') + 1);
    if (in_array($dominio, $inst, true)) return 'institucional';
    if (preg_match('/^(correo|alumnos|estudiantes|mail)\./', $dominio)) return 'institucional';
    if (str_ends_with($dominio, '.edu') || str_ends_with($dominio, '.edu.cl')) return 'institucional';
    return 'otro';
}

$proveedores_validos = ['gmail', 'outlook', 'yahoo', 'institucional', 'otro'];
$proveedores_raw = $_GET['proveedores'] ?? '';
if ($proveedores_raw === '' || $proveedores_raw === 'todos') {
    $proveedores_activos = $proveedores_validos;
} else {
    $proveedores_activos = array_values(array_intersect(
        explode(',', $proveedores_raw),
        $proveedores_validos
    ));
    if (empty($proveedores_activos)) $proveedores_activos = $proveedores_validos;
}

$stats = ['total' => count($todos), 'enviados' => 0, 'pendientes' => 0, 'fallidos' => 0];
$stats_prov = array_fill_keys($proveedores_validos, 0);
$filas = [];

foreach ($todos as $row) {
    $e = $row['estado_envio'];
    if (is_null($e)) {
        $row['_estado'] = 'pendiente';
        $stats['pendientes']++;
    } elseif ((int)$e === 1) {
        $row['_estado'] = 'enviado';
        $stats['enviados']++;
    } else {
        $row['_estado'] = 'fallo';
        $stats['fallidos']++;
    }

    $row['_proveedor'] = clasificar_proveedor($row['correo']);

    $pasa_estado = match($filtro) {
        'pendiente' => in_array($row['_estado'], ['pendiente', 'fallo']),
        'enviado'   => $row['_estado'] === 'enviado',
        default     => true,
    };

    if ($pasa_estado) {
        $stats_prov[$row['_proveedor']]++;
        if (in_array($row['_proveedor'], $proveedores_activos, true)) {
            $filas[] = $row;
        }
    }
}

$makeProvUrl = function(string $prov) use ($proveedores_activos, $proveedores_validos, $filtro, $orden): string {
    if (in_array($prov, $proveedores_activos, true)) {
        $nueva = array_values(array_filter($proveedores_activos, fn($p) => $p !== $prov));
    } else {
        $nueva = $proveedores_activos;
        $nueva[] = $prov;
        usort($nueva, fn($a, $b) => array_search($a, $proveedores_validos) - array_search($b, $proveedores_validos));
    }
    $base = '?filtro=' . $filtro . '&orden=' . $orden;
    return (empty($nueva) || count($nueva) === count($proveedores_validos))
        ? $base
        : $base . '&proveedores=' . implode(',', $nueva);
};

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Campaña Despertar Dormidos | Nubira Admin</title>
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

<main class="pt-20 pb-40 md:pb-24 lg:ml-64 px-4 md:px-8 w-auto">
  <div class="w-full max-w-[1400px] mx-auto space-y-6">

    <!-- Cabecera -->
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Campaña: Despertar Dormidos</h1>
        <p class="text-sm text-gray-500 mt-0.5">Usuarios confirmados que nunca publicaron ni contrataron.</p>
      </div>
      <button id="btn-preview"
              class="shrink-0 inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm font-bold text-gray-600 hover:border-[#54A6D8] hover:text-[#54A6D8] transition shadow-sm">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
        Ver preview del email
      </button>
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
        <input type="text" id="input-codigo" placeholder="REACTIVACION-DORMIDOS-JUL26"
               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl font-mono uppercase text-sm focus:border-[#54A6D8] focus:ring-1 focus:ring-[#54A6D8]/30 outline-none">
        <p id="info-cupon" class="text-xs text-gray-400 mt-2 hidden"></p>
      </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Total usuarios</p>
        <p class="text-3xl font-extrabold text-gray-900"><?= $stats['total'] ?></p>
      </div>
      <div class="bg-white rounded-2xl border border-green-100 shadow-sm p-5">
        <p class="text-xs font-bold text-green-500 uppercase tracking-wider mb-1">Ya enviados</p>
        <p class="text-3xl font-extrabold text-green-700"><?= $stats['enviados'] ?></p>
      </div>
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Pendientes</p>
        <p class="text-3xl font-extrabold text-gray-700"><?= $stats['pendientes'] ?></p>
      </div>
      <div class="bg-white rounded-2xl border border-amber-100 shadow-sm p-5">
        <p class="text-xs font-bold text-amber-500 uppercase tracking-wider mb-1">Fallidos</p>
        <p class="text-3xl font-extrabold text-amber-700"><?= $stats['fallidos'] ?></p>
      </div>
    </div>

    <!-- Filtros -->
    <div class="flex flex-wrap gap-2">
      <?php
      $ops = [
          'pendiente' => ['Pendientes', $stats['pendientes'] + $stats['fallidos']],
          'enviado'   => ['Ya enviados', $stats['enviados']],
          'todos'     => ['Todos', $stats['total']],
      ];
      foreach ($ops as $key => [$label, $cnt]):
      ?>
      <a href="?filtro=<?= $key ?>&amp;orden=<?= htmlspecialchars($orden) ?>&amp;proveedores=<?= htmlspecialchars(implode(',', $proveedores_activos)) ?>"
         class="px-4 py-2 rounded-xl text-sm font-bold border transition flex items-center gap-1.5
                <?= $filtro === $key
                    ? 'bg-[#54A6D8] text-white border-[#54A6D8] shadow-sm'
                    : 'bg-white text-gray-600 border-gray-200 hover:border-[#54A6D8] hover:text-[#54A6D8]' ?>">
        <?= $label ?>
        <span class="<?= $filtro === $key ? 'bg-white/25 text-white' : 'bg-gray-100 text-gray-500' ?> text-[10px] font-bold px-1.5 py-0.5 rounded-full">
          <?= $cnt ?>
        </span>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Filtro proveedor -->
    <div class="flex flex-wrap items-center gap-2">
      <span class="text-xs font-bold text-gray-400 uppercase tracking-wider mr-1 shrink-0">Proveedor:</span>
      <?php
      $prov_labels = ['gmail' => 'Gmail', 'outlook' => 'Outlook/Hotmail', 'yahoo' => 'Yahoo', 'institucional' => 'Institucional', 'otro' => 'Otro'];
      foreach ($prov_labels as $pkey => $plabel):
          $pactivo = in_array($pkey, $proveedores_activos, true);
          $purl    = $makeProvUrl($pkey);
      ?>
      <a href="<?= htmlspecialchars($purl) ?>"
         class="px-3 py-1.5 rounded-xl text-xs font-bold border transition flex items-center gap-1.5
                <?= $pactivo
                    ? 'bg-white text-gray-700 border-gray-300 shadow-sm hover:border-[#54A6D8]'
                    : 'bg-gray-50 text-gray-300 border-gray-100 opacity-50 hover:opacity-75' ?>">
        <?= $plabel ?>
        <span class="<?= $pactivo ? 'bg-gray-100 text-gray-500' : 'bg-gray-100 text-gray-300' ?> text-[10px] font-bold px-1.5 py-0.5 rounded-full">
          <?= $stats_prov[$pkey] ?>
        </span>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Tabla -->
    <?php if (empty($filas)): ?>
    <div class="bg-white border border-dashed border-gray-200 rounded-2xl p-16 text-center">
      <p class="text-gray-400 text-sm font-medium">No hay usuarios en este estado.</p>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100 text-gray-500 text-xs font-semibold uppercase tracking-wider">
          <tr>
            <th class="px-4 py-3.5 w-10 text-center">
              <input type="checkbox" id="check-all" class="w-4 h-4 rounded accent-[#54A6D8] cursor-pointer">
            </th>
            <th class="px-4 py-3.5 text-left">ID</th>
            <th class="px-4 py-3.5 text-left">Nombre</th>
            <th class="px-4 py-3.5 text-left hidden md:table-cell">Correo</th>
            <th class="px-4 py-3.5 text-left">Estado</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($filas as $fila):
            $estado = $fila['_estado'];
            $badge  = match($estado) {
                'enviado' => ['bg-green-100 text-green-700 border-green-200',
                              'Enviado ' . ($fila['fecha_enviado']
                                  ? date('d/m', strtotime($fila['fecha_enviado']))
                                  : '')],
                'fallo'   => ['bg-amber-100 text-amber-700 border-amber-200', 'Falló'],
                default   => ['bg-gray-100 text-gray-500 border-gray-200',    'Pendiente'],
            };
          ?>
          <tr class="hover:bg-gray-50/70 transition-colors">
            <td class="px-4 py-3 text-center">
              <input type="checkbox" class="row-check w-4 h-4 rounded accent-[#54A6D8] cursor-pointer"
                     value="<?= (int)$fila['alumno_id'] ?>">
            </td>
            <td class="px-4 py-3 text-xs text-gray-400 font-mono"><?= (int)$fila['alumno_id'] ?></td>
            <td class="px-4 py-3 font-semibold text-gray-800"><?= htmlspecialchars($fila['nombre']) ?></td>
            <td class="px-4 py-3 text-xs text-gray-500 font-mono hidden md:table-cell">
              <?= htmlspecialchars($fila['correo']) ?>
            </td>
            <td class="px-4 py-3">
              <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold border <?= $badge[0] ?>">
                <?= $badge[1] ?>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 text-xs text-gray-400 text-right">
        <?= count($filas) ?> usuario<?= count($filas) !== 1 ? 's' : '' ?>
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

<!-- Modal preview email -->
<div id="modal-preview"
     class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[70] hidden flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden">
    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
      <h3 class="text-base font-bold text-gray-900 tracking-tight">Preview del email</h3>
      <button id="btn-cerrar-preview"
              class="text-gray-400 hover:text-gray-600 transition p-1 rounded-lg hover:bg-gray-100">
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

<!-- Toast -->
<div id="toast"
     class="fixed bottom-24 right-6 px-5 py-3 rounded-xl shadow-xl text-white z-[90] hidden text-sm font-bold">
</div>

<?php
require_once $app_dir . '/componentes/nav_bottom.php';
require_once $app_dir . '/componentes/modal_publicar.php';
require_once $app_dir . '/componentes/modal_explora.php';
?>

<script>
const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;

window.onload = () => {
  const l = document.getElementById('loader');
  if (l) { l.classList.add('opacity-0'); setTimeout(() => l.classList.add('hidden'), 300); }
};

const checkAll  = document.getElementById('check-all');
const rowChecks = [...document.querySelectorAll('.row-check')];
const actionBar = document.getElementById('action-bar');
const barCount  = document.getElementById('bar-count');
const barPlural = document.getElementById('bar-plural');
const btnEnviar = document.getElementById('btn-enviar');
const chkIncluirCupon = document.getElementById('chk-incluir-cupon');
const bloqueCupon = document.getElementById('bloque-cupon');

chkIncluirCupon?.addEventListener('change', () => {
  bloqueCupon.classList.toggle('hidden', !chkIncluirCupon.checked);
  if (!chkIncluirCupon.checked) {
    document.getElementById('input-codigo').value = '';
    document.getElementById('info-cupon').classList.add('hidden');
  }
});

function syncBar() {
  const n = rowChecks.filter(c => c.checked).length;
  barCount.textContent = n;
  barPlural.textContent = n === 1 ? '' : 's';
  btnEnviar.disabled = n === 0;
  actionBar.classList.toggle('translate-y-full', n === 0);
  actionBar.classList.toggle('translate-y-0',    n > 0);
}

checkAll?.addEventListener('change', () => {
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

btnEnviar?.addEventListener('click', async () => {
  const checked = rowChecks.filter(c => c.checked);
  const n = checked.length;
  if (!n) return;
  if (!confirm(`¿Confirmas el envío del correo a ${n} usuario${n !== 1 ? 's' : ''}?`)) return;

  btnEnviar.disabled = true;
  btnEnviar.innerHTML = `
    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
    </svg> Enviando…`;

  const body = new URLSearchParams();
  body.append('csrf_token', CSRF_TOKEN);
  checked.forEach(cb => body.append('alumno_ids[]', cb.value));
  const codigoEnvio = chkIncluirCupon.checked ? document.getElementById('input-codigo').value.trim() : '';
  if (codigoEnvio) body.append('codigo', codigoEnvio);

  try {
    const res  = await fetch(window.location.pathname, { method: 'POST', body });
    const data = await res.json();
    if (data.ok) {
      const msg = `${data.enviados} enviado${data.enviados !== 1 ? 's' : ''}`
        + (data.fallidos > 0 ? `, ${data.fallidos} fallido${data.fallidos !== 1 ? 's' : ''}` : '');
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

document.getElementById('btn-preview')?.addEventListener('click', async () => {
  const codigo = chkIncluirCupon.checked ? document.getElementById('input-codigo').value.trim() : '';
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
  if (e.target.id === 'modal-preview')
    document.getElementById('modal-preview').classList.add('hidden');
});

document.addEventListener('DOMContentLoaded', () => {
  ['btn-publicar,modal-quick,quick-card,quick-close',
   'btn-explora,modal-explora,explora-card,explora-close'].forEach(s => {
    const [t, m, c, x] = s.split(',');
    const btn = document.getElementById(t), modal = document.getElementById(m);
    const card = document.getElementById(c), close = document.getElementById(x);
    if (!btn || !modal) return;
    const open = () => { modal.classList.remove('hidden'); requestAnimationFrame(() => card?.classList.remove('translate-y-full','opacity-0')); document.body.style.overflow='hidden'; };
    const shut = () => { card?.classList.add('translate-y-full','opacity-0'); setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow=''; },300); };
    btn.onclick = e => { e.preventDefault(); open(); };
    if (close) close.onclick = shut;
    modal.onclick = e => { if (e.target===modal) shut(); };
  });
});
</script>

</body>
</html>
