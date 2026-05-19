/* ============================================
   NUBIRA CORE JS
   Funciones comunes para toda la plataforma
   ============================================ */


/* -------------------------
   1. LOADER
-------------------------- */
window.onload = () => {
    const l = document.getElementById('loader');
    if (l) {
        l.classList.add('opacity-0');
        setTimeout(() => l.classList.add('hidden'), 300);
    }
};


/* -------------------------
   2. SISTEMA DE MODALES
-------------------------- */

function setupModal(triggerId, modalId, cardId, closeId) {

    const btn = document.getElementById(triggerId);
    const modal = document.getElementById(modalId);
    const card = document.getElementById(cardId);
    const close = document.getElementById(closeId);

    if (!btn || !modal || !card || !close) return;

    const open = () => {
        modal.classList.remove('hidden');

        requestAnimationFrame(() => {
            card.classList.remove('translate-y-full', 'opacity-0');
        });

        document.body.style.overflow = 'hidden';
    };

    const shut = () => {
        card.classList.add('translate-y-full', 'opacity-0');

        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }, 300);
    };

    btn.onclick = (e) => {
        e.preventDefault();
        open();
    };

    close.onclick = shut;

    modal.onclick = (e) => {
        if (e.target === modal) shut();
    };
}


/* -------------------------
   3. LAZY HYDRATION DE CARRUSELES
-------------------------- */

async function hydrateHTML(container) {
    const src = container.getAttribute('data-src');
    if (!src) return;

    try {
        const res = await fetch(src);
        if (!res.ok) throw new Error();
        const html = await res.text();
        if (html.trim()) container.innerHTML = html;
    } catch (e) {
        console.error("Error cargando sección:", e);
    }
}

document.addEventListener('DOMContentLoaded', () => {

    const ids = ['sec-apuntes', 'sec-servicios'];

    const obs = new IntersectionObserver((entries) => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                hydrateHTML(e.target);
                obs.unobserve(e.target);
            }
        });
    }, { rootMargin: '200px' });

    ids.forEach(id => {
        const el = document.getElementById(id);
        if (el) obs.observe(el);
    });

    actualizarBadgeChats();
    setInterval(actualizarBadgeChats, 10000);
});


/* -------------------------
   4. SCROLL SUAVE DE CARRUSELES
-------------------------- */

function scrollCarrusel(id, dir) {
    const c = document.getElementById(id);
    if (c) c.scrollBy({ left: dir * 300, behavior: 'smooth' });
}


/* -------------------------
   5. BADGE DE CHATS (CUENTAS)
-------------------------- */

async function actualizarBadgeChats() {

    try {
        const res = await fetch('/app/contar_mensajes_nuevos.php');
        const data = await res.json();
        const total = parseInt(data.total || 0);

        const ids = ['badge-chats-sidebar', 'badge-chats-bottom'];

        ids.forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;

            if (id === 'badge-chats-sidebar')
                el.innerText = total;

            total > 0
                ? el.classList.remove('hidden')
                : el.classList.add('hidden');
        });

    } catch (err) {
        console.error("Error actualizando badge chats:", err);
    }
}


/* -------------------------
   6. ABRIR MIS CHATS (POPUP WINDOW)
-------------------------- */

function abrirMisChats() {
    window.open(
        "/app/mis_chats.php",
        "mis_chats",
        "width=440,height=640,resizable=yes,scrollbars=yes"
    );
}


/* -------------------------
   7. POPUP DE BIENVENIDA
-------------------------- */

const popBienvenida = document.getElementById('popup-bienvenida');

if (popBienvenida) {

    const card = document.getElementById('bienvenida-card');
    const close = document.getElementById('bienvenida-close');
    const irPublicar = document.getElementById('btn-ir-publicar');
    const btnPublicar = document.getElementById('btn-publicar');

    popBienvenida.style.display = 'flex';

    setTimeout(() => {
        card.classList.remove('opacity-0', 'scale-95', 'translate-y-6');
    }, 100);

    close.onclick = () => popBienvenida.style.display = 'none';

    irPublicar.onclick = (e) => {
        e.preventDefault();
        popBienvenida.style.display = 'none';
        if (btnPublicar) btnPublicar.click();
    };
}
