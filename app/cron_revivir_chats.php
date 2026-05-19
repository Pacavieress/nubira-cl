<?php
/**
 * NUBIRA 2.0 - ROBOT DE RETENCIÓN (CRON JOB)
 * Ubicación: public_html/app/cron_revivir_chats.php
 * Función: 
 * - Fase 1 (24h): Avisa al tutor de un chat ignorado.
 * - Fase 2 (72h): Pausa el anuncio en la vitrina por inactividad.
 */

ini_set('display_errors', 0); // Ocultar errores en producción

// 1. SEGURIDAD BÁSICA
$token_secreto = "nubira_cron_2026"; 
$es_cli = (php_sapi_name() === 'cli'); 
$token_valido = (isset($_GET['token']) && $_GET['token'] === $token_secreto);

if (!$es_cli && !$token_valido) {
    http_response_code(403);
    die("Acceso denegado. Este archivo solo corre en segundo plano.");
}

require_once __DIR__ . '/conexion.php';
$ruta_correo = __DIR__ . '/correo.php';
if (file_exists($ruta_correo)) {
    require_once $ruta_correo;
} else {
    die("Error: No se encuentra el motor de correos.");
}

echo "Iniciando Robot de Retención Nubira 2.0...<br><br>";
$enviados_24h = 0;
$pausados_48h = 0;

// --- SEGURO ANTI-MADRUGADA NUBIRA 2.0 ---
// Obtenemos la hora actual en Chile
$zona_horaria = new DateTimeZone('America/Santiago');
$fecha_actual = new DateTime('now', $zona_horaria);
$hora_actual = (int)$fecha_actual->format('G'); // Devuelve la hora en formato 24h (ej: 4, 14, 23)

// Si la hora es menor a las 8 AM o mayor a las 22 (10 PM), el robot detiene la fase de correos
if ($hora_actual < 8 || $hora_actual > 22) {
    echo "<strong>Robot dormido. Son las " . $fecha_actual->format('H:i') . ". Evitando enviar correos de madrugada.</strong><br>";
    echo "PROCESO TERMINADO.";
    exit; // Detiene la ejecución completa del archivo
}
// ----------------------------------------

// =====================================================================
// FASE 1: AVISO AMISTOSO (24 a 48 Horas)
// =====================================================================
echo "<strong>--- Ejecutando Fase 1: Recordatorios de 24 horas ---</strong><br>";

$sql_24 = "
    SELECT 
        c.id AS chat_id, c.creado_en,
        s.titulo AS titulo_servicio,
        v.email AS email_tutor, v.nombre AS nombre_tutor,
        a.nombre AS nombre_estudiante
    FROM conversaciones c
    JOIN servicios s ON c.servicio_id = s.id
    JOIN alumnos v ON c.vendedor_id = v.id
    JOIN alumnos a ON c.comprador_id = a.id
    WHERE c.estado = 'activa'
      AND c.primera_respuesta_minutos IS NULL
      AND c.creado_en <= DATE_SUB(NOW(), INTERVAL 24 HOUR)
      AND c.creado_en >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
";

$res_24 = $conn->query($sql_24);

if ($res_24 && $res_24->num_rows > 0) {
    while ($row = $res_24->fetch_assoc()) {
        $email = trim($row['email_tutor']);
        if (empty($email)) continue;
        
        $nombreTutorCorto = explode(' ', trim($row['nombre_tutor']))[0];
        $nombreEstCorto = explode(' ', trim($row['nombre_estudiante']))[0];
        
        $html = "
            <p>¡Hola <strong>$nombreTutorCorto</strong>!</p>
            <p>Notamos que <strong>$nombreEstCorto</strong> te escribió ayer interesado en tu servicio <strong>\"" . htmlspecialchars($row['titulo_servicio']) . "\"</strong> y aún no le has respondido.</p>
            
            <div style='background-color:#FFFBEB; border-left: 4px solid #F59E0B; padding: 15px; margin: 20px 0;'>
                <p style='margin:0; font-weight:bold; color:#92400E; font-size:15px;'>El tiempo es dinero 💸</p>
                <p style='margin:5px 0 0 0; color:#B45309; font-size:14px;'>Los estudiantes suelen contratar a otros tutores si no reciben respuesta en las primeras horas. ¡Todavía estás a tiempo de asegurar esta venta!</p>
            </div>
            
        <p style='color: #64748B; font-size: 13px;'><em>Nota: Si no respondes en un máximo de 48 horas, tu anuncio se pausará automáticamente para proteger la experiencia de los estudiantes.</em></p>
        ";

      $linkChat = "https://nubira.cl/app/chat_previo_contrato.php?id=" . $row['chat_id'];
        $cuerpo = plantillaMaestra("Tienes un estudiante esperando ⏳", $html, "Responder Mensaje Ahora", $linkChat);
        
        if (_enviarEmailBase($email, "¡No pierdas tu venta! Alguien espera tu respuesta", $cuerpo)) {
            $enviados_24h++;
            echo "Aviso enviado a: $email (Chat ID: {$row['chat_id']})<br>";
        }
    }
} else {
    echo "No hay chats ignorados en la ventana de 24 hrs.<br>";
}

