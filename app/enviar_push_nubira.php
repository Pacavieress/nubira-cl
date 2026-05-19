<?php
/**
 * HELPER: Enviar Push Notification via OneSignal
 * UBICACIÓN: public_html/app/enviar_push_nubira.php
 * 
 * Uso:
 *   require_once __DIR__ . '/enviar_push_nubira.php';
 *   enviar_push_nubira($usuario_id_destino, "Título", "Mensaje del push", "/app/bandeja_entrada.php");
 */

require_once __DIR__ . '/onesignal_config.php';

if (!function_exists('enviar_push_nubira')) {
    
    /**
     * Envía una notificación push a un usuario de Nubira via OneSignal.
     *
     * @param int    $usuario_id  ID del usuario destino (el "externalId" en OneSignal)
     * @param string $titulo      Título de la notificación
     * @param string $mensaje     Cuerpo del mensaje
     * @param string $url         URL a la que lleva al clickear (opcional, default: /explorar)
     * @return array              ['success' => bool, 'response' => mixed, 'error' => string|null]
     */
    function enviar_push_nubira(int $usuario_id, string $titulo, string $mensaje, string $url = '/explorar'): array {
        
        if ($usuario_id <= 0 || empty($titulo) || empty($mensaje)) {
            return ['success' => false, 'error' => 'Parámetros inválidos', 'response' => null];
        }
        
        // URL absoluta obligatoria
        if (strpos($url, 'http') !== 0) {
            $url = 'https://nubira.cl' . (str_starts_with($url, '/') ? $url : '/' . $url);
        }
        
        // [NUBIRA 2.0] Payload OneSignal v11 - Formato estricto que SÍ funciona
        // Fix: include_external_user_ids es más robusto que include_aliases
        // Fix: isAnyWeb fuerza el canal web push
        // Fix: Forzamos string + array_unique para evitar IDs duplicados
        $payload = [
            'app_id'                      => ONESIGNAL_APP_ID,
            'include_external_user_ids'   => array_unique([(string)$usuario_id]),
            'channel_for_external_user_ids' => 'push',
            'isAnyWeb'                    => true,
            'headings'                    => ['en' => $titulo, 'es' => $titulo],
            'contents'                    => ['en' => $mensaje, 'es' => $mensaje],
            'url'                         => $url,
            'web_push_topic'              => 'nubira_msg_' . $usuario_id,
            'priority'                    => 10, // alta prioridad para mensajes
        ];
        
        $ch = curl_init('https://api.onesignal.com/notifications');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'Authorization: Key ' . ONESIGNAL_REST_API_KEY,
            ],
            CURLOPT_TIMEOUT        => 5, // max 5s para no colgar la request del usuario
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        
        $response_raw = curl_exec($ch);
        $http_code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err_curl     = curl_error($ch);
        curl_close($ch);
        
        // Log básico para debug
        $log_line = sprintf(
            "[%s] enviar_push_nubira user=%d http=%d curl_err=%s response=%s\n",
            date('Y-m-d H:i:s'),
            $usuario_id,
            $http_code,
            $err_curl ?: '-',
            substr($response_raw ?: '', 0, 500)
        );
        @file_put_contents(__DIR__ . '/../logs/push.log', $log_line, FILE_APPEND);
        
        if ($err_curl) {
            return ['success' => false, 'error' => 'cURL: ' . $err_curl, 'response' => null];
        }
        
        $response = json_decode($response_raw, true);
        
        if ($http_code >= 200 && $http_code < 300 && !empty($response['id'])) {
            return ['success' => true, 'response' => $response, 'error' => null];
        }
        
        $err_msg = $response['errors'] ?? "HTTP $http_code";
        return ['success' => false, 'error' => is_array($err_msg) ? json_encode($err_msg) : $err_msg, 'response' => $response];
    }
}