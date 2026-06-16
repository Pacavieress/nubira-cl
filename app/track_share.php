<?php
// Registra un share real (acción del usuario) en shares_servicio. Opción B:
// se llama desde el modal en cada clic (descargar/copiar/compartir), NO en los previews.
// Público (los compartidos pueden ser de visitantes anónimos). INSERT defensivo.
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/seguridad_url.php';

header('Content-Type: application/json');

$servicio_id = nubira_desencriptar_id($_POST['id'] ?? '');
$formato = mb_substr((string)($_POST['f'] ?? ''), 0, 10);
if ($servicio_id <= 0) { echo '{"ok":false}'; exit; }

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (!preg_match('/bot|crawl|spider|slurp/i', $ua)) {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    $uap = mb_substr($ua, 0, 255);
    // @-silenciado: si la tabla no existe en prod, no rompe la respuesta.
    if ($ins = @$conn->prepare("INSERT INTO shares_servicio (servicio_id, formato, ip, user_agent) VALUES (?,?,?,?)")) {
        $ins->bind_param('isss', $servicio_id, $formato, $ip, $uap);
        @$ins->execute();
        $ins->close();
    }
}
echo '{"ok":true}';
