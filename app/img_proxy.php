<?php
/**
 * PROXY DE IMÁGENES NUBIRA
 * Función: Forzar cabeceras correctas para WhatsApp/Social Media
 */
require_once __DIR__ . '/conexion.php'; // Solo para asegurar rutas, no usamos BD aquí

$file = $_GET['f'] ?? '';

// 1. SEGURIDAD: Limpiar nombre de archivo (evitar hackeos ../)
$file = basename($file); 

// 2. Rutas
$ruta_fisica = dirname(__DIR__) . '/upload/servicios/' . $file;
$ruta_default = dirname(__DIR__) . '/upload/servicios/default_clases.webp';

// 3. Verificar existencia
if (empty($file) || !file_exists($ruta_fisica)) {
    $ruta_fisica = $ruta_default;
    $file = 'default_clases.webp';
}

// 4. Detectar tipo MIME real
$ext = strtolower(pathinfo($ruta_fisica, PATHINFO_EXTENSION));
$mime = 'image/jpeg'; // Fallback
if ($ext === 'webp') $mime = 'image/webp';
elseif ($ext === 'png') $mime = 'image/png';
elseif ($ext === 'gif') $mime = 'image/gif';

// 5. ENVIAR CABECERAS FORZADAS (Esto arregla WhatsApp)
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($ruta_fisica));
header('Cache-Control: public, max-age=86400'); // Cache por 24 horas

// 6. Entregar imagen limpia
readfile($ruta_fisica);
exit;