<?php
/**
 * BACKEND: ENVIAR MENSAJE (NUBIRA 2.0 - CLEAN & SECURE)
 * Misión: Mantener comunicación fluida y segura.
 */

ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
session_start();

// 1. CONEXIÓN (Ruta absoluta robusta)
require_once __DIR__ . '/conexion.php'; 

// 2. SEGURIDAD DE SESIÓN
if (!isset($_SESSION['usuario_id'])) { 
    echo json_encode(['success' => false, 'error' => 'Sesión expirada. Por favor, reingresa.']); 
    exit; 
}

$my_id = (int)$_SESSION['usuario_id'];
$my_name = $_SESSION['usuario_nombre'] ?? 'Usuario';

// 3. CAPTURA Y SANITIZACIÓN
$id_ref  = (int)($_POST['conversacion_id'] ?? $_POST['id'] ?? 0);
$mensaje = trim($_POST['mensaje'] ?? '');
$contexto = $_POST['contexto'] === 'aula' ? 'aula' : 'conversacion';

if ($id_ref <= 0 || empty($mensaje)) {
    echo json_encode(['success' => false, 'error' => 'Escribe un mensaje válido.']);
    exit;
}

// Gate express: bloquear a partir del 4to mensaje en esta conversación
$cnt_express = 0;
$es_express  = !empty($_SESSION['cuenta_express']);
if ($es_express && $contexto === 'conversacion') {
    $stmt_cnt = $conn->prepare("SELECT COUNT(*) FROM mensajes WHERE remitente_id = ? AND conversacion_id = ?");
    $stmt_cnt->bind_param("ii", $my_id, $id_ref);
    $stmt_cnt->execute();
    $stmt_cnt->bind_result($cnt_express);
    $stmt_cnt->fetch();
    $stmt_cnt->close();
    if ($cnt_express >= 2) {
        echo json_encode(['success' => false, 'requiere_completar' => true]);
        exit;
    }
}

