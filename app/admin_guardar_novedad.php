<?php
/**
 * ENDPOINT: GUARDAR NOVEDAD (Fase 2 Marketing/Cards)
 * ESTADO: BLINDADO (CSRF + RBAC)
 * Inserta en `novedades` y devuelve las URLs de imagen POST/HISTORY listas para
 * incrustar en <img src> — la primera carga a img_novedad.php es la que genera
 * el JPG real (cache miss), este endpoint no toca el generador GD directamente.
 */
require_once __DIR__ . '/init_sesion.php';
require_once __DIR__ . '/seguridad_url.php'; // nubira_encriptar_id()
header('Content-Type: application/json; charset=utf-8');

// 1. CORTAFUEGOS DE MÉTODO
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

// 2. CORTAFUEGOS DE ROL
if (($_SESSION['rol'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Acceso denegado.']);
    exit;
}

// 3. CSRF
$csrf_post = $_POST['csrf_token'] ?? '';
if (empty($csrf_post) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_post)) {
    echo json_encode(['success' => false, 'error' => 'Token de seguridad inválido.']);
    exit;
}

// 4. SANITIZACIÓN + VALIDACIÓN (nunca confiar solo en el maxlength del navegador)
$titulo = trim((string)($_POST['titulo'] ?? ''));
$cuerpo = trim((string)($_POST['cuerpo'] ?? ''));

if ($titulo === '' || mb_strlen($titulo, 'UTF-8') > 120) {
    echo json_encode(['success' => false, 'error' => 'El título es obligatorio y debe tener máximo 120 caracteres.']);
    exit;
}
if ($cuerpo === '' || mb_strlen($cuerpo, 'UTF-8') > 280) {
    echo json_encode(['success' => false, 'error' => 'El cuerpo es obligatorio y debe tener máximo 280 caracteres.']);
    exit;
}

// 5. AUTO-MIGRACIÓN: tabla novedades (mismo criterio que img_novedad.php — nunca asumir
// que otro archivo ya se ejecutó antes y la creó).
$conn->query("CREATE TABLE IF NOT EXISTS novedades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(120) NOT NULL,
    cuerpo TEXT NOT NULL,
    icono VARCHAR(10) NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 6. INSERCIÓN
$stmt = $conn->prepare("INSERT INTO novedades (titulo, cuerpo) VALUES (?, ?)");
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Error de base de datos.']);
    exit;
}
$stmt->bind_param('ss', $titulo, $cuerpo);
$stmt->execute();
$id = $conn->insert_id;
$stmt->close();

// 7. HASH + URLS (misma función que ya usa img_novedad.php / nb_obtener_imagen_novedad())
$hash = nubira_encriptar_id($id);

echo json_encode([
    'success'      => true,
    'id'           => $id,
    'post_url'     => "/api/img/novedad/{$hash}/post.jpg",
    'history_url'  => "/api/img/novedad/{$hash}/history.jpg",
]);
