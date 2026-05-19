<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    die("❌ Acceso denegado.");
}

// Obtener filtros
$filtro_estado = $_GET['estado'] ?? '';
$filtro_institucion = $_GET['institucion'] ?? '';

// Condiciones
$condicion = [];
if ($filtro_estado === 'pendiente') {
    $condicion[] = "r.estado = 'pendiente'";
} elseif ($filtro_estado === 'aprobado') {
    $condicion[] = "r.estado = 'aprobado'";
} elseif ($filtro_estado === 'rechazado') {
    $condicion[] = "r.estado = 'rechazado'";
}

if (!empty($filtro_institucion)) {
    $condicion[] = "r.institucion = '" . $conn->real_escape_string($filtro_institucion) . "'";
}

$where = $condicion ? "WHERE " . implode(" AND ", $condicion) : "";

// Consultar solicitudes
$sql = "
SELECT r.id, a.nombre, a.correo, r.monto, r.estado, r.fecha_solicitud, r.fecha_pago,
       d.banco, d.tipo_cuenta, d.numero_cuenta, d.titular_nombre, d.rut
FROM solicitudes_retiro r
JOIN alumnos a ON r.usuario_id = a.id
LEFT JOIN datos_pago_usuario d ON r.usuario_id = d.usuario_id
$where
ORDER BY r.fecha_solicitud DESC";

$result = $conn->query($sql);

// Encabezados CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="retiros_exportados.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['ID', 'Nombre', 'Correo', 'Banco', 'Tipo Cuenta', 'Número Cuenta', 'Titular', 'RUT', 'Monto', 'Estado', 'Fecha Solicitud', 'Fecha Pago']);

while ($fila = $result->fetch_assoc()) {
    fputcsv($output, [
        $fila['id'],
        $fila['nombre'],
        $fila['correo'],
        $fila['banco'],
        $fila['tipo_cuenta'],
        $fila['numero_cuenta'],
        $fila['titular_nombre'],
        $fila['rut'],
        $fila['monto'],
        $fila['estado'],
        $fila['fecha_solicitud'],
        $fila['fecha_pago']
    ]);
}

fclose($output);
exit;
