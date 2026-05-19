<?php
// public_html/app/opiniones.php
$id = $_GET['id'] ?? '';
$rol = $_GET['rol'] ?? 'tutor';
if($id) { header("Location: perfil.php?id=$id&rol=$rol&tab=resenas"); exit; }
header("Location: /");