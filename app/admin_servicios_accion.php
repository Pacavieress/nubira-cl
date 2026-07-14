<?php
/**
 * LÓGICA: ADMIN SERVICIOS ACCIÓN (CORREGIDO: COLUMNA CORREO)
 * UBICACIÓN: app/admin_servicios_accion.php
 */

// 1. CONFIGURACIÓN
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_error_log.txt'); 
header('Content-Type: application/json; charset=utf-8');

try {
    session_start();
    
    // 2. DEPENDENCIAS
    require_once __DIR__ . '/conexion.php';
    
    // Carga robusta de correo.php
    if (file_exists(__DIR__ . '/correo.php')) {
        require_once __DIR__ . '/correo.php';
    } else {
        throw new Exception("Error crítico: No se encuentra app/correo.php");
    }

    if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
        throw new Exception("No autorizado");
    }

    // 3. LECTURA DE DATOS
    $jsonRaw = file_get_contents('php://input');
    $jsonData = json_decode($jsonRaw, true);

    $id_servicio = isset($_POST['id_servicio']) ? intval($_POST['id_servicio']) : ($jsonData['id_servicio'] ?? 0);
    $accion      = isset($_POST['accion'])      ? trim($_POST['accion'])      : ($jsonData['accion'] ?? '');
    $motivo      = isset($_POST['motivo_rechazo']) ? trim($_POST['motivo_rechazo']) : ($jsonData['motivo_rechazo'] ?? '');
    $img_base64  = isset($_POST['imagen_base64']) ? $_POST['imagen_base64'] : ($jsonData['imagen_base64'] ?? '');

    if ($id_servicio <= 0 && $accion !== 'guardar_imagen_editada') throw new Exception("ID inválido");

    // =================================================================
    // LÓGICA DE NEGOCIO
    // =================================================================

    // --- APROBAR ---
    if ($accion === 'aprobar') {
        // Gate: no se puede aprobar sin horario de disponibilidad cargado
        require_once __DIR__ . '/helpers/horarios.php';
        $stmt_h = $conn->prepare("SELECT horarios_json FROM servicios WHERE id=?");
        $stmt_h->bind_param("i", $id_servicio);
        $stmt_h->execute();
        $horarios_json_actual = $stmt_h->get_result()->fetch_assoc()['horarios_json'] ?? null;
        $stmt_h->close();

        if (!parsear_horarios_servicio($horarios_json_actual)['tiene_horarios']) {
            throw new Exception("Este servicio no puede aprobarse: el tutor no ha configurado su horario de disponibilidad.");
        }

        // Actualizar BD
        $stmt = $conn->prepare("UPDATE servicios SET estado='aprobado', motivo_rechazo=NULL, fecha_revision=NOW() WHERE id=?");
        $stmt->bind_param("i", $id_servicio);
        if (!$stmt->execute()) throw new Exception("Error BD Update: " . $stmt->error);
        $stmt->close();

        // Enviar Correo
        try {
            // CORRECCIÓN AQUÍ: Cambiamos 'a.email' por 'a.correo'
            $sqlInfo = "SELECT s.titulo, a.id AS autor_id, a.nombre, a.correo
                        FROM servicios s
                        JOIN alumnos a ON s.alumno_id = a.id
                        WHERE s.id = $id_servicio";

            $resInfo = $conn->query($sqlInfo);

            if ($resInfo && $row = $resInfo->fetch_assoc()) {
                // Usamos 'correo'
                if (!empty($row['correo'])) {
                    $html = "<p>Hola <strong>{$row['nombre']}</strong>,</p>";
                    $html .= "<p>¡Buenas noticias! Tu servicio <strong>'{$row['titulo']}'</strong> ha sido aprobado y ya está visible en la vitrina de Nubira.</p>";
                    $html .= "<p>¡Gracias por ser parte de la comunidad!</p>";

                    enviarCorreo($row['correo'], "✅ ¡Tu servicio está publicado!", $html);
                    require_once __DIR__ . '/enviar_push_nubira.php';
                    $t_push = mb_substr($row['titulo'], 0, 50);
                    enviar_push_nubira((int)$row['autor_id'], '✅ Servicio aprobado', 'Tu servicio "' . $t_push . '" ya está publicado', '/mis-publicaciones');
                }
            }
        } catch (Throwable $t) {
            error_log("Error envío SMTP: " . $t->getMessage());
        }

        echo json_encode(['status' => 'ok']);
        exit;
    }

    // --- RECHAZAR ---
    if ($accion === 'rechazar') {
        $stmt = $conn->prepare("UPDATE servicios SET estado='rechazado', motivo_rechazo=? WHERE id=?");
        $stmt->bind_param("si", $motivo, $id_servicio);
        $stmt->execute();

        // Enviar Correo
        try {
            // CORRECCIÓN AQUÍ: Cambiamos 'a.email' por 'a.correo'
            $sqlInfo = "SELECT s.titulo, a.nombre, a.correo 
                        FROM servicios s 
                        JOIN alumnos a ON s.alumno_id = a.id 
                        WHERE s.id = $id_servicio";

            $resInfo = $conn->query($sqlInfo);
            
            if ($resInfo && $row = $resInfo->fetch_assoc()) {
                if (!empty($row['correo'])) {
                    $html = "<p>Hola <strong>{$row['nombre']}</strong>,</p>";
                    $html .= "<p>Tu servicio <strong>'{$row['titulo']}'</strong> no ha podido ser aprobado.</p>";
                    
                    $html .= "<div style='background-color:#fee2e2; color:#991b1b; padding:15px; border-radius:8px; margin:20px 0; border:1px solid #fecaca;'>";
                    $html .= "<strong>Motivo del rechazo:</strong><br>" . nl2br(htmlspecialchars($motivo));
                    $html .= "</div>";
                    
                    $html .= "<p>Puedes corregir la información y volver a enviarlo.</p>";

                    enviarCorreo($row['correo'], "⚠️ Acción requerida: Tu servicio", $html);
                }
            }
        } catch (Throwable $t) {
            error_log("Error envío SMTP: " . $t->getMessage());
        }

        echo json_encode(['status' => 'ok']);
        exit;
    }

