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

/* ============================================================
   PASO 3 — HISTORY 1080x1920 + cache por fingerprint
   ============================================================ */

// --- Rectángulo con esquinas redondeadas ---
if (!function_exists('nb_rect_redondeado')) {
    function nb_rect_redondeado($img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void {
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    }
}

// --- Directorio de cache (web /upload/compartir/, FS resuelto en CLI y web) ---
if (!function_exists('nb_compartir_dir')) {
    function nb_compartir_dir(): string {
        $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($root === '') $root = dirname(__DIR__, 2);
        return rtrim($root, '/\\') . '/upload/compartir/';
    }
}

if (!function_exists('nb_texto_izquierda')) {
    function nb_texto_izquierda($img, string $font, float $size, int $color, string $txt, int $x, int $y): void {
        if ($txt === '') return;
        imagettftext($img, $size, 0, $x, $y, $color, $font, $txt);
    }
}

if (!function_exists('nb_texto_derecha')) {
    function nb_texto_derecha($img, string $font, float $size, int $color, string $txt, int $xRight, int $y): void {
        if ($txt === '') return;
        $x = $xRight - nb_ancho_texto($font, $size, $txt);
        imagettftext($img, $size, 0, (int)$x, $y, $color, $font, $txt);
    }
}

// Precio del HISTORY: si hay oferta → badge OFERTA + oferta grande + original tachado; si no → "desde $X".
if (!function_exists('nb_precio_history')) {
    function nb_precio_history($img, array $s, int $x, int $yBase, string $fBold, string $fReg, int $cTxt, int $cTxt2, int $cAzul, int $cBlanco): void {
        $of = (float)($s['precio_oferta'] ?? 0);
        $pr = (float)($s['precio'] ?? 0);

        if ($of > 0) {
            // Badge OFERTA (pill azul + texto blanco) encima del precio
            $badge = 'OFERTA';
            $bw  = nb_ancho_texto($fBold, 22, $badge);
            $bx1 = $x; $by1 = $yBase - 115; $bx2 = $x + $bw + 36; $by2 = $by1 + 44;
            $cVerde = imagecolorallocate($img, 22, 163, 74); // #16A34A green-600 (igual que badge oferta de la plataforma)
            nb_rect_redondeado($img, $bx1, $by1, $bx2, $by2, 14, $cVerde);
            imagettftext($img, 22, 0, $bx1 + 18, $by1 + 31, $cBlanco, $fBold, $badge);

            // Precio oferta grande (negro)
            $ofText = '$' . number_format($of, 0, ',', '.') . ' CLP';
            imagettftext($img, 52, 0, $x, $yBase, $cTxt, $fBold, $ofText);
            $wOf = nb_ancho_texto($fBold, 52, $ofText);

            // Precio original tachado (gris) al lado
            $origText = '$' . number_format($pr, 0, ',', '.');
            $xo = $x + $wOf + 24;
            $yo = $yBase - 6;
            imagettftext($img, 28, 0, $xo, $yo, $cTxt2, $fReg, $origText);
            $wo = nb_ancho_texto($fReg, 28, $origText);
            imagesetthickness($img, 3);
            imageline($img, $xo, $yo - 9, $xo + $wo, $yo - 9, $cTxt2);
            imagesetthickness($img, 1);
        } else {
            $txt = $pr > 0 ? ('desde $' . number_format($pr, 0, ',', '.') . ' CLP') : 'Gratis';
            imagettftext($img, 52, 0, $x, $yBase, $cTxt, $fBold, $txt);
        }
    }
}

if (!function_exists('nb_generar_imagen_history')) {
    function nb_generar_imagen_history(array $s, string $output_path): bool {
        $W = 1080; $H = 1920;
        $fReg  = nb_fonts_dir() . 'Inter-Regular.ttf';
        $fSemi = nb_fonts_dir() . 'Inter-SemiBold.ttf';
        $fBold = nb_fonts_dir() . 'Inter-Bold.ttf';
        foreach ([$fReg, $fSemi, $fBold] as $f) if (!is_file($f)) return false;

        $img = imagecreatetruecolor($W, $H);
        $cBg     = imagecolorallocate($img, 240, 246, 250); // #F0F6FA fondo (igual que POST)
        $cAzul   = imagecolorallocate($img, 84, 166, 216);   // #54A6D8 acento
        $cBlanco = imagecolorallocate($img, 255, 255, 255);
        $cTxt    = imagecolorallocate($img, 26, 26, 26);
        $cTxt2   = imagecolorallocate($img, 107, 114, 128);
        $cAcento = $cAzul;
        imagefilledrectangle($img, 0, 0, $W, $H, $cBg);

        // Card "sticker" centrada con borde sutil #E5E7EB
        $cardX1 = 90; $cardX2 = 990; $cardY1 = 460; $cardY2 = 1460; $cardR = 40;
        $cBorde = imagecolorallocate($img, 229, 231, 235); // #E5E7EB borde card
        // Card
        nb_rect_redondeado($img, $cardX1, $cardY1, $cardX2, $cardY2, $cardR, $cBorde);
        nb_rect_redondeado($img, $cardX1 + 2, $cardY1 + 2, $cardX2 - 2, $cardY2 - 2, $cardR - 2, $cBlanco);

        // Marco INTERNO (contiene el contenido; Nubira.cl va FUERA, abajo)
        $inX1 = 130; $inX2 = 950; $inY1 = 500; $inY2 = 1300; $inR = 30;
        nb_rect_redondeado($img, $inX1, $inY1, $inX2, $inY2, $inR, $cBorde);
        nb_rect_redondeado($img, $inX1 + 2, $inY1 + 2, $inX2 - 2, $inY2 - 2, $inR - 2, $cBlanco);

        $padL   = $inX1 + 40;     // 170: margen interno (dentro del marco interno)
        $padR   = $inX2 - 40;     // 910
        $maxTxt = $padR - $padL;  // 740: ancho útil

        // Avatar 200 a la IZQUIERDA (dentro del marco interno)
        $diam = 200; $fotoTop = 560;
        nb_dibujar_avatar($img, $s, $padL + (int)($diam / 2), $fotoTop, $diam, $cAcento, $cBlanco, $fBold);

        // Nombre (left)
        $nombre = nb_truncar_una_linea($fBold, 44, nombre_publico_tutor((string)($s['nombre_alumno'] ?? $s['nombre'] ?? '')), $maxTxt);
        nb_texto_izquierda($img, $fBold, 44, $cTxt, $nombre, $padL, 850);

        // Institución (left)
        $inst = mb_strtoupper(trim((string)($s['institucion_maestra'] ?? $s['institucion'] ?? '')), 'UTF-8');
        if ($inst === '') $inst = 'Tutor Particular';
        $inst = nb_truncar_una_linea($fReg, 28, $inst, $maxTxt);
        nb_texto_izquierda($img, $fReg, 28, $cTxt2, $inst, $padL, 905);

        // Categoría (left)
        $cat = mb_strtoupper(trim((string)($s['categoria'] ?? '')), 'UTF-8');
        if ($cat !== '') nb_texto_izquierda($img, $fBold, 32, $cAzul, $cat, $padL, 1000);

        // Título 1 línea (left)
        $titulo = nb_truncar_una_linea($fSemi, 36, (string)($s['titulo'] ?? ''), $maxTxt);
        nb_texto_izquierda($img, $fSemi, 36, $cTxt, $titulo, $padL, 1075);

        // Precio condicional (oferta tachada o "desde") — badge con respiración
        nb_precio_history($img, $s, $padL, 1230, $fBold, $fReg, $cTxt, $cTxt2, $cAzul, $cBlanco);

        // Nubira.cl FUERA del marco interno, abajo-izquierda DENTRO de la card
        nb_texto_izquierda($img, $fBold, 28, $cAzul, 'Nubira.cl', $cardX1 + 50, $cardY2 - 60);

        // CTA inferior: una sola línea
        $urlCorta = (string)($s['_url_corta'] ?? 'nubira.cl');
        nb_texto_centrado($img, $fBold, 38, $cAzul, 'Entra a ' . $urlCorta, $W, 1755);

        $ok = imagejpeg($img, $output_path, 90);
        imagedestroy($img);
        return (bool)$ok;
    }
}

if (!function_exists('nb_fingerprint_servicio')) {
    function nb_fingerprint_servicio(array $s): string {
        $base = ($s['id'] ?? '') . '|' . ($s['titulo'] ?? '') . '|' . ($s['precio'] ?? '')
              . '|' . ($s['precio_oferta'] ?? '') . '|' . ($s['foto_perfil'] ?? '')
              . '|' . ($s['categoria'] ?? '') . '|' . ($s['institucion_maestra'] ?? '');
        return substr(md5($base), 0, 10);
    }
}

if (!function_exists('nb_obtener_imagen_compartir')) {
    // Devuelve la RUTA FÍSICA del JPG (cache hit o recién generado), o '' si falla.
    function nb_obtener_imagen_compartir(int $servicio_id, string $formato): string {
        global $conn;
        $formato = ($formato === 'history') ? 'history' : 'post';
        if (!isset($conn) || !($conn instanceof mysqli) || $servicio_id <= 0) return '';

        $sql = "SELECT s.*, a.nombre AS nombre_alumno, a.foto_perfil,
                       COALESCE(dp.institucion, a.institucion) AS institucion_maestra
                FROM servicios s
                LEFT JOIN alumnos a ON s.alumno_id = a.id
                LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
                WHERE s.id = ? LIMIT 1";
        $st = $conn->prepare($sql);
        if (!$st) return '';
        $st->bind_param('i', $servicio_id);
        $st->execute();
        $s = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$s) return '';

        require_once __DIR__ . '/../seguridad_url.php';
        $hash = function_exists('nubira_encriptar_id') ? nubira_encriptar_id($servicio_id) : (string)$servicio_id;
        $fp   = nb_fingerprint_servicio($s);

        $dir = nb_compartir_dir();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . $hash . '_' . $formato . '_' . $fp . '.jpg';

        if (is_file($file)) return $file; // cache hit

        // link corto para el CTA del history
        require_once __DIR__ . '/link_corto.php';
        if (function_exists('url_corta')) {
            $s['_url_corta'] = preg_replace('#^https?://#', '', url_corta($servicio_id));
        }

        $ok = ($formato === 'history')
            ? nb_generar_imagen_history($s, $file)
            : nb_generar_imagen_post($s, $file);

        return $ok && is_file($file) ? $file : '';
    }
}
