<?php
/**
 * PROCESO: CREAR CONTRATO + NOTIFICACIONES (NUBIRA 2.0)
 * 
 * Mejoras aplicadas en esta versión:
 * - Prepared statements en TODAS las queries (sin excepciones)
 * - Flash messages + redirect en vez de die()
 * - $_SESSION['flash_error'] reemplaza ?error= en URL (anti-XSS)
 * - mb_substr + preg_split en formato de nombres (UTF-8 safe)
 * - try/catch independiente para correos (no rompe rollback)
 * - Logs de error sanitizados (no exponen detalles técnicos al usuario)
 * - FIX Beca/Cupón: Motor de validación estricto sincronizado Nubira Shield + consumo de cupón.
 */

// 1. CONFIGURACIÓN
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/helpers/cupones.php';

// =========================================================
// HELPER: Redirect con flash error y exit
// =========================================================
function flash_error_redirect($mensaje, $url) {
    $_SESSION['flash_error'] = $mensaje;
    header('Location: ' . $url);
    exit;
}

// =========================================================
// HELPER: Formatear nombre con privacidad (UTF-8 safe)
// =========================================================
function formatearNombrePrivado($nombreCompleto) {
    $partes = preg_split('/\s+/u', trim($nombreCompleto), -1, PREG_SPLIT_NO_EMPTY);
    $resultado = $partes[0] ?? 'Tutor Nubira';
    if (count($partes) > 1) {
        $resultado .= ' ' . mb_substr($partes[1], 0, 1, 'UTF-8') . '.';
    }
    return htmlspecialchars($resultado, ENT_QUOTES, 'UTF-8');
}

// 2. SEGURIDAD BASE
if (!isset($_SESSION['usuario_id'])) { 
    header('Location: /login'); 
    exit; 
}

if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    flash_error_redirect('Tu sesión expiró. Por favor, intenta nuevamente.', '/vitrina');
}

// 3. DATOS DE ENTRADA (Solo confiamos en IDs y textos)
$comprador_id = (int)$_SESSION['usuario_id'];
$servicio_id  = (int)($_POST['servicio_id'] ?? 0);
$vendedor_id  = (int)($_POST['vendedor_id'] ?? 0);
$fecha_clase_input = trim($_POST['fecha_clase'] ?? '');
$notas        = htmlspecialchars(trim($_POST['notas'] ?? ''), ENT_QUOTES, 'UTF-8');
$rol          = $_SESSION['rol'] ?? 'alumno';
$es_admin     = ($rol === 'admin');
$codigo_beca  = isset($_POST['codigo_beca']) ? strtoupper(trim(htmlspecialchars(strip_tags($_POST['codigo_beca']), ENT_QUOTES, 'UTF-8'))) : '';

// Precio que el usuario VIO en pantalla (solo para validación anti-trampa)
$precio_esperado_usuario = isset($_POST['monto']) ? (int)$_POST['monto'] : (int)($_POST['precio_original'] ?? 0);

// URL de retorno en caso de error (siempre disponible)
$url_retorno = "/app/contratar_servicio.php?servicio_id=" . $servicio_id;

// Validación de fecha+hora del slot reservado
if (empty($fecha_clase_input) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fecha_clase_input)) {
    flash_error_redirect('Debes seleccionar una fecha y hora para tu clase.', $url_retorno);
}
$ts = strtotime($fecha_clase_input);
if ($ts === false || $ts < time()) {
    flash_error_redirect('La fecha seleccionada no es válida o ya pasó.', $url_retorno);
}
$fecha_clase_sql = date('Y-m-d H:i:s', $ts);

// Para compatibilidad con campo legacy fecha_estimada (lo guardamos también)
$fecha_estimada_sql = $fecha_clase_sql;

if ($servicio_id <= 0 || $vendedor_id <= 0) {
    flash_error_redirect('Faltan datos para procesar la solicitud.', '/vitrina');
}
if (!$es_admin && $comprador_id === $vendedor_id) {
    flash_error_redirect('No puedes contratar tu propio servicio.', '/vitrina');
}

