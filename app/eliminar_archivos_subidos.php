<?php
/**
 * BACKEND: ELIMINAR APUNTES SUBIDOS EN MASA
 * UBICACIÓN: public_html/app/eliminar_archivos_subidos.php
 */
session_start();

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_SESSION['usuario_id'])) {
        throw new Exception('No autorizado. Inicia sesión.');
    }

    $app_dir = __DIR__;
    if (!file_exists($app_dir . '/conexion.php')) {
        if (file_exists(dirname($app_dir) . '/conexion.php')) {
            $app_dir = dirname($app_dir);
        }
    }
    require_once $app_dir . '/conexion.php';

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!isset($data['ids']) || !is_array($data['ids']) || empty($data['ids'])) {
        throw new Exception('No se enviaron datos válidos.');
    }

    $usuario_id = (int)$_SESSION['usuario_id'];
    $rol = $_SESSION['rol'] ?? 'alumno';
    $es_admin = ($rol === 'admin');
    $ids_eliminar = array_values(array_filter(array_map('intval', $data['ids'])));

    if (empty($ids_eliminar)) {
        throw new Exception('IDs corruptos.');
    }

    $conn->begin_transaction();

    // Consulta de verificación de propiedad
    $checkSql = $es_admin ? "SELECT id, archivo FROM apuntes WHERE id = ?" : "SELECT id, archivo FROM apuntes WHERE id = ? AND id_alumno = ?";
    $stmt_check = $conn->prepare($checkSql);
    
    // Consulta de borrado
    $del = $conn->prepare("DELETE FROM apuntes WHERE id = ?");

    $afectados = 0;

    foreach ($ids_eliminar as $eliminar_id) {
        if ($eliminar_id <= 0) continue;

        if ($es_admin) {
            $stmt_check->bind_param("i", $eliminar_id);
        } else {
            $stmt_check->bind_param("ii", $eliminar_id, $usuario_id);
        }
        
        $stmt_check->execute();
        $res = $stmt_check->get_result();
        
        if ($row = $res->fetch_assoc()) {
            // Borrar archivo físico del disco si existe
            $ruta_archivo = dirname($app_dir) . "/upload/apuntes/" . $row['archivo'];
            if (file_exists($ruta_archivo)) {
                @unlink($ruta_archivo);
            }
            
            // Borrar de la base de datos
            $del->bind_param("i", $eliminar_id);
            $del->execute();
            $afectados += $del->affected_rows;
        }
    }

    $stmt_check->close();
    $del->close();
    
    $conn->commit();

    echo json_encode([
        'success' => true, 
        'mensaje' => 'Archivos eliminados correctamente.',
        'afectados' => $afectados
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>