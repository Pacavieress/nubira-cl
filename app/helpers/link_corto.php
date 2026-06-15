<?php
// Helper de links cortos de compartir. Un código por servicio (reusable).
require_once __DIR__ . '/../conexion.php';

if (!function_exists('generar_link_corto')) {
    function generar_link_corto(int $servicio_id): string {
        global $conn;

        // 1) ¿Ya tiene código este servicio? → reusar
        $stmt = $conn->prepare("SELECT codigo FROM links_cortos WHERE servicio_id = ? LIMIT 1");
        $stmt->bind_param('i', $servicio_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) return $existing['codigo'];

        // 2) Generar código random 6 chars (base62), reintentando si colisiona
        $alfabeto = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $codigo = '';
        for ($intento = 0; $intento < 8; $intento++) {
            $codigo = '';
            for ($i = 0; $i < 6; $i++) $codigo .= $alfabeto[random_int(0, 61)];

            $ins = $conn->prepare("INSERT IGNORE INTO links_cortos (codigo, servicio_id) VALUES (?, ?)");
            $ins->bind_param('si', $codigo, $servicio_id);
            $ins->execute();
            $ok = $ins->affected_rows > 0;
            $ins->close();
            if ($ok) return $codigo;
            // affected_rows = 0 → colisión de UNIQUE: reintentar
        }
        // Fallback improbable: devolver el último código generado
        return $codigo;
    }
}

if (!function_exists('url_corta')) {
    function url_corta(int $servicio_id): string {
        return 'https://nubira.cl/r/' . generar_link_corto($servicio_id);
    }
}
