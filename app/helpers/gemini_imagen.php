<?php
/**
 * NUBIRA 2.0 — HELPER GEMINI IMAGEN (fondos de slides del Copiloto)
 *
 * Llamada a un modelo de Gemini con capacidad de generación de imagen. Separado de
 * helpers/gemini.php (texto) porque el shape de la request/response es distinto:
 * generationConfig con responseModalities/imageConfig en vez de solo texto, y la
 * respuesta trae la imagen en base64 (inlineData), no un string.
 *
 * MODELO: gemini-3.1-flash-lite-image — elegido por precio (el modelo de imagen más
 * barato de Google vigente a 2026-09, ~$0.0336/img estándar, ~$0.0168/img con Batch
 * API) y por estar optimizado para 1K (1024x1024), suficiente para un fondo simple
 * sin detalle fino. Imagen 4 (Fast/Standard/Ultra) fue dado de baja el 17/08/2026 —
 * NO usar. gemini-2.5-flash-image se apaga el 16/10/2026 — NO usar tampoco.
 *
 * OJO: modelo nuevo — el parseo de la respuesta de abajo sigue la estructura
 * documentada oficialmente a 2026-09 (candidates[].content.parts[].inlineData), pero
 * es best-effort: si el shape real difiere en la primera prueba real, revisar
 * $decoded contra la respuesta cruda antes de asumir que el helper está roto.
 */

require_once __DIR__ . '/../config.php';

if (!defined('GEMINI_IMAGEN_MODEL')) define('GEMINI_IMAGEN_MODEL', 'gemini-3.1-flash-lite-image');

/**
 * Genera una imagen a partir de un prompt de texto. Nunca lanza excepción.
 *
 * @param string $prompt Prompt de imagen (sin pedir texto/letras dentro de la imagen).
 * @param array $opts
 *   - aspect_ratio (string): ej. '4:5'. Default '4:5' (mismo formato que el canvas
 *     1080x1350 de nb_generar_slide_carrusel()).
 *   - timeout (int): timeout de cURL en segundos. Default 40.
 *
 * @return array{ok:bool, bytes?:string, mime?:string, error?:string}
 */
function nb_gemini_generar_imagen(string $prompt, array $opts = []): array {
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
        return ['ok' => false, 'error' => 'GEMINI_API_KEY no configurada'];
    }

    $aspect_ratio = $opts['aspect_ratio'] ?? '4:5';
    $timeout      = $opts['timeout'] ?? 40;

    $payload = [
        'contents' => [
            ['parts' => [['text' => $prompt]]],
        ],
        'generationConfig' => [
            'responseModalities' => ['IMAGE'],
            'imageConfig'        => ['aspectRatio' => $aspect_ratio],
        ],
    ];

    $json_payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
    if (!$json_payload) {
        return ['ok' => false, 'error' => 'No se pudo codificar el payload a JSON'];
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_IMAGEN_MODEL . ':generateContent?key=' . GEMINI_API_KEY;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // sí valida el certificado
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['ok' => false, 'error' => "cURL: {$curl_error}"];
    }
    if ($http_code !== 200 || !$response) {
        return ['ok' => false, 'error' => "HTTP {$http_code}"];
    }

    $decoded = json_decode($response, true);
    $parts = $decoded['candidates'][0]['content']['parts'] ?? [];

    foreach ($parts as $part) {
        if (!empty($part['inlineData']['data'])) {
            $bytes = base64_decode($part['inlineData']['data'], true);
            if ($bytes !== false) {
                return [
                    'ok'    => true,
                    'bytes' => $bytes,
                    'mime'  => $part['inlineData']['mimeType'] ?? 'image/png',
                ];
            }
        }
    }

    return ['ok' => false, 'error' => 'Respuesta sin imagen (revisar shape real de la API)'];
}
