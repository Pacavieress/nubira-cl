<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    http_response_code(403);
    exit('Acceso denegado');
}

require_once __DIR__ . '/conexion.php';

// --- Acciones ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    $id = intval($_POST['id']);
    $action = $_POST['action'];
    if ($action === 'aprobar') {
        $stmt = $conn->prepare("UPDATE oportunidades SET aprobado=1 WHERE id=?");
    } elseif ($action === 'rechazar') {
        $stmt = $conn->prepare("UPDATE oportunidades SET aprobado=2 WHERE id=?");
    } elseif ($action === 'eliminar') {
        $res = $conn->query("SELECT imagen FROM oportunidades WHERE id=$id");
        $img = $res->fetch_assoc()['imagen'] ?? '';
        if ($img) {
            @unlink(__DIR__ . '/../upload/oportunidades/' . $img);
        }
        $stmt = $conn->prepare("DELETE FROM oportunidades WHERE id=?");
    }
    if (isset($stmt)) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }
    header('Location: /admin/oportunidades');
    exit;
}

$res = $conn->query("SELECT id,titulo,tipo,organizador,fecha_inicio,fecha_termino,aprobado FROM oportunidades ORDER BY fecha_inicio DESC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Admin - Oportunidades</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    /* Scrollbar solo para la tabla */
    .tabla-scroll::-webkit-scrollbar {
      height: 8px;
    }
    .tabla-scroll::-webkit-scrollbar-thumb {
      background: #b3c6e0;
      border-radius: 6px;
    }
  </style>
  <script>
    function toggleSidebar() {
      const sidebar = document.getElementById('sidebar');
      sidebar.classList.toggle('-translate-x-full');
    }
  </script>
</head>
<body class="bg-gray-50 min-h-screen flex">

  <!-- Botón sidebar móvil -->
  <button onclick="toggleSidebar()" class="fixed top-3 left-3 z-50 md:hidden bg-blue-600 text-white p-2 rounded-full shadow-lg">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
    </svg>
  </button>

  <!-- SIDEBAR -->
  <aside id="sidebar"
    class="fixed top-0 left-0 z-40 w-64 h-full bg-white border-r p-6
           flex flex-col justify-between overflow-y-auto
           transform -translate-x-full md:translate-x-0
           transition-transform duration-200 shadow-lg md:shadow-none">
    <div>
      <h2 class="text-2xl font-extrabold mb-4 text-blue-700 select-none">Admin</h2>
      <ul class="space-y-3 text-sm">
        <li><a href="/dashboard" class="flex items-center gap-2 text-blue-700 font-semibold hover:underline">
          ← Volver al Dashboard
        </a></li>
      </ul>
    </div>
  </aside>

  <!-- CONTENIDO PRINCIPAL -->
  <main class="flex-1 w-full lg:ml-64 p-2 sm:p-4 md:p-8">
    <div class="max-w-6xl mx-auto bg-white shadow-lg rounded-2xl p-2 sm:p-6">
      <h1 class="text-2xl sm:text-3xl font-extrabold text-blue-700 mb-4 sm:mb-6">Gestión de Oportunidades</h1>

      <div class="overflow-x-auto tabla-scroll rounded-xl border border-gray-200 shadow-inner">
        <table class="min-w-full bg-white text-xs sm:text-sm rounded-lg overflow-hidden">
          <thead class="bg-blue-600 text-white">
            <tr>
              <th class="px-2 py-2 sm:px-4 sm:py-3 text-left">ID</th>
              <th class="px-2 py-2 sm:px-4 sm:py-3 text-left">Título</th>
              <th class="px-2 py-2 sm:px-4 sm:py-3 text-left">Tipo</th>
              <th class="px-2 py-2 sm:px-4 sm:py-3 text-left">Organizador</th>
              <th class="px-2 py-2 sm:px-4 sm:py-3 text-left">Fechas</th>
              <th class="px-2 py-2 sm:px-4 sm:py-3 text-left">Estado</th>
              <th class="px-2 py-2 sm:px-4 sm:py-3 text-left">Acciones</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php while($row = $res->fetch_assoc()):
              if ($row['aprobado'] == 1) {
                $label = 'Aprobada'; $color='bg-green-200 text-green-800';
              } elseif ($row['aprobado'] == 2) {
                $label = 'Rechazada'; $color='bg-red-200 text-red-800';
              } else {
                $label = 'Pendiente'; $color='bg-yellow-200 text-yellow-800';
              }
            ?>
            <tr>
              <td class="px-2 py-2 sm:px-4 sm:py-2"><?= $row['id'] ?></td>
              <td class="px-2 py-2 sm:px-4 sm:py-2"><?= htmlspecialchars($row['titulo']) ?></td>
              <td class="px-2 py-2 sm:px-4 sm:py-2"><?= htmlspecialchars($row['tipo']) ?></td>
              <td class="px-2 py-2 sm:px-4 sm:py-2"><?= htmlspecialchars($row['organizador']) ?></td>
              <td class="px-2 py-2 sm:px-4 sm:py-2 whitespace-nowrap"><?= htmlspecialchars($row['fecha_inicio']) ?> – <?= htmlspecialchars($row['fecha_termino']) ?></td>
              <td class="px-2 py-2 sm:px-4 sm:py-2">
                <span class="px-2 py-1 rounded-full <?= $color ?>"><?= $label ?></span>
              </td>
              <td class="px-2 py-2 sm:px-4 sm:py-2">
                <div class="flex flex-col sm:flex-row gap-1">
                <?php if ($row['aprobado'] !== 1): ?>
                  <form method="POST" class="inline">
                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                    <input type="hidden" name="action" value="aprobar">
                    <button type="submit" class="w-full sm:w-auto px-3 py-1 bg-green-600 text-white rounded hover:bg-green-700 text-xs">Aprobar</button>
                  </form>
                <?php endif; ?>
                <?php if ($row['aprobado'] !== 2): ?>
                  <form method="POST" class="inline">
                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                    <input type="hidden" name="action" value="rechazar">
                    <button type="submit" class="w-full sm:w-auto px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700 text-xs">Rechazar</button>
                  </form>
                <?php endif; ?>
                  <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar oportunidad #<?= $row['id'] ?>?');">
                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                    <input type="hidden" name="action" value="eliminar">
                    <button type="submit" class="w-full sm:w-auto px-3 py-1 bg-gray-600 text-white rounded hover:bg-gray-700 text-xs">Eliminar</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</body>
</html>
