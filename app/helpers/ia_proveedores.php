<?php
/**
 * NUBIRA — IA MULTI-PROVEEDOR CON FAILOVER (Lote 1)
 *
 * nb_ia_generar($prompt, $opts) prueba los proveedores en orden (Gemini -> Groq -> OpenRouter para /desafio;
 * Gemini -> Groq para apuntes) y devuelve el primero que responde bien. Pasa al siguiente ante cualquier
 * fallo: sin clave, HTTP 429/4xx/5xx, timeout, respuesta sin estructura, JSON inválido o rechazado por el
 * validador. Ante un fallo de CONTENIDO (JSON inválido / validador) hace un único intento de reparación en el
 * mismo proveedor antes de pasar al siguiente. Los fallos de transporte/HTTP pasan directo al siguiente.
 *
 * Seguridad:
 *  - Las claves salen SOLO de .env (vía config.php). Nada de claves en el repo.
 *  - Gemini recibe la clave en la cabecera x-goog-api-key, NO en la URL: así ninguna URL (ni log ni mensaje de
 *    error de cURL) puede contener la clave. Aun así, todo texto que se registra o devuelve pasa por
 *    nb_ia_redactar(), que elimina claves conocidas, "key=..." y cabeceras Authorization.
 *  - No se registran prompts ni respuestas: solo proveedor, modelo, resultado, HTTP, ms y un error corto.
 *  - SSL siempre verificado (a diferencia del código viejo de ia_nubira.php).
 *  - 'apuntes' NUNCA usa OpenRouter (los modelos gratuitos pueden registrar los prompts, y ahí viajan
 *    apuntes de usuarios). Es una regla fija en NB_IA_PROHIBIDOS_POR_USO, no depende de la configuración.
 *
 * Este archivo NO se conecta a nada todavía: ningún endpoint lo incluye (Lote 1).
 */

if (!defined('GEMINI_API_KEY')) {
    require_once __DIR__ . '/../config.php'; // GEMINI/GROQ/OPENROUTER_API_KEY, IA_* (desde .env)
}

const NB_IA_PROVEEDORES_CONOCIDOS = ['gemini', 'groq', 'openrouter'];
const NB_IA_PROHIBIDOS_POR_USO    = ['apuntes' => ['openrouter']];

/** Orden efectivo de proveedores para un uso ('desafio' | 'apuntes'): config + filtros fijos. */
function nb_ia_orden(string $uso): array {
    $cfg = match ($uso) {
        'desafio' => defined('IA_PROVEEDORES_DESAFIO') ? IA_PROVEEDORES_DESAFIO : [],
        'apuntes' => defined('IA_PROVEEDORES_APUNTES') ? IA_PROVEEDORES_APUNTES : [],
        default   => [],
    };
    return nb_ia_filtrar_orden($cfg, $uso);
}

function nb_ia_filtrar_orden(array $lista, string $uso): array {
    $prohibidos = NB_IA_PROHIBIDOS_POR_USO[$uso] ?? [];
    $orden = [];
    foreach ($lista as $p) {
        $p = strtolower(trim((string)$p));
        if (in_array($p, NB_IA_PROVEEDORES_CONOCIDOS, true) && !in_array($p, $prohibidos, true) && !in_array($p, $orden, true)) {
            $orden[] = $p;
        }
    }
    return $orden;
}

function nb_ia_clave(string $proveedor): string {
    return match ($proveedor) {
        'gemini'     => defined('GEMINI_API_KEY') ? (string)GEMINI_API_KEY : '',
        'groq'       => defined('GROQ_API_KEY') ? (string)GROQ_API_KEY : '',
        'openrouter' => defined('OPENROUTER_API_KEY') ? (string)OPENROUTER_API_KEY : '',
        default      => '',
    };
}

function nb_ia_modelo(string $proveedor): string {
    return match ($proveedor) {
        'gemini'     => defined('IA_MODELO_GEMINI') ? IA_MODELO_GEMINI : 'gemini-2.5-flash',
        'groq'       => defined('IA_MODELO_GROQ') ? IA_MODELO_GROQ : 'llama-3.3-70b-versatile',
        'openrouter' => defined('IA_MODELO_OPENROUTER') ? IA_MODELO_OPENROUTER : '',
        default      => '',
    };
}

