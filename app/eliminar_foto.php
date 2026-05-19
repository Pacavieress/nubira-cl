<?php
session_start();
require_once __DIR__ . '/conexion.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// === VALIDACIÓN CSRF (NUBIRA SHIELD) ===
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'TOKEN DE SEGURIDAD INVÁLIDO']);
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];

// 1. Obtener nombre de la foto actual para borrar el archivo físico
$stmt = $conn->prepare("SELECT foto_perfil, nombre FROM alumnos WHERE id = ?");
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_assoc();
$stmt->close();

if ($data) {
    $foto_actual = $data['foto_perfil'] ?? '';
    $nombre_user = $data['nombre'] ?? 'Usuario';

    // Borrar archivo físico si existe
    if (!empty($foto_actual)) {
        $ruta_fisica = __DIR__ . '/perfil/fotos/' . $foto_actual;
        if (file_exists($ruta_fisica)) {
            @unlink($ruta_fisica);
        }
    }

    // 2. Limpiar BD (FIX: Usar string vacío en vez de NULL para evitar fatal errors)
    $stmt_upd = $conn->prepare("UPDATE alumnos SET foto_perfil = '' WHERE id = ?");
    $stmt_upd->bind_param("i", $usuario_id);
    
    if ($stmt_upd->execute()) {
        
        $_SESSION['foto_perfil'] = ''; // Limpiamos también la sesión para el header
        
        // =========================================================================
        // [NUBIRA 2.0] RECALCULAR GAMIFICACIÓN EN TIEMPO REAL (CASTIGO)
        // El usuario borró su foto (pierde 20 pts). Recalculamos todas sus clases.
        // =========================================================================
        require_once __DIR__ . '/helpers/usuario_helper.php';
        
        $q_serv = $conn->prepare("SELECT id FROM servicios WHERE alumno_id = ?");
        if ($q_serv) {
            $q_serv->bind_param("i", $usuario_id);
            $q_serv->execute();
            $res_s = $q_serv->get_result();
            while($sv = $res_s->fetch_assoc()){
                actualizar_score_servicio($conn, $sv['id']);
            }
            $q_serv->close();
        }
        // =========================================================================

        // Generar URL del avatar por defecto para que JS actualice la vista
        $default_url = "https://ui-avatars.com/api/?name=" . urlencode($nombre_user) . "&background=54A6D8&color=fff";
        
        echo json_encode([
            'success' => true,
            'newUrl'  => $default_url
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error al actualizar base de datos']);
    }
    $stmt_upd->close();

} else {
    echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
}
?>