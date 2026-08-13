<?php
// app/componentes/head_common.php
// Tags PWA compartidos — incluir en el <head> de cada página DESPUÉS del <meta viewport>.
// NO incluye: <title>, <meta description>, canonical, OG tags (cada página los define).
if (defined('NUBIRA_HEAD_COMMON_LOADED')) return;
define('NUBIRA_HEAD_COMMON_LOADED', true);
?>
<!-- [META PIXEL] Base Code sitewide (Meta Ads). El stub de fbq() es síncrono e
     inmediato (definir una función y encolar una llamada no bloquea nada); solo
     se difiere la DESCARGA real de fbevents.js. Así fbq() ya existe para
     cualquier evento (ViewContent, Purchase, CompleteRegistration) que se
     dispare en la misma carga, sin depender del timing de requestIdleCallback. -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[]}(window, document);
fbq('init', '2149832959284130');
fbq('track', 'PageView');

var nbLoadFbq = function(){
  var t = document.createElement('script');
  t.async = true;
  t.src = 'https://connect.facebook.net/en_US/fbevents.js';
  var s = document.getElementsByTagName('script')[0];
  s.parentNode.insertBefore(t, s);
};
if ('requestIdleCallback' in window) requestIdleCallback(nbLoadFbq);
else setTimeout(nbLoadFbq, 0);
</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=2149832959284130&ev=PageView&noscript=1"/></noscript>
<!-- [Fix modal de avisos] Font Awesome centralizado acá — antes cada página decidía si
     cargarlo o no, y el modal de avisos (header.php) podía aparecer en páginas sin FA,
     dejando sus íconos invisibles. Con esto, toda página que incluya head_common.php
     tiene FA garantizado, sin depender de que cada una lo agregue por su cuenta. -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="manifest" href="/manifest.json">
<link rel="icon" type="image/png" href="/img/icon-192.png">
<link rel="apple-touch-icon" href="/img/icon-192.png">
<link rel="apple-touch-icon" sizes="192x192" href="/img/icon-192.png">
<link rel="apple-touch-icon" sizes="512x512" href="/img/icon-512.png">
<!-- [PWA] Splash screens iOS (apple-touch-startup-image) -->
<link rel="apple-touch-startup-image" href="/img/splash/apple-splash-solid.png" media="(device-width: 375px) and (device-height: 667px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="/img/splash/apple-splash-solid.png" media="(device-width: 414px) and (device-height: 736px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="/img/splash/apple-splash-solid.png" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="/img/splash/apple-splash-solid.png" media="(device-width: 375px) and (device-height: 812px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="/img/splash/apple-splash-solid.png" media="(device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="/img/splash/apple-splash-solid.png" media="(device-width: 393px) and (device-height: 852px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="/img/splash/apple-splash-solid.png" media="(device-width: 428px) and (device-height: 926px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="/img/splash/apple-splash-solid.png" media="(device-width: 430px) and (device-height: 932px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
<meta name="theme-color" content="#54A6D8">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Nubira">
<meta name="mobile-web-app-capable" content="yes">
<meta name="format-detection" content="telephone=no">
<!-- [Fix zoom automático iOS] Safari hace zoom automático al enfocar un campo de
     formulario con font-size < 16px. En vez de corregir clase por clase en decenas
     de archivos (auditado: 46 páginas con al menos un campo en 14px), se fuerza acá
     una sola vez, solo en el rango de ancho móvil donde el zoom realmente ocurre. -->
<style>
@media (max-width: 767px) {
    input, select, textarea { font-size: 16px !important; }
}
</style>
<script type="application/ld+json">
<?php
echo json_encode([
    '@context'    => 'https://schema.org',
    '@type'       => 'Organization',
    'name'        => 'Nubira',
    'alternateName' => 'Nubira.cl',
    'url'         => 'https://nubira.cl',
    'logo'        => 'https://nubira.cl/img/logo.webp',
    'sameAs'      => [
        'https://instagram.com/nubira.cl',
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
?>
</script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/sw.js')
            .then(function(reg) { console.log('[SW] registrado, scope:', reg.scope); })
            .catch(function(err) { console.warn('[SW] fallo al registrar:', err); });
    });
}
if ('clearAppBadge' in navigator) navigator.clearAppBadge().catch(() => {});
</script>
<?php if (!empty($_SESSION['usuario_id'])): ?>
<script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
<script>
window.OneSignalDeferred = window.OneSignalDeferred || [];
OneSignalDeferred.push(async function(OneSignal) {
    await OneSignal.init({ appId: "ae684576-e9b6-491e-a7e0-e2033e423ea4", serviceWorkerPath: "/sw.js" });
    await OneSignal.login("<?= (int)$_SESSION['usuario_id'] ?>");
});
</script>
<?php endif; ?>
<?php if (!empty($_SESSION['usuario_id'])): ?>
<script>
// [NUBIRA 2.0] Poller centralizado de alertas — reemplaza los 3 timers
// independientes de header.php/sidebar.php/nav_bottom.php por 1 solo fetch,
// difundido como evento custom a quien esté escuchando en la página.
function pollAlertasNubira() {
    if (document.hidden) return;
    fetch('/app/contar_alertas_sistema.php?v=' + Date.now())
        .then(res => res.json())
        .then(data => window.dispatchEvent(new CustomEvent('nubira:alertas', { detail: data })))
        .catch(() => {});
}
const scheduleIdle = window.requestIdleCallback || ((cb) => setTimeout(cb, 600));
document.addEventListener('DOMContentLoaded', () => scheduleIdle(pollAlertasNubira));
setInterval(pollAlertasNubira, 45000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) pollAlertasNubira(); });
</script>
<?php endif; ?>
