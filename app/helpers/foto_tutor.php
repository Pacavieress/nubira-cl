<?php
// Helper: resuelve la foto física del tutor para el generador de imágenes.
// Si no hay foto válida en disco, el generador dibuja círculo + inicial.

if (!function_exists('nb_fotos_tutor_dir')) {
    function nb_fotos_tutor_dir(): string {
        $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($root === '') $root = dirname(__DIR__, 2); // CLI: raíz del proyecto
        return rtrim($root, '/\\') . '/app/perfil/fotos/';
    }
}

if (!function_exists('path_foto_tutor')) {
    /** Ruta FÍSICA de la foto del tutor, o '' si no hay foto válida en disco. */
    function path_foto_tutor(array $row): string {
        $foto = trim((string)($row['foto_perfil'] ?? ''));
        if ($foto === '') return '';
        $path = nb_fotos_tutor_dir() . basename($foto);
        return is_file($path) ? $path : '';
    }
}

if (!function_exists('necesidad_avatar_inicial')) {
    /** true si NO hay foto válida → el generador usa círculo + inicial. */
    function necesidad_avatar_inicial(array $row): bool {
        return path_foto_tutor($row) === '';
    }
}
