/**
 * NUBIRA BRAIN v1.0
 * Sistema de rastreo de comportamiento para IA
 * Autor: Lead Dev Nubira
 */

document.addEventListener('DOMContentLoaded', () => {
    
    // CONFIGURACIÓN
    const API_ENDPOINT = '/app/api/registrar_evento.php';
    let tiempoInicio = Date.now();
    let haInteractuado = false;

    // A. RASTREADOR DE CLICS EN ELEMENTOS CLAVE
    // Busca cualquier elemento con data-track="algo"
    document.body.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-track]');
        if (trigger) {
            const evento = trigger.dataset.track; // Ej: 'contacto_whatsapp'
            const tipo = trigger.dataset.type || null; // Ej: 'servicio'
            const id = trigger.dataset.id || null; // Ej: 154
            
            enviarSeñal(evento, tipo, id);
            haInteractuado = true;
        }
    });

    // B. RASTREADOR DE BÚSQUEDAS
    const searchForm = document.querySelector('form[role="search"]'); // Asegúrate que tu form tenga este rol o una clase única
    if (searchForm) {
        searchForm.addEventListener('submit', (e) => {
            // No prevenimos el envío, solo registramos antes de irnos
            const input = searchForm.querySelector('input[name="q"]'); // O el nombre de tu input buscador
            if (input && input.value.length > 2) {
                enviarSeñal('search_query', null, null, { termino: input.value });
            }
        });
    }

    // C. RASTREADOR DE INTERÉS REAL (Tiempo en página > 10s)
    // Solo se dispara si el usuario está viendo un detalle de servicio o apunte
    const esDetalle = document.querySelector('.detalle-servicio') || document.querySelector('.detalle-apunte'); // Asegúrate de tener estas clases en tus vistas de detalle
    
    if (esDetalle) {
        window.addEventListener('beforeunload', () => {
            const segundos = Math.round((Date.now() - tiempoInicio) / 1000);
            
            // Si estuvo más de 10 segundos y menos de 30 minutos (evita pestañas olvidadas)
            if (segundos > 10 && segundos < 1800) {
                // Usamos sendBeacon porque funciona mejor al cerrar la pestaña
                const datos = new FormData();
                datos.append('evento', 'time_on_page');
                datos.append('tipo', document.querySelector('[data-tipo-entidad]')?.dataset.tipoEntidad || 'desconocido');
                datos.append('id', document.querySelector('[data-id-entidad]')?.dataset.idEntidad || 0);
                datos.append('metadata[segundos]', segundos);
                
                navigator.sendBeacon(API_ENDPOINT, datos);
            }
        });
    }

    // FUNCIÓN CORE DE ENVÍO
    function enviarSeñal(evento, tipo, id, meta = {}) {
        // En desarrollo puedes descomentar esto para ver qué pasa
        // console.log(`🧠 Nubira Brain: ${evento}`, { tipo, id, meta });

        fetch(API_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                evento: evento,
                tipo: tipo,
                id: id,
                metadata: meta
            })
        }).catch(err => {/* Fallo silencioso */});
    }
});