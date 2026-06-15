<?php
// Núcleo GD — genera la imagen POST 1080x1080 para compartir un servicio.
// Paso 2: solo POST, sin cache, sin endpoint. Fondo #F0F6FA + acento #54A6D8.
require_once __DIR__ . '/foto_tutor.php';
require_once __DIR__ . '/nombre_publico.php';

if (!function_exists('nb_fonts_dir')) {
    function nb_fonts_dir(): string { return __DIR__ . '/../assets/fonts/'; }
}

/* ---------- Helpers internos ---------- */

if (!function_exists('nb_ancho_texto')) {
    function nb_ancho_texto(string $font, float $size, string $txt): int {
        $bb = imagettfbbox($size, 0, $font, $txt);
        return $bb !== false ? abs($bb[2] - $bb[0]) : 0;
    }
}

if (!function_exists('nb_texto_centrado')) {
    // $y = baseline. Dibuja $txt centrado horizontalmente en ancho $W.
    function nb_texto_centrado($img, string $font, float $size, int $color, string $txt, int $W, int $y): void {
        if ($txt === '') return;
        $x = (int)(($W - nb_ancho_texto($font, $size, $txt)) / 2);
        imagettftext($img, $size, 0, $x, $y, $color, $font, $txt);
    }
}

if (!function_exists('nb_truncar_una_linea')) {
    function nb_truncar_una_linea(string $font, float $size, string $txt, int $maxW): string {
        $txt = trim($txt);
        if (nb_ancho_texto($font, $size, $txt) <= $maxW) return $txt;
        while ($txt !== '' && nb_ancho_texto($font, $size, $txt . '…') > $maxW) {
            $txt = mb_substr($txt, 0, mb_strlen($txt) - 1);
        }
        return rtrim($txt) . '…';
    }
}

if (!function_exists('nb_wrap_texto')) {
    // Word-wrap a $maxLineas; la última línea se trunca con … si sobra.
    function nb_wrap_texto(string $font, float $size, string $txt, int $maxW, int $maxLineas): array {
        $palabras = preg_split('/\s+/', trim($txt));
        $lineas = []; $actual = '';
        foreach ($palabras as $p) {
            $prueba = $actual === '' ? $p : "$actual $p";
            if (nb_ancho_texto($font, $size, $prueba) <= $maxW) {
                $actual = $prueba;
            } else {
                if ($actual !== '') $lineas[] = $actual;
                $actual = $p;
                if (count($lineas) === $maxLineas) break;
            }
        }
        if ($actual !== '' && count($lineas) < $maxLineas) $lineas[] = $actual;
        if (count($lineas) >= $maxLineas) {
            $lineas = array_slice($lineas, 0, $maxLineas);
            $lineas[$maxLineas - 1] = nb_truncar_una_linea($font, $size, $lineas[$maxLineas - 1], $maxW);
        }
        return $lineas;
    }
}

if (!function_exists('nb_formato_precio')) {
    function nb_formato_precio(array $s): string {
        $of = (float)($s['precio_oferta'] ?? 0);
        $pr = (float)($s['precio'] ?? 0);
        $val = $of > 0 ? $of : $pr;
        if ($val <= 0) return 'Gratis';
        return 'desde $' . number_format($val, 0, ',', '.') . ' CLP';
    }
}

if (!function_exists('nb_recorte_circular')) {
    // Devuelve una imagen $diam x $diam con la foto recortada en círculo (alpha).
    function nb_recorte_circular($src, int $diam) {
        $dst = imagecreatetruecolor($diam, $diam);
        imagesavealpha($dst, true);
        imagealphablending($dst, false);
        $transp = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $diam, $diam, $transp);
        // Escala la foto (cuadrada) al diámetro
        $sw = imagesx($src); $sh = imagesy($src);
        $lado = min($sw, $sh);
        $ox = (int)(($sw - $lado) / 2); $oy = (int)(($sh - $lado) / 2);
        $tmp = imagecreatetruecolor($diam, $diam);
        imagecopyresampled($tmp, $src, 0, 0, $ox, $oy, $diam, $diam, $lado, $lado);
        // Máscara circular pixel a pixel
        $r = $diam / 2;
        for ($y = 0; $y < $diam; $y++) {
            for ($x = 0; $x < $diam; $x++) {
                if ((($x - $r) ** 2 + ($y - $r) ** 2) <= $r * $r) {
                    imagesetpixel($dst, $x, $y, imagecolorat($tmp, $x, $y));
                }
            }
        }
        imagedestroy($tmp);
        return $dst;
    }
}

