<?php
session_start();
require_once 'conexion.php';

// Solo admins pueden usarlo
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['corregir_urls'])) {
    $sql = "SELECT id, enlace FROM banners";
    $result = $conn->query($sql);

    $corregidos = 0;

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $id = $row['id'];
            $enlace = trim($row['enlace']);

            if ($enlace !== '' && !preg_match('#^https?://#i', $enlace)) {
                $nuevo_enlace = 'https://' . $enlace;

                $stmt = $conn->prepare("UPDATE banners SET enlace = ? WHERE id = ?");
                $stmt->bind_param("si", $nuevo_enlace, $id);
                if ($stmt->execute()) {
                    $corregidos++;
                }
                $stmt->close();
            }
        }
    }

    $mensaje = "Se corrigieron $corregidos URLs en la base de datos.";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8" />
<title>Corregir URLs de Banners</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-800 p-6">
  <h1 class="text-2xl font-bold mb-4">Corregir URLs de Banners</h1>

  <?php if ($mensaje): ?>
    <div class="mb-4 p-3 bg-green-100 text-green-800 rounded"><?= htmlspecialchars($mensaje) ?></div>
  <?php endif; ?>

  <form method="POST">
    <button 
      type="submit" 
      name="corregir_urls" 
      class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700"
      onclick="return confirm('¿Estás seguro de corregir todas las URLs sin protocolo?')"
    >
      Ejecutar Corrección
    </button>
  </form>

  <p class="mt-6 text-sm text-gray-600">
    Este proceso agregará <code>https://</code> al inicio de cualquier URL en los banners que no la tengan.
  </p>

  <a href="/admin/banners.php" class="mt-6 inline-block text-blue-600 hover:underline">Volver a Admin Banners</a>
</body>
</html>
