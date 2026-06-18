<?php
// ¡Nunca muestres errores PHP aquí!
session_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pago no completado | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require_once __DIR__ . '/app/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-red-50 min-h-screen flex items-center justify-center">
    <div class="bg-white shadow-lg rounded-xl p-8 max-w-lg mx-auto text-center">
        <span class="inline-block bg-red-100 rounded-full p-4 mb-2">
            <svg class="w-10 h-10 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 9l-6 6m0-6l6 6"/>
            </svg>
        </span>
        <h2 class="text-2xl font-bold text-red-700 mb-2">El pago no se completó</h2>
        <p class="text-gray-700 mb-3">Tu publicación no se pudo activar porque el pago fue rechazado o cancelado.<br>
        Si el problema persiste, revisa tu cuenta o comunícate con soporte.</p>
        <div class="mt-6 flex flex-col md:flex-row gap-2 justify-center">
            <a href="/clases-servicios" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-5 py-2 rounded font-semibold shadow inline-block transition">
                Volver a Clases y Servicios
            </a>
            <a href="/publicar-servicio" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded font-semibold shadow inline-block transition">
                Intentar Publicar de Nuevo
            </a>
        </div>
        <div class="text-gray-600 text-sm mt-6">
            <p>¿Necesitas ayuda? <a href="/app/soporte.php" class="text-blue-600 underline">Contacta soporte</a></p>
        </div>
    </div>
</body>
</html>
