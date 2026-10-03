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

// ═══════════════════════════════════════════════════════════════════════════════════════════════
// LOTE 5 — LÍMITES DE USO: topes por proveedor, enfriamiento tras 429/503 y límite por usuario.
// Valores por defecto (se sobrescriben definiendo las constantes en config.php, que se carga antes).
// Si faltan las tablas (ia_llamadas_log / ia_proveedor_estado) todo DEGRADA sin romperse: no se aplica el control que
// dependía de la tabla ausente (comportamiento anterior al Lote 5).
// ═══════════════════════════════════════════════════════════════════════════════════════════════
foreach (['GEMINI', 'GROQ', 'OPENROUTER'] as $__p) {
    if (!defined("IA_LIMITE_MIN_$__p")) define("IA_LIMITE_MIN_$__p", 10);
    if (!defined("IA_LIMITE_DIA_$__p")) define("IA_LIMITE_DIA_$__p", 200);
}
unset($__p);
if (!defined('IA_ENFRIAMIENTO_MINUTOS')) define('IA_ENFRIAMIENTO_MINUTOS', 2);
if (!defined('IA_USUARIO_MAX_HORA'))     define('IA_USUARIO_MAX_HORA', 10);
if (!defined('IA_USUARIO_MAX_DIA'))      define('IA_USUARIO_MAX_DIA', 30);

/** Topes locales ['min' => n, 'dia' => n] de un proveedor conocido. */
function nb_ia_limites(string $prov): array {
    if (!in_array($prov, NB_IA_PROVEEDORES_CONOCIDOS, true)) return ['min' => 0, 'dia' => 0];
    $P = strtoupper($prov);
    return ['min' => (int)constant("IA_LIMITE_MIN_$P"), 'dia' => (int)constant("IA_LIMITE_DIA_$P")];
}

/** Llamadas HTTP registradas de un proveedor en los últimos $segundos (fallidas incluidas). null si no se puede contar. */
function nb_ia_uso_proveedor($conn, string $prov, int $segundos, string $tabla = 'ia_llamadas_log'): ?int {
    if (!($conn instanceof mysqli) || !preg_match('/^[a-z_]+$/', $tabla)) return null;
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM `$tabla` WHERE proveedor = ? AND fecha >= (NOW() - INTERVAL ? SECOND)");
        if (!$st) return null;
        $st->bind_param('si', $prov, $segundos);
        $st->execute();
        $st->bind_result($n);
        $st->fetch();
        $st->close();
        return (int)$n;
    } catch (\Throwable $e) {
        return null;
    }
}

