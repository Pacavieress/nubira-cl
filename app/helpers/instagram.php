<?php
/**
 * NUBIRA 2.0 — HELPER INSTAGRAM GRAPH API (Fase 2 Copiloto de Marketing)
 *
 * Cuenta oficial de Nubira en Instagram, vía Instagram Login
 * (graph.instagram.com — NO graph.facebook.com). Token de larga duración en
 * IG_ACCESS_TOKEN (.env) y cuenta en IG_ACCOUNT_ID (.env), ambas cargadas
 * como constantes en config.php.
 */

require_once __DIR__ . '/../config.php';

define('NB_IG_API_VERSION', 'v21.0');
define('NB_IG_BASE_URL', 'https://graph.instagram.com/' . NB_IG_API_VERSION);

/**
 * GET genérico a graph.instagram.com/v21.0/{path}. Agrega access_token
 * automáticamente. Nunca lanza fatal — cualquier fallo (HTTP, cURL, error
 * de la API) vuelve como ['ok'=>false, 'error'=>...].
 *
 * @return array{ok:bool, data?:array, error?:string, http_code?:int}
 */
function nb_ig_get(string $path, array $params = []): array {
    if (!defined('IG_ACCESS_TOKEN') || IG_ACCESS_TOKEN === '') {
        return ['ok' => false, 'error' => 'IG_ACCESS_TOKEN no configurada'];
    }

    $params['access_token'] = IG_ACCESS_TOKEN;
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
