<?php
session_start();
$id_servicio = intval($_GET['id_servicio'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pago pendiente | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-yellow-50 min-h-screen flex items-center justify-center">
    <div class="bg-white shadow-lg rounded-xl p-8 max-w-lg mx-auto text-center">
        <div class="mb-4">
            <span class="inline-block bg-yellow-100 rounded-full p-4 mb-2">
                <svg class="w-10 h-10 text-yellow-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6 1a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </span>
            <h2 class="text-2xl font-bold text-yellow-700 mb-2">Pago en proceso</h2>
            <p class="text-gray-700 mb-3">
                Mercado Pago aún está verificando tu transacción.  
                Esto puede tardar unos minutos.
            </p>
        </div>
        <div class="bg-yellow-100 border border-yellow-300 text-yellow-900 rounded p-4 mb-5">
            <p>Te avisaremos cuando se confirme. También puedes revisar el estado desde tu cuenta.</p>
        </div>
        <div class="mb-5 flex flex-col md:flex-row gap-2 justify-center">
            <a href="/app/detalle_servicio.php?id=<?= $id_servicio ?>" 
               class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded font-semibold shadow inline-block transition">
               Volver al servicio
            </a>
        </div>
        <div class="text-gray-600 text-sm">
            <p>¿Dudas o demoras? <a href="/soporte" class="text-blue-600 underline">Contacta soporte</a></p>
        </div>
    </div>
</body>
</html>
