<?php
// Generador de imágenes compartibles para APUNTES (POST 4:5 + HISTORY 9:16).
// Mismo motor GD server-side y mismos helpers de bajo nivel que imagen_compartir.php
// (servicios) — layout propio porque el apunte tiene portada real (protagonista) y
// no tiene sistema de rating.
require_once __DIR__ . '/imagen_compartir.php';
require_once __DIR__ . '/nombre_publico.php';
require_once __DIR__ . '/foto_tutor.php';
require_once __DIR__ . '/institucion.php';

// Versión separada de NB_IMG_VERSION (servicios): un rediseño de esta card no debe
// invalidar el cache de servicios y viceversa — son plantillas independientes que
// solo comparten helpers de bajo nivel, no layout.
if (!defined('NB_IMG_VERSION_APUNTE')) define('NB_IMG_VERSION_APUNTE', 'v1');

if (!function_exists('nb_ruta_portada_apunte')) {
    // Filesystem path (para GD/file_get_contents). Mismo fallback de 3 niveles que
    // miniatura_apunte() (ver_apunte.php) pero SIN la query string de cache-busting:
    // esa función arma URLs para <img src> en HTML, esto necesita una ruta real en disco.
    function nb_ruta_portada_apunte(array $a): string {
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2);

        if (!empty($a['portada'])) {
            $p = $docRoot . '/upload/portadas/' . basename($a['portada']);
            if (is_file($p)) return $p;
        }
        $pWebp = $docRoot . '/upload/preview/' . (int)($a['id'] ?? 0) . '.webp';
        if (is_file($pWebp)) return $pWebp;

        if (!empty($a['archivo'])) {
            $ext = strtolower(pathinfo((string)$a['archivo'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $pOrig = $docRoot . '/upload/apuntes/' . basename((string)$a['archivo']);
                if (is_file($pOrig)) return $pOrig;
            }
        }
        return '';
    }
}

if (!function_exists('nb_punto_en_rect_redondeado')) {
    // Prueba analítica punto-dentro-de-rectángulo-con-esquinas-redondeadas (mismo
    // principio que la máscara circular pixel a pixel de nb_recorte_circular, pero
    // para un rectángulo: solo las 4 esquinas necesitan la prueba circular).
    function nb_punto_en_rect_redondeado(int $px, int $py, int $w, int $h, int $r): bool {
        $enEsquinaX = ($px < $r || $px > $w - $r);
        $enEsquinaY = ($py < $r || $py > $h - $r);
        if ($enEsquinaX && $enEsquinaY) {
            $cx = $px < $r ? $r : $w - $r;
            $cy = $py < $r ? $r : $h - $r;
            return (($px - $cx) ** 2 + ($py - $cy) ** 2) <= $r * $r;
        }
        return true;
    }
}

if (!function_exists('nb_dibujar_portada_rect')) {
    // Portada del apunte: rectángulo con esquinas redondeadas, recorte "cover" (llena
    // sin deformar), + gradiente oscuro en el tercio inferior para legibilidad de
    // cualquier texto que se superponga. Si no hay portada real, deja un placeholder
    // sólido de marca (nunca un rectángulo roto/vacío).
    function nb_dibujar_portada_rect($img, array $a, int $x, int $y, int $w, int $h, int $r): void {
        $tmp = imagecreatetruecolor($w, $h);

        $ruta = nb_ruta_portada_apunte($a);
        $raw = $ruta !== '' ? @file_get_contents($ruta) : false;
        $src = $raw !== false ? @imagecreatefromstring($raw) : false;

        if ($src) {
            $sw = imagesx($src);
            $sh = imagesy($src);
            $escala = max($w / $sw, $h / $sh);
            $nw = max(1, (int)round($sw * $escala));
            $nh = max(1, (int)round($sh * $escala));
            $ox = (int)(($nw - $w) / 2);
            $oy = (int)(($nh - $h) / 2);
            $full = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($full, $src, 0, 0, 0, 0, $nw, $nh, $sw, $sh);
            imagecopy($tmp, $full, 0, 0, $ox, $oy, $w, $h);
            imagedestroy($full);
            imagedestroy($src);
        } else {
            $cPlaceholder = imagecolorallocate($tmp, 224, 238, 247); // azul muy claro de marca
            imagefilledrectangle($tmp, 0, 0, $w, $h, $cPlaceholder);
        }

        // Gradiente oscuro en el tercio inferior
        $alturaGrad = (int)($h / 3);
        for ($gy = 0; $gy < $alturaGrad; $gy++) {
            $factor = $gy / max(1, $alturaGrad); // 0 arriba del gradiente -> 1 abajo (más oscuro)
            $filaY = $h - $alturaGrad + $gy;
            for ($gx = 0; $gx < $w; $gx++) {
                $rgb = imagecolorat($tmp, $gx, $filaY);
                $rC = (int)((($rgb >> 16) & 0xFF) * (1 - $factor * 0.55));
                $gC = (int)((($rgb >> 8) & 0xFF) * (1 - $factor * 0.55));
                $bC = (int)(($rgb & 0xFF) * (1 - $factor * 0.55));
                imagesetpixel($tmp, $gx, $filaY, imagecolorallocate($tmp, $rC, $gC, $bC));
            }
        }

        // Recorte redondeado pixel a pixel (mismo patrón que nb_recorte_circular)
        $dst = imagecreatetruecolor($w, $h);
        imagesavealpha($dst, true);
        imagealphablending($dst, false);
        $transp = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $w, $h, $transp);
        for ($py = 0; $py < $h; $py++) {
            for ($px = 0; $px < $w; $px++) {
                if (nb_punto_en_rect_redondeado($px, $py, $w, $h, $r)) {
                    imagesetpixel($dst, $px, $py, imagecolorat($tmp, $px, $py));
                }
            }
        }
        imagedestroy($tmp);

        imagecopy($img, $dst, $x, $y, 0, 0, $w, $h);
        imagedestroy($dst);
    }
}

