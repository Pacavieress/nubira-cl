<?php
/**
 * ADMIN: Ejercicios del Desafío generados con IA (Lote 3).
 * Ruta: /admin/desafio-ia
 *  - Genera lotes de ejercicios con IA (se guardan SIN aprobar).
 *  - Revisión: Aprobar (revisado_por_admin=1, desde ese momento /desafio puede servirlos) o Rechazar (activa=0; la fila
 *    se conserva para que la deduplicación no vuelva a generar lo mismo).
 *  - Muestra el stock aprobado por materia/dificultad y el uso reciente de proveedores de IA.
 * Solo admin. POST protegido con CSRF.
 */
session_start();

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /vitrina'); exit;
}

$app_dir = __DIR__;
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/helpers/desafio_ia.php';
require_once $app_dir . '/iconos.php';

if (!isset($_SESSION['csrf_desafio_ia'])) {
    $_SESSION['csrf_desafio_ia'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_desafio_ia'];

// ── POST (JSON) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token inválido. Recarga la página.']);
        exit;
    }
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'generar') {
        // Enfriamiento de 8 s por sesión: evita doble clic y gasto accidental de cuota (el límite real por usuario
        // y por proveedor llega en el Lote 5).
        $ahora = time();
        if ($ahora - (int)($_SESSION['desafio_ia_ultima'] ?? 0) < 8) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'Espera unos segundos antes de generar otra vez.']);
            exit;
        }
        $_SESSION['desafio_ia_ultima'] = $ahora;
        set_time_limit(120);

        $materia   = trim((string)($_POST['materia'] ?? ''));
        $dificultad = (int)($_POST['dificultad'] ?? 0);
        $cantidad  = (int)($_POST['cantidad'] ?? 5);
        $tipos     = array_values(array_intersect((array)($_POST['tipos'] ?? ['alternativas']), NB_DESAFIO_IA_TIPOS));
        if (!$tipos) $tipos = ['alternativas'];

        try {
            $r = nb_desafio_ia_generar($conn, $materia, $dificultad, $cantidad, ['tipos' => $tipos, 'usuario_id' => (int)$_SESSION['usuario_id']]);
        } catch (\Throwable $e) {
            error_log('[admin_desafio_ia] ' . nb_ia_error_corto($e->getMessage()));
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Error interno al generar. Revisa el log del servidor.']);
            exit;
        }
        // 'intentos' ya viene redactado (sin claves); se resume para el panel
        $r['intentos'] = array_map(fn($i) => ['proveedor' => $i['proveedor'], 'ok' => $i['ok'], 'error' => $i['error'] ?? null], $r['intentos'] ?? []);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'aprobar' || $accion === 'rechazar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'ID inválido.']); exit; }
        // Solo filas generadas por IA y aún sin revisar: un admin no puede alterar por aquí el banco manual ya aprobado.
        $sql = $accion === 'aprobar'
            ? "UPDATE desafio_preguntas SET revisado_por_admin = 1 WHERE id = ? AND origen = 'ia' AND activa = 1 AND revisado_por_admin = 0"
            : "UPDATE desafio_preguntas SET activa = 0 WHERE id = ? AND origen = 'ia' AND revisado_por_admin = 0";
        $st = $conn->prepare($sql);
        $st->bind_param('i', $id);
        $st->execute();
        $afectadas = $st->affected_rows;
        $st->close();
        echo json_encode(['ok' => $afectadas === 1, 'error' => $afectadas === 1 ? null : 'La pregunta ya no está pendiente.']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Acción inválida.']);
    exit;
}

// ── GET: datos del panel ──────────────────────────────────────
$materias = $conn->query("SELECT slug, nombre FROM materias WHERE activa = 1 ORDER BY orden ASC")->fetch_all(MYSQLI_ASSOC);

$stock = [];
$res = $conn->query("SELECT materia_slug, dificultad, COUNT(*) AS n FROM desafio_preguntas
                      WHERE activa = 1 AND revisado_por_admin = 1 AND ambito = 'desafio' GROUP BY materia_slug, dificultad");
foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) $stock[$r['materia_slug']][(int)$r['dificultad']] = (int)$r['n'];

