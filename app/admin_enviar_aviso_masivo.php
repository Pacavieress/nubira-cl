<?php
/**
 * ENDPOINT: ENVIAR CAMPAÑA DE AVISOS (NUBIRA 2.0)
 * ESTADO: BLINDADO (CSRF + RBAC + Transacción Atómica)
 * Segmenta: todos, tutores (>=1 publicación aprobada), no_tutores
 */
require_once __DIR__ . '/init_sesion.php';
header('Content-Type: application/json; charset=utf-8');

// 1. CORTAFUEGOS DE MÉTODO
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

// 2. CORTAFUEGOS DE ROL
if (($_SESSION['rol'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Acceso denegado.']);
    exit;
}

// 3. CSRF
$csrf_post = $_POST['csrf_token'] ?? '';
if (empty($csrf_post) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_post)) {
    echo json_encode(['success' => false, 'error' => 'Token de seguridad inválido.']);
    exit;
}

// 4. SANITIZACIÓN
$admin_id  = (int)($_SESSION['usuario_id'] ?? 0);
$es_rapido = !empty($_POST['es_rapido']);
$titulo    = trim((string)($_POST['titulo'] ?? ''));
$mensaje   = trim((string)($_POST['mensaje'] ?? ''));
$tipo      = (string)($_POST['tipo'] ?? 'info');
$segmento  = (string)($_POST['segmento'] ?? 'todos');
$imagenes  = $_POST['imagenes'] ?? [];
$imagenes_origen = $_POST['imagenes_origen'] ?? [];
if (!is_array($imagenes_origen)) $imagenes_origen = [];

// Modo rápido (atajo desde perfil.php u otros accesos directos): si no viene
// título, se completa con un valor por defecto para que la campaña quede
// consistente y visible en el historial de /admin/avisos.
if ($es_rapido && $titulo === '') {
    $titulo = 'Mensaje directo';
}

// Validar imágenes: máx 3, solo nombres seguros
if (!is_array($imagenes)) $imagenes = [];
if (count($imagenes) > 3) {
    echo json_encode(['success' => false, 'error' => 'Máximo 3 imágenes permitidas.']);
    exit;
}
foreach ($imagenes as $img) {
    if (!preg_match('/^img_[a-zA-Z0-9._]+\.(jpg|png|webp)$/', $img)) {
        echo json_encode(['success' => false, 'error' => 'Nombre de imagen inválido.']);
        exit;
    }
}

// 5. VALIDACIONES DE NEGOCIO
$tipos_validos = ['info', 'novedad', 'importante'];
$segmentos_validos = ['todos', 'tutores', 'no_tutores', 'usuario'];

if (mb_strlen($titulo) < 3 || mb_strlen($titulo) > 150) {
    echo json_encode(['success' => false, 'error' => 'El título debe tener entre 3 y 150 caracteres.']);
    exit;
}
if (mb_strlen($mensaje) < 5 || mb_strlen($mensaje) > 1000) {
    echo json_encode(['success' => false, 'error' => 'El mensaje debe tener entre 5 y 1000 caracteres.']);
    exit;
}
if (!in_array($tipo, $tipos_validos, true)) {
    echo json_encode(['success' => false, 'error' => 'Tipo de aviso inválido.']);
    exit;
}
if (!in_array($segmento, $segmentos_validos, true)) {
    echo json_encode(['success' => false, 'error' => 'Segmento inválido.']);
    exit;
}

// 6. RESOLVER DESTINATARIOS SEGÚN SEGMENTO
global $conn;

// Subquery: usuarios con al menos 1 publicación aprobada (tutor = creador activo)
$sql_tutores_ids = "(
    SELECT DISTINCT alumno_id FROM servicios WHERE estado = 'aprobado' AND COALESCE(visible, 1) = 1
    UNION
    SELECT DISTINCT id_alumno FROM apuntes WHERE estado = 'aprobado' AND bloqueado = 0 AND COALESCE(visible, 1) = 1
)";

switch ($segmento) {
    case 'tutores':
        $sql_destinatarios = "SELECT id FROM alumnos 
                              WHERE id IN $sql_tutores_ids 
                              AND rol != 'admin' AND visible = 1 AND bloqueado = 0";
        break;
    case 'no_tutores':
        $sql_destinatarios = "SELECT id FROM alumnos 
                              WHERE id NOT IN $sql_tutores_ids 
                              AND rol != 'admin' AND visible = 1 AND bloqueado = 0";
        break;
  case 'usuario':
    $usuario_id_target = (int)($_POST['usuario_id'] ?? 0);
    if ($usuario_id_target <= 0) {
        echo json_encode(['success' => false, 'error' => 'Debes seleccionar un usuario.']);
        exit;
    }
    $stmt_check = $conn->prepare("SELECT id FROM alumnos WHERE id = ? AND rol != 'admin' AND visible = 1 AND bloqueado = 0");
    $stmt_check->bind_param("i", $usuario_id_target);
    $stmt_check->execute();
    $stmt_check->store_result();
    if ($stmt_check->num_rows === 0) {
        $stmt_check->close();
        echo json_encode(['success' => false, 'error' => 'Usuario inválido o inactivo.']);
        exit;
    }
    $stmt_check->close();
    $sql_destinatarios = "SELECT id FROM alumnos WHERE id = $usuario_id_target";
    break;

case 'todos':
default:
    $sql_destinatarios = "SELECT id FROM alumnos 
                          WHERE rol != 'admin' AND visible = 1 AND bloqueado = 0";
    break;
}

