<?php
/**
 * VISTA: GUÍA DE TUTORES (Panel Operativo Flat UI)
 * ESTADO: Nubira 2.0 - App Nativa (Sin sombras, color reducido, feedback integrado)
 */
session_start();

// --- ANTI-CACHÉ AGRESIVO NUBIRA 2.0 ---
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

if (!isset($_SESSION['usuario_id'])) { 
    header("Location: /login"); 
    exit; 
}

// RESOLUCIÓN DE RUTAS ESTRICTA
$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists($app_dir . '/app/conexion.php')) $app_dir = $app_dir . '/app';
    elseif (file_exists(dirname($app_dir) . '/app')) $app_dir = dirname($app_dir) . '/app';
}
require_once $app_dir . '/conexion.php';
if (file_exists($app_dir . '/iconos.php')) require_once $app_dir . '/iconos.php';

$usuario_id = (int)$_SESSION['usuario_id'];

// LÓGICA DE NEGOCIO
$query_acceso = "
    SELECT 
        a.rol,
        (SELECT COUNT(id) FROM servicios WHERE alumno_id = ?) AS total_servicios,
        (SELECT COUNT(id) FROM apuntes WHERE id_alumno = ?) AS total_apuntes
    FROM alumnos a 
    WHERE a.id = ? LIMIT 1
";
$stmt = $conn->prepare($query_acceso);
$stmt->bind_param("iii", $usuario_id, $usuario_id, $usuario_id);
$stmt->execute();
$usuario = $stmt->get_result()->fetch_assoc();
$stmt->close();

$rol_db = $usuario ? strtolower(trim($usuario['rol'])) : 'desconocido';
$es_creador = (($usuario['total_servicios'] ?? 0) + ($usuario['total_apuntes'] ?? 0)) > 0;

if (!$es_creador && $rol_db !== 'tutor' && $rol_db !== 'admin') {
    header("Location: /"); 
    exit;
}

