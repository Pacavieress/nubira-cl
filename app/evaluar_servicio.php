<?php
/**
 * VISTA: EVALUAR SERVICIO
 * Estilo: Nubira 2.0 (Clean Focus)
 */
session_start();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/iconos.php';

// 1. SEGURIDAD
if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit; }

$usuario_id = (int)$_SESSION['usuario_id'];
$id_contrato = (int)($_GET['id'] ?? 0);

if ($id_contrato <= 0) { header("Location: /dashboard"); exit; }

// 2. OBTENER DATOS
$sql = "SELECT c.*, s.titulo 
        FROM contratos c 
        JOIN servicios s ON s.id = c.servicio_id 
        WHERE c.id = ? AND (c.comprador_id = ? OR c.vendedor_id = ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $id_contrato, $usuario_id, $usuario_id);
$stmt->execute();
$contrato = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contrato) { header("Location: /dashboard"); exit; }

// 3. DETERMINAR ROL
$rol = ($usuario_id === (int)$contrato['vendedor_id']) ? 'vendedor' : 'comprador';

// 4. VERIFICAR PERMISO DE ACCESO (LÓGICA CORREGIDA)
$es_finalizado_global = ($contrato['estado'] === 'finalizado');
$alguien_finalizo = ($contrato['finalizado_comprador'] == 1 || $contrato['finalizado_vendedor'] == 1);

if (!$es_finalizado_global && !$alguien_finalizo) {
    header("Location: /app/mini_aula.php?id=" . $id_contrato); 
    exit;
}

// 5. VERIFICAR SI YA CALIFICÓ Y REDIRIGIR AL PANEL CORRESPONDIENTE
$ya_califico = ($rol === 'comprador') ? $contrato['calificacion_comprador'] : $contrato['calificacion_vendedor'];
$ruta_salida = ($rol === 'vendedor') ? '/ventas_clases' : '/mis_compras';

