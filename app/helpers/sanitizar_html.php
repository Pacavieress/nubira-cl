<?php
// app/helpers/sanitizar_html.php
// Sanitización de HTML libre por allowlist — reconstruye el árbol desde cero,
// emitiendo únicamente tags whitelisteados. Por defecto sin atributos (nunca
// se copian los del original); $atributos_permitidos abre una excepción
// explícita por tag/atributo (ej. img.src) validada contra un patrón, nunca
// copiada a ciegas. Pensado para contenido generado por IA o editado a mano
// antes de guardarlo o mostrarlo (Centro de Recursos, guias_articulos.cuerpo).
if (!function_exists('nb_sanitizar_html')) {
    function nb_sanitizar_html(
        string $html,
        array $tags_permitidos = ['p','h2','h3','ul','ol','li','strong','em'],
        array $atributos_permitidos = []
    ): string {
        // Tags cuyo contenido interno no es texto legible (código/markup) —
        // se descartan enteros, tag y contenido, en vez de "desenvolverlos".
        $descartar_con_contenido = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'link', 'meta', 'head', 'noscript'];

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $raiz = $dom->getElementsByTagName('div')->item(0);
        if (!$raiz) return '';

        return nb_limpiar_nodo_html($raiz, $tags_permitidos, $descartar_con_contenido, $atributos_permitidos);
    }
}

if (!function_exists('nb_limpiar_nodo_html')) {
    function nb_limpiar_nodo_html(DOMNode $nodo, array $permitidos, array $descartar, array $atributos_permitidos = []): string {
        // Tags sin contenido/cierre propio — se emiten autocerrados (<img ... />),
        // nunca como <img>...</img> (HTML inválido para un elemento vacío).
        $tags_vacios = ['img', 'br', 'hr'];

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

            if (!in_array($tag, $permitidos, true)) {
                // tag no permitido: se conserva el texto interno, igual que antes
                $html .= nb_limpiar_nodo_html($hijo, $permitidos, $descartar, $atributos_permitidos);
                continue;
            }

            $attrs_html = ($hijo instanceof DOMElement)
                ? nb_limpiar_atributos_html($hijo, $atributos_permitidos[$tag] ?? [])
                : '';

            if (in_array($tag, $tags_vacios, true)) {
                if ($tag === 'img' && strpos($attrs_html, ' src="') === false) {
                    continue; // sin src válido, un <img> vacío no tiene sentido
                }
                $html .= "<{$tag}{$attrs_html} />";
                continue;
            }

            $contenido_interno = nb_limpiar_nodo_html($hijo, $permitidos, $descartar, $atributos_permitidos);
            $html .= "<{$tag}{$attrs_html}>{$contenido_interno}</{$tag}>";
        }
        return $html;
    }
}

if (!function_exists('nb_limpiar_atributos_html')) {
    // $config: ['nombre_atributo' => patron_regex_o_null]. null = sin restricción
    // de formato (se guarda igual, solo escapado) — usar para texto libre (ej. alt).
    // Un atributo cuyo valor no matchea el patrón se descarta (el tag se conserva).
    function nb_limpiar_atributos_html(DOMElement $el, array $config): string {
        $out = '';
        foreach ($config as $nombre => $patron) {
            if (!$el->hasAttribute($nombre)) continue;
            $valor = trim($el->getAttribute($nombre));
            if ($valor === '') continue;
            if ($patron !== null && !preg_match($patron, $valor)) continue;
            $out .= ' ' . $nombre . '="' . htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') . '"';
        }
        return $out;
    }
}
