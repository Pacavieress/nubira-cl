<?php
// Resolver de links cortos de compartir: /r/{codigo} → /detalle-servicio/{hash}
// Público, sin login. Cuenta clicks. No expone el id interno (redirige al hash).

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/seguridad_url.php';

$codigo = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['codigo'] ?? '');

if ($codigo === '') { header('Location: /explorar'); exit; }

$stmt = $conn->prepare("SELECT servicio_id FROM links_cortos WHERE codigo = ? LIMIT 1");
$stmt->bind_param('s', $codigo);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) { header('Location: /explorar?nf=1'); exit; } // no encontrado

$servicio_id = (int)$row['servicio_id'];

$upd = $conn->prepare("UPDATE links_cortos SET clicks = clicks + 1 WHERE codigo = ?");
$upd->bind_param('s', $codigo);
$upd->execute();
$upd->close();

$hash = nubira_encriptar_id($servicio_id);
header('Location: /detalle-servicio/' . $hash, true, 302);
exit;
