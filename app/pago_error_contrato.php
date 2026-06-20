<?php
session_start();
require_once __DIR__ . '/conexion.php';

// Cargar config si existe
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
if (!defined('BASE_URL')) define('BASE_URL', 'https://nubira.cl');

// =========================================================================================
// NUBIRA 2.0 FIX: Atrapar 'contrato_id' de MercadoPago (que manda nulls si se cancela)
// =========================================================================================
$id_contrato = (int)($_GET['contrato_id'] ?? $_GET['id'] ?? 0);
$user_id     = (int)($_SESSION['usuario_id'] ?? 0);
$status_mp   = $_GET['status'] ?? 'null';

if ($id_contrato <= 0 || $user_id <= 0) {
    http_response_code(400);
    exit("Solicitud inválida. Faltan parámetros de sesión o de contrato.");
}

try {
    if (isset($conn)) {
        // 1. Asegurarnos de que el contrato quede en 'pendiente_pago'
        // Validamos también que el usuario actual sea el comprador real por seguridad
        $upd = $conn->prepare("
            UPDATE contratos 
            SET estado = 'pendiente_pago' 
            WHERE id = ? AND comprador_id = ? 
              AND estado NOT IN ('en_progreso','liberado','cancelado')
        ");

        if ($upd) {
            $upd->bind_param("ii", $id_contrato, $user_id);
            $upd->execute();
            $upd->close();
        }
        
        // 2. Rescatar el servicio original para el botón de "Volver"
        $servicio_id = 0;
        $qServ = $conn->prepare("SELECT servicio_id FROM contratos WHERE id = ?");
        if ($qServ) {
            $qServ->bind_param("i", $id_contrato);
            $qServ->execute();
            $qServ->bind_result($servicio_id);
            $qServ->fetch();
            $qServ->close();
        }

        // 3. Log de seguridad silencioso
        $motivo = ($status_mp === 'null') ? 'Usuario canceló en checkout' : 'Pago rechazado en pasarela';
        @file_put_contents(
            __DIR__ . '/../log_envio.txt',
            date("Y-m-d H:i:s") . " - ℹ️ Contrato #{$id_contrato}: {$motivo}\n",
            FILE_APPEND
        );
    }
} catch (Throwable $e) { }
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Pago cancelado | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body class="min-h-screen flex flex-col items-center justify-center p-4 font-sans text-gray-800 bg-gray-50">

  <div class="bg-white rounded-3xl shadow-lg border border-gray-100 p-8 max-w-md w-full text-center relative overflow-hidden transition-all hover:shadow-xl">
    
    <div class="w-20 h-20 bg-orange-50 text-orange-400 rounded-full flex items-center justify-center mx-auto mb-6 text-4xl shadow-sm">
        <i class="fa-solid fa-arrow-rotate-left"></i>
    </div>

    <h1 class="text-2xl font-bold text-gray-900 mb-2 tracking-tight leading-tight">Pago no completado</h1>
    
    <p class="text-gray-500 text-sm mb-8 leading-relaxed">
        Cancelaste el proceso o hubo un problema de conexión. <br><strong class="text-gray-700">No se ha realizado ningún cargo a tu cuenta.</strong>
    </p>

    <div class="space-y-3">
        <a href="/app/iniciar_pago_contrato.php?id_contrato=<?= $id_contrato ?>" 
           class="block w-full bg-[#54A6D8] hover:bg-sky-500 text-white font-bold py-3.5 rounded-xl shadow-md transition-all hover:shadow-lg hover:scale-[1.01]">
            <i class="fa-solid fa-credit-card mr-2"></i> Reintentar pago
        </a>
        
        <?php if (!empty($servicio_id) && $servicio_id > 0): ?>
            <a href="/app/detalle_servicio.php?id=<?= $servicio_id ?>" 
               class="block w-full bg-white border border-gray-200 hover:bg-gray-50 text-gray-600 font-bold py-3.5 rounded-xl transition-all hover:scale-[1.01]">
                Volver al detalle del servicio
            </a>
        <?php else: ?>
            <a href="/vitrina" 
               class="block w-full bg-white border border-gray-200 hover:bg-gray-50 text-gray-600 font-bold py-3.5 rounded-xl transition-all hover:scale-[1.01]">
                Explorar otros servicios
            </a>
        <?php endif; ?>
    </div>

  </div>

</body>
</html>