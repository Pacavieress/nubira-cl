<?php
// Registra un share real (acción del usuario) en shares_servicio, shares_apunte o
// shares_desafio, según ?tipo=. Opción B: se llama desde el modal en cada clic
// (descargar/copiar/compartir), NO en los previews. Público (los compartidos pueden
// ser de visitantes anónimos). INSERT defensivo. Default 'servicio' — no rompe el
// llamado existente de modal_compartir_servicio.php, que no manda ?tipo=.
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/seguridad_url.php';

header('Content-Type: application/json');

$tipo = $_POST['tipo'] ?? 'servicio';
if (!in_array($tipo, ['apunte', 'servicio', 'desafio'], true)) $tipo = 'servicio';
$formato = mb_substr((string)($_POST['f'] ?? ''), 0, 10);
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Desafío: el "id" es un materia_slug público (calculo, fisica, ...), no un ID de BD
// que haya que desencriptar — camino propio, sin nubira_desencriptar_id().
if ($tipo === 'desafio') {
    $materia_slug = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['id'] ?? ''));
    if ($materia_slug === '') { echo '{"ok":false}'; exit; }

    if (!preg_match('/bot|crawl|spider|slurp/i', $ua)) {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
        $uap = mb_substr($ua, 0, 255);
        // @-silenciado: si la tabla no existe en prod, no rompe la respuesta.
        if ($ins = @$conn->prepare("INSERT INTO shares_desafio (materia_slug, formato, ip, user_agent) VALUES (?,?,?,?)")) {
            $ins->bind_param('ssss', $materia_slug, $formato, $ip, $uap);
            @$ins->execute();
            $ins->close();
        }
    }
    echo '{"ok":true}';
    exit;
}

$entidad_id = nubira_desencriptar_id($_POST['id'] ?? '');
if ($entidad_id <= 0) { echo '{"ok":false}'; exit; }

if (!preg_match('/bot|crawl|spider|slurp/i', $ua)) {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    $uap = mb_substr($ua, 0, 255);
    $tabla = $tipo === 'apunte' ? 'shares_apunte' : 'shares_servicio';
    $columna = $tipo === 'apunte' ? 'apunte_id' : 'servicio_id';
    // @-silenciado: si la tabla no existe en prod, no rompe la respuesta.
    if ($ins = @$conn->prepare("INSERT INTO $tabla ($columna, formato, ip, user_agent) VALUES (?,?,?,?)")) {
        $ins->bind_param('isss', $entidad_id, $formato, $ip, $uap);
        @$ins->execute();
        $ins->close();
    }
}
echo '{"ok":true}';
