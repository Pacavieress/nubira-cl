<?php
/**
 * BACKEND: PROCESAR EVALUACIÓN
 * ESTADO: NUBIRA 2.0 (NO-TRIGGER VERSION & REDIRECCIÓN INTELIGENTE)
 * * Descripción: Guarda la evaluación y recalcula el promedio del servicio 
 * vía PHP para evitar errores de permisos en MySQL (#1227).
 */

// 1. CONFIGURACIÓN
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Validar ruta conexión
$ruta_conexion = __DIR__ . '/conexion.php';
if (!file_exists($ruta_conexion)) {
    die("Error Crítico: No se encuentra el sistema (conexion.php).");
}
require_once $ruta_conexion;

// 2. SEGURIDAD
if (!isset($_SESSION['usuario_id'])) { die("Error: Sesión no iniciada."); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { die("Error: Método no permitido."); }

// 3. RECIBIR DATOS
$usuario_id  = (int)$_SESSION['usuario_id']; // Quien evalúa
$contrato_id = (int)($_POST['contrato_id'] ?? 0);
$estrellas   = (int)($_POST['estrellas'] ?? 0);
$comentario  = trim($_POST['comentario'] ?? '');

// Sanitización
if ($estrellas < 1) $estrellas = 1;
if ($estrellas > 5) $estrellas = 5;
if (strlen($comentario) > 1000) $comentario = substr($comentario, 0, 1000);

if ($contrato_id <= 0) die("Error: ID de contrato inválido.");

// 4. OBTENER CONTEXTO DEL CONTRATO
$sql = "SELECT id, comprador_id, vendedor_id, servicio_id FROM contratos WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) die("Error SQL (Prepare Contrato): " . $conn->error);

$stmt->bind_param("i", $contrato_id);
$stmt->execute();
$contrato = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contrato) die("Error: Contrato no encontrado.");

// 5. DETERMINAR ROLES Y RUTA DE SALIDA (NUBIRA 2.0)
$es_comprador = ($usuario_id === (int)$contrato['comprador_id']); // Soy Alumno
$es_vendedor  = ($usuario_id === (int)$contrato['vendedor_id']);  // Soy Tutor

if (!$es_comprador && !$es_vendedor) die("Error: No participas en este contrato.");

// Definimos hacia dónde se va el usuario una vez terminada la evaluación
$ruta_salida = $es_vendedor ? '/ventas_clases' : '/mis_compras';

// Configuración de la actualización
if ($es_vendedor) {
    // CASO: TUTOR EVALÚA ALUMNO (No afecta promedio del servicio)
    $id_evaluado  = $contrato['comprador_id'];
    $rol_evaluado = 'comprador';
    
    $sql_legacy = "UPDATE contratos SET 
                   calificacion_vendedor = ?, 
                   comentario_vendedor = ?, 
                   finalizado_vendedor = 1 
                   WHERE id = ?";
} else {
    // CASO: ALUMNO EVALÚA TUTOR (Afecta promedio del servicio y LIBERA FONDOS)
    $id_evaluado  = $contrato['vendedor_id'];
    $rol_evaluado = 'vendedor';
    
    // NUBIRA 2.0 FIX: Cambiamos 'finalizado' a 'liberado' para que el dinero impacte la billetera
    $sql_legacy = "UPDATE contratos SET 
                   calificacion_comprador = ?, 
                   comentario_comprador = ?, 
                   finalizado_comprador = 1,
                   estado = 'liberado' 
                   WHERE id = ?";
}

// 6. TRANSACCIÓN PRINCIPAL
$conn->begin_transaction();

