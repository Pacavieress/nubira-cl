<?php
session_start();
require_once 'conexion.php';

// Solo permitir admins
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header('Location: /login');
    exit;
}

$mensaje = '';

// Eliminar banco
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    $stmtDelete = $conn->prepare("DELETE FROM bancos WHERE id = ?");
    $stmtDelete->bind_param("i", $id);
    if ($stmtDelete->execute()) {
        header("Location: /admin/agregar-banco?msg=" . urlencode("🗑️ Banco eliminado correctamente."));
        exit;
    } else {
        header("Location: /admin/agregar-banco?msg=" . urlencode("❌ Error al eliminar el banco."));
        exit;
    }
}

// Mensaje después de redirect (flash)
if (isset($_GET['msg'])) {
    $mensaje = htmlspecialchars($_GET['msg']);
}

// Agregar banco
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nuevo_banco = trim($_POST['nombre_banco']);

    if (!empty($nuevo_banco)) {
        // Verificar si ya existe
        $check = $conn->prepare("SELECT id FROM bancos WHERE nombre = ?");
        $check->bind_param("s", $nuevo_banco);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $mensaje = "⚠️ El banco ya existe.";
        } else {
            $insert = $conn->prepare("INSERT INTO bancos (nombre) VALUES (?)");
            $insert->bind_param("s", $nuevo_banco);
            if ($insert->execute()) {
                header("Location: /admin/agregar-banco?msg=" . urlencode("✅ Banco agregado correctamente."));
                exit;
            } else {
                $mensaje = "❌ Error al agregar el banco.";
            }
        }
    } else {
        $mensaje = "⚠️ El nombre no puede estar vacío.";
    }
}

// Obtener lista actualizada de bancos
$bancos = $conn->query("SELECT * FROM bancos ORDER BY nombre ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Admin Bancos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-800 min-h-screen flex">

  <!-- SIDEBAR ADMIN -->
  <aside class="w-64 bg-white border-r p-6 hidden md:block min-h-screen">
    <h1 class="text-2xl font-extrabold mb-6 text-blue-700">Admin Bancos</h1>
    <ul class="space-y-3 text-sm">
      <li><a href="/dashboard" class="text-blue-600 hover:underline inline-flex items-center gap-2">Volver atrás</a></li>
    </ul>
  </aside>

  <!-- CONTENIDO PRINCIPAL -->
  <main class="flex-1 flex flex-col items-center justify-center px-4">
    <div class="max-w-xl w-full bg-white p-6 rounded shadow mt-8">
      <h2 class="text-xl font-bold mb-4">🏦 Administrar Bancos</h2>

      <?php if ($mensaje): ?>
        <div class="mb-4 text-blue-700 font-semibold"><?= $mensaje ?></div>
      <?php endif; ?>

      <form method="POST" class="mb-6">
        <label class="block font-medium mb-1">Nombre del nuevo banco:</label>
        <input type="text" name="nombre_banco" class="w-full border px-3 py-2 rounded" required>
        <button type="submit" class="mt-3 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">➕ Agregar Banco</button>
      </form>

      <h3 class="text-lg font-bold mb-2">🏦 Bancos Registrados:</h3>
      <ul class="list-disc pl-6 space-y-2 text-sm">
        <?php while ($b = $bancos->fetch_assoc()): ?>
          <li class="flex justify-between items-center">
            <span><?= htmlspecialchars($b['nombre']) ?></span>
            <a href="/admin/agregar-banco?eliminar=<?= $b['id'] ?>" onclick="return confirm('¿Eliminar este banco?')" class="text-red-600 text-xs ml-4 hover:underline">Eliminar</a>
          </li>
        <?php endwhile; ?>
      </ul>
    </div>
  </main>
</body>
</html>
