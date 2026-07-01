<?php
session_start();

$app_dir = dirname(__DIR__) . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__ . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__;

require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /vitrina'); exit;
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
      <a href="/app/enviar_recuperar_gmails.php?limite=5"
         class="shrink-0 inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm font-bold text-gray-600 hover:border-[#54A6D8] hover:text-[#54A6D8] transition shadow-sm">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
        </svg>
        Reenviar campaña
      </a>
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
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100 text-gray-500 text-xs font-semibold uppercase tracking-wider">
          <tr>
            <th class="px-5 py-3.5 text-left">Correo</th>
            <th class="px-5 py-3.5 text-left">Estado</th>
            <th class="px-5 py-3.5 text-left hidden md:table-cell">Intento original</th>
            <th class="px-5 py-3.5 text-left hidden md:table-cell">Último email</th>
            <th class="px-5 py-3.5 text-right">Acción</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
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
          <tr class="hover:bg-gray-50/70 transition-colors">

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
        <?= count($leads) ?> de <?= $total ?> leads
      </div>
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