try {
    // A) INSERTAR EN NUEVA TABLA 'valoraciones' (Log histórico)
    $sql_new = "INSERT INTO valoraciones (contrato_id, servicio_id, id_evaluador, id_evaluado, rol_evaluado, calificacion, comentario, fecha) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt1 = $conn->prepare($sql_new);
    if (!$stmt1) throw new Exception("Error SQL Valoraciones: " . $conn->error);
    
    $stmt1->bind_param("iiiisis", 
        $contrato_id, 
        $contrato['servicio_id'], 
        $usuario_id, 
        $id_evaluado, 
        $rol_evaluado, 
        $estrellas, 
        $comentario
    );
    
    if (!$stmt1->execute()) throw new Exception("Error al guardar valoración: " . $stmt1->error);
    $stmt1->close();

    // B) ACTUALIZAR TABLA 'contratos' (Para el flujo actual)
    $stmt2 = $conn->prepare($sql_legacy);
    if (!$stmt2) throw new Exception("Error SQL Contratos: " . $conn->error);
    
    $stmt2->bind_param("isi", $estrellas, $comentario, $contrato_id);
    
    if (!$stmt2->execute()) throw new Exception("Error al actualizar contrato: " . $stmt2->error);
    $stmt2->close();

    // =========================================================================
    // C) SOLUCIÓN NUBIRA 2.0: RECALCULAR PROMEDIO SERVICIO (SIN TRIGGER)
    // =========================================================================
    // Solo recalculamos si el alumno (comprador) está evaluando el servicio
    if ($es_comprador) {
        $servicio_id = $contrato['servicio_id'];

        // 1. Obtener suma total y votos totales (Fuente Híbrida: Contratos + Comentarios Antiguos)
        $sql_calc = "
            SELECT SUM(suma_rating) as total_puntos, SUM(conteos) as total_votos FROM (
                -- Fuente 1: Contratos (Sistema Nuevo)
                SELECT SUM(calificacion_comprador) as suma_rating, COUNT(*) as conteos 
                FROM contratos 
                WHERE servicio_id = ? AND calificacion_comprador > 0
                
                UNION ALL
                
                -- Fuente 2: Servicio Comentarios (Sistema Antiguo)
                SELECT SUM(rating) as suma_rating, COUNT(*) as conteos 
                FROM servicio_comentarios 
                WHERE servicio_id = ?
            ) as fuentes
        ";

        $stmt_avg = $conn->prepare($sql_calc);
        if ($stmt_avg) {
            $stmt_avg->bind_param("ii", $servicio_id, $servicio_id);
            $stmt_avg->execute();
            $res_avg = $stmt_avg->get_result()->fetch_assoc();
            $stmt_avg->close();

            $nuevo_promedio = 0.0;
            if ($res_avg && $res_avg['total_votos'] > 0) {
                $nuevo_promedio = round($res_avg['total_puntos'] / $res_avg['total_votos'], 1);
            }

            // 2. Actualizar Tabla Servicios
            $stmt_upd_s = $conn->prepare("UPDATE servicios SET promedio = ? WHERE id = ?");
            if ($stmt_upd_s) {
                $stmt_upd_s->bind_param("di", $nuevo_promedio, $servicio_id);
                $stmt_upd_s->execute();
                $stmt_upd_s->close();
            }
        }
    }
    // =========================================================================

    // D) CONFIRMAR TODO
    $conn->commit();

    // E) ACTUALIZAR REPUTACIÓN DE USUARIO (Helper Opcional)
    try {
        $ruta_helper = __DIR__ . '/helpers/reputacion_helper.php';
        if (file_exists($ruta_helper)) {
            include_once $ruta_helper;
            if (function_exists('actualizarReputacionUsuario')) {
                actualizarReputacionUsuario($conn, $id_evaluado);
            }
        }
    } catch (Exception $e) { /* Silencioso */ }

    // G) PUSH AL EVALUADO (solo cuando alumno evalúa tutor)
    if ($es_comprador) {
        try {
            require_once __DIR__ . '/enviar_push_nubira.php';
            $nombre_push = explode(' ', trim($_SESSION['usuario_nombre'] ?? 'Alguien'))[0];
            $cuerpo_push = $nombre_push . ' te dejó ' . $estrellas . ' ⭐';
            if (!empty($comentario)) {
                $preview_val = mb_substr($comentario, 0, 60);
                if (mb_strlen($comentario) > 60) $preview_val .= '…';
                $cuerpo_push .= ': "' . $preview_val . '"';
            }
            enviar_push_nubira($id_evaluado, '⭐ Nueva valoración', $cuerpo_push, '/mis-evaluaciones');
        } catch (Exception $pushErr) { /* Silencioso */ }
    }

    // F) REDIRECCIÓN EXITOSA HACIA EL PANEL (NUBIRA 2.0)
    header("Location: " . $ruta_salida);
    exit;

} catch (Exception $e) {
    // ERROR: Deshacer todo
    $conn->rollback();
    
    // UI de Error (Estilo Nubira Básico)
    echo "<div style='font-family:sans-serif; max-width:600px; margin:50px auto; padding:20px; border-left:4px solid #ef4444; background:#fef2f2; border-radius:8px;'>";
    echo "<h2 style='color:#991b1b; margin-top:0;'>⚠️ Error al guardar evaluación</h2>";
    echo "<p style='color:#7f1d1d;'>Ocurrió un problema técnico. Tu evaluación no se ha perdido, pero no se pudo guardar en este momento.</p>";
    echo "<div style='background:#fff; padding:10px; border:1px solid #fee2e2; border-radius:4px; font-family:monospace; font-size:12px; color:#ef4444; margin:10px 0;'>";
    echo htmlspecialchars($e->getMessage());
    echo "</div>";
    echo "<a href='" . $ruta_salida . "' style='display:inline-block; margin-top:10px; text-decoration:none; color:#54A6D8; font-weight:bold;'>&larr; Volver a mi panel</a>";
    echo "</div>";
    exit;
}
?>