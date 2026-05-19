<?php
session_start();
require_once '../app/conexion.php';

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
  header("Location: /");
  exit;
}

$guardado = false;

// --- Guardar cambios ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $tipo    = $_POST['tipo'] ?? '';
  $titulo  = trim($_POST['titulo'] ?? '');
  $mensaje = trim($_POST['mensaje'] ?? '');
  $activo  = isset($_POST['activo']) ? 1 : 0;

  if (in_array($tipo, ['movil', 'escritorio'])) {
    $stmt = $conn->prepare("
      INSERT INTO popup_vitrina (tipo, titulo, mensaje, activo)
      VALUES (?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE titulo=VALUES(titulo), mensaje=VALUES(mensaje), activo=VALUES(activo)
    ");
    $stmt->bind_param("sssi", $tipo, $titulo, $mensaje, $activo);
    $stmt->execute();
    $stmt->close();
    $guardado = true;
  }
}

// --- Cargar popups existentes ---
$popups = [];
$res = $conn->query("SELECT * FROM popup_vitrina ORDER BY FIELD(tipo,'escritorio','movil')");
while ($row = $res->fetch_assoc()) {
  $popups[$row['tipo']] = $row;
}
$res->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Admin Popups | Nubira</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root { --nubira: #54A6D8; }
    .text-nubira { color: var(--nubira); }
    .bg-nubira { background-color: var(--nubira); }
  </style>
</head>
<body class="bg-gray-50 min-h-screen text-gray-800">

  <?php include __DIR__ . '/../app/sidebar.php'; ?>

  <main class="md:ml-64 p-6 mt-20 max-w-3xl mx-auto bg-white rounded-xl shadow space-y-10">
    <h1 class="text-2xl font-bold text-nubira mb-6 text-center">🪄 Administrar Popups de Vitrina</h1>

    <?php if ($guardado): ?>
      <div class="bg-green-100 border border-green-300 text-green-800 px-4 py-2 rounded-lg text-sm">
        ✅ Cambios guardados correctamente.
      </div>
    <?php endif; ?>

    <?php foreach (['escritorio'=>'🖥️ Popup Escritorio','movil'=>'📱 Popup Móvil'] as $tipo => $tituloForm): 
      $row = $popups[$tipo] ?? ['titulo'=>'','mensaje'=>'','activo'=>0];
    ?>
      <section class="border border-gray-200 rounded-xl p-5 shadow-sm">
        <h2 class="text-lg font-semibold mb-3 flex items-center gap-2"><?= $tituloForm ?></h2>

        <form method="POST" class="space-y-4">
          <input type="hidden" name="tipo" value="<?= $tipo ?>">

          <div>
            <label class="block text-sm font-semibold mb-1">Título</label>
            <input type="text" name="titulo" value="<?= htmlspecialchars($row['titulo']) ?>"
                   class="w-full border rounded px-3 py-2 text-sm focus:ring-2 focus:ring-nubira/40">
          </div>

          <div>
            <label class="block text-sm font-semibold mb-1">Mensaje</label>
            <textarea name="mensaje" rows="3"
                      class="w-full border rounded px-3 py-2 text-sm focus:ring-2 focus:ring-nubira/40"><?= htmlspecialchars($row['mensaje']) ?></textarea>
          </div>

          <div class="flex items-center gap-2">
            <input type="checkbox" name="activo" <?= !empty($row['activo']) ? 'checked' : '' ?> class="w-4 h-4">
            <label class="text-sm text-gray-700">Mostrar este popup</label>
          </div>

          <div class="flex justify-between items-center">
            <button type="submit"
                    class="bg-nubira hover:bg-[#3d8bc0] text-white px-4 py-2 rounded font-semibold text-sm transition">
              Guardar cambios
            </button>

            <a href="#" onclick="previewPopup('<?= $tipo ?>','<?= htmlspecialchars(addslashes($row['titulo'])) ?>','<?= htmlspecialchars(addslashes($row['mensaje'])) ?>'); return false;"
               class="text-nubira text-sm font-semibold hover:underline">👁️ Vista previa</a>
          </div>
        </form>
      </section>
    <?php endforeach; ?>
  </main>

  <script>
  function previewPopup(tipo, titulo, mensaje) {
    const div = document.createElement('div');
    div.className = 'fixed z-50 bg-white border border-gray-200 shadow-lg rounded-2xl p-4 w-80 max-w-[90%] transform translate-y-5 opacity-0 transition-all duration-300';

    if (tipo === 'movil') {
      div.classList.add('bottom-[5.5rem]', 'left-1/2', '-translate-x-1/2', 'md:hidden');
    } else {
      div.classList.add('right-5', 'bottom-5', 'hidden', 'md:flex');
    }

    div.innerHTML = `
      <div class="flex justify-between items-start w-full">
        <div class="flex-1">
          <h4 class="font-semibold text-nubira text-sm mb-1">${titulo || '(Sin título)'}</h4>
          <p class="text-gray-600 text-[13px] leading-tight">${mensaje || '(Sin mensaje)'}</p>
        </div>
        <button onclick="this.parentElement.parentElement.remove();" class="ml-2 text-gray-400 hover:text-gray-600">
          ✖
        </button>
      </div>
    `;

    document.body.appendChild(div);
    setTimeout(() => div.classList.replace('translate-y-5','translate-y-0'), 50);
  }
  </script>

</body>
</html>
