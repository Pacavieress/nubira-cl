<?php
/**
 * PROCESO: CREAR O RECUPERAR CONVERSACIÓN (CORREGIDO PARA DETALLE_SERVICIO.PHP)
 * UBICACIÓN: public_html/app/crear_conversacion.php
 */

ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

// Manejador de errores fatales para devolver siempre JSON
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        echo json_encode(['error' => 'Error crítico del servidor.']);
        die();
    }
});

session_start();

$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    echo json_encode(['error' => 'Error: conexión no encontrada.']);
    exit;
}
require_once $app_dir . '/conexion.php';

// 1. Validar Sesión
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Debes iniciar sesión.']);
    exit;
}

$comprador_id = (int)$_SESSION['usuario_id'];
$servicio_id  = isset($_POST['servicio_id']) ? (int)$_POST['servicio_id'] : 0;
// Capturamos el mensaje real del usuario, o usamos uno por defecto
$mensaje_inicial = isset($_POST['mensaje']) && !empty(trim($_POST['mensaje'])) 
                   ? trim($_POST['mensaje']) 
                   : "Hola, me interesa este servicio.";

if ($servicio_id <= 0) {
    echo json_encode(['error' => 'Datos incompletos: Faltó el ID del servicio.']);
    exit;
}

try {
    // 2. BUSCAR AL VENDEDOR (Esto faltaba: obtenemos el dueño del servicio)
    $stmtv = $conn->prepare("SELECT alumno_id FROM servicios WHERE id = ? LIMIT 1");
    $stmtv->bind_param("i", $servicio_id);
    $stmtv->execute();
    $resv = $stmtv->get_result();
    
    if ($resv->num_rows === 0) {
        echo json_encode(['error' => 'El servicio ya no existe.']);
        exit;
    }
    
    $servicio_data = $resv->fetch_assoc();
    $vendedor_id = (int)$servicio_data['alumno_id'];

    // Validar auto-compra
    if ($comprador_id === $vendedor_id) {
        echo json_encode(['error' => 'No puedes contactarte a ti mismo.']);
        exit;
    }

    // 3. BUSCAR CONVERSACIÓN EXISTENTE
    // Nota: Verificamos si ya existe un chat para este servicio entre estas dos personas
    $stmt = $conn->prepare("SELECT id FROM conversaciones WHERE servicio_id = ? AND comprador_id = ? AND vendedor_id = ? LIMIT 1");
    $stmt->bind_param("iii", $servicio_id, $comprador_id, $vendedor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        // YA EXISTE: Devolvemos el ID para redireccionar
        echo json_encode(['id' => $row['id'], 'status' => 'existing']);
    } else {
        // 4. CREAR NUEVA CONVERSACIÓN
        // Nota: Ajusta los nombres de columnas 'visible_comprador', 'creado_en', etc. a tu DB real si difieren.
        // He mantenido tu estructura original.
        $stmtInsert = $conn->prepare("INSERT INTO conversaciones (servicio_id, comprador_id, vendedor_id, estado, eliminado, visible_comprador, visible_vendedor, creado_en, ultima_interaccion) VALUES (?, ?, ?, 'activa', 0, 1, 1, NOW(), NOW())");
        $stmtInsert->bind_param("iii", $servicio_id, $comprador_id, $vendedor_id);
        
        if ($stmtInsert->execute()) {
            $newId = $stmtInsert->insert_id;
            
            // LOG DE ACTIVIDAD (SENSOR NUBIRA)
            if (file_exists(__DIR__ . '/logger.php')) {
                require_once __DIR__ . '/logger.php';
                registrar_actividad($conn, $comprador_id, 'CONTACTO', "Nuevo Lead -> Vendedor ID: $vendedor_id | Servicio ID: $servicio_id");
            }
            
            // 5. INSERTAR PRIMER MENSAJE
            try {
                // Usamos 'remitente_id' y 'enviado_en' como en tu archivo original
                $stmtMsg = $conn->prepare("INSERT INTO mensajes (conversacion_id, remitente_id, mensaje, leido, enviado_en) VALUES (?, ?, ?, 0, NOW())");
                if ($stmtMsg) {
                    $stmtMsg->bind_param("iis", $newId, $comprador_id, $mensaje_inicial);
                    $stmtMsg->execute();
                    $stmtMsg->close();
                }
            } catch (Exception $e) { 
                // Si falla el mensaje, al menos el chat se creó. Silencioso.
            }
            
            echo json_encode(['id' => $newId, 'status' => 'created']);
        } else {
            echo json_encode(['error' => 'Error SQL al crear chat: ' . $conn->error]);
        }
        $stmtInsert->close();
    }
    $stmt->close();

} catch (Exception $e) {
    echo json_encode(['error' => 'Excepción del sistema: ' . $e->getMessage()]);
}
?>