<?php
// app/sitemap.php — Sitemap dinámico de Nubira (paso #3 SEO)
// Servido en https://nubira.cl/sitemap.xml (ver RewriteRule en .htaccess).
// TODO: si supera ~45.000 URLs, dividir en sitemaps por tipo + sitemap index
//       (límite oficial: 50.000 URLs / 50MB sin comprimir).
// TODO: si la BD crece, cachear 6h (file-based, patrón app/cache_ia/).
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/seguridad_url.php';
require_once __DIR__ . '/helpers/seo.php';   // nubira_categorias_seo() para sección E
header('Content-Type: application/xml; charset=utf-8');

$BASE = 'https://nubira.cl';

// Fecha en formato W3C Datetime (ISO 8601), normalizada a UTC (+00:00).
// La BD guarda en hora Chile (conexion.php fija time_zone), así que se interpreta
// como local y se convierte a UTC. Sin fecha => ahora.
function w3c(?string $f): string {
    $dt = ($f !== null && $f !== '') ? new DateTime($f) : new DateTime('now');
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d\TH:i:sP');
}

function url_xml(string $loc, string $lastmod, string $freq, string $prio): string {
    return "  <url>\n"
         . "    <loc>" . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . "</loc>\n"
         . "    <lastmod>$lastmod</lastmod>\n"
         . "    <changefreq>$freq</changefreq>\n"
         . "    <priority>$prio</priority>\n"
         . "  </url>\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// A. Páginas estáticas
$estaticas = [
    ['/',                '1.0', 'daily'],
    ['/explorar',        '0.9', 'daily'],
    ['/apuntes',         '0.9', 'daily'],
    ['/servicios',       '0.9', 'daily'],
    ['/descubre',        '0.7', 'weekly'],
    ['/sobre-nosotros',  '0.4', 'monthly'],
    ['/terminos',        '0.2', 'monthly'],
    ['/privacidad',      '0.2', 'monthly'],
];
foreach ($estaticas as [$p, $prio, $freq]) {
    echo url_xml($BASE . $p, w3c(null), $freq, $prio);
}

// B. Servicios públicos (mismo filtro que app/cargar_servicios.php)
$sql = "SELECT s.id, s.slug, s.fecha_publicacion
        FROM servicios s
        JOIN alumnos a ON a.id = s.alumno_id
        WHERE TRIM(LOWER(s.estado)) IN ('aprobado','publicado','activo')
          AND s.visible = 1
          AND COALESCE(a.visible, 1) = 1";
$r = $conn->query($sql);
while ($r && $row = $r->fetch_assoc()) {
    echo url_xml($BASE . url_servicio((int)$row['id'], $row['slug'] ?? null),
                 w3c($row['fecha_publicacion']), 'weekly', '0.7');
}

// C. Apuntes públicos (mismo filtro que app/cargar_apuntes.php)
$sql = "SELECT ap.id, ap.fecha_subida
        FROM apuntes ap
        JOIN alumnos al ON al.id = ap.id_alumno
        WHERE ap.publico = 1
          AND ap.visible = 1
          AND al.visible = 1";
$r = $conn->query($sql);
while ($r && $row = $r->fetch_assoc()) {
    echo url_xml($BASE . '/apunte/' . nubira_encriptar_id($row['id']),
                 w3c($row['fecha_subida']), 'weekly', '0.7');
}

// E. Landings de categoría (clases) — solo las que tienen >=3 servicios públicos
foreach (nubira_categorias_seo() as $slug => $nombre) {
    $st = $conn->prepare("SELECT COUNT(*) n FROM servicios s
                          JOIN alumnos a ON a.id = s.alumno_id
                          WHERE TRIM(LOWER(s.estado)) IN ('aprobado','publicado','activo')
                            AND s.visible = 1
                            AND COALESCE(a.visible, 1) = 1
                            AND s.categoria = ?");
    if (!$st) continue;
    $st->bind_param("s", $nombre);
    $st->execute();
    $n = (int)($st->get_result()->fetch_assoc()['n']);
    $st->close();
    if ($n >= 3) {
        echo url_xml($BASE . '/clases/' . $slug, w3c(null), 'weekly', '0.8');
    }
}

echo '</urlset>' . "\n";
