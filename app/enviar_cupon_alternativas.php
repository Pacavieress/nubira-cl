<?php
/**
 * Panel de campaña — Cupón + tutores alternativos para estudiantes sin respuesta (única ejecución, no cron).
 * Requiere que el admin haya creado manualmente cada cupón individual en /admin/cupones (Global, sin servicio_id).
 */
session_start();

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /vitrina'); exit;
}

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/iconos.php';
require_once __DIR__ . '/helpers/tutores_alternativos.php';

date_default_timezone_set('America/Santiago');

if (!isset($_SESSION['csrf_cupon_alternativas'])) {
    $_SESSION['csrf_cupon_alternativas'] = bin2hex(random_bytes(32));
}
$csrf_token   = $_SESSION['csrf_cupon_alternativas'];
$admin_nombre = 'cupon_alternativas_jul2026';
$asunto       = 'Un 15% de descuento para volver a intentarlo en Nubira';

function generarHtmlEmailCuponAlternativas(string $primer_nombre, string $categoria, array $alternativas, string $codigo): string {
    $nombre_safe    = htmlspecialchars($primer_nombre, ENT_QUOTES, 'UTF-8');
    $categoria_safe = htmlspecialchars($categoria, ENT_QUOTES, 'UTF-8');
    $codigo_safe    = htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8');

    $cardsHtml = '';
    foreach ($alternativas as $alt) {
        $nombreTutor = htmlspecialchars($alt['nombre_tutor'], ENT_QUOTES, 'UTF-8');
        $tituloServ  = htmlspecialchars($alt['titulo'], ENT_QUOTES, 'UTF-8');
        $fotoUrl = !empty($alt['foto_perfil'])
            ? 'https://nubira.cl/app/perfil/fotos/' . $alt['foto_perfil']
            : 'https://ui-avatars.com/api/?name=' . urlencode($alt['nombre_tutor']) . '&background=54A6D8&color=fff&size=128&bold=true';
        $linkServicio = 'https://nubira.cl/servicios/' . $alt['slug'] . '-' . (int)$alt['id'];

        $cardsHtml .= "
        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='margin-bottom:12px;'>
          <tr>
            <td width='60' style='vertical-align:top;'>
              <img src='{$fotoUrl}' width='50' height='50' style='border-radius:50%; object-fit:cover; display:block;' alt='{$nombreTutor}'>
            </td>
            <td style='vertical-align:top; padding-left:12px;'>
              <p style='margin:0; font-weight:bold; color:#111; font-size:14px;'>{$nombreTutor}</p>
              <p style='margin:2px 0 6px 0; font-size:13px; color:#666;'>{$tituloServ}</p>
              <a href='{$linkServicio}' style='font-size:13px; color:#54A6D8; font-weight:bold; text-decoration:none;'>Ver perfil →</a>
            </td>
          </tr>
        </table>";
    }

    return "
        <p>Hola <strong>{$nombre_safe}</strong>,</p>
        <p>Vimos que hace poco estuviste buscando tutor en Nubira para <strong>{$categoria_safe}</strong>. Te dejamos un cupón de descuento — puedes usarlo con ese mismo tutor o con otras opciones disponibles:</p>
        <div style='background:#F0F9FF; border:1px dashed #54A6D8; border-radius:12px; padding:20px; margin:20px 0; text-align:center;'>
            <p style='margin:0 0 8px 0; font-size:13px; color:#0c4a6e; font-weight:bold;'>Tu código de descuento</p>
            <p style='margin:0; font-size:22px; font-weight:bold; letter-spacing:1px; color:#111;'>{$codigo_safe}</p>
            <p style='margin:8px 0 0 0; font-size:12px; color:#555;'>15% de descuento, válido por 7 días desde hoy.</p>
        </div>
        <div style='margin:20px 0;'>{$cardsHtml}</div>
    ";
}

