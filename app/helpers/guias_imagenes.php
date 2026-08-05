<?php
// app/helpers/guias_imagenes.php
// Pipeline de imágenes del Centro de Recursos (portada + imágenes inline del
// cuerpo). Extraído de admin_guias.php para poder reusarse también desde
// ajax_subir_imagen_guia.php sin cargar el resto de esa página.

if (!function_exists('nb_guia_generar_tamano')) {
    function nb_guia_generar_tamano($src, int $w0, int $h0, int $max_w, string $dest, int $q): bool {
        if ($w0 <= $max_w) return imagewebp($src, $dest, $q);
        $new_w = $max_w;
        $new_h = (int) round(($h0 / $w0) * $max_w);
        $dst = imagecreatetruecolor($new_w, $new_h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $w0, $h0);
        $ok = imagewebp($dst, $dest, $q);
        imagedestroy($dst);
        return $ok;
    }
}

if (!function_exists('nb_guia_cargar_imagen_subida')) {
    function nb_guia_cargar_imagen_subida(array $file) {
        if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
        if ($file['size'] > 15 * 1024 * 1024) return null;

        $info = @getimagesize($file['tmp_name']);
        if ($info === false) return null;
        $mime = $info['mime'];
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;

        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
            'image/png'  => @imagecreatefrompng($file['tmp_name']),
            'image/webp' => @imagecreatefromwebp($file['tmp_name']),
            default => null,
        };
    }
}

if (!function_exists('nb_guia_subir_portada')) {
    function nb_guia_subir_portada(array $file, string $dir_fs): ?string {
        $img = nb_guia_cargar_imagen_subida($file);
        if (!$img) return null;

        if (!is_dir($dir_fs)) @mkdir($dir_fs, 0755, true);
        $w0 = imagesx($img); $h0 = imagesy($img);
        $base = 'guia_' . uniqid();

        nb_guia_generar_tamano($img, $w0, $h0, 240,  $dir_fs . $base . '_thumb.webp', 78);
        nb_guia_generar_tamano($img, $w0, $h0, 480,  $dir_fs . $base . '_card.webp',  80);
        $ok_main = nb_guia_generar_tamano($img, $w0, $h0, 1200, $dir_fs . $base . '.webp', 82);
        imagedestroy($img);

        return $ok_main ? $base . '.webp' : null;
    }
}

if (!function_exists('nb_guia_subir_imagen_inline')) {
    // Imagen suelta dentro del cuerpo del artículo (no portada) — un solo
    // tamaño, acotado al ancho real de la columna de contenido en
    // guia_post.php (max-w-[900px]).
    function nb_guia_subir_imagen_inline(array $file, string $dir_fs): ?string {
        $img = nb_guia_cargar_imagen_subida($file);
        if (!$img) return null;

        if (!is_dir($dir_fs)) @mkdir($dir_fs, 0755, true);
        $w0 = imagesx($img); $h0 = imagesy($img);
        $nombre = 'guia_inline_' . uniqid() . '.webp';

        $ok = nb_guia_generar_tamano($img, $w0, $h0, 900, $dir_fs . $nombre, 82);
        imagedestroy($img);

        return $ok ? $nombre : null;
    }
}
