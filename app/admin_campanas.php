<?php
/**
 * VISTA: ADMIN CAMPAÑAS DE EMAIL
 * Gestión de campañas de reactivación de usuarios dormidos.
 */

// ── Auth ─────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    http_response_code(403);
    exit('403 - Acceso restringido a administradores.');
}

// ── Rutas ─────────────────────────────────────────────────────
$app_dir = dirname(__DIR__) . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__ . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__;

require_once $app_dir . '/conexion.php';
require_once $app_dir . '/correo.php';
require_once $app_dir . '/helpers/campanas.php';

date_default_timezone_set('America/Santiago');
if (!defined('LOG_PATH')) define('LOG_PATH', $app_dir . '/log_correos.txt');

// ── JSON ACTIONS (salen antes del HTML) ───────────────────────
$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    $segmentos_ok = ['0-30', '31-90', '91-180', '180+', 'todos'];
    $limites_ok   = ['5', '10', '20', '50', 'todos'];

    $segmento    = in_array($_POST['segmento'] ?? '', $segmentos_ok) ? $_POST['segmento'] : '31-90';
    $limite      = in_array($_POST['limite']   ?? '', $limites_ok)   ? $_POST['limite']   : '5';
    $universidad = trim($_POST['universidad']  ?? '');
    $admin_id    = (int)($_SESSION['usuario_id'] ?? 0);
    $asunto      = "¿Te ayudamos con tus ramos este semestre?";

    // ── PREVIEW ──────────────────────────────────────────────
    if ($action === 'preview') {
        $q    = buildQueryDormidos($segmento, $limite, $universidad);
        $stmt = $conn->prepare($q['sql']);
        if ($q['tipos']) $stmt->bind_param($q['tipos'], ...$q['params']);
        $stmt->execute();
        $res  = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id'          => (int)$r['id'],
                'nombre'      => $r['nombre'],
                'correo'      => $r['correo'],
                'dias'        => (int)$r['dias_inactivo'],
                'institucion' => $r['institucion'],
            ];
        }
        $stmt->close();
        echo json_encode(['status' => 'ok', 'total' => count($rows), 'rows' => $rows]);
        exit;
    }

    // ── DETALLE HISTORIAL ────────────────────────────────────
    if ($action === 'detalle') {
        $camp = trim($_POST['campana'] ?? $_GET['campana'] ?? '');
        if ($camp === '') { echo json_encode(['status' => 'error', 'msg' => 'Sin campaña']); exit; }
        $stmt = $conn->prepare(
            "SELECT destinatario, exito, fecha_envio FROM correos_admin
             WHERE admin_nombre = ? ORDER BY fecha_envio ASC LIMIT 200"
        );
        $stmt->bind_param('s', $camp);
        $stmt->execute();
        $res  = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
        echo json_encode(['status' => 'ok', 'rows' => $rows]);
        exit;
    }

    // ── PRUEBA ───────────────────────────────────────────────
    if ($action === 'prueba') {
        $dest     = 'contacto@nubira.cl';
        $unsubUrl = generarUnsubUrl($dest);
        $html     = generarHtmlEmailDormido('Admin (prueba)', 45, $unsubUrl);
        $ok       = enviarDormidoConUnsubscribe($dest, '[PRUEBA] ' . $asunto, $html, $unsubUrl, 'noreply');
        echo json_encode(['status' => $ok ? 'ok' : 'error']);
        exit;
    }

    // ── ENVIAR ───────────────────────────────────────────────
    if ($action === 'enviar') {
        set_time_limit(300);
        $campana_nombre = 'campaña_dormidos_' . date('Ymd_Hi');

        $q    = buildQueryDormidos($segmento, $limite, $universidad);
        $stmt = $conn->prepare($q['sql']);
        if ($q['tipos']) $stmt->bind_param($q['tipos'], ...$q['params']);
        $stmt->execute();
        $res  = $stmt->get_result();

        $stmt_log = $conn->prepare(
            "INSERT INTO correos_admin (admin_id, admin_nombre, destinatario, asunto, mensaje, exito)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        $enviados = 0; $fallidos = 0; $detalle = [];

        while ($row = $res->fetch_assoc()) {
            $correo = strtolower(trim($row['correo']));
            $nombre = $row['nombre'];
            $dias   = (int)$row['dias_inactivo'];

            $unsubUrl = generarUnsubUrl($correo);
            $html     = generarHtmlEmailDormido($nombre, $dias, $unsubUrl);

            $exito     = enviarDormidoConUnsubscribe($correo, $asunto, $html, $unsubUrl, 'noreply');
            $exito_int = $exito ? 1 : 0;

            $stmt_log->bind_param('issssi', $admin_id, $campana_nombre, $correo, $asunto, $html, $exito_int);
            $stmt_log->execute();

            if ($exito) {
                $enviados++;
                logCampana('[PANEL OK] ' . $correo . ' (' . $dias . ' días)');
            } else {
                $fallidos++;
                logCampana('[PANEL FAIL] ' . $correo);
            }

            $detalle[] = ['correo' => $correo, 'dias' => $dias, 'exito' => (bool)$exito];
            sleep(1);
        }

        $stmt->close();
        $stmt_log->close();

        echo json_encode([
            'status'   => 'ok',
            'campana'  => $campana_nombre,
            'total'    => $enviados + $fallidos,
            'exitosos' => $enviados,
            'fallidos' => $fallidos,
            'detalle'  => $detalle,
        ]);
        exit;
    }

    echo json_encode(['status' => 'error', 'msg' => 'Acción desconocida']);
    exit;
}

