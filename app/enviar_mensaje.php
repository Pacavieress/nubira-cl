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
// 4. CAPA DLP NUBIRA (DATA LOSS PREVENTION) - ESTRICTO Y EDUCATIVO
// =========================================================================================
$mensaje_lower = mb_strtolower($mensaje, 'UTF-8');
$patrones_bloqueo = [
    // 1. CORREOS ELECTRÓNICOS (Normales y Ofuscados)
    'email'              => '/[a-z0-9._%+-]+(?:@|\s+arroba\s+|\[arroba\]|\(arroba\))[a-z0-9.-]+(?:\.|\s+punto\s+|\[punto\])[a-z]{2,}/i',

    // 2. TELÉFONOS (Atrapa +569, 9, espacios, guiones y puntos)
    'telefono'           => '/(?:\+?56\s*9|9)?[\s\-\.]*(?:\d[\s\-\.]*){7,}/',

    // 3. REDES SOCIALES (Nombres, siglas y variaciones fonéticas)
    'redes'              => '/\b(wh?a[ts]+s?[aá]pp?|wasap|watsap|whsatap|guatsap|wsp|wa\.me|instagram|insta|ig|face|fb|tiktok|tk|telegram|tg|t\.me|discord|dc|linktree|x\.com|twitter|tw|linkedin|in)\b/i',

    // 4. MÉTODOS DE PAGO Y BANCOS (Evita transferencias directas)
    'banco'              => '/\b(transferencia|transferir|cuenta rut|cta rut|banco|santander|bci|estado|scotiabank|itau|tenpo|mach|mercadopago|mp|pago rut|datos de mi cuenta|mi rut|rut:)\b/i',

    // 5. INTENCIÓN DE CONTACTO Y UBICACIÓN
    'intencion_contacto' => '/\b(contacto|celular|fono|tel[eé]fono|ll[aá]mame|llamada|mi n[uú]mero|correo|email|direcci[oó]n|calle|pasaje|vives en|vivo en|mi casa|junt[eé]monos|reunámonos|zoom|meet|teams|skype)\b/i',

    // 6. IDENTIDAD Y BÚSQUEDA (Evita que se busquen por fuera)
    'identidad'          => '/\b(mi nombre es|me llamo|mi apellido|me dicen|puedes decirme|b[úu]scame|encontrarme|encontrame|soy el de|mi perfil|mi cuenta)\b/i',

    // 7. ENLACES EXTERNOS
    'urls'               => '/(http|https|www\.)/i'
];

foreach ($patrones_bloqueo as $categoria => $pattern) {
    if (preg_match($pattern, $mensaje_lower)) {
        // Registrar intento DLP silenciosamente — no debe romper el flujo si falla
        try {
            $stmt_dlp = $conn->prepare(
                "INSERT INTO dlp_intentos (conversacion_id, remitente_id, categoria, patron_matched, texto_intentado)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if ($stmt_dlp) {
                $patron_safe = mb_substr($pattern, 0, 200);
                $stmt_dlp->bind_param("iisss", $id_ref, $my_id, $categoria, $patron_safe, $mensaje);
                $stmt_dlp->execute();
                $stmt_dlp->close();
            }
        } catch (Exception $e) {}

        // Enfoque Nubira 2.0: No damos pistas. Recordamos la regla.
        echo json_encode([
            'success' => false,
            'error' => "⚠️ Por tu seguridad y la garantía de Nubira, no permitimos compartir identidad, redes ni medios de pago en el chat. Reescribe tu mensaje sin información externa."
        ]);
        exit;
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