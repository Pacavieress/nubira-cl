<?php
// Núcleo GD — genera la imagen POST 1080x1080 para compartir un servicio.
// Paso 2: solo POST, sin cache, sin endpoint. Fondo #F0F6FA + acento #54A6D8.
require_once __DIR__ . '/foto_tutor.php';
require_once __DIR__ . '/nombre_publico.php';
require_once __DIR__ . '/institucion.php';

// Versión del generador de imágenes. Incrementar (v1 → v2 → ...) invalida
// AUTOMÁTICAMENTE todo el cache de /upload/compartir/ cuando se cambia el diseño
// visual, porque entra en el fingerprint (no depende solo de los datos del servicio).
if (!defined('NB_IMG_VERSION')) define('NB_IMG_VERSION', 'v23');

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

if (!function_exists('nb_centrar_baseline_vertical')) {
    // Calcula el baseline Y necesario para centrar verticalmente $txt dentro de una caja
    // de alto $bh que empieza en $yTop, usando la caja de tinta REAL de la fuente
    // (imagettfbbox) — no un offset fijo. Un offset fijo asume una altura de mayúscula
    // estándar y falla con texto más alto de lo esperado (tildes: Á, É, Í, Ó, Ú, Ñ).
    function nb_centrar_baseline_vertical(string $font, float $size, string $txt, int $yTop, int $bh): int {
        $bb = imagettfbbox($size, 0, $font, $txt);
        $capHeight = -min($bb[7], $bb[5]); // tinta real por encima del baseline
        $descender = max($bb[1], $bb[3]);  // tinta real por debajo del baseline (0 si no hay)
        return $yTop + (int)round($bh / 2 + ($capHeight - $descender) / 2);
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
    function nb_cat_rating_render($img, string $cat, string $fBold, string $fSemi, float $size, float $prom, int $votos, int $x, int $yBase, int $cAcento, bool $soloMedir = false, ?int $cRating = null): int {
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
        $cNegro = $cRating ?? imagecolorallocate($img, 26, 26, 26); // #1a1a1a si no se pasa override
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
    function nb_dibujar_cat_rating_izquierda($img, string $cat, string $fBold, string $fSemi, float $size, float $prom, int $votos, int $x, int $yBase, int $cAcento, ?int $cRating = null): void {
        nb_cat_rating_render($img, $cat, $fBold, $fSemi, $size, $prom, $votos, $x, $yBase, $cAcento, false, $cRating);
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
            // 2 iniciales (nombre + apellido) — mismo patrón que obtener_iniciales()
            // en bandeja_entrada.php y el cálculo inline de header.php/header_aula.php.
            // Antes esta función tomaba solo mb_substr($nombre, 0, 1) — 1 sola letra,
            // inconsistente con el resto del sitio (bug real, no el de recorte que se
            // sospechó antes: la imagen no estaba cortada, le faltaba la 2da inicial).
            $partesNombre = explode(' ', $nombre !== '' ? $nombre : '?');
            $ini = mb_substr($partesNombre[0], 0, 1, 'UTF-8');
            if (isset($partesNombre[1]) && $partesNombre[1] !== '') {
                $ini .= mb_substr($partesNombre[1], 0, 1, 'UTF-8');
            }
            $ini = mb_strtoupper($ini, 'UTF-8');
            // Tamaño de fuente proporcional a $diam (antes fijo en 130 sin importar el
            // diámetro) — calibrado para que a diam=400 (servicio POST, el caso que ya
            // se veía bien en producción) el resultado sea idéntico a 130, sin cambio
            // visual ahí. A diámetros chicos (ej. avatar secundario de apunte, 72px) la
            // letra fija de 130 desbordaba masivamente el círculo — bug real encontrado
            // al integrar el avatar en el contexto de apunte.
            $sz = max(12, (int)round($diam * 0.325));
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
        $yTexto = nb_centrar_baseline_vertical($fSemi, $size, $cat, $yTop, $bh);
        imagettftext($img, $size, 0, $x + $padX + $dotR * 2 + $gapDot, $yTexto, $cTexto, $fSemi, $cat);
        return [$bw, $bh];
    }
}

if (!function_exists('nb_dibujar_features_fijas')) {
    // 1 sola columna alineada a la izquierda, texto más grande (18px -> 30px) — ANTES
    // grilla 2x2. Cambio de diseño pedido explícitamente por el usuario (30/08/2026),
    // confirmado contra 2 prototipos renderizados antes de tocar este archivo — ver el
    // mismo cambio espejado en dibujarFeaturesFijas() (compartirServicio.generador.ts).
    function nb_dibujar_features_fijas($img, string $fSemi, int $W, int $yTop, int $cTxt, int $cAcentoDot): int {
        $features = [
            'Clase 100% online en Nubira', 'Chat anónimo antes de contratar',
            'Horarios publicados por el tutor', 'Garantía Nubira',
        ];
        $padX = 110; $size = 30; $rowGap = 58; // padX debe coincidir con $M de nb_generar_imagen_post()
        $dotR = 7; $dotGap = 16;

        foreach ($features as $i => $label) {
            $yBase = $yTop + 14 + $i * $rowGap;
            $cyDot = $yBase - (int)($size * 0.35);
            imagefilledellipse($img, $padX + $dotR, $cyDot, $dotR * 2, $dotR * 2, $cAcentoDot);
            nb_texto_izquierda($img, $fSemi, $size, $cTxt, $label, $padX + $dotR * 2 + $dotGap, $yBase);
        }
        return $yTop + 14 + (count($features) - 1) * $rowGap + 20;
    }
}

if (!function_exists('nb_dibujar_boton_agendar')) {
    function nb_dibujar_boton_agendar($img, string $fBold, int $x, int $yTop, int $cAcento, int $cBlanco): int {
        $txt = 'Agendar clase';
        $size = 26;
        $w = nb_ancho_texto($fBold, $size, $txt);
        $padX = 40; $padY = 18;
        $bw = $w + $padX * 2;
        $bh = (int)($size * 1.15) + $padY * 2;
        nb_rect_redondeado($img, $x, $yTop, $x + $bw, $yTop + $bh, (int)($bh / 2), $cAcento);
        imagettftext($img, $size, 0, $x + $padX, $yTop + $bh - $padY - (int)($size * 0.22), $cBlanco, $fBold, $txt);
        return $bh;
    }
}

/* ---------- Generador POST 1080x1080 ---------- */

if (!function_exists('nb_generar_imagen_post')) {
    function nb_generar_imagen_post(array $s, string $output_path): bool {
        // 4:5 — formato recomendado por Instagram para feed (evita el recorte lateral que
        // aplica la cuadrícula de perfil 3:4 desde ene/2026 a posts 1:1).
        $W = 1080; $H = 1350;
        $fReg  = nb_fonts_dir() . 'Inter-Regular.ttf';
        $fSemi = nb_fonts_dir() . 'Inter-SemiBold.ttf';
        $fBold = nb_fonts_dir() . 'Inter-Bold.ttf';
        foreach ([$fReg, $fSemi, $fBold] as $f) if (!is_file($f)) return false;

        $img = imagecreatetruecolor($W, $H);
        imageantialias($img, true); // suaviza círculos/elipses: anillo del avatar, esquinas de píldoras, puntos decorativos
        $pal = nb_paleta_marca($img);
        $cBg = $pal['bg']; $cAcento = $pal['acento']; $cTxt = $pal['txt']; $cTxt2 = $pal['txt2']; $cBlanco = $pal['blanco'];
        // #10B981 (emerald-500) — mismo verde que usa detalle_servicio.php para "Disponible",
        // NO el #16A34A (green-600) del badge OFERTA (son conceptos y colores distintos).
        $cVerdeDisp = imagecolorallocate($img, 16, 185, 129);
        imagefilledrectangle($img, 0, 0, $W, $H, $cBg);

        /* ===== PARTE 1: avatar grande + nombre + institución (badge Disponible ya NO va
           acá — se movió a PARTE 2, debajo de la línea de rating, cambio pedido
           explícitamente por el usuario 30/08/2026) ===== */
        // Margen de seguridad lateral: colchón visual para compartir en redes,
        // independiente de si la plataforma finalmente recorta o no la imagen.
        $M = 110;
        $diamAv = 400; $avTop = 150; $avLeft = $M;
        $avCx = $avLeft + (int)($diamAv / 2);
        nb_dibujar_avatar($img, $s, $avCx, $avTop, $diamAv, $cAcento, $cBlanco, $fBold);
        $avBottom = $avTop + $diamAv;

        $colX = $avLeft + $diamAv + 40;
        $colMaxW = ($W - $M) - $colX;

        $nombre = nb_truncar_una_linea($fBold, 40, nombre_publico_tutor((string)($s['nombre_alumno'] ?? $s['nombre'] ?? '')), $colMaxW);
        $yNombre = $avTop + 90; // misma relación con avTop que en H=1080, para no desalinear con el avatar más grande
        nb_texto_izquierda($img, $fBold, 40, $cAcento, $nombre, $colX, $yNombre);

        $instRaw = trim((string)($s['institucion_maestra'] ?? $s['institucion'] ?? ''));
        $inst = $instRaw !== '' ? mb_strtoupper(html_entity_decode(abreviar_institucion($instRaw, 22)), 'UTF-8') : 'TUTOR PARTICULAR';
        $inst = nb_truncar_una_linea($fReg, 24, $inst, $colMaxW);
        $yInst = $yNombre + 45;
        nb_texto_izquierda($img, $fReg, 24, $cTxt2, $inst, $colX, $yInst);

        /* ===== PARTE 2: badge categoría + línea de rating + badge Disponible — los 3
           apilados en la misma columna ($colX). "Disponible" vivía antes al lado del
           nombre (PARTE 1); bajó acá debajo de "★ Nuevo"/rating, cambio pedido
           explícitamente por el usuario 30/08/2026. ===== */
        $cat = mb_strtoupper(trim((string)($s['categoria'] ?? '')), 'UTF-8');
        $yCatBadge = $yInst + 30;
        [$bwCat, $bhCat] = nb_dibujar_badge_categoria($img, $fSemi, $cat, $colX, $yCatBadge, $cAcento, $cBlanco, $cAcento);

        $prom  = (float)($s['rating_prom'] ?? 0);
        $votos = (int)($s['rating_votos'] ?? 0);
        $yRating = $yCatBadge + $bhCat + 34;
        nb_dibujar_cat_rating_izquierda($img, '', $fBold, $fSemi, 26, $prom, $votos, $colX, $yRating, $cAcento, $cAcento);

        $yDisponibleTop = $yRating + 24;
        [$bwDisp, $bhDisp] = nb_dibujar_badge_pill($img, $fSemi, 20, 'Disponible', $colX, $yDisponibleTop, $cVerdeDisp, $cBlanco);
        $yDisponibleBottom = $yDisponibleTop + $bhDisp;

        /* ===== PARTE 3: título genérico (categoría en acento) — sin bio (privacidad: ver nota) ===== */
        $y = max($avBottom, $yDisponibleBottom + 20) + 110;

        $categoriaTxt = trim((string)($s['categoria'] ?? ''));
        $tituloGenerico = 'Clases particulares de ' . $categoriaTxt;
        $lineasTit = nb_wrap_texto($fSemi, 34, $tituloGenerico, $W - ($M * 2), 1);
        $lineaTit = $lineasTit[0] ?? '';
        if ($categoriaTxt !== '' && mb_substr($lineaTit, -mb_strlen($categoriaTxt)) === $categoriaTxt) {
            $prefijo = mb_substr($lineaTit, 0, mb_strlen($lineaTit) - mb_strlen($categoriaTxt));
            $wPref = nb_ancho_texto($fSemi, 34, $prefijo);
            $wCat  = nb_ancho_texto($fSemi, 34, $categoriaTxt);
            $xTit = (int)(($W - $wPref - $wCat) / 2);
            imagettftext($img, 34, 0, $xTit, $y, $cTxt, $fSemi, $prefijo);
            imagettftext($img, 34, 0, $xTit + $wPref, $y, $cAcento, $fSemi, $categoriaTxt);
        } else {
            nb_texto_centrado($img, $fSemi, 34, $cTxt, $lineaTit, $W, $y);
        }
        $y += 46 + 40;

        // [NUBIRA 2.0] Bio removida de esta card (30/07/2026): el texto libre puede contener
        // el apellido completo del tutor (ej. "Soy Karen Almonacid..."), lo que anula la
        // protección de privacidad que ya aplica nombre_publico_tutor() más arriba ("Karen A.").
        // La bio SIGUE mostrándose normal en perfil.php — este cambio es solo para la imagen
        // pública compartible.
        // Gap reducido (100 -> 35, 30/08/2026): con las features ahora en 1 columna más alta
        // (4 filas en vez de 2), había que subirlas para no empujar el precio/botón fuera
        // del canvas — cambio pedido explícitamente por el usuario, confirmado en prototipo.
        $y += 35;

        /* ===== PARTE 4: features en 1 columna + precio (negro) + botón (izquierda) + marca (misma fila) ===== */
        $yFeaturesFin = nb_dibujar_features_fijas($img, $fSemi, $W, $y, $cTxt, $cAcento);

        $ofertaVigenteCard = !empty($s['is_subvencionado']) && (int)$s['is_subvencionado'] === 1
            && (empty($s['oferta_termino']) || $s['oferta_termino'] >= date('Y-m-d'))
            && (float)($s['precio_oferta'] ?? 0) > 0;
        $y = $yFeaturesFin + 80;
        if ($ofertaVigenteCard) {
            $y += 65; // espacio extra para que el badge OFERTA (dibujado sobre el precio) no choque con las features
        }

        nb_dibujar_precio_centrado($img, $s, $fBold, $fSemi, $fReg, 48, 32, $W, $y, $cTxt, $cTxt2);
        $y += 95;

        $bhBoton = nb_dibujar_boton_agendar($img, $fBold, $M, $y, $cAcento, $cBlanco);

        // Marca en la misma fila que el botón (izquierda), centrada verticalmente respecto
        // a su altura (65px) — antes iba apilada debajo y se sentían "encimados".
        nb_texto_derecha($img, $fBold, 28, $cAcento, 'Nubira.cl', $W - $M, $y + 42);
        $y += $bhBoton;

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
              . '|' . ($s['bio'] ?? '');
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
                          AND v.rol_evaluado = 'vendedor') AS rating_votos
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
                          AND v.rol_evaluado = 'vendedor') AS rating_votos
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
