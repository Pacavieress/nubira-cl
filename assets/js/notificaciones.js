console.log('Notificaciones globales cargadas');

async function actualizarNotificacionesGlobales() {
    try {
        const res = await fetch('/app/api_notificaciones_sidebar.php?t=' + Date.now(), {
            credentials: 'include'
        });

        if (!res.ok) return;

        const data = await res.json();

        // Mapa lógico → múltiples destinos
        const destinos = {
            chats: ['badge-chats-panel', 'badge-chats-sidebar'],
            mis_ventas: ['badge-mis-ventas'],
            mis_reclamos: ['badge-reclamos-user'],
            soporte_user: ['badge-soporte-user']
        };

        for (const key in destinos) {
            const cantidad = parseInt(data[key]) || 0;

            destinos[key].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;

                el.textContent = cantidad > 99 ? '99+' : cantidad;
                el.style.display = cantidad > 0 ? 'flex' : 'none';
            });
        }

    } catch (e) {
        console.error('Error notificaciones:', e);
    }
}

(function iniciarNotificaciones() {
    actualizarNotificacionesGlobales();
    setInterval(actualizarNotificacionesGlobales, 5000);
})();
