<?php
session_start();
if(!isset($_SESSION['usuario_id']) || !isset($_GET['id'])) exit;

$id_contrato = (int)$_GET['id'];
$usuario_id = (int)$_SESSION['usuario_id'];
$accion = $_GET['accion'] ?? 'estado';

// Guardar en la misma carpeta para asegurar permisos de Hostinger
$archivo_sala = __DIR__ . '/sala_activa_' . $id_contrato . '.txt';

header('Content-Type: application/json');

if ($accion === 'entrar' || $accion === 'ping') {
    // Guardo ID y Timestamp
    file_put_contents($archivo_sala, $usuario_id . '|' . time());
    echo json_encode(['status' => 'ok']);
} 
elseif ($accion === 'salir') {
    if (file_exists($archivo_sala)) unlink($archivo_sala);
    echo json_encode(['status' => 'ok']);
} 
elseif ($accion === 'estado') {
    clearstatcache(); // Obligatorio para que PHP no lea un archivo fantasma
    if (file_exists($archivo_sala)) {
        $data = explode('|', file_get_contents($archivo_sala));
        $user_cache = (int)($data[0] ?? 0);
        $time_cache = (int)($data[1] ?? 0);
        
        // Si alguien avisó hace menos de 25 segundos, está vivo
        if (time() - $time_cache < 25) {
            echo json_encode(['activo' => true, 'usuario_id' => $user_cache]);
            exit;
        }
    }
    echo json_encode(['activo' => false]);
}
?>