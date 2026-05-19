<?php
// RUTA: app/datos/log_training.php
session_start();

// 1. Seguridad: Solo usuarios logueados de Nubira pueden reportar
if (!isset($_SESSION['usuario_id'])) { 
    http_response_code(403); 
    exit; 
}

// 2. Procesar el reporte
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Leer el JSON enviado por el JS
    $data = json_decode(file_get_contents('php://input'), true);
    $titulo = trim($data['titulo'] ?? '');
    
    // Filtros básicos antispam
    if (!empty($titulo) && strlen($titulo) < 100) {
        
        // Archivo de registro en la misma carpeta 'datos'
        $file = __DIR__ . '/keywords_faltantes.log';
        
        // Formato: FECHA - USUARIO - TÍTULO NO RECONOCIDO
        $entry = sprintf(
            "[%s] UserID: %d - Título: %s" . PHP_EOL,
            date('Y-m-d H:i:s'),
            $_SESSION['usuario_id'],
            $titulo
        );
        
        // Escribir al final del archivo (FILE_APPEND)
        file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
    }
}
?>