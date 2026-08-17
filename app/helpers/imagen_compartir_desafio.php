<?php
// Generador de imagen compartible para "Desafío de hoy" (solo POST 4:5 por ahora).
// Mismo motor GD y mismos helpers de bajo nivel que imagen_compartir.php (servicios/
// apuntes) — layout propio porque acá no hay un "producto" (sin portada, sin precio,
// sin avatar): es una invitación a jugar, no una card de venta.
require_once __DIR__ . '/imagen_compartir.php';

if (!defined('NB_IMG_VERSION_DESAFIO')) define('NB_IMG_VERSION_DESAFIO', 'v7'); // v7: título incluye materia ("· {materia}"), quita el badge redundante

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
        $tituloTxt = "Desafío Nubira de hoy: " . ($m['nombre'] ?? '');

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

/* ============================================================
   Compartir las 3 preguntas de UNA sesión concreta (no la invitación
   genérica de materia) — HISTORY 9:16 únicamente. El usuario ya vio las 3
   preguntas en pantalla antes de responder; comparte exactamente esas,
   sin revelar cuál opción es la correcta (respuesta_correcta NUNCA se
   selecciona en esta query, a diferencia de responder_desafio.php).
   ============================================================ */

if (!function_exists('nb_datos_preguntas_desafio')) {
    // Valida y trae las 3 preguntas por ID. Exige: exactamente 3 IDs positivos
    // distintos, las 3 filas existen/activas/revisadas, y las 3 comparten la
    // MISMA materia (evita mezclar preguntas de materias distintas en una sola
    // card — no tendría badge de materia coherente). Devuelve null si algo no calza.
    function nb_datos_preguntas_desafio(array $ids): ?array {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli)) return null;

        $ids = array_map('intval', $ids);
        if (count($ids) !== 3 || count(array_unique($ids)) !== 3) return null;
        foreach ($ids as $id) if ($id <= 0) return null;

        $st = $conn->prepare(
            "SELECT id, materia_slug, tipo, enunciado, opcion_a, opcion_b, opcion_c, opcion_d
             FROM desafio_preguntas
             WHERE id IN (?,?,?) AND activa = 1 AND revisado_por_admin = 1"
        );
        if (!$st) return null;
        $st->bind_param('iii', $ids[0], $ids[1], $ids[2]);
        $st->execute();
        $res = $st->get_result();
        $porId = [];
        while ($row = $res->fetch_assoc()) $porId[(int)$row['id']] = $row;
        $st->close();

        if (count($porId) !== 3) return null;

        $slugs = array_unique(array_column($porId, 'materia_slug'));
        if (count($slugs) !== 1) return null;

        $m = nb_datos_materia_desafio($slugs[0]);
        if (!$m) return null;

        $preguntas = [];
        foreach ($ids as $id) { // reordenado según el orden pedido (el orden en que se mostraron)
            $row = $porId[$id];
            $opciones = [];
            foreach (['a' => 'opcion_a', 'b' => 'opcion_b', 'c' => 'opcion_c', 'd' => 'opcion_d'] as $letra => $col) {
                if ($row[$col] !== null && $row[$col] !== '') $opciones[$letra] = $row[$col];
            }
            $preguntas[] = [
                'id' => (int)$row['id'],
                'tipo' => $row['tipo'],
                'enunciado' => $row['enunciado'],
                'opciones' => $opciones,
            ];
        }

        return ['materia' => $m, 'preguntas' => $preguntas];
    }
}

if (!function_exists('nb_fingerprint_desafio_preguntas')) {
    function nb_fingerprint_desafio_preguntas(array $ids, array $datos): string {
        $base = NB_IMG_VERSION_DESAFIO . '|preguntas|' . implode(',', $ids) . '|' . ($datos['materia']['nombre'] ?? '');
        foreach ($datos['preguntas'] as $p) {
            $base .= '|' . $p['tipo'] . '|' . $p['enunciado'] . '|' . implode('|', $p['opciones']);
        }
        return substr(md5($base), 0, 10);
    }
}