/** Quita de un texto cualquier clave conocida, "key=..." y cabeceras de autorización. */
function nb_ia_redactar(string $s): string {
    $s = preg_replace('/([?&](?:key|api_key|apikey)=)[^&\s"\']+/i', '$1***', $s) ?? $s;
    $s = preg_replace('/\b(Bearer|Authorization:?|x-goog-api-key:?)\s+[A-Za-z0-9._\-]{6,}/i', '$1 ***', $s) ?? $s;
    foreach (['gemini', 'groq', 'openrouter'] as $p) {
        $k = nb_ia_clave($p);
        if (strlen($k) >= 8) $s = str_replace($k, '***', $s);
    }
    return $s;
}

/** Corta y redacta un mensaje de error para guardarlo/devolverlo. */
function nb_ia_error_corto(string $s, int $max = 200): string {
    return mb_substr(nb_ia_redactar(trim(preg_replace('/\s+/', ' ', $s))), 0, $max);
}

/** Transporte HTTP (reemplazable en pruebas). SSL siempre verificado. */
if (!function_exists('nb_ia_http_post')) {
    function nb_ia_http_post(string $url, array $headers, string $json_body, int $timeout): array {
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $body  = curl_exec($ch);
        $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'http'  => $http,
            'body'  => is_string($body) ? $body : '',
            'errno' => $errno,
            'error' => $error,
            'ms'    => (int)round((microtime(true) - $t0) * 1000),
        ];
    }
}

/** Primer objeto/arreglo JSON dentro de un texto (tolera ```json ... ``` y texto alrededor). */
function nb_ia_extraer_json(string $texto): ?array {
    $t = trim($texto);
    $t = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $t) ?? $t;
    $dec = json_decode($t, true);
    if (is_array($dec)) return $dec;
    if (preg_match('/\{[\s\S]*\}/', $t, $m)) {
        $dec = json_decode($m[0], true);
        if (is_array($dec)) return $dec;
    }
    return null;
}

/**
 * Una llamada a un proveedor. Devuelve ['ok','texto'?,'http','ms','error'?].
 * Nunca lanza excepciones.
 */
function nb_ia_llamar_proveedor(string $prov, string $prompt, array $opts, int $timeout): array {
    $modelo      = nb_ia_modelo($prov);
    $temperature = (float)($opts['temperature'] ?? 0.7);
    $json        = (bool)($opts['response_json'] ?? false);
    $system      = $opts['system'] ?? null;
    $max_tokens  = isset($opts['max_tokens']) ? (int)$opts['max_tokens'] : null;
    $clave       = nb_ia_clave($prov);

    if ($prov === 'gemini') {
        $gen = ['temperature' => $temperature];
        if ($json) $gen['responseMimeType'] = 'application/json';
        if ($max_tokens) $gen['maxOutputTokens'] = $max_tokens;
        $payload = ['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => $gen];
        if (!empty($system)) $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        $url     = "https://generativelanguage.googleapis.com/v1beta/models/{$modelo}:generateContent"; // sin clave en la URL
        $headers = ['Content-Type: application/json', 'x-goog-api-key: ' . $clave];
    } else {
        $base = $prov === 'groq' ? 'https://api.groq.com/openai/v1' : 'https://openrouter.ai/api/v1';
        $mensajes = [];
        if (!empty($system)) $mensajes[] = ['role' => 'system', 'content' => $system];
        $mensajes[] = ['role' => 'user', 'content' => $prompt];
        $payload = ['model' => $modelo, 'messages' => $mensajes, 'temperature' => $temperature];
        if ($max_tokens) $payload['max_tokens'] = $max_tokens;
        // response_format json_object lo soporta Groq (exige que el prompt mencione "JSON"); en OpenRouter los
        // modelos :free no siempre lo soportan, así que ahí se confía en el prompt + validación.
        if ($json && $prov === 'groq') $payload['response_format'] = ['type' => 'json_object'];
        $url     = $base . '/chat/completions';
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $clave];
        if ($prov === 'openrouter') {
            $headers[] = 'HTTP-Referer: https://nubira.cl';
            $headers[] = 'X-Title: Nubira';
        }
    }

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
    if ($body === false) {
        return ['ok' => false, 'http' => 0, 'ms' => 0, 'error' => 'no se pudo codificar el payload'];
    }

    try {
        $r = nb_ia_http_post($url, $headers, $body, $timeout);
    } catch (\Throwable $e) {
        return ['ok' => false, 'http' => 0, 'ms' => 0, 'error' => nb_ia_error_corto('excepción de transporte: ' . $e->getMessage())];
    }

    $http = (int)($r['http'] ?? 0);
    $ms   = (int)($r['ms'] ?? 0);
    if (!empty($r['errno'])) {
        $msg = ((int)$r['errno'] === 28) ? 'timeout' : 'error de red';
        return ['ok' => false, 'http' => $http, 'ms' => $ms, 'error' => nb_ia_error_corto($msg . ' (' . ($r['error'] ?? '') . ')')];
    }

    $dec = json_decode((string)($r['body'] ?? ''), true);
    if ($http !== 200) {
        $detalle = is_array($dec) ? ($dec['error']['message'] ?? $dec['error'] ?? '') : '';
        if (is_array($detalle)) $detalle = json_encode($detalle, JSON_UNESCAPED_UNICODE);
        return ['ok' => false, 'http' => $http, 'ms' => $ms, 'error' => nb_ia_error_corto("HTTP {$http}" . ($detalle !== '' ? ' - ' . $detalle : ''))];
    }

    $texto = $prov === 'gemini'
        ? ($dec['candidates'][0]['content']['parts'][0]['text'] ?? null)
        : ($dec['choices'][0]['message']['content'] ?? null);
    if (!is_string($texto) || trim($texto) === '') {
        $detalle = is_array($dec) && isset($dec['error']) ? (' - ' . (is_array($dec['error']) ? ($dec['error']['message'] ?? 'error') : $dec['error'])) : '';
        return ['ok' => false, 'http' => $http, 'ms' => $ms, 'error' => nb_ia_error_corto('respuesta 200 sin texto utilizable' . $detalle)];
    }
    return ['ok' => true, 'texto' => $texto, 'http' => $http, 'ms' => $ms];
}

