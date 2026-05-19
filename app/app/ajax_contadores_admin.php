<?php
// =================================================================================
// ARCHIVO: app/ajax_contadores_admin.php (NUBIRA 2.0 - NOTIFICATION SYSTEM)
// =================================================================================

// Configuración de cabeceras para JSON
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Iniciar sesión si no existe
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Respuesta por defecto (todo en 0)
$response = [
    // Contadores de Usuario
    'mis_ventas'        => 0,
    'reclamos_user'     => 0,
    'soporte_user'      => 0,
    
    // Contadores de Admin
    'usuarios'          => 0,
    'servicios'         => 0,
    'apuntes'           => 0,
    'retiros'           => 0,
    'reclamos'          => 0,
    'solicitudes'       => 0,
    'soporte'           => 0,
    'login_fallos'      => 0,
    'accesos_vitrina'   => 0
];

// 1. VERIFICACIÓN DE SEGURIDAD
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode($response);
    exit;
}

// 2. CONEXIÓN A BASE DE DATOS
require_once __DIR__ . '/conexion.php';
$mysqli = $conn ?? $conexion;

if (!$mysqli) {
    echo json_encode($response);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$es_admin   = (isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin');

try {
    // =========================================================================
    // 3. CONTADORES DE USUARIO (LOGICA PERSONAL)
    // =========================================================================
    
    // A) Mis Ventas Nuevas (Estado 'pagado' que requiere atención del vendedor)
    // Asumimos tabla 'compras' o 'ordenes' donde el vendedor es el usuario actual
    $sql = "SELECT COUNT(*) as c FROM compras WHERE vendedor_id = ? AND estado = 'pagado'";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("i", $usuario_id);
        $stmt->execute();
        $stmt->bind_result($c);
        if ($stmt->fetch()) { $response['mis_ventas'] = $c; }
        $stmt->close();
    }

    // B) Respuestas a mis Reclamos (Estado 'respondido' o similar)
    // Asumimos que el usuario quiere saber si le respondieron
    $sql = "SELECT COUNT(*) as c FROM reclamos WHERE usuario_id = ? AND estado = 'respondido'";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("i", $usuario_id);
        $stmt->execute();
        $stmt->bind_result($c);
        if ($stmt->fetch()) { $response['reclamos_user'] = $c; }
        $stmt->close();
    }

    // C) Respuestas a Soporte
    $sql = "SELECT COUNT(*) as c FROM soporte WHERE usuario_id = ? AND estado = 'respondido'";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("i", $usuario_id);
        $stmt->execute();
        $stmt->bind_result($c);
        if ($stmt->fetch()) { $response['soporte_user'] = $c; }
        $stmt->close();
    }

    // =========================================================================
    // 4. CONTADORES DE ADMINISTRADOR (SOLO SI ES ADMIN)
    // =========================================================================
    if ($es_admin) {
        
        // Helper para conteos simples de admin (sin bind params)
        function countAdmin($db, $table, $condition) {
            $q = $db->query("SELECT COUNT(*) as c FROM $table WHERE $condition");
            if ($q) {
                $r = $q->fetch_assoc();
                return (int)$r['c'];
            }
            return 0;
        }

        // 1. Usuarios Pendientes de Verificación (o nuevos hoy)
        // Ajusta 'estado_cuenta' según tu estructura real (ej: 'pendiente', 'no_verificado')
        $response['usuarios'] = countAdmin($mysqli, 'alumnos', "estado_cuenta = 'pendiente'");

        // 2. Servicios Pendientes de Aprobación
        $response['servicios'] = countAdmin($mysqli, 'servicios', "estado = 'pendiente'");

        // 3. Apuntes/Documentos Pendientes
        $response['apuntes'] = countAdmin($mysqli, 'apuntes', "estado = 'pendiente'");

        // 4. Solicitudes de Retiro de Dinero
        $response['retiros'] = countAdmin($mysqli, 'retiros', "estado = 'pendiente'");

        // 5. Reclamos Abiertos (De cualquier usuario)
        $response['reclamos'] = countAdmin($mysqli, 'reclamos', "estado IN ('pendiente', 'abierto')");

        // 6. Tickets de Soporte Abiertos
        $response['soporte'] = countAdmin($mysqli, 'soporte', "estado IN ('pendiente', 'abierto')");

        // 7. Solicitudes Generales (ej: ser tutor)
        $response['solicitudes'] = countAdmin($mysqli, 'solicitudes', "estado = 'pendiente'");

        // 8. Fallos de Login Recientes (Últimas 24 horas)
        // Asumiendo tabla 'login_logs' o 'auditoria_login'
        $response['login_fallos'] = countAdmin($mysqli, 'login_logs', "exito = 0 AND fecha > DATE_SUB(NOW(), INTERVAL 24 HOUR)");

        // 9. Accesos Vitrina (Opcional, si tienes tabla de logs)
        // Si no tienes tabla, dejamos en 0 para no romper
        // $response['accesos_vitrina'] = countAdmin($mysqli, 'vitrina_logs', "fecha > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    }

} catch (Exception $e) {
    // En caso de error, devolvemos JSON limpio con ceros (silencioso)
    // Loguear error internamente si es necesario: error_log($e->getMessage());
}

// Retornar JSON final
echo json_encode($response);
exit;