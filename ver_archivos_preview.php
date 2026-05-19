<?php
// Ruta absoluta a la carpeta de previews
$preview_dir = $_SERVER['DOCUMENT_ROOT'] . '/upload/preview/';

echo "<h2>Archivos en /upload/preview/</h2>";

if (!is_dir($preview_dir)) {
    echo "<p style='color:red;'>La carpeta /upload/preview/ no existe.</p>";
    exit;
}

$archivos = scandir($preview_dir);

if ($archivos === false || count($archivos) <= 2) {
    echo "<p>No hay archivos en la carpeta.</p>";
} else {
    echo "<ul style='font-family: monospace;'>";
    foreach ($archivos as $archivo) {
        if ($archivo === '.' || $archivo === '..') continue;
        echo "<li>$archivo</li>";
    }
    echo "</ul>";
}
?>
