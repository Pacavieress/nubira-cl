<?php
// app/helpers/redir_seguro.php
// Validación compartida del parámetro `redir` de login.php — antes había 3 copias
// inline de la misma regla (captura GET, POST rama pendiente, POST rama login exitoso),
// divergiendo con el tiempo. Único punto de verdad ahora.

if (!function_exists('nb_redir_es_seguro')) {
    /**
     * Anti open-redirect: acepta rutas relativas al propio dominio (comportamiento
     * histórico) O URLs absolutas cuyo origin (scheme+host+puerto, comparación exacta,
     * nunca por prefijo) esté en NEXTJS_TRUSTED_ORIGINS (app/config.php) — necesario para
     * páginas que hoy viven exclusivamente en Next.js, sin ruta equivalente en .htaccess.
     */
    function nb_redir_es_seguro(string $redir): bool {
        if ($redir === '') return false;

        // Caso histórico: ruta relativa al propio dominio (rechaza protocolo-relativo //).
        if (strpos($redir, '/') === 0 && strpos($redir, '//') !== 0) return true;

        // Caso nuevo: URL absoluta hacia un origin de Next.js explícitamente confiado.
        $partes = parse_url($redir);
        if (!$partes || empty($partes['scheme']) || empty($partes['host'])) return false;

        $origin = $partes['scheme'] . '://' . $partes['host'] . (isset($partes['port']) ? ':' . $partes['port'] : '');
        $confiados = defined('NEXTJS_TRUSTED_ORIGINS') ? NEXTJS_TRUSTED_ORIGINS : [];
        return in_array($origin, $confiados, true);
    }
}
