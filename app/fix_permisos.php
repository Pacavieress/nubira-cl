<?php
// fix_permisos.php
// Script de Mantenimiento para Nubira.cl
session_start();
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header("Location: /login.php");
    exit;
}

echo '<body style="font-family: sans-serif; padding: 20px; line-height: 1.5;">';
echo '<h1>🛠️ Reparador de Permisos de Imágenes</h1>';

$base_dir = $_SERVER['DOCUMENT_ROOT'] . '/upload';

if (!file_exists($base_dir)) {
    die("❌ La carpeta /upload no existe en: $base_dir");
}

echo "Analizando carpeta: <strong>$base_dir</strong><br><hr>";

$archivos_corregidos = 0;
$errores = 0;

// Iterador recursivo para entrar en todas las subcarpetas (preview, portadas, etc)
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base_dir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    // Normalizar permisos:
    // Carpetas -> 0755 (Lectura/Ejecución pública, Escritura dueño)
    // Archivos -> 0644 (Lectura pública, Escritura dueño)
    
    $path = $item->getPathname();
    $es_dir = $item->isDir();
    $permiso_deseado = $es_dir ? 0755 : 0644;
    
    // Intentar cambiar permisos
    if (@chmod($path, $permiso_deseado)) {
        // Solo mostrar si había un problema grave, para no saturar la pantalla
        // o mostrar un contador.
        $archivos_corregidos++;
    } else {
        echo "<div style='color: red;'>❌ Error cambiando permisos en: " . basename($path) . "</div>";
        $errores++;
    }
}

echo "<hr>";
echo "<h3>Resumen:</h3>";
echo "<ul>";
echo "<li>Archivos/Carpetas procesados: <strong>$archivos_corregidos</strong></li>";
echo "<li>Errores: <strong>$errores</strong></li>";
echo "</ul>";

echo "<p style='background: #e6fffa; color: #047857; padding: 10px; border-radius: 5px; border: 1px solid #047857;'>
      ✅ <strong>Listo.</strong> Ahora ve a la vitrina y presiona <strong>CTRL + F5</strong> para forzar la recarga de las imágenes.
      </p>";
?>