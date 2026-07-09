<?php
// Rate-limit y placeholder para endpoints de imágenes generadas por GD (img_novedad.php,
// y futuros generadores de imagen si se agregan). Extraído para no duplicar código dentro
// de cada endpoint. NO lo usa img_servicio.php a propósito — ese endpoint ya está en
// producción con su propia copia verificada, no se toca ni se hace depender de este archivo.

if (!function_exists('nb_servir_placeholder_novedad')) {
    function nb_servir_placeholder_novedad(int $code = 404): void {
        http_response_code($code);
        header('Content-Type: image/jpeg');
        header('Cache-Control: no-store');
        $ph = ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2)) . '/upload/compartir/placeholder.jpg';
        if (is_file($ph)) {
            readfile($ph);
        } else {
            $img = imagecreatetruecolor(1080, 1080);
            $bg = imagecolorallocate($img, 240, 246, 250);
            imagefilledrectangle($img, 0, 0, 1080, 1080, $bg);
            imagejpeg($img, null, 85);
            imagedestroy($img);
        }
        exit;
    }
}

if (!function_exists('check_img_novedad_rate_limit')) {
    // Mismo patrón/parámetros que check_img_servicio_rate_limit() de img_servicio.php,
    // pero tabla independiente (img_novedad_rate_limit) — copia deliberada, no reutilización,
    // para no acoplar este endpoint nuevo con uno que ya está verificado en producción.
    function check_img_novedad_rate_limit(mysqli $conn): void {
        if (($_SESSION['rol'] ?? '') === 'admin') return;

        $conn->query("CREATE TABLE IF NOT EXISTS img_novedad_rate_limit (
            ip VARCHAR(45) NOT NULL PRIMARY KEY,
            contador INT NOT NULL DEFAULT 1,
            ventana_inicio INT NOT NULL,
            bloqueado_hasta INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ahora = time();
        $limit_requests = 40;
        $limit_window = 60;
        $bloqueo_duracion = 300;

        $stmt = $conn->prepare("INSERT INTO img_novedad_rate_limit (ip, contador, ventana_inicio) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE ip = ip");
        $stmt->bind_param("si", $ip, $ahora);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("SELECT contador, ventana_inicio, bloqueado_hasta FROM img_novedad_rate_limit WHERE ip = ? LIMIT 1");
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!empty($row['bloqueado_hasta']) && $row['bloqueado_hasta'] > $ahora) {
            nb_servir_placeholder_novedad(429);
        }

        $tiempo_en_ventana = $ahora - (int)$row['ventana_inicio'];

        if ($tiempo_en_ventana < $limit_window) {
            $nuevo_contador = (int)$row['contador'] + 1;

            if ($nuevo_contador > $limit_requests) {
                $hasta = $ahora + $bloqueo_duracion;
                $stmt = $conn->prepare("UPDATE img_novedad_rate_limit SET contador = ?, bloqueado_hasta = ? WHERE ip = ?");
                $stmt->bind_param("iis", $nuevo_contador, $hasta, $ip);
                $stmt->execute();
                $stmt->close();
                nb_servir_placeholder_novedad(429);
            }

            $stmt = $conn->prepare("UPDATE img_novedad_rate_limit SET contador = ? WHERE ip = ?");
            $stmt->bind_param("is", $nuevo_contador, $ip);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("UPDATE img_novedad_rate_limit SET contador = 1, ventana_inicio = ?, bloqueado_hasta = NULL WHERE ip = ?");
            $stmt->bind_param("is", $ahora, $ip);
            $stmt->execute();
            $stmt->close();
        }
    }
}
