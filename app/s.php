<?php
// app/s.php - Redireccionador de enlaces ofuscados
$token = $_GET['t'] ?? '';
if (empty($token)) { header("Location: /vitrina"); exit; }

// Desofuscar
$decoded = base64_decode(strtr($token, '-_', '+/'));
// Importante: El salt debe ser "nubi" igual que en detalle_servicio.php
$id_real = (int)str_replace("nubi", "", $decoded);

if ($id_real > 0) {
    // Redirige a la raíz, donde está el detalle real
    header("Location: /servicios/" . $id_real);
} else {
    header("Location: /vitrina");
}
exit;