$sql_base = "
    FROM (
        SELECT c.id AS conversacion_id, c.comprador_id, c.vendedor_id, c.servicio_id,
               MAX(m.enviado_en) AS ultimo_mensaje_comprador
        FROM conversaciones c
        JOIN mensajes m ON m.conversacion_id = c.id AND m.remitente_id = c.comprador_id
        JOIN alumnos a_vendedor ON a_vendedor.id = c.vendedor_id
        WHERE m.enviado_en >= (NOW() - INTERVAL 30 DAY)
          AND c.contrato_id IS NULL
          AND a_vendedor.bloqueado = 0
          %s
        GROUP BY c.id, c.comprador_id, c.vendedor_id, c.servicio_id
    ) t
    JOIN alumnos a_comprador ON a_comprador.id = t.comprador_id
    JOIN servicios s ON s.id = t.servicio_id
";

// ── POST: envío ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    set_time_limit(300);

    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token inválido.']);
        exit;
    }

    $envios_raw = json_decode($_POST['envios_json'] ?? '[]', true);
    if (!is_array($envios_raw) || empty($envios_raw)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Sin destinatarios seleccionados.']);
        exit;
    }

    $mapa_codigos = [];
    $mapa_tutores = [];
    foreach ($envios_raw as $item) {
        $aid = (int)($item['alumno_id'] ?? 0);
        $cod = trim((string)($item['codigo'] ?? ''));
        if ($aid > 0 && $cod !== '') $mapa_codigos[$aid] = $cod;
        if ($aid > 0 && !empty($item['tutor_ids']) && is_array($item['tutor_ids'])) {
            $mapa_tutores[$aid] = array_map('intval', $item['tutor_ids']);
        }
    }
    if (empty($mapa_codigos)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Faltan códigos de cupón.']);
        exit;
    }

    $ids = array_keys($mapa_codigos);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sql_re = "SELECT t.comprador_id, t.vendedor_id, t.servicio_id, s.categoria, a_comprador.nombre,
                      LOWER(TRIM(a_comprador.correo)) AS correo " . sprintf($sql_base, "AND c.comprador_id IN ($placeholders)")
             . " ORDER BY t.comprador_id ASC, t.ultimo_mensaje_comprador DESC";
    $stmt_re = $conn->prepare($sql_re);
    $stmt_re->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt_re->execute();
    $candidatos = $stmt_re->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_re->close();

    $usuarios = [];
    foreach ($candidatos as $row) {
        if (isset($usuarios[$row['comprador_id']])) continue;
        $usuarios[$row['comprador_id']] = $row;
    }

    // Protección anti doble envío: excluir a quien ya recibió esta campaña con éxito
    $stmt_ya = $conn->prepare("SELECT DISTINCT LOWER(TRIM(destinatario)) AS correo FROM correos_admin WHERE admin_nombre = ? AND exito = 1");
    $stmt_ya->bind_param("s", $admin_nombre);
    $stmt_ya->execute();
    $ya_enviados = array_flip(array_column($stmt_ya->get_result()->fetch_all(MYSQLI_ASSOC), 'correo'));
    $stmt_ya->close();

    $admin_id = (int)$_SESSION['usuario_id'];
    $stmt_log = $conn->prepare(
        "INSERT INTO correos_admin (admin_id, admin_nombre, destinatario, asunto, mensaje, exito)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    $enviados = 0; $fallidos = 0; $omitidos = 0;

    foreach ($usuarios as $aid => $row) {
        $correo = $row['correo'];
        $codigo = $mapa_codigos[$aid] ?? null;

        if (!$codigo || !filter_var($correo, FILTER_VALIDATE_EMAIL) || isset($ya_enviados[$correo])) {
            $omitidos++;
            continue;
        }

        $alternativas = [];
        if (!empty($mapa_tutores[$aid])) {
            $alternativas = obtener_tutores_por_ids($conn, $mapa_tutores[$aid], $row['categoria'], (int)$row['vendedor_id']);
        }
        if (empty($alternativas)) {
            $alternativas = buscar_tutores_alternativos($conn, $row['categoria'], (int)$row['vendedor_id']);
        }
        if (empty($alternativas)) {
            $omitidos++;
            continue;
        }

        $primer_nombre = explode(' ', trim($row['nombre']))[0];
        $html          = generarHtmlEmailCuponAlternativas($primer_nombre, $row['categoria'], $alternativas, $codigo);
        $primera       = $alternativas[0];
        $link_cupon    = "https://nubira.cl/app/contratar_servicio.php?servicio_id=" . (int)$primera['id'] . "&codigo_beca=" . rawurlencode($codigo);
        $html_full     = plantillaMaestra($asunto, $html, 'Usar mi descuento', $link_cupon, 'Un 15% de descuento para volver a intentarlo en Nubira.');
        $exito         = _enviarEmailBase($correo, $asunto, $html_full, '', false);
        $exito_int     = $exito ? 1 : 0;

        $stmt_log->bind_param('issssi', $admin_id, $admin_nombre, $correo, $asunto, $html, $exito_int);
        $stmt_log->execute();

        if ($exito) $enviados++; else $fallidos++;
        sleep(2);
    }

    $stmt_log->close();
    $conn->close();

    echo json_encode(['ok' => true, 'enviados' => $enviados, 'fallidos' => $fallidos, 'omitidos' => $omitidos]);
    exit;
}