// ── Historial (modo HTML) ─────────────────────────────────────
$historial = [];
$res_hist  = $conn->query(
    "SELECT admin_nombre,
            COUNT(*) AS total,
            SUM(exito) AS exitosos,
            MIN(fecha_envio) AS primera,
            MAX(fecha_envio) AS ultima
     FROM correos_admin
     WHERE admin_nombre LIKE 'campaña_%'
     GROUP BY admin_nombre
     ORDER BY MAX(fecha_envio) DESC
     LIMIT 50"
);
if ($res_hist) {
    while ($h = $res_hist->fetch_assoc()) $historial[] = $h;
}

// ── Variables para header/sidebar ─────────────────────────────
$institucion    = "Panel Admin";
$nombre_usuario = $_SESSION['usuario_nombre'] ?? 'Admin';
$foto_perfil    = $_SESSION['foto'] ?? 'default.png';
$rol            = 'admin';
$es_admin       = true;
$page_title     = "Campañas de Email";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Campañas de Email | Nubira Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <?php require_once $app_dir . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #ffffff; -webkit-tap-highlight-color: transparent; }
    .force-no-shadow * { text-shadow: none !important; }
  </style>
</head>
<body class="text-slate-800 antialiased overflow-x-hidden force-no-shadow bg-white">

<div id="loader" class="fixed inset-0 bg-white flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-8 w-8 border-4 border-slate-100 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php
require_once $app_dir . '/componentes/header.php';
require_once $app_dir . '/componentes/sidebar.php';
?>

