<?php
// Guardar en app/ver_og.php
require_once 'conexion.php';

$id = $_GET['id'] ?? 74; // ID del servicio que falla
$sql = "SELECT imagen FROM servicios WHERE id = $id";
$res = $conn->query($sql);
$row = $res->fetch_assoc();

echo "<h1>Diagnóstico de Imagen (Servicio #$id)</h1>";

if (!$row) {
    die("❌ El servicio no existe en la base de datos.");
}

$imagen_db = $row['imagen'];
echo "<p><strong>Nombre en BD:</strong> $imagen_db</p>";

if (empty($imagen_db)) {
    die("⚠️ El servicio no tiene imagen asignada (usa la default).");
}

// Ruta física real en el servidor
$ruta_fisica = $_SERVER['DOCUMENT_ROOT'] . "/upload/servicios/" . $imagen_db;
echo "<p><strong>Ruta buscada:</strong> $ruta_fisica</p>";

if (!file_exists($ruta_fisica)) {
    echo "<h2 style='color:red'>❌ ERROR CRÍTICO: El archivo no existe en esa ruta.</h2>";
    echo "<p>Verifica que la imagen esté realmente en la carpeta <code>/upload/servicios/</code></p>";
} else {
    $peso = filesize($ruta_fisica);
    $peso_kb = round($peso / 1024, 2);
    
    echo "<h2 style='color:green'>✅ El archivo existe.</h2>";
    echo "<p><strong>Peso:</strong> $peso_kb KB</p>";
    
    if ($peso_kb > 300) {
        echo "<h2 style='color:orange'>⚠️ ALERTA: La imagen es muy pesada ($peso_kb KB).</h2>";
        echo "<p>WhatsApp suele ignorar imágenes mayores a 300KB. Intenta subir una versión comprimida.</p>";
    } else {
        echo "<p style='color:green'>El peso es perfecto para WhatsApp.</p>";
    }
    
    // Ver imagen
    $url_web = "https://nubira.cl/upload/servicios/" . rawurlencode($imagen_db);
    echo "<p><strong>URL para WhatsApp:</strong> <a href='$url_web'>$url_web</a></p>";
    echo "<img src='$url_web' style='max-width:300px; border:2px solid red;'>";
}
?>