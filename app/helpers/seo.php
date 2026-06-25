<?php
// app/helpers/seo.php — Helper SEO central de Nubira

if (!function_exists('nubira_canonical')) {
    /**
     * URL canónica de la request actual: https://nubira.cl + path real SIN query string.
     * Siempre no-www y sin trailing slash redundante (salvo la raíz "/").
     * $path_forzado: opcional, para fijar una ruta canónica explícita (alias).
     */
    function nubira_canonical(?string $path_forzado = null): string {
        $dominio = 'https://nubira.cl';
        $path = $path_forzado ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (!is_string($path) || $path === '') { $path = '/'; }
        $path = preg_replace('#/+#', '/', $path);          // colapsa //
        if ($path !== '/') { $path = rtrim($path, '/'); }  // sin trailing slash (excepto raíz)
        if ($path[0] !== '/') { $path = '/' . $path; }
        return $dominio . $path;
    }

    /** Etiqueta <link rel="canonical"> ya escapada para el <head>. */
    function nubira_canonical_tag(?string $path_forzado = null): string {
        return '<link rel="canonical" href="'
            . htmlspecialchars(nubira_canonical($path_forzado), ENT_QUOTES, 'UTF-8')
            . '" />';
    }

    /** Devuelve <title> + meta description + og:title + og:description, todo escapado. */
    function nubira_seo_meta(string $title, string $description): string {
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $d = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
        return '<title>' . $t . '</title>' . "\n  "
             . '<meta name="description" content="' . $d . '" />' . "\n  "
             . '<meta property="og:title" content="' . $t . '" />' . "\n  "
             . '<meta property="og:description" content="' . $d . '" />';
    }

    /** Mapa slug => nombre canónico de categoría para landings SEO (sin "Otros"). */
    function nubira_categorias_seo(): array {
        return [
            'matematicas'  => 'Matemáticas',
            'quimica'      => 'Química',
            'fisica'       => 'Física',
            'biologia'     => 'Biología',
            'programacion' => 'Programación',
            'idiomas'      => 'Idiomas',
            'historia'     => 'Historia',
            'lenguaje'     => 'Lenguaje',
            'economia'     => 'Economía',
            'diseno'       => 'Diseño',
            'derecho'      => 'Derecho',
            'asesoria'     => 'Asesoría',
            'calculo'      => 'Cálculo',
            'ingles'       => 'Inglés',
            'tesis'        => 'Tesis',
        ];
    }
}
