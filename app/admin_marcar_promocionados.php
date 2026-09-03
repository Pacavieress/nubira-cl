<?php
/**
 * ENDPOINT: MARCAR SERVICIOS COMO PROMOCIONADOS (rotación de tutores)
 * ESTADO: BLINDADO (CSRF + RBAC)
 *
 * Registra en copiloto_promociones qué servicios el admin ACABA de publicar
 * en el carrusel de marketing — acción deliberada y separada de "armar/
 * descargar el carrusel" (armar/descargar no implica que ya se publicó de
 * verdad en Instagram, por eso es un botón aparte, no automático).
 */
require_once __DIR__ . '/init_sesion.php';
header('Content-Type: application/json; charset=utf-8');

// 1. CORTAFUEGOS DE MÉTODO
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

// 2. CORTAFUEGOS DE ROL
if (($_SESSION['rol'] ?? '') !== 'admin') {
    echo json_encode(['ok' => false, 'error' => 'Acceso denegado.']);
    exit;
}

// 3. CSRF
$csrf_post = $_POST['csrf_token'] ?? '';
if (empty($csrf_post) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_post)) {
    echo json_encode(['ok' => false, 'error' => 'Token de seguridad inválido.']);
    exit;
}

// 4. AUTO-MIGRACIÓN: tabla copiloto_promociones (mismo criterio que el resto
// del Copiloto — nunca asumir que otro archivo ya la creó).
$conn->query("CREATE TABLE IF NOT EXISTS copiloto_promociones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    servicio_id INT NOT NULL,
    canal VARCHAR(30) NOT NULL DEFAULT 'instagram',
    fecha_promocionado DATETIME DEFAULT CURRENT_TIMESTAMP,
    marcado_por INT NULL,
    KEY idx_servicio (servicio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 5. VALIDAR IDS — acepta array (servicio_ids[]=1&servicio_ids[]=2, lo que
// manda fetch con URLSearchParams) o CSV (servicio_ids=1,2,3).
$raw = $_POST['servicio_ids'] ?? '';
$ids_raw = is_array($raw) ? $raw : explode(',', (string)$raw);

$ids = [];
foreach ($ids_raw as $v) {
    $v = trim((string)$v);
    if ($v !== '' && ctype_digit($v) && (int)$v > 0) {
        $ids[] = (int)$v;
    }
}
$ids = array_values(array_unique($ids));

if (empty($ids)) {
    echo json_encode(['ok' => false, 'error' => 'No se recibieron servicios válidos.']);
    exit;
}

// 6. INSERT parametrizado, uno por servicio.
$marcado_por = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : null;
$stmt = $conn->prepare("INSERT INTO copiloto_promociones (servicio_id, canal, marcado_por) VALUES (?, 'instagram', ?)");

$marcados = 0;
foreach ($ids as $id) {
    $stmt->bind_param('ii', $id, $marcado_por);
    if ($stmt->execute()) {
        $marcados++;
    }
}
$stmt->close();

// 7. RESPUESTA
echo json_encode(['ok' => true, 'marcados' => $marcados], JSON_UNESCAPED_UNICODE);
