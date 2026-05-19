<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/conexion.php';

echo "<h2>🔍 Sincronizando previews y portadas...</h2>";

$dirPreview = __DIR__ . '/../upload/preview/';
if (!is_dir($dirPreview)) {
    die("❌ Carpeta no encontrada: $dirPreview");
}

$res = $conn->query("SELECT id, archivo, portada, preview FROM apuntes ORDER BY id ASC");
$total = 0;

while ($row = $res->fetch_assoc()) {
    $id = (int)$row['id'];
    $actual = $row['portada'];
    $preview = $row['preview'];

    // Verificamos si ya existe el preview por ID
    $found = null;
    foreach (['webp','png','jpg','jpeg'] as $ext) {
        $path = $dirPreview . $id . '.' . $ext;
        if (file_exists($path)) {
            $found = basename($path);
            break;
        }
    }

    // Si no hay preview por ID, probamos con nombre del archivo original
    if (!$found && !empty($row['archivo'])) {
        $base = pathinfo($row['archivo'], PATHINFO_FILENAME);
        foreach (['webp','png','jpg','jpeg'] as $ext) {
            $oldPath = $dirPreview . $base . '.' . $ext;
            if (file_exists($oldPath)) {
                $newPath = $dirPreview . $id . '.webp';
                rename($oldPath, $newPath);
                $found = basename($newPath);
                echo "♻️ Renombrado: $base.$ext → $found<br>";
                break;
            }
        }
    }

    // Actualizamos portada si encontramos preview y está vacío o distinto
    if ($found && $actual !== $found) {
        $conn->query("UPDATE apuntes SET portada = '$found' WHERE id = $id");
        echo "✅ ID $id → portada actualizada a $found<br>";
        $total++;
    }

    // Limpieza: si preview tiene datos antiguos
    if (!empty($preview)) {
        $conn->query("UPDATE apuntes SET preview = NULL WHERE id = $id");
    }
}

echo "<hr><strong>Proceso completado:</strong> $total portadas sincronizadas correctamente.";
$conn->close();
?>
