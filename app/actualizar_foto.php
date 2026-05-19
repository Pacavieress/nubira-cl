<?php
ob_start(); // [NUBIRA FIX] Iniciar buffer para atrapar cualquier espacio en blanco o error oculto
session_start();
error_reporting(0); // [NUBIRA FIX] Apagar warnings visuales que rompen el JSON

require_once __DIR__ . '/conexion.php';

header('Content-Type: application/json');

// 1. Validar sesión
if (!isset($_SESSION['usuario_id'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];

// 1.5 VALIDACIÓN CSRF (NUBIRA SHIELD)
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'TOKEN DE SEGURIDAD INVÁLIDO']);
    exit;
}

// 2. Validar archivo
if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'No se recibió ninguna imagen válida']);
    exit;
}

$file = $_FILES['foto'];
$tmp_name = $file['tmp_name'];

// Validar que sea realmente una imagen
$info = getimagesize($tmp_name);
if ($info === false) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'El archivo no es una imagen válida']);
    exit;
}

// 3. Configuración de Procesamiento
$upload_dir   = __DIR__ . '/perfil/fotos/';
$nuevo_nombre = 'u' . $usuario_id . '_' . uniqid() . '.webp'; // Siempre .webp
$destino      = $upload_dir . $nuevo_nombre;
$tamano_final = 400; // 400x400 píxeles
$calidad      = 80;  // 0 a 100 (80 es muy bueno)

// Crear carpeta si no existe
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// 4. Cargar imagen en memoria (GD Library)
$tipo_imagen = $info[2]; // 1=GIF, 2=JPG, 3=PNG, 18=WEBP
$imagen_origen = null;

switch ($tipo_imagen) {
    case IMAGETYPE_JPEG: $imagen_origen = imagecreatefromjpeg($tmp_name); break;
    case IMAGETYPE_PNG:  $imagen_origen = imagecreatefrompng($tmp_name); break;
    case IMAGETYPE_WEBP: $imagen_origen = imagecreatefromwebp($tmp_name); break;
    default:
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Formato no soportado (Use JPG, PNG o WEBP)']);
        exit;
}

if (!$imagen_origen) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Error al procesar la imagen']);
    exit;
}

// 5. Matemáticas para el Recorte Cuadrado (Centrado)
$ancho_orig = imagesx($imagen_origen);
$alto_orig  = imagesy($imagen_origen);

// El lado más corto define el tamaño del cuadrado de recorte
$lado_cuadrado = min($ancho_orig, $alto_orig);

// Coordenadas para centrar el recorte
$x_origen = ($ancho_orig - $lado_cuadrado) / 2;
$y_origen = ($alto_orig  - $lado_cuadrado) / 2;

// 6. Crear el Lienzo Final (400x400)
$lienzo_final = imagecreatetruecolor($tamano_final, $tamano_final);

// Mantener transparencia (importante si suben PNG)
imagealphablending($lienzo_final, false);
imagesavealpha($lienzo_final, true);
$transparente = imagecolorallocatealpha($lienzo_final, 0, 0, 0, 127);
imagefill($lienzo_final, 0, 0, $transparente);

// 7. Recortar y Redimensionar
imagecopyresampled(
    $lienzo_final,   // Destino
    $imagen_origen,  // Fuente
    0, 0,            // Destino X, Y
    $x_origen, $y_origen, // Fuente X, Y (Centrado)
    $tamano_final, $tamano_final,   // Ancho/Alto Destino
    $lado_cuadrado, $lado_cuadrado  // Ancho/Alto Fuente (Recorte)
);
// 8. Guardar como WEBP
if (imagewebp($lienzo_final, $destino, $calidad)) {
    
    // Liberar memoria RAM INMEDIATAMENTE para evitar Fatal Errors por límite de memoria
    imagedestroy($imagen_origen);
    imagedestroy($lienzo_final);

    try {
        // 9. Borrar foto antigua y Actualizar BD
        $stmt = $conn->prepare("SELECT foto_perfil FROM alumnos WHERE id = ?");
        $stmt->bind_param("i", $usuario_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $old_foto = ($res->fetch_assoc())['foto_perfil'] ?? '';
        $stmt->close();

        // Borrar archivo viejo
        if (!empty($old_foto) && file_exists($upload_dir . $old_foto)) {
            @unlink($upload_dir . $old_foto);
        }

        // Guardar nuevo nombre en BD
        $stmt_upd = $conn->prepare("UPDATE alumnos SET foto_perfil = ? WHERE id = ?");
        $stmt_upd->bind_param("si", $nuevo_nombre, $usuario_id);
        
        if ($stmt_upd->execute()) {
            $_SESSION['foto_perfil'] = $nuevo_nombre;
            $stmt_upd->close(); 

            // =========================================================================
            // [NUBIRA 2.0] RECALCULAR GAMIFICACIÓN EN TIEMPO REAL
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

            if (ob_get_length()) ob_clean();
            echo json_encode([
                'success' => true, 
                'url' => '/app/perfil/fotos/' . $nuevo_nombre,
                'message' => 'Foto actualizada y optimizada'
            ]);
            exit;
            
        } else {
            if (ob_get_length()) ob_clean();
            echo json_encode(['success' => false, 'message' => 'Error al guardar en base de datos']);
            exit;
        }

    } catch (Throwable $e) {
        // ¡AQUÍ ATRAPAMOS EL FATAL ERROR!
        if (ob_get_length()) ob_clean();
        echo json_encode([
            'success' => false, 
            'message' => 'CRASH EN PHP: ' . $e->getMessage() . ' en la línea ' . $e->getLine()
        ]);
        exit;
    }

} else {
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'message' => 'Error al guardar el archivo WEBP']);
    exit;
}
