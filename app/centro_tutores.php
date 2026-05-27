<?php
/**
 * VISTA: CENTRO DE TUTORES (Dinámica y Flat UI)
 * ESTADO: Nubira 2.0 - App Nativa (Integración estricta de rutas, UI plana, feedback integrado)
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

// 1. RESOLUCIÓN DE RUTAS ESTRICTA
$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists($app_dir . '/app/conexion.php')) $app_dir = $app_dir . '/app';
    elseif (file_exists(dirname($app_dir) . '/app')) $app_dir = dirname($app_dir) . '/app';
}
require_once $app_dir . '/conexion.php';
if (file_exists($app_dir . '/iconos.php')) require_once $app_dir . '/iconos.php';

$usuario_id = (int)$_SESSION['usuario_id'];

// 2. LÓGICA DE ACCESO DINÁMICO NUBIRA 2.0
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
$es_admin = ($rol_db === 'admin');

// Escudo Protector: Si no tiene contenido, no es tutor ni admin, se expulsa a la vitrina
if (!$es_creador && $rol_db !== 'tutor' && !$es_admin) {
    header("Location: /"); 
    exit;
}

// 3. OBTENER VOTOS PREVIOS (Para bloquear feedback duplicado)
$votos_previos = [];
$check_feedback = $conn->query("SHOW TABLES LIKE 'guia_feedback'");
if ($check_feedback && $check_feedback->num_rows > 0) {
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

// 4. CARGAR CONTENIDO DINÁMICO DEL CMS
$tarjetas_guia = [];
$check_contenido = $conn->query("SHOW TABLES LIKE 'guia_tutores_contenido'");
if ($check_contenido && $check_contenido->num_rows > 0) {
    $q_guias = "SELECT * FROM guia_tutores_contenido WHERE activo = 1 ORDER BY orden ASC";
    $res_guias = $conn->query($q_guias);
    if ($res_guias) {
        while ($row = $res_guias->fetch_assoc()) {
            $tarjetas_guia[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Centro de Tutores | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=0" />
    <meta name="theme-color" content="#ffffff" />
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
    
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
        
        body.fouc-lock > *:not(#loader) { display: none !important; }
        #loader { position: fixed; inset: 0; background: #ffffff; z-index: 999999; display: flex; align-items: center; justify-content: center; transition: opacity 0.3s ease-out; }
        .spinner-nativo { width: 40px; height: 40px; border: 4px solid #f3f4f6; border-top-color: #54A6D8; border-radius: 50%; animation: spin-nativo 0.8s linear infinite; }
        @keyframes spin-nativo { 100% { transform: rotate(360deg); } }
        
        .accordion-content { transition: max-height 0.3s ease-out, opacity 0.3s ease-out, margin 0.3s ease-out; max-height: 0; opacity: 0; margin-top: 0; overflow: hidden; }
        .accordion-content.open { max-height: 600px; opacity: 1; margin-top: 12px; }
        
        .animate-fade-in-up { animation: fadeInUp 0.4s ease-out forwards; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="text-gray-800 antialiased overflow-x-hidden fouc-lock">

<div id="loader"><div class="spinner-nativo"></div></div>

<?php 
// 5. CARGA DE COMPONENTES OFICIALES
$header_path = $app_dir . '/componentes/header.php';
$sidebar_path = $app_dir . '/componentes/sidebar.php';
if(file_exists($header_path)) require_once $header_path; 
if(file_exists($sidebar_path)) require_once $sidebar_path; 
?>

<!-- MAIN CONTAINER NUBIRA 2.0 -->
<main class="pt-20 pb-32 md:pb-12 lg:ml-64 mx-auto max-w-[1100px] px-4 md:px-8 min-h-screen flex flex-col gap-5">
    
    <!-- Hero Section Flat UI -->
    <section class="border-b border-gray-100 pb-5 pt-2 animate-fade-in-up flex flex-col md:flex-row md:items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1 text-xs font-bold text-gray-400 uppercase tracking-widest">
                <i class="fa-solid fa-graduation-cap"></i> Centro de Operaciones
            </div>
            <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-gray-900 leading-none">Guía de Éxito</h1>
            <p class="text-gray-500 text-sm mt-2 max-w-2xl">
                Domina el flujo de pagos, el funcionamiento de tu Mini Aula y las normativas esenciales para operar en Nubira sin fricciones.
            </p>
        </div>
        
        <?php if ($es_admin): ?>
            <a href="/admin/gestionar_guia" class="inline-flex items-center justify-center gap-2 bg-gray-900 text-white font-bold text-xs px-4 py-2.5 rounded-xl hover:bg-gray-800 transition shrink-0 shadow-sm active:scale-95">
                <i class="fa-solid fa-pen-to-square"></i> Editar Guía
            </a>
        <?php endif; ?>
    </section>

    <!-- Grilla de Cards Flat -->
    <section class="grid grid-cols-1 md:grid-cols-2 gap-4 animate-fade-in-up" style="animation-delay: 50ms;">
        
        <?php 
        // Helper UI Feedback
        function renderFeedbackWidget($id_seccion, $votos_previos) {
            if (in_array($id_seccion, $votos_previos)) {
                return '<div class="mt-auto pt-4 border-t border-gray-100 flex items-center justify-between"><span class="text-[11px] font-bold text-gray-400"><i class="fa-solid fa-check text-green-500 mr-1"></i> Gracias por tu opinión</span></div>';
            } else {
                return '<div class="mt-auto pt-4 border-t border-gray-100 flex items-center justify-between" id="feedback-ui-'.$id_seccion.'">
                            <span class="text-[11px] font-semibold text-gray-500">¿Te fue útil esta info?</span>
                            <div class="flex gap-1.5">
                                <button onclick="enviarVoto(\''.$id_seccion.'\', 1)" class="w-8 h-8 rounded-full bg-gray-50 border border-gray-100 text-gray-400 hover:bg-gray-100 hover:text-green-600 transition-colors flex items-center justify-center active:scale-95"><i class="fa-regular fa-thumbs-up text-xs"></i></button>
                                <button onclick="enviarVoto(\''.$id_seccion.'\', 0)" class="w-8 h-8 rounded-full bg-gray-50 border border-gray-100 text-gray-400 hover:bg-gray-100 hover:text-red-500 transition-colors flex items-center justify-center active:scale-95"><i class="fa-regular fa-thumbs-down text-xs"></i></button>
                            </div>
                        </div>';
            }
        }

        if (!empty($tarjetas_guia)) {
            foreach ($tarjetas_guia as $index => $card) {
                $id_acc = "acc-" . $card['id'];
                $id_icon = "icon-acc-" . $card['id'];
                $seccion_slug = "seccion_" . $card['id']; 
                ?>
                <article class="bg-white rounded-2xl border border-gray-200 p-5 flex flex-col hover:border-gray-300 transition-all relative group">
                    
                    <?php if ($es_admin): ?>
                        <div class="absolute top-3 right-3 opacity-0 group-hover:opacity-100 transition-opacity">
                            <a href="/admin/gestionar_guia?edit=<?= $card['id'] ?>" class="w-7 h-7 bg-gray-100 hover:bg-gray-200 text-gray-500 hover:text-gray-900 rounded-full flex items-center justify-center transition-colors"><i class="fa-solid fa-pencil text-[10px]"></i></a>
                        </div>
                    <?php endif; ?>

                    <div class="flex items-center gap-3 mb-3 pr-8">
                        <div class="w-9 h-9 rounded-lg <?= htmlspecialchars($card['color_bg']) ?> <?= htmlspecialchars($card['color_text']) ?> flex items-center justify-center shrink-0 border">
                            <i class="<?= htmlspecialchars($card['icono']) ?> text-sm"></i>
                        </div>
                        <h2 class="text-base font-bold tracking-tight text-gray-900 leading-tight"><?= htmlspecialchars($card['titulo']) ?></h2>
                    </div>
                    
                    <div class="text-gray-500 text-xs md:text-sm leading-relaxed mb-4">
                        <?= preg_replace('/(\*\*)(.*?)\1/', '<strong class="text-gray-700">$2</strong>', htmlspecialchars($card['descripcion'])) ?>
                    </div>
                    
                    <?php if (!empty(trim($card['contenido_html']))): ?>
                        <button onclick="toggleInfo('<?= $id_acc ?>', '<?= $id_icon ?>')" class="<?= htmlspecialchars($card['color_text']) ?> font-bold text-xs hover:underline flex items-center gap-1.5 w-max mb-4">
                            Ver detalles <i id="<?= $id_icon ?>" class="fa-solid fa-chevron-down text-[9px] text-gray-400 transition-transform"></i>
                        </button>
                        <div id="<?= $id_acc ?>" class="accordion-content bg-gray-50 p-4 rounded-xl text-xs md:text-sm text-gray-600 border border-gray-100 mb-4">
                            <?= $card['contenido_html'] ?>
                        </div>
                    <?php endif; ?>

                    <?= renderFeedbackWidget($seccion_slug, $votos_previos) ?>
                </article>
                <?php
            }
        } else {
            // Estado vacío estricto
            echo '<div class="col-span-1 md:col-span-2 p-12 text-center border border-dashed border-gray-200 rounded-2xl bg-gray-50 flex flex-col items-center justify-center">
                    <i class="fa-solid fa-book-open text-gray-300 text-3xl mb-3"></i>
                    <h3 class="text-gray-800 font-bold text-base mb-1">Aún no hay guías disponibles</h3>
                    <p class="text-sm text-gray-500 max-w-sm">Pronto el administrador agregará contenido vital para tu gestión operativa.</p>
                  </div>';
        }
        ?>
    </section>
</main>

<?php 
// 6. BOTTOM NAV Y MODALES
$nav_bottom_path = $app_dir . '/componentes/nav_bottom.php';
$modal_pub_path = $app_dir . '/componentes/modal_publicar.php';
$modal_exp_path = $app_dir . '/componentes/modal_explora.php';

if(file_exists($nav_bottom_path)) require_once $nav_bottom_path; 
if(file_exists($modal_pub_path)) require_once $modal_pub_path; 
if(file_exists($modal_exp_path)) require_once $modal_exp_path; 
?>

<script>
    // Failsafe Native Loader
    window.onload = () => { 
        const l = document.getElementById('loader'); 
        const b = document.body;
        if(b) b.classList.remove('fouc-lock');
        if(l){ l.style.opacity = '0'; setTimeout(() => l.style.display = 'none', 300); } 
    };

    // Acordeón Minimalista (Cerrado por defecto)
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

    // Lógica Optimistic UI para Feedback
    function enviarVoto(seccion, voto) {
        const container = document.getElementById('feedback-ui-' + seccion);
        if(!container) return;
        
        // Efecto visual inmediato para el usuario
        container.innerHTML = `<span class="text-[11px] font-bold text-gray-400 animate-fade-in-up"><i class="fa-solid fa-check text-green-500 mr-1"></i> Gracias por tu opinión</span>`;
        
        // Petición silenciosa a la API (Asumiendo ruta segura)
        fetch('/app/api/registrar_feedback_guia.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ seccion: seccion, voto: voto })
        }).catch(() => { /* Failsafe: se ignora el error en consola para no alertar al usuario */ });
    }

    // Integrador Oficial de Modales
    function setupModalNav(triggerId, modalId, cardId, closeId) {
        const btn = document.getElementById(triggerId);
        const modal = document.getElementById(modalId);
        const card = document.getElementById(cardId);
        const close = document.getElementById(closeId);
        
        if(!btn || !modal) return;
        const open = () => { 
            modal.classList.remove('hidden'); 
            requestAnimationFrame(() => card.classList.remove('translate-y-full','opacity-0')); 
            document.body.style.overflow = 'hidden'; 
        };
        const shut = () => { 
            card.classList.add('translate-y-full','opacity-0'); 
            setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); 
        };
        
        btn.onclick = (e) => { e.preventDefault(); open(); }; 
        if(close) close.onclick = shut; 
        modal.onclick = (e) => { if(e.target === modal) shut(); };
    }

    // Inicializaciones
    setupModalNav('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
    setupModalNav('btn-pub-guia', 'modal-quick', 'quick-card', 'quick-close'); 
    setupModalNav('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
</script>

</body>
</html>