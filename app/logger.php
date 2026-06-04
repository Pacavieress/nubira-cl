<?php
// app/logger.php
if (!function_exists('es_bot_user_agent')) {
    /**
     * Detecta si un User-Agent corresponde a un bot, crawler o scraper conocido.
     * Patrón conservador: solo marca como bot si el UA lo declara explícitamente.
     */
    function es_bot_user_agent(string $ua): bool {
        if (empty($ua)) return true; // Sin UA = casi siempre bot/curl/script
        $ua_lower = strtolower($ua);
        $patron = '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|whatsapp|telegram|linkedinbot|googlebot|ahrefs|semrush|mj12bot|dotbot|petalbot|yandex|baidu|duckduckbot|applebot|headlesschrome|phantomjs|puppeteer|playwright|python-requests|curl\/|wget\/|http_request|scrapy/i';
        return (bool) preg_match($patron, $ua_lower);
    }
}

if (!function_exists('registrar_actividad')) {
    function registrar_actividad($conn, $usuario_id, $accion, $detalle = '') {
        try {
            if (!$conn || $conn->connect_error) {
                file_put_contents(__DIR__ . '/_logger_error.txt', "Sin conexion\n", FILE_APPEND);
                return;
            }
            $url = $_SERVER['REQUEST_URI'] ?? '/';
            $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

            // Si el usuario es 0 o null, intentamos sacarlo de la sesión
            if (empty($usuario_id) && isset($_SESSION['usuario_id'])) {
                $usuario_id = $_SESSION['usuario_id'];
            }
            if (empty($usuario_id)) $usuario_id = NULL;

            // [NUBIRA 2.0] Detección de bot al momento del INSERT
            $es_bot = es_bot_user_agent($ua) ? 1 : 0;

            $ref_raw = $_SERVER['HTTP_REFERER'] ?? null;
            $referrer = $ref_raw ? substr($ref_raw, 0, 255) : null;
            $utm_raw = $_GET['utm_source'] ?? null;
            $utm_source = ($utm_raw !== null && $utm_raw !== '') ? substr($utm_raw, 0, 100) : null;

            // Intenta INSERT con columnas extendidas; fallback si aún no existen
            $stmt = $conn->prepare("INSERT INTO historial_actividad
                (usuario_id, accion, detalle, url, ip_usuario, es_bot, user_agent, referrer, utm_source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("issssisss", $usuario_id, $accion, $detalle, $url, $ip, $es_bot, $ua, $referrer, $utm_source);
                $stmt->execute();
                $stmt->close();
            } else {
                // Columnas aún no creadas — fallback al INSERT base
                $stmt2 = $conn->prepare("INSERT INTO historial_actividad
                    (usuario_id, accion, detalle, url, ip_usuario, es_bot, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt2->bind_param("issssis", $usuario_id, $accion, $detalle, $url, $ip, $es_bot, $ua);
                $stmt2->execute();
                $stmt2->close();
            }
} catch (\Throwable $e) {
            // Silencioso: el log nunca debe romper la experiencia del usuario
        }
    }
}
// file_put_contents(__DIR__ . '/_logger_sonda.txt', date('Y-m-d H:i:s') . " | UA=" . ($_SERVER['HTTP_USER_AGENT'] ?? 'NONE') . "\n", FILE_APPEND);
?>