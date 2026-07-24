<?php
/**
 * Fragmentos HTML compartidos para paneles de campañas de envío masivo simple
 * (lista de candidatos con checkbox, sin datos estructurados por fila).
 */

function nb_renderizar_tabla_candidatos_simple(array $candidatos, ?string $columnaExtraLabel = null, ?string $columnaExtraCampo = null): string {
    ob_start();
    if (empty($candidatos)) {
        ?>
        <div class="bg-white border border-dashed border-gray-200 rounded-2xl p-16 text-center">
          <p class="text-gray-400 text-sm font-medium">No hay candidatos en este segmento.</p>
        </div>
        <?php
        return ob_get_clean();
    }
    ?>
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100 text-gray-500 text-xs font-semibold uppercase tracking-wider">
          <tr>
            <th class="px-4 py-3.5 w-10 text-center">
              <input type="checkbox" id="check-all" class="w-4 h-4 rounded accent-[#54A6D8] cursor-pointer">
            </th>
            <th class="px-4 py-3.5 text-left">ID</th>
            <th class="px-4 py-3.5 text-left">Nombre</th>
            <th class="px-4 py-3.5 text-left hidden md:table-cell">Correo</th>
            <?php if ($columnaExtraLabel): ?>
            <th class="px-4 py-3.5 text-left hidden md:table-cell"><?= htmlspecialchars($columnaExtraLabel) ?></th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($candidatos as $c): ?>
          <tr class="hover:bg-gray-50/70 transition-colors">
            <td class="px-4 py-3 text-center">
              <input type="checkbox" class="row-check w-4 h-4 rounded accent-[#54A6D8] cursor-pointer"
                     value="<?= (int)$c['alumno_id'] ?>">
            </td>
            <td class="px-4 py-3 text-xs text-gray-400 font-mono"><?= (int)$c['alumno_id'] ?></td>
            <td class="px-4 py-3 font-semibold text-gray-800"><?= htmlspecialchars($c['nombre']) ?></td>
            <td class="px-4 py-3 text-xs text-gray-500 font-mono hidden md:table-cell"><?= htmlspecialchars($c['correo']) ?></td>
            <?php if ($columnaExtraCampo): ?>
            <td class="px-4 py-3 text-xs text-gray-500 hidden md:table-cell">
              <?= !empty($c[$columnaExtraCampo]) ? date('d/m/Y', strtotime($c[$columnaExtraCampo])) : 'Nunca' ?>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 text-xs text-gray-400 text-right">
        <?= count($candidatos) ?> candidato<?= count($candidatos) !== 1 ? 's' : '' ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

function nb_renderizar_action_bar_campana(string $textoBoton = 'Enviar a seleccionados'): string {
    ob_start();
    ?>
    <div id="action-bar"
         class="fixed bottom-0 left-0 right-0 lg:left-64 z-50 bg-white border-t border-gray-200 shadow-xl
                px-6 py-4 flex items-center justify-between gap-4
                transform translate-y-full transition-transform duration-300">
      <p class="text-sm font-bold text-gray-700">
        <span id="bar-count">0</span> seleccionado<span id="bar-plural">s</span>
      </p>
      <button id="btn-enviar" disabled
              class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold shadow-sm transition
                     bg-[#54A6D8] hover:bg-sky-500 text-white
                     disabled:bg-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed">
        <?= htmlspecialchars($textoBoton) ?>
      </button>
    </div>
    <div id="toast"
         class="fixed bottom-24 right-6 px-5 py-3 rounded-xl shadow-xl text-white z-[90] hidden text-sm font-bold">
    </div>
    <?php
    return ob_get_clean();
}