// ── GET: preview de un correo real (por fila, con su código actual) ────
if (isset($_GET['preview'])) {
    $aid_preview    = (int)($_GET['alumno_id'] ?? 0);
    $codigo_preview = trim((string)($_GET['codigo'] ?? ''));
    $tutor_ids_raw  = trim((string)($_GET['tutor_ids'] ?? ''));

    if ($aid_preview <= 0 || $codigo_preview === '') {
        echo '<p style="font-family:sans-serif;padding:20px;color:#999;">Falta alumno o código para generar el preview.</p>';
        exit;
    }

    $stmt_pv = $conn->prepare("SELECT t.vendedor_id, s.categoria, a_comprador.nombre "
        . sprintf($sql_base, "AND c.comprador_id = ?") . " LIMIT 1");
    $stmt_pv->bind_param("i", $aid_preview);
    $stmt_pv->execute();
    $row_pv = $stmt_pv->get_result()->fetch_assoc();
    $stmt_pv->close();

    if (!$row_pv) {
        echo '<p style="font-family:sans-serif;padding:20px;color:#999;">Este estudiante ya no califica (el tutor pudo haber respondido, o ya hay un contrato).</p>';
        exit;
    }

    $alternativas_pv = [];
    if ($tutor_ids_raw !== '') {
        $ids_pv = array_map('intval', explode(',', $tutor_ids_raw));
        $alternativas_pv = obtener_tutores_por_ids($conn, $ids_pv, $row_pv['categoria'], (int)$row_pv['vendedor_id']);
    }
    if (empty($alternativas_pv)) {
        $alternativas_pv = buscar_tutores_alternativos($conn, $row_pv['categoria'], (int)$row_pv['vendedor_id']);
    }
    if (empty($alternativas_pv)) {
        echo '<p style="font-family:sans-serif;padding:20px;color:#999;">No hay tutores alternativos disponibles para esta categoría en este momento.</p>';
        exit;
    }

    $primer_nombre_pv = explode(' ', trim($row_pv['nombre']))[0];
    $html_pv    = generarHtmlEmailCuponAlternativas($primer_nombre_pv, $row_pv['categoria'], $alternativas_pv, $codigo_preview);
    $primera_pv = $alternativas_pv[0];
    $link_pv    = "https://nubira.cl/app/contratar_servicio.php?servicio_id=" . (int)$primera_pv['id'] . "&codigo_beca=" . rawurlencode($codigo_preview);
    echo plantillaMaestra($asunto, $html_pv, 'Usar mi descuento', $link_pv, 'Un 15% de descuento para volver a intentarlo en Nubira.');
    exit;
}

