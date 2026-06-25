<?php
// app/componentes/head_common.php
// Tags PWA compartidos — incluir en el <head> de cada página DESPUÉS del <meta viewport>.
// NO incluye: <title>, <meta description>, canonical, OG tags (cada página los define).
if (defined('NUBIRA_HEAD_COMMON_LOADED')) return;
define('NUBIRA_HEAD_COMMON_LOADED', true);
?>
<link rel="manifest" href="/manifest.json">
<link rel="icon" type="image/png" href="/img/icon-192.png">
<link rel="apple-touch-icon" href="/img/icon-192.png">
<link rel="apple-touch-icon" sizes="192x192" href="/img/icon-192.png">
<link rel="apple-touch-icon" sizes="512x512" href="/img/icon-512.png">
<meta name="theme-color" content="#54A6D8">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Nubira">
<meta name="mobile-web-app-capable" content="yes">
<meta name="format-detection" content="telephone=no">
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/sw.js')
            .then(function(reg) { console.log('[SW] registrado, scope:', reg.scope); })
            .catch(function(err) { console.warn('[SW] fallo al registrar:', err); });
    });
}
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
