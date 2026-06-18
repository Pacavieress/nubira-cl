<?php
session_start();

// 1. CARGA SEGURA DE CONEXIÓN
$app_dir = file_exists(__DIR__ . '/conexion.php') ? __DIR__ : __DIR__ . '/app';
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

$is_guest = !isset($_SESSION['usuario_id']);
$nombre_usuario = $_SESSION['usuario_nombre'] ?? 'Estudiante';
$page_title = "Términos y Condiciones";

// UX NUBIRA 2.0: MODO LECTURA (Limpia el Header de botones que distraen)
$ocultar_buscador = true;
$ocultar_botones_publicar = true;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php require_once __DIR__ . '/app/componentes/head_common.php'; ?>
  <?php require_once __DIR__ . '/app/helpers/seo.php'; echo nubira_seo_meta('Términos y Condiciones | Nubira', 'Términos de uso de Nubira: reglas de la plataforma, derechos y responsabilidades de usuarios, tutores y compradores.'); ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php require_once __DIR__ . '/app/helpers/seo.php'; echo nubira_canonical_tag(); ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #ffffff; }
    
    /* Patrón de fondo sutil */
    .bg-pattern {
        background-image: radial-gradient(#54A6D8 0.5px, transparent 0.5px), radial-gradient(#54A6D8 0.5px, #ffffff 0.5px);
        background-size: 20px 20px;
        background-position: 0 0, 10px 10px;
        opacity: 0.1;
    }
  </style>
</head>
<body class="bg-white text-gray-900 antialiased overflow-x-hidden flex flex-col min-h-screen">

<?php 
if (file_exists($app_dir . '/componentes/header.php')) {
    require_once $app_dir . '/componentes/header.php'; 
}
if (file_exists($app_dir . '/componentes/sidebar.php')) {
    require_once $app_dir . '/componentes/sidebar.php'; 
}
?>

<main class="flex-grow pt-24 pb-28 md:pb-16 w-full relative md:ml-64 transition-all duration-300 max-w-[1600px] mx-auto">
  
  <div class="absolute inset-0 pointer-events-none -z-10 bg-pattern"></div>

  <div class="w-full max-w-4xl mx-auto px-4 md:px-8 mt-6">
    
    <div class="text-center mb-10 border-b border-gray-100 pb-8 animate__animated animate__fadeIn">
      <span class="inline-block py-1.5 px-4 rounded-full bg-blue-50 text-[#54A6D8] text-xs font-bold uppercase tracking-wider mb-4 border border-blue-100 shadow-sm">
        Documento Legal
      </span>
      <h1 class="text-3xl md:text-5xl font-extrabold text-gray-900 mb-4 tracking-tight leading-tight">
        Términos y Condiciones
      </h1>
      <p class="text-gray-500 font-medium">Última actualización: <?= date('d/m/Y') ?></p>
    </div>

    <div class="bg-white p-8 md:p-12 rounded-[2.5rem] shadow-sm border border-gray-100 mb-12">
        <div class="space-y-10 text-gray-700 leading-relaxed">
            
            <section>
                <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-3">
                    <span class="bg-blue-50 w-10 h-10 rounded-xl flex items-center justify-center text-lg text-[#54A6D8]">1</span>
                    ¿Qué es Nubira?
                </h2>
                <p class="ml-1 md:ml-13 text-gray-600">
                    Nubira es una plataforma universitaria digital diseñada para conectar estudiantes, compartir apuntes, encontrar oportunidades y ofrecer servicios académicos. Al registrarte o utilizar nuestros servicios, aceptas cumplir con estos términos.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-3">
                    <span class="bg-blue-50 w-10 h-10 rounded-xl flex items-center justify-center text-lg text-[#54A6D8]">2</span>
                    Usuarios Permitidos
                </h2>
                <ul class="list-disc pl-5 md:pl-16 space-y-2 text-gray-600 marker:text-[#54A6D8]">
                    <li>Estudiantes, egresados y personal académico con correo institucional válido.</li>
                    <li>Mayores de 14 años o con autorización de apoderados.</li>
                    <li>Las cuentas son personales e intransferibles.</li>
                </ul>
            </section>

            <section>
                <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-3">
                    <span class="bg-blue-50 w-10 h-10 rounded-xl flex items-center justify-center text-lg text-[#54A6D8]">3</span>
                    Uso Correcto
                </h2>
                <p class="mb-3 ml-1 md:ml-13 text-gray-600">Nos tomamos muy en serio la calidad de la comunidad. Está estrictamente prohibido:</p>
                <ul class="list-disc pl-5 md:pl-16 space-y-2 text-gray-600 marker:text-[#54A6D8]">
                    <li>Publicar material ilegal, ofensivo, spam o información falsa.</li>
                    <li>Vulnerar derechos de autor o propiedad intelectual.</li>
                    <li>Utilizar la plataforma para fines comerciales ajenos a la educación sin permiso.</li>
                </ul>
            </section>

            <section>
                <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-3">
                    <span class="bg-blue-50 w-10 h-10 rounded-xl flex items-center justify-center text-lg text-[#54A6D8]">4</span>
                    Transacciones
                </h2>
                <p class="ml-1 md:ml-13 text-gray-600">
                    Nubira facilita la conexión entre compradores y vendedores. Los pagos se procesan de forma segura. Nubira actúa como intermediario para asegurar la entrega del servicio o archivo, pero la responsabilidad final de la calidad recae en el usuario vendedor.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-3">
                    <span class="bg-blue-50 w-10 h-10 rounded-xl flex items-center justify-center text-lg text-[#54A6D8]">5</span>
                    Contacto
                </h2>
                <p class="ml-1 md:ml-13 text-gray-600">
                    Si tienes dudas sobre estos términos, escríbenos directamente a <a href="mailto:contacto@nubira.cl" class="text-[#54A6D8] font-semibold hover:underline">contacto@nubira.cl</a>.
                </p>
            </section>

        </div>

        <div class="mt-12 pt-8 border-t border-gray-100 text-center">
            <a href="/explorar" class="inline-flex items-center gap-2 bg-gray-900 text-white font-bold px-8 py-3 rounded-xl hover:bg-gray-800 transition shadow-md hover:-translate-y-0.5">
                Volver al inicio
            </a>
        </div>
    </div>

    <?php 
    if (file_exists($app_dir . '/componentes/footer_minimal.php')) {
        require_once $app_dir . '/componentes/footer_minimal.php';
    } elseif (file_exists(__DIR__ . '/app/componentes/footer_minimal.php')) {
        require_once __DIR__ . '/app/componentes/footer_minimal.php';
    }
    ?>

  </div>
</main>

<?php 
if (file_exists($app_dir . '/componentes/nav_bottom.php')) {
    require_once $app_dir . '/componentes/nav_bottom.php';
}
if (file_exists($app_dir . '/componentes/modal_publicar.php')) {
    require_once $app_dir . '/componentes/modal_publicar.php';
}
if (file_exists($app_dir . '/componentes/modal_explora.php')) {
    require_once $app_dir . '/componentes/modal_explora.php';
}
?>

<script>
    function setupModal(triggerId, modalId, cardId, closeId) {
        const btn = document.getElementById(triggerId);
        const modal = document.getElementById(modalId);
        const card = document.getElementById(cardId);
        const close = document.getElementById(closeId);

        if(!btn || !modal) return;

        const open = () => { 
            modal.classList.remove('hidden'); 
            requestAnimationFrame(() => { card.classList.remove('translate-y-full', 'opacity-0'); });
            document.body.style.overflow = 'hidden'; 
        };

        const shut = () => { 
            card.classList.add('translate-y-full', 'opacity-0'); 
            setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); 
        };

        btn.onclick = (e) => { 
            e.preventDefault(); 
            <?php if ($is_guest): ?>
                if (triggerId === 'btn-publicar' || triggerId === 'btn-explora-publicar') {
                    window.location.href = '/login?redir=' + encodeURIComponent(window.location.pathname + window.location.search);
                    return;
                }
            <?php endif; ?>
            open(); 
        };
        
        if(close) close.onclick = shut; 
        modal.onclick = (e) => { if(e.target === modal) shut(); };
    }

    document.addEventListener('DOMContentLoaded', () => {
        setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
        setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');

        <?php if (!$is_guest): ?>
        async function actualizarBadgeChats() {
            try {
                const res = await fetch('/app/contar_mensajes_nuevos.php');
                const data = await res.json();
                const total = parseInt(data.total || 0);
                ['badge-chats-sidebar', 'badge-chats-bottom'].forEach(id => {
                    const el = document.getElementById(id);
                    if(el) { 
                        if(id.includes('sidebar')) el.innerText = total;
                        total > 0 ? el.classList.remove('hidden') : el.classList.add('hidden'); 
                    }
                });
            } catch(e) {}
        }
        actualizarBadgeChats();
        setInterval(actualizarBadgeChats, 10000);
        <?php endif; ?>
    });
</script>

</body>
</html>