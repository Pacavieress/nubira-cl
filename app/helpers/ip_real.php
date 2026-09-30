<?php
/**
 * ip_real() — IP real del visitante cuando PHP está detrás de un proxy (Nginx del VPS o el
 * proxy de Next). Sin esto, REMOTE_ADDR es siempre la IP del proxy y todo lo que dependa de la
 * IP (límites, bloqueos, huellas de visitante, logs) ve a todos los visitantes como uno solo.
 *
 * Misma regla de confianza que app/crear_cuenta_express.php: X-Forwarded-For solo se respeta si
 *   (a) REMOTE_ADDR está en la lista de proxies de confianza, o
 *   (b) X-Nubira-Proxy-Key coincide (hash_equals) con PROXY_SHARED_SECRET, que no puede estar vacío.
 * En cualquier otro caso se devuelve REMOTE_ADDR: un cliente cualquiera que mande la cabecera
 * por su cuenta no cambia su IP. El proxy debe SOBRESCRIBIR X-Forwarded-For con la IP real del
 * visitante, no agregarla a una que traiga el cliente (se toma la primera IP válida).
 *
 * PROXY_SHARED_SECRET viene del .env (cargado con env_loader.php, igual que config.php).
 * No define nada al incluirse: solo declara la función.
 */
require_once __DIR__ . '/../env_loader.php';

if (!function_exists('ip_real')) {
    function ip_real(): string
    {
        static $resuelta = null;
        if ($resuelta !== null) {
            return $resuelta;
        }

        // 127.0.0.1 / ::1 = desarrollo local. 187.127.58.175 = servidor de Next / Nginx del VPS
        // en producción. Mantener igual a $proxies_confiables de crear_cuenta_express.php.
        $proxies_confiables = ['127.0.0.1', '::1', '187.127.58.175'];

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $secreto = (string)(getenv('PROXY_SHARED_SECRET') ?: '');
        $proxy_por_secreto = $secreto !== ''
            && hash_equals($secreto, (string)($_SERVER['HTTP_X_NUBIRA_PROXY_KEY'] ?? ''));

        if ((in_array($ip, $proxies_confiables, true) || $proxy_por_secreto) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $candidata) {
                $candidata = trim($candidata);
                if (filter_var($candidata, FILTER_VALIDATE_IP)) {
                    $ip = $candidata;
                    break;
                }
            }
        }

        return $resuelta = $ip;
    }
}
