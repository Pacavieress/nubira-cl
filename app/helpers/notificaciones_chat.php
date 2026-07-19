<?php
/**
 * HELPER: NOTIFICACIÓN DE NUEVO MENSAJE (PUSH + EMAIL FALLBACK - NUBIRA 2.0)
 * Extraído de enviar_mensaje.php para reutilizarse también desde la liberación
 * manual de mensajes DLP en admin_chats.php.
 */

if (!function_exists('nb_notificar_nuevo_mensaje')) {
    function nb_notificar_nuevo_mensaje($conn, $id_ref, $remitente_id, $remitente_nombre, $mensaje, $tabla_padre = 'conversaciones', $tabla = 'mensajes', $col_id = 'conversacion_id', $col_fecha = 'enviado_en', $contexto_texto = null) {
        try {
            // Obtener ID del destinatario
            $sqlDest = "SELECT CASE WHEN comprador_id = ? THEN vendedor_id ELSE comprador_id END as id_otro FROM $tabla_padre WHERE id = ? LIMIT 1";
            $stmt_dest = $conn->prepare($sqlDest);
            $stmt_dest->bind_param("ii", $remitente_id, $id_ref);
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

                    $nombreEmisor = explode(' ', trim($remitente_nombre))[0];
                    $titulo_notif = $contexto_texto ?? ("💬 Mensaje de " . $nombreEmisor);

                    // URL de destino (directo al chat)
                    $url_chat = "/app/chat_previo_contrato.php?id=" . $id_ref;

                    $push_enviado = false;

                    // ========== PASO 1: PUSH si lleva 30+ seg offline ==========
                    if ($segundos_offline > 30) {
                        require_once __DIR__ . '/../enviar_push_nubira.php';
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
                        $stmt_spam->bind_param("ii", $id_ref, $remitente_id);
                        $stmt_spam->execute();
                        $mensajes_recientes = (int)$stmt_spam->get_result()->fetch_assoc()['totales'];
                        $stmt_spam->close();

                        if ($mensajes_recientes <= 1) {
                            require_once __DIR__ . '/../correo.php';

                            $nombreDestino = explode(' ', trim($userDat['nombre']))[0];

                            enviarCorreoNuevoMensaje(
                                $userDat['correo'],
                                $nombreDestino,
                                $nombreEmisor,
                                $contexto_texto ?? "Nuevo mensaje en Nubira",
                                $mensaje,
                                $id_ref
                            );
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silencioso: no queremos que un fallo de notificación rompa el flujo que la llamó
            @file_put_contents(__DIR__ . '/../../logs/push.log',
                "[" . date('Y-m-d H:i:s') . "] ERROR notificacion msg: " . $e->getMessage() . "\n",
                FILE_APPEND);
        }
    }
}