// =====================================================================
// FASE 2: CONSECUENCIA DE INACTIVIDAD (+48 Horas)
// =====================================================================
echo "<br><strong>--- Ejecutando Fase 2: Pausa de anuncios inactivos (72+ horas) ---</strong><br>";

// Buscamos chats con más de 72 horas sin respuesta, donde el servicio SIGA activo ("aprobado")
$sql_72 = "
    SELECT 
        c.id AS chat_id, s.id AS servicio_id, s.titulo AS titulo_servicio,
        v.email AS email_tutor, v.nombre AS nombre_tutor
    FROM conversaciones c
    JOIN servicios s ON c.servicio_id = s.id
    JOIN alumnos v ON c.vendedor_id = v.id
    WHERE c.estado = 'activa'
      AND c.primera_respuesta_minutos IS NULL
      AND c.creado_en <= DATE_SUB(NOW(), INTERVAL 48 HOUR)
      AND s.estado = 'aprobado' 
";

$res_72 = $conn->query($sql_72);

if ($res_72 && $res_72->num_rows > 0) {
    while ($row = $res_72->fetch_assoc()) {
        $servicio_id = $row['servicio_id'];
        $email = trim($row['email_tutor']);
        
        // 1. Pausamos el anuncio (Motor de vitrina limpia)
        $stmt_pausar = $conn->prepare("UPDATE servicios SET estado = 'pausado' WHERE id = ?");
        $stmt_pausar->bind_param("i", $servicio_id);
        $stmt_pausar->execute();
        $stmt_pausar->close();

        // 2. Avisamos al tutor de la pausa
        if (!empty($email)) {
            $nombreTutorCorto = explode(' ', trim($row['nombre_tutor']))[0];
            
            $html_pausa = "
                <p>Hola <strong>$nombreTutorCorto</strong>,</p>
                <p>Han pasado más de 48 horas sin respuesta en tu anuncio <strong>\"" . htmlspecialchars($row['titulo_servicio']) . "\"</strong>.</p>
                
                <div style='background-color:#FEF2F2; border-left: 4px solid #EF4444; padding: 15px; margin: 20px 0;'>
                    <p style='margin:0; color:#991B1B;'>
                        Para evitar que más estudiantes te hablen sin recibir respuesta, <strong>hemos pausado tu anuncio temporalmente</strong>.
                    </p>
                </div>
                
                <p>¡No te preocupes! Cuando tengas tiempo de volver a Nubira, solo entra a tus publicaciones y dale al botón de reactivar.</p>
            ";

            $linkPerfil = "https://nubira.cl/mis-publicaciones"; // Ajustado a la ruta correcta que vimos
            $cuerpo_pausa = plantillaMaestra("Anuncio pausado por inactividad ⏸️", $html_pausa, "Ir a mis publicaciones", $linkPerfil);
            
            if (_enviarEmailBase($email, "Tu anuncio ha sido pausado - Nubira", $cuerpo_pausa)) {
                $pausados_48h++;
                echo "Servicio Pausado ID: $servicio_id | Email enviado a: $email<br>";
            }
        }
    }
} else {
    echo "No hay anuncios para pausar por inactividad (+72h).<br>";
}

echo "<br><strong>PROCESO TERMINADO.</strong><br>";
echo "Avisos enviados (24h): $enviados_24h<br>";
echo "Anuncios pausados (48h): $pausados_48h<br>";
?>