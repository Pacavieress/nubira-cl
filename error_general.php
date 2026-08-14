<?php
/**
 * VISTA: ERROR GENERAL (NUBIRA 2.0 - REFINADO)
 * DISEÑO: Centrado, Claro y Directo.
 */

// 1. CONFIGURACIÓN
$codigo = $_GET['code'] ?? http_response_code();
if (!is_numeric($codigo)) $codigo = 404;
http_response_code($codigo);

// Zona horaria y logging (Mantener la lógica de backend)
date_default_timezone_set('America/Santiago');
$app_dir = __DIR__ . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__;
$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
$log_msg = "[" . date('Y-m-d H:i:s') . "] CODE: $codigo | URL: " . ($_SERVER['REQUEST_URI'] ?? 'N/A') . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . PHP_EOL;
@file_put_contents($log_dir . '/errores.log', $log_msg, FILE_APPEND);

// 2. TEXTOS PERSONALIZADOS (Añadimos iconos visuales para consistencia)
$titulo = "Error Desconocido";
$mensaje = "Algo ocurrió, pero no estamos seguros de qué.";
$icono_clase = "fa-solid fa-triangle-exclamation text-yellow-500";
$bg_color_class = "bg-yellow-50";

switch ($codigo) {
    case 400: 
        $titulo = "Solicitud Incorrecta"; 
        $mensaje = "La dirección o los datos enviados no son válidos."; 
        $icono_clase = "fa-solid fa-bug text-orange-500"; 
        $bg_color_class = "bg-orange-50";
        break;
    case 401: 
        $titulo = "Sesión Expirada"; 
        $mensaje = "Necesitas iniciar sesión nuevamente para continuar."; 
        $icono_clase = "fa-solid fa-lock text-gray-500"; 
        $bg_color_class = "bg-gray-100";
        break;
    case 403: 
        $titulo = "Acceso Prohibido"; 
        $mensaje = "No tienes permisos para ver esta área restringida."; 
        $icono_clase = "fa-solid fa-hand-paper text-red-500"; 
        $bg_color_class = "bg-red-50";
        break;
    case 404: 
        $titulo = "Página No Encontrada"; 
        $mensaje = "Lo sentimos, la ruta que buscas no existe o fue movida."; 
        $icono_clase = "fa-solid fa-ghost text-sky-500"; 
        $bg_color_class = "bg-sky-50";
        break;
    case 500: 
        $titulo = "Error del Servidor"; 
        $mensaje = "Tuvimos un problema interno. Ya estamos trabajando en ello."; 
        $icono_clase = "fa-solid fa-server text-red-600"; 
        $bg_color_class = "bg-red-50";
        break;
    case 503: 
        $titulo = "En Mantenimiento"; 
        $mensaje = "Estamos mejorando Nubira. Volveremos en breve."; 
        $icono_clase = "fa-solid fa-tools text-purple-500"; 
        $bg_color_class = "bg-purple-50";
        break;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Error <?= $codigo ?> | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <?php require_once __DIR__ . '/app/componentes/head_common.php'; ?>
    <link rel="stylesheet" href="/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;900&display=swap'); body{font-family:'Inter',sans-serif;}</style>
</head>
<body class="bg-gray-50 text-gray-800 h-screen flex flex-col overflow-hidden">

    <nav class="w-full bg-white/90 backdrop-blur border-b border-gray-200 h-16 flex items-center justify-center fixed top-0 z-50">
        <span class="text-xl font-bold text-sky-600 tracking-tight flex items-center gap-2">
            <i class="fa-solid fa-shapes"></i> Nubira
        </span>
    </nav>

    <main class="flex-1 flex flex-col items-center justify-center px-4 text-center relative">
        
        <div class="absolute inset-0 overflow-hidden -z-10 opacity-20">
            <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-blue-300 rounded-full mix-blend-multiply filter blur-3xl animate-blob"></div>
            <div class="absolute top-1/3 right-1/4 w-96 h-96 bg-purple-300 rounded-full mix-blend-multiply filter blur-3xl animate-blob animation-delay-2000"></div>
        </div>

        <div class="max-w-xl w-full p-8 rounded-3xl <?= $bg_color_class ?> border border-gray-100 shadow-2xl shadow-gray-300/40">
            
            <div class="relative mb-6">
                <h1 class="text-[120px] md:text-[180px] font-black text-gray-200/50 leading-none select-none">
                    <?= $codigo ?>
                </h1>
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                    <i class="<?= $icono_clase ?> text-6xl mb-2"></i>
                    <span class="text-3xl md:text-4xl font-extrabold text-gray-900 bg-white/70 backdrop-blur-sm px-4 py-1 rounded-lg border border-gray-100">
                        Error <?= $codigo ?>
                    </span>
                </div>
            </div>

            <h2 class="text-xl md:text-2xl font-bold text-gray-800 mt-[-10px] mb-3">
                <?= $titulo ?>
            </h2>
            
            <p class="text-gray-600 mx-auto text-base mb-8 leading-relaxed">
                <?= $mensaje ?>
            </p>

            <div class="flex flex-col sm:flex-row gap-3">
                <button onclick="history.back()" class="flex-1 px-8 py-3 rounded-xl border border-gray-300 text-gray-700 font-bold hover:bg-white transition shadow-sm flex items-center justify-center gap-2">
                    <i class="fa-solid fa-arrow-left"></i> Regresar
                </button>
                
                <a href="/vitrina.php" class="flex-1 px-8 py-3 rounded-xl bg-sky-600 text-white font-bold hover:bg-sky-700 transition shadow-lg shadow-sky-200 flex items-center justify-center gap-2">
                    <i class="fa-solid fa-house"></i> Ir al Inicio
                </a>
            </div>
            
            <div class="mt-6 text-xs text-gray-400 font-mono">
                Ref: <?= uniqid() ?>
            </div>
        </div>

    </main>

    <style>
        .animate-blob { animation: blob 7s infinite; }
        .animation-delay-2000 { animation-delay: 2s; }
        @keyframes blob {
            0% { transform: translate(0px, 0px) scale(1); }
            33% { transform: translate(30px, -50px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.9); }
            100% { transform: translate(0px, 0px) scale(1); }
        }
    </style>

</body>
</html>