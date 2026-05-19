<?php
require_once 'conexion.php';

$id = intval($_GET['id'] ?? 0);
$url = $_GET['url'] ?? '/';

if ($id > 0 && filter_var($url, FILTER_VALIDATE_URL)) {
    $stmt = $conn->prepare("UPDATE banners SET clics = clics + 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}

header("Location: " . $url);
exit;