// =========================================================================================
// [NUBIRA 2.0] LÍMITE DE 6 MENSAJES ANTES DE CONTRATAR (capa independiente, no altera DLP)
// Solo aplica al chat previo a la contratación. Una vez que existe contrato_id, o en el
// aula (contexto='aula'), el chat queda libre — mismo criterio de siempre.
// =========================================================================================
if ($contexto === 'conversacion') {
    $stmt_contrato = $conn->prepare("SELECT contrato_id FROM conversaciones WHERE id = ? LIMIT 1");
    $stmt_contrato->bind_param("i", $id_ref);
    $stmt_contrato->execute();
    $fila_conv = $stmt_contrato->get_result()->fetch_assoc();
    $stmt_contrato->close();

    if (empty($fila_conv['contrato_id'])) {
        $stmt_total = $conn->prepare("SELECT COUNT(*) AS total FROM mensajes WHERE conversacion_id = ? AND visible = 1");
        $stmt_total->bind_param("i", $id_ref);
        $stmt_total->execute();
        $total_mensajes = (int)($stmt_total->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt_total->close();

        if ($total_mensajes >= 6) {
            echo json_encode([
                'success' => false,
                'limite_alcanzado' => true,
                'error' => 'Llegaste al límite de mensajes antes de contratar. Si quieres seguir conversando, avanza con la contratación del servicio.'
            ]);
            exit;
        }
    }
}

// =========================================================================================
// 4. CAPA DLP NUBIRA (DATA LOSS PREVENTION) - ESTRICTO Y EDUCATIVO
// =========================================================================================
require_once __DIR__ . '/helpers/dlp.php';

$mensaje_lower = mb_strtolower($mensaje, 'UTF-8');

// Núcleo reutilizado del patrón 'telefono' (checks 5b/5d más abajo) — vive en helpers/dlp.php.
$nucleo_digitos_tel = nb_dlp_nucleo_digitos_tel();
$patrones_bloqueo   = nb_dlp_patrones();

// Bloquea el mensaje y registra el intento en dlp_intentos (no debe romper el flujo si falla)
// Enfoque Nubira 2.0 (actualizado): antes el mensaje era genérico a propósito para "no dar
// pistas". Ahora es específico por categoría (nombra el tipo de dato detectado: correo,
// teléfono, etc.) sin revelar el patrón/regex exacto que lo disparó — el usuario entiende
// qué pasó sin obtener un manual de cómo evadir el filtro la próxima vez.
function nb_dlp_bloquear($conn, $id_ref, $my_id, $mensaje, $categoria, $pattern_desc) {
    try {
        $stmt_dlp = $conn->prepare(
            "INSERT INTO dlp_intentos (conversacion_id, remitente_id, categoria, patron_matched, texto_intentado)
             VALUES (?, ?, ?, ?, ?)"
        );
        if ($stmt_dlp) {
            $patron_safe = mb_substr($pattern_desc, 0, 200);
            $stmt_dlp->bind_param("iisss", $id_ref, $my_id, $categoria, $patron_safe, $mensaje);
            $stmt_dlp->execute();
            $stmt_dlp->close();
        }
    } catch (Exception $e) {}

    $mensajes_dlp = [
        'email'              => '⚠️ Detectamos que intentaste compartir un correo electrónico. Por tu seguridad y la garantía de pago protegido, todo contacto debe quedar dentro de Nubira. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.',
        'telefono'           => '⚠️ Detectamos que intentaste compartir un número de teléfono. Por tu seguridad y la garantía de pago protegido, todo contacto debe quedar dentro de Nubira. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.',
        'redes'              => '⚠️ Detectamos que intentaste compartir una red social o app de mensajería externa. Por tu seguridad y la garantía de pago protegido, todo contacto debe quedar dentro de Nubira. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.',
        'banco'              => '⚠️ Detectamos que intentaste compartir datos bancarios o coordinar un pago fuera de Nubira. Los pagos solo deben hacerse a través de la plataforma para mantener tu Garantía Nubira. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.',
        'intencion_contacto' => '⚠️ Detectamos que intentaste coordinar contacto o encuentros fuera de Nubira. Por tu seguridad y la garantía de pago protegido, todo debe quedar dentro de la plataforma. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.',
        'identidad'          => '⚠️ Detectamos que intentaste compartir datos que permitirían identificarte o ser encontrado fuera de Nubira. Por tu seguridad, mantén la conversación dentro de la plataforma. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.',
        'urls'               => '⚠️ Detectamos que intentaste compartir un enlace externo. Por tu seguridad y la garantía de pago protegido, todo contacto debe quedar dentro de Nubira. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.',
    ];
    $mensaje_error = $mensajes_dlp[$categoria]
        ?? '⚠️ Por tu seguridad y la garantía de Nubira, no permitimos compartir identidad, redes ni medios de pago en el chat. Reescribe tu mensaje sin información externa.';

    echo json_encode([
        'success' => false,
        'error' => $mensaje_error
    ]);
    exit;
}

$categoria_bloqueada = nb_dlp_evaluar_patrones($mensaje_lower);
if ($categoria_bloqueada !== null) {
    nb_dlp_bloquear($conn, $id_ref, $my_id, $mensaje, $categoria_bloqueada, $patrones_bloqueo[$categoria_bloqueada]);
}

// 5b. "celular" con contexto: solo bloquea si aparece junto a una frase explícita de compartir número
//     o cerca (±25 caracteres, en unidades de caracteres UTF-8 — no bytes, para evitar desalinear la
//     ventana con tildes/ñ) de una secuencia de 7+ dígitos, reutilizando el núcleo de 'telefono'.
//     Evita el falso positivo de "biología celular" / "división celular" / "membrana celular".
$pos_celular = mb_stripos($mensaje_lower, 'celular', 0, 'UTF-8');
if ($pos_celular !== false) {
    $inicio_ventana = max(0, $pos_celular - 25);
    $ventana = mb_substr($mensaje_lower, $inicio_ventana, 25 + mb_strlen('celular', 'UTF-8') + 25, 'UTF-8');
    $celular_frase = '/\b(mi|tu|su)\s+celular\b|\bn[uú]mero\s+celular\b/i';

    if (preg_match($celular_frase, $ventana) || preg_match('/' . $nucleo_digitos_tel . '/', $ventana)) {
        nb_dlp_bloquear($conn, $id_ref, $my_id, $mensaje, 'intencion_contacto', 'celular (con contexto)');
    }
}

// 5c. "juntémonos"/"reunámonos" con contexto: solo bloquea si el mensaje también menciona una
//     plataforma externa (Zoom/Meet/Teams/Skype/WhatsApp/Telegram/Discord). La palabra sola no
//     debe bloquear la coordinación normal de horario de clase dentro de Nubira.
if (preg_match('/\b(junt[eé]monos|reun[aá]monos)\b/i', $mensaje_lower)) {
    $patron_plataformas = '/\b(zoom|meet|teams|skype|wh?a[ts]+s?[aá]pp?|wasap|watsap|whsatap|guatsap|wsp|wa\.me|telegram|tg|t\.me|discord)\b/i';
    if (preg_match($patron_plataformas, $mensaje_lower)) {
        nb_dlp_bloquear($conn, $id_ref, $my_id, $mensaje, 'intencion_contacto', 'juntemonos/reunamonos + plataforma externa');
    }
}

// 5d. Teléfono fraccionado en varios mensajes consecutivos: concatena con los últimos
//     mensajes (hasta 5) del MISMO remitente en esta conversación (ventana de 5 min) y
//     reaplica el patrón 'telefono' sin modificar — la adyacencia + el umbral de 7 dígitos
//     ya descartan casos como "a las 15" + "30 minutos" o "cuesta 10" + "000 pesos".
if (preg_match('/\d/', $mensaje_lower)) {
    $tabla_prev     = ($contexto === 'aula') ? 'chat_aula' : 'mensajes';
    $col_id_prev    = ($contexto === 'aula') ? 'contrato_id' : 'conversacion_id';
    $col_fecha_prev = ($contexto === 'aula') ? 'fecha' : 'enviado_en';

    $stmt_prev = $conn->prepare(
        "SELECT mensaje FROM $tabla_prev
         WHERE $col_id_prev = ? AND remitente_id = ? AND $col_fecha_prev > (NOW() - INTERVAL 5 MINUTE)
         ORDER BY id DESC LIMIT 5"
    );
    $stmt_prev->bind_param("ii", $id_ref, $my_id);
    $stmt_prev->execute();
    $previos = array_column($stmt_prev->get_result()->fetch_all(MYSQLI_ASSOC), 'mensaje');
    $stmt_prev->close();

    if (!empty($previos)) {
        $previos = array_reverse($previos); // orden cronológico: más viejo primero
        $texto_combinado = mb_strtolower(implode(' ', $previos), 'UTF-8') . ' ' . $mensaje_lower;
        if (preg_match($patrones_bloqueo['telefono'], $texto_combinado)) {
            nb_dlp_bloquear($conn, $id_ref, $my_id, $mensaje, 'telefono', 'telefono (fraccionado en varios mensajes)');
        }
    }
}
// =========================================================================================

// =========================================================================================
// [NUBIRA 2.0] ESCÁNER DE INTENCIÓN BASADO EN TEXTO (SMART DISCOVERY)
// =========================================================================================
// Actualiza esta sección en enviar_mensaje.php
$materias_clave = [
    // La palabra de la izquierda es lo que escribe el alumno, 
    // la de la derecha DEBE SER IGUAL a tu categoría en la BD.
    'matemática' => 'Matemáticas', 'matematicas' => 'Matemáticas', 
    'calculo' => 'Matemáticas', 'cálculo' => 'Matemáticas', 
    'algebra' => 'Matemáticas', 'álgebra' => 'Matemáticas',
    'física' => 'Física', 'fisica' => 'Física',
    'química' => 'Química', 'quimica' => 'Química',
    'lenguaje' => 'Lenguaje', 'comunicación' => 'Lenguaje', 'literatura' => 'Lenguaje',
    'asesoría' => 'Asesoría', 'consultoría' => 'Asesoría'
];

foreach ($materias_clave as $palabra => $categoria_real) {
    if (strpos($mensaje_lower, $palabra) !== false) {
        // Encontramos una materia en el texto. La guardamos en sesión.
        $_SESSION['ultimo_interes_categoria'] = $categoria_real;
        break; // Detenemos el escáner
    }
}
// =========================================================================================

// 4. DEFINICIÓN DE TABLAS SEGÚN CONTEXTO
if ($contexto === 'aula') {
    $tabla = 'chat_aula'; $col_id = 'contrato_id'; $col_fecha = 'fecha'; $col_visto = 'visto'; $tabla_padre = 'contratos';
} else {
    $tabla = 'mensajes'; $col_id = 'conversacion_id'; $col_fecha = 'enviado_en'; $col_visto = 'leido'; $tabla_padre = 'conversaciones';
}

// 5. VALIDACIÓN DE PERMISOS (Prepared Statement)
$sqlCheck = "SELECT id FROM $tabla_padre WHERE id = ? AND (comprador_id = ? OR vendedor_id = ?)";
$stmt = $conn->prepare($sqlCheck);
$stmt->bind_param("iii", $id_ref, $my_id, $my_id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

// 5.5 BLOQUEO POR SUSPENSIÓN DEL DESTINATARIO (solo chat previo al contrato)
if ($contexto === 'conversacion') {
    $sqlOtro = "SELECT CASE WHEN comprador_id = ? THEN vendedor_id ELSE comprador_id END as id_otro FROM $tabla_padre WHERE id = ? LIMIT 1";
    $stmt_otro = $conn->prepare($sqlOtro);
    $stmt_otro->bind_param("ii", $my_id, $id_ref);
    $stmt_otro->execute();
    $row_otro = $stmt_otro->get_result()->fetch_assoc();
    $stmt_otro->close();

    $id_destinatario = (int)($row_otro['id_otro'] ?? 0);
    if ($id_destinatario > 0) {
        $stmt_bloq = $conn->prepare("SELECT bloqueado FROM alumnos WHERE id = ? LIMIT 1");
        $stmt_bloq->bind_param("i", $id_destinatario);
        $stmt_bloq->execute();
        $bloq_otro = $stmt_bloq->get_result()->fetch_assoc();
        $stmt_bloq->close();

        if (!empty($bloq_otro['bloqueado'])) {
            echo json_encode(['success' => false, 'error' => 'Esta persona no está disponible temporalmente.']);
            exit;
        }
    }
}

// 6. INSERCIÓN DEL MENSAJE
$sqlInsert = "INSERT INTO $tabla ($col_id, remitente_id, mensaje, $col_fecha, $col_visto) VALUES (?, ?, ?, NOW(), 0)";
$stmt_ins = $conn->prepare($sqlInsert);
$stmt_ins->bind_param("iis", $id_ref, $my_id, $mensaje);

if ($stmt_ins->execute()) {
    
   // 7. ACTUALIZACIÓN DE ESTADOS Y TRACKER DE TIEMPO (Nubira 2.0)
    $sqlResurrect = "UPDATE $tabla_padre SET oculto_comprador = 0, oculto_vendedor = 0" . ($contexto === 'conversacion' ? ", ultima_interaccion = NOW()" : "") . " WHERE id = ?";
    $stmt_res = $conn->prepare($sqlResurrect);
    $stmt_res->bind_param("i", $id_ref);
    $stmt_res->execute();

 // =========================================================================
    // [NUBIRA 2.0] TRACKER DE TIEMPO DE RESPUESTA — Mediana móvil 30d
    // Solo registra respuestas REALES del tutor a mensajes del comprador.
    // El cálculo de la mediana se hace en cron nocturno (no aquí).
    // =========================================================================
    if ($contexto === 'conversacion') {
        try {
            // 1. Verificar que el remitente es el vendedor (tutor) de esta conversación
            $sqlEsTutor = "SELECT comprador_id, vendedor_id FROM conversaciones WHERE id = ? LIMIT 1";
            $stmt_rol = $conn->prepare($sqlEsTutor);
            $stmt_rol->bind_param("i", $id_ref);
            $stmt_rol->execute();
            $rol_data = $stmt_rol->get_result()->fetch_assoc();
            $stmt_rol->close();

            if ($rol_data && (int)$rol_data['vendedor_id'] === $my_id) {
                $comprador_id = (int)$rol_data['comprador_id'];

                // 2. Buscar el último mensaje del comprador ANTES de este envío
                $sqlUltMsg = "SELECT enviado_en 
                              FROM mensajes 
                              WHERE conversacion_id = ? 
                                AND remitente_id = ? 
                              ORDER BY id DESC 
                              LIMIT 1";
                $stmt_um = $conn->prepare($sqlUltMsg);
                $stmt_um->bind_param("ii", $id_ref, $comprador_id);
                $stmt_um->execute();
                $ult_msg = $stmt_um->get_result()->fetch_assoc();
                $stmt_um->close();

                if ($ult_msg) {
                    // 3. Calcular minutos transcurridos
                    $minutos = (int) ((time() - strtotime($ult_msg['enviado_en'])) / 60);

                    // 4. Solo registrar si está en rango válido (0 a 1440 min = 24h)
                    //    - 0 min: respuesta inmediata (válida)
                    //    - >1440 min: probablemente offline real, descartamos como outlier
                    if ($minutos >= 0 && $minutos <= 1440) {
                        $sqlIns = "INSERT INTO respuestas_tutor 
                                     (tutor_id, conversacion_id, minutos_respuesta) 
                                   VALUES (?, ?, ?)";
                        $stmt_rt = $conn->prepare($sqlIns);
                        if ($stmt_rt) {
                            $stmt_rt->bind_param("iii", $my_id, $id_ref, $minutos);
                            $stmt_rt->execute();
                            $stmt_rt->close();
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Silencioso: nunca debe romper el envío del mensaje
        }
    }
    // =========================================================================
   // 8. LÓGICA DE NOTIFICACIÓN (PUSH + EMAIL FALLBACK - NUBIRA 2.0)
    try {
        // Obtener ID del destinatario
        $sqlDest = "SELECT CASE WHEN comprador_id = ? THEN vendedor_id ELSE comprador_id END as id_otro FROM $tabla_padre WHERE id = ? LIMIT 1";
        $stmt_dest = $conn->prepare($sqlDest);
        $stmt_dest->bind_param("ii", $my_id, $id_ref);
        $stmt_dest->execute();
        $resDest = $stmt_dest->get_result();
        
        if ($rowDest = $resDest->fetch_assoc()) {
            $id_otro = (int)$rowDest['id_otro'];
            
            // Datos del destinatario
            $stmt_user = $conn->prepare("SELECT nombre, correo, ultima_sesion FROM alumnos WHERE id = ? LIMIT 1");
            $stmt_user->bind_param("i", $id_otro);
            $stmt_user->execute();
            $userDat = $stmt_user->get_result()->fetch_assoc();
            
            if ($userDat) {
                $last_active = strtotime($userDat['ultima_sesion'] ?? '2000-01-01');
                $segundos_offline = time() - $last_active;
                
                // Preview limpia del mensaje (máx 120 chars para push)
                $preview_msg = mb_substr($mensaje, 0, 120);
                if (mb_strlen($mensaje) > 120) $preview_msg .= '…';
                
                $nombreEmisor = explode(' ', trim($my_name))[0];
                $titulo_notif = "💬 Mensaje de " . $nombreEmisor;
                
                // URL de destino (directo al chat)
                $url_chat = "/app/chat_previo_contrato.php?id=" . $id_ref;
                
                $push_enviado = false;
                
                // ========== PASO 1: PUSH si lleva 30+ seg offline ==========
                if ($segundos_offline > 30) {
                    require_once __DIR__ . '/enviar_push_nubira.php';
                    $res_push = enviar_push_nubira($id_otro, $titulo_notif, $preview_msg, $url_chat);
                    if (!empty($res_push['success'])) {
                        $push_enviado = true;
                    }
                }
                
                // ========== PASO 2: EMAIL si push falló o no estaba offline suficiente ==========
                // Solo enviar email si lleva 3+ min offline Y el push no llegó
                if (!$push_enviado && $segundos_offline > 180 && !empty($userDat['correo'])) {
                    
                    // ANTI-SPAM: Solo 1 email cada 30 min por emisor en la conversación
                    $sqlSpam = "SELECT COUNT(*) as totales FROM $tabla WHERE $col_id = ? AND remitente_id = ? AND $col_fecha > (NOW() - INTERVAL 30 MINUTE)";
                    $stmt_spam = $conn->prepare($sqlSpam);
                    $stmt_spam->bind_param("ii", $id_ref, $my_id);
                    $stmt_spam->execute();
                    $mensajes_recientes = (int)$stmt_spam->get_result()->fetch_assoc()['totales'];
                    $stmt_spam->close();

                    if ($mensajes_recientes <= 1) {
                        require_once __DIR__ . '/correo.php';
                        
                        $nombreDestino = explode(' ', trim($userDat['nombre']))[0];
                        
                        enviarCorreoNuevoMensaje(
                            $userDat['correo'],
                            $nombreDestino,
                            $nombreEmisor,
                            "Nuevo mensaje en Nubira",
                            $mensaje,
                            $id_ref
                        );
                    }
                }
            }
        }
    } catch (Exception $e) { 
        // Silencioso: no queremos que un fallo de notificación rompa el envío del mensaje
        @file_put_contents(__DIR__ . '/../logs/push.log', 
            "[" . date('Y-m-d H:i:s') . "] ERROR notificacion msg: " . $e->getMessage() . "\n", 
            FILE_APPEND);
    }
    echo json_encode(['success' => true, 'mostrar_banner_express' => ($es_express && $cnt_express === 1)]);
} else {
    echo json_encode(['success' => false, 'error' => 'Error al procesar el envío.']);
}
?>