$res = $conn->query($sql_destinatarios);
if (!$res) {
    error_log("[NUBIRA SHIELD] Error resolviendo destinatarios: " . $conn->error);
    echo json_encode(['success' => false, 'error' => 'Error al calcular destinatarios.']);
    exit;
}

$destinatarios = [];
while ($row = $res->fetch_assoc()) {
    $destinatarios[] = (int)$row['id'];
}
$res->free();

if (empty($destinatarios)) {
    echo json_encode(['success' => false, 'error' => 'No hay destinatarios para este segmento.']);
    exit;
}

$total = count($destinatarios);

// 7. TRANSACCIÓN ATÓMICA: campaña + N avisos individuales
$conn->begin_transaction();

try {
    // 7a. Crear campaña padre
    $stmt_camp = $conn->prepare(
        "INSERT INTO avisos_campanas (admin_id, titulo, mensaje, tipo, segmento, total_destinatarios) 
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt_camp) throw new Exception("Prepare campaña falló: " . $conn->error);
    
    $stmt_camp->bind_param("issssi", $admin_id, $titulo, $mensaje, $tipo, $segmento, $total);
    if (!$stmt_camp->execute()) throw new Exception("Insert campaña falló: " . $stmt_camp->error);
    
    $campana_id = $stmt_camp->insert_id;
    $stmt_camp->close();
    
    // 7b. Insertar avisos individuales (1 por destinatario)
    $stmt_aviso = $conn->prepare(
        "INSERT INTO avisos_admin (admin_id, destino_id, mensaje, tipo, campana_id) 
         VALUES (?, ?, ?, ?, ?)"
    );
    if (!$stmt_aviso) throw new Exception("Prepare aviso falló: " . $conn->error);
    
    foreach ($destinatarios as $destino_id) {
        $stmt_aviso->bind_param("iissi", $admin_id, $destino_id, $mensaje, $tipo, $campana_id);
        if (!$stmt_aviso->execute()) throw new Exception("Insert aviso #$destino_id falló: " . $stmt_aviso->error);
    }
    $stmt_aviso->close();
    
  // 7c. Procesar imágenes: mover desde temp/ O copiar desde campaña existente
if (!empty($imagenes)) {
    $carpeta_temp  = $_SERVER['DOCUMENT_ROOT'] . '/upload/avisos/temp/' . $admin_id . '/';
    $carpeta_final = $_SERVER['DOCUMENT_ROOT'] . '/upload/avisos/' . $campana_id . '/';
    
    if (!is_dir($carpeta_final)) {
        mkdir($carpeta_final, 0755, true);
    }
    
    $stmt_img = $conn->prepare("INSERT INTO avisos_imagenes (campana_id, archivo, orden) VALUES (?, ?, ?)");
    
    $orden = 1;
    foreach ($imagenes as $i => $nombre_archivo) {
        $origen = $imagenes_origen[$i] ?? 'temp';
        $ruta_final = $carpeta_final . $nombre_archivo;
        $exito = false;
        
        if ($origen === 'temp') {
            // Imagen nueva subida → mover desde temp
            $ruta_temp = $carpeta_temp . $nombre_archivo;
            if (file_exists($ruta_temp) && rename($ruta_temp, $ruta_final)) {
                $exito = true;
            }
        } else {
            // Imagen duplicada de otra campaña → copiar físicamente
            $origen_campana_id = (int)$origen;
            if ($origen_campana_id > 0) {
                $ruta_origen = $_SERVER['DOCUMENT_ROOT'] . '/upload/avisos/' . $origen_campana_id . '/' . $nombre_archivo;
                if (file_exists($ruta_origen) && copy($ruta_origen, $ruta_final)) {
                    $exito = true;
                }
            }
        }
        
        if ($exito) {
            $stmt_img->bind_param("isi", $campana_id, $nombre_archivo, $orden);
            $stmt_img->execute();
            $orden++;
        }
    }
    $stmt_img->close();
}
    
    // Confirmar todo
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'campana_id' => $campana_id,
        'enviados' => $total,
        'mensaje' => "Campaña enviada a $total usuario" . ($total > 1 ? 's' : '') . "."
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("[NUBIRA SHIELD] Error envío masivo: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error de infraestructura. Operación cancelada.']);
}
?>