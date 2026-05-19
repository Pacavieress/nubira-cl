<?php
require_once __DIR__ . '/init_sesion.php';

header('Content-Type: application/json');

// RBAC
if (($_SESSION['rol'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

// CSRF
$csrf_recibido = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_recibido)) {
    echo json_encode(['success' => false, 'error' => 'Token inválido']);
    exit;
}

$campana_id = (int)($_POST['campana_id'] ?? 0);
if ($campana_id < 1) {
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    exit;
}

try {
    $conn->begin_transaction();
    
    // 1. Eliminar avisos enviados a usuarios
    $stmt1 = $conn->prepare("DELETE FROM avisos_admin WHERE campana_id = ?");
    $stmt1->bind_param("i", $campana_id);
    $stmt1->execute();
    $stmt1->close();
    
    // 2. Obtener nombres de imágenes para borrarlas del disco
    $imgs_a_borrar = [];
    $stmt2 = $conn->prepare("SELECT archivo FROM avisos_imagenes WHERE campana_id = ?");
    $stmt2->bind_param("i", $campana_id);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($row = $res2->fetch_assoc()) $imgs_a_borrar[] = $row['archivo'];
    $stmt2->close();
    
    // 3. Eliminar registros de imágenes
    $stmt3 = $conn->prepare("DELETE FROM avisos_imagenes WHERE campana_id = ?");
    $stmt3->bind_param("i", $campana_id);
    $stmt3->execute();
    $stmt3->close();
    
    // 4. Eliminar la campaña
    $stmt4 = $conn->prepare("DELETE FROM avisos_campanas WHERE id = ?");
    $stmt4->bind_param("i", $campana_id);
    $stmt4->execute();
    $afectadas = $stmt4->affected_rows;
    $stmt4->close();
    
    $conn->commit();
    
    // 5. Borrar archivos físicos del disco (fuera de la transacción)
    $dir_campana = $_SERVER['DOCUMENT_ROOT'] . '/upload/avisos/' . $campana_id;
    if (is_dir($dir_campana)) {
        foreach ($imgs_a_borrar as $archivo) {
            $ruta = $dir_campana . '/' . $archivo;
            if (file_exists($ruta)) @unlink($ruta);
        }
        // Intentar borrar carpeta vacía
        @rmdir($dir_campana);
    }
    
    if ($afectadas > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Campaña no encontrada']);
    }
    
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Error al eliminar: ' . $e->getMessage()]);
}