/**
 * Registro en ia_llamadas_log + error_log. Nunca rompe a quien llama: si la tabla no existe (SQL sin ejecutar)
 * o no hay conexión, solo queda la línea de error_log. NO guarda prompts ni respuestas.
 */
function nb_ia_registrar($conn, string $funcion, string $prov, string $modelo, bool $ok, ?int $http, ?int $ms, $usuario_id, ?string $error): void {
    $linea = sprintf('[IA] %s %s/%s ok=%d http=%s ms=%s%s', $funcion, $prov, $modelo, $ok ? 1 : 0,
        $http ?? '-', $ms ?? '-', $error ? ' err=' . $error : '');
    error_log($linea);

    if (!($conn instanceof mysqli)) return;
    try {
        $stmt = $conn->prepare(
            "INSERT INTO ia_llamadas_log (funcion, proveedor, modelo, ok, http_code, ms, usuario_id, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return;
        $ok_i = $ok ? 1 : 0;
        $uid  = $usuario_id ? (int)$usuario_id : null;
        $stmt->bind_param('sssiiiis', $funcion, $prov, $modelo, $ok_i, $http, $ms, $uid, $error);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $e) {
        // tabla inexistente u otro problema de BD: se ignora a propósito
    }
}

/**
 * Genera texto (o JSON) con failover entre proveedores.
 *
 * @param array $opts
 *   uso            'desafio' | 'apuntes'  (define el orden por defecto y las exclusiones fijas). Default 'desafio'.
 *   proveedores    array|null  orden explícito (igual se filtran los prohibidos del uso).
 *   funcion        string  etiqueta para el log (default = uso), p. ej. 'desafio_generar', 'ia_nubira'.
 *   usuario_id     int|null  para el log.
 *   conn           mysqli|null  conexión para el log (default $GLOBALS['conn']).
 *   system         string|null  instrucción de sistema.
 *   temperature    float (0.7)
 *   response_json  bool  si true, exige JSON y lo devuelve parseado en 'json'.
 *   validador      callable(array):true|string  valida el JSON; string = motivo de rechazo.
 *   reintento_json bool (true)  un intento de reparación por proveedor ante fallo de contenido.
 *   timeout        int  segundos por llamada (default IA_TIMEOUT_PROVEEDOR).
 *   presupuesto    float  segundos totales para toda la cadena (default IA_PRESUPUESTO_SEGUNDOS).
 *   max_tokens     int|null
 *
 * @return array{ok:bool, proveedor?:string, modelo?:string, texto?:string, json?:array, error?:string, intentos:array}
 */
function nb_ia_generar(string $prompt, array $opts = []): array {
    $uso      = (string)($opts['uso'] ?? 'desafio');
    $orden    = isset($opts['proveedores']) && is_array($opts['proveedores'])
        ? nb_ia_filtrar_orden($opts['proveedores'], $uso)
        : nb_ia_orden($uso);
    $funcion  = (string)($opts['funcion'] ?? $uso);
    $conn     = $opts['conn'] ?? ($GLOBALS['conn'] ?? null);
    $uid      = $opts['usuario_id'] ?? null;
    $timeout  = (int)($opts['timeout'] ?? (defined('IA_TIMEOUT_PROVEEDOR') ? IA_TIMEOUT_PROVEEDOR : 12));
    $presup   = (float)($opts['presupuesto'] ?? (defined('IA_PRESUPUESTO_SEGUNDOS') ? IA_PRESUPUESTO_SEGUNDOS : 40));
    $json     = (bool)($opts['response_json'] ?? false);
    $validador = $opts['validador'] ?? null;
    $reparar  = (bool)($opts['reintento_json'] ?? true);

    $inicio   = microtime(true);
    $intentos = [];
    $ultimo_error = 'No hay proveedores de IA configurados';

    foreach ($orden as $prov) {
        $modelo = nb_ia_modelo($prov);
        if (nb_ia_clave($prov) === '') {
            $intentos[] = ['proveedor' => $prov, 'ok' => false, 'http' => null, 'ms' => 0, 'error' => 'sin clave configurada (omitido)'];
            continue;
        }

        $prompt_actual = $prompt;
        $max = ($json && $reparar) ? 2 : 1;
        for ($i = 1; $i <= $max; $i++) {
            $restante = $presup - (microtime(true) - $inicio);
            if ($restante < 2) {
                $ultimo_error = 'Se agotó el tiempo total disponible para la IA';
                $intentos[] = ['proveedor' => $prov, 'ok' => false, 'http' => null, 'ms' => 0, 'error' => 'presupuesto de tiempo agotado'];
                break 2;
            }
            $t = (int)max(2, min($timeout, floor($restante)));

            $r = nb_ia_llamar_proveedor($prov, $prompt_actual, $opts, $t);

            $parsed = null;
            $motivo_contenido = null;
            if ($r['ok'] && $json) {
                $parsed = nb_ia_extraer_json($r['texto']);
                if ($parsed === null) {
                    $motivo_contenido = 'la respuesta no es JSON válido';
                } elseif (is_callable($validador)) {
                    try { $v = $validador($parsed); } catch (\Throwable $e) { $v = 'el validador falló'; }
                    if ($v !== true) $motivo_contenido = 'validación: ' . nb_ia_error_corto(is_string($v) ? $v : 'formato inválido', 120);
                }
            }

            $ok_final = $r['ok'] && $motivo_contenido === null;
            $err      = $ok_final ? null : ($r['error'] ?? $motivo_contenido);
            nb_ia_registrar($conn, $funcion, $prov, $modelo, $ok_final, $r['http'] ?? null, $r['ms'] ?? null, $uid, $err);
            $intentos[] = ['proveedor' => $prov, 'ok' => $ok_final, 'http' => $r['http'] ?? null, 'ms' => $r['ms'] ?? 0, 'error' => $err];

            if ($ok_final) {
                $res = ['ok' => true, 'proveedor' => $prov, 'modelo' => $modelo, 'texto' => $r['texto'], 'intentos' => $intentos];
                if ($json) $res['json'] = $parsed;
                return $res;
            }

            $ultimo_error = (string)$err;
            if (!$r['ok']) break; // fallo de transporte/HTTP: directo al siguiente proveedor

            // fallo de contenido: un intento de reparación en el mismo proveedor
            if ($i < $max) {
                $prompt_actual = $prompt . "\n\nTu respuesta anterior no se pudo usar (" . $motivo_contenido
                    . "). Responde SOLO con el JSON pedido, sin texto adicional ni markdown.";
            }
        }
    }

    return ['ok' => false, 'error' => $ultimo_error, 'intentos' => $intentos];
}
