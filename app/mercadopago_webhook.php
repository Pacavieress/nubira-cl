<?php
/**
 * WEBHOOK: RETORNO SILENCIOSO DE MERCADOPAGO (NUBIRA 2.0)
 * OBJETIVO: Conciliar pago si el usuario no retornó a la web, aplicar descuento de cupos y liberar fondos.
 */
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/correo.php';

header("Content-Type: application/json");

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) exit('Sin datos');

$payment_id = $input['data']['id'] ?? null;
if (!$payment_id) exit('Falta ID');

$token = MP_ACCESS_TOKEN;

// 1. Consulta a la API oficial
$ch = curl_init("https://api.mercadopago.com/v1/payments/$payment_id");
curl_setopt_array($ch, [
  CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
  CURLOPT_RETURNTRANSFER => true
]);
$res = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($res['status']) || empty($res['external_reference'])) exit('Datos incompletos');

$status = $res['status']; // approved / pending / rejected
$contrato_id = (int)$res['external_reference']; // FIX: Esto es el ID del contrato, no del servicio

if ($status === 'approved' && $contrato_id > 0) {
    
    // 2. Extraer datos para el cupo y correos
    $stmt = $conn->prepare("
        SELECT c.servicio_id, c.comprador_id, c.vendedor_id, c.monto, s.titulo AS servicio_titulo,
               a.nombre AS comprador_nombre, a.correo AS comprador_correo,
               b.nombre AS vendedor_nombre, b.correo AS vendedor_correo
        FROM contratos c
        JOIN servicios s ON c.servicio_id = s.id
        JOIN alumnos a ON c.comprador_id = a.id
        JOIN alumnos b ON c.vendedor_id = b.id
        WHERE c.id = ?
    ");
    $stmt->bind_param("i", $contrato_id);
    $stmt->execute();
    $contrato = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($contrato) {
        // 3. ACTUALIZACIÓN ATÓMICA Y ANTI-DUPLICADOS
        // Solo actualizará si el estado actual es 'pendiente_pago'
        $update = $conn->prepare("UPDATE contratos SET estado = 'en_progreso', fecha_pago = NOW() WHERE id = ? AND estado = 'pendiente_pago'");
        $update->bind_param("i", $contrato_id);
        $update->execute();
        $filas_afectadas = $update->affected_rows;
        $update->close();

        // Si filas_afectadas > 0, significa que EL WEBHOOK PROCESÓ EL PAGO PRIMERO
        if ($filas_afectadas > 0) {
            
            // A) [FIX NUBIRA] DESCUENTO DE CUPO ATÓMICO
            $stmt_cupos = $conn->prepare("
                UPDATE servicios 
                SET cupos_oferta = cupos_oferta - 1 
                WHERE id = ? AND is_subvencionado = 1 AND cupos_oferta > 0
            ");
            $stmt_cupos->bind_param("i", $contrato['servicio_id']);
            $stmt_cupos->execute();
            $stmt_cupos->close();

            // C) LOG DEL EVENTO
            $log = $conn->prepare("INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle) VALUES (?, 0, 'PAGO_CONFIRMADO_WEBHOOK', 'Confirmado vía IPN MercadoPago')");
            $log->bind_param("i", $contrato_id);
            $log->execute();
            $log->close();

            // D) CORREOS (Simplificado para el webhook)
            $tituloServicio = htmlspecialchars($contrato['servicio_titulo'], ENT_QUOTES, 'UTF-8');
            $montoFmt = number_format((float)$contrato['monto'], 0, ',', '.');
            
            // Avisar al vendedor para que comience
            enviarCorreo(
                $contrato['vendedor_correo'],
                "💼 Nuevo servicio contratado - Nubira",
                "<p>El sistema ha verificado el pago por tu servicio <b>{$tituloServicio}</b> (Monto: $<b>{$montoFmt}</b>).</p><p>Ingresa al Aula Virtual para comenzar.</p>",
                "Pago verificado por {$tituloServicio}."
            );
            require_once __DIR__ . '/enviar_push_nubira.php';
            $t = mb_substr($contrato['servicio_titulo'], 0, 50);
            enviar_push_nubira((int)$contrato['comprador_id'], '✅ Pago confirmado', 'Tu pago por "' . $t . '" fue procesado', '/mis-contratos');
            enviar_push_nubira((int)$contrato['vendedor_id'], '💰 Pago recibido', 'Recibiste pago por "' . $t . '"', '/mis-ventas');
        }
    }
}

http_response_code(200);
echo json_encode(["ok" => true]);
?>