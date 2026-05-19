<?php
session_start();
require_once '../app/conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../public/login.php');
    exit;
}

$alumno_id = $_SESSION['usuario_id'];
$id_emprendimiento = $_GET['id'] ?? null;

if (!$id_emprendimiento) {
    die("ID inválido.");
}

// Obtener datos de la publicación
$stmt = $conn->prepare("SELECT titulo, descripcion, estado FROM emprendimientos WHERE id = ? AND alumno_id = ?");
$stmt->bind_param("ii", $id_emprendimiento, $alumno_id);
$stmt->execute();
$result = $stmt->get_result();
$emprendimiento = $result->fetch_assoc();
$stmt->close();

if (!$emprendimiento) {
    die("Publicación no encontrada.");
}

$mensaje = '';

// Procesar publicación (cambiar estado a 'publicado')
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $emprendimiento['estado'] === 'pagado') {
    $stmt = $conn->prepare("UPDATE emprendimientos SET estado = 'publicado', fecha_publicacion = NOW() WHERE id = ? AND alumno_id = ?");
    $stmt->bind_param("ii", $id_emprendimiento, $alumno_id);
    if ($stmt->execute()) {
        $mensaje = "Tu emprendimiento se ha publicado exitosamente.";
        $emprendimiento['estado'] = 'publicado';
    } else {
        $mensaje = "Error al publicar. Intenta nuevamente.";
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Editar Emprendimiento</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-6">
  <div class="max-w-xl mx-auto bg-white p-6 rounded shadow">
    <h1 class="text-2xl font-bold mb-4">Editar Emprendimiento</h1>

    <?php if ($mensaje): ?>
      <p class="mb-4 text-green-600"><?= htmlspecialchars($mensaje) ?></p>
    <?php endif; ?>

    <p><strong>Título:</strong> <?= htmlspecialchars($emprendimiento['titulo']) ?></p>
    <p class="mb-4"><strong>Descripción:</strong><br><?= nl2br(htmlspecialchars($emprendimiento['descripcion'])) ?></p>
    <p class="mb-6"><strong>Estado actual:</strong> <?= htmlspecialchars($emprendimiento['estado']) ?></p>

    <form method="POST" action="">
      <button type="submit"
        class="px-4 py-2 rounded text-white
          <?= $emprendimiento['estado'] === 'pagado' ? 'bg-blue-600 hover:bg-blue-700' : 'bg-gray-400 cursor-not-allowed' ?>"
        <?= $emprendimiento['estado'] === 'pagado' ? '' : 'disabled' ?>>
        Publicar
      </button>
    </form>

    <a href="mis_emprendimientos.php" class="inline-block mt-4 text-blue-600 hover:underline">Volver a mis emprendimientos</a>
  </div>
</body>
</html>
