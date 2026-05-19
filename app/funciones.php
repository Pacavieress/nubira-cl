<?php
function generarMiniaturaPDF($rutaArchivo, $rutaMiniatura, $ancho = 600, $alto = 800) {
    try {
        $imagick = new Imagick();
        $imagick->setResolution(150, 150);
        $imagick->readImage($rutaArchivo . '[0]'); // solo primera página
        $imagick->setImageFormat('webp');
        $imagick->resizeImage($ancho, $alto, Imagick::FILTER_LANCZOS, 1, true);
        $imagick->stripImage(); // quita metadatos
        $imagick->setImageCompressionQuality(80);
        $imagick->writeImage($rutaMiniatura);
        $imagick->clear();
        $imagick->destroy();
        return true;
    } catch (Exception $e) {
        error_log("Error al generar miniatura: " . $e->getMessage());
        return false;
    }
}
?>
