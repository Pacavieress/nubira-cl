<?php
// app/helpers/sanitizar_html.php
// Sanitización de HTML libre por allowlist — reconstruye el árbol desde cero,
// emitiendo únicamente tags whitelisteados SIN atributos (nunca se copian los
// del original). Pensado para contenido generado por IA antes de guardarlo o
// mostrarlo (Centro de Recursos, guias_articulos.cuerpo).
if (!function_exists('nb_sanitizar_html')) {
    function nb_sanitizar_html(string $html, array $tags_permitidos = ['p','h2','h3','ul','ol','li','strong','em']): string {
        // Tags cuyo contenido interno no es texto legible (código/markup) —
        // se descartan enteros, tag y contenido, en vez de "desenvolverlos".
        $descartar_con_contenido = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'link', 'meta', 'head', 'noscript'];

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $raiz = $dom->getElementsByTagName('div')->item(0);
        if (!$raiz) return '';

        return nb_limpiar_nodo_html($raiz, $tags_permitidos, $descartar_con_contenido);
    }
}

if (!function_exists('nb_limpiar_nodo_html')) {
    function nb_limpiar_nodo_html(DOMNode $nodo, array $permitidos, array $descartar): string {
        $html = '';
        foreach (iterator_to_array($nodo->childNodes) as $hijo) {
            if ($hijo->nodeType === XML_TEXT_NODE) {
                // ENT_NOQUOTES: las comillas no tienen significado especial como
                // contenido de un tag (solo dentro de un atributo) — escaparlas acá
                // producía &#039; visible en texto de prosa normal (ej. "'acerque'").
                $html .= htmlspecialchars($hijo->textContent, ENT_NOQUOTES, 'UTF-8');
                continue;
            }
            if ($hijo->nodeType !== XML_ELEMENT_NODE) continue; // descarta comentarios, CDATA, etc.

            $tag = strtolower($hijo->nodeName);
            if (in_array($tag, $descartar, true)) {
                continue; // tag Y contenido fuera
            }

            $contenido_interno = nb_limpiar_nodo_html($hijo, $permitidos, $descartar);

            if (in_array($tag, $permitidos, true)) {
                $html .= "<{$tag}>{$contenido_interno}</{$tag}>"; // reconstruido, cero atributos originales
            } else {
                $html .= $contenido_interno; // tag no permitido: se conserva el texto interno
            }
        }
        return $html;
    }
}
