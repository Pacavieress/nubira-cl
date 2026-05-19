<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../public/login.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];

// Consulta para obtener todas las ventas del usuario
$query = "SELECT v.*, a.titulo, al.correo AS comprador_email
          FROM ventas_apuntes v
          JOIN apuntes a ON v.apunte_id = a.id
          JOIN alumnos al ON v.comprador_id = al.id
          WHERE v.vendedor_id = ?
          ORDER BY v.fecha DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$result = $stmt->get_result();

// Encabezado para descarga de archivo CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=mis_ventas.csv');

// Abrir la salida estándar para escribir el CSV
$output = fopen('php://output', 'w');

// Escribir encabezados de columnas
fputcsv($output, ['Título', 'Email comprador', 'Precio (CLP)', 'Fecha', 'Pagado']);

// Escribir cada fila de resultado
while ($row = $result->fetch_assoc()) {
    $pagado = $row['pagado_al_vendedor'] ? 'Sí' : 'No';
    fputcsv($output, [
        $row['titulo'],
        $row['comprador_email'],
        number_format($row['precio'], 0, ',', '.'),
        date('d/m/Y H:i', strtotime($row['fecha'])),
        $pagado
    ]);
}

fclose($output);
exit;
