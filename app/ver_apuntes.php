<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login.php");
    exit;
}

$usuario_id  = $_SESSION['usuario_id'];
$institucion = $_SESSION['institucion'] ?? null;
$q           = trim($_GET['q'] ?? '');

$sql     = "SELECT a.id, a.titulo, a.asignatura, a.archivo, a.fecha_subida, u.nombre AS autor
            FROM apuntes a
            JOIN alumnos u ON a.id_alumno = u.id
            WHERE a.institucion = ?";
$params  = [$institucion];
$types   = "s";

if ($q !== '') {
    $sql .= " AND (a.titulo LIKE ? OR a.asignatura LIKE ?)";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    $types   .= "ss";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>📚 Vitrina de Apuntes</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-tr from-blue-50 via-purple-50 to-green-50 min-h-screen text-gray-800">

  <!-- Mobile navbar -->
  <nav class="md:hidden fixed top-0 left-0 w-full bg-white border-b shadow z-40 flex items-center justify-between px-4 h-14">
    <button id="openSidebar" class="p-2 rounded border bg-white">
      <svg class="w-6 h-6 text-blue-700" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round"/></svg>
    </button>
    <span class="text-blue-700 font-bold text-lg">Vitrina de Apuntes</span>
    <span></span>
  </nav>

  <!-- Sidebar overlay -->
  <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-40 z-30 hidden md:hidden"></div>

  <div class="flex pt-14 md:pt-0 min-h-screen">

    <!-- Sidebar -->
    <aside id="sidebar"
           class="fixed top-0 left-0 z-40 w-64 h-full bg-white border-r p-6 flex flex-col justify-between overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform">
      <div>
        <h2 class="text-2xl font-extrabold text-blue-700 mb-6">Menú</h2>
        <ul class="space-y-4 text-sm">
          <li>
            <a href="/dashboard" class="flex items-center gap-2 text-blue-700 hover:underline">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M15 19l-7-7 7-7" stroke-linecap="round"/></svg>
              Dashboard
            </a>
          </li>
        </ul>
      </div>
      <div class="mt-auto pt-6 border-t text-sm space-y-4">
        <button onclick="document.getElementById('modal-soporte').classList.remove('hidden')"
                class="flex items-center gap-2 text-blue-600 hover:underline">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/></svg>
          Soporte
        </button>
        <a href="/logout" class="flex items-center gap-2 text-red-500 hover:underline">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M17 16l4-4m0 0l-4-4m4 4H7" stroke-linecap="round"/><path d="M7 8v8" stroke-linecap="round"/></svg>
          Cerrar sesión
        </a>
      </div>
      <button id="closeSidebar" class="md:hidden absolute top-3 right-3 text-gray-600 hover:text-gray-900">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M6 18L18 6M6 6l12 12" stroke-linecap="round"/></svg>
      </button>
    </aside>

    <!-- Main content -->
    <main class="flex-1 px-4 md:px-8 py-6">

      <!-- Search bar -->
      <form method="GET" class="flex flex-wrap items-center gap-2 mb-6">
        <button type="button" id="openSidebar"
                class="md:hidden p-2 bg-white rounded-full shadow focus:outline-none">
          <svg class="w-6 h-6 text-blue-700" fill="none" stroke="currentColor" stroke-width="2"
               viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round"/></svg>
        </button>
        <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>"
               placeholder="🔍 Buscar por título o asignatura"
               class="flex-1 border rounded px-3 py-2 focus:ring-2 focus:ring-blue-300 focus:outline-none">
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
          Buscar
        </button>
      </form>

      <!-- Grid of cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        <?php if ($result->num_rows > 0): ?>
          <?php while ($a = $result->fetch_assoc()): ?>
            <div class="bg-white rounded-2xl shadow hover:shadow-lg transition flex flex-col">
              <img src="/upload/preview/<?= htmlspecialchars(pathinfo($a['archivo'], PATHINFO_FILENAME)) ?>.png"
                   alt="Miniatura" class="w-full h-40 object-cover rounded-t-2xl">
              <div class="p-4 flex-1 flex flex-col">
                <h3 class="font-semibold text-lg mb-1"><?= htmlspecialchars($a['titulo']) ?></h3>
                <p class="text-sm text-gray-500 mb-2">📘 <?= htmlspecialchars($a['asignatura']) ?></p>
                <p class="text-xs text-gray-600 mb-4">👤 <?= htmlspecialchars($a['autor']) ?></p>
                <p class="text-xs text-gray-600 mb-4">📅 <?= date('d-m-Y', strtotime($a['fecha_subida'])) ?></p>
                <a href="/ver_apunte.php?archivo=<?= urlencode($a['archivo']) ?>"
                   class="mt-auto bg-blue-600 text-white text-center px-4 py-2 rounded-full hover:bg-blue-700 transition">
                  Ver más
                </a>
              </div>
            </div>
          <?php endwhile; ?>
        <?php else: ?>
          <p class="col-span-full text-center text-gray-600">No se encontraron apuntes.</p>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <!-- Soporte Modal -->
  <div id="modal-soporte" class="hidden fixed inset-0 bg-black bg-opacity-40 z-50 flex items-center justify-center">
    <div class="bg-white p-6 rounded-lg shadow-lg max-w-md w-full text-center">
      <h2 class="text-xl font-bold mb-3">Soporte</h2>
      <p class="mb-4">Escríbenos a <a href="mailto:soporte@nubira.online" class="text-blue-600 underline">soporte@nubira.online</a></p>
      <button onclick="document.getElementById('modal-soporte').classList.add('hidden')"
              class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
        Cerrar
      </button>
    </div>
  </div>

  <script>
    // Sidebar toggle
    const sb = document.getElementById('sidebar'),
          ov = document.getElementById('sidebar-overlay'),
          ob = document.querySelectorAll('#openSidebar'),
          cb = document.getElementById('closeSidebar');
    ob.forEach(btn => btn.addEventListener('click', ()=>{
      sb.classList.toggle('-translate-x-full');
      ov.classList.toggle('hidden');
    }));
    cb.addEventListener('click', ()=>{
      sb.classList.add('-translate-x-full');
      ov.classList.add('hidden');
    });
    ov.addEventListener('click', ()=> cb.click());
  </script>

</body>
</html>
<?php
$stmt->close();
$conn->close();
?>