if (!function_exists('nb_desafio_preguntas_dibujar_bloque')) {
    // Dibuja (o solo mide, si $soloMedir) las 3 preguntas numeradas con sus opciones
    // neutras. Misma función para medir y dibujar (patrón "soloMedir" ya usado en
    // nb_cat_rating_render de imagen_compartir.php) — evita mantener dos copias del
    // layout que podrían desincronizarse. Devuelve el alto total consumido.
    function nb_desafio_preguntas_dibujar_bloque($img, array $preguntas, string $fBold, string $fSemi, string $fReg, int $W, int $M, array $pf, int $yStart, int $cTxt, int $cTxt2, int $cAcento, int $cBlanco, bool $soloMedir): int {
        $xTexto = $M + $pf['diamCircle'] + $pf['gapCircleTexto'];
        $maxTxt = ($W - $M) - $xTexto;
        $total = count($preguntas);

        $y = $yStart;
        foreach ($preguntas as $i => $p) {
            $circleTop = $y;
            $circleCenterY = $circleTop + (int)($pf['diamCircle'] / 2);

            $lineasEnun = nb_wrap_texto($fSemi, $pf['sizeEnun'], (string)$p['enunciado'], $maxTxt, 3);
            $altoEnun = count($lineasEnun) * $pf['lhEnun'];

            if (!$soloMedir) {
                $numero = (string)($i + 1);
                imagefilledellipse($img, $M + (int)($pf['diamCircle'] / 2), $circleCenterY, $pf['diamCircle'], $pf['diamCircle'], $cAcento);
                $bb = imagettfbbox($pf['sizeNum'], 0, $fBold, $numero);
                $tw = abs($bb[2] - $bb[0]); $th = abs($bb[7] - $bb[1]);
                imagettftext($img, $pf['sizeNum'], 0, $M + (int)($pf['diamCircle'] / 2) - (int)($tw / 2), $circleCenterY + (int)($th / 2), $cBlanco, $fBold, $numero);

                $yTextoBase = $circleCenterY + (int)($pf['sizeEnun'] * 0.35);
                foreach ($lineasEnun as $li => $linea) {
                    nb_texto_izquierda($img, $fSemi, $pf['sizeEnun'], $cTxt, $linea, $xTexto, $yTextoBase + $li * $pf['lhEnun']);
                }
            }

            // Solo el alto real del enunciado, SIN max() contra diamCircle: ese max()
            // inflaba el gap real hasta ~2x en enunciados de 1 línea (frecuente en 'vf'),
            // porque el círculo (más alto que una sola línea de texto) empujaba el inicio
            // de las opciones hacia abajo aunque las opciones nunca se cruzan con el
            // círculo (están indentadas a la derecha, en xTexto). Bug real, no cosmético:
            // hacía que el gap enunciado→opción se sintiera "parejo" con gapPreguntas en
            // vez de claramente menor — justo el desbalance reportado en captura real.
            $y = $circleTop + $altoEnun + $pf['gapEnunOp'];

            $letras = array_keys($p['opciones']);
            foreach ($letras as $oi => $letra) {
                $texto = mb_strtoupper($letra, 'UTF-8') . '.  ' . $p['opciones'][$letra];
                $lineasOp = nb_wrap_texto($fReg, $pf['sizeOp'], $texto, $maxTxt, 2);
                if (!$soloMedir) {
                    foreach ($lineasOp as $li => $linea) {
                        nb_texto_izquierda($img, $fReg, $pf['sizeOp'], $cTxt2, $linea, $xTexto, $y + (int)($pf['sizeOp'] * 0.8) + $li * $pf['lhOp']);
                    }
                }
                $y += count($lineasOp) * $pf['lhOp'];
                if ($oi < count($letras) - 1) $y += $pf['gapOpciones'];
            }

            if ($i < $total - 1) $y += $pf['gapPreguntas'];
        }

        return $y - $yStart;
    }
}

