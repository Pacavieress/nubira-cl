<?php
session_start();
require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$id = (int)($_GET['id'] ?? 0);

// Consulta con JOIN para traer título del banner, nombre del alumno y total de clics
$stmt = $conn->prepare("SELECT b.titulo, c.fecha, c.ip, c.url, a.nombre
                        FROM banner_clicks c
                        JOIN banners b ON b.id = c.banner_id
                        LEFT JOIN alumnos a ON a.id = c.usuario_id
                        WHERE c.banner_id = ?
                        ORDER BY c.fecha DESC");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();

// Recuperar el título del banner y contar clics
$tituloBanner = '';
$totalClicks  = $res->num_rows;

if ($rowFirst = $res->fetch_assoc()) {
    $tituloBanner = $rowFirst['titulo'];
    $res->data_seek(0); // volver al inicio del result set
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Historial de clics</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
  <div class="max-w-5xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-1">Historial de clics</h1>
    <p class="text-gray-600">Banner: <span class="font-semibold"><?= htmlspecialchars($tituloBanner) ?></span></p>
    <p class="text-gray-700 mb-6">Total de clics: <span class="font-bold"><?= $totalClicks ?></span></p>

    <div class="overflow-x-auto bg-white rounded shadow">
      <table class="min-w-full border text-sm">
        <thead class="bg-blue-50 text-blue-800">
          <tr>
            <th class="p-2 border">Fecha</th>
            <th class="p-2 border">Usuario</th>
            <th class="p-2 border">IP</th>
            <th class="p-2 border">URL</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($res->num_rows > 0): ?>
            <?php while($row = $res->fetch_assoc()): ?>
              <tr class="border-t hover:bg-gray-50">
                <td class="p-2 border"><?= htmlspecialchars($row['fecha']) ?></td>
                <td class="p-2 border"><?= $row['nombre'] ?: 'Anónimo' ?></td>
                <td class="p-2 border"><?= htmlspecialchars($row['ip']) ?></td>
                <td class="p-2 border">
                  <a href="<?= htmlspecialchars($row['url']) ?>" target="_blank" class="text-blue-600 hover:underline">
                    <?= htmlspecialchars($row['url']) ?>
                  </a>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr>
              <td colspan="4" class="p-4 text-center text-gray-500">No hay clics registrados para este banner.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <a href="admin_banners.php" 
       class="inline-block mt-6 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
       Volver
    </a>
  </div>
</body>
</html>
