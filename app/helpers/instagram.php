<?php
/**
 * NUBIRA 2.0 — HELPER INSTAGRAM GRAPH API (Fase 2 Copiloto de Marketing)
 *
 * Cuenta oficial de Nubira en Instagram, vía Instagram Login
 * (graph.instagram.com — NO graph.facebook.com). Cuenta en IG_ACCOUNT_ID
 * (.env), cargada como constante en config.php.
 *
 * TOKEN — IMPORTANTE, LEER ANTES DE TOCAR IG_ACCESS_TOKEN:
 * IG_ACCESS_TOKEN (.env) es SOLO la semilla de arranque (bootstrap), usada
 * una única vez si la tabla `copiloto_ig_token` todavía está vacía. Desde
 * que `cron/copiloto_ig_refresh.php` hace su primer refresh exitoso, la
 * ÚNICA fuente de verdad del token vigente es esa tabla — el valor de .env
 * queda obsoleto y NO se actualiza nunca más. Si Instagram empieza a fallar
 * y vas a mirar el token, mira la tabla `copiloto_ig_token`, no el .env.
 * Ver nb_ig_obtener_token() más abajo.
 */

require_once __DIR__ . '/../config.php';

define('NB_IG_API_VERSION', 'v21.0');
define('NB_IG_BASE_URL', 'https://graph.instagram.com/' . NB_IG_API_VERSION);

/**
 * Resuelve el token de Instagram vigente: primero `copiloto_ig_token`
 * (fuente de verdad tras el primer refresh), y solo si esa tabla está
 * vacía o inaccesible, cae a IG_ACCESS_TOKEN (.env) como semilla inicial.
 * Auto-migración de la tabla acá también — mismo criterio que el resto del
 * Copiloto: nunca asumir que otro archivo (el cron de refresh) ya corrió.
 * Cacheado en un static: un solo cron run puede llamar a nb_ig_get() más de
 * 10 veces (perfil + insights + hasta 12 posts) y no hace falta re-consultar
 * la tabla cada vez.
 */
function nb_ig_obtener_token(): string {
    static $token_cache = null;
    if ($token_cache !== null) return $token_cache;

    global $conn;

    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS copiloto_ig_token (
                id INT PRIMARY KEY,
                token VARCHAR(255) NOT NULL,
                actualizado_en DATETIME NOT NULL,
                expira_estimado_en DATETIME NOT NULL,
                ultimo_error TEXT NULL,
                intentos_fallidos INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $res = $conn->query("SELECT token FROM copiloto_ig_token WHERE id = 1 LIMIT 1");
            $row = $res ? $res->fetch_assoc() : null;
            if ($row && !empty($row['token'])) {
                $token_cache = $row['token'];
                return $token_cache;
            }
        } catch (Throwable $e) {
            // Best-effort: si la tabla falla por lo que sea, cae a la semilla de .env.
        }
    }

    // BOOTSTRAP: tabla vacía (primera vez, antes del primer refresh) o sin
    // conexión disponible — usar la semilla de .env.
    $token_cache = (defined('IG_ACCESS_TOKEN') && IG_ACCESS_TOKEN !== '') ? IG_ACCESS_TOKEN : '';
    return $token_cache;
}

/**
 * GET genérico a graph.instagram.com/v21.0/{path}. Agrega access_token
 * automáticamente. Nunca lanza fatal — cualquier fallo (HTTP, cURL, error
 * de la API) vuelve como ['ok'=>false, 'error'=>...].
 *
 * @return array{ok:bool, data?:array, error?:string, http_code?:int}
 */
function nb_ig_get(string $path, array $params = []): array {
    $token = nb_ig_obtener_token();
    if ($token === '') {
        return ['ok' => false, 'error' => 'Token de Instagram no configurado (ni en copiloto_ig_token ni en IG_ACCESS_TOKEN)'];
    }

    $params['access_token'] = $token;
    $url = NB_IG_BASE_URL . '/' . ltrim($path, '/') . '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // sí valida certificado
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['ok' => false, 'error' => "cURL: {$curl_error}"];
    }

    $decoded = json_decode((string)$response, true);

    if ($http_code !== 200 || !is_array($decoded)) {
        $msg = $decoded['error']['message'] ?? "HTTP {$http_code}";
        return ['ok' => false, 'error' => $msg, 'http_code' => $http_code];
    }

    if (isset($decoded['error'])) {
        return ['ok' => false, 'error' => $decoded['error']['message'] ?? 'Error de la API de Instagram', 'http_code' => $http_code];
    }

    return ['ok' => true, 'data' => $decoded];
}

/**
 * Datos básicos de la cuenta: username, name, biography, followers_count, media_count.
 */
function nb_ig_perfil(): array {
    return nb_ig_get(IG_ACCOUNT_ID, [
        'fields' => 'username,name,biography,followers_count,media_count',
    ]);
}

/**
 * Insights de cuenta — SOLO reach (period=day). OJO: el conteo de
 * seguidores vía insights (metric=follower_count) devolvió 0 en las
 * pruebas manuales — para seguidores reales usar SIEMPRE
 * nb_ig_perfil()['data']['followers_count'], nunca este endpoint.
 */
