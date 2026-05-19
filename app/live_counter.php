<?php
session_start();
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header("Location: /login.php");
    exit;
}
// VISTA: CONTADOR DE USUARIOS EN VIVO (Ubicación: app/live_counter.php)
// ESTADO: NUBIRA 2.0 BORDERLESS EVENT MODE (SMART ICON DISTRIBUTION)
require_once __DIR__ . '/conexion.php';

// Petición AJAX (Silenciosa)
if (isset($_GET['ajax'])) {
    header('Content-Type: text/plain');
    $total = $conn->query("SELECT COUNT(*) as c FROM alumnos")->fetch_assoc()['c'];
    echo (int)$total;
    exit;
}

// Carga Inicial
$total_inicial = (int)$conn->query("SELECT COUNT(*) as c FROM alumnos")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Live Counter | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap');
        
        body { 
            font-family: 'Inter', sans-serif; 
            margin: 0;
            padding: 0;
            overflow: hidden;
            background-color: #0f172a;
        }

        /* 1. MESH GRADIENT ANIMADO */
        .mesh-bg {
            position: absolute;
            inset: 0;
            background: linear-gradient(-45deg, #0ea5e9, #54A6D8, #8b5cf6, #3b82f6);
            background-size: 400% 400%;
            animation: gradientMove 20s ease infinite;
            z-index: 0;
        }
        @keyframes gradientMove {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* 2. ICONOS FLOTANTES */
        .floating-icon {
            position: absolute;
            color: rgba(255, 255, 255, 0.15);
            animation: floatUp linear infinite;
            z-index: 10; 
            filter: blur(0.5px);
        }
        @keyframes floatUp {
            0% { transform: translateY(110vh) rotate(0deg) scale(0.8); opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% { transform: translateY(-20vh) rotate(360deg) scale(1.2); opacity: 0; }
        }

        /* 3. TIPOGRAFÍA FLUIDA PARA NÚMEROS */
        .tabular-nums { font-variant-numeric: tabular-nums; }
        
        .number-display {
            background: linear-gradient(180deg, #ffffff 0%, #e0f2fe 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0px 12px 30px rgba(0,0,0,0.35));
            transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            font-size: clamp(6rem, 18vw, 22rem); 
            line-height: 1;
            width: 100%;
            text-align: center;
            margin: 0;
            padding: 0;
        }

        .tick-pop {
            transform: scale(1.05) translateY(-5px);
            filter: drop-shadow(0px 0px 50px rgba(255,255,255,0.9));
        }
    </style>
</head>
<body class="flex items-center justify-center h-screen w-screen relative">

    <div class="mesh-bg"></div>
    <div id="icon-container" class="absolute inset-0 overflow-hidden pointer-events-none z-10"></div>

    <div class="relative z-20 flex flex-col items-center justify-center w-full h-full px-6 py-12">
        
        <div class="flex items-center gap-3 mb-6 md:mb-12 mt-auto">
            <div class="w-10 h-10 md:w-16 md:h-16 bg-white/10 backdrop-blur-md rounded-xl md:rounded-2xl border border-white/20 flex items-center justify-center shadow-2xl rotate-3 transform hover:rotate-0 transition-transform">
                <i class="fa-solid fa-graduation-cap text-white text-xl md:text-3xl" style="filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));"></i>
            </div>
            <div class="flex flex-col text-left">
                <span class="text-3xl md:text-5xl font-black text-white tracking-tight leading-none" style="text-shadow: 0 4px 15px rgba(0,0,0,0.2);">Nubira.cl</span>
            </div>
        </div>
        
        <div class="relative flex justify-center items-center w-full z-30">
            <h1 id="counter" class="font-black tabular-nums number-display tracking-tighter">
                <?= number_format($total_inicial, 0, ',', '.') ?>
            </h1>
        </div>
        
        <div class="flex items-center justify-center gap-4 mt-4 md:mt-8 w-full max-w-4xl px-4 mb-auto z-30">
            <div class="h-px flex-1 bg-gradient-to-r from-transparent to-white/40"></div>
            <p class="text-white/95 text-sm md:text-xl font-bold tracking-widest uppercase text-center" style="text-shadow: 0 4px 10px rgba(0,0,0,0.3);">
                Estudiantes Registrados
            </p>
            <div class="h-px flex-1 bg-gradient-to-l from-transparent to-white/40"></div>
        </div>

        <div class="absolute bottom-8 flex items-center gap-2 bg-black/20 px-5 py-2 rounded-full border border-white/10 backdrop-blur-md z-30 shadow-lg">
            <span class="relative flex h-2 w-2">
              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
              <span class="relative inline-flex rounded-full h-2 w-2 bg-green-400"></span>
            </span>
            <span class="text-[10px] md:text-xs text-white/90 font-mono uppercase font-bold tracking-widest">Sincronización en tiempo real</span>
        </div>
    </div>

    <script>
        // --- 1. LÓGICA DE ÍCONOS DISTRIBUIDOS INTELIGENTEMENTE ---
        const iconClasses = ['fa-graduation-cap', 'fa-book-open', 'fa-pencil', 'fa-flask', 'fa-laptop-code', 'fa-atom', 'fa-calculator', 'fa-lightbulb', 'fa-globe', 'fa-book-bookmark'];
        const iconContainer = document.getElementById('icon-container');
        
        const totalIcons = 50; // Aumentamos a 50 para que se vea más tupido
        
        for(let i = 0; i < totalIcons; i++) {
            let el = document.createElement('i');
            el.className = `fa-solid ${iconClasses[Math.floor(Math.random() * iconClasses.length)]} floating-icon`;
            
            // Distribución Inteligente: Repartimos la pantalla en carriles para evitar espacios vacíos
            let franjaX = (100 / totalIcons) * i; 
            let offsetRand = (Math.random() * 4) - 2; // Ligera desviación de +/- 2% para no verse robótico
            el.style.left = `${franjaX + offsetRand}%`;
            
            el.style.fontSize = `${Math.random() * 1.5 + 1}rem`; 
            el.style.animationDuration = `${Math.random() * 25 + 15}s`; 
            el.style.animationDelay = `-${Math.random() * 30}s`; 
            iconContainer.appendChild(el);
        }

        // --- 2. LÓGICA DE SINCRONIZACIÓN Y CONFETI ---
        const milestones = [100, 500, 1000, 2500, 5000, 10000, 25000, 50000, 100000, 500000, 1000000];
        let currentTotal = <?= $total_inicial ?>;
        const counterEl = document.getElementById('counter');

        const formatNumber = (num) => new Intl.NumberFormat('es-CL').format(num);

        function triggerCelebration() {
            var duration = 8 * 1000; 
            var animationEnd = Date.now() + duration;
            var defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 100 };

            function randomInRange(min, max) { return Math.random() * (max - min) + min; }

            var interval = setInterval(function() {
              var timeLeft = animationEnd - Date.now();
              if (timeLeft <= 0) return clearInterval(interval);
              var particleCount = 50 * (timeLeft / duration);
              
              const colors = ['#FFD700', '#ffffff', '#54A6D8', '#8b5cf6'];
              confetti(Object.assign({}, defaults, { particleCount, origin: { x: randomInRange(0.1, 0.3), y: Math.random() - 0.2 }, colors }));
              confetti(Object.assign({}, defaults, { particleCount, origin: { x: randomInRange(0.7, 0.9), y: Math.random() - 0.2 }, colors }));
            }, 250);
        }

        setInterval(async () => {
            try {
                const res = await fetch('?ajax=1', { cache: "no-store" });
                const textNum = await res.text();
                const newTotal = parseInt(textNum, 10);

                if (!isNaN(newTotal) && newTotal > currentTotal) {
                    const crossedMilestone = milestones.some(m => currentTotal < m && newTotal >= m);
                    if (crossedMilestone) triggerCelebration();

                    counterEl.classList.add('tick-pop');
                    counterEl.innerText = formatNumber(newTotal);
                    
                    setTimeout(() => { counterEl.classList.remove('tick-pop'); }, 200);
                    currentTotal = newTotal;
                }
            } catch (error) {
                console.error("Error syncing counter:", error);
            }
        }, 2000);

        // SECRETO DE DEBUG para probar Hitos: window.testMilestone(99)
        window.testMilestone = (fakeCurrent = 99) => {
            currentTotal = fakeCurrent;
            counterEl.innerText = formatNumber(currentTotal);
            setTimeout(() => {
                counterEl.classList.add('tick-pop');
                counterEl.innerText = formatNumber(currentTotal + 1);
                triggerCelebration();
                setTimeout(() => counterEl.classList.remove('tick-pop'), 200);
                currentTotal += 1;
            }, 1500);
        };
    </script>
</body>
</html>