if (!function_exists('nb_dibujar_boton_generico')) {
    // Igual a nb_dibujar_boton_agendar pero con label parametrizable (esa función
    // trae "Agendar clase" hardcodeado — no aplica al contexto de descarga de apunte).
    function nb_dibujar_boton_generico($img, string $fBold, string $txt, int $x, int $yTop, int $cAcento, int $cBlanco): int {
        $size = 26;
        $w = nb_ancho_texto($fBold, $size, $txt);
        $padX = 40;
        $padY = 18;
        $bw = $w + $padX * 2;
        $bh = (int)($size * 1.15) + $padY * 2;
        nb_rect_redondeado($img, $x, $yTop, $x + $bw, $yTop + $bh, (int)($bh / 2), $cAcento);
        imagettftext($img, $size, 0, $x + $padX, $yTop + $bh - $padY - (int)($size * 0.22), $cBlanco, $fBold, $txt);
        return $bh;
    }
}

if (!function_exists('nb_precio_apunte')) {
    // Lógica de precio de apunte: promo_gratis/promo_limite/promo_contador (cupo
    // LIMITADO POR CANTIDAD de descargas gratis) — distinto del precio_oferta/
    // is_subvencionado/oferta_termino de servicios (descuento limitado por FECHA).
    // Mismo criterio real usado en cargar_apuntes.php/vitrina.php:
    // $es_promo_activa = ($promo_gratis === 1 && $promo_contador < $promo_limite).
    function nb_precio_apunte($img, array $a, string $fBold, string $fReg, float $szBig, float $szTachado, int $W, int $yBase, int $cTxt, int $cTxt2): void {
        $precio = (float)($a['precio'] ?? 0);
        $promoGratis = (int)($a['promo_gratis'] ?? 0);
        $promoLimite = (int)($a['promo_limite'] ?? 0);
        $promoContador = (int)($a['promo_contador'] ?? 0);
        $promoActiva = ($promoGratis === 1 && $promoContador < $promoLimite);

        if ($promoActiva && $precio > 0) {
            $txtGratis = '¡Gratis!';
            $wGratis = nb_ancho_texto($fBold, $szBig, $txtGratis);
            $origText = '$' . number_format($precio, 0, ',', '.');
            $wOrig = nb_ancho_texto($fReg, $szTachado, $origText);
            $wTotal = $wGratis + 24 + $wOrig;
            $xInicio = (int)(($W - $wTotal) / 2);

            imagettftext($img, $szBig, 0, $xInicio, $yBase, $cTxt, $fBold, $txtGratis);
            $xOrig = $xInicio + $wGratis + 24;
            $yOrig = $yBase - 8;
            imagettftext($img, $szTachado, 0, $xOrig, $yOrig, $cTxt2, $fReg, $origText);
            imagesetthickness($img, 3);
            imageline($img, $xOrig, $yOrig - 9, $xOrig + $wOrig, $yOrig - 9, $cTxt2);
            imagesetthickness($img, 1);
        } elseif ($precio > 0) {
            $txt = '$' . number_format($precio, 0, ',', '.') . ' CLP';
            nb_texto_centrado($img, $fBold, $szBig, $cTxt, $txt, $W, $yBase);
        } else {
            nb_texto_centrado($img, $fBold, $szBig, $cTxt, 'Gratis', $W, $yBase);
        }
    }
}

