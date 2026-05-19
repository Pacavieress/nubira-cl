<?php
require_once '../app/conexion.php';
header('Content-Type: application/json; charset=UTF-8');

$institucion = strtolower(trim($_GET['institucion'] ?? ''));

// --- Filtros dinámicos ---
$where = "WHERE DATE(v.fecha)=CURDATE()";
$params = [];
$types  = '';

if ($institucion !== '') {
  $where .= " AND a.institucion = ?";
  $params[] = $institucion;
  $types .= 's';
}

// --- Estadísticas generales ---
$stmt = $conn->prepare("SELECT COUNT(*) FROM accesos_vitrina v JOIN alumnos a ON a.id=v.usuario_id $where");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute(); $stmt->bind_result($totalAccesos); $stmt->fetch(); $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(DISTINCT v.usuario_id) FROM accesos_vitrina v JOIN alumnos a ON a.id=v.usuario_id $where");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute(); $stmt->bind_result($usuariosUnicos); $stmt->fetch(); $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(DISTINCT a.institucion) FROM accesos_vitrina v JOIN alumnos a ON a.id=v.usuario_id $where");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute(); $stmt->bind_result($institucionesActivas); $stmt->fetch(); $stmt->close();

$stmt = $conn->prepare("SELECT TIME(MAX(v.fecha)) FROM accesos_vitrina v JOIN alumnos a ON a.id=v.usuario_id $where");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute(); $stmt->bind_result($ultimaHora); $stmt->fetch(); $stmt->close();

// --- Últimos accesos ---
$sql = "SELECT a.nombre, a.correo, a.institucion, v.fecha, v.ip
        FROM accesos_vitrina v
        JOIN alumnos a ON a.id=v.usuario_id
        $where
        ORDER BY v.fecha DESC
        LIMIT 50";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

// --- Tabla HTML ---
ob_start(); ?>
<table class="w-full bg-white border shadow-md rounded-lg overflow-hidden">
  <thead class="bg-blue-600 text-white">
    <tr>
      <th class="py-2 px-4 text-left">Alumno</th>
      <th class="py-2 px-4 text-left">Correo</th>
      <th class="py-2 px-4 text-left">Institución</th>
      <th class="py-2 px-4 text-left">Fecha</th>
      <th class="py-2 px-4 text-left">IP</th>
    </tr>
  </thead>
  <tbody>
    <?php while ($r = $res->fetch_assoc()): ?>
    <tr class="border-b hover:bg-blue-50">
      <td class="py-2 px-4"><?= htmlspecialchars($r['nombre']) ?></td>
      <td class="py-2 px-4"><?= htmlspecialchars($r['correo']) ?></td>
      <td class="py-2 px-4"><?= strtoupper(htmlspecialchars($r['institucion'])) ?></td>
      <td class="py-2 px-4"><?= htmlspecialchars($r['fecha']) ?></td>
      <td class="py-2 px-4"><?= htmlspecialchars($r['ip']) ?></td>
    </tr>
    <?php endwhile; ?>
  </tbody>
</table>
<?php
$html = ob_get_clean();

echo json_encode([
  'totalAccesos' => $totalAccesos ?? 0,
  'usuariosUnicos' => $usuariosUnicos ?? 0,
  'institucionesActivas' => $institucionesActivas ?? 0,
  'ultimaHora' => $ultimaHora ?? '--',
  'html' => $html
], JSON_UNESCAPED_UNICODE);
