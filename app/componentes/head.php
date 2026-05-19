<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<meta name="theme-color" content="#ffffff" />

<link rel="icon" type="image/webp" href="/img/logo2.webp">

<script src="https://cdn.tailwindcss.com"></script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<script>
  !function(f,b,e,v,n,t,s)
  {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};
  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
  n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];
  s.parentNode.insertBefore(t,s)}(window, document,'script',
  'https://connect.facebook.net/en_US/fbevents.js');
  
  // INICIALIZACIÓN
  fbq('init', '949858788026352'); 
  fbq('track', 'PageView');
</script>
<noscript>
  <img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=949858788026352&ev=PageView&noscript=1" />
</noscript>
<style>
    :root {
        --nubira: #54A6D8;
    }

    body {
        font-family: 'Inter', sans-serif;
        background-color: #ffffff;
        color: #1f2937;
    }

    .text-nubira { color: var(--nubira); }
    .bg-nubira { background-color: var(--nubira); }

    /* Eliminar scrollbars en carruseles */
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

    /* Safe Area para móviles */
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }

    /* Loader */
    #loader {
        transition: opacity 0.3s ease;
    }

    /* Carrusel compacto */
    .compact-root { align-items: stretch; }
    .compact-root > * {
        flex: 0 0 auto !important;
        width: 260px !important; 
        max-width: 260px !important;
        transition: transform 0.3s ease;
    }

    @media (max-width: 640px) {
        .compact-root > * {
            width: 150px !important;
            max-width: 150px !important;
        }
    }

    .compact-root > *:hover {
        transform: translateY(-3px);
    }
</style>

<div id="loader" class="fixed inset-0 bg-white/95 flex items-center justify-center z-[60]">
    <div class="flex flex-col items-center">
        <div class="animate-spin h-10 w-10 border-4 border-blue-200 border-t-[var(--nubira)] rounded-full"></div>
        <p class="mt-4 text-nubira font-bold text-sm tracking-wide">NUBIRA.CL</p>
    </div>
</div>