if ($ya_califico) {
    header("Location: " . $ruta_salida); 
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
    <title>Evaluar Experiencia | Nubira</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap'); 
        body { font-family: 'Inter', sans-serif; }
        
        .fade-in-up { animation: fadeInUp 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; opacity: 0; transform: translateY(20px); }
        @keyframes fadeInUp { to { opacity: 1; transform: translateY(0); } }
        
        /* Magia CSS para rating stars de derecha a izquierda */
        .star-group { display: flex; flex-direction: row-reverse; justify-content: center; }
        .star-group input { display: none; }
        .star-group label { color: #e5e7eb; cursor: pointer; padding: 0 0.25rem; transition: all 0.2s; font-size: 2.5rem; }
        .star-group label:hover,
        .star-group label:hover ~ label,
        .star-group input:checked ~ label { color: #facc15; }
        .star-group label:active { transform: scale(0.9); }
    </style>
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-[100dvh] p-4">

    <div class="bg-white w-full max-w-md rounded-3xl shadow-xl overflow-hidden border border-gray-100 fade-in-up relative">
        
        <a href="<?= $ruta_salida ?>" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 transition active:scale-95">
            <i class="fa-solid fa-xmark text-lg"></i>
        </a>

        <div class="p-8 text-center mt-2">
            <div class="w-20 h-20 bg-gradient-to-br from-sky-100 to-white rounded-full flex items-center justify-center mx-auto mb-6 shadow-sm border border-sky-50">
                <i class="fa-solid fa-star text-4xl text-[#54A6D8]"></i>
            </div>
            
            <h1 class="text-2xl font-extrabold text-slate-800 mb-2 tracking-tight">¡Servicio Finalizado!</h1>
            <p class="text-gray-500 text-sm mb-8 leading-relaxed px-4">
                Tu opinión es vital para la comunidad.<br>
                Califica tu experiencia en: <br>
                <span class="text-slate-800 font-bold block mt-1 line-clamp-2">"<?= htmlspecialchars($contrato['titulo']) ?>"</span>
            </p>

            <form action="/app/procesar_evaluacion.php" method="POST" class="space-y-6">
                <input type="hidden" name="contrato_id" value="<?= $id_contrato ?>">
                <input type="hidden" name="rol_evaluador" value="<?= $rol ?>">
                
                <div class="flex flex-col items-center gap-2 mb-4">
                    <div class="star-group">
                        <input type="radio" id="star5" name="estrellas" value="5" required>
                        <label for="star5"><i class="fa-solid fa-star"></i></label>
                        
                        <input type="radio" id="star4" name="estrellas" value="4">
                        <label for="star4"><i class="fa-solid fa-star"></i></label>
                        
                        <input type="radio" id="star3" name="estrellas" value="3">
                        <label for="star3"><i class="fa-solid fa-star"></i></label>
                        
                        <input type="radio" id="star2" name="estrellas" value="2">
                        <label for="star2"><i class="fa-solid fa-star"></i></label>
                        
                        <input type="radio" id="star1" name="estrellas" value="1">
                        <label for="star1"><i class="fa-solid fa-star"></i></label>
                    </div>
                    <p class="text-xs text-gray-400 font-bold h-4 transition-colors duration-200 mt-1" id="rating-text">Selecciona una calificación</p>
                </div>

                <div class="text-left relative">
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-2 ml-1">
                        <?= $rol === 'comprador' ? '¿Cómo fue tu tutoría?' : '¿Qué tal el alumno?' ?> (Opcional)
                    </label>
                    <textarea name="comentario" rows="3" 
                        class="w-full bg-gray-50 border border-gray-200 rounded-2xl p-4 text-sm font-medium text-slate-700 focus:outline-none focus:ring-2 focus:ring-[#54A6D8]/20 focus:border-[#54A6D8] transition-all resize-none placeholder-gray-400 shadow-inner" 
                        placeholder="<?= $rol === 'comprador' ? 'Excelente explicación, muy paciente...' : 'Muy participativo y puntual...' ?>"></textarea>
                </div>

                <button type="submit" class="w-full bg-[#54A6D8] hover:bg-sky-500 text-white font-bold py-4 rounded-2xl shadow-lg shadow-sky-500/30 transition-transform active:scale-[0.98] flex items-center justify-center gap-2 mt-4">
                    <span>Enviar Calificación</span>
                    <i class="fa-solid fa-paper-plane text-sm"></i>
                </button>
            </form>
        </div>
        
        <div class="bg-gray-50 p-4 text-center border-t border-gray-100">
            <a href="<?= $ruta_salida ?>" class="text-xs text-gray-400 hover:text-slate-700 font-bold transition-colors">
                Omitir y volver al Panel
            </a>
        </div>
    </div>

    <script>
        const labels = document.querySelectorAll('.star-group label');
        const textDisplay = document.getElementById('rating-text');
        // Orden invertido porque el HTML está invertido por flex-row-reverse
        const texts = { "5": "¡Excelente!", "4": "Bueno", "3": "Regular", "2": "Malo", "1": "Muy malo" };

        labels.forEach(label => {
            label.addEventListener('mouseenter', () => {
                const val = label.getAttribute('for').replace('star', '');
                textDisplay.textContent = texts[val];
                textDisplay.classList.add('text-[#54A6D8]');
            });
            
            label.addEventListener('mouseleave', () => {
                const checked = document.querySelector('input[name="estrellas"]:checked');
                if(checked) {
                    textDisplay.textContent = texts[checked.value];
                } else {
                    textDisplay.textContent = "Selecciona una calificación";
                    textDisplay.classList.remove('text-[#54A6D8]');
                }
            });
        });
        
        document.querySelectorAll('input[name="estrellas"]').forEach(input => {
            input.addEventListener('change', (e) => {
                textDisplay.textContent = texts[e.target.value];
                textDisplay.classList.add('text-[#54A6D8]');
            });
        });
        // Protección Nubira 2.0: Evitar envíos duplicados
const formEvaluacion = document.querySelector('form');
const btnSubmit = formEvaluacion.querySelector('button[type="submit"]');

formEvaluacion.addEventListener('submit', function() {
    // Si ya está deshabilitado, no hacemos nada
    if (btnSubmit.disabled) return;
    
    // Deshabilitar y mostrar loader
    btnSubmit.disabled = true;
    btnSubmit.classList.add('opacity-75', 'cursor-not-allowed');
    btnSubmit.innerHTML = `
        <div class="animate-spin h-5 w-5 border-2 border-white border-t-transparent rounded-full"></div>
        <span>Procesando...</span>
    `;
});
    </script>
    
</body>
</html>