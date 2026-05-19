<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/conexion.php';

// --- Solo accesible para administrador ---
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    die('⛔ Acceso restringido.');
}

/* ---------- Filtros ---------- */
$busqueda = trim($_GET['q'] ?? '');
$estado   = trim($_GET['estado'] ?? '');
$tipo     = trim($_GET['tipo'] ?? '');
$fecha_desde = $_GET['desde'] ?? '';
$fecha_hasta = $_GET['hasta'] ?? '';

$where = [];
$params = [];
$types  = '';

if ($busqueda !== '') {
    $where[] = "(a.nombre LIKE CONCAT('%', ?, '%') OR a.correo LIKE CONCAT('%', ?, '%'))";
    $params[] = $busqueda;
    $params[] = $busqueda;
    $types .= 'ss';
}
if ($estado !== '') {
    $where[] = "ap.estado = ?";
    $params[] = $estado;
    $types .= 's';
}
if ($tipo !== '') {
    $where[] = "ap.tipo = ?";
    $params[] = $tipo;
    $types .= 's';
}
if ($fecha_desde !== '' && $fecha_hasta !== '') {
    $where[] = "DATE(ap.programado_para) BETWEEN ? AND ?";
    $params[] = $fecha_desde;
    $params[] = $fecha_hasta;
    $types .= 'ss';
}

$sql = "
SELECT 
    ap.id,
    ap.alumno_id,
    a.nombre,
    a.correo,
    ap.tipo,
    ap.etapa,
    ap.programado_para,
    ap.enviado_en,
    ap.estado,
    ap.motivo_omision
FROM acciones_pendientes ap
LEFT JOIN alumnos a ON a.id = ap.alumno_id
";

if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= " ORDER BY ap.creado_en DESC LIMIT 200";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>📬 Log de Recordatorios - Nubira</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
  <div class="max-w-7xl mx-auto py-10 px-4">
    <h1 class="text-3xl font-bold mb-6 text-[#54A6D8]">📬 Log de recordatorios automáticos</h1>

    <!-- 🔍 Filtros -->
    <form method="GET" class="bg-white shadow-md rounded-lg p-4 mb-6 grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
      <div>
        <label class="block text-sm font-semibold text-gray-600 mb-1">Buscar</label>
        <input type="text" name="q" placeholder="Nombre o correo..." value="<?= htmlspecialchars($busqueda) ?>" class="w-full border rounded-md px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-semibold text-gray-600 mb-1">Estado</label>
        <select name="estado" class="w-full border rounded-md px-3 py-2">
          <option value="">Todos</option>
          <option value="pendiente" <?= $estado=='pendiente'?'selected':'' ?>>Pendiente</option>
          <option value="enviado" <?= $estado=='enviado'?'selected':'' ?>>Enviado</option>
          <option value="omitido" <?= $estado=='omitido'?'selected':'' ?>>Omitido</option>
          <option value="bloqueado" <?= $estado=='bloqueado'?'selected':'' ?>>Bloqueado</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-semibold text-gray-600 mb-1">Tipo</label>
        <input type="text" name="tipo" placeholder="Ej: recordatorio_7dias" value="<?= htmlspecialchars($tipo) ?>" class="w-full border rounded-md px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-semibold text-gray-600 mb-1">Desde</label>
        <input type="date" name="desde" value="<?= htmlspecialchars($fecha_desde) ?>" class="w-full border rounded-md px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-semibold text-gray-600 mb-1">Hasta</label>
        <div class="flex gap-2">
          <input type="date" name="hasta" value="<?= htmlspecialchars($fecha_hasta) ?>" class="w-full border rounded-md px-3 py-2">
          <button class="bg-[#54A6D8] text-white px-3 py-2 rounded-md hover:bg-[#3b8fc6] transition">Filtrar</button>
        </div>
      </div>
    </form>

    <!-- 📊 Tabla -->
    <div class="bg-white shadow-md rounded-lg overflow-x-auto">
      <table class="min-w-full text-sm text-left">
        <thead class="bg-[#54A6D8] text-white">
          <tr>
            <th class="px-4 py-2">#</th>
            <th class="px-4 py-2">Alumno</th>
            <th class="px-4 py-2">Correo</th>
            <th class="px-4 py-2">Tipo</th>
            <th class="px-4 py-2">Etapa</th>
            <th class="px-4 py-2">Programado para</th>
            <th class="px-4 py-2">Enviado en</th>
            <th class="px-4 py-2">Estado</th>
            <th class="px-4 py-2">Motivo / Observación</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($result && $result->num_rows > 0): ?>
          <?php while ($row = $result->fetch_assoc()): ?>
            <tr class="border-b hover:bg-gray-50">
              <td class="px-4 py-2 font-semibold text-gray-600"><?= $row['id'] ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($row['nombre'] ?? '—') ?></td>
              <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($row['correo'] ?? '—') ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($row['tipo'] ?? '—') ?></td>
              <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($row['etapa']) ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($row['programado_para']) ?></td>
              <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($row['enviado_en'] ?? '—') ?></td>
              <td class="px-4 py-2">
                <?php if ($row['estado'] === 'enviado'): ?>
                  <span class="bg-green-100 text-green-700 px-2 py-1 rounded">Enviado</span>
                <?php elseif ($row['estado'] === 'pendiente'): ?>
                  <span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded">Pendiente</span>
                <?php elseif ($row['estado'] === 'omitido'): ?>
                  <span class="bg-gray-200 text-gray-700 px-2 py-1 rounded">Omitido</span>
                <?php elseif ($row['estado'] === 'bloqueado'): ?>
                  <span class="bg-red-100 text-red-700 px-2 py-1 rounded">Bloqueado</span>
                <?php else: ?>
                  <span class="bg-gray-100 text-gray-700 px-2 py-1 rounded"><?= htmlspecialchars($row['estado']) ?></span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($row['motivo_omision'] ?? '—') ?></td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr><td colspan="9" class="text-center text-gray-500 py-6">No hay registros con esos filtros.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