function nb_ig_insights_cuenta(): array {
    $res = nb_ig_get(IG_ACCOUNT_ID . '/insights', [
        'metric' => 'reach',
        'period' => 'day',
    ]);

    // Algunas métricas de este endpoint exigen metric_type=total_value en
    // vez de time series — si la llamada de arriba falla, reintenta así
    // antes de rendirse.
    if (!$res['ok']) {
        $res = nb_ig_get(IG_ACCOUNT_ID . '/insights', [
            'metric'      => 'reach',
            'period'      => 'day',
            'metric_type' => 'total_value',
        ]);
    }

    return $res;
}

/**
 * Insights de cuenta — profile_views y website_clicks. A diferencia de
 * reach (time-series), estas 2 son métricas metric_type=total_value y NO
 * se pueden combinar con reach en la misma llamada — por eso viven en su
 * propia función, aparte de nb_ig_insights_cuenta(). Confirmado en vivo
 * contra v21.0 (2026-09): ambas siguen soportadas con este nombre y este
 * metric_type, sin necesidad de profile_links_taps/breakdown.
 *
 * Best-effort real: si la llamada falla, o si alguna de las 2 métricas no
 * viene en la respuesta, esa métrica vuelve como null — nunca lanza, el
 * caller debe tratar cada valor null como "sin dato", no como fatal.
 *
 * @return array{ok:bool, profile_views:?int, website_clicks:?int, error?:string}
 */
function nb_ig_insights_clicks_perfil(): array {
    $res = nb_ig_get(IG_ACCOUNT_ID . '/insights', [
        'metric'      => 'profile_views,website_clicks',
        'metric_type' => 'total_value',
        'period'      => 'day',
    ]);

    if (!$res['ok']) {
        return ['ok' => false, 'profile_views' => null, 'website_clicks' => null, 'error' => $res['error'] ?? 'Fallo desconocido'];
    }

    $profile_views  = null;
    $website_clicks = null;

    foreach (($res['data']['data'] ?? []) as $metrica) {
        $valor = $metrica['total_value']['value'] ?? null;
        if (($metrica['name'] ?? '') === 'profile_views') {
            $profile_views = $valor !== null ? (int)$valor : null;
        } elseif (($metrica['name'] ?? '') === 'website_clicks') {
            $website_clicks = $valor !== null ? (int)$valor : null;
        }
    }

    return ['ok' => true, 'profile_views' => $profile_views, 'website_clicks' => $website_clicks];
}

/**
 * Últimas $limite publicaciones: id, caption, media_type, timestamp,
 * permalink, like_count, comments_count.
 */
function nb_ig_media_reciente(int $limite = 12): array {
    return nb_ig_get(IG_ACCOUNT_ID . '/media', [
        'fields' => 'id,caption,media_type,timestamp,permalink,like_count,comments_count',
        'limit'  => $limite,
    ]);
}

/**
 * Insights de UNA publicación (reach, saved). No todas las media soportan
 * las mismas métricas (reels vs. imágenes vs. carruseles) — el caller debe
 * tratar un ok=false acá como "sin dato para este post", no como fatal.
 */
function nb_ig_insights_media(string $media_id): array {
    return nb_ig_get($media_id . '/insights', [
        'metric' => 'reach,saved',
    ]);
}

/**
 * Refresca un token de larga duración de Instagram Login (endpoint propio,
 * SIN el prefijo de versión de NB_IG_BASE_URL — Meta expone este endpoint
 * en la raíz de graph.instagram.com, no bajo /v21.0/). Requiere que el
 * token tenga al menos 24h de antigüedad desde que se emitió o refrescó por
 * última vez, y que todavía no haya expirado — si ya expiró, esto no sirve,
 * hay que rehacer el flujo de autorización OAuth completo a mano.
 *
 * Usada exclusivamente por cron/copiloto_ig_refresh.php — no la use
 * nb_ig_get() ni ninguna otra función de este archivo.
 *
 * @return array{ok:bool, access_token?:string, expires_in?:int, error?:string}
 */
function nb_ig_refrescar_token(string $token_actual): array {
    $url = 'https://graph.instagram.com/refresh_access_token?' . http_build_query([
        'grant_type'   => 'ig_refresh_token',
        'access_token' => $token_actual,
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // sí valida certificado
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['ok' => false, 'error' => "cURL: {$curl_error}"];
    }

    $decoded = json_decode((string)$response, true);

    if ($http_code !== 200 || !is_array($decoded) || empty($decoded['access_token'])) {
        $msg = $decoded['error']['message'] ?? $decoded['error_message'] ?? "HTTP {$http_code}";
        return ['ok' => false, 'error' => $msg];
    }

    return [
        'ok'           => true,
        'access_token' => $decoded['access_token'],
        // Meta devuelve expires_in en segundos (normalmente ~60 días); si algún
        // día no viniera, 60 días fijos es el valor documentado por Meta.
        'expires_in'   => (int)($decoded['expires_in'] ?? (60 * 86400)),
    ];
}
