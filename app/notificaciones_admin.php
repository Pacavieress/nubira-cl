<?php
/**
 * notificaciones_admin.php
 * 
 * Módulo centralizado para mostrar alertas de elementos pendientes
 * en todas las secciones del panel de administración.
 * 
 * Uso:
 *   require_once __DIR__ . '/notificaciones_admin.php';
 *   $notificaciones = obtenerNotificacionesAdmin($conn);
 */

function obtenerNotificacionesAdmin($conn) {
    $notificaciones = [];

    // 🔹 Servicios pendientes
    $sql1 = "SELECT COUNT(*) AS total FROM servicios WHERE estado = 'pendiente'";
    $res1 = $conn->query($sql1);
    if ($res1 && $res1->num_rows > 0) {
        $data1 = $res1->fetch_assoc();
        if ((int)$data1['total'] > 0) {
            $notificaciones[] = [
                'tipo' => 'servicios',
                'mensaje' => "Hay {$data1['total']} servicios pendientes de revisión",
                'color' => 'red'
            ];
        }
    }

    // 🔹 Apuntes pendientes
    $sql2 = "SELECT COUNT(*) AS total FROM apuntes WHERE estado = 'pendiente'";
    $res2 = $conn->query($sql2);
    if ($res2 && $res2->num_rows > 0) {
        $data2 = $res2->fetch_assoc();
        if ((int)$data2['total'] > 0) {
            $notificaciones[] = [
                'tipo' => 'apuntes',
                'mensaje' => "Hay {$data2['total']} apuntes esperando aprobación",
                'color' => 'orange'
            ];
        }
    }

    // 🔹 Retiros pendientes
    $sql3 = "SELECT COUNT(*) AS total FROM retiros WHERE estado = 'pendiente'";
    $res3 = $conn->query($sql3);
    if ($res3 && $res3->num_rows > 0) {
        $data3 = $res3->fetch_assoc();
        if ((int)$data3['total'] > 0) {
            $notificaciones[] = [
                'tipo' => 'retiros',
                'mensaje' => "Hay {$data3['total']} retiros por aprobar",
                'color' => 'yellow'
            ];
        }
    }

    // 🔹 Empleos pendientes (si aplica)
    $sql4 = "SELECT COUNT(*) AS total FROM empleos WHERE estado = 'pendiente'";
    $res4 = $conn->query($sql4);
    if ($res4 && $res4->num_rows > 0) {
        $data4 = $res4->fetch_assoc();
        if ((int)$data4['total'] > 0) {
            $notificaciones[] = [
                'tipo' => 'empleos',
                'mensaje' => "Hay {$data4['total']} empleos pendientes de revisión",
                'color' => 'blue'
            ];
        }
    }

    return $notificaciones;
}
?>
