<?php
session_start();
require_once __DIR__ . '/../conexion.php';

if ($_SESSION['rol'] !== 'admin') die("Acceso denegado");

// Mapeo de dominios a nombres reales
$dominios = [
    'uc.cl' => 'UC',
    'puc.cl' => 'UC',
    'udp.cl' => 'UDP',
    'mail.udp.cl' => 'UDP',
    'aiep.cl' => 'AIEP',
    'uss.cl' => 'USS',
    'uautonoma.cl' => 'UA'
];

$actualizados = 0;

foreach ($dominios as $dominio => $nombre_real) {
    // Buscamos alumnos con ese dominio de correo que tengan la institución VACÍA
    $sql = "UPDATE alumnos 
            SET institucion = ? 
            WHERE correo LIKE ? 
            AND (institucion IS NULL OR institucion = '' OR institucion = 'Estudiante verificado')";
    
    $stmt = $conn->prepare($sql);
    $like_dom = "%@" . $dominio;
    $stmt->bind_param("ss", $nombre_real, $like_dom);
    $stmt->execute();
    $actualizados += $stmt->affected_rows;
    $stmt->close();
}

echo "Proceso terminado. Se actualizaron $actualizados registros de alumnos.";