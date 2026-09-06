<?php
/**
 * ENDPOINT: GENERAR IMÁGENES DE UN DÍA DE CARRUSEL (Copiloto, Fase 3 — fondos IA)
 * ESTADO: BLINDADO (CSRF + RBAC), best-effort por slide.
 *
 * Bajo demanda (botón "Generar imágenes del carrusel" en admin_marketing_cards.php,
 * tab=copiloto). Procesa UN solo día del calendario por request — nunca los 7 de una
 * vez, nunca automático/cron. Recorre los slides de ese día llamando a
 * nb_obtener_imagen_slide_carrusel() (imagen_compartir.php), que decide internamente
 * si sirve de caché o genera de verdad (fondo IA vía Gemini + texto GD encima).
 *
 * Si un slide falla, NO corta el resto del día — cada slide va en su propio try/catch
 * y el resultado final reporta cuántos ok / de caché / generados / fallidos.
 */
require_once __DIR__ . '/init_sesion.php';
require_once __DIR__ . '/helpers/imagen_compartir.php';

// Hasta 5 slides por día, cada uno puede implicar una llamada real a Gemini (~hasta 40s
// de timeout cada una) — 240s da margen real sin acercarse al límite por defecto de PHP.
set_time_limit(240);
ini_set('max_execution_time', '240');

header('Content-Type: application/json; charset=utf-8');

// 1. CORTAFUEGOS DE MÉTODO
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

// 2. CORTAFUEGOS DE ROL
if (($_SESSION['rol'] ?? '') !== 'admin') {
    echo json_encode(['ok' => false, 'error' => 'Acceso denegado.']);
    exit;
}

// 3. CSRF
$csrf_post = $_POST['csrf_token'] ?? '';
if (empty($csrf_post) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_post)) {
    echo json_encode(['ok' => false, 'error' => 'Token de seguridad inválido.']);
    exit;
}

// 4. ENTRADA — calendario_id + dia identifican SIN ambigüedad la tarjeta exacta que el
// admin está viendo (copiloto_calendario no tiene UNIQUE en semana_inicio: regenerar el
// calendario de la misma semana inserta una fila nueva, así que semana_inicio solo no
// alcanza para saber de cuál de esas filas viene el día que se está pidiendo).
$calendario_id = (int)($_POST['calendario_id'] ?? 0);
$dia_pedido    = trim((string)($_POST['dia'] ?? ''));

if ($calendario_id <= 0 || $dia_pedido === '') {
    echo json_encode(['ok' => false, 'error' => 'Faltan parámetros (calendario_id, dia).'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT semana_inicio, contenido FROM copiloto_calendario WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $calendario_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Calendario no encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $semana_inicio = $row['semana_inicio'];
    $contenido     = json_decode($row['contenido'] ?? '{}', true) ?: [];
    $semana        = is_array($contenido['semana'] ?? null) ? $contenido['semana'] : [];

    $dia_data = null;
    foreach ($semana as $d) {
        if (is_array($d) && (string)($d['dia'] ?? '') === $dia_pedido) {
            $dia_data = $d;
            break;
        }
    }

    if (!$dia_data) {
        echo json_encode(['ok' => false, 'error' => 'Ese día no existe en este calendario.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // A partir de acá, el "nombre del día" viene 100% del JSON confiable de la BD
    // (idéntico al de $dia_pedido por construcción del foreach de arriba, pero sin
    // depender de esa igualdad implícita) — el prompt de Gemini nunca se arma con un
    // valor que dependa directamente de lo que llegó crudo en el POST.
    $dia_nombre_real = (string)$dia_data['dia'];

    if ((string)($dia_data['formato'] ?? '') !== 'carrusel') {
        echo json_encode(['ok' => false, 'error' => 'Ese día no es de formato carrusel.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Mismo filtro que la vista PHP usa para decidir qué slides mostrar/contar.
    $slides = is_array($dia_data['slides'] ?? null)
        ? array_values(array_filter($dia_data['slides'], fn($sl) => is_array($sl) && !empty(trim((string)($sl['texto'] ?? '')))))
        : [];

    if (empty($slides)) {
        echo json_encode(['ok' => false, 'error' => 'Ese día no tiene slides con texto.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $categoria_nubira = $dia_data['categoria_nubira'] ?? null;
    $total             = count($slides);
    $root              = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__);

    $resultados = [];
    $ok_count = 0; $cache_count = 0; $generado_count = 0; $fallo_count = 0;

    foreach ($slides as $i => $slide) {
        $numero   = $i + 1;
        $tipo     = in_array($slide['tipo'] ?? '', ['portada', 'contenido', 'cierre'], true) ? $slide['tipo'] : 'contenido';
        $texto    = trim((string)($slide['texto'] ?? ''));
        // Solo relevante para "portada" — contenido/cierre nunca lo traen desde el prompt,
        // pero por si acaso llega algo ahí, no rompe nada pasarlo igual (nb_generar_slide_
        // carrusel() lo ignora fuera de portada).
        $subtexto = trim((string)($slide['subtexto'] ?? ''));

        try {
            $res = nb_obtener_imagen_slide_carrusel($semana_inicio, $dia_nombre_real, $numero, $total, $tipo, $texto, $categoria_nubira, $subtexto);
        } catch (Throwable $e) {
            $res = ['ok' => false, 'path' => null, 'origen' => 'error'];
        }

        if ($res['ok']) {
            $ok_count++;
            if ($res['origen'] === 'generado') $generado_count++; else $cache_count++;
            // URL pública estática: /upload/... se sirve directo (mismo criterio que el
            // resto de archivos en /upload/ del sitio, sin endpoint PHP intermedio).
            $url = '/' . ltrim(str_replace(rtrim($root, '/\\'), '', $res['path']), '/\\');
            $url = str_replace('\\', '/', $url);
        } else {
            $fallo_count++;
            $url = null;
        }

        $resultados[] = [
            'numero' => $numero,
            'tipo'   => $tipo,
            'ok'     => $res['ok'],
            'origen' => $res['origen'],
            'url'    => $url,
        ];
    }

    echo json_encode([
        'ok'        => true,
        'dia'       => $dia_nombre_real,
        'total'     => $total,
        'ok_count'  => $ok_count,
        'de_cache'  => $cache_count,
        'generadas' => $generado_count,
        'fallidas'  => $fallo_count,
        'slides'    => $resultados,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error inesperado generando las imágenes: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
