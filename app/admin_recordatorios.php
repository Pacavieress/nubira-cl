<?php
session_start();
require_once __DIR__ . '/../app/conexion.php';

// Opcional pero recomendado: asegurar zona horaria coherente
date_default_timezone_set('America/Santiago');

// Solo admins
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header("Location: /login");
    exit;
}

// --- Parámetros de filtro ---
$filtro_fecha  = $_GET['fecha']  ?? '';
$filtro_tipo   = $_GET['tipo']   ?? '';
$filtro_estado = $_GET['estado'] ?? '';

// --- Resumen general ---
$hoy = date('Y-m-d');

$stmt = $conn->prepare("
    SELECT 
        SUM(estado = 'enviado') AS enviados,
        SUM(estado != 'enviado') AS pendientes
    FROM acciones_pendientes
    WHERE DATE(enviado_en) = ?
");
if (!$stmt) {
    die('Error preparando resumen de recordatorios: ' . $conn->error);
}
$stmt->bind_param('s', $hoy);
$stmt->execute();
$stmt->bind_result($enviados_hoy, $pendientes_hoy);
$stmt->fetch();
$stmt->close();

// Normalizar por si vienen NULL
$enviados_hoy   = (int)($enviados_hoy   ?? 0);
$pendientes_hoy = (int)($pendientes_hoy ?? 0);

// --- Construir consulta dinámica según filtros ---
$condiciones = [];
$parametros = [];
$tipos = '';

if (!empty($filtro_fecha)) {
    $condiciones[] = 'DATE(ap.enviado_en) = ?';
    $parametros[] = $filtro_fecha;
    $tipos .= 's';
}
if (!empty($filtro_tipo)) {
    $condiciones[] = 'ap.tipo = ?';
    $parametros[] = $filtro_tipo;
    $tipos .= 's';
}
if (!empty($filtro_estado)) {
    $condiciones[] = 'ap.estado = ?';
    $parametros[] = $filtro_estado;
    $tipos .= 's';
}

$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

$sql = "
SELECT 
    ap.id,
    a.nombre AS alumno,
    a.correo,
    ap.tipo,
    ap.etapa,
    ap.programado_para,
    ap.enviado_en,
    ap.estado,
    ap.motivo_omision
FROM acciones_pendientes ap
LEFT JOIN alumnos a ON ap.alumno_id = a.id
$where
ORDER BY ap.programado_para DESC, ap.id DESC
LIMIT 300
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Error preparando consulta de recordatorios: ' . $conn->error);
}
if ($parametros) {
    $stmt->bind_param($tipos, ...$parametros);
}
$stmt->execute();
$result = $stmt->get_result();
$registros = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Helper para mostrar tipo más entendible
function labelTipo(string $tipo): string {
    return match ($tipo) {
        'recordatorio_3dias'  => '3 días – Publicar',
        'recordatorio_7dias'  => '7 días – Explorar',
        'recordatorio_14dias' => '14 días – Reenganche',
        default               => $tipo,
    };
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recordatorios automáticos - Nubira</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  /* fuerza a dejar espacio exacto bajo el header fijo */
  main {
    margin-top: 130px !important; /* puedes ajustar 130 → 140 o 150 si sigue tapando */
  }

  /* opcional: en pantallas grandes, compensa el sidebar */
  @media (min-width: 768px) {
    main {
      margin-left: 18rem; /* igual al ancho de tu sidebar md:ml-72 */
    }
  }
</style>

</head>
<body class="bg-gray-50 text-gray-800 font-[Poppins]">
<header class="fixed top-0 left-0 right-0 z-40 backdrop-blur-md bg-white/90 border-b border-gray-200 shadow-sm">
  <div class="max-w-6xl mx-auto flex flex-col sm:flex-row sm:items-center sm:justify-between h-auto px-6 py-3 space-y-2 sm:space-y-0">
    <span class="text-[#54A6D8] font-bold text-xl">📬 Recordatorios Automáticos</span>

    <form method="post" action="ejecutar_recordatorios.php"
          onsubmit="return confirm('¿Deseas ejecutar el envío manualmente?');">
      <button type="submit"
        class="bg-[#54A6D8] hover:bg-[#4693c0] text-white px-5 py-2 rounded-lg shadow text-sm font-semibold flex items-center gap-2">
        🔄 Ejecutar ahora
      </button>
    </form>
  </div>
</header>


<!-- Sidebar fijo (solo escritorio) -->
<aside class="hidden md:flex md:flex-col fixed top-[5.5rem] left-0 h-[calc(100%-5.5rem)] w-64 
             bg-white border-r border-gray-200 shadow-lg z-30">

  <div class="p-6">
    <nav class="flex flex-col space-y-3">
      <a href="/vitrina" class="text-gray-700 hover:text-[#54A6D8]">🏠 Inicio</a>
      <a href="/dashboard" class="text-gray-700 hover:text-[#54A6D8]">⚙️ Perfil</a>
      <a href="/vitrina-apuntes" class="text-gray-700 hover:text-[#54A6D8]">📘 Explorar Apuntes</a>
      <a href="/clases-servicios" class="text-gray-700 hover:text-[#54A6D8]">🧑‍🏫 Explorar Servicios</a>
      <!-- 💬 Mis Chats con badge -->
      <a href="#" 
         onclick="abrirMisChats(); return false;"
         class="flex justify-between items-center text-gray-700 hover:text-[#54A6D8]">
        <span>💬 Mis Chats</span>
        <span id="badge-chats-sidebar"
              class="ml-2 bg-[#54A6D8] text-white text-[10px] font-bold px-2 py-0.5 rounded-full hidden">
          0
        </span>
      </a>

      <a href="/oportunidades" class="text-gray-700 hover:text-[#54A6D8]">🎯 Explorar Oportunidades</a>
    </nav>
  </div>
</aside>

<!-- Contenido principal -->
<main class="mt-[6.5rem] md:ml-72 p-4 md:p-8 max-w-7xl mx-auto space-y-6 transition-all">

  <!-- Resumen -->
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div class="bg-blue-50 border border-blue-200 p-4 rounded-lg text-blue-700 text-center">
      <p class="text-2xl font-bold"><?= $enviados_hoy ?></p>
      <p class="text-sm">Correos enviados hoy</p>
    </div>
    <div class="bg-yellow-50 border border-yellow-200 p-4 rounded-lg text-yellow-700 text-center">
      <p class="text-2xl font-bold"><?= $pendientes_hoy ?></p>
      <p class="text-sm">Pendientes / con error</p>
    </div>
    <div class="bg-gray-50 border border-gray-200 p-4 rounded-lg text-gray-600 text-center">
      <p class="text-2xl font-bold"><?= count($registros) ?></p>
      <p class="text-sm">Registros mostrados</p>
    </div>
  </div>

  <!-- Filtros -->
  <form method="get" class="bg-white shadow rounded-lg p-4 flex flex-col sm:flex-wrap md:flex-row md:items-end gap-4">
    <div class="flex flex-col w-full sm:w-auto">
      <label class="text-sm font-semibold text-gray-600 mb-1">Fecha envío</label>
      <input type="date" name="fecha" value="<?= htmlspecialchars($filtro_fecha) ?>" class="border rounded px-3 py-2 text-sm w-full">
    </div>
    <div class="flex flex-col w-full sm:w-auto">
      <label class="text-sm font-semibold text-gray-600 mb-1">Tipo de recordatorio</label>
      <select name="tipo" class="border rounded px-3 py-2 text-sm w-full">
        <option value="">Todos</option>
        <option value="recordatorio_3dias" <?= $filtro_tipo === 'recordatorio_3dias' ? 'selected' : '' ?>>3 días – Publicar</option>
        <option value="recordatorio_7dias" <?= $filtro_tipo === 'recordatorio_7dias' ? 'selected' : '' ?>>7 días – Explorar</option>
        <option value="recordatorio_14dias" <?= $filtro_tipo === 'recordatorio_14dias' ? 'selected' : '' ?>>14 días – Reenganche</option>
      </select>
    </div>
    <div class="flex flex-col w-full sm:w-auto">
      <label class="text-sm font-semibold text-gray-600 mb-1">Estado</label>
      <select name="estado" class="border rounded px-3 py-2 text-sm w-full">
        <option value="">Todos</option>
        <option value="enviado" <?= $filtro_estado === 'enviado' ? 'selected' : '' ?>>Enviado</option>
        <option value="pendiente" <?= $filtro_estado === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
      </select>
    </div>
    <div class="flex gap-2">
      <button type="submit" class="bg-[#54A6D8] hover:bg-[#4693c0] text-white px-4 py-2 rounded-lg text-sm font-semibold">Filtrar</button>
      <a href="admin_recordatorios.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-semibold">Limpiar</a>
    </div>
  </form>

  <!-- Tabla -->
  <div class="bg-white rounded-lg shadow p-4 overflow-x-auto">
    <table class="min-w-full border border-gray-200 text-sm">
      <thead class="bg-[#54A6D8] text-white">
        <tr>
          <th class="py-2 px-3 text-left">#</th>
          <th class="py-2 px-3 text-left">Alumno</th>
          <th class="py-2 px-3 text-left">Correo</th>
          <th class="py-2 px-3 text-left">Tipo</th>
          <th class="py-2 px-3 text-center">Etapa</th>
          <th class="py-2 px-3 text-center">Programado</th>
          <th class="py-2 px-3 text-center">Enviado</th>
          <th class="py-2 px-3 text-center">Estado</th>
          <th class="py-2 px-3 text-left">Motivo / Observación</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($registros)): ?>
          <tr><td colspan="9" class="text-center py-6 text-gray-500">Sin registros con esos filtros</td></tr>
        <?php else: ?>
          <?php foreach ($registros as $r): ?>
            <tr class="hover:bg-gray-50">
              <td class="py-2 px-3 text-gray-500"><?= (int)$r['id'] ?></td>
              <td class="py-2 px-3 font-medium break-words"><?= htmlspecialchars($r['alumno']) ?></td>
              <td class="py-2 px-3 text-gray-600 break-all"><?= htmlspecialchars($r['correo']) ?></td>
              <td class="py-2 px-3 font-semibold text-[#54A6D8]">
                <?= htmlspecialchars(labelTipo($r['tipo'])) ?>
              </td>
              <td class="py-2 px-3 text-center"><?= htmlspecialchars((string)$r['etapa']) ?></td>
              <td class="py-2 px-3 text-center text-gray-500"><?= htmlspecialchars((string)$r['programado_para']) ?></td>
              <td class="py-2 px-3 text-center text-gray-500"><?= $r['enviado_en'] ? htmlspecialchars((string)$r['enviado_en']) : '—' ?></td>
              <td class="py-2 px-3 text-center">
                <?php if ($r['estado'] === 'enviado'): ?>
                  <span class="bg-green-100 text-green-700 px-2 py-1 rounded text-xs font-semibold">Enviado</span>
                <?php else: ?>
                  <span class="bg-yellow-100 text-yellow-700 px-2 py-1 rounded text-xs font-semibold"><?= htmlspecialchars((string)$r['estado']) ?></span>
                <?php endif; ?>
              </td>
              <td class="py-2 px-3 text-gray-600 break-words"><?= htmlspecialchars((string)$r['motivo_omision']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="mt-4 flex flex-col sm:flex-row justify-between text-gray-500 text-sm gap-2">
    <p>Mostrando <?= count($registros) ?> registros filtrados.</p>
    <button onclick="location.reload()" class="text-[#54A6D8] hover:underline">🔄 Actualizar</button>
  </div>
</main>

</body>
</html>
