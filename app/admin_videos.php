<?php
session_start();

$app_dir = dirname(__DIR__) . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__ . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__;

require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';
require_once $app_dir . '/correo.php';

// ── Auth admin ────────────────────────────────────────────────────
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /vitrina'); exit;
}

// ── CSRF token ────────────────────────────────────────────────────
if (!isset($_SESSION['csrf_token_admin_videos'])) {
    $_SESSION['csrf_token_admin_videos'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token_admin_videos'];

// ── POST: aprobar / rechazar ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token inválido.']);
        exit;
    }

    $accion      = $_POST['accion']   ?? '';
    $id_servicio = (int)($_POST['video_id'] ?? 0);

    if (!in_array($accion, ['aprobar', 'rechazar'], true) || $id_servicio <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Acción no válida.']);
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT s.titulo, a.id AS alumno_id, a.nombre, a.correo
         FROM servicios s
         JOIN alumnos a ON s.alumno_id = a.id
         WHERE s.id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $id_servicio);
    $stmt->execute();
    $stmt->bind_result($titulo, $alumno_id, $tutor_nombre, $tutor_correo);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Servicio no encontrado.']);
        exit;
    }

    require_once $app_dir . '/enviar_push_nubira.php';

    if ($accion === 'aprobar') {
        $stmt_upd = $conn->prepare(
            "UPDATE servicios SET video_estado = 'aprobado', video_motivo_rechazo = NULL WHERE id = ?"
        );
        $stmt_upd->bind_param("i", $id_servicio);
        $stmt_upd->execute();
        $stmt_upd->close();

        $asunto_email = 'Tu video está publicado en tu servicio';
        $body = '<p>Hola <strong>' . htmlspecialchars($tutor_nombre) . '</strong>,</p>'
              . '<p>Tu video de presentación para el servicio <strong>"' . htmlspecialchars($titulo) . '"</strong> fue aprobado y ya es visible para los estudiantes.</p>'
              . '<p>¡Ahora pueden conocerte antes de contratarte!</p>';
        _enviarEmailBase($tutor_correo, $asunto_email, plantillaMaestra($asunto_email, $body));
        enviar_push_nubira((int)$alumno_id, '✅ Video aprobado', 'Tu video de presentación ya está publicado.', '/mis-publicaciones');

        echo json_encode(['ok' => true, 'estado' => 'aprobado']);

    } else {
        $motivo = trim($_POST['motivo'] ?? '');
        if (empty($motivo)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'El motivo es obligatorio.']);
            exit;
        }

        $stmt_upd = $conn->prepare(
            "UPDATE servicios SET video_estado = 'rechazado', video_motivo_rechazo = ? WHERE id = ?"
        );
        $stmt_upd->bind_param("si", $motivo, $id_servicio);
        $stmt_upd->execute();
        $stmt_upd->close();

        $asunto_email = 'Tu video no fue aprobado';
        $body = '<p>Hola <strong>' . htmlspecialchars($tutor_nombre) . '</strong>,</p>'
              . '<p>Tu video de presentación para <strong>"' . htmlspecialchars($titulo) . '"</strong> no pudo ser aprobado.</p>'
              . '<p><strong>Motivo:</strong> ' . htmlspecialchars($motivo) . '</p>'
              . '<p>Puedes subir uno nuevo desde <a href="https://nubira.cl/mis-publicaciones">Mis publicaciones</a>.</p>';
        _enviarEmailBase($tutor_correo, $asunto_email, plantillaMaestra($asunto_email, $body));
        enviar_push_nubira((int)$alumno_id, '❌ Video rechazado', 'Tu video no fue aprobado. Puedes subir uno nuevo.', '/mis-publicaciones');

        echo json_encode(['ok' => true, 'estado' => 'rechazado']);
    }
    exit;
}

// ── GET: listado ──────────────────────────────────────────────────
$filtro = $_GET['filtro'] ?? 'pendiente';
if (!in_array($filtro, ['pendiente', 'aprobado', 'rechazado', 'todos'], true)) $filtro = 'pendiente';

$where_estado = $filtro === 'todos' ? '' : 'AND s.video_estado = ?';
$sql = "SELECT s.id, s.titulo, s.categoria, s.materia, s.precio,
               s.video_path, s.video_estado, s.video_motivo_rechazo, s.video_subido_en,
               a.id AS alumno_id, a.nombre AS tutor_nombre, a.foto_perfil, a.correo AS tutor_correo
        FROM servicios s
        JOIN alumnos a ON s.alumno_id = a.id
        WHERE s.video_path IS NOT NULL AND s.video_path != ''
        $where_estado
        ORDER BY
            CASE s.video_estado WHEN 'pendiente' THEN 0 ELSE 1 END,
            s.video_subido_en DESC
        LIMIT 200";

