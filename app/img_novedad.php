<?php
// Endpoint público: sirve la imagen compartible (POST/HISTORY) de una novedad/anuncio de
// plataforma (Fase 2 de Marketing/Cards). Usa helpers/rate_limit_imagenes.php (propio,
// tabla independiente img_novedad_rate_limit) — NO comparte código con img_servicio.php,
// que ya está en producción verificado y no se toca.
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/seguridad_url.php';
require_once __DIR__ . '/helpers/imagen_compartir.php';
require_once __DIR__ . '/helpers/rate_limit_imagenes.php';

if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', '0'); // que un warning no corrompa el binario

// Auto-migración: tabla novedades (mismo patrón que img_servicio_rate_limit / video_thumb_path).
// icono queda reservado sin uso en el generador (decisión: sin ícono en v1 — GD+Inter no
// puede dibujar glifos de emoji reales, probado con 3 emojis distintos → mismo .notdef).
$conn->query("CREATE TABLE IF NOT EXISTS novedades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(120) NOT NULL,
    cuerpo TEXT NOT NULL,
    icono VARCHAR(10) NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

check_img_novedad_rate_limit($conn);

$formato = (($_GET['f'] ?? 'post') === 'history') ? 'history' : 'post';
$novedad_id = nubira_desencriptar_id($_GET['id'] ?? '');

if ($novedad_id <= 0) nb_servir_placeholder_novedad();

// Generar o servir desde cache (helper del Paso 4)
$file = nb_obtener_imagen_novedad($novedad_id, $formato);
if ($file === '' || !is_file($file)) nb_servir_placeholder_novedad();

// Servir el JPG con cache largo
if (ob_get_level() > 0) ob_clean(); // por si algún include emitió whitespace
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400, immutable');
header('Content-Length: ' . filesize($file));
readfile($file);
exit;
