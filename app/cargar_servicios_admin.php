<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    exit;
}

$pagina = max(1, intval($_GET['pagina'] ?? 1));
$busqueda = trim($_GET['q'] ?? '');
$filtro_estado = trim($_GET['estado'] ?? '');
$filtro_categoria = trim($_GET['categoria'] ?? '');
$limite = 10;
$offset = ($pagina - 1) * $limite;

// --- Filtros dinámicos ---
$filtros = [];
$params = [];
$tipos = '';

if ($busqueda !== '') {
    $filtros[] = "(s.titulo LIKE ? OR s.nombre_oferente LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $tipos .= "ss";
}

if ($filtro_estado !== '') {
    $filtros[] = "s.estado = ?";
    $params[] = $filtro_estado;
    $tipos .= "s";
}

if ($filtro_categoria !== '') {
    $filtros[] = "s.categoria = ?";
    $params[] = $filtro_categoria;
    $tipos .= "s";
}

$where = $filtros ? "WHERE " . implode(" AND ", $filtros) : "";

$sql = "SELECT s.*, a.nombre AS nombre_alumno
        FROM servicios s
        LEFT JOIN alumnos a ON s.alumno_id = a.id
        $where
        ORDER BY s.id DESC
        LIMIT ? OFFSET ?";
$params[] = $limite;
$params[] = $offset;
$tipos .= "ii";

$stmt = $conn->prepare($sql);
if ($tipos !== '') $stmt->bind_param($tipos, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$servicios = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Conteo total
$total = $conn->query("SELECT COUNT(*) FROM servicios")->fetch_row()[0];
$total_paginas = ceil($total / $limite);

foreach ($servicios as $row):
?>
<tr id="fila-<?= $row['id'] ?>" class="border-b hover:bg-gray-50 align-top transition">
  <td class="px-3 py-2"><?= $row['id'] ?></td>
  <td class="px-3 py-2 font-medium text-gray-800"><?= htmlspecialchars($row['titulo']) ?></td>
  <td class="px-3 py-2"><?= htmlspecialchars($row['nombre_oferente'] ?? $row['nombre_alumno']) ?></td>
  <td class="px-3 py-2"><?= htmlspecialchars($row['categoria']) ?></td>

  <td class="px-3 py-2 text-center">
    <?php
    $ruta = !empty($row['imagen']) ? "/upload/servicios/" . htmlspecialchars($row['imagen']) : "/upload/servicios/default.webp";
    echo "<img src='{$ruta}' alt='imagen' class='w-20 h-14 object-cover rounded border'>";
    ?>
  </td>

  <td class="px-3 py-2 estado-cell">
    <?php
    $estado = htmlspecialchars($row['estado']);
    $color = match($estado) {
      'aprobado' => 'bg-green-100 text-green-800',
      'rechazado' => 'bg-red-100 text-red-800',
      default => 'bg-yellow-100 text-yellow-800'
    };
    echo "<span class='estado-span px-2 py-1 rounded text-xs {$color}'>".ucfirst($estado)."</span>";
    if ($estado === 'rechazado' && !empty($row['motivo_rechazo'])) {
        echo "<div class='text-xs text-red-600 mt-1 motivo-span'>Motivo: ".htmlspecialchars($row['motivo_rechazo'])."</div>";
    }
    ?>
  </td>

  <td class="px-3 py-2"><?= htmlspecialchars($row['fecha_publicacion']) ?></td>

  <td class="px-3 py-2 text-center space-x-1">
    <?php if ($row['estado'] === 'pendiente'): ?>
      <button onclick="aprobarServicio(<?= $row['id'] ?>)" 
              class="bg-green-500 hover:bg-green-600 text-white px-2 py-1 rounded text-xs">Aprobar</button>
      <button onclick="abrirModalRechazo(<?= $row['id'] ?>)" 
              class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded text-xs">Rechazar</button>
    <?php endif; ?>
    <form method="POST" action="/app/admin_servicios_accion.php" class="inline"
          onsubmit="return confirm('¿Seguro que deseas eliminar este servicio?');">
      <input type="hidden" name="id_servicio" value="<?= $row['id'] ?>">
      <input type="hidden" name="accion" value="eliminar">
      <button class="bg-gray-400 hover:bg-gray-500 text-white px-2 py-1 rounded text-xs">Eliminar</button>
    </form>
  </td>
</tr>
<?php endforeach; ?>

<?php if (empty($servicios)): ?>
<tr><td colspan="8" class="text-center py-4 text-gray-500">No hay resultados</td></tr>
<?php endif; ?>

<!-- PAGINACIÓN -->
<tr>
  <td colspan="8" class="text-center py-3">
    <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
      <button onclick="cargarPagina(<?= $i ?>)" 
              class="mx-1 px-3 py-1 rounded text-sm <?= $i==$pagina ? 'bg-blue-600 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">
        <?= $i ?>
      </button>
    <?php endfor; ?>
  </td>
</tr>
