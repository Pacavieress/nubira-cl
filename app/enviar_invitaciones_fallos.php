<?php
require_once __DIR__ . '/../app/conexion.php';
require_once __DIR__ . '/../app/correo.php'; // Usa tu PHPMailer configurado

date_default_timezone_set('America/Santiago');

$sql = "SELECT id, correo FROM interesados_registro WHERE invitado = 0 LIMIT 30";
$res = $conn->query($sql);

if (!$res || $res->num_rows === 0) {
    echo "✅ No hay interesados pendientes.\n";
    exit;
}

while ($row = $res->fetch_assoc()) {
    $id      = (int)$row['id'];
    $correo  = strtolower(trim($row['correo']));

    // Validar formato
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        echo "⚠️ Correo inválido: $correo\n";
        continue;
    }

    $asunto  = "💡 Crea tu cuenta gratuita en Nubira.cl";
    $mensaje = "
    <div style='font-family:Arial,sans-serif;line-height:1.5;color:#333'>
      <p>Hola 👋</p>
      <p>Vimos que intentaste ingresar a <b>Nubira.cl</b> pero aún no tienes una cuenta registrada.</p>
      <p>En solo 1 minuto puedes crearla y acceder a:</p>
      <ul>
        <li>📘 Apuntes de tu universidad</li>
        <li>🤝 Servicios y clases particulares</li>
        <li>🚀 Oportunidades laborales y de práctica</li>
      </ul>
      <p style='text-align:center'>
        <a href='https://nubira.cl/register?email=" . urlencode($correo) . "'
           style='background:#54A6D8;color:white;padding:12px 20px;
           border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block'>
           Crear mi cuenta
        </a>
      </p>
      <p style='font-size:12px;color:#888;margin-top:10px;text-align:center'>
        Si ya tienes cuenta, ignora este mensaje.<br>
        — El equipo Nubira 💙
      </p>
    </div>
    ";

    // Registrar intento
    file_put_contents(LOG_PATH, date("Y-m-d H:i:s") . " - Enviando invitación automática a $correo\n", FILE_APPEND);

    $ok = enviarCorreo($correo, $asunto, $mensaje);

    if ($ok) {
        $upd = $conn->prepare("UPDATE interesados_registro SET invitado = 1 WHERE id = ?");
        $upd->bind_param("i", $id);
        $upd->execute();
        $upd->close();

        echo "📨 Invitación enviada a $correo\n";
        file_put_contents(LOG_PATH, date("Y-m-d H:i:s") . " - Resultado: OK ($correo)\n", FILE_APPEND);
    } else {
        echo "⚠️ Error al enviar a $correo\n";
        file_put_contents(LOG_PATH, date("Y-m-d H:i:s") . " - Resultado: FALLÓ ($correo)\n", FILE_APPEND);
    }

    // Espera 1 segundo entre envíos para evitar throttling SMTP
    sleep(1);
}

$conn->close();
echo "✅ Proceso finalizado.\n";
?>