if (!function_exists('nb_generar_imagen_desafio_preguntas_history')) {
    function nb_generar_imagen_desafio_preguntas_history(array $materia, array $preguntas, string $output_path): bool {
        if (count($preguntas) !== 3) return false;

        $W = 1080; $H = 1920;
        $fReg  = nb_fonts_dir() . 'Inter-Regular.ttf';
        $fSemi = nb_fonts_dir() . 'Inter-SemiBold.ttf';
        $fBold = nb_fonts_dir() . 'Inter-Bold.ttf';
        foreach ([$fReg, $fSemi, $fBold] as $f) if (!is_file($f)) return false;

        $img = imagecreatetruecolor($W, $H);
        imageantialias($img, true);
        $pal = nb_paleta_marca($img);
        $cBg = $pal['bg']; $cAcento = $pal['acento']; $cTxt = $pal['txt']; $cTxt2 = $pal['txt2']; $cBlanco = $pal['blanco'];
        imagefilledrectangle($img, 0, 0, $W, $H, $cBg);

        $M = 100;

        /* ===== Título con materia incluida ("· ") + subtítulo, arriba =====
           El badge de materia (pill "CÁLCULO") se retiró: quedaba repitiendo
           el mismo dato que ahora ya está en el título — sin badge, sube
           1 dato menos en la jerarquía visual y libera espacio para las
           3 preguntas. Título a 1 sola línea con nombres cortos (11 de 12
           materias) y 2 líneas con nombres largos ("Psicología y
           Estadística", "Contabilidad y Finanzas", etc.) — nb_wrap_texto
           evita truncar cualquiera de los 12 nombres reales (verificado). */
        $y = 90;
        $tituloTxt = 'Desafío Nubira de hoy · ' . (string)($materia['nombre'] ?? '');
        $tituloLineas = nb_wrap_texto($fBold, 38, $tituloTxt, $W - $M * 2, 2);
        $lhTit = 46;
        foreach ($tituloLineas as $i => $linea) {
            nb_texto_centrado($img, $fBold, 38, $cTxt, $linea, $W, $y + 30 + $i * $lhTit);
        }
        $y += count($tituloLineas) * $lhTit + 34;

        nb_texto_centrado($img, $fReg, 26, $cTxt2, '¿Cuánto sabes tú de verdad?', $W, $y);
        $y += 50;

        $contentTop = $y;
        $bottomReservado = 260; // botón + marca + aire
        $alturaDisponible = ($H - $bottomReservado) - $contentTop;

        // Perfiles de tamaño: NORMAL primero; si no entra, un único reintento
        // COMPACT (sin ajuste infinito — diseño aprobado). El texto más largo
        // (alternativas extensas) es el caso que fuerza el fallback; V/F corto
        // casi siempre entra holgado en NORMAL.
        // Jerarquía de espaciado (siempre menor DENTRO del bloque de una pregunta,
        // mayor ENTRE preguntas): gapOpciones < gapEnunOp < gapPreguntas.
        // gapEnunOp calibrado con imagettfbbox() real (no el offset aproximado
        // sizeOp*0.8 usado en el cálculo de posición) para que el gap de TINTA
        // REAL enunciado->opción quede igual al gap real opción->opción — ambos
        // "dentro del bloque". Medido con la pregunta "La derivada de una función
        // constante siempre es igual a 0." (V/F, 2 líneas): a 20/14 el gap real
        // daba 3px/0px (prácticamente tocando — el bug reportado); a 36/28 da
        // 19px/14px, igualando el gap real opción->opción (19px/14px) en vez de
        // quedar muy por debajo. gapPreguntas se mantiene ~5x ese gap real.
        $perfilNormal = [
            'diamCircle' => 64, 'sizeNum' => 28, 'gapCircleTexto' => 28,
            'sizeEnun' => 32, 'lhEnun' => 40,
            'sizeOp' => 26, 'lhOp' => 34,
            'gapEnunOp' => 36, 'gapOpciones' => 10, 'gapPreguntas' => 76,
        ];
        $perfilCompacto = [
            'diamCircle' => 52, 'sizeNum' => 22, 'gapCircleTexto' => 24,
            'sizeEnun' => 26, 'lhEnun' => 32,
            'sizeOp' => 22, 'lhOp' => 28,
            'gapEnunOp' => 28, 'gapOpciones' => 7, 'gapPreguntas' => 58,
        ];

        $altoNormal = nb_desafio_preguntas_dibujar_bloque($img, $preguntas, $fBold, $fSemi, $fReg, $W, $M, $perfilNormal, $contentTop, $cTxt, $cTxt2, $cAcento, $cBlanco, true);

        $perfil = $perfilNormal;
        $altoBloque = $altoNormal;
        if ($altoNormal > $alturaDisponible) {
            $perfil = $perfilCompacto;
            $altoBloque = nb_desafio_preguntas_dibujar_bloque($img, $preguntas, $fBold, $fSemi, $fReg, $W, $M, $perfilCompacto, $contentTop, $cTxt, $cTxt2, $cAcento, $cBlanco, true);
        }

        // Si cabe con margen, se centra dentro del área disponible; si ni comprimido
        // entra, se dibuja desde arriba tal cual (sin un segundo reintento).
        $yInicio = $altoBloque < $alturaDisponible ? $contentTop + (int)(($alturaDisponible - $altoBloque) / 2) : $contentTop;

        nb_desafio_preguntas_dibujar_bloque($img, $preguntas, $fBold, $fSemi, $fReg, $W, $M, $perfil, $yInicio, $cTxt, $cTxt2, $cAcento, $cBlanco, false);

        /* ===== CTA + marca, fijos abajo ===== */
        $yBoton = $H - $bottomReservado + 40;
        $bhBoton = nb_dibujar_boton_generico_desafio($img, $fBold, 'Juega tú mismo', $W, $yBoton, $cAcento, $cBlanco);
        $yMarca = $yBoton + $bhBoton + 45;
        nb_texto_centrado($img, $fBold, 28, $cAcento, 'nubira.cl/desafio', $W, $yMarca);

        $ok = imagejpeg($img, $output_path, 90);
        imagedestroy($img);
        return (bool)$ok;
    }
}

if (!function_exists('nb_obtener_imagen_desafio_preguntas')) {
    // Devuelve la RUTA FÍSICA del JPG (cache hit o recién generado), o '' si falla o
    // los IDs no validan (ver nb_datos_preguntas_desafio). El nombre de archivo incluye
    // los 3 IDs en el orden pedido (no ordenados) — el mismo trío en otro orden genera
    // un archivo de cache distinto, porque la numeración 1/2/3 en la imagen cambiaría.
    function nb_obtener_imagen_desafio_preguntas(array $ids): string {
        $ids = array_map('intval', $ids);
        $datos = nb_datos_preguntas_desafio($ids);
        if (!$datos) return '';

        $fp = nb_fingerprint_desafio_preguntas($ids, $datos);
        $dir = nb_compartir_dir();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . 'desafio_preguntas_' . implode('-', $ids) . '_history_' . $fp . '.jpg';

        if (is_file($file)) return $file; // cache hit

        $ok = nb_generar_imagen_desafio_preguntas_history($datos['materia'], $datos['preguntas'], $file);
        return $ok && is_file($file) ? $file : '';
    }
}
