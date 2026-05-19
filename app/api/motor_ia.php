<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

// 1. CARGA SEGURA DE CONEXIÓN
$app_dir = file_exists(__DIR__ . '/../conexion.php') ? __DIR__ . '/..' : $_SERVER['DOCUMENT_ROOT'] . '/app';
require_once $app_dir . '/conexion.php';

$usuario_id = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
$institucion_usuario = $_SESSION['institucion'] ?? '';

$limite_total = 6;
$items_finales = [];
$ids_servicios_usados = [0]; // Para evitar duplicados en el fallback
$ids_apuntes_usados = [0];

// =========================================================================
// FASE 1: REGLA DE AFINIDAD DIRECTA (TRACKER DE INTERESES)
// =========================================================================
$cat_favorita = null;

if ($usuario_id > 0) {
    // Buscar la categoría con más peso histórico del usuario
    $sql_tracker = "SELECT categoria FROM tracker_intereses 
                    WHERE usuario_id = ? AND categoria != 'General' AND categoria IS NOT NULL 
                    GROUP BY categoria ORDER BY SUM(peso_score) DESC LIMIT 1";
    if ($stmt_t = $conn->prepare($sql_tracker)) {
        $stmt_t->bind_param("i", $usuario_id);
        $stmt_t->execute();
        $res_t = $stmt_t->get_result()->fetch_assoc();
        if ($res_t) $cat_favorita = $res_t['categoria'];
        $stmt_t->close();
    }
}

// Si tenemos categoría favorita, sacamos los 3 mejores servicios y 3 mejores apuntes
if ($cat_favorita) {
    // Servicios de la categoría
    $sql_fav_serv = "SELECT id FROM servicios 
                     WHERE estado = 'aprobado' AND categoria = ? AND (visible = 1 OR visible IS NULL) 
                     ORDER BY score_nubira DESC LIMIT 3";
    if ($stmt_fs = $conn->prepare($sql_fav_serv)) {
        $stmt_fs->bind_param("s", $cat_favorita);
        $stmt_fs->execute();
        $res_fs = $stmt_fs->get_result();
        while ($row = $res_fs->fetch_assoc()) {
            $items_finales[] = ['id' => (int)$row['id'], 'tipo' => 'servicio'];
            $ids_servicios_usados[] = (int)$row['id'];
        }
        $stmt_fs->close();
    }

    // Apuntes de la categoría
    $sql_fav_ap = "SELECT id FROM apuntes 
                   WHERE estado = 'aprobado' AND categoria = ? 
                   ORDER BY descargas DESC LIMIT 3";
    if ($stmt_fa = $conn->prepare($sql_fav_ap)) {
        $stmt_fa->bind_param("s", $cat_favorita);
        $stmt_fa->execute();
        $res_fa = $stmt_fa->get_result();
        while ($row = $res_fa->fetch_assoc()) {
            $items_finales[] = ['id' => (int)$row['id'], 'tipo' => 'apunte'];
            $ids_apuntes_usados[] = (int)$row['id'];
        }
        $stmt_fa->close();
    }
}

// =========================================================================
// FASE 2: FALLBACK / RELLENO DE ALTA CONVERSIÓN
// Si la fase 1 no completó los 6 items, rellenamos con lo mejor de la plataforma
// priorizando la institución del usuario si está logueado.
// =========================================================================
$faltan = $limite_total - count($items_finales);

if ($faltan > 0) {
    // Calculamos cuántos servicios y apuntes faltan equitativamente
    $faltan_servicios = ceil($faltan / 2);
    $faltan_apuntes = floor($faltan / 2);

    // Variables dinámicas para el IN(...) anti-duplicados
    $placeholders_serv = str_repeat('?,', count($ids_servicios_usados) - 1) . '?';
    $types_serv = str_repeat('i', count($ids_servicios_usados));
    
    // Relleno Servicios
    if ($faltan_servicios > 0) {
        $sql_relleno_serv = "SELECT s.id 
                             FROM servicios s
                             INNER JOIN alumnos a ON s.alumno_id = a.id
                             LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
                             WHERE s.estado = 'aprobado' AND s.id NOT IN ($placeholders_serv)";
        
        // Boost institucional
        if (!empty($institucion_usuario)) {
            $inst_esc = $conn->real_escape_string($institucion_usuario);
            $sql_relleno_serv .= " ORDER BY CASE WHEN COALESCE(dp.institucion, a.institucion) LIKE '%$inst_esc%' THEN 1 ELSE 2 END, s.score_nubira DESC LIMIT ?";
        } else {
            $sql_relleno_serv .= " ORDER BY s.score_nubira DESC LIMIT ?";
        }

        if ($stmt_rs = $conn->prepare($sql_relleno_serv)) {
            $params = array_merge($ids_servicios_usados, [$faltan_servicios]);
            $stmt_rs->bind_param($types_serv . "i", ...$params);
            $stmt_rs->execute();
            $res_rs = $stmt_rs->get_result();
            while ($row = $res_rs->fetch_assoc()) {
                $items_finales[] = ['id' => (int)$row['id'], 'tipo' => 'servicio'];
            }
            $stmt_rs->close();
        }
    }

    // Relleno Apuntes
    if ($faltan_apuntes > 0) {
        $placeholders_ap = str_repeat('?,', count($ids_apuntes_usados) - 1) . '?';
        $types_ap = str_repeat('i', count($ids_apuntes_usados));

        $sql_relleno_ap = "SELECT ap.id 
                           FROM apuntes ap
                           INNER JOIN alumnos a ON ap.id_alumno = a.id
                           LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
                           WHERE ap.estado = 'aprobado' AND ap.id NOT IN ($placeholders_ap)";
        
        // Boost institucional
        if (!empty($institucion_usuario)) {
            $inst_esc = $conn->real_escape_string($institucion_usuario);
            $sql_relleno_ap .= " ORDER BY CASE WHEN COALESCE(dp.institucion, a.institucion) LIKE '%$inst_esc%' THEN 1 ELSE 2 END, ap.descargas DESC LIMIT ?";
        } else {
            $sql_relleno_ap .= " ORDER BY ap.descargas DESC LIMIT ?";
        }

        if ($stmt_ra = $conn->prepare($sql_relleno_ap)) {
            $params = array_merge($ids_apuntes_usados, [$faltan_apuntes]);
            $stmt_ra->bind_param($types_ap . "i", ...$params);
            $stmt_ra->execute();
            $res_ra = $stmt_ra->get_result();
            while ($row = $res_ra->fetch_assoc()) {
                $items_finales[] = ['id' => (int)$row['id'], 'tipo' => 'apunte'];
            }
            $stmt_ra->close();
        }
    }
}

// 3. MEZCLAR Y RESPONDER
// Hacemos un shuffle suave para que no queden todos los servicios de un lado y los apuntes del otro
shuffle($items_finales);

// Aseguramos de enviar exactamente el JSON que el JS frontend render_card espera
echo json_encode(['items' => array_slice($items_finales, 0, $limite_total)]);
exit;
?>