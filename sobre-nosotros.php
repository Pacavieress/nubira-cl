<?php
session_start();

// 1. CARGA SEGURA DE CONEXIÓN
$app_dir = file_exists(__DIR__ . '/conexion.php') ? __DIR__ : __DIR__ . '/app';
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

$is_guest = !isset($_SESSION['usuario_id']);
$nombre_usuario = $_SESSION['usuario_nombre'] ?? 'Estudiante';
$page_title = "Sobre Nubira";

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
  <?php require_once __DIR__ . '/app/helpers/seo.php'; echo nubira_seo_meta('Sobre Nubira | Plataforma universitaria chilena', 'Nubira nace de estudiantes para estudiantes. Marketplace chileno de tutorías y apuntes con pago protegido y verificación institucional.'); ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php require_once __DIR__ . '/app/helpers/seo.php'; echo nubira_canonical_tag(); ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <!-- [NUBIRA 2.0] Fuente Inter — carga directa, mismo patrón que vitrina.php -->
  <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
  <style>
    body { font-family: 'Inter', sans-serif; background-color: #ffffff; }

    /* Animación sutil de entrada para los bloques */
    @keyframes slideUp {
      from { opacity: 0; transform: translateY(12px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .reveal { animation: slideUp 0.7s ease-out forwards; }
    .reveal-delay-1 { animation-delay: 0.1s; opacity: 0; }
    .reveal-delay-2 { animation-delay: 0.2s; opacity: 0; }
    .reveal-delay-3 { animation-delay: 0.3s; opacity: 0; }

    /* Cita con barra lateral */
    .quote-block {
        border-left: 3px solid #54A6D8;
        padding-left: 1.25rem;
    }

    /* Lista de problemas con tachado sutil */
    .problem-item {
        position: relative;
        padding-left: 1.75rem;
    }
    .problem-item::before {
        content: "✕";
        position: absolute;
        left: 0;
        top: 0;
        color: #ef4444;
        font-weight: 700;
        font-size: 0.9rem;
    }

    /* Lista de soluciones con check */
    .solution-item {
        position: relative;
        padding-left: 1.75rem;
    }
    .solution-item::before {
        content: "✓";
        position: absolute;
        left: 0;
        top: 0;
        color: #10b981;
        font-weight: 700;
        font-size: 1rem;
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

<main class="flex-grow pt-20 md:pt-24 pb-28 md:pb-16 w-full lg:ml-64 transition-all duration-300 max-w-[1600px] mx-auto">

  <article class="w-full max-w-2xl mx-auto px-5 md:px-8">

    <!-- HERO PERSONAL -->
    <header class="mb-16 md:mb-20 reveal">
      <span class="inline-block py-1 px-3 rounded-full bg-gray-100 text-gray-600 text-[10px] font-bold uppercase tracking-widest mb-6">
        Una nota del fundador
      </span>
      <h1 class="text-3xl md:text-5xl font-extrabold text-[#222222] mb-6 tracking-[-0.01em] leading-[1.1]">
        Hola. Soy Pablo,<br>
        y esto es <span class="text-[#54A6D8]">Nubira</span>.
      </h1>
      <p class="text-lg text-gray-600 leading-relaxed">
        No es una startup financiada por un fondo. No tiene un equipo de cincuenta personas detrás. Hoy es una sola persona construyéndolo, desde adentro de una universidad chilena, intentando resolver algo que viví antes de que se me ocurriera la idea.
      </p>
    </header>

    <!-- EL PROBLEMA -->
    <section class="mb-16 md:mb-20 reveal reveal-delay-1">
      <p class="text-xs font-bold uppercase tracking-widest text-[#54A6D8] mb-4">El problema</p>
      <h2 class="text-2xl md:text-3xl font-bold text-[#222222] mb-6 tracking-[-0.01em] leading-tight">
        Estudiar en Chile no debería depender de la suerte.
      </h2>
      <p class="text-base text-gray-600 leading-relaxed mb-6">
        Si has buscado un tutor o un apunte para tu próxima prueba, ya conoces el camino:
      </p>

      <ul class="space-y-3 text-sm md:text-base text-gray-700 mb-8">
        <li class="problem-item">Grupos de Facebook llenos de PDFs basura que no se abren.</li>
        <li class="problem-item">Tutores en Instagram que cobran por adelantado y desaparecen.</li>
        <li class="problem-item">Drives compartidos que llevan meses muertos.</li>
        <li class="problem-item">Mensajes leídos sin respuesta, justo el día antes de la prueba.</li>
      </ul>

      <p class="text-base text-gray-600 leading-relaxed">
        Lo viví. Lo vieron mis compañeros. Lo siguen viviendo miles de estudiantes cada semestre. Es un sistema improvisado donde aprobar o reprobar depende de a qué grupo de WhatsApp llegaste primero.
      </p>
    </section>

    <!-- LA SOLUCIÓN -->
    <section class="mb-16 md:mb-20 reveal reveal-delay-2">
      <p class="text-xs font-bold uppercase tracking-widest text-[#54A6D8] mb-4">Lo que estoy construyendo</p>
      <h2 class="text-2xl md:text-3xl font-bold text-[#222222] mb-6 tracking-[-0.01em] leading-tight">
        Un solo lugar. Sin estafas. Sin perseguir a nadie.
      </h2>

      <p class="text-base text-gray-600 leading-relaxed mb-6">
        Nubira reúne en un mismo lugar lo que hoy está roto y disperso:
      </p>

      <ul class="space-y-3 text-sm md:text-base text-gray-700 mb-8">
        <li class="solution-item">El alumno paga una vez, la plataforma protege el dinero hasta que la clase ocurre.</li>
        <li class="solution-item">El tutor se profesionaliza: agenda, perfil, reseñas reales.</li>
        <li class="solution-item">Los apuntes se descargan al instante, no hay que rogar acceso.</li>
        <li class="solution-item">Todo dentro de la misma plataforma. Sin sacarte a Instagram, sin pedir tu WhatsApp.</li>
      </ul>

      <div class="quote-block">
        <p class="text-base md:text-lg text-gray-800 leading-relaxed italic">
          Nubira no nace en una oficina con post-its. Nace desde adentro de una universidad chilena, hecha por alguien que vive cada día los mismos problemas que intenta resolver.
        </p>
      </div>
    </section>

    <!-- PRINCIPIOS -->
    <section class="mb-16 md:mb-20 reveal reveal-delay-3">
      <p class="text-xs font-bold uppercase tracking-widest text-[#54A6D8] mb-4">Los principios</p>
      <h2 class="text-2xl md:text-3xl font-bold text-[#222222] mb-8 tracking-[-0.01em] leading-tight">
        Tres reglas que no se negocian.
      </h2>

      <div class="space-y-8">
        <div>
          <div class="flex items-baseline gap-3 mb-2">
            <span class="text-3xl font-extrabold text-gray-200 leading-none">01</span>
            <h4 class="text-lg font-bold text-gray-900">El estudiante primero, siempre.</h4>
          </div>
          <p class="text-sm md:text-base text-gray-600 leading-relaxed pl-12">
            Cada decisión de producto se toma pensando en si protege al alumno. Si una función ayuda a vender pero perjudica al que paga, no entra.
          </p>
        </div>

        <div>
          <div class="flex items-baseline gap-3 mb-2">
            <span class="text-3xl font-extrabold text-gray-200 leading-none">02</span>
            <h4 class="text-lg font-bold text-gray-900">Sin trampas, sin letra chica.</h4>
          </div>
          <p class="text-sm md:text-base text-gray-600 leading-relaxed pl-12">
            La comisión es transparente. El precio del tutor es el que ves. No hay cobros sorpresa, ni planes premium escondidos.
          </p>
        </div>

        <div>
          <div class="flex items-baseline gap-3 mb-2">
            <span class="text-3xl font-extrabold text-gray-200 leading-none">03</span>
            <h4 class="text-lg font-bold text-gray-900">Construido lento, hecho bien.</h4>
          </div>
          <p class="text-sm md:text-base text-gray-600 leading-relaxed pl-12">
            Prefiero soltar una cosa que funcione bien antes que diez funciones a medias. Cada actualización se piensa con calma.
          </p>
        </div>
      </div>
    </section>

    <!-- EL EQUIPO REAL -->
    <section class="mb-16 md:mb-20 reveal reveal-delay-3">
      <p class="text-xs font-bold uppercase tracking-widest text-[#54A6D8] mb-4">El equipo</p>
      <h2 class="text-2xl md:text-3xl font-bold text-[#222222] mb-8 tracking-[-0.01em] leading-tight">
        Hoy somos uno. Mañana, los que se sumen.
      </h2>

      <div class="bg-gray-50 border border-gray-100 rounded-3xl p-8 md:p-10 flex flex-col md:flex-row items-center gap-6 md:gap-8">
        <img src="/img/team_pablo.webp"
             alt="Pablo, fundador de Nubira"
             class="w-24 h-24 md:w-28 md:h-28 rounded-full object-cover border-4 border-white shadow-md flex-shrink-0"
             onerror="this.src='/img/default_avatar.webp'">
        <div class="text-center md:text-left">
          <h4 class="text-xl font-bold text-gray-900 mb-1">Pablo</h4>
          <p class="text-xs text-[#54A6D8] font-bold uppercase tracking-widest mb-3">Fundador · Producto · Soporte · Todo lo demás</p>
          <p class="text-sm text-gray-600 leading-relaxed">
            Diseño la plataforma, escribo el código, modero los apuntes y respondo los correos. Si algo no funciona, soy yo. Si algo te ayudó, también.
          </p>
        </div>
      </div>
    </section>

    <!-- CTA HONESTO -->
    <section class="reveal reveal-delay-3">
      <?php if ($is_guest): ?>
      <div class="border-t border-gray-100 pt-10 md:pt-12 text-center">
        <h3 class="text-2xl md:text-3xl font-bold text-gray-900 mb-3 tracking-tight">
          Si te suena, súmate.
        </h3>
        <p class="text-sm md:text-base text-gray-500 mb-7 max-w-md mx-auto leading-relaxed">
          Estoy construyendo esto público y lento. Si decides usarlo, eres parte del proceso.
        </p>
        <a href="/register" class="inline-block bg-[#54A6D8] text-white font-bold px-8 py-3.5 rounded-xl hover:bg-blue-600 transition-all shadow-sm hover:shadow-md hover:-translate-y-0.5 text-sm md:text-base">
          Crear mi cuenta
        </a>
        <p class="text-[11px] text-gray-400 mt-4 tracking-wide">
          Gratis · Sin tarjeta · Sin compromiso
        </p>
      </div>
      <?php else: ?>
      <div class="border-t border-gray-100 pt-10 md:pt-12">
        <p class="text-center text-sm md:text-base text-gray-600 leading-relaxed max-w-md mx-auto">
          Gracias por estar acá, <?= htmlspecialchars(explode(' ', $nombre_usuario)[0]) ?>. Esta plataforma se construye con tu uso. Si encuentras algo que arreglar, dímelo.
        </p>
      </div>
      <?php endif; ?>
    </section>

  </article>

  <?php
  if (file_exists($app_dir . '/componentes/footer_minimal.php')) {
      require_once $app_dir . '/componentes/footer_minimal.php';
  } elseif (file_exists(__DIR__ . '/app/componentes/footer_minimal.php')) {
      require_once __DIR__ . '/app/componentes/footer_minimal.php';
  }
  ?>
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
    // Configuración de Modales (Incluye intercepción de Lazy Registration)
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

        // Sistema de badges (Solo si el usuario está logueado)
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