if (!function_exists('nb_precio_apunte_izquierda')) {
    // Variante alineada a la izquierda de nb_precio_apunte, para HISTORY (columna
    // izquierda dentro de la card "sticker"), mismo criterio de promo que la centrada.
    function nb_precio_apunte_izquierda($img, array $a, string $fBold, string $fReg, float $szBig, float $szTachado, int $x, int $yBase, int $cTxt, int $cTxt2): void {
        $precio = (float)($a['precio'] ?? 0);
        $promoGratis = (int)($a['promo_gratis'] ?? 0);
        $promoLimite = (int)($a['promo_limite'] ?? 0);
        $promoContador = (int)($a['promo_contador'] ?? 0);
        $promoActiva = ($promoGratis === 1 && $promoContador < $promoLimite);

        if ($promoActiva && $precio > 0) {
            $txtGratis = '¡Gratis!';
            imagettftext($img, $szBig, 0, $x, $yBase, $cTxt, $fBold, $txtGratis);
            $wGratis = nb_ancho_texto($fBold, $szBig, $txtGratis);

            $origText = '$' . number_format($precio, 0, ',', '.');
            $xOrig = $x + $wGratis + 24;
            $yOrig = $yBase - 8;
            imagettftext($img, $szTachado, 0, $xOrig, $yOrig, $cTxt2, $fReg, $origText);
            $wOrig = nb_ancho_texto($fReg, $szTachado, $origText);
            imagesetthickness($img, 3);
            imageline($img, $xOrig, $yOrig - 9, $xOrig + $wOrig, $yOrig - 9, $cTxt2);
            imagesetthickness($img, 1);
        } elseif ($precio > 0) {
            $txt = '$' . number_format($precio, 0, ',', '.') . ' CLP';
            nb_texto_izquierda($img, $fBold, $szBig, $cTxt, $txt, $x, $yBase);
        } else {
            nb_texto_izquierda($img, $fBold, $szBig, $cTxt, 'Gratis', $x, $yBase);
        }
    }
}

