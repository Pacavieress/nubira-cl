/**
 * NUBIRA 2.0 - BEHAVIOR TRACKER (Sensor Evolucionado)
 * Mide el interés real del usuario y alimenta el Algoritmo de Recomendación
 */
document.addEventListener('DOMContentLoaded', () => {
    let startTime = Date.now();
    let dataEnviada = false; // Evitar envíos duplicados al cerrar pestaña
    
    // Estado inicial de métricas
    let metrics = {
        scroll_depth: 0,
        clicks_galeria: 0,
        click_vendedor: 0,
        vio_precio: 0,
        intencion_compra: 0, // Clic en Contratar
        intencion_contacto: 0 // Clic en Contactar
    };

    // Obtenemos datos del body (inyectados por PHP)
    const entidadId = document.body.getAttribute('data-id');
    const entidadTipo = document.body.getAttribute('data-tipo');
    
    if (!entidadId) return;

    // [NUBIRA 2.0] Extraemos la categoría inyectada desde PHP
    const categoriaServicio = document.body.getAttribute('data-categoria') || 'General';

    // 1. SENSOR DE SCROLL (Profundidad de lectura)
    window.addEventListener('scroll', () => {
        let scrollTop = window.scrollY;
        let docHeight = document.body.scrollHeight - window.innerHeight;
        if (docHeight <= 0) return;
        
        let currentPercent = Math.round((scrollTop / docHeight) * 100);
        if (currentPercent > metrics.scroll_depth) {
            metrics.scroll_depth = currentPercent;
        }
    });

    // 2. SENSOR DE PRECIO (Intersection Observer)
    const priceBlock = document.getElementById('precio-block');
    if (priceBlock) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(e => {
                if (e.isIntersecting) {
                    metrics.vio_precio = 1;
                    observer.disconnect(); 
                }
            });
        });
        observer.observe(priceBlock);
    }

    // 3. SENSOR DE INTERACCIÓN (Delegación de eventos)
    document.body.addEventListener('click', (e) => {
        if (e.target.closest('.track-gallery')) metrics.clicks_galeria++;
        if (e.target.closest('.track-seller')) metrics.click_vendedor = 1;
        
        // [NUBIRA 2.0] High-Value Interactions (Vale muchos puntos para el algoritmo)
        if (e.target.closest('#btn-submit-pago')) metrics.intencion_compra = 1;
        if (e.target.closest('#form-contactar button')) metrics.intencion_contacto = 1;
    });

    // 4. ENVÍO DE DATOS (Al salir)
    const sendData = () => {
        if (dataEnviada) return; // Evitar disparar dos veces si se cruzan visibilitychange y pagehide
        
        const duration = Math.round((Date.now() - startTime) / 1000);
        
        // Filtro anti-rebote: Solo enviamos si estuvo al menos 3 segundos (para limpiar datos basura)
        if (duration >= 3) {
            const data = new FormData();
            data.append('id', entidadId);
            data.append('tipo', entidadTipo);
            data.append('categoria', categoriaServicio); // [NUEVO] Crucial para recomendar
            data.append('duracion', duration);
            data.append('vio_precio', metrics.vio_precio);
            data.append('scroll_depth', metrics.scroll_depth);
            data.append('clicks_galeria', metrics.clicks_galeria);
            data.append('click_vendedor', metrics.click_vendedor);
            data.append('intencion_compra', metrics.intencion_compra); // [NUEVO]
            data.append('intencion_contacto', metrics.intencion_contacto); // [NUEVO]
            data.append('evento', 'view_exit'); 

            // Si hay token CSRF en la página (como en perfil.php), lo añadimos
            if (typeof CSRF_TOKEN !== 'undefined') {
                data.append('csrf_token', CSRF_TOKEN);
            }

            // RUTA DEL CEREBRO DE RECOMENDACIONES
            const url = '/app/api/registrar_evento.php';
            
            if (navigator.sendBeacon) {
                navigator.sendBeacon(url, data);
            } else {
                fetch(url, { method: 'POST', body: data, keepalive: true }).catch(()=>{});
            }
            dataEnviada = true;
        }
    };

    // Disparadores de envío (Optimizados para Mobile y Desktop)
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') sendData();
    });
    window.addEventListener('pagehide', sendData); 
    window.addEventListener('beforeunload', sendData); // Cobertura extra para algunos navegadores Desktop
});