// app/js/agenda_slots.js
// Selector de horarios (grilla de días + slots de 30 min) compartido entre
// contratar_servicio.php y detalle_servicio.php. Consulta slots_disponibles.php.
// Requiere en el DOM: #agenda-wrapper[data-servicio-id], botones .dia-card[data-dia],
// #slots-section, #slots-dia-label, #fechas-strip, #slots-grid, #slots-leyenda,
// #slots-loading, #slots-empty.
function initAgendaSlots(config) {
    const wrapper = document.getElementById(config.wrapperId || 'agenda-wrapper');
    if (!wrapper) return;

    const servicioId = wrapper.dataset.servicioId;
    const slotsSection = document.getElementById('slots-section');
    const slotsDiaLabel = document.getElementById('slots-dia-label');
    const fechasStrip = document.getElementById('fechas-strip');
    const slotsGrid = document.getElementById('slots-grid');
    const slotsLeyenda = document.getElementById('slots-leyenda');
    const slotsLoad = document.getElementById('slots-loading');
    const slotsEmpty = document.getElementById('slots-empty');

    const meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    const diasMap = { 'Lunes':1,'Martes':2,'Miércoles':3,'Jueves':4,'Viernes':5,'Sábado':6,'Domingo':0 };

    let diaSeleccionado = null;
    let fechaActiva = null;

    document.querySelectorAll('.dia-card').forEach(card => {
        card.addEventListener('click', () => {
            document.querySelectorAll('.dia-card').forEach(c => {
                c.classList.remove('ring-2','ring-blue-100','border-[#54A6D8]','bg-blue-50');
                c.classList.add('border-blue-100');
            });
            card.classList.remove('border-blue-100');
            card.classList.add('ring-2','ring-blue-100','border-[#54A6D8]','bg-blue-50');

            diaSeleccionado = card.dataset.dia;
            slotsDiaLabel.textContent = diaSeleccionado.toLowerCase();
            slotsSection.classList.remove('hidden');
            if (config.onDiaSeleccionado) config.onDiaSeleccionado();

            renderFechasProximas(diasMap[diaSeleccionado]);
        });
    });

    function renderFechasProximas(targetDow) {
        fechasStrip.innerHTML = '';
        const hoy = new Date();
        const fechas = [];
        let cursor = new Date(hoy);

        while (fechas.length < 4) {
            if (cursor.getDay() === targetDow && cursor >= hoy) {
                fechas.push(new Date(cursor));
            }
            cursor.setDate(cursor.getDate() + 1);
            if (fechas.length >= 4) break;
        }

        fechas.forEach((d, idx) => {
            const yyyy = d.getFullYear();
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const dd = String(d.getDate()).padStart(2, '0');
            const fechaStr = `${yyyy}-${mm}-${dd}`;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'flex-shrink-0 w-20 py-2.5 rounded-xl border border-gray-200 bg-white hover:border-[#54A6D8] transition-all text-center fecha-btn';
            btn.dataset.fecha = fechaStr;
            btn.innerHTML = `
                <p class="text-[10px] font-bold text-gray-400 uppercase">${idx === 0 ? 'Próximo' : 'En ' + (idx*7) + ' días'}</p>
                <p class="text-base font-extrabold text-gray-900 leading-tight">${d.getDate()} ${meses[d.getMonth()]}</p>
            `;
            btn.addEventListener('click', () => seleccionarFecha(btn));
            fechasStrip.appendChild(btn);
        });

        // Auto-seleccionar la primera fecha
        const first = fechasStrip.querySelector('.fecha-btn');
        if (first) seleccionarFecha(first);
    }

    function seleccionarFecha(btn) {
        fechasStrip.querySelectorAll('.fecha-btn').forEach(b => {
            b.classList.remove('border-[#54A6D8]','bg-blue-50','ring-2','ring-blue-100');
            b.classList.add('border-gray-200','bg-white');
        });
        btn.classList.remove('border-gray-200','bg-white');
        btn.classList.add('border-[#54A6D8]','bg-blue-50','ring-2','ring-blue-100');

        if (config.onFechaSeleccionada) config.onFechaSeleccionada();
        fechaActiva = btn.dataset.fecha;
        cargarSlots(fechaActiva);
    }

    async function cargarSlots(fecha) {
        slotsGrid.innerHTML = '';
        slotsEmpty.classList.add('hidden');
        slotsLoad.classList.remove('hidden');

        try {
            const res = await fetch(`/app/api/slots_disponibles.php?servicio_id=${servicioId}&fecha=${fecha}`);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();

            slotsLoad.classList.add('hidden');

            if (!data.slots || data.slots.length === 0) {
                slotsEmpty.classList.remove('hidden');
                return;
            }
            renderSlots(data.slots);
        } catch (e) {
            console.error('Error cargando slots:', e);
            slotsLoad.classList.add('hidden');
            slotsEmpty.classList.remove('hidden');
        }
    }

    function renderSlots(slots) {
        let html = '';
        slots.forEach(slot => {
            const dis = slot.disponible;
            let cls, title = '';
            if (dis) {
                cls = 'slot-btn bg-white border border-gray-200 text-gray-900 hover:border-[#54A6D8] hover:bg-blue-50 cursor-pointer';
            } else if (slot.motivo === 'pasado') {
                cls = 'bg-gray-50 border border-dashed border-gray-200 text-gray-300 cursor-not-allowed';
                title = 'Muy pronto para agendar';
            } else {
                cls = 'bg-gray-50 border border-gray-100 text-gray-300 cursor-not-allowed line-through';
                title = 'Este horario ya está ocupado';
            }
            html += `
                <button type="button"
                        class="${cls} py-2.5 rounded-xl text-sm font-bold transition-all"
                        ${dis ? `data-datetime="${slot.datetime}" data-hora="${slot.hora}"` : `disabled title="${title}"`}>
                    ${slot.hora}
                </button>
            `;
        });
        slotsGrid.innerHTML = html;

        slotsGrid.querySelectorAll('.slot-btn').forEach(b => {
            b.addEventListener('click', () => seleccionarSlot(b));
        });

        // Leyenda visible siempre (no depende de hover) para explicar los dos motivos de bloqueo
        const hayOcupado = slots.some(s => !s.disponible && s.motivo === 'ocupado');
        const hayPasado  = slots.some(s => !s.disponible && s.motivo === 'pasado');
        if (hayOcupado || hayPasado) {
            let leyenda = '';
            if (hayOcupado) {
                leyenda += `<span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded border border-gray-200 bg-gray-50"></span>Ocupado</span>`;
            }
            if (hayPasado) {
                leyenda += `<span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded border border-dashed border-gray-200 bg-gray-50"></span>Muy pronto para agendar</span>`;
            }
            slotsLeyenda.innerHTML = leyenda;
            slotsLeyenda.classList.remove('hidden');
        } else {
            slotsLeyenda.classList.add('hidden');
        }
    }

    function seleccionarSlot(btn) {
        slotsGrid.querySelectorAll('.slot-btn').forEach(b => {
            b.classList.remove('bg-[#54A6D8]','text-white','border-[#54A6D8]');
            b.classList.add('bg-white','text-gray-900','border-gray-200');
        });
        btn.classList.remove('bg-white','text-gray-900','border-gray-200');
        btn.classList.add('bg-[#54A6D8]','text-white','border-[#54A6D8]');

        config.onSlotSelected(btn.dataset.datetime, btn.dataset.hora);
    }
}
