<?php
if (!function_exists('geoip_lookup')) {
    function geoip_lookup(string $ip): array {
        $null = ['pais' => null, 'ciudad' => null];

        // IPs locales/privadas: no llamar al API
        if ($ip === '127.0.0.1' || $ip === '::1') return $null;
        if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $ip)) return $null;

        try {
            $ctx = stream_context_create(['http' => [
                'timeout' => 2,
                'ignore_errors' => true,
            ]]);
            $url  = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,city';
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) return $null;

            $data = json_decode($body, true);
            if (!is_array($data) || ($data['status'] ?? '') !== 'success') return $null;

            return [
                'pais'   => isset($data['country']) ? substr((string)$data['country'], 0, 60) : null,
                'ciudad' => isset($data['city'])    ? substr((string)$data['city'],    0, 80) : null,
            ];
        } catch (\Throwable $e) {
            return $null;
        }
    }
}