// ── GET: listar candidatos de una categoría para elegir manualmente ────
if (isset($_GET['listar_tutores'])) {
    header('Content-Type: application/json');
    $aid_lt = (int)($_GET['alumno_id'] ?? 0);
    if ($aid_lt <= 0) { echo json_encode(['ok' => false, 'error' => 'ID inválido.']); exit; }

    $stmt_lt = $conn->prepare("SELECT t.vendedor_id, s.categoria "
        . sprintf($sql_base, "AND c.comprador_id = ?") . " LIMIT 1");
    $stmt_lt->bind_param("i", $aid_lt);
    $stmt_lt->execute();
    $row_lt = $stmt_lt->get_result()->fetch_assoc();
    $stmt_lt->close();

    if (!$row_lt) { echo json_encode(['ok' => false, 'error' => 'Este estudiante ya no califica.']); exit; }

    $sql_todos = "
        SELECT s.id, s.titulo, s.slug, a.id AS tutor_id, a.nombre AS nombre_tutor, a.foto_perfil,
               (SELECT AVG(rt.minutos_respuesta)
                FROM respuestas_tutor rt
                WHERE rt.tutor_id = a.id
                  AND rt.creado_en > (NOW() - INTERVAL 30 DAY)
                  AND rt.minutos_respuesta <= 1440) AS tiempo_resp_calculado
        FROM servicios s
        INNER JOIN alumnos a ON s.alumno_id = a.id
        WHERE s.estado = 'aprobado' AND s.visible = 1 AND a.bloqueado = 0
          AND s.categoria = ? AND a.id != ?
        ORDER BY tiempo_resp_calculado IS NULL, tiempo_resp_calculado ASC
    ";
    $stmt_todos = $conn->prepare($sql_todos);
    $stmt_todos->bind_param("si", $row_lt['categoria'], $row_lt['vendedor_id']);
    $stmt_todos->execute();
    $todos_tutores = $stmt_todos->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_todos->close();

    $auto = buscar_tutores_alternativos($conn, $row_lt['categoria'], (int)$row_lt['vendedor_id']);
    $auto_ids = array_column($auto, 'id');
    foreach ($todos_tutores as &$t) { $t['auto_pick'] = in_array((int)$t['id'], $auto_ids, true); }

    echo json_encode(['ok' => true, 'categoria' => $row_lt['categoria'], 'tutores' => $todos_tutores]);
    exit;
}

// ── GET: listado ──────────────────────────────────────────────
$sql = "SELECT t.comprador_id, t.vendedor_id, t.servicio_id, t.ultimo_mensaje_comprador, s.categoria,
               a_comprador.nombre, LOWER(TRIM(a_comprador.correo)) AS correo,
               (SELECT MAX(ca.fecha_envio) FROM correos_admin ca
                   WHERE LOWER(TRIM(ca.destinatario)) = LOWER(TRIM(a_comprador.correo))
                     AND ca.admin_nombre = ? AND ca.exito = 1) AS fecha_enviado "
        . sprintf($sql_base, "") . " ORDER BY t.comprador_id ASC, t.ultimo_mensaje_comprador DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $admin_nombre);
$stmt->execute();
$todos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$filas = [];
$vistos = [];
foreach ($todos as $row) {
    if (isset($vistos[$row['comprador_id']])) continue;
    $vistos[$row['comprador_id']] = true;
    $row['_estado'] = $row['fecha_enviado'] ? 'enviado' : 'pendiente';
    $filas[] = $row;
}
$conn->close();

