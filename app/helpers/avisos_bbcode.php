<?php
// Whitelist de íconos + mini-sintaxis BBCode segura para avisos (admin_avisos.php / header.php).
// El admin nunca escribe HTML: solo [b]texto[/b] y [icon:nombre], con nombre validado
// contra esta misma whitelist tanto en el render PHP como en la vista previa JS.
if (!function_exists('nb_avisos_iconos_whitelist')) {
    function nb_avisos_iconos_whitelist(): array {
        return [
            'info'       => 'fa-regular fa-circle-info',
            'alerta'     => 'fa-solid fa-triangle-exclamation',
            'regalo'     => 'fa-solid fa-gift',
            'megafono'   => 'fa-solid fa-bullhorn',
            'calendario' => 'fa-regular fa-calendar',
            'estrella'   => 'fa-regular fa-star',
            'check'      => 'fa-regular fa-circle-check',
            'corazon'    => 'fa-regular fa-heart',
            'campana'    => 'fa-regular fa-bell',
            'cohete'     => 'fa-solid fa-rocket',
        ];
    }
}

// Aplicar SIEMPRE después de htmlspecialchars(), nunca antes. El texto que entra ya tiene
// < > & " ' escapados; el único HTML que sale de acá es el que generamos nosotros mismos
// (<strong> y <i> de la whitelist) — cualquier intento de inyección ya fue neutralizado
// por el escape previo y no puede hacer match con estos patrones.
if (!function_exists('nb_renderizar_aviso_bbcode')) {
    function nb_renderizar_aviso_bbcode(string $textoEscapado): string {
        $texto = preg_replace('/\[b\](.+?)\[\/b\]/s', '<strong>$1</strong>', $textoEscapado);

        $iconos = nb_avisos_iconos_whitelist();
        $texto = preg_replace_callback('/\[icon:([a-zA-Z0-9_]+)\]/', function ($m) use ($iconos) {
            $key = strtolower($m[1]);
            return isset($iconos[$key]) ? '<i class="' . $iconos[$key] . '" aria-hidden="true"></i>' : $m[0];
        }, $texto);

        return $texto;
    }
}
