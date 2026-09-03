<?php
/**
 * NUBIRA 2.0 — HELPER GEMINI (llamada única y reutilizable)
 *
 * Encapsula la llamada HTTP cruda a la API de Gemini (generateContent) en una
 * sola función. Reemplaza el patrón que hoy está duplicado en
 * app/datos/ia_nubira.php y app/ajax_generar_borrador_guia.php — esos 2
 * archivos NO fueron tocados todavía; ese refactor es un paso aparte.
 *
 * Diferencia deliberada respecto a esos 2: CURLOPT_SSL_VERIFYPEER = true acá
 * (los otros dos lo tienen en false, deuda técnica ya documentada en
 * CLAUDE.md — este helper nuevo no la arrastra).
 */

require_once __DIR__ . '/../config.php'; // define GEMINI_API_KEY desde .env

/**
 * Llama a Gemini y devuelve el resultado sin nunca lanzar una excepción fatal.
 *
 * @param string $prompt Prompt de usuario (texto plano).
 * @param array $opts Opciones:
 *   - model (string): modelo de Gemini. Default 'gemini-2.5-flash'.
 *   - temperature (float): default 0.7.
 *   - response_json (bool): si true, pide responseMimeType=application/json
 *     e intenta parsear la respuesta como JSON. Default false.
 *   - timeout (int): timeout de cURL en segundos. Default 30.
 *   - system_instruction (string|null): instrucción de sistema opcional.
 *
 * @return array{ok:bool, texto?:string, json?:array, error?:string}
 */
function nb_gemini_generar(string $prompt, array $opts = []): array {
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
        return ['ok' => false, 'error' => 'GEMINI_API_KEY no configurada'];
    }

    $model              = $opts['model'] ?? 'gemini-2.5-flash';
    $temperature        = $opts['temperature'] ?? 0.7;
    $response_json      = (bool)($opts['response_json'] ?? false);
    $timeout            = $opts['timeout'] ?? 30;
    $system_instruction = $opts['system_instruction'] ?? null;

    $generationConfig = ['temperature' => $temperature];
    if ($response_json) {
        $generationConfig['responseMimeType'] = 'application/json';
    }

    $payload = [
        'contents' => [
            ['parts' => [['text' => $prompt]]],
        ],
        'generationConfig' => $generationConfig,
    ];
    if (!empty($system_instruction)) {
        $payload['systemInstruction'] = ['parts' => [['text' => $system_instruction]]];
    }

    $json_payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
    if (!$json_payload) {
        return ['ok' => false, 'error' => 'No se pudo codificar el payload a JSON'];
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;

    // Retry: 2 intentos con 500ms de espera entre ellos — mismo patrón que
    // app/datos/ia_nubira.php, ante fallos de bajo nivel (timeout, HTTP != 200,
    // estructura inesperada). No reintenta si Gemini respondió 200 pero el
    // texto no es JSON parseable en modo response_json — eso no es un fallo
    // de la llamada, es un problema de contenido, y se refleja devolviendo
    // 'texto' sin la clave 'json'.
    $intentos_maximos = 2;
    $ultimo_error = 'Fallo desconocido llamando a Gemini';

    for ($intento = 1; $intento <= $intentos_maximos; $intento++) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // CRÍTICO: sí valida el certificado (a diferencia de los 2 generadores viejos)
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        $response   = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($http_code === 200 && $response) {
            $decoded = json_decode($response, true);
            if (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
                $texto = $decoded['candidates'][0]['content']['parts'][0]['text'];
                $resultado = ['ok' => true, 'texto' => $texto];

                if ($response_json) {
                    $texto_json = $texto;
                    if (preg_match('/\{[\s\S]*\}/', $texto_json, $m)) {
                        $texto_json = $m[0]; // Gemini a veces envuelve el JSON en texto/markdown pese al responseMimeType
                    }
                    $obj = json_decode($texto_json, true);
                    if (is_array($obj)) {
                        $resultado['json'] = $obj;
                    }
                }

                return $resultado;
            }
            $ultimo_error = 'Respuesta HTTP 200 con estructura inesperada';
        } else {
            $ultimo_error = "HTTP {$http_code}" . ($curl_error ? " - {$curl_error}" : '');
        }

        if ($intento < $intentos_maximos) {
            usleep(500000); // 500ms antes de reintentar
        }
    }

    return ['ok' => false, 'error' => $ultimo_error];
}