if (!function_exists('nb_dibujar_avatar')) {
    function nb_dibujar_avatar($img, array $s, int $cx, int $top, int $diam, int $cAcento, int $cBlanco, string $fontBold): void {
        $r = (int)($diam / 2);
        $cy = $top + $r;
        // Anillo de borde 4px (círculo acento un poco más grande)
        imagefilledellipse($img, $cx, $cy, $diam + 8, $diam + 8, $cAcento);
        if (necesidad_avatar_inicial($s)) {
            // Círculo relleno + inicial blanca
            imagefilledellipse($img, $cx, $cy, $diam, $diam, $cAcento);
            $nombre = trim((string)($s['nombre_alumno'] ?? $s['nombre'] ?? '?'));
            $ini = mb_strtoupper(mb_substr($nombre !== '' ? $nombre : '?', 0, 1));
            $sz = 130;
            $bb = imagettfbbox($sz, 0, $fontBold, $ini);
            $tx = $cx - (abs($bb[2] - $bb[0]) / 2);
            $ty = $cy + (abs($bb[7] - $bb[1]) / 2);
            imagettftext($img, $sz, 0, (int)$tx, (int)$ty, $cBlanco, $fontBold, $ini);
        } else {
            // Fondo blanco bajo la foto (por si tiene alpha) + foto circular
            imagefilledellipse($img, $cx, $cy, $diam, $diam, $cBlanco);
            $raw = @file_get_contents(path_foto_tutor($s));
            $src = $raw !== false ? @imagecreatefromstring($raw) : false;
            if ($src) {
                $circ = nb_recorte_circular($src, $diam);
                imagecopy($img, $circ, $cx - $r, $cy - $r, 0, 0, $diam, $diam);
                imagedestroy($circ); imagedestroy($src);
            }
        }
    }
}

/* ---------- Generador POST 1080x1080 ---------- */

if (!function_exists('nb_generar_imagen_post')) {
    function nb_generar_imagen_post(array $s, string $output_path): bool {
        $W = 1080; $H = 1080;
        $fReg  = nb_fonts_dir() . 'Inter-Regular.ttf';
        $fSemi = nb_fonts_dir() . 'Inter-SemiBold.ttf';
        $fBold = nb_fonts_dir() . 'Inter-Bold.ttf';
        foreach ([$fReg, $fSemi, $fBold] as $f) if (!is_file($f)) return false;

        $img = imagecreatetruecolor($W, $H);
        $cBg     = imagecolorallocate($img, 240, 246, 250);
        $cAcento = imagecolorallocate($img, 84, 166, 216);
        $cTxt    = imagecolorallocate($img, 26, 26, 26);
        $cTxt2   = imagecolorallocate($img, 107, 114, 128);
        $cBlanco = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, $W, $H, $cBg);

        // Logo top
        nb_texto_centrado($img, $fBold, 40, $cAcento, 'nubira.cl', $W, 95);

        // Avatar
        $diam = 280; $fotoTop = 175;
        nb_dibujar_avatar($img, $s, (int)($W / 2), $fotoTop, $diam, $cAcento, $cBlanco, $fBold);

        // Nombre tutor
        $yNombre = $fotoTop + $diam + 75;
        $nombre = nb_truncar_una_linea($fBold, 38, nombre_publico_tutor((string)($s['nombre_alumno'] ?? $s['nombre'] ?? '')), $W - 120);
        nb_texto_centrado($img, $fBold, 38, $cTxt, $nombre, $W, $yNombre);

        // Institución
        $inst = mb_strtoupper(trim((string)($s['institucion_maestra'] ?? $s['institucion'] ?? '')), 'UTF-8');
        if ($inst === '') $inst = 'Tutor Particular';
        $inst = nb_truncar_una_linea($fReg, 24, $inst, $W - 160);
        nb_texto_centrado($img, $fReg, 24, $cTxt2, $inst, $W, $yNombre + 44);

        // Badge categoría (MAYÚSCULAS, sin emoji)
        $cat = mb_strtoupper(trim((string)($s['categoria'] ?? '')), 'UTF-8');
        $yCat = $yNombre + 130;
        if ($cat !== '') nb_texto_centrado($img, $fBold, 28, $cAcento, $cat, $W, $yCat);

        // Título (wrap 2 líneas)
        $yTit = $yCat + 70;
        $lineas = nb_wrap_texto($fSemi, 32, (string)($s['titulo'] ?? ''), $W - 160, 2);
        foreach ($lineas as $i => $ln) nb_texto_centrado($img, $fSemi, 32, $cTxt, $ln, $W, $yTit + $i * 48);

        // Precio
        $yPrecio = $yTit + count($lineas) * 48 + 80;
        nb_texto_centrado($img, $fBold, 44, $cTxt, nb_formato_precio($s), $W, $yPrecio);

        // Footer
        nb_texto_centrado($img, $fReg, 20, $cTxt2, 'nubira.cl', $W, $H - 55);

        $ok = imagejpeg($img, $output_path, 90);
        imagedestroy($img);
        return (bool)$ok;
    }
}
