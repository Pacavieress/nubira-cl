<?php
// 🧩 Mostrar errores en pantalla temporalmente
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/conexion.php';

echo "<h2>🔄 Renombrando previews...</h2>";

$dirPreview = __DIR__ . '/../upload/preview/';

// Verifica carpeta
if (!is_dir($dirPreview)) {
    die("❌ Carpeta no encontrada: " . htmlspecialchars($dirPreview));
}

// Obtiene apuntes existentes
$sql = "SELECT id, archivo, portada FROM apuntes ORDER BY id ASC";
$res = $conn->query($sql);
if (!$res) {
    die("❌ Error en consulta SQL: " . htmlspecialchars($conn->error));
}

$renombrados = 0;
while ($row = $res->fetch_assoc()) {
    $id = (int)$row['id'];

    // Buscar previews antiguas con distintos nombres
    $base = pathinfo($row['archivo'], PATHINFO_FILENAME);

    $encontrado = false;
    foreach (['png','jpg','jpeg','webp'] as $ext) {
        // Ejemplo de archivo antiguo (basado en nombre original)
        $viejo = $dirPreview . $base . '.' . $ext;
        $nuevo = $dirPreview . $id . '.webp';

        if (file_exists($viejo)) {
            if (rename($viejo, $nuevo)) {
                $conn->query("UPDATE apuntes SET portada = '" . basename($nuevo) . "' WHERE id = $id");
                echo "✅ ID $id → renombrado a " . basename($nuevo) . "<br>";
                $renombrados++;
                $encontrado = true;
                break;
            } else {
                echo "⚠️ No se pudo renombrar $viejo<br>";
            }
        }
    }

    if (!$encontrado && !empty($row['portada'])) {
        $viejo = $dirPreview . basename($row['portada']);
        $nuevo = $dirPreview . $id . '.webp';
        if ($viejo !== $nuevo && file_exists($viejo)) {
            rename($viejo, $nuevo);
            $conn->query("UPDATE apuntes SET portada = '" . basename($nuevo) . "' WHERE id = $id");
            echo "♻️ Ajustado: $viejo → $nuevo<br>";
            $renombrados++;
        }
    }
}

echo "<hr><strong>Proceso completado:</strong> $renombrados previews renombradas.";
$conn->close();
?>