if (!function_exists('nb_generar_imagen_apunte_post')) {
    function nb_generar_imagen_apunte_post(array $a, string $output_path): bool {
        $W = 1080;
        $H = 1350; // mismo formato 4:5 que servicio
        $fReg  = nb_fonts_dir() . 'Inter-Regular.ttf';
        $fSemi = nb_fonts_dir() . 'Inter-SemiBold.ttf';
        $fBold = nb_fonts_dir() . 'Inter-Bold.ttf';
        foreach ([$fReg, $fSemi, $fBold] as $f) if (!is_file($f)) return false;

        $img = imagecreatetruecolor($W, $H);
        imageantialias($img, true);
        $pal = nb_paleta_marca($img);
        $cBg = $pal['bg']; $cAcento = $pal['acento']; $cTxt = $pal['txt']; $cTxt2 = $pal['txt2']; $cBlanco = $pal['blanco'];
        imagefilledrectangle($img, 0, 0, $W, $H, $cBg);

        $M = 110;

        /* ===== PARTE 1: portada protagonista ===== */
        $portW = $W - ($M * 2);
        $portH = 620;
        nb_dibujar_portada_rect($img, $a, $M, 90, $portW, $portH, 32);
        $y = 90 + $portH + 55;

        /* ===== PARTE 2: título real del apunte (hasta 2 líneas) ===== */
        $lineasTit = nb_wrap_texto($fSemi, 40, (string)($a['titulo'] ?? ''), $W - ($M * 2), 2);
        foreach ($lineasTit as $linea) {
            nb_texto_centrado($img, $fSemi, 40, $cTxt, $linea, $W, $y);
            $y += 52;
        }
        $y += 26;

        /* ===== PARTE 3: materia · universidad (reemplaza modalidad online/presencial) ===== */
        $asignatura = trim((string)($a['asignatura'] ?? ''));
        $institucion = trim((string)($a['institucion_maestra'] ?? $a['institucion'] ?? ''));
        $instAbrev = $institucion !== '' ? html_entity_decode(abreviar_institucion($institucion, 22)) : '';
        $partesLinea = array_values(array_filter([$asignatura, $instAbrev]));
        $lineaMateria = mb_strtoupper($partesLinea !== [] ? implode(' · ', $partesLinea) : 'NUBIRA', 'UTF-8');
        $lineaMateria = nb_truncar_una_linea($fReg, 24, $lineaMateria, $W - ($M * 2));
        nb_texto_centrado($img, $fReg, 24, $cTxt2, $lineaMateria, $W, $y);
        $y += 66;

        /* ===== PARTE 4: autor — foto + nombre, secundario (no protagonista) ===== */
        $diamAv = 72;
        $avCx = $M + (int)($diamAv / 2);
        nb_dibujar_avatar($img, $a, $avCx, $y, $diamAv, $cAcento, $cBlanco, $fBold);
        $nombre = nb_truncar_una_linea($fSemi, 26, nombre_publico_tutor((string)($a['nombre_alumno'] ?? $a['nombre'] ?? '')), $W - ($M * 2) - $diamAv - 20);
        nb_texto_izquierda($img, $fSemi, 26, $cTxt, $nombre, $M + $diamAv + 20, $y + (int)($diamAv / 2) + 9);
        // +95 (antes +55) — con precio a tamaño 48 el espacio visual real quedaba en
        // ~21px entre el avatar y el precio, muy pegado. Ahora ~60px, en línea con los
        // gaps de 55-95px que usa servicio entre sus propias líneas.
        $y += $diamAv + 95;

        /* ===== PARTE 5: precio + botón + marca (misma fila, mismo patrón que servicio) ===== */
        nb_precio_apunte($img, $a, $fBold, $fReg, 48, 32, $W, $y, $cTxt, $cTxt2);
        $y += 95;

        $bhBoton = nb_dibujar_boton_generico($img, $fBold, 'Ver apunte', $M, $y, $cAcento, $cBlanco);
        nb_texto_derecha($img, $fBold, 28, $cAcento, 'Nubira.cl', $W - $M, $y + 42);
        $y += $bhBoton;

        $ok = imagejpeg($img, $output_path, 90);
        imagedestroy($img);
        return (bool)$ok;
    }
}