$stats = ['total' => count($filas), 'enviados' => 0, 'pendientes' => 0];
foreach ($filas as $f) { $f['_estado'] === 'enviado' ? $stats['enviados']++ : $stats['pendientes']++; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Campaña: Cupón + Alternativas | Nubira Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
  </style>
</head>
<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden">

<?php
require_once __DIR__ . '/componentes/header.php';
require_once __DIR__ . '/componentes/sidebar.php';
?>

<main class="pt-20 pb-40 md:pb-24 lg:ml-64 px-4 md:px-8 w-auto">
  <div class="w-full max-w-[1400px] mx-auto space-y-6">

    <div>
      <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Campaña: Cupón + Tutores Alternativos</h1>
      <p class="text-sm text-gray-500 mt-0.5">Estudiantes sin respuesta en los últimos 30 días. Pega el código de cupón ya creado en /admin/cupones para cada uno.</p>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Total</p>
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
    </div>

    <?php if (empty($filas)): ?>
    <div class="bg-white border border-dashed border-gray-200 rounded-2xl p-16 text-center">
      <p class="text-gray-400 text-sm font-medium">No hay estudiantes calificando hoy.</p>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100 text-gray-500 text-xs font-semibold uppercase tracking-wider">
          <tr>
            <th class="px-4 py-3.5 w-10 text-center"><input type="checkbox" id="check-all" class="w-4 h-4 rounded accent-[#54A6D8] cursor-pointer"></th>
            <th class="px-4 py-3.5 text-left">Nombre</th>
            <th class="px-4 py-3.5 text-left hidden md:table-cell">Correo</th>
            <th class="px-4 py-3.5 text-left">Categoría</th>
            <th class="px-4 py-3.5 text-left hidden md:table-cell">Fecha mensaje</th>
            <th class="px-4 py-3.5 text-left">Código de cupón</th>
            <th class="px-4 py-3.5 text-left">Estado</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($filas as $fila):
            $enviado = $fila['_estado'] === 'enviado';
          ?>
          <tr class="hover:bg-gray-50/70 transition-colors <?= $enviado ? 'opacity-50' : '' ?>">
            <td class="px-4 py-3 text-center">
              <input type="checkbox" class="row-check w-4 h-4 rounded accent-[#54A6D8] cursor-pointer"
                     value="<?= (int)$fila['comprador_id'] ?>" <?= $enviado ? 'disabled' : '' ?>>
            </td>
            <td class="px-4 py-3 font-semibold text-gray-800"><?= htmlspecialchars($fila['nombre']) ?></td>
            <td class="px-4 py-3 text-xs text-gray-500 font-mono hidden md:table-cell"><?= htmlspecialchars($fila['correo']) ?></td>
            <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($fila['categoria']) ?></td>
            <td class="px-4 py-3 text-xs text-gray-500 hidden md:table-cell"><?= date('d/m/Y H:i', strtotime($fila['ultimo_mensaje_comprador'])) ?></td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <input type="text" class="row-codigo w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs font-mono uppercase focus:border-[#54A6D8] focus:ring-1 focus:ring-[#54A6D8]/30 outline-none"
                       placeholder="CODIGO-BECA" <?= $enviado ? 'disabled' : '' ?>>
                <?php if (!$enviado): ?>
                <button type="button" class="btn-preview-fila shrink-0 w-7 h-7 flex items-center justify-center rounded-lg border border-gray-200 text-gray-400 hover:border-[#54A6D8] hover:text-[#54A6D8] transition"
                        data-alumno-id="<?= (int)$fila['comprador_id'] ?>" title="Ver preview del correo">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  </svg>
                </button>
                <button type="button" class="btn-elegir-tutores shrink-0 w-7 h-7 flex items-center justify-center rounded-lg border border-gray-200 text-gray-400 hover:border-[#54A6D8] hover:text-[#54A6D8] transition"
                        data-alumno-id="<?= (int)$fila['comprador_id'] ?>" title="Elegir tutores alternativos">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM21 8.625a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                  </svg>
                </button>
                <?php endif; ?>
              </div>
              <?php if (!$enviado): ?>
              <span class="row-tutores-estado text-[10px] text-gray-400 mt-1 block" data-alumno-id="<?= (int)$fila['comprador_id'] ?>">Automático</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3">
              <?php if ($enviado): ?>
              <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold border bg-green-100 text-green-700 border-green-200">Enviado <?= date('d/m', strtotime($fila['fecha_enviado'])) ?></span>
              <?php else: ?>
              <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold border bg-gray-100 text-gray-500 border-gray-200">Pendiente</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 text-xs text-gray-400 text-right"><?= count($filas) ?> estudiantes</div>
    </div>
    <?php endif; ?>

  </div>
</main>

<div id="action-bar"
     class="fixed bottom-0 left-0 right-0 lg:left-64 z-50 bg-white border-t border-gray-200 shadow-xl
            px-6 py-4 flex items-center justify-between gap-4
            transform translate-y-full transition-transform duration-300">
  <p class="text-sm font-bold text-gray-700"><span id="bar-count">0</span> seleccionado<span id="bar-plural">s</span></p>
  <button id="btn-enviar" disabled
          class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold shadow-sm transition
                 bg-[#54A6D8] hover:bg-sky-500 text-white
                 disabled:bg-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed">
    Enviar seleccionados
  </button>
</div>

<div id="toast" class="fixed bottom-24 right-6 px-5 py-3 rounded-xl shadow-xl text-white z-[90] hidden text-sm font-bold"></div>

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

<div id="modal-tutores" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[70] hidden flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
      <h3 class="text-base font-bold text-gray-900 tracking-tight">Elegir tutores alternativos</h3>
      <button id="btn-cerrar-tutores" class="text-gray-400 hover:text-gray-600 transition p-1 rounded-lg hover:bg-gray-100">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
        </svg>
      </button>
    </div>
    <div id="lista-tutores" class="overflow-y-auto flex-1 p-4 space-y-2 text-sm text-gray-500">Cargando…</div>
    <div class="flex items-center justify-between gap-3 px-6 py-4 border-t border-gray-100 shrink-0">
      <button id="btn-usar-automatico" type="button" class="text-xs font-bold text-gray-400 hover:text-[#54A6D8] transition">Usar automático</button>
      <button id="btn-guardar-tutores" type="button" class="bg-[#54A6D8] hover:bg-sky-500 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition">Guardar selección</button>
    </div>
  </div>
</div>

<?php
require_once __DIR__ . '/componentes/nav_bottom.php';
?>

<script>
const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;
const MAX_LOTE = 10;

const checkAll  = document.getElementById('check-all');
const rows      = [...document.querySelectorAll('tbody tr')];
const actionBar = document.getElementById('action-bar');
const barCount  = document.getElementById('bar-count');
const barPlural = document.getElementById('bar-plural');
const btnEnviar = document.getElementById('btn-enviar');

function syncBar() {
  const n = rows.filter(r => r.querySelector('.row-check')?.checked).length;
  barCount.textContent = n;
  barPlural.textContent = n === 1 ? '' : 's';
  btnEnviar.disabled = n === 0;
  actionBar.classList.toggle('translate-y-full', n === 0);
}

checkAll?.addEventListener('change', () => {
  const seleccionables = rows.filter(r => {
    const cb = r.querySelector('.row-check');
    return cb && !cb.disabled;
  });

  if (checkAll.checked) {
    let marcados = 0;
    seleccionables.forEach(r => {
      const cb = r.querySelector('.row-check');
      cb.checked = marcados < MAX_LOTE;
      if (cb.checked) marcados++;
    });
    if (seleccionables.length > MAX_LOTE) {
      mostrarToast(`Selecciona máximo ${MAX_LOTE} por tanda para evitar timeouts — envía en 2-3 tandas.`, 'error');
      checkAll.checked = false;
      checkAll.indeterminate = true;
    }
  } else {
    seleccionables.forEach(r => { r.querySelector('.row-check').checked = false; });
  }
  syncBar();
});

rows.forEach(r => {
  const cb = r.querySelector('.row-check');
  if (!cb) return;
  cb.addEventListener('change', () => {
    const n = rows.filter(rr => rr.querySelector('.row-check')?.checked).length;
    if (cb.checked && n > MAX_LOTE) {
      cb.checked = false;
      mostrarToast(`Máximo ${MAX_LOTE} seleccionados por tanda — envía en 2-3 tandas.`, 'error');
    }
    syncBar();
  });
});

btnEnviar?.addEventListener('click', async () => {
  const envios = [];
  let faltaCodigo = false;

  rows.forEach(r => {
    const cb = r.querySelector('.row-check');
    if (!cb || !cb.checked) return;
    const codigoInput = r.querySelector('.row-codigo');
    const codigo = codigoInput.value.trim();
    if (!codigo) { faltaCodigo = true; codigoInput.classList.add('border-red-400'); return; }
    envios.push({ alumno_id: parseInt(cb.value, 10), codigo, tutor_ids: seleccionesTutores[cb.value] || [] });
  });

  if (faltaCodigo) { mostrarToast('Falta pegar el código de cupón en alguna fila seleccionada', 'error'); return; }
  if (envios.length === 0) return;

  if (!confirm(`¿Confirmas el envío del correo a ${envios.length} estudiante${envios.length !== 1 ? 's' : ''}?`)) return;

  btnEnviar.disabled = true;
  btnEnviar.textContent = 'Enviando…';

  const body = new URLSearchParams();
  body.append('csrf_token', CSRF_TOKEN);
  body.append('envios_json', JSON.stringify(envios));

  try {
    const res  = await fetch(window.location.pathname, { method: 'POST', body });
    const data = await res.json();
    if (data.ok) {
      const msg = `${data.enviados} enviado${data.enviados !== 1 ? 's' : ''}`
        + (data.fallidos > 0 ? `, ${data.fallidos} fallido${data.fallidos !== 1 ? 's' : ''}` : '')
        + (data.omitidos > 0 ? `, ${data.omitidos} omitido${data.omitidos !== 1 ? 's' : ''}` : '');
      mostrarToast(msg, 'ok');
      setTimeout(() => location.reload(), 2500);
    } else {
      mostrarToast(data.error || 'Error al enviar', 'error');
      btnEnviar.disabled = false; btnEnviar.textContent = 'Enviar seleccionados';
    }
  } catch {
    mostrarToast('Error de conexión', 'error');
    btnEnviar.disabled = false; btnEnviar.textContent = 'Enviar seleccionados';
  }
});

function mostrarToast(msg, tipo) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'fixed bottom-24 right-6 px-5 py-3 rounded-xl shadow-xl text-white z-[90] text-sm font-bold transition-all duration-300 '
    + (tipo === 'ok' ? 'bg-green-600' : 'bg-red-600');
  t.classList.remove('hidden');
  setTimeout(() => t.classList.add('hidden'), 4000);
}

document.querySelectorAll('.btn-preview-fila').forEach(btn => {
  btn.addEventListener('click', () => {
    const tr = btn.closest('tr');
    const codigo = tr.querySelector('.row-codigo').value.trim();
    if (!codigo) { mostrarToast('Escribe el código de cupón antes de ver el preview', 'error'); return; }
    const alumnoId = btn.dataset.alumnoId;
    const tutorIds = seleccionesTutores[alumnoId];
    const extraTutores = (tutorIds && tutorIds.length > 0) ? `&tutor_ids=${tutorIds.join(',')}` : '';
    document.getElementById('preview-iframe').src =
      `${window.location.pathname}?preview=1&alumno_id=${alumnoId}&codigo=${encodeURIComponent(codigo)}${extraTutores}`;
    document.getElementById('modal-preview').classList.remove('hidden');
  });
});
document.getElementById('btn-cerrar-preview')?.addEventListener('click', () => {
  document.getElementById('modal-preview').classList.add('hidden');
});
document.getElementById('modal-preview')?.addEventListener('click', e => {
  if (e.target.id === 'modal-preview') document.getElementById('modal-preview').classList.add('hidden');
});

// ── Selección manual de tutores (persiste en sessionStorage) ──────────
const STORAGE_KEY = 'cupon_alt_tutores';
let seleccionesTutores = {};
try { seleccionesTutores = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '{}'); } catch { seleccionesTutores = {}; }
let alumnoModalActual = null;