// --- TOGGLE VISIBILIDAD ---
    if ($accion === 'toggle_visibilidad') {
        $visible = isset($_POST['visible']) ? intval($_POST['visible']) : ($jsonData['visible'] ?? 0);
        
        $stmt = $conn->prepare("UPDATE servicios SET visible = ? WHERE id = ?");
        $stmt->bind_param("ii", $visible, $id_servicio);
        if (!$stmt->execute()) throw new Exception("Error BD Update: " . $stmt->error);
        $stmt->close();
        
        echo json_encode(['status' => 'ok']);
        exit;
    }
    // --- ELIMINAR ---
    if ($accion === 'eliminar') {
        $conn->query("DELETE FROM servicios WHERE id=$id_servicio");
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // --- GUARDAR EDICIÓN IMAGEN ---
    if ($accion === 'guardar_imagen_editada') {
        if (empty($img_base64)) throw new Exception("Sin imagen");
        
        $res = $conn->query("SELECT imagen FROM servicios WHERE id=$id_servicio");
        $row = $res->fetch_assoc();
        
        if ($row && !empty($row['imagen'])) {
            $path = __DIR__ . '/../upload/servicios/' . $row['imagen'];
            if(!is_dir(dirname($path))) @mkdir(dirname($path), 0777, true);

            $parts = explode(";base64,", $img_base64);
            if (isset($parts[1])) {
                file_put_contents($path, base64_decode($parts[1]));
                echo json_encode(['status' => 'ok']);
            } else throw new Exception("Formato base64 inválido");
        } else throw new Exception("Servicio sin imagen");
        
        exit;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
?>