$stmt = $conn->prepare($sql);
if ($filtro !== 'todos') $stmt->bind_param("s", $filtro);
$stmt->execute();
$videos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt_cnt = $conn->prepare(
    "SELECT COUNT(*) FROM servicios WHERE video_path IS NOT NULL AND video_path != '' AND video_estado = 'pendiente'"
);
$stmt_cnt->execute();
$stmt_cnt->bind_result($total_pendientes);
$stmt_cnt->fetch();
$stmt_cnt->close();

if (!function_exists('nav_class')) {
    function nav_class(string $path): string {
        $ruta_actual = $_SERVER['REQUEST_URI'] ?? '/';
        $base     = 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent';
        $activo   = ' bg-blue-50 text-[#54A6D8] border-blue-100';
        $inactivo = ' text-gray-500 hover:bg-gray-50 hover:text-gray-900';
        if (str_starts_with($ruta_actual, '/admin/videos')) return $base . $activo;
        return $base . $inactivo;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Admin Videos | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
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
  <div class="w-full max-w-[1600px] mx-auto space-y-6">

    <!-- Cabecera -->
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 tracking-tight flex items-center gap-3">
          Videos de presentación
          <?php if ($total_pendientes > 0): ?>
            <span class="bg-amber-100 text-amber-700 text-sm font-bold px-2.5 py-0.5 rounded-full border border-amber-200">
              <?= $total_pendientes ?> pendiente<?= $total_pendientes !== 1 ? 's' : '' ?>
            </span>
          <?php endif; ?>
        </h1>
        <p class="text-sm text-gray-500 mt-0.5">Modera los videos antes de que sean visibles en los perfiles.</p>
      </div>

      <!-- Filtros -->
      <div class="flex gap-2 flex-wrap">
        <?php foreach (['pendiente' => 'Pendientes', 'aprobado' => 'Aprobados', 'rechazado' => 'Rechazados', 'todos' => 'Todos'] as $key => $label): ?>
        <a href="?filtro=<?= $key ?>"
           class="px-4 py-2 rounded-xl text-sm font-bold border transition
                  <?= $filtro === $key
                      ? 'bg-[#54A6D8] text-white border-[#54A6D8] shadow-sm'
                      : 'bg-white text-gray-600 border-gray-200 hover:border-[#54A6D8] hover:text-[#54A6D8]' ?>">
          <?= $label ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Grid de videos -->
    <?php if (empty($videos)): ?>
    <div class="bg-white border border-dashed border-gray-200 rounded-2xl p-16 text-center">
      <p class="text-gray-400 text-sm font-medium">No hay videos en este estado.</p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
      <?php foreach ($videos as $vid):
        $vid_estado = $vid['video_estado'];
        $vid_url    = '/upload/videos_servicios/' . htmlspecialchars($vid['video_path']);
        $foto_tutor = !empty($vid['foto_perfil'])
            ? '/app/perfil/fotos/' . htmlspecialchars($vid['foto_perfil'])
            : 'https://ui-avatars.com/api/?name=' . urlencode($vid['tutor_nombre']) . '&background=f1f5f9&color=64748b&size=128';
        $perfil_url = '/perfil/' . rtrim(base64_encode((int)$vid['alumno_id'] . '-nubira_secreto'), '=');

        $badge_cfg = match($vid_estado) {
            'aprobado'  => ['bg-green-100 text-green-700 border-green-200', 'Aprobado'],
            'rechazado' => ['bg-red-100 text-red-700 border-red-200',       'Rechazado'],
            default     => ['bg-amber-100 text-amber-700 border-amber-200', 'Pendiente'],
        };
      ?>
      <div id="card-video-<?= (int)$vid['id'] ?>" class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden flex flex-col">

        <!-- Player + info -->
        <div class="flex gap-4 p-4">

          <!-- Player 9:16 -->
          <div class="shrink-0 w-[110px]">
            <div class="aspect-[9/16] bg-black rounded-xl overflow-hidden">
              <video src="<?= $vid_url ?>" class="w-full h-full object-cover"
                     controls preload="metadata" playsinline></video>
            </div>
          </div>

          <!-- Datos -->
          <div class="flex-1 min-w-0 space-y-2 pt-1">

            <span id="badge-estado-<?= (int)$vid['id'] ?>"
                  class="inline-block text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full border <?= $badge_cfg[0] ?>">
              <?= $badge_cfg[1] ?>
            </span>

            <div>
              <p class="font-bold text-gray-900 text-sm leading-tight line-clamp-2">
                <?= htmlspecialchars($vid['titulo']) ?>
              </p>
              <p class="text-xs text-gray-400 mt-0.5">
                <?= htmlspecialchars($vid['materia'] ?: ($vid['categoria'] ?: '')) ?>
              </p>
              <p class="text-xs font-bold text-[#54A6D8] mt-1">
                $<?= number_format((int)$vid['precio'], 0, ',', '.') ?> CLP
              </p>
            </div>

            <a href="<?= $perfil_url ?>" target="_blank" class="flex items-center gap-2 group">
              <img src="<?= $foto_tutor ?>" alt=""
                   class="w-7 h-7 rounded-full object-cover border border-gray-100 shrink-0">
              <div class="min-w-0">
                <p class="text-xs font-bold text-gray-700 truncate group-hover:text-[#54A6D8] transition">
                  <?= htmlspecialchars($vid['tutor_nombre']) ?>
                </p>
                <p class="text-[10px] text-gray-400 truncate">
                  <?= htmlspecialchars($vid['tutor_correo']) ?>
                </p>
              </div>
            </a>

            <p class="text-[10px] text-gray-400">
              Subido el <?= date('d/m/Y H:i', strtotime($vid['video_subido_en'])) ?>
            </p>

            <?php if ($vid_estado === 'rechazado' && !empty($vid['video_motivo_rechazo'])): ?>
            <p class="text-[11px] text-red-600 bg-red-50 rounded-lg px-2 py-1 border border-red-100 line-clamp-2">
              <?= htmlspecialchars($vid['video_motivo_rechazo']) ?>
            </p>
            <?php endif; ?>
          </div>
        </div>

        <!-- Botones -->
        <div class="border-t border-gray-50 px-4 py-3 flex gap-2">

          <a href="<?= $vid_url ?>" download
             class="flex items-center gap-1.5 px-3 py-2 bg-gray-50 hover:bg-gray-100 text-gray-600 rounded-lg text-xs font-bold border border-gray-200 transition hover:scale-[1.02] shrink-0"
             title="Descargar para RRSS">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round"
                    d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
            </svg>
            Descargar
          </a>

          <?php if ($vid_estado !== 'aprobado'): ?>
          <button onclick="aprobarVideo(<?= (int)$vid['id'] ?>)"
                  class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 bg-green-50 hover:bg-green-100 text-green-700 rounded-lg text-xs font-bold border border-green-200 transition hover:scale-[1.02]">
            <?= icon('check-circle', 'w-4 h-4') ?> Aprobar
          </button>
          <?php endif; ?>

          <?php if ($vid_estado !== 'rechazado'): ?>
          <button onclick="abrirModalRechazo(<?= (int)$vid['id'] ?>)"
                  class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 bg-red-50 hover:bg-red-100 text-red-700 rounded-lg text-xs font-bold border border-red-200 transition hover:scale-[1.02]">
            <?= icon('xmark', 'w-4 h-4') ?> Rechazar
          </button>
          <?php endif; ?>

        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
</main>

<!-- Modal rechazo -->
<div id="modal-rechazo" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[70] hidden flex items-center justify-center">
  <div class="bg-white p-6 rounded-2xl shadow-2xl w-full max-w-sm mx-4">
    <h3 class="text-lg font-bold text-gray-900 mb-4 tracking-tight">Rechazar video</h3>
    <input type="hidden" id="rechazo_video_id">
    <label class="block mb-2 text-xs font-bold text-gray-700 uppercase tracking-wider">Motivo del rechazo</label>
    <textarea id="rechazo_motivo" rows="3" required
              placeholder="Explica por qué el video no puede publicarse..."
              class="w-full border border-gray-200 rounded-xl px-4 py-3 mb-4 text-sm focus:ring-2 focus:ring-red-300 outline-none transition resize-none"></textarea>
    <div class="flex justify-end gap-2">
      <button onclick="cerrarModalRechazo()"
              class="px-4 py-2 rounded-xl text-sm font-bold text-gray-600 hover:bg-gray-100 transition">
        Cancelar
      </button>
      <button onclick="confirmarRechazo()"
              class="bg-red-600 hover:bg-red-700 text-white px-5 py-2 rounded-xl text-sm font-bold shadow-sm transition hover:scale-[1.02]">
        Confirmar rechazo
      </button>
    </div>
  </div>
</div>

<div id="toast" class="fixed bottom-6 right-6 px-4 py-3 rounded-xl shadow-lg hidden text-white text-sm font-bold z-[90] flex items-center gap-2 transform translate-y-10 transition-all duration-300"></div>

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

const NubiraModales = {
  setup(triggerId, modalId, cardId, closeId) {
    const btn   = document.getElementById(triggerId);
    const modal = document.getElementById(modalId);
    const card  = document.getElementById(cardId);
    const close = document.getElementById(closeId);
    if (!btn || !modal) return;
    const open = () => {
      modal.classList.remove('hidden');
      requestAnimationFrame(() => card.classList.remove('translate-y-full', 'opacity-0'));
      document.body.style.overflow = 'hidden';
    };
    const shut = () => {
      card.classList.add('translate-y-full', 'opacity-0');
      setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300);
    };
    btn.onclick = e => { e.preventDefault(); open(); };
    if (close) close.onclick = shut;
    modal.onclick = e => { if (e.target === modal) shut(); };
  }
};

document.addEventListener('DOMContentLoaded', () => {
  NubiraModales.setup('btn-publicar', 'modal-quick',   'quick-card',   'quick-close');
  NubiraModales.setup('btn-explora',  'modal-explora', 'explora-card', 'explora-close');
});

function mostrarToast(msg, tipo = 'ok') {
  const t = document.getElementById('toast');
  t.innerHTML = (tipo === 'ok' ? '✅ ' : '❌ ') + msg;
  t.className = 'fixed bottom-6 right-6 px-5 py-3 rounded-xl shadow-xl text-white z-[90] '
    + 'flex items-center gap-2 transform transition-all duration-300 translate-y-0 '
    + (tipo === 'ok' ? 'bg-green-600' : 'bg-red-600');
  t.classList.remove('hidden');
  setTimeout(() => {
    t.classList.add('translate-y-10', 'opacity-0');
    setTimeout(() => t.classList.add('hidden'), 300);
  }, 3500);
}

async function aprobarVideo(id) {
  if (!confirm('¿Aprobar este video y notificar al tutor?')) return;
  try {
    const res  = await fetch('/admin/videos', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ accion: 'aprobar', video_id: id, csrf_token: CSRF_TOKEN })
    });
    const data = await res.json();
    if (data.ok) {
      actualizarCard(id, 'aprobado');
      mostrarToast('Video aprobado y tutor notificado');
    } else {
      mostrarToast(data.error || 'Error al aprobar', 'error');
    }
  } catch {
    mostrarToast('Error de conexión', 'error');
  }
}