// OBTENER VOTOS PREVIOS DEL USUARIO (Para bloquear la UI si ya votó)
$votos_previos = [];
// Failsafe por si la tabla aún no existe en tu BD
$check_table = $conn->query("SHOW TABLES LIKE 'guia_feedback'");
if ($check_table && $check_table->num_rows > 0) {
    $q_votos = "SELECT seccion FROM guia_feedback WHERE usuario_id = ?";
    $stmt_v = $conn->prepare($q_votos);
    $stmt_v->bind_param("i", $usuario_id);
    $stmt_v->execute();
    $res_v = $stmt_v->get_result();
    while ($row = $res_v->fetch_assoc()) {
        $votos_previos[] = $row['seccion'];
    }
    $stmt_v->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Guía del Tutor | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=0" />
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { nubira: '#54A6D8' } } } }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap">
    
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #ffffff; -webkit-tap-highlight-color: transparent; }
        .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
        ::-webkit-scrollbar { width: 0px; background: transparent; }
        
        /* Acordeón */
        .accordion-content { transition: max-height 0.3s ease-out, opacity 0.3s ease-out, margin 0.3s ease-out; max-height: 0; opacity: 0; margin-top: 0; overflow: hidden; }
        .accordion-content.open { max-height: 600px; opacity: 1; margin-top: 12px; }
        
        #loader { position: fixed; inset: 0; background: #ffffff; z-index: 999999; display: flex; align-items: center; justify-content: center; transition: opacity 0.3s ease-out; }
        .spinner-nativo { width: 40px; height: 40px; border: 4px solid #f3f4f6; border-top-color: #54A6D8; border-radius: 50%; animation: spin-nativo 0.8s linear infinite; }
        @keyframes spin-nativo { 100% { transform: rotate(360deg); } }
        .animate-fade-in-up { animation: fadeInUp 0.4s ease-out forwards; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>

<body class="text-gray-800 antialiased overflow-x-hidden">

<div id="loader"><div class="spinner-nativo"></div></div>

<?php 
$header_path = $app_dir . '/componentes/header.php';
$sidebar_path = $app_dir . '/componentes/sidebar.php';
if(file_exists($header_path)) require_once $header_path; 
if(file_exists($sidebar_path)) require_once $sidebar_path; 
?>

<main class="pt-20 pb-32 md:pb-12 lg:ml-64 mx-auto max-w-[900px] px-4 md:px-8 min-h-screen flex flex-col gap-5">
    
    <!-- Hero Section Minimalista (Sin sombras, sin colores saturados) -->
    <section class="border-b border-gray-100 pb-5 pt-2 animate-fade-in-up">
        <div class="flex items-center gap-2 mb-1 text-xs font-bold text-gray-400 uppercase tracking-widest">
          
        </div>
        <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-gray-900 leading-none">Guía del Tutor</h1>
        <p class="text-gray-500 text-sm mt-2 max-w-xl">
            Domina el flujo de pagos, el funcionamiento de tu Mini Aula y las normativas esenciales para escalar tus servicios en Nubira.
        </p>
    </section>

    <!-- Grilla Flat -->
    <section class="grid grid-cols-1 md:grid-cols-2 gap-4 animate-fade-in-up" style="animation-delay: 50ms;">
        
        <?php 
        // Helper para renderizar el widget de feedback
        function renderFeedbackWidget($id_seccion, $votos_previos) {
            $ya_voto = in_array($id_seccion, $votos_previos);
            if ($ya_voto) {
                return '<div class="mt-auto pt-4 border-t border-gray-100 flex items-center justify-between">
                            <span class="text-[11px] font-bold text-gray-400"><i class="fa-solid fa-check text-green-500 mr-1"></i> Gracias por tu opinión</span>
                        </div>';
            } else {
                return '<div class="mt-auto pt-4 border-t border-gray-100 flex items-center justify-between" id="feedback-ui-'.$id_seccion.'">
                            <span class="text-[11px] font-semibold text-gray-500">¿Te fue útil esta info?</span>
                            <div class="flex gap-1">
                                <button onclick="enviarVoto(\''.$id_seccion.'\', 1)" class="w-7 h-7 rounded-full bg-gray-50 border border-gray-100 text-gray-400 hover:bg-gray-100 hover:text-green-600 transition-colors flex items-center justify-center"><i class="fa-regular fa-thumbs-up text-xs"></i></button>
                                <button onclick="enviarVoto(\''.$id_seccion.'\', 0)" class="w-7 h-7 rounded-full bg-gray-50 border border-gray-100 text-gray-400 hover:bg-gray-100 hover:text-red-500 transition-colors flex items-center justify-center"><i class="fa-regular fa-thumbs-down text-xs"></i></button>
                            </div>
                        </div>';
            }
        }
        ?>

        <!-- Card 1: Mini Aula -->
        <article class="bg-white rounded-2xl border border-gray-200 p-5 flex flex-col hover:border-gray-300 transition-colors relative">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-8 h-8 rounded-lg border border-gray-200 bg-gray-50 text-gray-600 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-laptop-house text-sm"></i>
                </div>
                <h2 class="text-base font-bold tracking-tight text-gray-900">Tu Mini Aula Virtual</h2>
            </div>
            <p class="text-gray-500 text-xs md:text-sm leading-relaxed mb-3">
                Cuando un alumno contrata tu servicio, se habilita automáticamente un <strong>Mini Aula</strong> privada con videollamada, chat y envío de archivos. No necesitas usar plataformas externas.
            </p>
            <button onclick="toggleInfo('acc-1', 'icon-acc-1')" class="text-gray-900 font-bold text-xs hover:underline flex items-center gap-1.5 w-max mb-4">
                ¿Cómo finaliza la clase? <i id="icon-acc-1" class="fa-solid fa-chevron-down text-[9px] text-gray-400 transition-transform"></i>
            </button>
            <div id="acc-1" class="accordion-content bg-gray-50 p-4 rounded-xl text-xs text-gray-600 border border-gray-100 mb-4">
                <p class="mb-2 font-medium">El alumno presiona "Terminada" al concluir:</p>
                <ul class="space-y-1.5">
                    <li>1. Tu botón de cobro se activa instantáneamente.</li>
                    <li>2. Ambos pueden dejarse una valoración pública.</li>
                    <li>3. El saldo se transfiere a tu billetera Nubira.</li>
                </ul>
            </div>
            <?= renderFeedbackWidget('mini_aula', $votos_previos) ?>
        </article>

        <!-- Card 2: Reglas -->
        <article class="bg-white rounded-2xl border border-gray-200 p-5 flex flex-col hover:border-gray-300 transition-colors relative">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-8 h-8 rounded-lg border border-red-100 bg-red-50 text-red-500 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-user-shield text-sm"></i>
                </div>
                <h2 class="text-base font-bold tracking-tight text-gray-900">Normativa de Mensajería</h2>
            </div>
            <p class="text-gray-500 text-xs md:text-sm leading-relaxed mb-3">
                Mantén toda la comunicación en la plataforma. Está estrictamente prohibido compartir números telefónicos, correos o redes sociales por el chat interno.
            </p>
            <button onclick="toggleInfo('acc-2', 'icon-acc-2')" class="text-red-500 font-bold text-xs hover:underline flex items-center gap-1.5 w-max mb-4">
                Infracciones <i id="icon-acc-2" class="fa-solid fa-chevron-down text-[9px] transition-transform"></i>
            </button>
            <div id="acc-2" class="accordion-content bg-red-50 p-4 rounded-xl text-xs text-red-800 border border-red-100 mb-4">
                <p class="font-bold mb-1">Tolerancia Cero:</p>
                <p>Nuestros sistemas auditan la evasión. Compartir contactos externos resulta en la baja automática de tus servicios y la eliminación permanente de tu cuenta.</p>
            </div>
            <?= renderFeedbackWidget('reglas_chat', $votos_previos) ?>
        </article>

        <!-- Card 3: Pago Protegido -->
        <article class="bg-white rounded-2xl border border-gray-200 p-5 flex flex-col hover:border-gray-300 transition-colors relative">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-8 h-8 rounded-lg border border-gray-200 bg-gray-50 text-gray-600 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-shield-halved text-sm"></i>
                </div>
                <h2 class="text-base font-bold tracking-tight text-gray-900">Pago Protegido</h2>
            </div>
            <p class="text-gray-500 text-xs md:text-sm leading-relaxed mb-3">
                Inicia tus clases con tranquilidad. El dinero del estudiante es retenido y asegurado por Nubira antes de que el Mini Aula se abra. 
            </p>
            <button onclick="toggleInfo('acc-3', 'icon-acc-3')" class="text-gray-900 font-bold text-xs hover:underline flex items-center gap-1.5 w-max mb-4">
                ¿Qué pasa si el alumno olvida confirmar? <i id="icon-acc-3" class="fa-solid fa-chevron-down text-[9px] text-gray-400 transition-transform"></i>
            </button>
            <div id="acc-3" class="accordion-content bg-gray-50 p-4 rounded-xl text-xs text-gray-600 border border-gray-100 mb-4">
                <p>Nuestro equipo audita la actividad del Mini Aula. Si hay evidencia de que la clase ocurrió, los fondos son liberados a tu favor tras 48 horas de disputa.</p>
            </div>
            <?= renderFeedbackWidget('pago_protegido', $votos_previos) ?>
        </article>

        <!-- Card 4: Creación -->
        <article class="bg-white rounded-2xl border border-gray-200 p-5 flex flex-col hover:border-gray-300 transition-colors relative">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-8 h-8 rounded-lg border border-gray-200 bg-gray-50 text-gray-600 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-image text-sm"></i>
                </div>
                <h2 class="text-base font-bold tracking-tight text-gray-900">Optimización de Perfil</h2>
            </div>
            <p class="text-gray-500 text-xs md:text-sm leading-relaxed mb-4">
                Usa imágenes limpias en proporción horizontal (16:9) sin exceso de texto. Un título claro y etiquetas correctas garantizan mejor posicionamiento orgánico en la vitrina.
            </p>
            <div class="mb-4">
                <button onclick="setupModalNav('btn-pub-guia', 'modal-quick', 'quick-card', 'quick-close')" id="btn-pub-guia" class="bg-gray-50 border border-gray-200 text-gray-800 font-bold text-xs px-4 py-2 rounded-lg hover:bg-gray-100 transition-colors w-max">
                    Publicar Servicio
                </button>
            </div>
            <?= renderFeedbackWidget('optimizacion_perfil', $votos_previos) ?>
        </article>

    </section>
</main>

<?php 
$nav_bottom_path = $app_dir . '/componentes/nav_bottom.php';
$modal_pub_path = $app_dir . '/componentes/modal_publicar.php';
$modal_exp_path = $app_dir . '/componentes/modal_explora.php';

if(file_exists($nav_bottom_path)) require_once $nav_bottom_path; 
if(file_exists($modal_pub_path)) require_once $modal_pub_path; 
if(file_exists($modal_exp_path)) require_once $modal_exp_path; 
?>

<script>
    window.onload = () => { 
        const l = document.getElementById('loader'); 
        if(l){ l.style.opacity = '0'; setTimeout(() => l.style.display = 'none', 300); } 
    };

    // Acordeón Minimalista
    function toggleInfo(idContent, idIcon) {
        const content = document.getElementById(idContent);
        const icon = document.getElementById(idIcon);
        
        if (content.classList.contains('open')) {
            content.classList.remove('open');
            icon.classList.remove('fa-chevron-up', 'text-gray-900');
            icon.classList.add('fa-chevron-down', 'text-gray-400');
        } else {
            content.classList.add('open');
            icon.classList.remove('fa-chevron-down', 'text-gray-400');
            icon.classList.add('fa-chevron-up', 'text-gray-900');
        }
    }

    // Lógica del Feedback (Bloqueo UI instantáneo)
    function enviarVoto(seccion, voto) {
        const container = document.getElementById('feedback-ui-' + seccion);
        if(!container) return;

        // Optimistic UI Update (Cambio inmediato)
        container.innerHTML = `<span class="text-[11px] font-bold text-gray-400"><i class="fa-solid fa-check text-green-500 mr-1"></i> Gracias por tu opinión</span>`;

        // Llamada silenciosa al backend
        fetch('/app/api/registrar_feedback_guia.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ seccion: seccion, voto: voto })
        }).catch(err => console.log('Error silenciado de red'));
    }

    // Modales
    function setupModalNav(triggerId, modalId, cardId, closeId) {
        const btn = document.getElementById(triggerId);
        const modal = document.getElementById(modalId);
        const card = document.getElementById(cardId);
        const close = document.getElementById(closeId);
        
        if(!btn || !modal) return;
        const open = () => { modal.classList.remove('hidden'); requestAnimationFrame(() => card.classList.remove('translate-y-full','opacity-0')); document.body.style.overflow = 'hidden'; };
        const shut = () => { card.classList.add('translate-y-full','opacity-0'); setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); };
        
        btn.onclick = (e) => { e.preventDefault(); open(); }; 
        if(close) close.onclick = shut; 
        modal.onclick = (e) => { if(e.target === modal) shut(); };
    }

    setupModalNav('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
    setupModalNav('btn-pub-guia', 'modal-quick', 'quick-card', 'quick-close');
    setupModalNav('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
</script>

</body>
</html>