<?php
/**
 * ONE-SHOT: Generar slugs SEO para servicios existentes.
 * Uso: /app/one_shot_generar_slugs.php?confirmar=1
 * BORRAR via FileZilla inmediatamente después de ejecutar.
 */
session_start();

if (($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Acceso denegado. Debes estar logueado como admin.');
}

if (($_GET['confirmar'] ?? '') !== '1') {
    die('Agrega ?confirmar=1 a la URL para ejecutar. BORRA este archivo despues.');
}

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/helpers/seo.php';

$res = $conn->query("SELECT id, titulo FROM servicios WHERE slug IS NULL OR slug = '' ORDER BY id ASC");
if (!$res) {
    die('Error en consulta: ' . $conn->error);
}

$total        = 0;
$actualizados = 0;
$errores      = [];

while ($row = $res->fetch_assoc()) {
    $total++;
    $slug = generar_slug($row['titulo']);

    if (empty($slug)) {
        $errores[] = 'ID ' . $row['id'] . ': slug vacio para titulo ' . htmlspecialchars($row['titulo']);
        continue;
    }

    $stmt = $conn->prepare("UPDATE servicios SET slug = ? WHERE id = ?");
    $stmt->bind_param("si", $slug, $row['id']);
    if ($stmt->execute()) {
        $actualizados++;
        echo '&#10003; ID ' . $row['id'] . ': <em>' . htmlspecialchars($row['titulo']) . '</em> &rarr; <strong>' . $slug . '</strong><br>';
    } else {
        $errores[] = 'ID ' . $row['id'] . ': error UPDATE - ' . $stmt->error;
    }
    $stmt->close();
}

echo '<hr>';
echo '<strong>Total procesados:</strong> ' . $total . '<br>';
echo '<strong>Slugs generados:</strong> ' . $actualizados . '<br>';

if ($errores) {
    echo '<strong>Errores (' . count($errores) . '):</strong><br>';
    foreach ($errores as $e) {
        echo '&nbsp;&nbsp;&#9888; ' . $e . '<br>';
    }
}

echo '<br><strong style="color:red">&#9888; BORRA ESTE ARCHIVO DE PRODUCCION VIA FILEZILLA AHORA.</strong>';
