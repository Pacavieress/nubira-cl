<?php
/**
 * ENDPOINT: Obtener datos de una campaña para duplicar
 * ESTADO: BLINDADO (Solo admin)
 */
require_once __DIR__ . '/init_sesion.php';
header('Content-Type: application/json; charset=utf-8');

// Verificación de rol admin
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Acceso denegado.']);
    exit;
}

$campana_id = (int)($_GET['id'] ?? 0);
if ($campana_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID inválido.']);
    exit;
}

global $conn;

try {
    // 1. Traer datos de la campaña
    $stmt = $conn->prepare("SELECT id, titulo, mensaje, tipo, segmento FROM avisos_campanas WHERE id = ?");
    $stmt->bind_param("i", $campana_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $campana = $res->fetch_assoc();
    $stmt->close();
    
    if (!$campana) {
        echo json_encode(['success' => false, 'error' => 'Campaña no encontrada.']);
        exit;
    }
    
    // 2. Traer imágenes asociadas
    $imagenes = [];
    $stmt_img = $conn->prepare("SELECT archivo FROM avisos_imagenes WHERE campana_id = ? ORDER BY orden ASC");
    $stmt_img->bind_param("i", $campana_id);
    $stmt_img->execute();
    $res_img = $stmt_img->get_result();
    while ($row = $res_img->fetch_assoc()) {
        $imagenes[] = [
            'archivo' => $row['archivo'],
            'url_preview' => '/upload/avisos/' . $campana_id . '/' . $row['archivo']
        ];
    }
    $stmt_img->close();
    
    $campana['imagenes'] = $imagenes;
    
    echo json_encode(['success' => true, 'campana' => $campana]);
    
} catch (Exception $e) {
    error_log("[NUBIRA] Error obteniendo campaña: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error técnico.']);
}