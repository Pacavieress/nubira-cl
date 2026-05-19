<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$query = "SELECT * FROM empleos WHERE estado = 'pendiente' ORDER BY fecha_publicacion DESC";
$resultado = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Empleos Pendientes</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>

<body class="bg-gray-100 min-h-screen p-6">

    <div class="max-w-5xl mx-auto">
        <h1 class="text-3xl font-bold mb-6">🛠 Empleos Pendientes de Aprobación</h1>

        <?php if ($resultado->num_rows > 0): ?>
            <div class="space-y-4">
                <?php while ($empleo = $resultado->fetch_assoc()): ?>
                    <div class="bg-white shadow rounded p-6">
                        <h2 class="text-xl font-semibold"><?= htmlspecialchars($empleo['titulo']) ?></h2>
                        <p class="text-gray-700"><?= htmlspecialchars($empleo['empresa']) ?> — <?= htmlspecialchars($empleo['ubicacion']) ?></p>
                        <p class="text-sm text-gray-500 mb-4">
                            <?= htmlspecialchars($empleo['tipo']) ?> | <?= htmlspecialchars($empleo['modalidad']) ?>
                        </p>
                        <p class="mb-4"><?= nl2br(htmlspecialchars($empleo['descripcion'])) ?></p>

                        <div class="flex gap-4">
                            <form action="aprobar_empleo.php" method="POST">
                                <input type="hidden" name="id" value="<?= $empleo['id'] ?>">
                                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded">✅ Aprobar</button>
                            </form>
                            <form action="rechazar_empleo.php" method="POST">
                                <input type="hidden" name="id" value="<?= $empleo['id'] ?>">
                                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">❌ Rechazar</button>
                            </form>
                            <!-- Botón Eliminar (solo admin) -->
                            <form action="eliminar_empleo.php" method="POST" onsubmit="return confirm('¿Eliminar esta oferta permanentemente?');">
                                <input type="hidden" name="id" value="<?= $empleo['id'] ?>">
                                <button type="submit" class="bg-red-700 hover:bg-red-800 text-white px-4 py-2 rounded">🗑️ Eliminar</button>
                            </form>

                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <p class="text-center text-gray-600">No hay empleos pendientes.</p>
        <?php endif; ?>
    </div>

</body>

</html>