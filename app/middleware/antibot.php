<?php
// ==========================================
// NUBIRA SHIELD - MIDDLEWARE ANTI-BOT
// ==========================================

if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();

function check_nubira_shield($conn, array $opts = []) {
    // 1. IPs en lista blanca (No bloquear nunca)
    $whitelist_ips = ['127.0.0.1', '::1']; // Localhost
    $ip_usuario = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (in_array($ip_usuario, $whitelist_ips)) {
        return true; // Pase libre
    }

    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // 2. Bots buenos permitidos (Indexadores)
    $good_bots = ['Googlebot', 'Bingbot', 'Slurp', 'DuckDuckBot', 'Baiduspider', 'Google-InspectionTool'];
    foreach ($good_bots as $bot) {
        if (stripos($user_agent, $bot) !== false) {
            return true; // Permitir indexación SEO
        }
    }

    // 3. Bloqueo de Scrapers y Herramientas (Los "Metiches")
// Lista ampliada de User-Agents de scrapers, librerías HTTP y herramientas
    $bad_agents = [
        // Librerías Python
        'python-requests', 'python-urllib', 'aiohttp', 'httpx', 'mechanize',
        // Librerías de otros lenguajes
        'curl', 'wget', 'libwww-perl', 'go-http-client', 'java/', 
        'okhttp', 'apache-httpclient', 'node-fetch', 'axios', 'got (',
        // Frameworks de scraping
        'scrapy', 'crawler', 'spider', 'scraperapi',
        // Herramientas de testing/dev
        'postman', 'insomnia', 'paw/', 'httpie',
        // Headless browsers (automatización)
        'phantomjs', 'headlesschrome', 'selenium', 'puppeteer', 'playwright',
        // Bots genéricos sospechosos
        'bot/', 'crawl', 'fetch', 'scan'
    ];
    foreach ($bad_agents as $bad) {
        if (stripos($user_agent, $bad) !== false) {
            registrar_bloqueo($conn, $ip_usuario, 'User-Agent malicioso (' . $bad . ')', $user_agent);
            terminar_peticion_bloqueada();
        }
    }
    // 3.1 Bloqueo de User-Agents vacíos o sospechosamente cortos
    // Navegadores reales tienen UAs de 80-200+ caracteres. UAs vacíos o de <20 chars son scrapers mal configurados.
    if (empty(trim($user_agent))) {
        registrar_bloqueo($conn, $ip_usuario, 'User-Agent vacío', '(sin UA)');
        terminar_peticion_bloqueada();
    }
    
    if (strlen($user_agent) < 20) {
        registrar_bloqueo($conn, $ip_usuario, 'User-Agent sospechosamente corto (' . strlen($user_agent) . ' chars)', $user_agent);
        terminar_peticion_bloqueada();
    }

// 4. Rate Limiting por IP (BD - resistente a bots sin cookies)
    // Doble umbral: invitados estricto, logueados permisivo
    $esta_logueado = isset($_SESSION['usuario_id']) && $_SESSION['usuario_id'] > 0;

    // [NUBIRA] Límite configurable por llamador (ej. buscador, con público
    // universitario detrás de NAT compartido). Sin $opts, es IDÉNTICO a antes.
    $limit_requests   = $esta_logueado
        ? ($opts['limit_logueado'] ?? 300)
        : ($opts['limit_invitado'] ?? 90);
    $limit_window     = 60;                          // Ventana de 60 segundos
    $bloqueo_duracion = $esta_logueado ? 300 : 600;  // Logueados 5min, invitados 10min
    $ahora = time();
    
    // 4.1 ¿Está actualmente bloqueada esta IP?
    $stmt = $conn->prepare("SELECT contador, ventana_inicio, bloqueado_hasta FROM shield_rate_limit WHERE ip = ? LIMIT 1");
    $stmt->bind_param("s", $ip_usuario);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($row) {
        // IP bloqueada y aún no expira el baneo → cortar
        if (!empty($row['bloqueado_hasta']) && $row['bloqueado_hasta'] > $ahora) {
            terminar_peticion_bloqueada(429);
        }
        
        $tiempo_en_ventana = $ahora - (int)$row['ventana_inicio'];
        
        if ($tiempo_en_ventana < $limit_window) {
            // Dentro de la ventana → incrementar contador
            $nuevo_contador = (int)$row['contador'] + 1;
            
            if ($nuevo_contador > $limit_requests) {
                // Excedió el límite → bloquear temporalmente
                $hasta = $ahora + $bloqueo_duracion;
                $stmt = $conn->prepare("UPDATE shield_rate_limit SET contador = ?, bloqueado_hasta = ? WHERE ip = ?");
                $stmt->bind_param("iis", $nuevo_contador, $hasta, $ip_usuario);
                $stmt->execute();
                $stmt->close();
                
                $tipo = $esta_logueado ? 'LOGUEADO' : 'INVITADO';
                registrar_bloqueo($conn, $ip_usuario, "Rate Limit [{$tipo}] ({$nuevo_contador} req/{$limit_window}s)", $user_agent);
                terminar_peticion_bloqueada(429);
            }
            
            // Aún dentro del límite → solo actualizar contador
            $stmt = $conn->prepare("UPDATE shield_rate_limit SET contador = ? WHERE ip = ?");
            $stmt->bind_param("is", $nuevo_contador, $ip_usuario);
            $stmt->execute();
            $stmt->close();
        } else {
            // Ventana expiró → reiniciar contador y limpiar bloqueo
            $stmt = $conn->prepare("UPDATE shield_rate_limit SET contador = 1, ventana_inicio = ?, bloqueado_hasta = NULL WHERE ip = ?");
            $stmt->bind_param("is", $ahora, $ip_usuario);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        // Primera vez que vemos esta IP
        $stmt = $conn->prepare("INSERT INTO shield_rate_limit (ip, contador, ventana_inicio) VALUES (?, 1, ?)");
        $stmt->bind_param("si", $ip_usuario, $ahora);
        $stmt->execute();
        $stmt->close();
    }
    
    return true; // Si pasó todo, es tráfico normal
}

function registrar_bloqueo($conn, $ip, $motivo, $ua) {
    if (!$conn || $conn->connect_error) return;
    
    $accion = 'BLOQUEO_SHIELD';
    $url = $_SERVER['REQUEST_URI'] ?? '/';
    $fecha = date('Y-m-d H:i:s');
    
    // Lo registramos en historial_actividad para verlo en el monitor
    $stmt = $conn->prepare("INSERT INTO historial_actividad (ip_usuario, accion, detalle, url, fecha) VALUES (?, ?, ?, ?, ?)");
    if($stmt){
        $detalle = "Motivo: $motivo | UA: " . substr($ua, 0, 100);
        $stmt->bind_param("sssss", $ip, $accion, $detalle, $url, $fecha);
        $stmt->execute();
        $stmt->close();
    }
}

function terminar_peticion_bloqueada($code = 403) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => true,
        'codigo' => $code,
        'mensaje' => 'Petición bloqueada por Nubira Shield. Si eres un estudiante, intenta nuevamente en unos minutos.'
    ]);
    exit;
}
?>