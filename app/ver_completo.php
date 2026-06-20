<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login");
    exit;
}

$usuario_id  = $_SESSION['usuario_id'];
$institucion = $_SESSION['institucion'] ?? null;
$id_apunte   = intval($_GET['id'] ?? 0);

$stmt = $conn->prepare("
    SELECT a.titulo, a.archivo
    FROM compras c
    JOIN apuntes a ON c.id_apunte = a.id
    WHERE c.id_alumno = ? AND c.id_apunte = ? AND a.institucion = ?
");
$stmt->bind_param("iis", $usuario_id, $id_apunte, $institucion);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>Sin acceso</title>
          <script src='https://cdn.tailwindcss.com'></script></head><body class='bg-gray-100 flex items-center justify-center h-screen'>
          <div class='bg-white p-6 rounded shadow text-center'>
            <p class='text-red-600 font-semibold mb-4'>⚠️ No tienes acceso a este apunte.</p>
            <a href='/mis_compras.php' class='text-blue-600 hover:underline'>Volver a Mis Compras</a>
          </div></body></html>";
    exit;
}
$apunte = $res->fetch_assoc();
$stmt->close();
$archivo = htmlspecialchars($apunte['archivo'], ENT_QUOTES, 'UTF-8');
$titulo  = htmlspecialchars($apunte['titulo'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title><?= $titulo ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { user-select: none; }
  </style>
  <script>
    document.addEventListener('DOMContentLoaded', ()=>{
      // disable right-click & common shortcuts
      document.addEventListener('contextmenu', e=>e.preventDefault());
      document.addEventListener('keydown', e=>{
        if ((e.ctrlKey && ['s','p','u'].includes(e.key.toLowerCase()))
            || e.key==='F12' || e.key==='PrintScreen') {
          e.preventDefault();
          alert('🚫 Acción no permitida.');
        }
      });
    });
  </script>
</head>
<body class="bg-gradient-to-tr from-blue-50 via-purple-50 to-green-50 min-h-screen text-gray-800">

  <!-- Loader -->
  <div id="loader" class="fixed inset-0 flex items-center justify-center bg-white z-50">
    <div class="animate-spin h-12 w-12 border-t-4 border-blue-600 rounded-full"></div>
  </div>

  <!-- Mobile Navbar -->
  <nav class="md:hidden fixed top-0 left-0 w-full bg-white border-b shadow z-40 flex items-center justify-between px-4 h-14">
    <a href="/mis_compras.php" class="text-blue-700 font-bold">&larr;</a>
    <span class="text-blue-700 font-bold text-lg">Ver Apunte</span>
    <span></span>
  </nav>

  <div id="content" class="hidden flex flex-col pt-14 md:pt-0 min-h-screen">

    <!-- Desktop Sidebar -->
    <aside class="hidden md:flex flex-col w-64 bg-white border-r p-6">
      <h2 class="text-2xl font-extrabold text-blue-700 mb-6">Menú</h2>
      <ul class="space-y-4 text-sm">
        <li>
          <a href="/mis_compras.php" class="flex items-center gap-2 text-blue-700 hover:underline">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M15 19l-7-7 7-7" stroke-linecap="round"/>
            </svg> Mis Compras
          </a>
        </li>
      </ul>
      <div class="mt-auto pt-6 border-t text-sm space-y-4">
        <button onclick="document.getElementById('modal-soporte').classList.remove('hidden')"
                class="text-blue-600 hover:underline flex items-center gap-2">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/>
          </svg> Soporte
        </button>
        <a href="/logout" class="flex items-center gap-2 text-red-500 hover:underline">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M17 16l4-4m0 0l-4-4m4 4H7" stroke-linecap="round"/><path d="M7 8v8" stroke-linecap="round"/>
          </svg> Cerrar sesión
        </a>
      </div>
    </aside>

    <!-- Main -->
    <main class="flex-1 flex flex-col items-center justify-start px-2 sm:px-6 py-6">

      <!-- Title -->
      <h1 class="text-2xl font-bold text-center mb-6"><?= $titulo ?></h1>

      <!-- Iframe viewer -->
      <div class="w-full max-w-4xl h-[80vh] mb-6">
        <iframe src="/upload/<?= $archivo ?>"
                class="w-full h-full border-none rounded shadow"
                allowfullscreen
                loading="lazy"></iframe>
      </div>

      <!-- Back button -->
      <a href="/mis_compras.php"
         class="mt-auto bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 transition">
        ⬅️ Volver a Mis Compras
      </a>
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
    window.addEventListener('load', ()=>{
      setTimeout(()=>{
        document.getElementById('loader').classList.add('hidden');
        document.getElementById('content').classList.remove('hidden');
      }, 350);
    });
  </script>
</body>
</html>
