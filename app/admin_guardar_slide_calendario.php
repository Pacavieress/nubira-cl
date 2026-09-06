<?php
/**
 * ENDPOINT: GUARDAR TEXTO EDITADO DE 1 SLIDE DEL CALENDARIO (Copiloto)
 * ESTADO: BLINDADO (CSRF + RBAC).
 *
 * Bajo demanda (botón "Guardar" en cada slide, admin_marketing_cards.php tab=copiloto).
 * Actualiza los campos "texto" (título) y "subtexto" (apoyo, opcional) de una slide dentro
 * del JSON de copiloto_calendario — aplica a los 3 tipos (portada/contenido/cierre), cada
 * uno con su propio límite de caracteres. Nunca toca imágenes ni llama a Gemini. Guardar y
 * generar imágenes son 2 acciones completamente independientes, con su propio botón cada
 * una.
 *
 * Identificación de la slide (calendario_id + dia + numero_slide): "numero_slide" es la
 * posición dentro del array de slides YA FILTRADO por texto no vacío (mismo criterio que
 * admin_generar_fondos_carrusel.php y admin_marketing_cards.php al renderizar) — NO la
 * posición cruda en el JSON. Se ubica primero por ese criterio y se escribe en su posición
 * ORIGINAL dentro del array crudo, para no correr la numeración del resto del sistema.
 */
require_once __DIR__ . '/init_sesion.php';
require_once __DIR__ . '/helpers/imagen_compartir.php'; // nb_limpiar_texto_slide()

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

// 4. ENTRADA
$calendario_id = (int)($_POST['calendario_id'] ?? 0);
$dia_pedido    = trim((string)($_POST['dia'] ?? ''));
$numero_slide  = (int)($_POST['numero_slide'] ?? 0);
$texto_bruto   = trim((string)($_POST['texto_nuevo'] ?? ''));
$subtexto_bruto = trim((string)($_POST['subtexto_nuevo'] ?? '')); // opcional, solo aplica a portada

if ($calendario_id <= 0 || $dia_pedido === '' || $numero_slide <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Faltan parámetros (calendario_id, dia, numero_slide).'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT contenido FROM copiloto_calendario WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $calendario_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Calendario no encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // JSON corrupto → cortar sin escribir nada, con error claro (no confundir con "día no
    // existe", que es un caso distinto más abajo).
    $contenido = json_decode($row['contenido'] ?? '{}', true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($contenido)) {
        echo json_encode(['ok' => false, 'error' => 'El calendario tiene un JSON corrupto, no se modificó nada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $semana = is_array($contenido['semana'] ?? null) ? $contenido['semana'] : [];

    $dia_idx = null;
    foreach ($semana as $i => $d) {
        if (is_array($d) && (string)($d['dia'] ?? '') === $dia_pedido) {
            $dia_idx = $i;
            break;
        }
    }

    if ($dia_idx === null) {
        echo json_encode(['ok' => false, 'error' => 'Ese día no existe en este calendario.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $slides_crudos = is_array($semana[$dia_idx]['slides'] ?? null) ? $semana[$dia_idx]['slides'] : [];

    // Ubica la slide N contando SOLO las válidas (mismo filtro que el resto del sistema),
    // pero guarda el índice ORIGINAL dentro del array crudo para escribir ahí.
    $contador = 0;
    $slide_idx_original = null;
    foreach ($slides_crudos as $idx => $sl) {
        if (!is_array($sl) || trim((string)($sl['texto'] ?? '')) === '') continue;
        $contador++;
        if ($contador === $numero_slide) {
            $slide_idx_original = $idx;
            break;
        }
    }

    if ($slide_idx_original === null) {
        echo json_encode(['ok' => false, 'error' => 'Esa slide no existe.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tipo_slide = in_array($slides_crudos[$slide_idx_original]['tipo'] ?? '', ['portada', 'contenido', 'cierre'], true)
        ? $slides_crudos[$slide_idx_original]['tipo']
        : 'contenido';

    // Orden: limpiar -> validar límite -> validar vacío. El límite duro se mide sobre el
    // texto YA LIMPIO (lo que realmente va a dibujar nb_generar_slide_carrusel()), no sobre
    // lo que el admin tecleó crudo.
    $texto_limpio = nb_limpiar_texto_slide($texto_bruto);

    $limites = ['portada' => 60, 'contenido' => 40, 'cierre' => 60];
    $limite  = $limites[$tipo_slide];
    $largo   = mb_strlen($texto_limpio, 'UTF-8');

    if ($largo > $limite) {
        echo json_encode([
            'ok'    => false,
            'error' => "El texto supera el límite de {$limite} caracteres para slides tipo \"{$tipo_slide}\" (quedó en {$largo} tras limpiar emojis/símbolos).",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($texto_limpio === '') {
        echo json_encode(['ok' => false, 'error' => 'El texto quedó vacío después de quitar emojis/símbolos no soportados.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Subtexto: mismo orden (limpiar -> límite), SIN chequeo de vacío — a diferencia del
    // título, el subtexto SÍ puede guardarse vacío (es opcional en los 3 tipos; el admin
    // puede borrarlo para volver a "slide sin apoyo"). Límite según el tipo de la slide.
    $subtexto_limpio = nb_limpiar_texto_slide($subtexto_bruto);
    $limitesSubtexto = ['portada' => 90, 'contenido' => 140, 'cierre' => 140];
    $limiteSubtexto  = $limitesSubtexto[$tipo_slide];
    $largoSubtexto   = mb_strlen($subtexto_limpio, 'UTF-8');
    if ($largoSubtexto > $limiteSubtexto) {
        echo json_encode([
            'ok'    => false,
            'error' => "El subtítulo supera el límite de {$limiteSubtexto} caracteres (quedó en {$largoSubtexto} tras limpiar emojis/símbolos).",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $semana[$dia_idx]['slides'][$slide_idx_original]['texto'] = $texto_limpio;
    $semana[$dia_idx]['slides'][$slide_idx_original]['subtexto'] = $subtexto_limpio;
    $contenido['semana'] = $semana;

    $contenido_json = json_encode($contenido, JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare("UPDATE copiloto_calendario SET contenido = ? WHERE id = ?");
    $stmt->bind_param('si', $contenido_json, $calendario_id);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        'ok'                => true,
        'texto_guardado'    => $texto_limpio,
        'subtexto_guardado' => $subtexto_limpio,
        'limpio_cambio'     => ($texto_limpio !== $texto_bruto) || ($subtexto_limpio !== $subtexto_bruto),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error inesperado guardando el texto: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
