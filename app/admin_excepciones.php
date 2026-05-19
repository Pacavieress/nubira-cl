<?php
session_start();
require_once __DIR__ . '/conexion.php';

// Solo admin puede entrar
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header("Location: /login");
    exit;
}

// Procesar formulario (agregar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nuevo_correo'])) {
    $nuevo_correo = strtolower(trim($_POST['nuevo_correo']));
    $motivo = trim($_POST['motivo'] ?? '');

    if (filter_var($nuevo_correo, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare("INSERT IGNORE INTO excepciones_email (correo, motivo, activo) VALUES (?, ?, 1)");
        $stmt->bind_param("ss", $nuevo_correo, $motivo);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: /admin/excepciones?msg=ok");
    exit;
}

// Cambiar estado (activar/desactivar)
if (isset($_GET['toggle'])) {
    $id = intval($_GET['toggle']);
    $conn->query("UPDATE excepciones_email SET activo = IF(activo=1,0,1) WHERE id = $id");
    header("Location: /admin/excepciones?msg=ok");
    exit;
}

// Eliminar
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $conn->query("DELETE FROM excepciones_email WHERE id = $id");
    header("Location: /admin/excepciones?msg=ok");
    exit;
}

// Obtener lista
$result = $conn->query("SELECT * FROM excepciones_email ORDER BY fecha_agregado DESC");
$excepciones = $result->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Excepciones Gmail - Nubira</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen p-6">
  <div class="max-w-4xl mx-auto bg-white shadow-lg rounded-xl p-6">
    <h1 class="text-2xl font-bold text-blue-600 mb-4">📧 Excepciones Gmail</h1>

    <!-- Formulario agregar -->
    <form method="POST" class="flex flex-col sm:flex-row gap-2 mb-6">
      <input type="email" name="nuevo_correo" placeholder="ejemplo@gmail.com"
             class="flex-1 border rounded-lg px-3 py-2" required>
      <input type="text" name="motivo" placeholder="Motivo (opcional)"
             class="flex-1 border rounded-lg px-3 py-2">
      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">Agregar</button>
    </form>

    <!-- Tabla -->
    <div class="overflow-x-auto">
      <table class="w-full border border-gray-300 rounded-lg">
        <thead class="bg-gray-200">
          <tr>
            <th class="p-2 text-left">Correo</th>
            <th class="p-2">Motivo</th>
            <th class="p-2">Estado</th>
            <th class="p-2">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($excepciones as $exc): ?>
            <tr class="border-t">
              <td class="p-2"><?= htmlspecialchars($exc['correo']) ?></td>
              <td class="p-2"><?= htmlspecialchars($exc['motivo'] ?? '') ?></td>
              <td class="p-2 text-center">
                <?php if ($exc['activo']): ?>
                  <span class="text-green-600 font-bold">Activo</span>
                <?php else: ?>
                  <span class="text-red-600 font-bold">Inactivo</span>
                <?php endif; ?>
              </td>
              <td class="p-2 text-center">
                <a href="?toggle=<?= $exc['id'] ?>" class="text-blue-600 hover:underline">Cambiar estado</a> | 
                <a href="?delete=<?= $exc['id'] ?>" class="text-red-600 hover:underline"
                   onclick="return confirm('¿Eliminar excepción?')">Eliminar</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
