<?php
/**
 * PARTIAL: Grilla semanal de horario de disponibilidad.
 * El includer debe haber hecho require_once helpers/horarios.php antes.
 * Variable opcional antes de incluir: $horarios_info (array{tiene_horarios,dias,dia_proximo}).
 */
$horarios_info = $horarios_info ?? ['tiene_horarios' => false, 'dias' => [], 'dia_proximo' => null];
$dias_semana   = dias_semana_nubira();
?>
<div class="space-y-4" id="dias-container">
    <?php foreach ($dias_semana as $dia):
        $bloques = $horarios_info['dias'][$dia] ?? [];
        $activo  = count($bloques) > 0;
    ?>
        <div class="dia-block border border-gray-200 rounded-2xl p-4 transition-all <?= $activo ? 'bg-white' : 'bg-gray-50 opacity-70' ?>" data-dia="<?= $dia ?>">
            <div class="flex items-center justify-between">
                <h3 class="font-bold <?= $activo ? 'text-gray-900' : 'text-gray-400' ?> w-24 dia-titulo"><?= $dia ?></h3>
                <label class="relative inline-flex items-center cursor-pointer mr-2">
                    <input type="checkbox" class="sr-only peer toggle-checkbox"
                           <?= $activo ? 'checked' : '' ?>
                           onchange="toggleDiaHorario(this)">
                    <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#34C759]"></div>
                </label>
            </div>

            <div class="slots-container mt-4 space-y-2 <?= $activo ? '' : 'hidden' ?>">
                <?php if ($activo): ?>
                    <?php foreach ($bloques as $b):
                        $horas = explode(' - ', $b);
                        $desde = $horas[0] ?? '';
                        $hasta = $horas[1] ?? '';
                    ?>
                        <div class="flex items-center gap-2 slot-row animate-fade-in">
                            <input type="time" class="bg-gray-100 border-0 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-[#54A6D8] block w-full p-2.5 font-bold time-desde" value="<?= htmlspecialchars($desde) ?>">
                            <span class="text-gray-400 font-bold">-</span>
                            <input type="time" class="bg-gray-100 border-0 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-[#54A6D8] block w-full p-2.5 font-bold time-hasta" value="<?= htmlspecialchars($hasta) ?>">
                            <button type="button" class="w-10 h-10 shrink-0 text-gray-400 hover:text-red-500 bg-white hover:bg-red-50 rounded-xl transition-all flex items-center justify-center" onclick="eliminarSlotHorario(this)">
                                <i class="fa-solid fa-circle-minus text-lg"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="flex items-center gap-2 slot-row">
                        <input type="time" class="bg-gray-100 border-0 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-[#54A6D8] block w-full p-2.5 font-bold time-desde" value="09:00">
                        <span class="text-gray-400 font-bold">-</span>
                        <input type="time" class="bg-gray-100 border-0 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-[#54A6D8] block w-full p-2.5 font-bold time-hasta" value="12:00">
                        <button type="button" class="w-10 h-10 shrink-0 text-gray-400 hover:text-red-500 bg-white hover:bg-red-50 rounded-xl transition-all flex items-center justify-center" onclick="eliminarSlotHorario(this)">
                            <i class="fa-solid fa-circle-minus text-lg"></i>
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="btn-add-container mt-3 <?= $activo ? '' : 'hidden' ?>">
                <button type="button" onclick="añadirSlotHorario(this)" class="text-xs font-bold text-[#54A6D8] hover:text-blue-700 bg-blue-50 px-3 py-1.5 rounded-full transition-colors inline-flex items-center gap-1">
                    <i class="fa-solid fa-circle-plus"></i> Añadir bloque
                </button>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div id="horario-error" class="hidden mt-3 text-xs font-bold text-red-600 bg-red-50 border border-red-200 rounded-xl px-3 py-2">
    Marca al menos un bloque de disponibilidad antes de publicar.
</div>

