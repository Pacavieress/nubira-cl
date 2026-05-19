<?php
/**
 * NUBIRA 2.0 - SISTEMA DE APRENDIZAJE AUTOMÁTICO
 * Este archivo procesa términos desconocidos enviados por la IA del frontend
 * y los aprueba automáticamente tras alcanzar un umbral de frecuencia.
 */

session_start();

// 1. SEGURIDAD: Solo usuarios logueados pueden nutrir el cerebro
if (!isset($_SESSION['usuario_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// 2. CONEXIÓN (Ruta absoluta desde la raíz de app)
require_once __DIR__ . '/conexion.php';

// 3. CONFIGURACIÓN DEL CEREBRO
$umbral_auto_aprobacion = 10; // Veces que debe aparecer un término para ser oficial
$respuesta = ['status' => 'ignore', 'processed' => 0];

// 4. PROCESAMIENTO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['terminos'])) {
    
    $terminos = $_POST['terminos']; // Array enviado por el fetch de JS
    $procesados = 0;

    if (is_array($terminos)) {
        foreach ($terminos as $t) {
            // Sanitización básica: minúsculas, sin espacios extra y largo mínimo
            $t = strtolower(trim($t));
            if (strlen($t) < 4 || is_numeric($t)) continue;

            // Lógica 1: Insertar o subir frecuencia (Upsert)
            $stmt = $conn->prepare("
                INSERT INTO nubira_brain (categoria, keyword, es_oficial, frecuencia, estado) 
                VALUES ('general', ?, 0, 1, 'pendiente') 
                ON DUPLICATE KEY UPDATE frecuencia = frecuencia + 1
            ");
            $stmt->bind_param("s", $t);
            $stmt->execute();
            $stmt->close();

            // Lógica 2: Auto-aprobación si supera el umbral
            $stmt_check = $conn->prepare("
                UPDATE nubira_brain 
                SET estado = 'aprobado' 
                WHERE keyword = ? AND frecuencia >= ? AND estado = 'pendiente'
            ");
            $stmt_check->bind_param("si", $t, $umbral_auto_aprobacion);
            $stmt_check->execute();
            $stmt_check->close();

            $procesados++;
        }
        $respuesta = ['status' => 'ok', 'processed' => $procesados];
    }
}

// 5. RESPUESTA SILENCIOSA (Estilo API)
header('Content-Type: application/json');
echo json_encode($respuesta);