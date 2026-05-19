<?php
session_start();
require_once __DIR__ . '/enviar_push_nubira.php';

$resultado = enviar_push_nubira(
    167, // cambia al user_id que quieras testear
    'Prueba manual Nubira 2.0',
    'Si ves esto, el push funciona perfectamente',
    '/explorar'
);

echo '<pre>';
print_r($resultado);
echo '</pre>';