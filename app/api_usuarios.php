<?php
// api_usuarios.php
require_once 'conexion.php';

// --- SEGURIDAD: Solo admin autenticado ---
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// --- RESUMEN POR DOMINIO ---
if (isset($_GET['resumen']) && $_GET['resumen'] === 'dominios') {
    $sql = "SELECT dominio, COUNT(*) as total FROM alumnos GROUP BY dominio ORDER BY total DESC";
    $res = $conn->query($sql);
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = $row;
    }
    header('Content-Type: application/json');
    echo json_encode($out);
    exit;
}

// --- PARÁMETROS DE FILTRO Y PAGINACIÓN ---
$pagina = max(1, intval($_GET['pagina'] ?? 1));
$por_pagina = min(100, max(10, intval($_GET['por_pagina'] ?? 25))); // Máximo 100 por página
$dominio = trim($_GET['dominio'] ?? '');
$buscar = trim($_GET['buscar'] ?? '');

// --- FILTROS DINÁMICOS ---
$where = [];
$params = [];
$types = '';

// Filtro por dominio
if ($dominio !== '') {
    $where[] = 'dominio = ?';
    $params[] = $dominio;
    $types .= 's';
}

// Filtro por búsqueda de texto (nombre o correo)
if ($buscar !== '') {
    $where[] = '(nombre LIKE ? OR correo LIKE ?)';
    $params[] = "%$buscar%";
    $params[] = "%$buscar%";
    $types .= 'ss';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// --- CONSULTA PRINCIPAL ---
$sql = "SELECT SQL_CALC_FOUND_ROWS id, nombre, correo, confirmado, rol, dominio
        FROM alumnos
        $where_sql
        ORDER BY id DESC
        LIMIT ? OFFSET ?";

$params[] = $por_pagina;
$params[] = ($pagina - 1) * $por_pagina;
$types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$usuarios = [];
while ($row = $res->fetch_assoc()) {
    $usuarios[] = $row;
}

// --- TOTAL PARA PAGINACIÓN ---
$total_res = $conn->query("SELECT FOUND_ROWS() as total");
$total = ($total_res && $row = $total_res->fetch_assoc()) ? intval($row['total']) : 0;

// --- RESPUESTA JSON ---
header('Content-Type: application/json');
echo json_encode([
    'usuarios' => $usuarios,
    'total' => $total,
    'pagina' => $pagina,
    'por_pagina' => $por_pagina
]);
?>
