<?php
/**
 * NUBIRA 2.0 — Resolver unificado de portada de servicios (banco de imágenes)
 *
 * Reemplaza la lógica dispersa de portada (portada_helper / resolver_portada_servicio /
 * bloques inline) por un único punto que decide la imagen a partir de la FILA del servicio.
 *
 * Prioridad (IGNORA imagen_estado — la moderación de imagen quedó obsoleta):
 *   1) imagen_banco_id NOT NULL  → /upload/banco/{archivo del banco}
 *   2) imagen IS NOT NULL (legacy) → /upload/servicios/{imagen}
 *   3) nada                       → /upload/banco/placeholder.webp
 *
 * Tamaños responsivos: cada imagen (banco o legacy) tiene 3 variantes generadas:
 *   main → base.webp  |  card → base_card.webp  |  thumb → base_thumb.webp
 * Si una variante no existe físicamente, cae a main; si main no existe, cae al placeholder.
 *
 * CONTRATO DE LA FILA ($row):
 *   - 'imagen_banco_id' (int|null)
 *   - 'banco_archivo'   (string|null)  ← nombre del archivo del banco, vía LEFT JOIN
 *                                         banco_imagenes bi ON bi.id = s.imagen_banco_id
 *   - 'imagen'          (string|null)  ← legacy
 *
 * Si la fila trae imagen_banco_id pero NO trae 'banco_archivo' (consumidor olvidó el JOIN),
 * el helper hace un lookup perezoso con caché estática (sin N+1) usando $conn global.
 */

if (!defined('NB_BANCO_WEB'))      define('NB_BANCO_WEB', '/upload/banco/');
if (!defined('NB_SERVICIOS_WEB'))  define('NB_SERVICIOS_WEB', '/upload/servicios/');
if (!defined('NB_PLACEHOLDER'))    define('NB_PLACEHOLDER', 'placeholder.webp');

if (!function_exists('nb_doc_root')) {
    function nb_doc_root(): string {
        // En CLI no hay DOCUMENT_ROOT; usamos la raíz del proyecto (dos niveles sobre /app/helpers).
        $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($root === '') $root = dirname(__DIR__, 2);
        return rtrim($root, '/\\');
    }
}

if (!function_exists('nb_lookup_banco_archivo')) {
    /**
     * Lookup perezoso del archivo del banco por id, con caché estática (evita N+1).
     * Solo se usa si el consumidor no proveyó 'banco_archivo' en la fila.
     */
    function nb_lookup_banco_archivo(int $banco_id): ?string {
        static $cache = [];
        if ($banco_id <= 0) return null;
        if (array_key_exists($banco_id, $cache)) return $cache[$banco_id];

        global $conn;
        $cache[$banco_id] = null;
        if (isset($conn) && $conn instanceof mysqli) {
            if ($stmt = $conn->prepare("SELECT archivo FROM banco_imagenes WHERE id = ? LIMIT 1")) {
                $stmt->bind_param("i", $banco_id);
                $stmt->execute();
                $stmt->bind_result($archivo);
                if ($stmt->fetch()) $cache[$banco_id] = $archivo;
                $stmt->close();
            }
        }
        return $cache[$banco_id];
    }
}

if (!function_exists('nb_resolver_size')) {
    /**
     * Resuelve una variante de tamaño dentro de un directorio web dado.
     * @return string|null URL con cache-busting, o null si ni la variante ni el main existen.
     */
    function nb_resolver_size(string $webDir, string $archivo, string $tamano): ?string {
        $docRoot = nb_doc_root();
        $base    = pathinfo(basename($archivo), PATHINFO_FILENAME);
        $sufijo  = ($tamano === 'main') ? '' : '_' . $tamano;

        // 1) Variante solicitada (siempre .webp para las versiones generadas)
        $rel_pref = $webDir . $base . $sufijo . '.webp';
        $fis_pref = $docRoot . $rel_pref;
        if (is_file($fis_pref)) {
            return $rel_pref . '?v=' . filemtime($fis_pref);
        }

        // 2) Fallback al main (respeta la extensión original del archivo legacy)
        $rel_main = $webDir . basename($archivo);
        $fis_main = $docRoot . $rel_main;
        if (is_file($fis_main)) {
            return $rel_main . '?v=' . filemtime($fis_main);
        }

        return null;
    }
}

if (!function_exists('nb_resolver_portada')) {
    /**
     * Núcleo del resolver. Devuelve la URL de la portada del servicio para un tamaño dado.
     * @param array  $row     Fila del servicio (ver contrato arriba)
     * @param string $tamano  'thumb' | 'card' | 'main'
     */
    function nb_resolver_portada(array $row, string $tamano = 'main'): string {
        $banco_id = (int)($row['imagen_banco_id'] ?? 0);

        // 1) Banco (prioridad). Si el archivo del banco no existe, cae al placeholder.
        if ($banco_id > 0) {
            $archivo = $row['banco_archivo'] ?? ($row['archivo'] ?? null);
            if (empty($archivo)) $archivo = nb_lookup_banco_archivo($banco_id);
            if (!empty($archivo)) {
                $url = nb_resolver_size(NB_BANCO_WEB, $archivo, $tamano);
                if ($url !== null) return $url;
            }
        }
        // 2) Legacy (ignora imagen_estado)
        elseif (!empty($row['imagen'])) {
            $url = nb_resolver_size(NB_SERVICIOS_WEB, $row['imagen'], $tamano);
            if ($url !== null) return $url;
        }

        // 3) Placeholder del banco
        $ph = nb_resolver_size(NB_BANCO_WEB, NB_PLACEHOLDER, $tamano);
        return $ph !== null ? $ph : (NB_BANCO_WEB . NB_PLACEHOLDER);
    }
}

if (!function_exists('url_portada')) {
    /**
     * URL única de portada (tamaño main). Para render_card, mis_contratos, detalle, etc.
     */
    function url_portada(array $row): string {
        return nb_resolver_portada($row, 'main');
    }
}

if (!function_exists('srcset_portada')) {
    /**
     * Las 3 variantes listas para srcset. Para vitrina y listados responsivos.
     * @return array{thumb:string,card:string,main:string}
     */
    function srcset_portada(array $row): array {
        return [
            'thumb' => nb_resolver_portada($row, 'thumb'),
            'card'  => nb_resolver_portada($row, 'card'),
            'main'  => nb_resolver_portada($row, 'main'),
        ];
    }
}

if (!function_exists('path_portada')) {
    /**
     * Ruta FÍSICA (filesystem) del archivo de portada principal (main).
     * Para procesos que LEEN el archivo (ej. generar miniaturas de email), NO para URLs.
     * Prioridad: banco → legacy → placeholder. Devuelve null si nada existe.
     */
    function path_portada(array $row): ?string {
        $docRoot  = nb_doc_root();
        $banco_id = (int)($row['imagen_banco_id'] ?? 0);
        $candidatos = [];

        if ($banco_id > 0) {
            $archivo = $row['banco_archivo'] ?? ($row['archivo'] ?? null);
            if (empty($archivo)) $archivo = nb_lookup_banco_archivo($banco_id);
            if (!empty($archivo)) $candidatos[] = $docRoot . NB_BANCO_WEB . basename($archivo);
        } elseif (!empty($row['imagen'])) {
            $candidatos[] = $docRoot . NB_SERVICIOS_WEB . basename($row['imagen']);
        }
        // Último recurso: placeholder del banco
        $candidatos[] = $docRoot . NB_BANCO_WEB . NB_PLACEHOLDER;

        foreach ($candidatos as $p) {
            if (is_file($p)) return $p;
        }
        return null;
    }
}
