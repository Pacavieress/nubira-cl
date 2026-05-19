<?php
session_start();
require_once __DIR__ . '/conexion.php';

// 🚀 BYPASS ADMIN
$rol = $_SESSION['rol'] ?? 'alumno';
$es_admin = ($rol === 'admin');
if ($es_admin) {
    $id_contrato = (int)($_GET['id'] ?? 0);
    header("Location: /app/mini_aula.php?id=" . $id_contrato);
    exit;
}

// 🔒 SEGURIDAD USUARIO
if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login');
    exit;
}

$id_contrato = (int)($_GET['id'] ?? 0);
if ($id_contrato <= 0) { echo "Contrato inválido"; exit; }

$stmt = $conn->prepare("
  SELECT c.*, s.titulo AS servicio_titulo, a.nombre AS vendedor_nombre
  FROM contratos c
  JOIN servicios s ON c.servicio_id = s.id
  JOIN alumnos a ON c.vendedor_id = a.id
  WHERE c.id = ? AND c.comprador_id = ?
");
$stmt->bind_param("ii", $id_contrato, $_SESSION['usuario_id']);
$stmt->execute();
$contrato = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contrato) { echo "Contrato no encontrado."; exit; }

if (in_array($contrato['estado'], ['en_progreso', 'liberado'])) {
    header("Location: /app/mini_aula.php?id=" . $id_contrato); // Redirigir si ya pagó
    exit;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf_token'];
?>

<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Pagar Contrato #<?= $id_contrato ?> | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    :root { --nubira:#54A6D8; }
    .text-nubira { color: var(--nubira); }
    .bg-nubira { background-color: var(--nubira); }
    /* Fondo sutil de checkout */
    body { background-color: #f8fafc; }
  </style>
</head>

<body class="min-h-screen font-sans text-gray-800 flex flex-col">

<header class="fixed top-0 left-0 right-0 z-40 bg-white/90 backdrop-blur-sm border-b border-gray-200 h-16">
  <div class="w-full max-w-3xl mx-auto flex items-center justify-between h-full px-4">
    <div class="flex items-center gap-3">
        <a href="/mis_contratos" class="text-gray-400 hover:text-gray-800 transition p-2 rounded-full hover:bg-gray-100" title="Cancelar">
            <i class="fa-solid fa-xmark text-xl"></i>
        </a>
        <span class="font-bold text-lg text-gray-900 flex items-center gap-2">
            <i class="fa-solid fa-lock text-green-500 text-sm"></i> Pago Seguro
        </span>
    </div>
    <div class="text-xs font-bold text-gray-400 uppercase tracking-wider">
        Paso 2 de 2
    </div>
  </div>
</header>

<main class="flex-grow pt-24 pb-12 px-4 flex justify-center items-start">
  <div class="w-full max-w-md">

    <div class="bg-white rounded-3xl shadow-xl border border-gray-100 overflow-hidden ring-1 ring-black/5 relative">
        
        <div class="h-2 bg-gradient-to-r from-[#54A6D8] to-blue-600 w-full"></div>

        <div class="p-8">
            
            <div class="text-center mb-8">
                <p class="text-sm text-gray-500 font-medium mb-1">Total a pagar</p>
                <h1 class="text-4xl font-extrabold text-gray-900 tracking-tight">
                    $<?= number_format($contrato['monto'], 0, ',', '.') ?>
                </h1>
                <div class="mt-2 inline-flex items-center gap-1 px-3 py-1 rounded-full bg-blue-50 text-blue-600 text-xs font-bold">
                    <i class="fa-solid fa-shield-alt"></i> Garantía Nubira
                </div>
            </div>

            <div class="bg-gray-50 rounded-2xl p-5 border border-gray-200 mb-8 space-y-3 text-sm">
                <div class="flex justify-between items-start">
                    <span class="text-gray-500">Servicio:</span>
                    <span class="font-semibold text-gray-900 text-right w-1/2 truncate"><?= htmlspecialchars($contrato['servicio_titulo']) ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-500">Profesor:</span>
                    <span class="font-semibold text-gray-900"><?= htmlspecialchars($contrato['vendedor_nombre']) ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-500">Fecha Estimada:</span>
                    <span class="font-semibold text-gray-900"><?= date('d/m/Y', strtotime($contrato['fecha_estimada'])) ?></span>
                </div>
            </div>

            <form id="formPago" method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= $CSRF ?>">
                <input type="hidden" name="contrato_id" value="<?= $id_contrato ?>">
                <input type="hidden" name="metodo" value="webpay"> 

                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">Método de pago</label>
                    <div class="border border-[#54A6D8] bg-blue-50/50 rounded-xl p-4 flex items-center justify-between cursor-pointer shadow-sm ring-1 ring-[#54A6D8]">
                        <div class="flex items-center gap-3">
                            <div class="bg-white p-2 rounded-lg border border-gray-200">
                                <i class="fa-regular fa-credit-card text-2xl text-gray-700"></i>
                            </div>
                            <div>
                                <p class="font-bold text-gray-900 text-sm">Mercado Pago / Webpay</p>
                                <p class="text-xs text-gray-500">Débito, Crédito, Prepago</p>
                            </div>
                        </div>
                        <i class="fa-solid fa-circle-check text-[#54A6D8] text-xl"></i>
                    </div>
                </div>

                <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 rounded-xl shadow-lg shadow-green-600/20 transition transform active:scale-[.98] flex items-center justify-center gap-2 text-base mt-4">
                    <i class="fa-solid fa-lock"></i> Pagar $<?= number_format($contrato['monto'], 0, ',', '.') ?>
                </button>
            </form>

        </div>

        <div class="bg-gray-50 p-4 text-center border-t border-gray-100">
            <p class="text-[10px] text-gray-400 leading-tight">
                Al pagar, aceptas que los fondos se retendrán hasta la finalización del servicio.
                <a href="/terminos" target="_blank" class="underline hover:text-gray-600">Términos y condiciones</a>.
            </p>
        </div>

    </div>
    
    <div class="mt-6 text-center">
        <p class="text-xs text-gray-400 flex items-center justify-center gap-1">
            <i class="fa-solid fa-lock"></i> SSL Encriptado de extremo a extremo
        </p>
    </div>

  </div>
</main>

<script>
    document.getElementById('formPago').addEventListener('submit', function(e) {
        e.preventDefault(); // Prevenir envío estándar para manejar lógica JS
        
        const btn = this.querySelector('button[type="submit"]');
        const contratoId = <?= $id_contrato ?>;
        
        // Feedback visual
        btn.disabled = true;
        btn.classList.add('opacity-75', 'cursor-not-allowed');
        btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Procesando...';

        // Redirigir al controlador de pago
        setTimeout(() => {
             window.location.href = '/app/iniciar_pago_contrato.php?id_contrato=' + contratoId;
        }, 800);
    });
</script>

</body>
</html>