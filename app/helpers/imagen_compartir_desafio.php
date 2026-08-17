<?php
// Generador de imagen compartible para "Desafío de hoy" (solo POST 4:5 por ahora).
// Mismo motor GD y mismos helpers de bajo nivel que imagen_compartir.php (servicios/
// apuntes) — layout propio porque acá no hay un "producto" (sin portada, sin precio,
// sin avatar): es una invitación a jugar, no una card de venta.
require_once __DIR__ . '/imagen_compartir.php';

if (!defined('NB_IMG_VERSION_DESAFIO')) define('NB_IMG_VERSION_DESAFIO', 'v1');

if (!function_exists('nb_dibujar_boton_generico_desafio')) {
    // Copia local de nb_dibujar_boton_generico (imagen_compartir_apunte.php) — no la
    // reutilizo directo para no forzar un require cruzado entre los dos helpers de
    // "apunte" y "desafio" (ambos ya dependen de imagen_compartir.php, no uno del otro).
    function nb_dibujar_boton_generico_desafio($img, string $fBold, string $txt, int $W, int $yTop, int $cAcento, int $cBlanco): int {
        $size = 30;
        $w = nb_ancho_texto($fBold, $size, $txt);
        $padX = 48;
        $padY = 22;
        $bw = $w + $padX * 2;
        $bh = (int)($size * 1.15) + $padY * 2;
        $x = (int)(($W - $bw) / 2);
        nb_rect_redondeado($img, $x, $yTop, $x + $bw, $yTop + $bh, (int)($bh / 2), $cAcento);
        imagettftext($img, $size, 0, $x + $padX, $yTop + $bh - $padY - (int)($size * 0.22), $cBlanco, $fBold, $txt);
        return $bh;
    }
}

if (!function_exists('nb_datos_materia_desafio')) {
    // Única fuente de datos: la fila de `materias` (slug + nombre). No hay nada más
    // dinámico que mostrar (sin precio, sin conteos) — por eso una sola función sirve
    // tanto para generar la imagen como para el chequeo liviano de versión (?v=).
    function nb_datos_materia_desafio(string $materia_slug): ?array {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli) || $materia_slug === '') return null;

        $st = $conn->prepare("SELECT slug, nombre FROM materias WHERE slug = ? AND activa = 1 LIMIT 1");
        if (!$st) return null;
        $st->bind_param('s', $materia_slug);
        $st->execute();
        $m = $st->get_result()->fetch_assoc();
        $st->close();
        return $m ?: null;
    }
}

if (!function_exists('nb_fingerprint_desafio')) {
    function nb_fingerprint_desafio(array $m): string {
        $base = NB_IMG_VERSION_DESAFIO . '|' . ($m['slug'] ?? '') . '|' . ($m['nombre'] ?? '');
        return substr(md5($base), 0, 10);
    }
}

if (!function_exists('nb_version_imagen_desafio')) {
    function nb_version_imagen_desafio(string $materia_slug): string {
        $m = nb_datos_materia_desafio($materia_slug);
        return $m ? nb_fingerprint_desafio($m) : '0';
    }
}

if (!function_exists('nb_generar_imagen_desafio_post')) {
    function nb_generar_imagen_desafio_post(array $m, string $output_path): bool {
        $W = 1080;
        $H = 1350; // mismo formato 4:5 que apunte/servicio
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
        $nombreMateria = mb_strtoupper((string)($m['nombre'] ?? ''), 'UTF-8');
        $tituloTxt = "¿Te atreves con el Desafío de " . ($m['nombre'] ?? '') . "?";

        // Sin portada/precio, el contenido es corto y su altura varía según el largo
        // del nombre de la materia (1 a 3 líneas de título) — en vez de posiciones fijas
        // (que dejaban un hueco vacío grande con títulos cortos), se mide todo primero y
        // se centra el bloque completo verticalmente en el canvas.
        $lineasTit = nb_wrap_texto($fBold, 56, $tituloTxt, $W - ($M * 2), 3);
        $bhCat = (int)(24 * 1.15) + 9 * 2;
        $gapBadgeTitulo = 50;
        $altoLineaTitulo = 72;
        $gapTituloSub = 36;
        $altoSub = 44;
        $gapSubBoton = 70;
        $bhBoton = (int)(30 * 1.15) + 22 * 2;
        $gapBotonMarca = 45;
        $altoMarca = 40;

        $altoTotal = $bhCat + $gapBadgeTitulo + (count($lineasTit) * $altoLineaTitulo)
                   + $gapTituloSub + $altoSub + $gapSubBoton + $bhBoton + $gapBotonMarca + $altoMarca;
        $y = (int)(($H - $altoTotal) / 2);

        /* ===== Badge de materia, centrado (mismo estilo que categoría de apunte/servicio) ===== */
        $bwCat = nb_ancho_texto($fSemi, 24, $nombreMateria) + 16 * 2 + 6 * 2 + 14;
        $xCat = (int)(($W - $bwCat) / 2);
        nb_dibujar_badge_categoria($img, $fSemi, $nombreMateria, $xCat, $y, $cAcento, $cBlanco, $cAcento);
        $y += $bhCat + $gapBadgeTitulo;

        /* ===== Título: pregunta-invitación, centrada (nombre en su case natural acá —
           el mayúsculas queda reservado al badge, arriba) ===== */
        foreach ($lineasTit as $linea) {
            nb_texto_centrado($img, $fBold, 56, $cTxt, $linea, $W, $y);
            $y += $altoLineaTitulo;
        }
        $y += $gapTituloSub;

        /* ===== Subtítulo ===== */
        nb_texto_centrado($img, $fReg, 28, $cTxt2, '3 preguntas rápidas. ¿Cuánto sabes de verdad?', $W, $y);
        $y += $altoSub + $gapSubBoton;

        /* ===== CTA + marca (mismo patrón que apunte: botón centrado + Nubira.cl) ===== */
        nb_dibujar_boton_generico_desafio($img, $fBold, 'Jugar ahora', $W, $y, $cAcento, $cBlanco);
        $y += $bhBoton + $gapBotonMarca;
        nb_texto_centrado($img, $fBold, 28, $cAcento, 'nubira.cl/desafio', $W, $y);

        $ok = imagejpeg($img, $output_path, 90);
        imagedestroy($img);
        return (bool)$ok;
    }
}

if (!function_exists('nb_obtener_imagen_desafio')) {
    // Devuelve la RUTA FÍSICA del JPG (cache hit o recién generado), o '' si falla.
    function nb_obtener_imagen_desafio(string $materia_slug): string {
        $m = nb_datos_materia_desafio($materia_slug);
        if (!$m) return '';

        $fp = nb_fingerprint_desafio($m);
        $dir = nb_compartir_dir();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . 'desafio_' . $m['slug'] . '_post_' . $fp . '.jpg';

        if (is_file($file)) return $file; // cache hit

        $ok = nb_generar_imagen_desafio_post($m, $file);
        return $ok && is_file($file) ? $file : '';
    }
}