<main class="pt-16 pb-32 md:pb-16 lg:ml-64 px-4 md:px-6 w-full md:w-[calc(100%-16rem)]">
  <div class="max-w-4xl mx-auto space-y-6">

    <!-- Sticky header -->
    <div class="sticky top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-slate-100 py-4 flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
      <div>
        <h1 class="text-xl md:text-2xl font-extrabold text-slate-900 tracking-tight">Campañas de Email</h1>
        <p class="text-slate-400 text-xs font-medium mt-0.5">Gestión de campañas de reactivación de usuarios.</p>
      </div>
      <a href="/admin/panel" class="shrink-0 flex items-center gap-2 text-slate-500 hover:text-slate-800 bg-slate-100 hover:bg-slate-200 px-4 py-2 rounded-xl text-sm font-bold transition-colors">
        <i class="fa-solid fa-arrow-left"></i> Panel Admin
      </a>
    </div>

    <!-- ═══════════════════════════════════
         SECCIÓN A — Nueva campaña
    ════════════════════════════════════ -->
    <div class="bg-white border border-slate-100 rounded-2xl p-6 shadow-sm">
      <h2 class="text-base font-extrabold text-slate-900 mb-5 flex items-center gap-2">
        <i class="fa-solid fa-paper-plane text-[#54A6D8]"></i> Nueva campaña — Usuarios dormidos
      </h2>

      <form id="form-campana" class="space-y-6" onsubmit="return false;">

        <!-- Segmento por antigüedad -->
        <div>
          <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-3">
            Segmento por antigüedad
          </label>
          <div class="flex flex-wrap gap-2">
            <?php
            $segmentos = [
                '0-30'   => '0–30 días <span class="opacity-50">(nuevos)</span>',
                '31-90'  => '31–90 días <span class="opacity-50">(sweet spot)</span>',
                '91-180' => '91–180 días',
                '180+'   => '180+ días <span class="opacity-50">(último intento)</span>',
                'todos'  => 'Todos',
            ];
            foreach ($segmentos as $val => $label):
                $checked = $val === '31-90' ? 'checked' : '';
            ?>
            <label class="flex items-center gap-2 cursor-pointer border px-3 py-2 rounded-xl text-sm font-medium transition-all
                          <?= $val === '31-90' ? 'bg-blue-50 border-[#54A6D8] text-[#54A6D8]' : 'bg-slate-50 border-slate-200 text-slate-600 hover:bg-slate-100' ?>"
                   id="lbl-seg-<?= $val ?>">
              <input type="radio" name="segmento" value="<?= $val ?>" <?= $checked ?>
                     class="accent-[#54A6D8]" onchange="actualizarSegmentos()">
              <?= $label ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Cantidad + Universidad -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
          <div>
            <label for="sel-limite" class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2">
              Cantidad a enviar
            </label>
            <select id="sel-limite" name="limite"
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-0 focus:border-[#54A6D8] focus:bg-white transition-colors outline-none">
              <option value="5">5 (prueba)</option>
              <option value="10">10</option>
              <option value="20">20</option>
              <option value="50">50</option>
              <option value="todos">Todos los disponibles</option>
            </select>
          </div>
          <div>
            <label for="inp-universidad" class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2">
              Filtrar por universidad <span class="text-slate-300 normal-case font-normal">(opcional)</span>
            </label>
            <input type="text" id="inp-universidad" name="universidad"
                   placeholder="ej: usach → filtra correos @usach.cl"
                   class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-medium focus:ring-0 focus:border-[#54A6D8] focus:bg-white transition-colors outline-none placeholder-slate-300">
          </div>
        </div>

        <!-- Botones -->
        <div class="flex flex-wrap items-center gap-3 pt-1 border-t border-slate-100">
          <button type="button" id="btn-preview"
                  class="flex items-center gap-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-4 py-2.5 rounded-xl text-sm transition-colors">
            <i class="fa-solid fa-eye"></i> Vista previa
          </button>
          <button type="button" id="btn-prueba"
                  class="flex items-center gap-2 bg-sky-50 hover:bg-sky-100 text-[#54A6D8] font-bold px-4 py-2.5 rounded-xl text-sm transition-colors">
            <i class="fa-solid fa-flask"></i> Prueba a mi correo
          </button>
          <button type="button" id="btn-enviar"
                  class="flex items-center gap-2 bg-red-500 hover:bg-red-600 active:bg-red-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm transition-colors ml-auto">
            <i class="fa-solid fa-rocket"></i> ENVIAR CAMPAÑA
          </button>
        </div>

      </form>
    </div>

    <!-- ═══════════════════════════════════
         SECCIÓN B — Preview / Resultado
    ════════════════════════════════════ -->
    <div id="section-preview" class="hidden bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
          <h2 class="text-base font-extrabold text-slate-900" id="preview-titulo">Vista previa</h2>
          <p class="text-xs text-slate-400 mt-0.5" id="preview-subtitulo"></p>
        </div>
        <div id="preview-acciones" class="flex gap-3 shrink-0">
          <button id="btn-cancelar"
                  class="text-slate-500 hover:text-slate-800 bg-slate-100 hover:bg-slate-200 px-4 py-2 rounded-xl text-sm font-bold transition-colors">
            Cancelar
          </button>
          <button id="btn-confirmar"
                  class="flex items-center gap-2 bg-red-500 hover:bg-red-600 text-white font-bold px-4 py-2 rounded-xl text-sm transition-colors">
            <i class="fa-solid fa-paper-plane"></i> Confirmar y enviar
          </button>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-sm">
          <thead class="text-[11px] text-slate-400 uppercase tracking-widest bg-slate-50 border-b border-slate-100">
            <tr>
              <th class="px-4 py-3 font-bold text-center w-10">#</th>
              <th class="px-4 py-3 font-bold">Nombre</th>
              <th class="px-4 py-3 font-bold">Correo</th>
              <th class="px-4 py-3 font-bold text-center">Días inactivo</th>
              <th class="px-4 py-3 font-bold">Institución</th>
            </tr>
          </thead>
          <tbody id="preview-tbody" class="divide-y divide-slate-50"></tbody>
        </table>
      </div>
    </div>

    <!-- ═══════════════════════════════════
         SECCIÓN C — Historial
    ════════════════════════════════════ -->
    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-100">
        <h2 class="text-base font-extrabold text-slate-900 flex items-center gap-2">
          <i class="fa-solid fa-clock-rotate-left text-[#54A6D8]"></i> Historial de campañas
        </h2>
      </div>

      <?php if (empty($historial)): ?>
        <div class="px-6 py-12 text-center text-slate-400">
          <i class="fa-solid fa-inbox text-4xl mb-3 block text-slate-200"></i>
          <p class="text-sm font-medium">Sin campañas enviadas todavía.</p>
        </div>
      <?php else: ?>
        <div class="divide-y divide-slate-50">
          <?php foreach ($historial as $h):
            $total    = (int)$h['total'];
            $exitosos = (int)$h['exitosos'];
            $fallidos = $total - $exitosos;
            $id_safe  = 'detalle-' . md5($h['admin_nombre']);
            $nom_esc  = htmlspecialchars($h['admin_nombre'], ENT_QUOTES, 'UTF-8');
          ?>
          <div class="px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
              <div class="flex-1 min-w-0">
                <p class="font-bold text-slate-900 text-sm font-mono truncate"><?= htmlspecialchars($h['admin_nombre']) ?></p>
                <p class="text-[10px] text-slate-400 mt-0.5">
                  <?= date('d/m/Y H:i', strtotime($h['primera'])) ?>
                  <?php if ($h['primera'] !== $h['ultima']): ?>
                    → <?= date('d/m/Y H:i', strtotime($h['ultima'])) ?>
                  <?php endif; ?>
                </p>
              </div>
              <div class="flex items-center gap-4 shrink-0">
                <div class="text-center">
                  <span class="block text-xl font-black text-slate-900"><?= $total ?></span>
                  <span class="text-[9px] text-slate-400 uppercase tracking-widest">Total</span>
                </div>
                <div class="text-center">
                  <span class="block text-xl font-black text-emerald-600"><?= $exitosos ?></span>
                  <span class="text-[9px] text-slate-400 uppercase tracking-widest">OK</span>
                </div>
                <?php if ($fallidos > 0): ?>
                <div class="text-center">
                  <span class="block text-xl font-black text-red-500"><?= $fallidos ?></span>
                  <span class="text-[9px] text-slate-400 uppercase tracking-widest">Fail</span>
                </div>
                <?php endif; ?>
                <div class="w-px h-8 bg-slate-100"></div>
                <button onclick="toggleDetalle('<?= $nom_esc ?>', '<?= $id_safe ?>')"
                        class="flex items-center gap-1.5 text-[11px] font-bold text-slate-400 hover:text-[#54A6D8] uppercase tracking-widest transition-colors">
                  <i class="fa-solid fa-chevron-down" id="ico-<?= $id_safe ?>"></i> Ver
                </button>
              </div>
            </div>
            <div id="<?= $id_safe ?>" class="hidden mt-4" data-loaded="0">
              <p class="text-xs text-slate-400 py-2 text-center">Cargando...</p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>
</main>

<div id="toast" class="fixed bottom-6 right-1/2 translate-x-1/2 md:translate-x-0 md:right-5 px-5 py-3 rounded-xl hidden text-white text-sm font-bold z-[90] flex items-center gap-3 bg-slate-800"></div>

<?php
require_once $app_dir . '/componentes/nav_bottom.php';
?>

<script>
(function() {
    const l = document.getElementById('loader');
    if (l) { l.style.opacity = '0'; setTimeout(() => l.style.display = 'none', 300); }
})();

function showToast(msg, tipo = 'ok') {
    const t = document.getElementById('toast');
    const icon = tipo === 'ok'
        ? '<i class="fa-solid fa-circle-check text-emerald-400"></i>'
        : '<i class="fa-solid fa-circle-exclamation text-red-400"></i>';
    t.innerHTML = icon + ' ' + msg;
    t.classList.remove('hidden');
    setTimeout(() => t.classList.add('hidden'), 4000);
}

function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function getParams() {
    return {
        segmento:    document.querySelector('input[name="segmento"]:checked')?.value || '31-90',
        limite:      document.getElementById('sel-limite').value,
        universidad: document.getElementById('inp-universidad').value.trim(),
    };
}

function actualizarSegmentos() {
    document.querySelectorAll('input[name="segmento"]').forEach(r => {
        const lbl = document.getElementById('lbl-seg-' + r.value);
        if (!lbl) return;
        if (r.checked) {
            lbl.className = lbl.className.replace('bg-slate-50 border-slate-200 text-slate-600 hover:bg-slate-100', 'bg-blue-50 border-[#54A6D8] text-[#54A6D8]');
        } else {
            lbl.className = lbl.className.replace('bg-blue-50 border-[#54A6D8] text-[#54A6D8]', 'bg-slate-50 border-slate-200 text-slate-600 hover:bg-slate-100');
        }
    });
}

let ultimosParams = {};

// ── Vista previa ──────────────────────────────────────────────
document.getElementById('btn-preview').addEventListener('click', async () => {
    const btn = document.getElementById('btn-preview');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Buscando...';
    ultimosParams = getParams();
    try {
        const res  = await fetch(window.location.pathname, {
            method: 'POST',
            body: new URLSearchParams({ ...ultimosParams, action: 'preview' }),
        });
        const json = await res.json();
        if (json.status === 'ok') renderPreview(json);
        else showToast('Error al obtener destinatarios', 'error');
    } catch (e) {
        showToast('Error de conexión', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-eye"></i> Vista previa';
    }
});

function renderPreview(json) {
    document.getElementById('preview-titulo').textContent = 'Vista previa';
    document.getElementById('preview-subtitulo').textContent = json.total + ' destinatarios encontrados';
    document.getElementById('preview-acciones').classList.remove('hidden');

    const tbody = document.getElementById('preview-tbody');
    if (json.total === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="px-4 py-10 text-center text-slate-400 text-sm">No hay destinatarios para este segmento.</td></tr>';
        document.getElementById('preview-acciones').classList.add('hidden');
    } else {
        tbody.innerHTML = json.rows.map((r, i) => `
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 text-center text-slate-400 font-mono text-xs">${i + 1}</td>
                <td class="px-4 py-3 font-medium text-slate-900 text-sm">${escHtml(r.nombre)}</td>
                <td class="px-4 py-3 text-slate-600 text-sm">${escHtml(r.correo)}</td>
                <td class="px-4 py-3 text-center">
                    <span class="bg-amber-50 text-amber-700 text-xs font-bold px-2 py-0.5 rounded-md">${r.dias}d</span>
                </td>
                <td class="px-4 py-3 text-slate-400 text-xs">${escHtml(r.institucion || '—')}</td>
            </tr>
        `).join('');
    }

    const sec = document.getElementById('section-preview');
    sec.classList.remove('hidden');
    setTimeout(() => sec.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 50);
}

// ── Cancelar preview ──────────────────────────────────────────
document.getElementById('btn-cancelar').addEventListener('click', () => {
    document.getElementById('section-preview').classList.add('hidden');
});

// ── Confirmar y enviar (desde preview) ───────────────────────
document.getElementById('btn-confirmar').addEventListener('click', async () => {
    const subtitulo = document.getElementById('preview-subtitulo').textContent;
    const total = parseInt(subtitulo);
    if (!confirm(`¿Enviar a ${total} destinatarios?\nEsta acción no se puede deshacer.`)) return;
    await ejecutarEnvio(ultimosParams);
});

// ── ENVIAR CAMPAÑA directo (con confirm) ─────────────────────
document.getElementById('btn-enviar').addEventListener('click', async () => {
    const params = getParams();
    if (!confirm(`¿Enviar campaña?\nSegmento: ${params.segmento} | Límite: ${params.limite}\n\nEsta acción no se puede deshacer.`)) return;
    ultimosParams = params;
    await ejecutarEnvio(params);
});

async function ejecutarEnvio(params) {
    const btnC = document.getElementById('btn-confirmar');
    const btnE = document.getElementById('btn-enviar');
    [btnC, btnE].forEach(b => { b.disabled = true; });
    btnC.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';
    btnE.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';

    try {
        const res  = await fetch(window.location.pathname, {
            method: 'POST',
            body: new URLSearchParams({ ...params, action: 'enviar' }),
        });
        const json = await res.json();
        if (json.status === 'ok') {
            renderResultado(json);
            showToast(`Campaña completada: ${json.exitosos}/${json.total} enviados`, json.fallidos === 0 ? 'ok' : 'error');
            setTimeout(() => location.reload(), 5000);
        } else {
            showToast('Error durante el envío', 'error');
        }
    } catch (e) {
        showToast('Error de conexión', 'error');
    } finally {
        btnC.disabled = false; btnE.disabled = false;
        btnC.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Confirmar y enviar';
        btnE.innerHTML = '<i class="fa-solid fa-rocket"></i> ENVIAR CAMPAÑA';
    }
}

function renderResultado(json) {
    document.getElementById('preview-titulo').textContent  = 'Campaña completada — ' + escHtml(json.campana);
    document.getElementById('preview-subtitulo').textContent =
        `Total: ${json.total} | Exitosos: ${json.exitosos} | Fallidos: ${json.fallidos}`;
    document.getElementById('preview-acciones').classList.add('hidden');

    document.getElementById('preview-tbody').innerHTML = json.detalle.map((d, i) => `
        <tr class="hover:bg-slate-50">
            <td class="px-4 py-3 text-center text-slate-400 font-mono text-xs">${i + 1}</td>
            <td class="px-4 py-3 text-slate-700 text-sm" colspan="2">${escHtml(d.correo)}</td>
            <td class="px-4 py-3 text-center">
                <span class="bg-amber-50 text-amber-700 text-xs font-bold px-2 py-0.5 rounded-md">${d.dias}d</span>
            </td>
            <td class="px-4 py-3 font-bold text-sm ${d.exito ? 'text-emerald-600' : 'text-red-500'}">
                ${d.exito ? 'Enviado ✓' : 'Fallido ✗'}
            </td>
        </tr>
    `).join('');

    document.getElementById('section-preview').classList.remove('hidden');
}

// ── Prueba ────────────────────────────────────────────────────
document.getElementById('btn-prueba').addEventListener('click', async () => {
    const btn = document.getElementById('btn-prueba');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';
    try {
        const res  = await fetch(window.location.pathname, {
            method: 'POST',
            body: new URLSearchParams({ action: 'prueba' }),
        });
        const json = await res.json();
        showToast(
            json.status === 'ok' ? 'Prueba enviada a contacto@nubira.cl' : 'Error al enviar prueba',
            json.status === 'ok' ? 'ok' : 'error'
        );
    } catch (e) {
        showToast('Error de conexión', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-flask"></i> Prueba a mi correo';
    }
});

// ── Historial toggle ──────────────────────────────────────────
function toggleDetalle(campana, idSafe) {
    const container = document.getElementById(idSafe);
    const icon      = document.getElementById('ico-' + idSafe);

    if (container.dataset.loaded === '1') {
        const oculto = container.classList.toggle('hidden');
        icon.className = icon.className
            .replace('fa-chevron-down', oculto ? 'fa-chevron-down' : 'fa-chevron-up')
            .replace('fa-chevron-up',   oculto ? 'fa-chevron-down' : 'fa-chevron-up');
        return;
    }

    const url = window.location.pathname + '?action=detalle&campana=' + encodeURIComponent(campana);
    fetch(url)
        .then(r => r.json())
        .then(json => {
            if (!json.rows || json.rows.length === 0) {
                container.innerHTML = '<p class="text-xs text-slate-400 py-2 text-center">Sin registros.</p>';
            } else {
                container.innerHTML = `
                <div class="overflow-x-auto mt-2">
                <table class="w-full text-xs border-collapse">
                  <thead>
                    <tr class="text-[10px] text-slate-400 uppercase tracking-widest border-b border-slate-100">
                      <th class="pb-2 pr-4 text-left font-bold">Destinatario</th>
                      <th class="pb-2 pr-4 text-left font-bold">Fecha envío</th>
                      <th class="pb-2 text-left font-bold">Estado</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-50">
                    ${json.rows.map(r => `
                    <tr>
                      <td class="py-1.5 pr-4 text-slate-700">${escHtml(r.destinatario)}</td>
                      <td class="py-1.5 pr-4 text-slate-400">${escHtml(r.fecha_envio)}</td>
                      <td class="py-1.5 font-bold ${r.exito == 1 ? 'text-emerald-600' : 'text-red-500'}">
                          ${r.exito == 1 ? '✓ OK' : '✗ Fail'}
                      </td>
                    </tr>`).join('')}
                  </tbody>
                </table>
                </div>`;
            }
            container.dataset.loaded = '1';
            container.classList.remove('hidden');
            icon.classList.replace('fa-chevron-down', 'fa-chevron-up');
        })
        .catch(() => {
            container.innerHTML = '<p class="text-xs text-red-400 py-2">Error al cargar.</p>';
        });
}


</script>

</body>
</html>