/** Fecha 'Y-m-d H:i:s' hasta la que el proveedor está en enfriamiento, o null si no lo está (o no hay tabla). */
function nb_ia_enfriamiento_hasta($conn, string $prov, string $tabla = 'ia_proveedor_estado'): ?string {
    if (!($conn instanceof mysqli) || !preg_match('/^[a-z_]+$/', $tabla)) return null;
    try {
        $st = $conn->prepare("SELECT bloqueado_hasta FROM `$tabla` WHERE proveedor = ? AND bloqueado_hasta > NOW()");
        if (!$st) return null;
        $st->bind_param('s', $prov);
        $st->execute();
        $st->bind_result($hasta);
        $ok = $st->fetch();
        $st->close();
        return $ok ? (string)$hasta : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/** Deja al proveedor en enfriamiento $minutos. Nunca rompe a quien llama. */
function nb_ia_marcar_enfriamiento($conn, string $prov, int $minutos, string $motivo, string $tabla = 'ia_proveedor_estado'): void {
    if (!($conn instanceof mysqli) || $minutos <= 0 || !preg_match('/^[a-z_]+$/', $tabla)) return;
    try {
        $st = $conn->prepare("INSERT INTO `$tabla` (proveedor, bloqueado_hasta, motivo) VALUES (?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)
                              ON DUPLICATE KEY UPDATE bloqueado_hasta = VALUES(bloqueado_hasta), motivo = VALUES(motivo)");
        if (!$st) return;
        $motivo = mb_substr($motivo, 0, 120);
        $st->bind_param('sis', $prov, $minutos, $motivo);
        $st->execute();
        $st->close();
    } catch (\Throwable $e) {
        // tabla inexistente: sin enfriamiento compartido (se ignora a propósito)
    }
}

/**
 * ¿Se puede consultar a este proveedor ahora? Revisa (1) enfriamiento tras 429/503, (2) tope por minuto, (3) tope por día.
 * @param array $o limite_min, limite_dia (pruebas), tabla_log, tabla_estado (pruebas)
 * @return array{ok:bool, motivo?:string}
 */
function nb_ia_proveedor_disponible($conn, string $prov, array $o = []): array {
    if (!($conn instanceof mysqli)) return ['ok' => true];
    $hasta = nb_ia_enfriamiento_hasta($conn, $prov, $o['tabla_estado'] ?? 'ia_proveedor_estado');
    if ($hasta !== null) return ['ok' => false, 'motivo' => 'en enfriamiento hasta las ' . substr($hasta, 11, 5)];

    $lim = nb_ia_limites($prov);
    $lim_min = (int)($o['limite_min'] ?? $lim['min']);
    $lim_dia = (int)($o['limite_dia'] ?? $lim['dia']);
    $tabla = $o['tabla_log'] ?? 'ia_llamadas_log';

    $uso_min = nb_ia_uso_proveedor($conn, $prov, 60, $tabla);
    if ($uso_min !== null && $uso_min >= $lim_min) return ['ok' => false, 'motivo' => "tope local por minuto ({$uso_min} de {$lim_min})"];
    $uso_dia = nb_ia_uso_proveedor($conn, $prov, 86400, $tabla);
    if ($uso_dia !== null && $uso_dia >= $lim_dia) return ['ok' => false, 'motivo' => "tope local por día ({$uso_dia} de {$lim_dia})"];
    return ['ok' => true];
}

/** Estado de cada proveedor del uso indicado (para el panel de admin). */
function nb_ia_estado_proveedores($conn, string $uso = 'desafio'): array {
    $out = [];
    foreach (nb_ia_orden($uso) as $prov) {
        $lim = nb_ia_limites($prov);
        $out[] = [
            'proveedor'    => $prov,
            'con_clave'    => nb_ia_clave($prov) !== '',
            'usados_min'   => nb_ia_uso_proveedor($conn, $prov, 60),
            'limite_min'   => $lim['min'],
            'usados_dia'   => nb_ia_uso_proveedor($conn, $prov, 86400),
            'limite_dia'   => $lim['dia'],
            'enfriamiento' => nb_ia_enfriamiento_hasta($conn, $prov),
        ];
    }
    return $out;
}

/**
 * Límite por usuario para llamadas de IA iniciadas por usuarios: generaciones EXITOSAS de $funcion en la última hora y
 * en las últimas 24 h (ia_llamadas_log). Cuenta solo éxitos para que un fallo del proveedor no castigue al usuario.
 * Sin tabla: no bloquea ('degradado' => true). Aún NO está conectado a ia_nubira.php (Lote 6).
 *
 * @param array $o max_hora, max_dia, tabla_log (pruebas)
 * @return array{ok:bool, motivo?:string, reintentar_en?:int, usadas_hora:?int, usadas_dia:?int, max_hora:int, max_dia:int, degradado?:bool}
 */
function nb_ia_limite_usuario($conn, int $usuario_id, string $funcion, array $o = []): array {
    $max_h = (int)($o['max_hora'] ?? IA_USUARIO_MAX_HORA);
    $max_d = (int)($o['max_dia'] ?? IA_USUARIO_MAX_DIA);
    $tabla = $o['tabla_log'] ?? 'ia_llamadas_log';
    $r = ['ok' => true, 'usadas_hora' => null, 'usadas_dia' => null, 'max_hora' => $max_h, 'max_dia' => $max_d];
    if (!($conn instanceof mysqli) || !preg_match('/^[a-z_]+$/', $tabla) || $usuario_id <= 0) { $r['degradado'] = true; return $r; }

    try {
        $cuenta = function (int $segundos) use ($conn, $tabla, $usuario_id, $funcion): int {
            $st = $conn->prepare("SELECT COUNT(*) FROM `$tabla` WHERE usuario_id = ? AND funcion = ? AND ok = 1 AND fecha >= (NOW() - INTERVAL ? SECOND)");
            $st->bind_param('isi', $usuario_id, $funcion, $segundos);
            $st->execute();
            $st->bind_result($n);
            $st->fetch();
            $st->close();
            return (int)$n;
        };
        // segundos hasta que se libere un cupo: la fila (usadas - max) en orden de antigüedad debe salir de la ventana
        $espera = function (int $segundos, int $usadas, int $max) use ($conn, $tabla, $usuario_id, $funcion): int {
            $k = $usadas - $max;
            $st = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(fecha, INTERVAL ? SECOND)) FROM `$tabla`
                                   WHERE usuario_id = ? AND funcion = ? AND ok = 1 AND fecha >= (NOW() - INTERVAL ? SECOND)
                                   ORDER BY fecha ASC LIMIT 1 OFFSET ?");
            $st->bind_param('iisii', $segundos, $usuario_id, $funcion, $segundos, $k);
            $st->execute();
            $st->bind_result($s);
            $st->fetch();
            $st->close();
            return max(1, (int)$s);
        };

        $r['usadas_hora'] = $cuenta(3600);
        $r['usadas_dia']  = $cuenta(86400);
        $bloqueos = [];
        if ($r['usadas_hora'] >= $max_h) $bloqueos[] = [$espera(3600, $r['usadas_hora'], $max_h), "límite por hora ({$r['usadas_hora']} de {$max_h})"];
        if ($r['usadas_dia']  >= $max_d) $bloqueos[] = [$espera(86400, $r['usadas_dia'], $max_d), "límite por día ({$r['usadas_dia']} de {$max_d})"];
        if ($bloqueos) {
            usort($bloqueos, fn($a, $b) => $b[0] <=> $a[0]);   // si hay dos, manda el que tarda más en liberarse
            $r['ok'] = false;
            $r['reintentar_en'] = $bloqueos[0][0];
            $r['motivo'] = 'Alcanzaste el ' . $bloqueos[0][1] . '. Intenta de nuevo en ' . ($bloqueos[0][0] >= 3600 ? ceil($bloqueos[0][0] / 3600) . ' h' : ceil($bloqueos[0][0] / 60) . ' min') . '.';
        }
    } catch (\Throwable $e) {
        $r['degradado'] = true;      // sin tabla de log: no se bloquea a nadie
        $r['ok'] = true;
    }
    return $r;
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
    $llamadas = 0;     // llamadas HTTP reales hechas
    $omitidos = 0;     // proveedores saltados por tope local o enfriamiento (Lote 5)

    foreach ($orden as $prov) {
        $modelo = nb_ia_modelo($prov);
        if (nb_ia_clave($prov) === '') {
            $intentos[] = ['proveedor' => $prov, 'ok' => false, 'http' => null, 'ms' => 0, 'error' => 'sin clave configurada (omitido)'];
            continue;
        }

        $prompt_actual = $prompt;
        $max = ($json && $reparar) ? 2 : 1;
        for ($i = 1; $i <= $max; $i++) {
            // Lote 5: tope local por minuto/día y enfriamiento tras 429/503. Un proveedor saltado NO se registra en el log
            // (no hubo llamada) y la cadena sigue con el siguiente.
            $disp = nb_ia_proveedor_disponible($conn, $prov);
            if (!$disp['ok']) {
                $omitidos++;
                $ultimo_error = "{$prov}: {$disp['motivo']}";
                $intentos[] = ['proveedor' => $prov, 'ok' => false, 'http' => null, 'ms' => 0, 'error' => 'omitido: ' . $disp['motivo']];
                break;
            }

            $restante = $presup - (microtime(true) - $inicio);
            if ($restante < 2) {
                $ultimo_error = 'Se agotó el tiempo total disponible para la IA';
                $intentos[] = ['proveedor' => $prov, 'ok' => false, 'http' => null, 'ms' => 0, 'error' => 'presupuesto de tiempo agotado'];
                break 2;
            }
            $t = (int)max(2, min($timeout, floor($restante)));

            $r = nb_ia_llamar_proveedor($prov, $prompt_actual, $opts, $t);
            $llamadas++;

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
            if (in_array((int)($r['http'] ?? 0), [429, 503], true)) {   // el proveedor se está limitando: dejarlo descansar para todos
                nb_ia_marcar_enfriamiento($conn, $prov, (int)IA_ENFRIAMIENTO_MINUTOS, 'HTTP ' . (int)$r['http']);
            }
            $intentos[] =['proveedor' => $prov, 'ok' => $ok_final, 'http' => $r['http'] ?? null, 'ms' => $r['ms'] ?? 0, 'error' => $err];

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

    if ($llamadas === 0 && $omitidos > 0) {
        $ultimo_error = 'Todos los proveedores de IA están en su tope local o en enfriamiento. Reintenta en unos minutos.';
    }
    return ['ok' => false, 'error' => $ultimo_error, 'intentos' => $intentos];
}
