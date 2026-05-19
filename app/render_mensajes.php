<?php
/**
 * COMPONENTE DE RENDERIZADO DE MENSAJES (NUBIRA 2.0)
 * UBICACIÓN: public_html/app/render_mensajes.php
 */

if (!isset($mensajes) || !is_array($mensajes)) return;
if (!isset($usuario_id)) return;

$remitente_anterior = null;
$timestamp_anterior = 0;
$total_mensajes = count($mensajes);

foreach ($mensajes as $idx => $msg):
    
    
    // =========================================================
    // [NUBIRA 2.0] SYSTEM MESSAGE: Renderizar sin burbuja
    // =========================================================
    if ($msg['remitente_id'] == 0) {
        echo $msg['mensaje']; // Imprime el HTML puro del botón/alerta
        
        // Actualizamos los punteros para no romper la agrupación visual del chat
        $remitente_anterior = 0;
        $timestamp_anterior = strtotime($msg['fecha_real']);
        continue; // Saltamos todo el resto del código y pasamos al siguiente mensaje
    }
    
    $soyYo = ($msg['remitente_id'] == $usuario_id);
    $hora  = date('H:i', strtotime($msg['fecha_real']));
    $timestamp_actual = strtotime($msg['fecha_real']);

    $es_continuacion = (
        $remitente_anterior === $msg['remitente_id']
        && ($timestamp_actual - $timestamp_anterior) < 120
    );

    $siguiente = $mensajes[$idx + 1] ?? null;
    $es_ultimo_del_grupo = (
        !$siguiente
        || $siguiente['remitente_id'] !== $msg['remitente_id']
        || (strtotime($siguiente['fecha_real']) - $timestamp_actual) >= 120
    );

    $remitente_anterior = $msg['remitente_id'];
    $timestamp_anterior = $timestamp_actual;

    $align = $soyYo ? 'justify-end' : 'justify-start';
    $bubbleClass = $soyYo ? 'bubble-me' : 'bubble-other';
    $tickColor = $msg['estado_visto'] ? 'text-blue-100' : 'text-blue-200/60';
    $marginClass = $es_continuacion ? 'mb-0.5' : 'mb-2';

    if ($soyYo) {
        if ($es_continuacion && !$es_ultimo_del_grupo) {
            $bubbleRadius = 'rounded-[18px_4px_4px_18px]';
        } elseif ($es_continuacion && $es_ultimo_del_grupo) {
            $bubbleRadius = 'rounded-[18px_4px_18px_18px]';
        } elseif (!$es_continuacion && !$es_ultimo_del_grupo) {
            $bubbleRadius = 'rounded-[18px_18px_4px_18px]';
        } else {
            $bubbleRadius = '';
        }
    } else {
        if ($es_continuacion && !$es_ultimo_del_grupo) {
            $bubbleRadius = 'rounded-[4px_18px_18px_4px]';
        } elseif ($es_continuacion && $es_ultimo_del_grupo) {
            $bubbleRadius = 'rounded-[4px_18px_18px_18px]';
        } elseif (!$es_continuacion && !$es_ultimo_del_grupo) {
            $bubbleRadius = 'rounded-[18px_18px_18px_4px]';
        } else {
            $bubbleRadius = '';
        }
    }

    $tiene_archivo = !empty($msg['archivo_ruta']);
    $es_imagen = $tiene_archivo && strpos($msg['archivo_tipo'] ?? '', 'image/') === 0;
    $es_pdf    = $tiene_archivo && ($msg['archivo_tipo'] ?? '') === 'application/pdf';
    
    $archivo_nombre_safe = htmlspecialchars($msg['archivo_nombre'] ?? '');
    $msg_id = (int)$msg['id'];
?>
    <div class="flex w-full <?= $align ?> fade-in <?= $marginClass ?> group">
        <div class="<?= $bubbleClass ?> <?= $bubbleRadius ?> relative max-w-[85%] md:max-w-[70%] px-4 py-2 shadow-sm text-[14px] leading-snug break-words">

<?php if ($es_imagen): ?>
<div class="relative group/img -mx-4 -mt-2 mb-1 overflow-hidden first:rounded-t-[18px] last:rounded-b-[18px]">
<a href="/archivo-chat/<?= $msg_id ?>" target="_blank" rel="noopener" class="block">
<img src="/archivo-chat/<?= $msg_id ?>" alt="<?= $archivo_nombre_safe ?>" class="w-full max-w-[280px] object-cover hover:opacity-90 transition-opacity" loading="lazy">
</a>
<a href="/archivo-chat/<?= $msg_id ?>?dl=1" download="<?= $archivo_nombre_safe ?>" class="absolute top-2 right-2 w-8 h-8 bg-black/60 hover:bg-black/80 backdrop-blur-sm text-white rounded-full flex items-center justify-center transition-all shadow-md" title="Descargar imagen" aria-label="Descargar imagen">
<i class="fa-solid fa-arrow-down text-xs"></i>
</a>
</div>
<?php elseif ($es_pdf): ?>
<div class="-mx-2 my-1 flex flex-col gap-1.5 min-w-[240px]">
<a href="/archivo-chat/<?= $msg_id ?>" target="_blank" rel="noopener" class="flex items-center gap-3 px-3 py-2.5 rounded-xl <?= $soyYo ? 'bg-white/15 hover:bg-white/25' : 'bg-gray-50 hover:bg-gray-100' ?> transition-colors">
<div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0 <?= $soyYo ? 'bg-white/20' : 'bg-red-50 text-red-500' ?>">
<i class="fa-solid fa-file-pdf text-xl <?= $soyYo ? 'text-white' : '' ?>"></i>
</div>
<div class="flex-1 min-w-0">
<p class="text-[13px] font-medium truncate <?= $soyYo ? 'text-white' : 'text-gray-900' ?>"><?= $archivo_nombre_safe ?></p>
<p class="text-[11px] <?= $soyYo ? 'text-blue-50/90' : 'text-gray-500' ?>">PDF · <?= number_format(($msg['archivo_peso'] ?? 0) / 1024, 0) ?> KB</p>
</div>
<div class="<?= $soyYo ? 'text-white/80' : 'text-gray-400' ?> shrink-0">
<i class="fa-solid fa-eye text-sm"></i>
</div>
</a>
<a href="/archivo-chat/<?= $msg_id ?>?dl=1" download="<?= $archivo_nombre_safe ?>" class="flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold transition-colors <?= $soyYo ? 'bg-white/10 hover:bg-white/20 text-white' : 'bg-white border border-gray-200 hover:border-gray-300 text-gray-700' ?>">
<i class="fa-solid fa-arrow-down text-[10px]"></i>
Descargar
</a>
</div>
<?php endif; ?>

           <?php if (!empty($msg['mensaje'])): ?>
                <?= nl2br(htmlspecialchars($msg['mensaje'], ENT_QUOTES, 'UTF-8')) ?>
            <?php endif; ?>

            <?php if ($es_ultimo_del_grupo): ?>
                <div class="text-[10px] flex items-center justify-end gap-1 mt-1 select-none opacity-80">
                    <span class="<?= $soyYo ? 'text-blue-50' : 'text-gray-400' ?>"><?= $hora ?></span>
                    <?php if($soyYo): ?>
                        <span class="<?= $tickColor ?>">
                            <?php if($msg['estado_visto']): ?>
                                <i class="fa-solid fa-check-double"></i>
                            <?php else: ?>
                                <i class="fa-solid fa-check"></i>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php
endforeach;