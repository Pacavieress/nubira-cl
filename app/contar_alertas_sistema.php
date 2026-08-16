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
    require_once __DIR__ . '/helpers/roles.php'; // nb_es_tutor_activo()

    // 3. INICIAR SESIÓN
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();
    $uid = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
    
    // Validar si el usuario actual es admin
    $es_admin = (($_SESSION['rol'] ?? '') === 'admin');

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
        'guias_tutores' => 0,
        
        // --- MÉTRICAS ADMIN NUBIRA 2.0 ---
        'admin_retiros' => 0,
        'admin_pagos' => 0,
        'admin_contratos' => 0,
        'admin_usuarios' => 0,
        'admin_servicios' => 0,
        'admin_apuntes' => 0,
        'admin_chats' => 0,
        'admin_chats_moderacion' => 0,
        'admin_soporte' => 0,
        'admin_reclamos' => 0,
        'admin_solicitudes' => 0,
        'admin_login_fallos' => 0,
        'admin_accesos' => 0,
        'admin_perfil_incompleto' => 0,
        'admin_videos' => 0,
        'admin_anuncio_video' => 0,
        'admin_despertar_dormidos' => 0
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

        // Guías "Para Tutores" no vistas — solo cuenta para quien realmente califica
        // como tutor (mismo criterio que perfil.php:382 $es_creador, vía nb_es_tutor_activo()).
        try {
            if (nb_es_tutor_activo($conn, $uid)) {
                $sql = "SELECT COUNT(a.id) AS total
                        FROM guias_articulos a
                        JOIN guias_categorias c ON c.id = a.categoria_id
                        LEFT JOIN guias_articulos_vistos v ON v.articulo_id = a.id AND v.usuario_id = ?
                        WHERE c.solo_tutores = 1 AND a.estado = 'publicado' AND v.id IS NULL";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $stmt->bind_result($total_guias_tutores);
                $stmt->fetch();
                $alertas['guias_tutores'] = (int)$total_guias_tutores;
                $stmt->close();
            }
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
                $res = $conn->query("SELECT COUNT(id) as total FROM servicios WHERE estado = 'pendiente'");
                if ($res) { $alertas['admin_servicios'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}

            // 7. Apuntes pendientes de revisión
            try {
                $res = $conn->query("SELECT COUNT(id) as total FROM apuntes WHERE estado = 'pendiente'");
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

            // 10b. Archivos adjuntos de chat pendientes de moderación (visible=0)
            try {
                $res = $conn->query("SELECT COUNT(*) AS total FROM mensajes WHERE visible = 0 AND archivo_ruta IS NOT NULL");
                if ($res) { $alertas['admin_chats_moderacion'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}

            // 10c. Compras de apuntes sin revisar por el admin (revisado_por_admin,
            // distinto de 'revisado' que es la notificación propia del vendedor)
            try {
                $res = $conn->query("SELECT COUNT(id) AS total FROM ventas_apuntes WHERE precio > 0 AND revisado_por_admin = 0");
                if ($res) { $alertas['admin_compras_apuntes'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}

            // 12. Tutores con perfil incompleto (sin foto, bio, tipo o servicios sin horario)
            try {
                $res = $conn->query("SELECT COUNT(*) AS total FROM (
                    SELECT a.id
                    FROM alumnos a
                    INNER JOIN servicios s ON s.alumno_id = a.id AND s.estado = 'aprobado'
                    WHERE a.visible = 1 AND a.confirmado = 1
                    GROUP BY a.id
                    HAVING (a.foto_perfil IS NULL OR a.foto_perfil = ''
                         OR a.bio IS NULL OR a.bio = ''
                         OR a.tipo IS NULL OR a.tipo = ''
                         OR SUM(CASE WHEN s.horarios_json IS NOT NULL
                                      AND s.horarios_json != ''
                                      AND s.horarios_json LIKE '% - %'
                                      THEN 1 ELSE 0 END) < COUNT(*))
                ) AS sub");
                if ($res) { $alertas['admin_perfil_incompleto'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}

            // 13. Videos pendientes de moderación
            try {
                $res = $conn->query("SELECT COUNT(id) AS total FROM servicios WHERE video_estado = 'pendiente'");
                if ($res) { $alertas['admin_videos'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}

            // 14. Tutores activos sin recibir el anuncio de video
            try {
                $res = $conn->query("
                    SELECT COUNT(DISTINCT a.id) AS total
                    FROM alumnos a
                    INNER JOIN servicios s ON s.alumno_id = a.id
                    WHERE s.estado = 'aprobado'
                      AND a.visible = 1
                      AND a.id != 1
                      AND a.correo NOT LIKE 'testpablo%'
                      AND LOWER(TRIM(a.correo)) NOT IN (
                          SELECT LOWER(TRIM(destinatario)) FROM correos_admin
                          WHERE admin_nombre = 'anuncio_video_tutores_jun2026' AND exito = 1
                      )
                ");
                if ($res) { $alertas['admin_anuncio_video'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}

            // 15. Usuarios dormidos sin recibir campaña despertar
            try {
                $res = $conn->query("
                    SELECT COUNT(DISTINCT a.id) AS total
                    FROM alumnos a
                    WHERE a.visible = 1
                      AND a.bloqueado = 0
                      AND a.confirmado = 1
                      AND a.recibir_emails = 1
                      AND a.id != 1
                      AND a.correo NOT LIKE 'testpablo%'
                      AND DATEDIFF(NOW(), a.fecha_registro) >= 31
                      AND NOT EXISTS (SELECT 1 FROM servicios s WHERE s.alumno_id = a.id)
                      AND NOT EXISTS (SELECT 1 FROM contratos c WHERE c.comprador_id = a.id)
                      AND NOT EXISTS (SELECT 1 FROM apuntes ap WHERE ap.id_alumno = a.id)
                      AND LOWER(TRIM(a.correo)) NOT IN (
                          SELECT LOWER(TRIM(destinatario)) FROM correos_admin
                          WHERE admin_nombre = 'despertar_dormidos_jun2026' AND exito = 1
                      )
                ");
                if ($res) { $alertas['admin_despertar_dormidos'] = (int)$res->fetch_assoc()['total']; }
            } catch (Exception $e) {}
        }
    }

    // Defensa en profundidad: si no es admin, nunca exponer las métricas admin_* en el JSON
    // aunque una regresión futura vuelva a encender $es_admin por error.
    if (!$es_admin) {
        foreach ($alertas as $clave => $valor) {
            if (strpos($clave, 'admin_') === 0) {
                unset($alertas[$clave]);
            }
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