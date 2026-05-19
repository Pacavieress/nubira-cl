<?php
session_start();
$id_servicio = intval($_GET['id_servicio'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Error en el pago | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-red-50 min-h-screen flex items-center justify-center">
    <div class="bg-white shadow-lg rounded-xl p-8 max-w-lg mx-auto text-center">
        <div class="mb-4">
            <span class="inline-block bg-red-100 rounded-full p-4 mb-2">
                <svg class="w-10 h-10 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </span>
            <h2 class="text-2xl font-bold text-red-700 mb-2">❌ No se pudo completar el pago</h2>
            <p class="text-gray-700 mb-3">
                Hubo un error al procesar tu pago o fue cancelado por Mercado Pago.
            </p>
        </div>
        <div class="bg-red-100 border border-red-300 text-red-900 rounded p-4 mb-5">
            <p>Si el cobro se realizó, no te preocupes: se validará automáticamente en unos minutos.</p>
        </div>
        <div class="mb-5 flex flex-col md:flex-row gap-2 justify-center">
            <a href="/app/detalle_servicio.php?id=<?= $id_servicio ?>" 
               class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded font-semibold shadow inline-block transition">
               Volver al servicio
            </a>
        </div>
        <div class="text-gray-600 text-sm">
            <p>¿Necesitas ayuda? <a href="/soporte" class="text-blue-600 underline">Contacta soporte</a></p>
        </div>
    </div>
</body>
</html>
