<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/conexion.php';
session_start();

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$usuario_id = (int) $_SESSION['usuario_id'];
$rol        = $_SESSION['rol'] ?? 'alumno';

/* -----------------------------------------------
 * Helper seguro: evita errores si la tabla no existe
 * ----------------------------------------------- */
function contar($conn, string $sql): int {
    try {
        $res = $conn->query($sql);
        if ($res && $row = $res->fetch_assoc()) {
            return (int)$row['total'];
        }
    } catch (mysqli_sql_exception $e) {
        // Si la tabla no existe o el campo no se encuentra, devolvemos 0
        return 0;
    }
    return 0;
}

/* ================================================================
 * ADMINISTRADOR
 * ================================================================ */
if ($rol === 'admin') {
    $data = [
        // 🔹 Usuarios no confirmados
        'usuarios'           => contar($conn, "SELECT COUNT(*) AS total FROM alumnos WHERE confirmado = 0 AND bloqueado = 0"),

        // 🔹 Apuntes sin publicar (publico = 0)
        'apuntes'            => contar($conn, "SELECT COUNT(*) AS total FROM apuntes WHERE publico = 0"),

        // 🔹 Servicios pendientes
        'servicios'          => contar($conn, "SELECT COUNT(*) AS total FROM servicios WHERE estado = 'pendiente'"),

        // 🔹 Retiros (por ahora no hay tabla creada)
        'retiros'            => 0,

        // 🔹 Soporte pendiente
        'soporte'            => contar($conn, "SELECT COUNT(*) AS total FROM soporte WHERE estado = 'pendiente'"),

        // 🔹 Reclamos pendientes
        'reclamos'           => contar($conn, "SELECT COUNT(*) AS total FROM reclamos WHERE estado = 'pendiente'"),

        // 🔹 Solicitudes pendientes
        'solicitudes'        => contar($conn, "SELECT COUNT(*) AS total FROM solicitudes WHERE estado = 'pendiente'"),

        // 🔹 Login fallos sin revisar
        'login_fallos'       => contar($conn, "SELECT COUNT(*) AS total FROM login_fallos WHERE revisado = 0"),

        // 🔹 Logs de correo fallidos
        'logs_correo'        => contar($conn, "SELECT COUNT(*) AS total FROM logs_correo WHERE exito = 0"),

        // 🔹 Accesos a vitrina (hoy)
        'accesos_vitrina'    => contar($conn, "SELECT COUNT(*) AS total FROM accesos_vitrina WHERE fecha >= CURDATE()"),

        // 🔹 Reportes de servicios (no creada aún)
        'reportes_servicios' => 0
    ];
}
/* ================================================================
 * USUARIO NORMAL
 * ================================================================ */
else {
    $data = [
        // Ventas pendientes de pago
        'mis_ventas'    => contar($conn, "SELECT COUNT(*) AS total FROM ventas_apuntes WHERE estado = 'pendiente' AND id_vendedor = $usuario_id"),

        // Soporte con respuesta no leída
        'soporte_user'  => contar($conn, "SELECT COUNT(*) AS total FROM soporte WHERE estado = 'resuelto' AND leido_usuario = 0 AND usuario_id = $usuario_id"),

        // Reclamos resueltos no leídos
        'reclamos_user' => contar($conn, "SELECT COUNT(*) AS total FROM reclamos WHERE estado = 'resuelto' AND leido_usuario = 0 AND usuario_id = $usuario_id")
    ];
}

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$conn->close();
