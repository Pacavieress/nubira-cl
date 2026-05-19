<?php
session_start();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../app/correo.php';

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header('Location: /');
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
date_default_timezone_set('America/Santiago');

echo "<body style='font-family:Arial;padding:30px;background:#f8fafc;color:#333'>";

echo "<h2>📨 Procesando interesados...</h2>";

$conn->query("
INSERT IGNORE INTO interesados_registro (correo, ip)
SELECT DISTINCT lf.correo, lf.ip
FROM login_fallos lf
LEFT JOIN alumnos a ON lf.correo = a.correo
WHERE a.id IS NULL
");

echo "<p>✅ Interesados nuevos registrados.</p>";

$sql = "SELECT id, correo FROM interesados_registro WHERE invitado = 0 LIMIT 20";
$res = $conn->query($sql);
if ($res->num_rows === 0) {
    echo "<p>🎉 No hay usuarios pendientes de invitar.</p>";
    echo "<a href='/admin/login-fallos' style='color:#54A6D8;text-decoration:none;'>← Volver</a>";
    exit;
}

$enviados = 0;
while ($row = $res->fetch_assoc()) {
    $id = $row['id'];
    $correo = strtolower(trim($row['correo']));

    $asunto = "💡 Crea tu cuenta gratuita en Nubira.cl";
    $mensaje = "
    <div style='font-family:Arial,sans-serif;line-height:1.5;color:#333'>
      <p>Hola 👋</p>
      <p>Intentaste ingresar a <b>Nubira.cl</b> pero aún no tienes una cuenta.</p>
      <p>En menos de un minuto puedes crearla y acceder a:</p>
      <ul>
        <li>📘 Apuntes de tu universidad</li>
        <li>🤝 Servicios y clases particulares</li>
        <li>🚀 Oportunidades laborales y de práctica</li>
      </ul>
      <p>
        <a href='https://nubira.cl/register?email=" . urlencode($correo) . "'
           style='background:#54A6D8;color:white;padding:10px 18px;
           border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block'>
           Crear mi cuenta
        </a>
      </p>
      <p style='font-size:12px;color:#777;margin-top:10px'>
        Si ya tienes cuenta, ignora este mensaje. 💙
      </p>
    </div>
    ";

    if (enviarCorreo($correo, $asunto, $mensaje)) {
        $upd = $conn->prepare("UPDATE interesados_registro SET invitado = 1 WHERE id = ?");
        $upd->bind_param("i", $id);
        $upd->execute();
        $enviados++;
    }
}

echo "<p>📤 Invitaciones enviadas: <b>$enviados</b></p>";
echo "<a href='/admin/login-fallos' style='color:#54A6D8;text-decoration:none;'>← Volver</a>";
echo "</body>";
?>
