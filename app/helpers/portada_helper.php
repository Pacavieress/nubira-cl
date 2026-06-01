<?php
/**
 * Devuelve la URL pública de la portada del servicio.
 * - $archivo: nombre/relativo de la imagen subida del servicio (p.ej. "1234.webp")
 * - $categoria: texto de categoría (p.ej. "tutorias", "programacion", etc.)
 *
 * Reglas:
 * 1) Si $archivo existe físicamente en /upload/servicios/, usarlo.
 * 2) Si no, usar portada automática por categoría (en /img/portadas/servicios/).
 * 3) Si no hay match, usar default.webp.
 */
function portada_servicio(?string $archivo, string $categoria = 'otro'): string {
    $categoria = normalizar_categoria($categoria);

    // Rutas base (ajusta si tu public path es distinto)
  $webUpload = '/upload/servicios/';
$fsUpload  = realpath(dirname(__DIR__, 2) . '/upload/servicios/') . DIRECTORY_SEPARATOR;

    // 1) Imagen subida por el usuario
    if (!empty($archivo)) {
        $archivo = ltrim($archivo, '/');
        $fsPath  = $fsUpload . $archivo;

        // Evita path traversal y valida existencia
        if (strpos(realpath($fsPath) ?: '', $fsUpload) === 0 && is_file($fsPath)) {
            return $webUpload . $archivo;
        }
    }

    // 2) Portadas automáticas por categoría
    $webBase = '/img/portadas/servicios/';
   $map = [
    'apuntes'       => $webBase . 'apuntes.webp',
    'tutorias'      => $webBase . 'tutorias.webp',
    'clases'        => $webBase . 'clases.webp',       // NUEVO
    'redaccion'     => $webBase . 'redaccion.webp',    // NUEVO
    'diseno'        => $webBase . 'diseno.webp',
    'programacion'  => $webBase . 'programacion.webp',
    'oportunidades' => $webBase . 'oportunidades.webp',
    'otro'          => $webBase . 'otro.webp',
];


    // 3) Default si no hay match
    return $map[$categoria] ?? ($webBase . 'default.webp');
}

/**
 * Normaliza la categoría:
 * - Convierte a minúsculas
 * - Elimina tildes, ñ y caracteres especiales
 * - Quita espacios y guiones
 */
function normalizar_categoria(string $texto): string {
    $texto = strtolower(trim($texto));

    // Eliminar tildes y ñ
    $acentos = ['á','é','í','ó','ú','ñ'];
    $sin     = ['a','e','i','o','u','n'];
    $texto = str_replace($acentos, $sin, $texto);

    // Quitar caracteres no alfanuméricos
    $texto = preg_replace('/[^a-z0-9]/', '', $texto);

    return $texto;
}

// 🧠 Función usada por admin_aprobar_imagen.php
// Devuelve la URL de imagen para mostrar en la moderación
function url_portada_moderacion(array $row): string {
    $nombre = trim($row['imagen'] ?? '');
    $estado = trim($row['imagen_estado'] ?? '');
    $categoria = strtolower($row['categoria'] ?? '');

    // Si la imagen no está aprobada o no existe → mostrar genérica
    if ($nombre === '' || $estado !== 'aprobada') {
        $catFile = match (true) {
            str_contains($categoria, 'apunte')   => 'default_apuntes.webp',
            str_contains($categoria, 'clase')    => 'default_clases.webp',
            str_contains($categoria, 'servicio') => 'default_servicios.webp',
            default                              => 'default.webp',
        };
        return "/upload/servicios/$catFile";
    }

    // Si está aprobada, devolver la imagen real
    return "/upload/servicios/" . basename($nombre);
}

if (!function_exists('obtenerMiniaturaApunte')) {
    function obtenerMiniaturaApunte($id, $portadaBD, $previewBD, $archivoOriginal): string {
        $docRoot = $_SERVER['DOCUMENT_ROOT'];

        $getVersionedPath = function(string $path) use ($docRoot): string {
            $fs = $docRoot . $path;
            return file_exists($fs) ? $path . '?v=' . filemtime($fs) : $path;
        };

        // 1. Preview WebP por ID
        $rutaWebP = "/upload/preview/{$id}.webp";
        if (file_exists($docRoot . $rutaWebP)) return $getVersionedPath($rutaWebP);

        // 2. Legacy previews por ID
        foreach (['jpg', 'png', 'jpeg'] as $ext) {
            $rutaLegacy = "/upload/preview/{$id}.{$ext}";
            if (file_exists($docRoot . $rutaLegacy)) return $getVersionedPath($rutaLegacy);
        }

        // 3. Portada personalizada
        if (!empty($portadaBD)) {
            $p = basename($portadaBD);
            if (file_exists($docRoot . "/upload/portadas/" . $p)) return $getVersionedPath("/upload/portadas/" . $p);
            if (file_exists($docRoot . "/upload/preview/"  . $p)) return $getVersionedPath("/upload/preview/"  . $p);
        }

        // 4. Archivo original si es imagen
        if (!empty($archivoOriginal)) {
            $ext = strtolower(pathinfo($archivoOriginal, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'])) {
                $rutaOrig = "/upload/apuntes/" . basename($archivoOriginal);
                if (file_exists($docRoot . $rutaOrig)) return $getVersionedPath($rutaOrig);
            }
        }

        return '/img/logo2.webp';
    }
}
