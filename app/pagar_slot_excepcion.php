<?php
/**
 * VISTA/PROCESO: PAGAR SLOT DE EXCEPCIÓN
 * GET  /app/pagar_slot_excepcion.php?token=XXX  → muestra resumen de la reserva
 * POST /app/pagar_slot_excepcion.php             → crea o reusa contrato → redirige a MercadoPago
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();
date_default_timezone_set('America/Santiago');

require_once __DIR__ . '/conexion.php';

// ─────────────────────────────────────────────────────────────
// 1. AUTH GUARD
// ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login?returnUrl=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
    exit;
}
$usuario_id = (int)$_SESSION['usuario_id'];

// ─────────────────────────────────────────────────────────────
// HELPER: página de error standalone + exit
// ─────────────────────────────────────────────────────────────
function pagina_error(string $titulo, string $cuerpo, string $url_btn = '/vitrina', string $texto_btn = 'Ir a la vitrina'): void {
    $t  = htmlspecialchars($titulo,    ENT_QUOTES, 'UTF-8');
    $c  = htmlspecialchars($cuerpo,    ENT_QUOTES, 'UTF-8');
    $u  = htmlspecialchars($url_btn,   ENT_QUOTES, 'UTF-8');
    $bt = htmlspecialchars($texto_btn, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="es"><head>
        <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>' . $t . ' | Nubira</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="icon" type="image/webp" href="/img/logo2.webp">
    </head>
    <body class="bg-gray-50 flex items-center justify-center min-h-screen p-4" style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">
        <div class="bg-white rounded-3xl border border-gray-100 shadow-lg max-w-sm w-full p-8 text-center">
            <div class="w-14 h-14 bg-amber-50 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                </svg>
            </div>
            <h2 class="text-xl font-bold text-gray-900 mb-2">' . $t . '</h2>
            <p class="text-sm text-gray-500 mb-6 leading-relaxed">' . $c . '</p>
            <a href="' . $u . '" class="block w-full bg-[#54A6D8] hover:bg-blue-600 text-white font-bold py-3 rounded-xl transition-all">' . $bt . '</a>
        </div>
    </body></html>';
    exit;
}

// ─────────────────────────────────────────────────────────────
// 2. POST — PROCESAR PAGO (idempotente)
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        pagina_error('Sesión expirada', 'Tu sesión expiró. Vuelve a abrir el enlace de la reserva.');
    }

    $token = trim($_POST['token'] ?? '');

    // Validación 1: formato token
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        pagina_error('Enlace inválido', 'Este enlace de reserva no es válido.');
    }

    $conn->begin_transaction();
    try {
        // Leer slot con FOR UPDATE (bloqueo pesimista anti-race)
        $stmt = $conn->prepare("
            SELECT se.id, se.servicio_id, se.tutor_id, se.alumno_id, se.conversacion_id,
                   se.fecha_clase, se.monto, se.expira_en, se.estado, se.contrato_id,
                   s.titulo AS servicio_titulo, s.duracion_minutos, s.estado AS servicio_estado
            FROM slots_excepcion se
            JOIN servicios s ON se.servicio_id = s.id
            WHERE se.token = ?
            LIMIT 1 FOR UPDATE
        ");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $slot = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Validaciones 2–6
        if (!$slot)                                  throw new Exception('Este enlace de reserva no es válido.');
        if ($slot['estado'] === 'pagado') {
            $conn->rollback();
            header('Location: /app/chat_previo_contrato.php?id=' . (int)$slot['conversacion_id'] . '&msg=ya_pagado');
            exit;
        }
        if ($slot['estado'] !== 'pendiente')         throw new Exception('Esta reserva ya no está disponible.');
        if (strtotime($slot['expira_en']) < time())  throw new Exception('Este enlace expiró. El tutor puede generar una nueva reserva desde el chat.');
        if ((int)$slot['alumno_id'] !== $usuario_id) throw new Exception('Este enlace no corresponde a tu cuenta.');
        if ($slot['servicio_estado'] !== 'aprobado') throw new Exception('El servicio ya no está disponible.');

        $slot_id     = (int)$slot['id'];
        $servicio_id = (int)$slot['servicio_id'];
        $tutor_id    = (int)$slot['tutor_id'];
        $monto       = (int)$slot['monto'];
        $duracion    = (int)($slot['duracion_minutos'] ?: 60);
        $fecha_clase = $slot['fecha_clase'];
        $conv_id     = (int)$slot['conversacion_id'];

        // ─── Bifurcación: contrato_id NULL → crear / NOT NULL → reusar ───
        if ($slot['contrato_id'] !== null) {

            $stmtC = $conn->prepare("SELECT estado FROM contratos WHERE id = ? LIMIT 1");
            $stmtC->bind_param("i", $slot['contrato_id']);
            $stmtC->execute();
            $stmtC->bind_result($estado_c);
            $stmtC->fetch();
            $stmtC->close();

            if ($estado_c === 'pendiente_pago') {
                $contrato_id = (int)$slot['contrato_id'];   // reuso: ir directo a MP
            } else {
                $conn->rollback();
                header('Location: /app/chat_previo_contrato.php?id=' . $conv_id . '&msg=ya_pagado');
                exit;
            }

        } else {

            // Verificar solapamiento de horario (mismo patrón que crear_contrato.php §E.1)
            $slot_fin_sql = date('Y-m-d H:i:s', strtotime($fecha_clase) + $duracion * 60);
            $stmt_sol = $conn->prepare("
                SELECT id FROM reservas_slots
                WHERE tutor_id = ?
                  AND estado IN ('reservado','en_curso')
                  AND fecha_clase < ?
                  AND DATE_ADD(fecha_clase, INTERVAL duracion_minutos MINUTE) > ?
                LIMIT 1 FOR UPDATE
            ");
            $stmt_sol->bind_param("iss", $tutor_id, $slot_fin_sql, $fecha_clase);
            $stmt_sol->execute();
            if ($stmt_sol->get_result()->num_rows > 0) {
                $stmt_sol->close();
                throw new Exception('Lo sentimos, ese horario ya no está disponible. El tutor puede proponer una nueva reserva desde el chat.');
            }
            $stmt_sol->close();

            // Comisión plataforma
            $stmtCom = $conn->prepare("SELECT valor FROM configuracion WHERE clave = 'comision_plataforma'");
            $stmtCom->execute();
            $stmtCom->bind_result($val_com);
            $stmtCom->fetch();
            $stmtCom->close();
            $monto_comision = (int)(($monto * ($val_com !== null ? (int)$val_com : 0)) / 100);
            $monto_subsidio = 0;
            $monto_aceptado = 0;
            $estado_nuevo   = 'pendiente_pago';

            // Crear contrato
            $stmtC = $conn->prepare("
                INSERT INTO contratos
                    (servicio_id, comprador_id, vendedor_id, monto, monto_subsidio, monto_comision,
                     monto_aceptado, fecha_estimada, notas, estado, fecha_creacion)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, '', ?, NOW())
            ");
            $stmtC->bind_param("iiiiiiiss",
                $servicio_id, $usuario_id, $tutor_id, $monto,
                $monto_subsidio, $monto_comision, $monto_aceptado,
                $fecha_clase, $estado_nuevo
            );
            if (!$stmtC->execute()) throw new Exception('No se pudo crear el contrato.');
            $contrato_id = $conn->insert_id;
            $stmtC->close();

            // Crear reserva de horario
            $estado_reserva = 'reservado';
            $stmtR = $conn->prepare("
                INSERT INTO reservas_slots
                    (contrato_id, servicio_id, tutor_id, alumno_id, fecha_clase, duracion_minutos, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtR->bind_param("iiiisis",
                $contrato_id, $servicio_id, $tutor_id, $usuario_id,
                $fecha_clase, $duracion, $estado_reserva
            );
            if (!$stmtR->execute()) throw new Exception('No se pudo registrar la reserva de horario.');
            $stmtR->close();

            // Vincular conversación ↔ contrato (bidireccional, mismo patrón que crear_contrato.php §G)
            $stmtL1 = $conn->prepare("UPDATE conversaciones SET contrato_id = ? WHERE id = ?");
            $stmtL1->bind_param("ii", $contrato_id, $conv_id);
            $stmtL1->execute();
            $stmtL1->close();

            $stmtL2 = $conn->prepare("UPDATE contratos SET conversacion_id = ? WHERE id = ?");
            $stmtL2->bind_param("ii", $conv_id, $contrato_id);
            $stmtL2->execute();
            $stmtL2->close();

            // Guardar puente slot → contrato (lo usa pago_exitoso_contrato.php para marcar 'pagado')
            $stmtSlot = $conn->prepare("UPDATE slots_excepcion SET contrato_id = ? WHERE id = ?");
            $stmtSlot->bind_param("ii", $contrato_id, $slot_id);
            $stmtSlot->execute();
            $stmtSlot->close();
        }

        $conn->commit();

        // Spinner + auto-submit a iniciar_pago_servicio.php (idéntico a crear_contrato.php §6)
        $cid = (int)$contrato_id;
        echo '<!DOCTYPE html><html lang="es"><head>
            <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Procesando pago seguro | Nubira</title>
            <style>body{background:#f9fafb;display:flex;justify-content:center;align-items:center;height:100vh;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:0}
            @keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}</style>
        </head><body>
            <div style="text-align:center">
                <div style="width:48px;height:48px;border:4px solid #e0f2fe;border-top:4px solid #54A6D8;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 24px"></div>
                <h2 style="color:#111827;margin-bottom:8px;font-weight:600">Asegurando tu pago...</h2>
                <p style="color:#6b7280;font-size:14px">Serás redirigido a la pasarela en un instante.</p>
            </div>
            <form id="fP" action="/app/iniciar_pago_servicio.php?id=' . $cid . '" method="POST">
                <input type="hidden" name="id" value="' . $cid . '">
                <input type="hidden" name="contrato_id" value="' . $cid . '">
            </form>
            <script>setTimeout(()=>document.getElementById("fP").submit(),800)</script>
        </body></html>';
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        pagina_error('No pudimos procesar tu reserva', $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────
// 3. GET — VALIDAR SLOT Y PREPARAR DATOS
// ─────────────────────────────────────────────────────────────
$token = trim($_GET['token'] ?? '');

// Validación 1: formato token
if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    pagina_error('Enlace inválido', 'Este enlace de reserva no es válido.');
}

$stmt = $conn->prepare("
    SELECT se.id, se.servicio_id, se.tutor_id, se.alumno_id, se.conversacion_id,
           se.fecha_clase, se.monto, se.expira_en, se.estado, se.contrato_id,
           s.titulo AS servicio_titulo, s.duracion_minutos, s.estado AS servicio_estado,
           a.nombre AS tutor_nombre
    FROM slots_excepcion se
    JOIN servicios s ON se.servicio_id = s.id
    JOIN alumnos   a ON se.tutor_id    = a.id
    WHERE se.token = ?
    LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$slot = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Validación 2: fila existe
if (!$slot) pagina_error('Enlace inválido', 'Este enlace de reserva no es válido.');

$conv_id     = (int)$slot['conversacion_id'];
$ya_expirado = (strtotime($slot['expira_en']) < time());

// Validaciones 3+4: estado y expiración
if ($slot['estado'] === 'pagado') {
    pagina_error('Reserva ya pagada', 'Esta reserva ya fue pagada.',
        '/app/chat_previo_contrato.php?id=' . $conv_id, 'Ir al chat');
}
if ($slot['estado'] === 'expirado' || $ya_expirado) {
    pagina_error('Reserva expirada', 'Este enlace expiró. El tutor puede generar una nueva reserva desde el chat.',
        '/app/chat_previo_contrato.php?id=' . $conv_id, 'Volver al chat');
}

// Validación 5: alumno_id coincide con sesión
if ((int)$slot['alumno_id'] !== $usuario_id) {
    pagina_error('Sin acceso', 'Este enlace no corresponde a tu cuenta.');
}

// Validación 6: servicio aprobado
if ($slot['servicio_estado'] !== 'aprobado') {
    pagina_error('Servicio no disponible', 'El servicio ya no está disponible.',
        '/app/chat_previo_contrato.php?id=' . $conv_id, 'Volver al chat');
}

// Bifurcación contrato existente: verificar que siga siendo pagable
if ($slot['contrato_id'] !== null) {
    $stmtC = $conn->prepare("SELECT estado FROM contratos WHERE id = ? LIMIT 1");
    $stmtC->bind_param("i", $slot['contrato_id']);
    $stmtC->execute();
    $stmtC->bind_result($estado_c_existente);
    $stmtC->fetch();
    $stmtC->close();
    if ($estado_c_existente !== 'pendiente_pago') {
        pagina_error('Reserva ya procesada', 'Esta reserva ya fue procesada.',
            '/app/chat_previo_contrato.php?id=' . $conv_id, 'Ir al chat');
    }
}

// Preparar datos de display
$ts_clase           = strtotime($slot['fecha_clase']);
$ts_expira          = strtotime($slot['expira_en']);
$segundos_restantes = $ts_expira - time();

$dias_es  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
$meses_es = ['enero','febrero','marzo','abril','mayo','junio','julio',
             'agosto','septiembre','octubre','noviembre','diciembre'];

$fecha_display = ucfirst($dias_es[(int)date('w', $ts_clase)])
               . ' ' . (int)date('j', $ts_clase)
               . ' de ' . $meses_es[(int)date('n', $ts_clase) - 1]
               . ' · ' . date('H:i', $ts_clase);

$expira_display = 'las ' . date('H:i', $ts_expira)
                . ' del ' . (int)date('j', $ts_expira)
                . ' de ' . $meses_es[(int)date('n', $ts_expira) - 1];

// Nombre tutor con privacidad (Pablo C.)
$partes_tutor = preg_split('/\s+/u', trim($slot['tutor_nombre']), -1, PREG_SPLIT_NO_EMPTY);
$nombre_tutor = htmlspecialchars($partes_tutor[0], ENT_QUOTES, 'UTF-8');
if (count($partes_tutor) > 1) {
    $nombre_tutor .= ' ' . htmlspecialchars(mb_substr($partes_tutor[1], 0, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8') . '.';
}

$monto_fmt        = '$' . number_format((int)$slot['monto'], 0, ',', '.');
$duracion         = (int)($slot['duracion_minutos'] ?: 60);
$titulo_serv      = htmlspecialchars($slot['servicio_titulo'], ENT_QUOTES, 'UTF-8');
$token_safe       = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
$mostrar_countdown = ($segundos_restantes < 6 * 3600);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Reserva propuesta | Nubira</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
</head>
<body class="bg-gray-50 min-h-screen flex items-start justify-center pt-10 pb-16 px-4 antialiased"
      style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

    <div class="w-full max-w-md">

        <!-- Logo -->
        <div class="flex justify-center mb-6">
            <a href="/vitrina"><img src="/img/logo2.webp" alt="Nubira" class="h-8"></a>
        </div>

        <!-- Header degradado -->
        <div class="bg-gradient-to-r from-sky-400 to-[#54A6D8] rounded-2xl px-5 py-4 mb-4 text-white">
            <p class="text-xs font-bold uppercase tracking-widest opacity-80 mb-0.5">Reserva propuesta</p>
            <p class="text-base font-semibold">por <?= $nombre_tutor ?></p>
        </div>

        <!-- Card resumen -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-4 space-y-4">

            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-[#54A6D8]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.966 8.966 0 00-6 2.292m0-14.25v14.25"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs text-gray-400 font-medium uppercase tracking-wide">Servicio</p>
                    <p class="text-sm font-semibold text-gray-800"><?= $titulo_serv ?></p>
                </div>
            </div>

            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-[#54A6D8]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs text-gray-400 font-medium uppercase tracking-wide">Fecha y hora</p>
                    <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($fecha_display, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>

            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-[#54A6D8]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs text-gray-400 font-medium uppercase tracking-wide">Duración</p>
                    <p class="text-sm font-semibold text-gray-800"><?= $duracion ?> minutos</p>
                </div>
            </div>

            <div class="border-t border-gray-100 pt-3 flex items-center justify-between">
                <p class="text-sm text-gray-500">Total a pagar</p>
                <p class="text-2xl font-bold text-gray-900"><?= $monto_fmt ?> <span class="text-xs font-normal text-gray-400">CLP</span></p>
            </div>

        </div>

        <!-- Vencimiento -->
        <div class="bg-amber-50 border border-amber-100 rounded-xl px-4 py-3 mb-4 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-amber-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <?php if ($mostrar_countdown): ?>
                <p class="text-xs text-amber-700">Este enlace vence en <span id="countdown" class="font-bold"></span></p>
            <?php else: ?>
                <p class="text-xs text-amber-700">Válida hasta <?= htmlspecialchars($expira_display, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </div>

        <!-- Aviso custodia -->
        <div class="bg-gray-50 rounded-xl border border-gray-100 p-4 mb-5 flex items-start gap-3">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-[#54A6D8] flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
            </svg>
            <div>
                <p class="text-xs font-bold text-gray-900">Dinero protegido por Nubira</p>
                <p class="text-[11px] text-gray-500 mt-0.5 leading-relaxed">El pago no se entregará al tutor hasta que el servicio sea realizado por completo y tú estés conforme.</p>
            </div>
        </div>

        <!-- Formulario de pago -->
        <form method="POST" action="/app/pagar_slot_excepcion.php">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="token"      value="<?= $token_safe ?>">
            <button type="submit"
                    class="w-full bg-[#54A6D8] hover:bg-blue-600 text-white font-bold text-base py-4 rounded-xl transition-all shadow-lg shadow-blue-200 hover:scale-[1.01]">
                Pagar <?= $monto_fmt ?>
            </button>
        </form>

        <a href="/app/chat_previo_contrato.php?id=<?= $conv_id ?>"
           class="block text-center text-gray-400 hover:text-gray-600 text-sm font-medium mt-4 transition-colors">
            Volver al chat
        </a>

    </div>

    <?php if ($mostrar_countdown): ?>
    <script>
    (function () {
        var expira = <?= (int)$ts_expira ?> * 1000;
        var el = document.getElementById('countdown');
        if (!el) return;
        function tick() {
            var diff = Math.max(0, Math.floor((expira - Date.now()) / 1000));
            if (diff === 0) { el.textContent = '00:00'; return; }
            if (diff < 3600) {
                // Menos de 1h → MM:SS
                var m = Math.floor(diff / 60);
                var s = diff % 60;
                el.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
            } else {
                // Entre 1h y 6h → Xh YYm
                var h = Math.floor(diff / 3600);
                var m = Math.floor((diff % 3600) / 60);
                el.textContent = h + 'h ' + String(m).padStart(2, '0') + 'm';
            }
            setTimeout(tick, 1000);
        }
        tick();
    }());
    </script>
    <?php endif; ?>

</body>
</html>
