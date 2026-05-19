<?php
/**
 * ENDPOINT: OCULTAR CONVERSACIONES Y AULAS (SOFT DELETE)
 * UBICACIÓN: public_html/app/eliminar_conversacion.php
 * ESTÁNDAR: Nubira 2.0 (Seguridad, Procedural, Prepared Statements)
 */

session_start();
header('Content-Type: application/json');

// 1. Verificación de sesión estricta
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/conexion.php';

$my_id = (int)$_SESSION['usuario_id'];

// 2. Validación de datos entrantes
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['ids']) || !is_array($_POST['ids'])) {
    echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
    exit;
}

$eliminados = 0;

// 3. Procesamiento iterativo seguro (Soft Delete individual)
foreach ($_POST['ids'] as $item) {
    // El frontend envía formato "tipo_id" (ej: "negociacion_12" o "aula_5")
    $partes = explode('_', $item);
    if (count($partes) !== 2) continue;

    $tipo = $partes[0];
    $id_item = (int)$partes[1];

    if ($tipo === 'negociacion') {
        // Actualizamos el flag solo para quien lo solicita
        $sql = "UPDATE conversaciones 
                SET oculto_comprador = IF(comprador_id = ?, 1, oculto_comprador),
                    oculto_vendedor  = IF(vendedor_id = ?, 1, oculto_vendedor)
                WHERE id = ? AND (comprador_id = ? OR vendedor_id = ?)";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("iiiii", $my_id, $my_id, $id_item, $my_id, $my_id);
            $stmt->execute();
            if ($stmt->affected_rows > 0) $eliminados++;
            $stmt->close();
        }
        
    } elseif ($tipo === 'aula') {
        // Igual para contratos/aulas: nunca borramos la evidencia de un servicio
        $sql = "UPDATE contratos 
                SET oculto_comprador = IF(comprador_id = ?, 1, oculto_comprador),
                    oculto_vendedor  = IF(vendedor_id = ?, 1, oculto_vendedor)
                WHERE id = ? AND (comprador_id = ? OR vendedor_id = ?)";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("iiiii", $my_id, $my_id, $id_item, $my_id, $my_id);
            $stmt->execute();
            if ($stmt->affected_rows > 0) $eliminados++;
            $stmt->close();
        }
    }
}

// 4. Respuesta al frontend
echo json_encode(['success' => true, 'eliminados' => $eliminados]);