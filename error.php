<?php
$codigo = $_GET['code'] ?? 500;

// Define mensajes según el error
switch ($codigo) {
  case 404:
    $titulo = "Página no encontrada (404)";
    $mensaje = "Lo que buscas no existe o fue movido.";
    $color = "blue";
    break;
  case 403:
    $titulo = "Acceso denegado (403)";
    $mensaje = "No tienes permisos para ver esta sección.";
    $color = "red";
    break;
  case 500:
    $titulo = "Error interno del servidor (500)";
    $mensaje = "Algo falló en nuestro lado. Estamos trabajando en ello.";
    $color = "yellow";
    break;
  case 401:
    $titulo = "No autorizado (401)";
    $mensaje = "Necesitas iniciar sesión para acceder.";
    $color = "orange";
    break;
  default:
    $titulo = "Error desconocido";
    $mensaje = "Ha ocurrido un error inesperado.";
    $color = "gray";
    break;
}
?>

<?php include 'index.php'; ?>
<section class="py-32 text-center">
  <h1 class="text-5xl font-bold text-<?= $color ?>-600"><?= $titulo ?></h1>
  <p class="mt-4 text-gray-600"><?= $mensaje ?></p>
  <a href="/" class="mt-6 inline-block bg-<?= $color ?>-600 text-white px-5 py-2 rounded-full hover:bg-<?= $color ?>-700">
    Volver al inicio
  </a>
</section>