// 4. TRANSACCIÓN SEGURA Y BLOQUEO PESIMISTA (Anti-Race Condition)
$conn->begin_transaction();
try {
    // A) LA BASE DE DATOS COMO ÚNICA FUENTE DE VERDAD + BLOQUEO
    $stmt = $conn->prepare("SELECT titulo, precio, precio_oferta, cupos_oferta, is_subvencionado FROM servicios WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->bind_param("i", $servicio_id);
    $stmt->execute();
    $serv = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$serv) throw new Exception("Servicio no encontrado.");

    $titulo = $serv['titulo'];
    $precio_real = (int)$serv['precio'];
    $is_oferta_db = ($serv['is_subvencionado'] == 1 && $serv['cupos_oferta'] > 0);

    // ==========================================
    // [NUBIRA] MATEMÁTICA FINANCIERA CENTRAL
    // ==========================================
    $monto_final = 0;
    $monto_subsidio = 0;

    // Consultar la comisión actual en vivo desde el panel de admin
    $stmtCom = $conn->prepare("SELECT valor FROM configuracion WHERE clave = 'comision_plataforma'");
    $stmtCom->execute();
    $stmtCom->bind_result($val_com);
    $stmtCom->fetch();
    $stmtCom->close();
    $porcentaje_comision = $val_com !== null ? (int)$val_com : 0;

   // B) CÁLCULO ESTRICTO DE PRECIOS Y OFERTAS DE BD
    // [FIX NUBIRA] El cupo NO se descuenta aquí. Se descontará exclusivamente en el Webhook de Mercado Pago al recibir el dinero.
    if ($is_oferta_db) {
        $monto_final = (int)$serv['precio_oferta'];
        $monto_subsidio += ($precio_real - $monto_final);
    } else {
        $monto_final = $precio_real;
    }

    // C) APLICAR CUPÓN DE DESCUENTO (BECA) CON BLOQUEO (FIX NUBIRA SHIELD)
    // Lógica extraída a helpers/cupones.php — el FOR UPDATE y el consumo
    // (usos_actuales+1) corren dentro de esta misma transacción, sin cambios.
    $resultado_cupon = nb_aplicar_cupon($conn, $codigo_beca, $servicio_id, $monto_final);
    if (!$resultado_cupon['valido']) {
        throw new Exception($resultado_cupon['motivo']);
    }
    $monto_final = $resultado_cupon['monto_final'];
    $monto_subsidio += $resultado_cupon['descuento'];

    // D) VALIDACIÓN ANTI-TRAMPA
    if ($precio_esperado_usuario < $monto_final) {
        throw new Exception("El precio del servicio cambió o la beca caducó mientras estabas en pantalla. Por favor, vuelve a intentarlo.");
    }


    // E) CÁLCULO DE COMISIÓN PLATAFORMA
    $valor_total_clase = $monto_final + $monto_subsidio;
    $monto_comision = (int)(($valor_total_clase * $porcentaje_comision) / 100);

    $estado = ($monto_final == 0) ? 'en_progreso' : 'pendiente_pago';
    $monto_aceptado = 0;
// E.1) [NUBIRA 2.0] Validar slot disponible en tiempo real (anti-race condition)
    // Obtener duración del servicio
    $stmt_dur = $conn->prepare("SELECT duracion_minutos FROM servicios WHERE id = ? LIMIT 1");
    $stmt_dur->bind_param("i", $servicio_id);
    $stmt_dur->execute();
    $stmt_dur->bind_result($duracion_minutos);
    $stmt_dur->fetch();
    $stmt_dur->close();
    $duracion_minutos = (int)($duracion_minutos ?: 60);

    // Calcular fin del slot solicitado
    $slot_ini_ts = strtotime($fecha_clase_sql);
    $slot_fin_ts = $slot_ini_ts + ($duracion_minutos * 60);
    $slot_fin_sql = date('Y-m-d H:i:s', $slot_fin_ts);

    // Verificar que el slot esté dentro del horario publicado del tutor
    $stmt_hj = $conn->prepare("SELECT horarios_json FROM servicios WHERE id = ? LIMIT 1");
    $stmt_hj->bind_param("i", $servicio_id);
    $stmt_hj->execute();
    $stmt_hj->bind_result($horarios_json_raw);
    $stmt_hj->fetch();
    $stmt_hj->close();
    if (empty($horarios_json_raw)) {
        throw new Exception("Este servicio no acepta reservas en línea. Coordina con el tutor por chat.");
    }
    $horarios_validar = json_decode($horarios_json_raw, true);
    $dias_es = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    $dia_solicitado = $dias_es[(int)date('w', $slot_ini_ts)];
    if (empty($horarios_validar[$dia_solicitado])) {
        throw new Exception("El tutor no tiene disponibilidad publicada para ese día.");
    }

    // ¿Hay reservas que se solapen con este slot? (FOR UPDATE bloquea la fila)
    $stmt_solape = $conn->prepare("
        SELECT id FROM reservas_slots 
        WHERE tutor_id = ? 
          AND estado IN ('reservado','en_curso')
          AND fecha_clase < ?
          AND DATE_ADD(fecha_clase, INTERVAL duracion_minutos MINUTE) > ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt_solape->bind_param("iss", $vendedor_id, $slot_fin_sql, $fecha_clase_sql);
    $stmt_solape->execute();
    $res_solape = $stmt_solape->get_result();
    if ($res_solape->num_rows > 0) {
        $stmt_solape->close();
        throw new Exception("Lo sentimos, alguien acaba de reservar esa hora. Por favor elige otra.");
    }
    $stmt_solape->close();
    // F) CREAR CONTRATO
    $sqlC = "INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, monto_subsidio, monto_comision, monto_aceptado, fecha_estimada, notas, estado, fecha_creacion) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmtC = $conn->prepare($sqlC);
    $stmtC->bind_param("iiiiiiisss", $servicio_id, $comprador_id, $vendedor_id, $monto_final, $monto_subsidio, $monto_comision, $monto_aceptado, $fecha_estimada_sql, $notas, $estado);
    if (!$stmtC->execute()) throw new Exception("No se pudo crear el contrato.");
    $contrato_id = $conn->insert_id;
    $stmtC->close();
    
    // F.1) [NUBIRA 2.0] Crear el slot de reserva concreto
    $estado_reserva = 'reservado';
    $stmtR = $conn->prepare("INSERT INTO reservas_slots (contrato_id, servicio_id, tutor_id, alumno_id, fecha_clase, duracion_minutos, estado) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtR->bind_param("iiiisis", $contrato_id, $servicio_id, $vendedor_id, $comprador_id, $fecha_clase_sql, $duracion_minutos, $estado_reserva);
    if (!$stmtR->execute()) throw new Exception("No se pudo registrar la reserva del horario.");
    $stmtR->close();

    // G) CREAR/VINCULAR CONVERSACIÓN
    $chat_id = 0;
    $stmtCheck = $conn->prepare("SELECT id FROM conversaciones WHERE servicio_id=? AND ((comprador_id=? AND vendedor_id=?) OR (comprador_id=? AND vendedor_id=?)) LIMIT 1");
    $stmtCheck->bind_param("iiiii", $servicio_id, $comprador_id, $vendedor_id, $vendedor_id, $comprador_id);
    $stmtCheck->execute();
    $stmtCheck->bind_result($chat_id);
    $stmtCheck->fetch();
    $stmtCheck->close();

    if (!$chat_id) {
        $stmtNew = $conn->prepare("INSERT INTO conversaciones (servicio_id, comprador_id, vendedor_id, contrato_id, creado_en, estado) VALUES (?, ?, ?, ?, NOW(), 'activa')");
        $stmtNew->bind_param("iiii", $servicio_id, $comprador_id, $vendedor_id, $contrato_id);
        $stmtNew->execute();
        $chat_id = $conn->insert_id;
        $stmtNew->close();
    } else {
        $stmt_link = $conn->prepare("UPDATE conversaciones SET contrato_id = ? WHERE id = ?");
        $stmt_link->bind_param("ii", $contrato_id, $chat_id);
        $stmt_link->execute();
        $stmt_link->close();
    }

    $stmt_link2 = $conn->prepare("UPDATE contratos SET conversacion_id = ? WHERE id = ?");
    $stmt_link2->bind_param("ii", $chat_id, $contrato_id);
    $stmt_link2->execute();
    $stmt_link2->close();

    // H) MENSAJE AUTOMÁTICO INICIAL
    $msg = "Hola, he solicitado este servicio (" . ($monto_final == 0 ? "Gratis" : "$" . number_format($monto_final, 0, ',', '.')) . ").";
    if ($notas) $msg .= "\n\nNota: " . $notas;

    $stmtM = $conn->prepare("INSERT INTO mensajes (conversacion_id, contrato_id, remitente_id, mensaje, enviado_en) VALUES (?, ?, ?, ?, NOW())");
    $stmtM->bind_param("iiis", $chat_id, $contrato_id, $comprador_id, $msg);
    $stmtM->execute();
    $stmtM->close();

    // I) CONFIRMAR TODOS LOS CAMBIOS Y LIBERAR LA TABLA
    $conn->commit();

    // =========================================================
    // 5. ENVÍO DE CORREOS (try/catch independiente)
    // =========================================================
    try {
        $sql_users = $conn->prepare("SELECT c.nombre AS comp_nom, c.correo AS comp_mail, v.nombre AS vend_nom, v.correo AS vend_mail FROM alumnos c, alumnos v WHERE c.id = ? AND v.id = ?");
        $sql_users->bind_param("ii", $comprador_id, $vendedor_id);
        $sql_users->execute();
        $users = $sql_users->get_result()->fetch_assoc();
        $sql_users->close();

        if ($users) {
            $vendedor_privado = formatearNombrePrivado($users['vend_nom']);
            $comprador_privado = formatearNombrePrivado($users['comp_nom']);

            $titulo_email_tutor = $titulo;
            $monto_email_tutor = $monto_final;

            if ($monto_subsidio > 0 || $porcentaje_comision > 0) {
                $ganancia_tutor = $valor_total_clase - $monto_comision;
                $monto_email_tutor = $ganancia_tutor;
                $titulo_email_tutor .= " (Valor Total: $" . number_format($valor_total_clase, 0, ',', '.') . " | Comisión Nubira: -$" . number_format($monto_comision, 0, ',', '.') . " | Tú recibes: $" . number_format($ganancia_tutor, 0, ',', '.') . ")";
            }

            if (function_exists('enviarCorreoNuevaVenta')) {
                enviarCorreoNuevaVenta($users['vend_mail'], $vendedor_privado, $comprador_privado, $titulo_email_tutor, $monto_email_tutor, $contrato_id);
            }
            if (function_exists('enviarCorreoConfirmacionCompra')) {
                enviarCorreoConfirmacionCompra($users['comp_mail'], $comprador_privado, $vendedor_privado, $titulo, $contrato_id);
            }

            require_once __DIR__ . '/enviar_push_nubira.php';
            $push_comp = explode(' ', trim($users['comp_nom']))[0];
            $push_vend = explode(' ', trim($users['vend_nom']))[0];
            enviar_push_nubira($vendedor_id, '🎉 ¡Nueva venta!', $push_comp . ' contrató tu servicio: ' . $titulo, '/clases-vendidas');
            enviar_push_nubira($comprador_id, '✅ Contrato creado', 'Contrataste a ' . $push_vend . '. Te avisaremos cuando confirme.', '/mis-contratos');
        }
    } catch (Exception $mailErr) {
        error_log("Nubira | Fallo correo contrato #{$contrato_id}: " . $mailErr->getMessage());
    }

    // =========================================================
    // 6. REDIRECCIÓN UX: ESTILO AIRBNB
    // =========================================================
    if ($monto_final == 0) {
        $url_destino = "/app/mini_aula.php?id=" . $contrato_id;
        if (!headers_sent()) {
            header("Location: " . $url_destino);
        } else {
            echo "<script>window.location.href='" . htmlspecialchars($url_destino, ENT_QUOTES, 'UTF-8') . "';</script>";
        }
        exit;
    } else {
        echo "<!DOCTYPE html><html><head><title>Procesando pago seguro | Nubira</title>";
        echo "<meta name='viewport' content='width=device-width, initial-scale=1'>";
        echo "<style>body{background:#f9fafb;display:flex;justify-content:center;align-items:center;height:100vh;font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;margin:0;}</style>";
        echo "</head><body>";
        echo "<div style='text-align:center;'>
                <div style='width: 48px; height: 48px; border: 4px solid #e0f2fe; border-top: 4px solid #54A6D8; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 24px;'></div>
                <h2 style='color:#111827; margin-bottom: 8px; font-weight: 600;'>Asegurando tu pago...</h2>
                <p style='color:#6b7280; font-size: 14px;'>Serás redirigido a la pasarela en un instante.</p>
                <style>@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>
              </div>";
        echo "<form id='formPasarela' action='/app/iniciar_pago_servicio.php?id=" . (int)$contrato_id . "' method='POST'>
                <input type='hidden' name='id' value='" . (int)$contrato_id . "'>
                <input type='hidden' name='contrato_id' value='" . (int)$contrato_id . "'>
              </form>";
        echo "<script>setTimeout(() => document.getElementById('formPasarela').submit(), 800);</script>";
        echo "</body></html>";
        exit;
    }

} catch (Exception $e) {
    $conn->rollback();
    error_log("Nubira | Error checkout (servicio={$servicio_id}, user={$comprador_id}): " . $e->getMessage());
    flash_error_redirect($e->getMessage(), $url_retorno);
}
?>