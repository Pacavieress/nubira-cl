function setupModal(triggerId, modalId, cardId, closeId) {
    const btn = document.getElementById(triggerId),
          modal = document.getElementById(modalId),
          card = document.getElementById(cardId),
          close = document.getElementById(closeId);

    if(!btn || !modal) return;

    const open = () => {
        modal.classList.remove('hidden');
        requestAnimationFrame(() => {
            card.classList.remove('translate-y-full','opacity-0');
        });
        document.body.style.overflow = 'hidden';
    };

    const shut = () => {
        card.classList.add('translate-y-full','opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }, 300);
    };

    btn.onclick = e => { e.preventDefault(); open(); };
    close.onclick = shut;
    modal.onclick = e => { if(e.target === modal) shut(); };
}

document.addEventListener('DOMContentLoaded', () => {
    setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
    setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
});