if (!function_exists('nb_generar_imagen_apunte_history')) {
    function nb_generar_imagen_apunte_history(array $a, string $output_path): bool {
        $W = 1080;
        $H = 1920;
        $fReg  = nb_fonts_dir() . 'Inter-Regular.ttf';
        $fSemi = nb_fonts_dir() . 'Inter-SemiBold.ttf';
        $fBold = nb_fonts_dir() . 'Inter-Bold.ttf';
        foreach ([$fReg, $fSemi, $fBold] as $f) if (!is_file($f)) return false;

        $img = imagecreatetruecolor($W, $H);
        $pal = nb_paleta_marca($img);
        $cBg = $pal['bg']; $cAzul = $pal['acento']; $cBlanco = $pal['blanco']; $cTxt = $pal['txt']; $cTxt2 = $pal['txt2'];
        imagefilledrectangle($img, 0, 0, $W, $H, $cBg);

        // Card "sticker" centrada (mismo patrón que nb_generar_imagen_history de servicio)
        $cardX1 = 90; $cardX2 = 990; $cardY1 = 300; $cardY2 = 1620; $cardR = 40;
        $cBorde = imagecolorallocate($img, 229, 231, 235);
        nb_rect_redondeado($img, $cardX1, $cardY1, $cardX2, $cardY2, $cardR, $cBorde);
        nb_rect_redondeado($img, $cardX1 + 2, $cardY1 + 2, $cardX2 - 2, $cardY2 - 2, $cardR - 2, $cBlanco);

        $inX1 = 130; $inX2 = 950; $inY1 = 340; $inY2 = 1460; $inR = 30;
        nb_rect_redondeado($img, $inX1, $inY1, $inX2, $inY2, $inR, $cBorde);
        nb_rect_redondeado($img, $inX1 + 2, $inY1 + 2, $inX2 - 2, $inY2 - 2, $inR - 2, $cBlanco);

        $padL = $inX1 + 30;
        $padR = $inX2 - 30;
        $maxTxt = $padR - $padL;
        $portW = $padR - $padL;
        $portH = 640;

        /* Portada protagonista, arriba dentro del marco interno */
        nb_dibujar_portada_rect($img, $a, $padL, $inY1 + 30, $portW, $portH, 24);
        $y = $inY1 + 30 + $portH + 50;

        /* Título (1 línea) */
        $titulo = nb_truncar_una_linea($fSemi, 38, (string)($a['titulo'] ?? ''), $maxTxt);
        nb_texto_izquierda($img, $fSemi, 38, $cTxt, $titulo, $padL, $y);
        $y += 48;

        /* Materia · universidad */
        $asignatura = trim((string)($a['asignatura'] ?? ''));
        $institucion = trim((string)($a['institucion_maestra'] ?? $a['institucion'] ?? ''));
        $instAbrev = $institucion !== '' ? html_entity_decode(abreviar_institucion($institucion, 22)) : '';
        $partesLinea = array_values(array_filter([$asignatura, $instAbrev]));
        $lineaMateria = mb_strtoupper($partesLinea !== [] ? implode(' · ', $partesLinea) : 'NUBIRA', 'UTF-8');
        $lineaMateria = nb_truncar_una_linea($fReg, 26, $lineaMateria, $maxTxt);
        nb_texto_izquierda($img, $fReg, 26, $cTxt2, $lineaMateria, $padL, $y);
        $y += 60;

        /* Autor: foto + nombre, secundario */
        $diamAv = 64;
        nb_dibujar_avatar($img, $a, $padL + (int)($diamAv / 2), $y, $diamAv, $cAzul, $cBlanco, $fBold);
        $nombre = nb_truncar_una_linea($fSemi, 26, nombre_publico_tutor((string)($a['nombre_alumno'] ?? $a['nombre'] ?? '')), $maxTxt - $diamAv - 20);
        nb_texto_izquierda($img, $fSemi, 26, $cTxt, $nombre, $padL + $diamAv + 20, $y + (int)($diamAv / 2) + 9);
        // +90 (antes +50) — mismo ajuste que en POST, el precio a tamaño 44 quedaba a
        // ~19px del avatar, muy pegado. Ahora ~60px de aire real.
        $y += $diamAv + 90;

        /* Precio (alineado a la izquierda, columna del marco interno) */
        nb_precio_apunte_izquierda($img, $a, $fBold, $fReg, 44, 28, $padL, $y, $cTxt, $cTxt2);

        nb_texto_izquierda($img, $fBold, 28, $cAzul, 'Nubira.cl', $cardX1 + 50, $cardY2 - 60);

        $ok = imagejpeg($img, $output_path, 90);
        imagedestroy($img);
        return (bool)$ok;
    }
}

