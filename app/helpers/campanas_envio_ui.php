<?php
/**
 * UI compartida para paneles de campañas de envío masivo.
 * Requiere en la página: #check-all, .row-check, #action-bar, #bar-count, #bar-plural, #btn-enviar, #toast.
 */
?>
<script>
function mostrarToast(msg, tipo = 'ok') {
    const t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.className = 'fixed bottom-24 right-6 px-5 py-3 rounded-xl shadow-xl text-white z-[90] text-sm font-bold transition-all duration-300 '
        + (tipo === 'ok' ? 'bg-green-600' : 'bg-red-600');
    t.classList.remove('hidden');
    setTimeout(() => t.classList.add('hidden'), 4000);
}

function initSeleccionMasiva({ maxLote = Infinity } = {}) {
    const checkAll  = document.getElementById('check-all');
    const rowChecks = [...document.querySelectorAll('.row-check')];
    const actionBar = document.getElementById('action-bar');
    const barCount  = document.getElementById('bar-count');
    const barPlural = document.getElementById('bar-plural');
    const btnEnviar = document.getElementById('btn-enviar');

    function syncBar() {
        const n = rowChecks.filter(c => c.checked).length;
        barCount.textContent = n;
        barPlural.textContent = n === 1 ? '' : 's';
        btnEnviar.disabled = n === 0;
        actionBar.classList.toggle('translate-y-full', n === 0);
        actionBar.classList.toggle('translate-y-0',    n > 0);
    }

    checkAll?.addEventListener('change', () => {
        const seleccionables = rowChecks.filter(cb => !cb.disabled);
        if (checkAll.checked) {
            let marcados = 0;
            seleccionables.forEach(cb => {
                cb.checked = marcados < maxLote;
                if (cb.checked) marcados++;
            });
            if (seleccionables.length > maxLote) {
                mostrarToast(`Selecciona máximo ${maxLote} por tanda para evitar timeouts — envía en varias tandas.`, 'error');
                checkAll.checked = false;
                checkAll.indeterminate = true;
            }
        } else {
            seleccionables.forEach(cb => { cb.checked = false; });
        }
        syncBar();
    });

    rowChecks.forEach(cb => cb.addEventListener('change', () => {
        if (cb.checked && rowChecks.filter(c => c.checked).length > maxLote) {
            cb.checked = false;
            mostrarToast(`Máximo ${maxLote} seleccionados por tanda — envía en varias tandas.`, 'error');
            return;
        }
        const all  = rowChecks.every(c => c.checked);
        const some = rowChecks.some(c => c.checked);
        checkAll.checked       = all;
        checkAll.indeterminate = !all && some;
        syncBar();
    }));

    syncBar();
    return { rowChecks, syncBar };
}

function enviarCampanaMasiva({
    endpoint, csrfToken, campoIds, getSeleccionados,
    nounSingular, nounPlural, incluirOmitidos = false,
    extraCampos = () => ({}),
}) {
    const btnEnviar = document.getElementById('btn-enviar');
    const iconoOriginal = btnEnviar.innerHTML;

    btnEnviar?.addEventListener('click', async () => {
        const checked = getSeleccionados();
        const n = checked.length;
        if (!n) return;
        if (!confirm(`¿Confirmas el envío del correo a ${n} ${n !== 1 ? nounPlural : nounSingular}?`)) return;

        btnEnviar.disabled = true;
        btnEnviar.innerHTML = `
          <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
          </svg> Enviando…`;

        const body = new URLSearchParams();
        body.append('csrf_token', csrfToken);
        checked.forEach(cb => body.append(campoIds, cb.value));
        Object.entries(extraCampos()).forEach(([k, v]) => body.append(k, v));

        try {
            const res  = await fetch(endpoint, { method: 'POST', body });
            const data = await res.json();
            if (data.ok) {
                let msg = `${data.enviados} enviado${data.enviados !== 1 ? 's' : ''}`;
                if (data.fallidos > 0) msg += `, ${data.fallidos} fallido${data.fallidos !== 1 ? 's' : ''}`;
                if (incluirOmitidos && data.omitidos > 0) msg += `, ${data.omitidos} omitido${data.omitidos !== 1 ? 's' : ''} (ya no elegible)`;
                mostrarToast(msg, 'ok');
                setTimeout(() => location.reload(), 2500);
            } else {
                mostrarToast(data.error || 'Error al enviar', 'error');
                btnEnviar.disabled = false;
                btnEnviar.innerHTML = iconoOriginal;
            }
        } catch {
            mostrarToast('Error de conexión', 'error');
            btnEnviar.disabled = false;
            btnEnviar.innerHTML = iconoOriginal;
        }
    });
}
</script>
