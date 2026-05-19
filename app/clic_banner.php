<?php
session_start();
require_once __DIR__ . '/conexion.php';

if (empty($_GET['id'])) {
    http_response_code(400);
    exit('❌ ID inválido');
}

$banner_id = (int) $_GET['id'];
$url = !empty($_GET['url']) ? urldecode($_GET['url']) : '';
if (!preg_match('#^https?://#i', $url) && !empty($url)) {
    $url = 'https://' . $url;
}

// --- 1. Incrementar contador global ---
$stmt = $conn->prepare("UPDATE banners SET clics = clics + 1 WHERE id = ?");
$stmt->bind_param("i", $banner_id);
$stmt->execute();
$stmt->close();

// --- 2. Insertar registro detallado ---
$usuario_id = $_SESSION['usuario_id'] ?? null;
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

$stmt = $conn->prepare("INSERT INTO banner_clicks (banner_id, usuario_id, ip, url) VALUES (?, ?, ?, ?)");
$stmt->bind_param("iiss", $banner_id, $usuario_id, $ip, $url);
$stmt->execute();
$stmt->close();

$conn->close();

// --- 3. Redirigir al destino real ---
if (!empty($url)) {
    header("Location: " . $url);
    exit;
}

echo "✅ Clic registrado, pero sin URL de destino.";