$pend_por_materia = [];
$res = $conn->query("SELECT materia_slug, COUNT(*) AS n FROM desafio_preguntas
                      WHERE origen = 'ia' AND revisado_por_admin = 0 AND activa = 1 GROUP BY materia_slug");
foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) $pend_por_materia[$r['materia_slug']] = (int)$r['n'];

$pendientes = $conn->query("SELECT id, materia_slug, tipo, dificultad, enunciado, opcion_a, opcion_b, opcion_c, opcion_d,
                                   respuesta_correcta, explicacion, eje_tematico, proveedor_ia, created_at
                              FROM desafio_preguntas
                             WHERE origen = 'ia' AND revisado_por_admin = 0 AND activa = 1
                             ORDER BY id DESC LIMIT 60")->fetch_all(MYSQLI_ASSOC);
$total_pend = array_sum($pend_por_materia);

$uso_ia = [];
try {   // si ia_llamadas_log aún no existe (SQL sin ejecutar), el panel sigue funcionando
    $res = $conn->query("SELECT proveedor, ok, COUNT(*) AS n FROM ia_llamadas_log
                          WHERE funcion = 'desafio_generar' AND fecha >= (NOW() - INTERVAL 7 DAY) GROUP BY proveedor, ok ORDER BY proveedor");
    $uso_ia = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
} catch (\Throwable $e) { /* tabla pendiente */ }

$nombres_materia = array_column($materias, 'nombre', 'slug');
$cuales = ['gemini' => 'Gemini', 'groq' => 'Groq', 'openrouter' => 'OpenRouter'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Desafío con IA | Nubira Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <?php require_once $app_dir . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
  </style>
</head>
<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden">
<?php
require_once $app_dir . '/componentes/header.php';
require_once $app_dir . '/componentes/sidebar.php';
?>
<main class="pt-20 pb-40 md:pb-24 lg:ml-64 px-4 md:px-8 w-auto">
  <div class="w-full max-w-[1100px] mx-auto space-y-6">

    <div>
      <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Desafío: ejercicios con IA</h1>
      <p class="text-sm text-gray-500 mt-0.5">Todo lo que genera la IA queda <strong>sin aprobar</strong>: /desafio solo sirve lo que apruebas aquí.
        Pendientes: <span class="font-semibold text-gray-700"><?= (int)$total_pend ?></span></p>
    </div>

    <!-- Generar -->
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
      <h2 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-3">Generar un lote</h2>
      <form id="form-generar" class="flex flex-wrap items-end gap-3">
        <label class="text-xs font-bold text-gray-500">Materia
          <select name="materia" class="block mt-1 px-3 py-2.5 border border-gray-200 rounded-xl text-sm font-normal text-gray-800">
            <?php foreach ($materias as $m): ?><option value="<?= htmlspecialchars($m['slug']) ?>"><?= htmlspecialchars($m['nombre']) ?></option><?php endforeach; ?>
          </select></label>
        <label class="text-xs font-bold text-gray-500">Dificultad
          <select name="dificultad" class="block mt-1 px-3 py-2.5 border border-gray-200 rounded-xl text-sm font-normal text-gray-800">
            <option value="1">1 · Fácil</option><option value="2" selected>2 · Medio</option><option value="3">3 · Difícil</option>
          </select></label>
        <label class="text-xs font-bold text-gray-500">Cantidad
          <input type="number" name="cantidad" min="1" max="<?= NB_DESAFIO_IA_MAX_POR_LOTE ?>" value="5"
                 class="block mt-1 w-24 px-3 py-2.5 border border-gray-200 rounded-xl text-sm font-normal text-gray-800"></label>
        <label class="flex items-center gap-2 text-xs font-bold text-gray-500 pb-3">
          <input type="checkbox" name="tipos[]" value="vf" class="w-4 h-4 rounded accent-[#54A6D8]"> Incluir verdadero/falso</label>
        <button type="submit" id="btn-generar"
                class="px-5 py-2.5 bg-[#54A6D8] hover:bg-sky-500 text-white rounded-xl text-sm font-bold shadow-sm transition">Generar</button>
      </form>
      <p id="resultado-generar" class="text-sm text-gray-500 mt-3 hidden"></p>
    </section>

    <!-- Stock -->
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 overflow-x-auto">
      <h2 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-3">Stock aprobado (activo) por dificultad</h2>
      <table class="w-full text-sm">
        <thead class="text-gray-500 text-xs uppercase"><tr><th class="text-left py-2">Materia</th><th>Fácil</th><th>Medio</th><th>Difícil</th><th>Pendientes IA</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($materias as $m): $s = $stock[$m['slug']] ?? []; ?>
          <tr class="text-center">
            <td class="text-left py-2 font-medium text-gray-800"><?= htmlspecialchars($m['nombre']) ?></td>
            <td><?= (int)($s[1] ?? 0) ?></td><td><?= (int)($s[2] ?? 0) ?></td><td><?= (int)($s[3] ?? 0) ?></td>
            <td class="<?= !empty($pend_por_materia[$m['slug']]) ? 'text-amber-600 font-bold' : 'text-gray-300' ?>"><?= (int)($pend_por_materia[$m['slug']] ?? 0) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($uso_ia): ?>
      <p class="text-xs text-gray-400 mt-4">Llamadas de generación, últimos 7 días:
        <?php foreach ($uso_ia as $u): ?>
          <span class="inline-block mr-3"><?= htmlspecialchars($cuales[$u['proveedor']] ?? $u['proveedor']) ?>
            <?= (int)$u['ok'] === 1 ? 'ok' : 'fallidas' ?>: <b><?= (int)$u['n'] ?></b></span>
        <?php endforeach; ?></p>
      <?php endif; ?>
    </section>

    <!-- Revisión -->
    <section>
      <h2 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-3">Pendientes de revisión<?= $total_pend > 60 ? ' (primeros 60)' : '' ?></h2>
      <?php if (!$pendientes): ?>
        <div class="bg-white border border-dashed border-gray-200 rounded-2xl p-12 text-center text-gray-400 text-sm">No hay ejercicios pendientes.</div>
      <?php endif; ?>
      <div class="space-y-3" id="lista-pendientes">
      <?php foreach ($pendientes as $p):
          $ops = ['a' => $p['opcion_a'], 'b' => $p['opcion_b'], 'c' => $p['opcion_c'], 'd' => $p['opcion_d']];
          $dif = [1 => 'Fácil', 2 => 'Medio', 3 => 'Difícil'][(int)$p['dificultad']] ?? '?';
      ?>
        <article class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5" data-id="<?= (int)$p['id'] ?>">
          <p class="text-[11px] text-gray-400 mb-2">
            <?= htmlspecialchars($nombres_materia[$p['materia_slug']] ?? $p['materia_slug']) ?> · <?= $dif ?> ·
            <?= $p['tipo'] === 'vf' ? 'V/F' : 'Alternativas' ?> · eje: <?= htmlspecialchars((string)$p['eje_tematico']) ?> ·
            IA: <?= htmlspecialchars($cuales[$p['proveedor_ia']] ?? (string)$p['proveedor_ia']) ?></p>
          <p class="font-medium text-gray-900 mb-3"><?= htmlspecialchars($p['enunciado']) ?></p>
          <ul class="space-y-1 text-sm mb-3">
            <?php foreach ($ops as $letra => $txt): if ($txt === null || $txt === '') continue; $ok = $letra === $p['respuesta_correcta']; ?>
              <li class="<?= $ok ? 'text-green-700 font-semibold' : 'text-gray-600' ?>"><?= strtoupper($letra) ?>) <?= htmlspecialchars($txt) ?><?= $ok ? ' ✓' : '' ?></li>
            <?php endforeach; ?>
          </ul>
          <p class="text-xs text-gray-500 bg-gray-50 rounded-lg p-3 mb-3"><b>Explicación:</b> <?= htmlspecialchars((string)$p['explicacion']) ?></p>
          <div class="flex gap-2">
            <button type="button" data-accion="aprobar" class="px-4 py-2 rounded-xl text-sm font-bold bg-green-600 hover:bg-green-700 text-white transition">Aprobar</button>
            <button type="button" data-accion="rechazar" class="px-4 py-2 rounded-xl text-sm font-bold bg-white border border-gray-200 text-gray-600 hover:border-red-300 hover:text-red-600 transition">Rechazar</button>
          </div>
        </article>
      <?php endforeach; ?>
      </div>
    </section>

  </div>
</main>

<div id="toast" class="fixed bottom-24 right-6 px-5 py-3 rounded-xl shadow-xl text-white z-[90] hidden text-sm font-bold"></div>

<?php
require_once $app_dir . '/componentes/nav_bottom.php';
require_once $app_dir . '/componentes/modal_publicar.php';
require_once $app_dir . '/componentes/modal_explora.php';
?>
<script>
const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;

function mostrarToast(msg, tipo = 'ok') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'fixed bottom-24 right-6 px-5 py-3 rounded-xl shadow-xl text-white z-[90] text-sm font-bold ' + (tipo === 'ok' ? 'bg-green-600' : 'bg-red-600');
  t.classList.remove('hidden');
  setTimeout(() => t.classList.add('hidden'), 5000);
}

document.getElementById('form-generar').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = document.getElementById('btn-generar');
  const salida = document.getElementById('resultado-generar');
  const fd = new FormData(e.target);
  const body = new URLSearchParams();
  body.append('csrf_token', CSRF_TOKEN);
  body.append('accion', 'generar');
  for (const [k, v] of fd.entries()) body.append(k, v);

  btn.disabled = true; const txt = btn.textContent; btn.textContent = 'Generando… (puede tardar hasta 40 s)';
  try {
    const res = await fetch(window.location.pathname, { method: 'POST', body });
    const d = await res.json();
    salida.classList.remove('hidden');
    if (d.ok) {
      salida.textContent = `${d.insertados} guardados para revisión (${d.proveedor}). Recibidos ${d.recibidos}, válidos ${d.validos}, duplicados ${d.duplicados}, descartados ${d.descartados.length}.`
        + (d.descartados.length ? ' Motivos: ' + [...new Set(d.descartados)].slice(0, 3).join('; ') : '');
      mostrarToast(`${d.insertados} ejercicio${d.insertados === 1 ? '' : 's'} listo${d.insertados === 1 ? '' : 's'} para revisar`, d.insertados > 0 ? 'ok' : 'error');
      if (d.insertados > 0) setTimeout(() => location.reload(), 1800);
    } else {
      const det = (d.intentos || []).map(i => `${i.proveedor}: ${i.error || 'ok'}`).join(' | ');
      salida.textContent = (d.error || 'No se pudo generar.') + (det ? ' — ' + det : '');
      mostrarToast(d.error || 'No se pudo generar', 'error');
    }
  } catch { mostrarToast('Error de conexión', 'error'); }
  finally { btn.disabled = false; btn.textContent = txt; }
});

document.getElementById('lista-pendientes').addEventListener('click', async (e) => {
  const b = e.target.closest('button[data-accion]');
  if (!b) return;
  const tarjeta = b.closest('article');
  const body = new URLSearchParams();
  body.append('csrf_token', CSRF_TOKEN);
  body.append('accion', b.dataset.accion);
  body.append('id', tarjeta.dataset.id);
  tarjeta.querySelectorAll('button').forEach(x => x.disabled = true);
  try {
    const res = await fetch(window.location.pathname, { method: 'POST', body });
    const d = await res.json();
    if (d.ok) { tarjeta.remove(); mostrarToast(b.dataset.accion === 'aprobar' ? 'Aprobado: ya puede servirse en /desafio' : 'Rechazado'); }
    else { mostrarToast(d.error || 'No se pudo', 'error'); tarjeta.querySelectorAll('button').forEach(x => x.disabled = false); }
  } catch { mostrarToast('Error de conexión', 'error'); tarjeta.querySelectorAll('button').forEach(x => x.disabled = false); }
});
</script>
</body>
</html>