function abrirModalRechazo(id) {
  document.getElementById('rechazo_video_id').value = id;
  document.getElementById('rechazo_motivo').value   = '';
  document.getElementById('modal-rechazo').classList.remove('hidden');
}

function cerrarModalRechazo() {
  document.getElementById('modal-rechazo').classList.add('hidden');
}

async function confirmarRechazo() {
  const id     = document.getElementById('rechazo_video_id').value;
  const motivo = document.getElementById('rechazo_motivo').value.trim();
  if (!motivo) { document.getElementById('rechazo_motivo').focus(); return; }
  try {
    const res  = await fetch('/admin/videos', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ accion: 'rechazar', video_id: id, motivo, csrf_token: CSRF_TOKEN })
    });
    const data = await res.json();
    if (data.ok) {
      cerrarModalRechazo();
      actualizarCard(id, 'rechazado');
      mostrarToast('Video rechazado y tutor notificado');
    } else {
      mostrarToast(data.error || 'Error al rechazar', 'error');
    }
  } catch {
    mostrarToast('Error de conexión', 'error');
  }
}

function actualizarCard(id, estado) {
  const badge = document.getElementById('badge-estado-' + id);
  if (badge) {
    badge.className = 'inline-block text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full border '
      + (estado === 'aprobado'
          ? 'bg-green-100 text-green-700 border-green-200'
          : 'bg-red-100 text-red-700 border-red-200');
    badge.textContent = estado === 'aprobado' ? 'Aprobado' : 'Rechazado';
  }
  const card = document.getElementById('card-video-' + id);
  if (!card) return;
  if (estado === 'aprobado')  card.querySelectorAll('button[onclick^="aprobarVideo"]').forEach(b => b.remove());
  if (estado === 'rechazado') card.querySelectorAll('button[onclick^="abrirModalRechazo"]').forEach(b => b.remove());
}

document.getElementById('modal-rechazo').addEventListener('click', e => {
  if (e.target.id === 'modal-rechazo') cerrarModalRechazo();
});
</script>

</body>
</html>
