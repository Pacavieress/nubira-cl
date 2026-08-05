<?php
// app/ajax_subir_imagen_guia.php
// Endpoint AJAX: sube una imagen suelta para insertar dentro del cuerpo de un
// artículo del Centro de Recursos (distinta de la portada). Llamado desde
// admin_guias.php (botón "+ Insertar imagen"). Devuelve la URL final para que
// el JS inserte el <img> en el textarea — el guardado real del cuerpo sigue
// pasando siempre por nb_sanitizar_html() en admin_guias.php al enviar el form.
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
session_start();

if (($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['exito' => false, 'error' => 'Acceso denegado']);
    exit;
}

require_once __DIR__ . '/helpers/guias_imagenes.php';

if (empty($_FILES['imagen']['name'])) {
    http_response_code(400);
    echo json_encode(['exito' => false, 'error' => 'No se recibió ninguna imagen.']);
    exit;
}

$dir_fs = $_SERVER['DOCUMENT_ROOT'] . '/upload/guias/';
$nombre = nb_guia_subir_imagen_inline($_FILES['imagen'], $dir_fs);

if (!$nombre) {
    echo json_encode(['exito' => false, 'error' => 'Imagen inválida (solo JPG/PNG/WebP, máx 15MB).']);
    exit;
}

echo json_encode(['exito' => true, 'url' => '/upload/guias/' . $nombre]);
