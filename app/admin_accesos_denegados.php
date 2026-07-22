<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Filtros básicos por usuario o archivo
$usuario_id_filtro = $_GET['usuario_id'] ?? '';
$archivo_filtro = $_GET['archivo'] ?? '';
$where = [];
$params = [];
$types = '';

if ($usuario_id_filtro !== '') {
    $where[] = "usuario_id = ?";
    $params[] = $usuario_id_filtro;
    $types .= 'i';
}
if ($archivo_filtro !== '') {
    $where[] = "archivo LIKE ?";
    $params[] = '%' . $archivo_filtro . '%';
    $types .= 's';
}

$sql = "SELECT * FROM accesos_denegados";
if (count($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY fecha DESC LIMIT 200"; // Limita los últimos 200 accesos

$stmt = $conn->prepare($sql);
if (count($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Auditoría de accesos denegados</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <section class="max-w-6xl mx-auto py-8 px-4">
        <h1 class="text-3xl font-bold mb-6">🔒 Auditoría: Accesos Denegados</h1>
        <form class="flex flex-wrap gap-4 mb-6" method="get">
            <input type="text" name="usuario_id" placeholder="Filtrar por ID usuario" value="<?= htmlspecialchars($usuario_id_filtro) ?>"
                class="px-4 py-2 border rounded" style="max-width:160px;">
            <input type="text" name="archivo" placeholder="Filtrar por archivo" value="<?= htmlspecialchars($archivo_filtro) ?>"
                class="px-4 py-2 border rounded" style="max-width:220px;">
            <button class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">Filtrar</button>
            <a href="admin_accesos_denegados.php" class="text-blue-600 underline mt-2">Limpiar filtros</a>
        </form>
        <div class="overflow-x-auto">
            <table class="min-w-full bg-white shadow rounded text-sm">
                <thead>
                    <tr class="bg-blue-100">
                        <th class="px-2 py-2">Fecha</th>
                        <th class="px-2 py-2">Usuario ID</th>
                        <th class="px-2 py-2">Archivo</th>
                        <th class="px-2 py-2">IP</th>
                        <th class="px-2 py-2">Motivo</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-2 py-1"><?= htmlspecialchars($row['fecha']) ?></td>
                        <td class="px-2 py-1"><?= htmlspecialchars($row['usuario_id']) ?></td>
                        <td class="px-2 py-1"><?= htmlspecialchars($row['archivo']) ?></td>
                        <td class="px-2 py-1"><?= htmlspecialchars($row['ip']) ?></td>
                        <td class="px-2 py-1"><?= htmlspecialchars($row['motivo']) ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php if ($result->num_rows == 0): ?>
                <div class="text-center mt-6 text-gray-500">No hay registros que coincidan.</div>
            <?php endif; ?>
        </div>
    </section>
</body>
</html>