if (!function_exists('nb_fingerprint_apunte')) {
    function nb_fingerprint_apunte(array $a): string {
        $base = NB_IMG_VERSION_APUNTE . '|' . ($a['id'] ?? '') . '|' . ($a['titulo'] ?? '') . '|' . ($a['precio'] ?? '')
              . '|' . ($a['portada'] ?? '') . '|' . ($a['categoria'] ?? '') . '|' . ($a['foto_perfil'] ?? '')
              . '|' . ($a['promo_gratis'] ?? '') . '|' . ($a['promo_contador'] ?? '');
        return substr(md5($base), 0, 10);
    }
}

if (!function_exists('nb_version_imagen_apunte')) {
    function nb_version_imagen_apunte(int $apunte_id): string {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli) || $apunte_id <= 0) return '0';

        $sql = "SELECT ap.id, ap.titulo, ap.precio, ap.portada, ap.categoria, ap.promo_gratis, ap.promo_contador,
                       a.foto_perfil
                FROM apuntes ap
                JOIN alumnos a ON a.id = ap.id_alumno
                WHERE ap.id = ? LIMIT 1";
        $st = $conn->prepare($sql);
        if (!$st) return '0';
        $st->bind_param('i', $apunte_id);
        $st->execute();
        $a = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$a) return '0';

        return nb_fingerprint_apunte($a);
    }
}

if (!function_exists('nb_obtener_imagen_apunte')) {
    // Devuelve la RUTA FÍSICA del JPG (cache hit o recién generado), o '' si falla.
    function nb_obtener_imagen_apunte(int $apunte_id, string $formato): string {
        global $conn;
        $formato = ($formato === 'history') ? 'history' : 'post';
        if (!isset($conn) || !($conn instanceof mysqli) || $apunte_id <= 0) return '';

        $sql = "SELECT ap.*, a.nombre AS nombre_alumno, a.foto_perfil,
                       COALESCE(dp.institucion, NULLIF(ap.institucion,''), a.institucion) AS institucion_maestra
                FROM apuntes ap
                JOIN alumnos a ON a.id = ap.id_alumno
                LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
                WHERE ap.id = ? LIMIT 1";
        $st = $conn->prepare($sql);
        if (!$st) return '';
        $st->bind_param('i', $apunte_id);
        $st->execute();
        $a = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$a) return '';

        require_once __DIR__ . '/../seguridad_url.php';
        $hash = function_exists('nubira_encriptar_id') ? nubira_encriptar_id($apunte_id) : (string)$apunte_id;
        $fp   = nb_fingerprint_apunte($a);

        $dir = nb_compartir_dir();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . 'ap_' . $hash . '_' . $formato . '_' . $fp . '.jpg';

        if (is_file($file)) return $file; // cache hit

        $ok = ($formato === 'history')
            ? nb_generar_imagen_apunte_history($a, $file)
            : nb_generar_imagen_apunte_post($a, $file);

        return $ok && is_file($file) ? $file : '';
    }
}
