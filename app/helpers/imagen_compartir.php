<?php
// Núcleo GD — genera la imagen POST 1080x1080 para compartir un servicio.
// Paso 2: solo POST, sin cache, sin endpoint. Fondo #F0F6FA + acento #54A6D8.
require_once __DIR__ . '/foto_tutor.php';
require_once __DIR__ . '/nombre_publico.php';
require_once __DIR__ . '/institucion.php';

// Versión del generador de imágenes. Incrementar (v1 → v2 → ...) invalida
// AUTOMÁTICAMENTE todo el cache de /upload/compartir/ cuando se cambia el diseño
// visual, porque entra en el fingerprint (no depende solo de los datos del servicio).
if (!defined('NB_IMG_VERSION')) define('NB_IMG_VERSION', 'v16');

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
        $crudo = preg_split('/\s+/', trim($txt));
        // Fallback: una "palabra" sin espacios más ancha que $maxW se corta por caracteres
        // (sin guion) para que nunca se desborde del margen, sin importar el contenido.
        $palabras = [];
        foreach ($crudo as $p) {
            if ($p === '') continue;
            if (nb_ancho_texto($font, $size, $p) <= $maxW) {
                $palabras[] = $p;
                continue;
            }
            $trozo = '';
            foreach (mb_str_split($p, 1, 'UTF-8') as $ch) {
                $prueba = $trozo . $ch;
                if ($trozo !== '' && nb_ancho_texto($font, $size, $prueba) > $maxW) {
                    $palabras[] = $trozo;
                    $trozo = $ch;
                } else {
                    $trozo = $prueba;
                }
            }
            if ($trozo !== '') $palabras[] = $trozo;
        }
        $lineas = []; $actual = ''; $truncado = false;
        foreach ($palabras as $p) {
            $prueba = $actual === '' ? $p : "$actual $p";
            if (nb_ancho_texto($font, $size, $prueba) <= $maxW) {
                $actual = $prueba;
            } else {
                if ($actual !== '') $lineas[] = $actual;
                $actual = $p;
                if (count($lineas) === $maxLineas) { $truncado = true; break; }
            }
        }
        if ($actual !== '' && count($lineas) < $maxLineas) {
            $lineas[] = $actual;
        } elseif ($actual !== '') {
            // Quedó texto sin ubicar y ya no hay líneas disponibles: se pierde contenido real.
            $truncado = true;
        }
        if (count($lineas) >= $maxLineas) {
            $lineas = array_slice($lineas, 0, $maxLineas);
        }
        // Solo forzamos "…" cuando de verdad se perdió contenido (antes, si la última línea
        // ya entraba en $maxW, nb_truncar_una_linea() la devolvía intacta sin avisar del corte).
        if ($truncado && !empty($lineas)) {
            $i = count($lineas) - 1;
            $base = rtrim($lineas[$i]);
            while ($base !== '' && nb_ancho_texto($font, $size, $base . '…') > $maxW) {
                $base = mb_substr($base, 0, mb_strlen($base, 'UTF-8') - 1, 'UTF-8');
            }
            $lineas[$i] = $base . '…';
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

if (!function_exists('nb_dibujar_precio_centrado')) {
    // Precio POST centrado.
    // Con oferta: badge OFERTA verde centrado (yBase-115) + oferta grande + original tachado.
    // Sin oferta: precio normal + CLP chico. Sin precio: "Gratis".
    function nb_dibujar_precio_centrado($img, array $s, string $fBold, string $fSemi, string $fReg, float $szBig, float $szCLP, int $W, int $yBase, int $cTxt, int $cTxt2): void {
        $of = (float)($s['precio_oferta'] ?? 0);
        $pr = (float)($s['precio'] ?? 0);
        // Mismo criterio que "Precios de última hora" en vitrina.php: is_subvencionado=1
        // y oferta_termino sin vencer (NULL o >= hoy). Evita el badge OFERTA en ofertas
        // ya expiradas que no se limpiaron manualmente en admin_ofertas.php.
        $ofertaVigente = !empty($s['is_subvencionado']) && (int)$s['is_subvencionado'] === 1
            && (empty($s['oferta_termino']) || $s['oferta_termino'] >= date('Y-m-d'));

        if ($of <= 0 && $pr <= 0) {
            nb_texto_centrado($img, $fBold, $szBig, $cTxt, 'Gratis', $W, $yBase);
            return;
        }

        if ($of > 0 && $ofertaVigente) {
            // Badge OFERTA verde centrado, 115px encima del baseline del precio
            $badge   = 'OFERTA';
            $bw      = nb_ancho_texto($fBold, 22, $badge);
            $bx1     = (int)(($W - $bw - 36) / 2);
            $by1     = $yBase - 115;
            $cVerde  = imagecolorallocate($img, 22, 163, 74);
            $cBlanco = imagecolorallocate($img, 255, 255, 255);
            nb_rect_redondeado($img, $bx1, $by1, $bx1 + $bw + 36, $by1 + 44, 14, $cVerde);
            imagettftext($img, 22, 0, $bx1 + 18, $by1 + 31, $cBlanco, $fBold, $badge);

            // Bloque precio: "$10.800 CLP $18.000" (tachado) centrado en $W
            $szOrig  = 28;
            $ofTxt   = '$' . number_format($of, 0, ',', '.');
            $clpTxt  = ' CLP';
            $origTxt = ' $' . number_format($pr, 0, ',', '.');

            $wOf   = nb_ancho_texto($fBold, $szBig, $ofTxt);
            $wCLP  = nb_ancho_texto($fSemi, $szCLP, $clpTxt);
            $wOrig = nb_ancho_texto($fReg,  $szOrig, $origTxt);
            $x = (int)(($W - $wOf - $wCLP - $wOrig) / 2);

            imagettftext($img, $szBig, 0, $x, $yBase, $cTxt, $fBold, $ofTxt);
            $x += $wOf;
            imagettftext($img, $szCLP, 0, $x, $yBase, $cTxt, $fSemi, $clpTxt);
            $x += $wCLP;
            // Precio original: subir para alinear visualmente con la línea base del precio grande
            $yo = $yBase - (int)(($szBig - $szOrig) * 0.45);
            imagettftext($img, $szOrig, 0, $x, $yo, $cTxt2, $fReg, $origTxt);
            // Tachado horizontal centrado en la altura del cap
            $lineY = $yo - (int)($szOrig * 0.3);
            imagesetthickness($img, 3);
            imageline($img, $x, $lineY, $x + $wOrig, $lineY, $cTxt2);
            imagesetthickness($img, 1);
        } else {
            // Sin oferta: precio normal + CLP chico
            $mainTxt = '$' . number_format($pr, 0, ',', '.');
            $wMain = nb_ancho_texto($fBold, $szBig, $mainTxt);
            $wCLP  = nb_ancho_texto($fSemi, $szCLP, ' CLP');
            $x = (int)(($W - $wMain - $wCLP) / 2);
            imagettftext($img, $szBig, 0, $x, $yBase, $cTxt, $fBold, $mainTxt);
            imagettftext($img, $szCLP, 0, $x + $wMain, $yBase, $cTxt, $fSemi, ' CLP');
        }
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

if (!function_exists('nb_estrella')) {
    // Dibuja una estrella de 5 puntas rellena, centrada en ($cx,$cy), radio exterior $R.
    function nb_estrella($img, int $cx, int $cy, int $R, int $color): void {
        $rIn = $R * 0.382; // razón pentagrama estándar
        $pts = [];
        for ($i = 0; $i < 10; $i++) {
            $ang = -M_PI / 2 + $i * M_PI / 5;  // arranca en la punta superior
            $rad = ($i % 2 === 0) ? $R : $rIn;
            $pts[] = (int)round($cx + $rad * cos($ang));
            $pts[] = (int)round($cy + $rad * sin($ang));
        }
        // PHP 8: imagefilledpolygon admite firma sin num_points
        imagefilledpolygon($img, $pts, $color);
    }
}

if (!function_exists('nb_cat_rating_render')) {
    // Núcleo de la línea "CATEGORIA  ·  ★ 5,0 (1)" / "CATEGORIA  ·  ★ Nuevo".
    // Estrella + texto del rating en NEGRO #1a1a1a (ambos estados). Separador "·" gris #D1D5DB.
    // Con reseñas → Inter-Bold; "Nuevo" → Inter-SemiBold. $cat ya viene en MAYÚSCULAS (puede ser '').
    // Dibuja a partir de $x (baseline $yBase) y devuelve el ancho total. Si $soloMedir → no dibuja.
    function nb_cat_rating_render($img, string $cat, string $fBold, string $fSemi, float $size, float $prom, int $votos, int $x, int $yBase, int $cAcento, bool $soloMedir = false): int {
        $hayRes = $prom > 0;
        $fRate  = $hayRes ? $fBold : $fSemi;
        $rTxt   = $hayRes ? (number_format($prom, 1, ',', '.') . ' (' . $votos . ')') : 'Nuevo';
        $sep    = '·';

        // Escala estrella/gaps proporcional al tamaño de fuente (POST 28 → 15px; HISTORY 32 → ~17px)
        $starR = (int)round($size * 0.54);
        $gStar = (int)round($size * 0.46);
        $gSep  = (int)round($size * 0.78);

        $catW  = $cat !== '' ? nb_ancho_texto($fBold, $size, $cat) : 0;
        $sepW  = $cat !== '' ? nb_ancho_texto($fBold, $size, $sep) : 0;
        $rateW = nb_ancho_texto($fRate, $size, $rTxt);
        $total = ($cat !== '' ? $catW + $gSep + $sepW + $gSep : 0) + ($starR * 2 + $gStar + $rateW);

        if ($soloMedir) return $total;

        $cSep   = imagecolorallocate($img, 209, 213, 219); // #D1D5DB
        $cNegro = imagecolorallocate($img, 26, 26, 26);    // #1a1a1a
        if ($cat !== '') {
            imagettftext($img, $size, 0, $x, $yBase, $cAcento, $fBold, $cat);
            $x += $catW + $gSep;
            imagettftext($img, $size, 0, $x, $yBase, $cSep, $fBold, $sep);
            $x += $sepW + $gSep;
        }
        $cyStar = $yBase - (int)($size * 0.35);
        nb_estrella($img, $x + $starR, $cyStar, $starR, $cNegro);
        imagettftext($img, $size, 0, $x + $starR * 2 + $gStar, $yBase, $cNegro, $fRate, $rTxt);
        return $total;
    }
}

if (!function_exists('nb_dibujar_cat_rating_centrado')) {
    // Línea categoría · ★ rating centrada horizontalmente en ancho $W (POST).
    function nb_dibujar_cat_rating_centrado($img, string $cat, string $fBold, string $fSemi, float $size, float $prom, int $votos, int $W, int $yBase, int $cAcento): void {
        $total = nb_cat_rating_render($img, $cat, $fBold, $fSemi, $size, $prom, $votos, 0, $yBase, $cAcento, true);
        $x = (int)(($W - $total) / 2);
        nb_cat_rating_render($img, $cat, $fBold, $fSemi, $size, $prom, $votos, $x, $yBase, $cAcento, false);
    }
}

if (!function_exists('nb_dibujar_cat_rating_izquierda')) {
    // Línea categoría · ★ rating alineada a la izquierda desde $x (HISTORY).
    function nb_dibujar_cat_rating_izquierda($img, string $cat, string $fBold, string $fSemi, float $size, float $prom, int $votos, int $x, int $yBase, int $cAcento): void {
        nb_cat_rating_render($img, $cat, $fBold, $fSemi, $size, $prom, $votos, $x, $yBase, $cAcento, false);
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
            // Foto circular directa sobre el anillo. Sin círculo blanco de fondo:
            // ese relleno asomaba como un halo blanco de ~1px entre el anillo y la foto
            // (antialiasing del ellipse vs. corte duro de la máscara circular).
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

/* ---------- Paleta de marca (compartida entre todos los generadores) ---------- */

if (!function_exists('nb_paleta_marca')) {
    // Aloca los 5 colores de marca una sola vez por $img. Fondo #F0F6FA, acento #54A6D8,
    // texto #1a1a1a, texto secundario #6B7280, blanco. Reutilizado por servicios y novedades
    // para garantizar la misma identidad visual sin duplicar los imagecolorallocate() en cada plantilla.
    function nb_paleta_marca($img): array {
        return [
            'bg'     => imagecolorallocate($img, 240, 246, 250),
            'acento' => imagecolorallocate($img, 84, 166, 216),
            'txt'    => imagecolorallocate($img, 26, 26, 26),
            'txt2'   => imagecolorallocate($img, 107, 114, 128),
            'blanco' => imagecolorallocate($img, 255, 255, 255),
        ];
    }
}

if (!function_exists('nb_dibujar_badge_pill')) {
    // Pill genérico (fondo sólido redondeado + texto) — reutilizado por
    // "Disponible" y como base conceptual del badge de categoría.
    function nb_dibujar_badge_pill($img, string $font, float $size, string $txt, int $x, int $yTop, int $cFondo, int $cTexto, int $padX = 16, int $padY = 10): array {
        $w  = nb_ancho_texto($font, $size, $txt);
        $bh = (int)($size * 1.15) + $padY * 2;
        $bw = $w + $padX * 2;
        nb_rect_redondeado($img, $x, $yTop, $x + $bw, $yTop + $bh, (int)($bh / 2), $cFondo);
        imagettftext($img, $size, 0, $x + $padX, $yTop + $bh - $padY - (int)($size * 0.22), $cTexto, $font, $txt);
        return [$bw, $bh];
    }
}

if (!function_exists('nb_dibujar_badge_categoria')) {
    // Badge de categoría con "ícono" genérico (punto sólido) + borde acento + relleno
    // blanco — misma técnica de doble rect que la card "sticker" del HISTORY.
    function nb_dibujar_badge_categoria($img, string $fSemi, string $cat, int $x, int $yTop, int $cBorde, int $cBlanco, int $cTexto): array {
        $padX = 16; $padY = 9; $size = 24;
        $w  = nb_ancho_texto($fSemi, $size, $cat);
        $dotR = 6; $gapDot = 14;
        $bh = (int)($size * 1.15) + $padY * 2;
        $bw = $dotR * 2 + $gapDot + $w + $padX * 2;

        nb_rect_redondeado($img, $x, $yTop, $x + $bw, $yTop + $bh, (int)($bh / 2), $cBorde);
        nb_rect_redondeado($img, $x + 2, $yTop + 2, $x + $bw - 2, $yTop + $bh - 2, (int)($bh / 2) - 2, $cBlanco);

        $cyDot = $yTop + (int)($bh / 2);
        imagefilledellipse($img, $x + $padX + $dotR, $cyDot, $dotR * 2, $dotR * 2, $cTexto);
        imagettftext($img, $size, 0, $x + $padX + $dotR * 2 + $gapDot, $yTop + $bh - $padY - (int)($size * 0.22), $cTexto, $fSemi, $cat);
        return [$bw, $bh];
    }
}

if (!function_exists('nb_dibujar_features_fijas')) {
    // 4 features fijas (marketing genérico, iguales para todos los tutores — confirmado).
    // Solo texto, sin dot decorativo (quitado tras feedback visual de producción).
    function nb_dibujar_features_fijas($img, string $fSemi, int $W, int $yTop, int $cTxt2): int {
        $features = ['Clases online', 'Material incluido', 'Responde rápido', 'Primera clase disponible'];
        $n = count($features);
        $padX = 100;
        $usableW = $W - $padX * 2;
        $colW = (int)($usableW / $n);
        $size = 18;

        foreach ($features as $i => $label) {
            $colX = $padX + $colW * $i;
            $lineas = nb_wrap_texto($fSemi, $size, $label, $colW - 10, 2);
            foreach ($lineas as $j => $ln) {
                $wLn = nb_ancho_texto($fSemi, $size, $ln);
                $xLn = $colX + (int)(($colW - $wLn) / 2);
                nb_texto_izquierda($img, $fSemi, $size, $cTxt2, $ln, $xLn, $yTop + 20 + $j * 24);
            }
        }
        return $yTop + 20 + 24 + 16;
    }
}

if (!function_exists('nb_dibujar_boton_agendar')) {
    function nb_dibujar_boton_agendar($img, string $fBold, int $W, int $yTop, int $cAcento, int $cBlanco): int {
        $txt = 'Agendar clase';
        $size = 26;
        $w = nb_ancho_texto($fBold, $size, $txt);
        $padX = 40; $padY = 18;
        $bw = $w + $padX * 2;
        $bh = (int)($size * 1.15) + $padY * 2;
        $x = (int)(($W - $bw) / 2);
        nb_rect_redondeado($img, $x, $yTop, $x + $bw, $yTop + $bh, (int)($bh / 2), $cAcento);
        imagettftext($img, $size, 0, $x + $padX, $yTop + $bh - $padY - (int)($size * 0.22), $cBlanco, $fBold, $txt);
        return $bh;
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
        $pal = nb_paleta_marca($img);
        $cBg = $pal['bg']; $cAcento = $pal['acento']; $cTxt = $pal['txt']; $cTxt2 = $pal['txt2']; $cBlanco = $pal['blanco'];
        // #10B981 (emerald-500) — mismo verde que usa detalle_servicio.php para "Disponible",
        // NO el #16A34A (green-600) del badge OFERTA (son conceptos y colores distintos).
        $cVerdeDisp = imagecolorallocate($img, 16, 185, 129);
        imagefilledrectangle($img, 0, 0, $W, $H, $cBg);

        /* ===== PARTE 1: avatar grande + badge Disponible + nombre + institución ===== */
        $diamAv = 380; $avTop = 90; $avLeft = 70;
        $avCx = $avLeft + (int)($diamAv / 2);
        nb_dibujar_avatar($img, $s, $avCx, $avTop, $diamAv, $cAcento, $cBlanco, $fBold);
        $avBottom = $avTop + $diamAv;

        $colX = $avLeft + $diamAv + 40;
        $colMaxW = $W - 40 - $colX;

        $nombre = nb_truncar_una_linea($fBold, 40, nombre_publico_tutor((string)($s['nombre_alumno'] ?? $s['nombre'] ?? '')), $colMaxW);
        $yNombre = 180;
        nb_texto_izquierda($img, $fBold, 40, $cTxt, $nombre, $colX, $yNombre);

        if (!empty($s['disponible'])) {
            $wNombre = nb_ancho_texto($fBold, 40, $nombre);
            nb_dibujar_badge_pill($img, $fSemi, 20, 'Disponible', $colX + $wNombre + 20, $yNombre - 32, $cVerdeDisp, $cBlanco);
        }

        $instRaw = trim((string)($s['institucion_maestra'] ?? $s['institucion'] ?? ''));
        $inst = $instRaw !== '' ? mb_strtoupper(html_entity_decode(abreviar_institucion($instRaw, 22)), 'UTF-8') : 'TUTOR PARTICULAR';
        $inst = nb_truncar_una_linea($fReg, 24, $inst, $colMaxW);
        $yInst = $yNombre + 45;
        nb_texto_izquierda($img, $fReg, 24, $cTxt2, $inst, $colX, $yInst);

        /* ===== PARTE 2: badge categoría (separado) + línea de rating (separada) ===== */
        $cat = mb_strtoupper(trim((string)($s['categoria'] ?? '')), 'UTF-8');
        $yCatBadge = $yInst + 30;
        [$bwCat, $bhCat] = nb_dibujar_badge_categoria($img, $fSemi, $cat, $colX, $yCatBadge, $cAcento, $cBlanco, $cAcento);

        $prom  = (float)($s['rating_prom'] ?? 0);
        $votos = (int)($s['rating_votos'] ?? 0);
        $yRating = $yCatBadge + $bhCat + 34;
        nb_dibujar_cat_rating_izquierda($img, '', $fBold, $fSemi, 26, $prom, $votos, $colX, $yRating, $cAcento);

        /* ===== PARTE 3: título genérico + bio condicional ===== */
        $y = max($avBottom, $yRating + 20) + 50;

        $tituloGenerico = 'Clases particulares de ' . trim((string)($s['categoria'] ?? ''));
        $lineasTit = nb_wrap_texto($fSemi, 34, $tituloGenerico, $W - 160, 1);
        foreach ($lineasTit as $i => $ln) {
            nb_texto_centrado($img, $fSemi, 34, $cTxt, $ln, $W, $y + $i * 46);
        }
        $y += count($lineasTit) * 46 + 30;

        $bio = trim((string)($s['bio'] ?? ''));
        if ($bio !== '') {
            $lineasBio = nb_wrap_texto($fReg, 26, $bio, $W - 200, 2);
            foreach ($lineasBio as $i => $ln) {
                nb_texto_centrado($img, $fReg, 26, $cTxt2, $ln, $W, $y + $i * 36);
            }
            $y += count($lineasBio) * 36 + 40;
        } else {
            $y += 10;
        }

        /* ===== PARTE 4: features fijas + precio + botón + marca ===== */
        $yFeaturesFin = nb_dibujar_features_fijas($img, $fSemi, $W, $y, $cTxt2);
        $y = $yFeaturesFin + 40;

        nb_dibujar_precio_centrado($img, $s, $fBold, $fSemi, $fReg, 48, 32, $W, $y, $cTxt, $cTxt2);
        $y += 60;

        $bhBoton = nb_dibujar_boton_agendar($img, $fBold, $W, $y, $cAcento, $cBlanco);
        $y += $bhBoton + 35;

        // Marca — mismo estilo que el diseño original (y que nb_generar_imagen_novedad_post()
        // sigue usando hoy): Inter-Bold 28, borde derecho en W*0.75, color acento.
        nb_texto_derecha($img, $fBold, 28, $cAcento, 'Nubira.cl', (int)($W * 0.75), $y);

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

// Precio del HISTORY: si hay oferta → badge OFERTA + oferta grande + original tachado; si no → "$X".
// Sin "desde". "CLP" siempre en tamaño menor (Inter-SemiBold) que el monto.
if (!function_exists('nb_precio_history')) {
    function nb_precio_history($img, array $s, int $x, int $yBase, string $fBold, string $fSemi, string $fReg, int $cTxt, int $cTxt2, int $cAzul, int $cBlanco): void {
        $of = (float)($s['precio_oferta'] ?? 0);
        $pr = (float)($s['precio'] ?? 0);
        $szBig = 52; $szCLP = 36;
        // Mismo criterio que "Precios de última hora" en vitrina.php: is_subvencionado=1
        // y oferta_termino sin vencer (NULL o >= hoy).
        $ofertaVigente = !empty($s['is_subvencionado']) && (int)$s['is_subvencionado'] === 1
            && (empty($s['oferta_termino']) || $s['oferta_termino'] >= date('Y-m-d'));

        if ($of > 0 && $ofertaVigente) {
            // Badge OFERTA (pill azul + texto blanco) encima del precio
            $badge = 'OFERTA';
            $bw  = nb_ancho_texto($fBold, 22, $badge);
            $bx1 = $x; $by1 = $yBase - 115; $bx2 = $x + $bw + 36; $by2 = $by1 + 44;
            $cVerde = imagecolorallocate($img, 22, 163, 74); // #16A34A green-600 (igual que badge oferta de la plataforma)
            nb_rect_redondeado($img, $bx1, $by1, $bx2, $by2, 14, $cVerde);
            imagettftext($img, 22, 0, $bx1 + 18, $by1 + 31, $cBlanco, $fBold, $badge);

            // Precio oferta grande (negro) + " CLP" más pequeño
            $ofMain = '$' . number_format($of, 0, ',', '.');
            imagettftext($img, $szBig, 0, $x, $yBase, $cTxt, $fBold, $ofMain);
            $wMain = nb_ancho_texto($fBold, $szBig, $ofMain);
            imagettftext($img, $szCLP, 0, $x + $wMain, $yBase, $cTxt, $fSemi, ' CLP');
            $wOf = $wMain + nb_ancho_texto($fSemi, $szCLP, ' CLP');

            // Precio original tachado (gris) al lado
            $origText = '$' . number_format($pr, 0, ',', '.');
            $xo = $x + $wOf + 24;
            $yo = $yBase - 6;
            imagettftext($img, 28, 0, $xo, $yo, $cTxt2, $fReg, $origText);
            $wo = nb_ancho_texto($fReg, 28, $origText);
            imagesetthickness($img, 3);
            imageline($img, $xo, $yo - 9, $xo + $wo, $yo - 9, $cTxt2);
            imagesetthickness($img, 1);
        } elseif ($pr > 0) {
            // "$X" grande + " CLP" pequeño (sin "desde")
            $main = '$' . number_format($pr, 0, ',', '.');
            imagettftext($img, $szBig, 0, $x, $yBase, $cTxt, $fBold, $main);
            $wMain = nb_ancho_texto($fBold, $szBig, $main);
            imagettftext($img, $szCLP, 0, $x + $wMain, $yBase, $cTxt, $fSemi, ' CLP');
        } else {
            imagettftext($img, $szBig, 0, $x, $yBase, $cTxt, $fBold, 'Gratis');
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
        $pal = nb_paleta_marca($img);
        $cBg = $pal['bg']; $cAzul = $pal['acento']; $cBlanco = $pal['blanco']; $cTxt = $pal['txt']; $cTxt2 = $pal['txt2'];
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

        // Institución (left, abreviada con el diccionario de la plataforma)
        $instRaw = trim((string)($s['institucion_maestra'] ?? $s['institucion'] ?? ''));
        $inst = $instRaw !== '' ? mb_strtoupper(html_entity_decode(abreviar_institucion($instRaw, 22)), 'UTF-8') : 'TUTOR PARTICULAR';
        $inst = nb_truncar_una_linea($fReg, 28, $inst, $maxTxt);
        nb_texto_izquierda($img, $fReg, 28, $cTxt2, $inst, $padL, 905);

        // Categoría · ★ rating (left) — estrella negra, mismo estilo que POST (escalado a 32)
        $prom  = (float)($s['rating_prom'] ?? 0);
        $votos = (int)($s['rating_votos'] ?? 0);
        $cat = mb_strtoupper(trim((string)($s['categoria'] ?? '')), 'UTF-8');
        nb_dibujar_cat_rating_izquierda($img, $cat, $fBold, $fSemi, 32, $prom, $votos, $padL, 1000, $cAzul);

        // Título 1 línea (left)
        $titulo = nb_truncar_una_linea($fSemi, 36, (string)($s['titulo'] ?? ''), $maxTxt);
        nb_texto_izquierda($img, $fSemi, 36, $cTxt, $titulo, $padL, 1075);

        // Precio condicional (oferta tachada o "desde") — badge con respiración
        nb_precio_history($img, $s, $padL, 1230, $fBold, $fSemi, $fReg, $cTxt, $cTxt2, $cAzul, $cBlanco);

        // Nubira.cl FUERA del marco interno, abajo-izquierda DENTRO de la card
        nb_texto_izquierda($img, $fBold, 28, $cAzul, 'Nubira.cl', $cardX1 + 50, $cardY2 - 60);

        $ok = imagejpeg($img, $output_path, 90);
        imagedestroy($img);
        return (bool)$ok;
    }
}

/* ============================================================
   PASO 4 — NOVEDAD (Fase 2 Marketing/Cards): POST + HISTORY
   Plantilla genérica (título + cuerpo), sin datos de servicio real.
   Sin ícono/emoji: GD+Inter no puede dibujar glifos de emoji reales
   (probado — 3 emojis distintos generan el mismo glyph .notdef).
   ============================================================ */

if (!function_exists('nb_generar_imagen_novedad_post')) {
    function nb_generar_imagen_novedad_post(array $n, string $output_path): bool {
        $W = 1080; $H = 1080;
        $fReg  = nb_fonts_dir() . 'Inter-Regular.ttf';
        $fSemi = nb_fonts_dir() . 'Inter-SemiBold.ttf';
        $fBold = nb_fonts_dir() . 'Inter-Bold.ttf';
        foreach ([$fReg, $fSemi, $fBold] as $f) if (!is_file($f)) return false;

        $img = imagecreatetruecolor($W, $H);
        $pal = nb_paleta_marca($img);
        $cBg = $pal['bg']; $cAcento = $pal['acento']; $cTxt = $pal['txt']; $cTxt2 = $pal['txt2'];
        imagefilledrectangle($img, 0, 0, $W, $H, $cBg);

        $padX = 100; $maxW = $W - ($padX * 2); // 880
        $szTit = 48; $lhTit = 60;
        $szCuerpo = 28; $lhCuerpo = 42;
        $gapTitCuerpo = 40;

        $titulo = trim((string)($n['titulo'] ?? ''));
        $lineasTit = nb_wrap_texto($fBold, $szTit, $titulo, $maxW, 2);

        $cuerpo = trim((string)($n['cuerpo'] ?? ''));
        $lineasCuerpo = nb_wrap_texto($fSemi, $szCuerpo, $cuerpo, $maxW, 5);

        // Sin avatar: título + cuerpo se centran como bloque dentro del área disponible
        // ARRIBA del logo. El logo queda FIJO en y=990 — misma altura que
        // nb_generar_imagen_post() (servicios) — para mantener consistencia visual
        // entre ambos tipos de card, en vez de moverse según el largo del contenido.
        $alturaDisponible = 900;
        $altoTit = count($lineasTit) * $lhTit;
        $altoCuerpo = count($lineasCuerpo) * $lhCuerpo;
        $altoBloque = $altoTit + $gapTitCuerpo + $altoCuerpo;
        $yBloque = (int)(($alturaDisponible - $altoBloque) / 2);

        // Placeholder visual: círculo reservado para un futuro ícono/imagen de la novedad
        // (subida manual por el admin — funcionalidad pendiente, ver CLAUDE.md). Por ahora
        // solo ocupa el espacio, sin lógica de carga.
        $diamIcono = 90; $gapIconoTit = 30;
        $cyIcono = $yBloque - $gapIconoTit - (int)($diamIcono / 2);
        imagefilledellipse($img, (int)($W / 2), $cyIcono, $diamIcono, $diamIcono, $cAcento);

        $yTit = $yBloque + (int)($szTit * 0.75);
        foreach ($lineasTit as $i => $ln) {
            nb_texto_centrado($img, $fBold, $szTit, $cTxt, $ln, $W, $yTit + $i * $lhTit);
        }

        $yCuerpo = $yTit + (count($lineasTit) - 1) * $lhTit + $gapTitCuerpo;
        foreach ($lineasCuerpo as $i => $ln) {
            nb_texto_centrado($img, $fSemi, $szCuerpo, $cTxt2, $ln, $W, $yCuerpo + $i * $lhCuerpo);
        }

        // Marca — misma posición Y que nb_generar_imagen_post() (servicios): y=990.
        nb_texto_derecha($img, $fBold, 28, $cAcento, 'Nubira.cl', (int)($W * 0.75), 990);

        $ok = imagejpeg($img, $output_path, 90);
        imagedestroy($img);
        return (bool)$ok;
    }
}

if (!function_exists('nb_generar_imagen_novedad_history')) {
    function nb_generar_imagen_novedad_history(array $n, string $output_path): bool {
        $W = 1080; $H = 1920;
        $fReg  = nb_fonts_dir() . 'Inter-Regular.ttf';
        $fSemi = nb_fonts_dir() . 'Inter-SemiBold.ttf';
        $fBold = nb_fonts_dir() . 'Inter-Bold.ttf';
        foreach ([$fReg, $fSemi, $fBold] as $f) if (!is_file($f)) return false;

        $img = imagecreatetruecolor($W, $H);
        $pal = nb_paleta_marca($img);
        $cBg = $pal['bg']; $cAzul = $pal['acento']; $cBlanco = $pal['blanco']; $cTxt = $pal['txt']; $cTxt2 = $pal['txt2'];
        imagefilledrectangle($img, 0, 0, $W, $H, $cBg);

        // Card "sticker" + marco interno, repositionado dentro de la zona segura real de
        // Instagram Stories/TikTok: ~250px libres arriba (perfil/username/barra de progreso)
        // y ~340px libres abajo (barra de respuesta IG + caption/nav de TikTok) — en vez de
        // los 460px/460px que dejaba fija la card heredada de nb_generar_imagen_history()
        // (servicios). Mismos gaps internos card->marco (40 arriba, 160 abajo).
        $cardX1 = 90; $cardX2 = 990; $cardY1 = 250; $cardY2 = 1580; $cardR = 40;
        $cBorde = imagecolorallocate($img, 229, 231, 235);
        nb_rect_redondeado($img, $cardX1, $cardY1, $cardX2, $cardY2, $cardR, $cBorde);
        nb_rect_redondeado($img, $cardX1 + 2, $cardY1 + 2, $cardX2 - 2, $cardY2 - 2, $cardR - 2, $cBlanco);

        $inX1 = 130; $inX2 = 950; $inY1 = 290; $inY2 = 1420; $inR = 30;
        nb_rect_redondeado($img, $inX1, $inY1, $inX2, $inY2, $inR, $cBorde);
        nb_rect_redondeado($img, $inX1 + 2, $inY1 + 2, $inX2 - 2, $inY2 - 2, $inR - 2, $cBlanco);

        $maxTxt = ($inX2 - 40) - ($inX1 + 40); // 740
        $szTit = 44; $lhTit = 54;
        $szCuerpo = 28; $lhCuerpo = 40;
        $gapTitCuerpo = 50;

        $titulo = trim((string)($n['titulo'] ?? ''));
        $lineasTit = nb_wrap_texto($fBold, $szTit, $titulo, $maxTxt, 2);

        $cuerpo = trim((string)($n['cuerpo'] ?? ''));
        $lineasCuerpo = nb_wrap_texto($fSemi, $szCuerpo, $cuerpo, $maxTxt, 6);

        // Sin avatar: título + cuerpo se centran DENTRO del marco interno (inY1..inY2),
        // no en un $yTit=720 fijo — ese valor era el hueco que en servicios ocupan
        // avatar/nombre/institución/rating antes del título.
        $altoTit = count($lineasTit) * $lhTit;
        $altoCuerpo = count($lineasCuerpo) * $lhCuerpo;
        $altoBloque = $altoTit + $gapTitCuerpo + $altoCuerpo;
        $yBloque = $inY1 + (int)((($inY2 - $inY1) - $altoBloque) / 2);

        // Placeholder visual: círculo reservado para un futuro ícono/imagen de la novedad
        // (subida manual por el admin — funcionalidad pendiente, ver CLAUDE.md). Por ahora
        // solo ocupa el espacio, sin lógica de carga.
        $diamIcono = 90; $gapIconoTit = 30;
        $cyIcono = $yBloque - $gapIconoTit - (int)($diamIcono / 2);
        imagefilledellipse($img, (int)($W / 2), $cyIcono, $diamIcono, $diamIcono, $cAzul);

        $yTit = $yBloque + (int)($szTit * 0.75);
        foreach ($lineasTit as $i => $ln) {
            nb_texto_centrado($img, $fBold, $szTit, $cTxt, $ln, $W, $yTit + $i * $lhTit);
        }

        $yCuerpo = $yTit + (count($lineasTit) - 1) * $lhTit + $gapTitCuerpo;
        foreach ($lineasCuerpo as $i => $ln) {
            nb_texto_centrado($img, $fSemi, $szCuerpo, $cTxt2, $ln, $W, $yCuerpo + $i * $lhCuerpo);
        }

        // Nubira.cl fuera del marco interno, abajo-izquierda dentro de la card (igual que servicio HISTORY)
        nb_texto_izquierda($img, $fBold, 28, $cAzul, 'Nubira.cl', $cardX1 + 50, $cardY2 - 60);

        $ok = imagejpeg($img, $output_path, 90);
        imagedestroy($img);
        return (bool)$ok;
    }
}

if (!function_exists('nb_fingerprint_novedad')) {
    function nb_fingerprint_novedad(array $n): string {
        $base = NB_IMG_VERSION . '|' . ($n['id'] ?? '') . '|' . ($n['titulo'] ?? '') . '|' . ($n['cuerpo'] ?? '');
        return substr(md5($base), 0, 10);
    }
}

if (!function_exists('nb_novedades_dir')) {
    function nb_novedades_dir(): string {
        $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($root === '') $root = dirname(__DIR__, 2);
        return rtrim($root, '/\\') . '/upload/novedades/';
    }
}

if (!function_exists('nb_obtener_imagen_novedad')) {
    // Devuelve la RUTA FÍSICA del JPG (cache hit o recién generado), o '' si falla/no existe.
    function nb_obtener_imagen_novedad(int $novedad_id, string $formato): string {
        global $conn;
        $formato = ($formato === 'history') ? 'history' : 'post';
        if (!isset($conn) || !($conn instanceof mysqli) || $novedad_id <= 0) return '';

        $st = $conn->prepare("SELECT id, titulo, cuerpo FROM novedades WHERE id = ? LIMIT 1");
        if (!$st) return '';
        $st->bind_param('i', $novedad_id);
        $st->execute();
        $n = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$n) return '';

        require_once __DIR__ . '/../seguridad_url.php';
        $hash = function_exists('nubira_encriptar_id') ? nubira_encriptar_id($novedad_id) : (string)$novedad_id;
        $fp   = nb_fingerprint_novedad($n);

        $dir = nb_novedades_dir();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . $hash . '_' . $formato . '_' . $fp . '.jpg';

        if (is_file($file)) return $file; // cache hit

        $ok = ($formato === 'history')
            ? nb_generar_imagen_novedad_history($n, $file)
            : nb_generar_imagen_novedad_post($n, $file);

        return $ok && is_file($file) ? $file : '';
    }
}

if (!function_exists('nb_fingerprint_servicio')) {
    function nb_fingerprint_servicio(array $s): string {
        $base = NB_IMG_VERSION . '|' . ($s['id'] ?? '') . '|' . ($s['titulo'] ?? '') . '|' . ($s['precio'] ?? '')
              . '|' . ($s['precio_oferta'] ?? '') . '|' . ($s['foto_perfil'] ?? '')
              . '|' . ($s['categoria'] ?? '') . '|' . ($s['institucion_maestra'] ?? '')
              . '|' . ($s['rating_prom'] ?? '') . '|' . ($s['rating_votos'] ?? '')
              . '|' . ($s['bio'] ?? '') . '|' . ($s['disponible'] ?? '');
        return substr(md5($base), 0, 10);
    }
}

if (!function_exists('nb_version_imagen_servicio')) {
    // Versión ligera para cache-busting de URL (?v=): mismo fingerprint que decide si el
    // archivo en disco se regenera, pero con una query mínima (sin traer la fila completa
    // ni generar nada). Cambia si y solo si algo visualmente relevante en la imagen cambió
    // (precio, oferta, foto, categoría, institución, rating) — NO con visitas/contrataciones.
    function nb_version_imagen_servicio(int $servicio_id): string {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli) || $servicio_id <= 0) return '0';

        $sql = "SELECT s.id, s.titulo, s.precio, s.precio_oferta, s.categoria, a.foto_perfil,
                       a.bio,
                       COALESCE(dp.institucion, a.institucion) AS institucion_maestra,
                       COALESCE((SELECT ROUND(AVG(v.calificacion),1) FROM valoraciones v
                                 WHERE v.servicio_id = s.id AND v.calificacion > 0
                                   AND v.rol_evaluado = 'vendedor'), 0) AS rating_prom,
                       (SELECT COUNT(*) FROM valoraciones v
                        WHERE v.servicio_id = s.id AND v.calificacion > 0
                          AND v.rol_evaluado = 'vendedor') AS rating_votos,
                       (SELECT AVG(rt.minutos_respuesta) FROM respuestas_tutor rt
                        WHERE rt.tutor_id = s.alumno_id
                          AND rt.creado_en > (NOW() - INTERVAL 30 DAY)
                          AND rt.minutos_respuesta <= 1440) AS tiempo_resp_calculado
                FROM servicios s
                LEFT JOIN alumnos a ON s.alumno_id = a.id
                LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
                WHERE s.id = ? LIMIT 1";
        $st = $conn->prepare($sql);
        if (!$st) return '0';
        $st->bind_param('i', $servicio_id);
        $st->execute();
        $s = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$s) return '0';

        $s['disponible'] = ($s['tiempo_resp_calculado'] !== null && (float)$s['tiempo_resp_calculado'] < 60) ? 1 : 0;

        return nb_fingerprint_servicio($s);
    }
}

if (!function_exists('nb_obtener_imagen_compartir')) {
    // Devuelve la RUTA FÍSICA del JPG (cache hit o recién generado), o '' si falla.
    function nb_obtener_imagen_compartir(int $servicio_id, string $formato): string {
        global $conn;
        $formato = ($formato === 'history') ? 'history' : 'post';
        if (!isset($conn) || !($conn instanceof mysqli) || $servicio_id <= 0) return '';

        $sql = "SELECT s.*, a.nombre AS nombre_alumno, a.foto_perfil,
                       a.bio,
                       COALESCE(dp.institucion, a.institucion) AS institucion_maestra,
                       COALESCE((SELECT ROUND(AVG(v.calificacion),1) FROM valoraciones v
                                 WHERE v.servicio_id = s.id AND v.calificacion > 0
                                   AND v.rol_evaluado = 'vendedor'), 0) AS rating_prom,
                       (SELECT COUNT(*) FROM valoraciones v
                        WHERE v.servicio_id = s.id AND v.calificacion > 0
                          AND v.rol_evaluado = 'vendedor') AS rating_votos,
                       (SELECT AVG(rt.minutos_respuesta) FROM respuestas_tutor rt
                        WHERE rt.tutor_id = s.alumno_id
                          AND rt.creado_en > (NOW() - INTERVAL 30 DAY)
                          AND rt.minutos_respuesta <= 1440) AS tiempo_resp_calculado
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

        $s['disponible'] = ($s['tiempo_resp_calculado'] !== null && (float)$s['tiempo_resp_calculado'] < 60) ? 1 : 0;

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
