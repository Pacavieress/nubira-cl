<?php
/**
 * API ALERTAS NUBIRA - VERSIÓN BLINDADA ANTI-ERROR 500
 * (Optimizada para Panel de Gestión y Header dinámico)
 * NUBIRA 2.0: Incluye TODAS las métricas operativas para Administradores
 */
// 1. Evitar que errores de PHP rompan el JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

try {
    // 2. BUSCAR CONEXIÓN AUTOMÁTICAMENTE
    $ruta_conexion = __DIR__ . '/conexion.php';
    if (!file_exists($ruta_conexion)) {
        $ruta_conexion = dirname(__DIR__) . '/conexion.php'; 
    }
    
    if (!file_exists($ruta_conexion)) {
        throw new Exception("No se encuentra el archivo conexion.php");
    }
    require_once $ruta_conexion;

    // 3. INICIAR SESIÓN
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();
    $uid = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
    
    // Validar si el usuario actual es admin (Idealmente usar una variable de sesión real como $_SESSION['es_admin'])
    $es_admin = true; // 🔥 MODO NUBIRA 2.0 ADMIN (Asegúrate de conectar esto a tu lógica real de sesión)

    // Array por defecto (AHORA INCLUYE TODAS LAS LLAVES DEL FRONTEND)
    $alertas = [
        // Métricas de Usuario
        'ventas_apuntes' => 0, 
        'ventas_clases' => 0, 
        'reclamos' => 0, 
        'soporte' => 0, 
        'valoraciones' => 0,
        'falta_banco' => 0,
        'falta_perfil' => 0,
        
        // --- MÉTRICAS ADMIN NUBIRA 2.0 ---
        'admin_retiros' => 0,
        'admin_pagos' => 0,
        'admin_contratos' => 0,
        'admin_usuarios' => 0,
        'admin_servicios' => 0,
        'admin_apuntes' => 0,
        'admin_chats' => 0,
        'admin_soporte' => 0,
        'admin_reclamos' => 0,
        'admin_solicitudes' => 0,
        'admin_login_fallos' => 0,
        'admin_accesos' => 0,
        'admin_pendientes_verificacion' => 0
    ];

    if ($uid > 0) {
        
        // ==========================================================
        //  A. MÉTRICAS DE USUARIO ESTÁNDAR
        // ==========================================================
        try {
    // NUBIRA 2.0 FIX: Solo notificar tickets ACTIVOS no leídos.
    // Los resueltos/cerrados/eliminados NO deben generar badge persistente.
    $sql = "SELECT COUNT(id) as total 
            FROM reclamos_sugerencias 
            WHERE usuario_id = ? 
              AND revisado_usuario = 0
              AND estado NOT IN ('resuelto', 'cerrado', 'eliminado')";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->bind_result($total_reclamos);
        $stmt->fetch();
        $alertas['reclamos'] = (int)$total_reclamos;
        $stmt->close();
    }
} catch (Exception $e) {}
        try {
            $sql = "SELECT COUNT(id) as total FROM ventas_apuntes WHERE vendedor_id = $uid AND revisado = 0";
            $res = $conn->query($sql);
            if ($res) { $alertas['ventas_apuntes'] = (int)$res->fetch_assoc()['total']; }
        } catch (Exception $e) {}

        try {
            $sql = "SELECT COUNT(id) as total FROM contratos WHERE vendedor_id = $uid AND revisado = 0";
            $res = $conn->query($sql);
            if ($res) { $alertas['ventas_clases'] = (int)$res->fetch_assoc()['total']; }
        } catch (Exception $e) {}

        try {
            $sql = "SELECT COUNT(id) as total FROM reclamos_sugerencias WHERE usuario_id = $uid AND revisado_usuario = 0";
            $res = $conn->query($sql);
            if ($res) { $alertas['soporte'] = (int)$res->fetch_assoc()['total']; }
        } catch (Exception $e) {}

        try {
            $sql = "SELECT COUNT(id) as total FROM valoraciones WHERE vendedor_id = $uid AND revisado = 0";
            $res = $conn->query($sql);
            if ($res) { $alertas['valoraciones'] = (int)$res->fetch_assoc()['total']; }
        } catch (Exception $e) {}

        // Datos de Perfil y Banco
        try {
            $stmt = $conn->prepare("SELECT foto_perfil, bio FROM alumnos WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $stmt->bind_result($f, $b);
                if ($stmt->fetch()) {
                    if (empty($f) || empty(trim((string)$b))) {
                        $alertas['falta_perfil'] = 1;
                    }
                }
                $stmt->close();
            }

            $stmt2 = $conn->prepare("SELECT banco, numero_cuenta FROM datos_pago_usuario WHERE usuario_id = ? LIMIT 1");
            if ($stmt2) {
                $stmt2->bind_param("i", $uid);
                $stmt2->execute();
                $stmt2->bind_result($banco, $cuenta);
                if ($stmt2->fetch()) {
                    if (empty(trim((string)$banco)) || empty(trim((string)$cuenta))) {
                        $alertas['falta_banco'] = 1;
                    }
                } else {
                     $alertas['falta_banco'] = 1;
                }
                $stmt2->close();
            }
        } catch (Exception $e) {}

        // ==========================================================
        //  B. MÉTRICAS EXCLUSIVAS PARA ADMINISTRADORES
        // ==========================================================
        if ($es_admin) {
            
            // 1. Retiros pendientes
            try {
                $res = $conn->query("SELECT COUNT(id) as total FROM solicitudes_retiro WHERE estado = 'pendiente'");
                if ($res) { $alertas['admin_retiros'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}

            // 2. Pagos pendientes
            try {
                $res = $conn->query("SELECT COUNT(id) as total FROM pagos_escrow WHERE estado = 'pendiente'");
                if ($res) { $alertas['admin_pagos'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}

            // 3. Contratos en progreso
            try {
                $res = $conn->query("SELECT COUNT(id) as total FROM contratos WHERE estado = 'en_progreso' OR estado IS NULL OR estado = ''");
                if ($res) { $alertas['admin_contratos'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}

            // 4. Soporte sin responder
            try {
                $res = $conn->query("SELECT COUNT(id) as total FROM reclamos_sugerencias WHERE estado = 'pendiente'");
                if ($res) { $alertas['admin_soporte'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}

            // 5. Reclamos sin revisar por admin
            try {
                // NUBIRA 2.0: Usamos notificado_admin para controlar el badge rojo
                $res = $conn->query("SELECT COUNT(id) as total FROM reclamos_sugerencias WHERE notificado_admin = 0");
                if ($res) { $alertas['admin_reclamos'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}
            
            // 6. Servicios pendientes de aprobación
            try {
                // Ajusta 'revisado = 0' o 'estado = "pendiente"' según tu base de datos
                $res = $conn->query("SELECT COUNT(id) as total FROM servicios WHERE activo = 0");
                if ($res) { $alertas['admin_servicios'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}

            // 7. Apuntes pendientes de revisión
            try {
                $res = $conn->query("SELECT COUNT(id) as total FROM apuntes WHERE activo = 0");
                if ($res) { $alertas['admin_apuntes'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}
            
            // 8. Logins fallidos recientes (Ejemplo de métrica de seguridad)
            try {
                $res = $conn->query("SELECT COUNT(id) as total FROM login_intentos WHERE exito = 0 AND revisado = 0");
                if ($res) { $alertas['admin_login_fallos'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}

            // 9. Usuarios nuevos sin revisar
            try {
                $res = $conn->query("SELECT COUNT(id) AS total FROM alumnos WHERE visible = 1 AND visto_admin = 0");
                if ($res) { $alertas['admin_usuarios'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}

            // 10. Intentos DLP sin revisar (violaciones de contacto en chat)
            try {
                $res = $conn->query("SELECT COUNT(id) AS total FROM dlp_intentos WHERE revisado_admin = 0");
                if ($res) { $alertas['admin_chats'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}

            // 11. Usuarios pendientes de verificación (registro híbrido)
            try {
                $res = $conn->query("SELECT COUNT(id) AS total FROM alumnos WHERE verificacion_estado = 'pendiente' AND visible = 1");
                if ($res) { $alertas['admin_pendientes_verificacion'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}
        }
    }

    // RESPUESTA EXITOSA
    echo json_encode($alertas);

} catch (Throwable $e) {
    // SI OCURRE UN ERROR FATAL, DEVOLVEMOS JSON CON EL ERROR
    echo json_encode([
        'error' => true, 
        'mensaje' => $e->getMessage()
    ]);
}
?>