function guardarSelecciones() { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(seleccionesTutores)); }

function actualizarIndicador(alumnoId) {
  const span = document.querySelector(`.row-tutores-estado[data-alumno-id="${alumnoId}"]`);
  if (!span) return;
  const sel = seleccionesTutores[alumnoId];
  span.textContent = (sel && sel.length > 0) ? `${sel.length} tutor${sel.length !== 1 ? 'es' : ''} (manual)` : 'Automático';
}
Object.keys(seleccionesTutores).forEach(actualizarIndicador);

document.querySelectorAll('.btn-elegir-tutores').forEach(btn => {
  btn.addEventListener('click', async () => {
    const alumnoId = btn.dataset.alumnoId;
    alumnoModalActual = alumnoId;
    const lista = document.getElementById('lista-tutores');
    lista.innerHTML = 'Cargando…';
    document.getElementById('modal-tutores').classList.remove('hidden');
    try {
      const res = await fetch(`${window.location.pathname}?listar_tutores=1&alumno_id=${alumnoId}`);
      const data = await res.json();
      if (!data.ok) { lista.innerHTML = `<p class="text-red-500">${data.error || 'Error al cargar tutores.'}</p>`; return; }
      if (data.tutores.length === 0) { lista.innerHTML = '<p>No hay tutores disponibles en esta categoría.</p>'; return; }
      const seleccionActual = seleccionesTutores[alumnoId];
      lista.innerHTML = data.tutores.map(t => {
        const marcado = seleccionActual ? seleccionActual.includes(t.id) : t.auto_pick;
        return `<label class="flex items-center gap-3 p-2 rounded-xl hover:bg-gray-50 cursor-pointer">
          <input type="checkbox" class="chk-tutor w-4 h-4 rounded accent-[#54A6D8]" value="${t.id}" ${marcado ? 'checked' : ''}>
          <span class="flex-1 text-sm font-medium text-gray-700">${t.nombre_tutor}</span>
          <span class="text-xs text-gray-400">${t.titulo}</span>
        </label>`;
      }).join('');
    } catch { lista.innerHTML = '<p class="text-red-500">Error de conexión.</p>'; }
  });
});

document.getElementById('btn-guardar-tutores')?.addEventListener('click', () => {
  if (!alumnoModalActual) return;
  const ids = [...document.querySelectorAll('#lista-tutores .chk-tutor:checked')].map(c => parseInt(c.value, 10));
  if (ids.length === 0) { mostrarToast('Selecciona al menos un tutor, o usa "Usar automático"', 'error'); return; }
  seleccionesTutores[alumnoModalActual] = ids;
  guardarSelecciones();
  actualizarIndicador(alumnoModalActual);
  document.getElementById('modal-tutores').classList.add('hidden');
});

document.getElementById('btn-usar-automatico')?.addEventListener('click', () => {
  if (!alumnoModalActual) return;
  delete seleccionesTutores[alumnoModalActual];
  guardarSelecciones();
  actualizarIndicador(alumnoModalActual);
  document.getElementById('modal-tutores').classList.add('hidden');
});

document.getElementById('btn-cerrar-tutores')?.addEventListener('click', () => {
  document.getElementById('modal-tutores').classList.add('hidden');
});
document.getElementById('modal-tutores')?.addEventListener('click', e => {
  if (e.target.id === 'modal-tutores') document.getElementById('modal-tutores').classList.add('hidden');
});
</script>
</body>
</html>
