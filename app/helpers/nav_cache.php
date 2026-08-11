<?php
// app/helpers/nav_cache.php
// [NUBIRA 2.0] Caché de alertas de nav (perfil incompleto / foto / banco) en archivo,
// no en $_SESSION — permite liberar la sesión temprano (session_write_close()) sin
// perder el caché de 5 min compartido entre header.php y nav_bottom.php.

define('NAV_CACHE_DIR', __DIR__ . '/../cache_nav');
define('NAV_CACHE_TTL', 300); // 5 minutos, igual que antes

function nb_nav_cache_ruta(int $uid): string {
    return NAV_CACHE_DIR . '/nav_' . $uid . '.json';
}

function nb_nav_cache_get(int $uid): ?array {
    $ruta = nb_nav_cache_ruta($uid);
    if (!file_exists($ruta) || (time() - filemtime($ruta)) > NAV_CACHE_TTL) {
        return null;
    }
    $data = json_decode(file_get_contents($ruta), true);
    return is_array($data) ? $data : null;
}

function nb_nav_cache_set(int $uid, array $data): void {
    if (!is_dir(NAV_CACHE_DIR)) @mkdir(NAV_CACHE_DIR, 0775, true);
    // [NUBIRA 2.0] Escritura atómica: escribe a un archivo temporal único por proceso
    // (uniqid evita que 2 requests concurrentes del mismo usuario compartan el mismo
    // .tmp) y recién al final hace rename() al nombre real. rename() es atómico a nivel
    // de sistema de archivos — cualquier lector ve el archivo viejo completo o el nuevo
    // completo, nunca un estado a medio truncar/escribir.
    $ruta = nb_nav_cache_ruta($uid);
    $tmp  = $ruta . '.' . uniqid('', true) . '.tmp';
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE)) !== false) {
        @rename($tmp, $ruta);
    } else {
        @unlink($tmp);
    }
}

function nb_nav_cache_invalidar(int $uid): void {
    $ruta = nb_nav_cache_ruta($uid);
    if (file_exists($ruta)) @unlink($ruta);
}
