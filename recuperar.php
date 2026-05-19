<?php
session_start();
$mensaje = $_SESSION['mensaje_recuperacion'] ?? '';
unset($_SESSION['mensaje_recuperacion']);
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Recuperar Contraseña | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    :root{ --nubira:#54A6D8; }
    .text-nubira{ color: var(--nubira); }
    .bg-nubira{ background-color: var(--nubira); }
    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      20%, 60% { transform: translateX(-4px); }
      40%, 80% { transform: translateX(4px); }
    }
    .animate-shake { animation: shake 0.4s ease-in-out; }
  </style>
</head>

<body class="bg-white min-h-screen flex">

  <div class="hidden md:flex w-1/2 h-screen sticky top-0 bg-cover bg-center relative" 
       style="background-image: url('https://images.unsplash.com/photo-1481627834876-b7833e8f5570?q=80&w=2128&auto=format&fit=crop');">
       
       <div class="absolute inset-0 bg-gradient-to-t from-blue-900/90 via-blue-900/40 to-transparent"></div>
       
       <div class="absolute bottom-12 left-12 text-white pr-12 z-10">
           <h2 class="text-4xl font-extrabold mb-4 leading-tight">Recupera tu<br>acceso.</h2>
           <p class="text-lg opacity-90 font-medium">No te preocupes, te ayudaremos a volver a entrar a tu cuenta en pocos pasos.</p>
           
           <div class="mt-8 flex items-center gap-2 text-sm opacity-80">
               <i class="fa-solid fa-shield-halved"></i> Proceso seguro y verificado.
           </div>
       </div>
  </div>

  <div class="w-full md:w-1/2 flex flex-col bg-white min-h-screen">
    <div class="flex-1 flex items-center justify-center p-6 md:p-12 lg:p-16">
        <div class="w-full max-w-md">

            <div class="mb-8 text-center md:text-left">
                <a href="/" class="inline-block">
                    <img src="/img/logo.webp" alt="Logo Nubira" class="h-12 w-auto">
                </a>
            </div>

            <div class="md:hidden mb-6">
                <a href="/login" class="text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm">
                    <i class="fa-solid fa-arrow-left"></i> Volver al inicio
                </a>
            </div>

            <h1 class="text-3xl font-bold text-gray-900 mb-2 text-center md:text-left">¿Olvidaste tu contraseña?</h1>
            <p class="text-gray-500 mb-8 text-sm text-center md:text-left">Ingresa tu correo institucional y te enviaremos las instrucciones.</p>

            <?php if ($mensaje): ?>
                <div class="mb-6 p-4 rounded-xl flex items-start gap-3 text-sm border animate-shake bg-blue-50 text-blue-700 border-blue-200">
                    <i class="fa-solid fa-circle-info mt-0.5"></i>
                    <div class="flex-1"><?= htmlspecialchars($mensaje) ?></div>
                </div>
            <?php endif; ?>

            <form action="enviar_recuperacion.php" method="POST" class="space-y-5" id="recoveryForm" autocomplete="off">
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1.5">Correo Institucional</label>
                    <div class="relative">
                        <i class="fa-regular fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="email" name="correo" id="correo" required autofocus
                            class="w-full pl-10 pr-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent outline-none transition"
                            placeholder="usuario@institucion.cl">
                    </div>
                </div>

                <button type="submit" id="btnSubmit"
                        class="w-full bg-[#54A6D8] hover:bg-[#3d91c7] text-white font-bold py-3 rounded-xl shadow-lg hover:shadow-xl transition transform active:scale-[.98] flex justify-center items-center gap-2 text-base">
                    <span>Enviar enlace</span>
                    <i class="fa-solid fa-paper-plane"></i>
                </button>

            </form>

            <div class="mt-8 text-center border-t border-gray-100 pt-6">
                <p class="text-sm text-gray-600">
                    ¿Ya recordaste tu clave? 
                    <a href="/login" class="text-[#54A6D8] font-bold hover:underline">Inicia sesión</a>
                </p>
            </div>

        </div>
    </div>
  </div>

  <script>
    // Feedback en botón al enviar
    const form = document.getElementById('recoveryForm');
    const btn = document.getElementById('btnSubmit');

    form.addEventListener('submit', () => {
        btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Enviando...';
        btn.classList.add('opacity-75', 'cursor-not-allowed');
        btn.disabled = true;
    });
  </script>

</body>
</html>