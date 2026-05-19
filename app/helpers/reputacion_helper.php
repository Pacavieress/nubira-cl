<?php
/**
 * HELPER DE REPUTACIÓN NUBIRA
 * Ubicación: app/helpers/reputacion_helper.php
 * Tabla Objetivo: alumnos (que actúa como tabla de usuarios)
 */

function actualizarReputacionUsuario($conn, $usuario_id) {
    // 1. Sanitización
    $usuario_id = (int)$usuario_id;
    if ($usuario_id <= 0) return false;

    // 2. QUERY DE CÁLCULO (Sin cambios, lee de contratos)
    $sql = "
        SELECT 
            COUNT(*) as total_votos, 
            AVG(rating) as promedio 
        FROM (
            SELECT calificacion_comprador as rating 
            FROM contratos 
            WHERE vendedor_id = $usuario_id 
              AND calificacion_comprador > 0 
              AND estado = 'finalizado'
            
            UNION ALL
            
            SELECT calificacion_vendedor as rating 
            FROM contratos 
            WHERE comprador_id = $usuario_id 
              AND calificacion_vendedor > 0 
              AND estado = 'finalizado'
        ) as source_ratings
    ";

    $result = $conn->query($sql);
    
    if ($result && $row = $result->fetch_assoc()) {
        $total = (int)$row['total_votos'];
        $promedio = number_format((float)($row['promedio'] ?? 0), 2, '.', '');

        // 3. ACTUALIZAR TABLA 'alumnos' (CORREGIDO)
        $updateSql = "UPDATE alumnos SET calificacion_promedio = ?, cantidad_votos = ? WHERE id = ?";
        
        $stmt = $conn->prepare($updateSql);
        if ($stmt) {
            $stmt->bind_param("dii", $promedio, $total, $usuario_id);
            $stmt->execute();
            $stmt->close();
            return true;
        }
    }
    
    return false;
}
?>