<script>
    window.toggleDiaHorario = function(checkbox) {
        const block = checkbox.closest('.dia-block');
        const slotsContainer = block.querySelector('.slots-container');
        const btnAdd = block.querySelector('.btn-add-container');
        const titulo = block.querySelector('.dia-titulo');

        if (checkbox.checked) {
            block.classList.remove('bg-gray-50', 'opacity-70');
            block.classList.add('bg-white');
            titulo.classList.remove('text-gray-400');
            titulo.classList.add('text-gray-900');
            slotsContainer.classList.remove('hidden');
            btnAdd.classList.remove('hidden');
            if (slotsContainer.children.length === 0) añadirSlotHorario(btnAdd.querySelector('button'));
        } else {
            block.classList.add('bg-gray-50', 'opacity-70');
            block.classList.remove('bg-white');
            titulo.classList.add('text-gray-400');
            titulo.classList.remove('text-gray-900');
            slotsContainer.classList.add('hidden');
            btnAdd.classList.add('hidden');
        }
    };

    window.eliminarSlotHorario = function(btn) {
        const row = btn.closest('.slot-row');
        const container = row.parentElement;
        row.remove();
        if (container.children.length === 0) {
            const block = container.closest('.dia-block');
            const checkbox = block.querySelector('.toggle-checkbox');
            checkbox.checked = false;
            toggleDiaHorario(checkbox);
        }
    };

    window.añadirSlotHorario = function(btn) {
        const block = btn.closest('.dia-block');
        const container = block.querySelector('.slots-container');
        const html = `
            <div class="flex items-center gap-2 slot-row animate-fade-in">
                <input type="time" class="bg-gray-100 border-0 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-[#54A6D8] block w-full p-2.5 font-bold time-desde" value="15:00">
                <span class="text-gray-400 font-bold">-</span>
                <input type="time" class="bg-gray-100 border-0 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-[#54A6D8] block w-full p-2.5 font-bold time-hasta" value="18:00">
                <button type="button" class="w-10 h-10 shrink-0 text-gray-400 hover:text-red-500 bg-white hover:bg-red-50 rounded-xl transition-all flex items-center justify-center" onclick="eliminarSlotHorario(this)">
                    <i class="fa-solid fa-circle-minus text-lg"></i>
                </button>
            </div>
        `;
        container.insertAdjacentHTML('beforeend', html);
    };

    // Serializa la grilla y valida (mismas reglas que editar_horarios.php: formato HH:MM,
    // desde<hasta, sin solapes) + exige al menos 1 bloque en toda la semana.
    window.serializarHorarioGrilla = function() {
        const data = {};
        let error = null;

        const aMinutos = (hhmm) => {
            const [h, m] = hhmm.split(':').map(Number);
            return h * 60 + m;
        };

        document.querySelectorAll('#dias-container .dia-block').forEach(block => {
            const dia = block.getAttribute('data-dia');
            const activo = block.querySelector('.toggle-checkbox').checked;
            if (!activo) { data[dia] = []; return; }

            const horarios = [];
            const rangos = [];
            block.querySelectorAll('.slot-row').forEach(row => {
                const desde = row.querySelector('.time-desde').value;
                const hasta = row.querySelector('.time-hasta').value;
                if (!desde || !hasta) return;
                if (desde >= hasta) {
                    error = `Error en ${dia}: la hora de inicio (${desde}) debe ser menor a la de fin (${hasta}).`;
                    return;
                }
                horarios.push(`${desde} - ${hasta}`);
                rangos.push([aMinutos(desde), aMinutos(hasta), desde, hasta]);
            });

            rangos.sort((a, b) => a[0] - b[0]);
            for (let i = 1; i < rangos.length; i++) {
                if (rangos[i][0] < rangos[i - 1][1]) {
                    error = `Error en ${dia}: el bloque ${rangos[i][2]} - ${rangos[i][3]} se solapa con ${rangos[i - 1][2]} - ${rangos[i - 1][3]}.`;
                }
            }
            data[dia] = horarios;
        });

        const tieneAlMenosUnBloque = Object.values(data).some(bloques => bloques.length > 0);
        if (!error && !tieneAlMenosUnBloque) {
            error = 'Marca al menos un bloque de disponibilidad antes de publicar.';
        }

        return { json: JSON.stringify(data), error };
    };
</script>
