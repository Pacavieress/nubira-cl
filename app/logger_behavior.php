<?php
// app/logger_behavior.php
require_once __DIR__ . '/../conexion.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['usuario_id'])) {
    
    $uid = (int)$_SESSION['usuario_id'];
    $tipo = $_POST['tipo'] ?? ''; 
    $id_entidad = (int)($_POST['id'] ?? 0);
    $duracion = (int)($_POST['duracion'] ?? 0);
    $vio_precio = (int)($_POST['vio_precio'] ?? 0);

    if ($id_entidad > 0 && ($tipo === 'servicio' || $tipo === 'apunte')) {
        // Guardamos la inteligencia
        $sql = "INSERT INTO nubira_behavior_logs 
                (usuario_id, entidad_tipo, entidad_id, fecha, tipo_evento, duracion_segundos, vio_precio) 
                VALUES (?, ?, ?, NOW(), 'view', ?, ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isiii", $uid, $tipo, $id_entidad, $duracion, $vio_precio);
        $stmt->execute();
    }
}
?>