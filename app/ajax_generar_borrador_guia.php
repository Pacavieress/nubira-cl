<?php
// app/ajax_generar_borrador_guia.php
// Endpoint AJAX: genera un borrador de artículo (titulo_h1, resumen, cuerpo_html,
// meta_description, faqs) vía Gemini para el Centro de Recursos. Llamado desde
// admin_guias.php (botón "Generar con IA"). NO guarda nada en BD — solo
// devuelve el borrador para que el admin lo revise/edite en el formulario.
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
session_start();

if (($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['exito' => false, 'error' => 'Acceso denegado']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/sanitizar_html.php';

$input = json_decode(file_get_contents('php://input'), true);
$categoria_nombre = trim($input['categoria_nombre'] ?? '');
$tema             = trim($input['tema'] ?? '');
$puntos_clave     = trim($input['puntos_clave'] ?? '');
$tono             = $input['tono'] ?? 'default';

if ($categoria_nombre === '' || $tema === '') {
    http_response_code(400);
    echo json_encode(['exito' => false, 'error' => 'Falta categoría o tema.']);
    exit;
}

if (GEMINI_API_KEY === '') {
    error_log('[Guias IA] GEMINI_API_KEY no configurada.');
    echo json_encode(['exito' => false, 'error' => 'IA no disponible: falta configuración de API key.']);
    exit;
}

$instrucciones_tono = match ($tono) {
    'academico'  => "TONO: académico formal, lenguaje técnico y objetivo.",
    'persuasivo' => "TONO: persuasivo profesional, foco en el problema real del estudiante, sin caer en autoayuda ni exclamaciones.",
    default      => "TONO: premium equilibrado, directo y táctico (estilo Airbnb), sin lenguaje motivacional.",
};

$puntos_clave_txt = $puntos_clave !== '' ? $puntos_clave : '(sin puntos clave adicionales — usa tu criterio editorial dentro del tema dado)';

$prompt = <<<PROMPT
Actúa como redactor de contenido educativo para el Centro de Recursos de Nubira.

CONTEXTO REAL DE NUBIRA (no inventes nada fuera de esto):
- Marketplace educativo chileno: estudiantes universitarios compran/venden
  tutorías y apuntes entre sí. Moneda CLP. Pago protegido (Garantía Nubira).
- NO tienes datos reales de cifras (número de tutores, calificaciones
  promedio, testimonios). PROHIBIDO inventar estadísticas, nombres de
  tutores, reseñas o cifras específicas que no se te den explícitamente.
- Si necesitas ejemplos, usa ejemplos genéricos ("un tutor de Cálculo en
  Nubira puede ayudarte con...") sin datos verificables inventados.
- No menciones URLs ni links.

CATEGORÍA: {$categoria_nombre}
TEMA: {$tema}
PUNTOS CLAVE A CUBRIR: {$puntos_clave_txt}
{$instrucciones_tono}

INSTRUCCIONES PARA "resumen": redacta 2-3 oraciones completas que resuman
el artículo. El límite es 300 caracteres, pero es más importante que la
idea quede cerrada (oración completa, terminada en punto) que llegar
exactamente al límite — si una tercera oración no entra completa, no la
empieces; prefiere 2 oraciones completas y bien cerradas antes que 3
oraciones con la última cortada a la mitad.

INSTRUCCIONES PARA "meta_description": mismo criterio, 1-2 oraciones
completas, límite 155 caracteres — prioriza cerrar la idea antes que
llegar al límite exacto.

FORMATO DE SALIDA EXACTO (JSON PURO, sin texto antes ni después):
{
  "titulo_h1": "string, máx 100 caracteres",
  "resumen": "2-3 oraciones completas, máx 300 caracteres (ver instrucción arriba)",
  "cuerpo_html": "string HTML — SOLO estas etiquetas: <p> <h2> <h3> <ul> <ol> <li> <strong> <em>. Nada de <a>, <script>, <style>, ni atributos.",
  "meta_description": "1-2 oraciones completas, máx 155 caracteres (ver instrucción arriba)",
  "faqs": [{"pregunta": "string", "respuesta": "string"}]
}
PROMPT;

$payload = [
    "contents" => [["parts" => [["text" => $prompt]]]],
    "generationConfig" => [
        "temperature" => 0.7,
        "responseMimeType" => "application/json",
    ],
];

$ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . GEMINI_API_KEY);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response   = curl_exec($ch);
$http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

function nb_guia_ia_error(string $mensaje, string $log): void {
    error_log('[Guias IA] ' . $log);
    echo json_encode(['exito' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

// Corte inteligente: prioriza terminar en '.', '!' o '?' dentro del límite
// (oración completa); si no hay uno razonablemente cerca, corta en el último
// espacio (nunca parte una palabra a la mitad).
function nb_truncar_en_limite(string $texto, int $max): string {
    if (mb_strlen($texto) <= $max) return $texto;
    $recorte = mb_substr($texto, 0, $max);

    $ultimo_punto = max(
        mb_strrpos($recorte, '.') ?: -1,
        mb_strrpos($recorte, '!') ?: -1,
        mb_strrpos($recorte, '?') ?: -1
    );
    if ($ultimo_punto > $max * 0.5) {
        return mb_substr($recorte, 0, $ultimo_punto + 1);
    }

    $ultimo_espacio = mb_strrpos($recorte, ' ');
    return $ultimo_espacio !== false ? mb_substr($recorte, 0, $ultimo_espacio) : $recorte;
}

// Señal barata de "esto no cierra una idea" — no es infalible, es un aviso
// para que el admin lo revise, no una validación que bloquee nada.
function nb_parece_incompleto(string $texto): bool {
    $texto = rtrim($texto);
    return $texto !== '' && !in_array(mb_substr($texto, -1), ['.', '!', '?', '…'], true);
}

if ($http_code !== 200 || !$response) {
    nb_guia_ia_error(
        'No se pudo generar contenido. Intenta de nuevo o redacta manualmente.',
        "HTTP $http_code: " . ($response ?: $curl_error)
    );
}

$decoded = json_decode($response, true);
$texto_ia = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;

if (!$texto_ia) {
    nb_guia_ia_error('No se pudo generar contenido. Intenta de nuevo o redacta manualmente.', 'Estructura inesperada: ' . $response);
}

if (preg_match('/\{[\s\S]*\}/', $texto_ia, $m)) {
    $texto_ia = $m[0];
}

$obj = json_decode($texto_ia, true);

if (!is_array($obj) || empty($obj['cuerpo_html']) || empty($obj['titulo_h1'])) {
    nb_guia_ia_error('El modelo devolvió una respuesta incompleta. Intenta de nuevo.', 'JSON inválido o incompleto: ' . $texto_ia);
}

// Sanitización (momento 1 de 2 — el segundo ocurre al guardar en admin_guias.php)
$cuerpo_html_limpio = nb_sanitizar_html((string)$obj['cuerpo_html']);
$se_removio_contenido = trim(strip_tags($cuerpo_html_limpio)) !== '' && $cuerpo_html_limpio !== $obj['cuerpo_html'];

$faqs_out = [];
if (!empty($obj['faqs']) && is_array($obj['faqs'])) {
    foreach ($obj['faqs'] as $faq) {
        if (!empty($faq['pregunta']) && !empty($faq['respuesta'])) {
            $faqs_out[] = [
                'pregunta'  => mb_substr(trim((string)$faq['pregunta']), 0, 200),
                'respuesta' => trim((string)$faq['respuesta']),
            ];
        }
    }
}

$titulo_h1_out = nb_truncar_en_limite(trim((string)$obj['titulo_h1']), 100);
$resumen_out   = nb_truncar_en_limite(trim((string)($obj['resumen'] ?? '')), 300);
$meta_desc_out = nb_truncar_en_limite(trim((string)($obj['meta_description'] ?? '')), 155);

echo json_encode([
    'exito' => true,
    'titulo_h1'        => $titulo_h1_out,
    'resumen'          => $resumen_out,
    'cuerpo_html'      => $cuerpo_html_limpio,
    'meta_description' => $meta_desc_out,
    'faqs'             => $faqs_out,
    'aviso_sanitizacion' => $se_removio_contenido
        ? 'Se removió contenido no permitido del borrador generado, revisa el resultado.'
        : null,
    'aviso_resumen_incompleto' => nb_parece_incompleto($resumen_out)
        ? 'El resumen parece estar incompleto, revísalo antes de guardar.'
        : null,
    'aviso_meta_incompleto' => nb_parece_incompleto($meta_desc_out)
        ? 'La meta description parece estar incompleta, revísala antes de guardar.'
        : null,
], JSON_UNESCAPED_UNICODE);
