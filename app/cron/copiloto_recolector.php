<?php
/**
 * CRON: COPILOTO DE MARKETING — RECOLECTOR DE SEÑALES (NUBIRA 2.0)
 *
 * Frecuencia recomendada: 1 vez al día (madrugada)
 * Ubicación: /app/cron/copiloto_recolector.php
 *
 * Fase 1, Pieza 1 del Copiloto de Marketing: SOLO calcula señales de negocio
 * y guarda un snapshot diario en copiloto_snapshots (UPSERT por fecha, no
 * duplica si el cron corre 2 veces el mismo día). NO llama a Gemini, NO
 * genera ningún brief — eso es una pieza posterior.
 */

// Solo permitir ejecución por CLI o por Hostinger (no acceso web sin secret)
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_secret'])) {
    http_response_code(403);
    die('Forbidden');
}

require_once dirname(__DIR__) . '/env_loader.php';

// Token anti-acceso web no autorizado (para disparo manual vía URL en prod)
$CRON_SECRET = getenv('CRON_COPILOTO_SECRET') ?: '';
if (php_sapi_name() !== 'cli' && ($_GET['cron_secret'] ?? '') !== $CRON_SECRET) {
    http_response_code(403);
    die('Forbidden');
}

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('memory_limit', '256M');
set_time_limit(120);

date_default_timezone_set('America/Santiago');

$app_dir = dirname(__DIR__); // sube de /app/cron/ a /app/
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/helpers/gemini.php';

// Logging
$log_file = __DIR__ . '/logs/copiloto_recolector.log';
function log_cron($msg) {
    global $log_file;
    file_put_contents($log_file, date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND);
}

log_cron("=== INICIO cron copiloto_recolector ===");

// -----------------------------------------------------------------------
// 0. TABLA DE SNAPSHOTS (auto-migración, mismo criterio que 'novedades' en
//    admin_marketing_cards.php: nunca asumir que otro archivo ya la creó)
// -----------------------------------------------------------------------
$conn->query("CREATE TABLE IF NOT EXISTS copiloto_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    dormidos_total INT NOT NULL DEFAULT 0,
    leads_sin_contactar INT NOT NULL DEFAULT 0,
    contratos_7d INT NOT NULL DEFAULT 0,
    contratos_30d INT NOT NULL DEFAULT 0,
    monto_contratos_30d DECIMAL(12,2) NOT NULL DEFAULT 0,
    oferta_por_categoria JSON NULL,
    demanda_vistas_por_categoria JSON NULL,
    busquedas_fallidas_top JSON NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Columnas del brief (Pieza 2) — ALTER separado del CREATE porque la tabla ya
// existe desde la Pieza 1 en instalaciones que corrieron el cron antes de hoy.
// try/catch: en PHP 8.1+ mysqli lanza mysqli_sql_exception por defecto ante
// "Duplicate column" en reejecuciones — mismo patrón que completar_perfil.php
// y helpers/comprador_invitado.php para ALTER TABLE idempotentes.
try { $conn->query("ALTER TABLE copiloto_snapshots ADD COLUMN brief_texto TEXT NULL DEFAULT NULL"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE copiloto_snapshots ADD COLUMN brief_generado_en DATETIME NULL DEFAULT NULL"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE copiloto_snapshots ADD COLUMN brief_error TEXT NULL DEFAULT NULL"); } catch (Throwable $e) {}

// -----------------------------------------------------------------------
// 1. DORMIDOS — alumnos confirmados inactivos.
//    Mismo criterio base que enviar_despertar_dormidos.php:154-172
//    (consistencia con la campaña real), CON UNA DIFERENCIA DELIBERADA:
//    NO excluimos por correos_admin.admin_nombre='despertar_dormidos_jun2026',
//    porque esa exclusión es específica de UNA campaña puntual (a quién ya
//    se le mandó ESE correo), no de la señal de negocio "cuántos alumnos
//    están dormidos hoy" — usarla acá haría bajar el número con cada envío
//    de esa campaña, sin que eso signifique que el alumno se reactivó.
//    APROXIMACIÓN, NO CONTEO EXACTO: el NOT EXISTS contra contratos solo
//    mira comprador_id, así que un alumno que SOLO vendió (nunca compró)
//    puede colarse como "dormido" aunque esté activo vendiendo.
// -----------------------------------------------------------------------
$sql_dormidos = "
    SELECT COUNT(*) AS n
    FROM alumnos a
    WHERE a.visible = 1
      AND a.bloqueado = 0
      AND a.confirmado = 1
      AND a.recibir_emails = 1
      AND a.id != 1
      AND a.correo NOT LIKE 'testpablo%'
      AND DATEDIFF(NOW(), a.fecha_registro) >= 31
      AND NOT EXISTS (SELECT 1 FROM servicios s WHERE s.alumno_id = a.id)
      AND NOT EXISTS (SELECT 1 FROM contratos c WHERE c.comprador_id = a.id)
      AND NOT EXISTS (SELECT 1 FROM apuntes ap WHERE ap.id_alumno = a.id)
";
$dormidos_total = (int)($conn->query($sql_dormidos)->fetch_assoc()['n'] ?? 0);

// -----------------------------------------------------------------------
// 2. LEADS SIN CONTACTAR — interesados_registro sin NINGÚN correo de
//    campaña exitoso registrado en correos_admin (sin filtrar por campaña
//    específica, a diferencia del punto 1 — acá SÍ es "nunca se le
//    contactó por ninguna campaña", que es la señal real pedida).
//    NOT EXISTS en vez de NOT IN: correos_admin.destinatario es nullable,
//    y un NOT IN contra una subquery con algún NULL adentro devuelve NULL
//    (falso) para TODAS las filas — el bug clásico de MySQL/NULL en NOT IN.
// -----------------------------------------------------------------------
$sql_leads = "
    SELECT COUNT(*) AS n
    FROM interesados_registro ir
    WHERE NOT EXISTS (
        SELECT 1 FROM correos_admin ca
        WHERE ca.exito = 1
          AND LOWER(TRIM(ca.destinatario)) = LOWER(TRIM(ir.correo))
    )
";
$leads_sin_contactar = (int)($conn->query($sql_leads)->fetch_assoc()['n'] ?? 0);

// -----------------------------------------------------------------------
// 3. CONVERSIÓN RECIENTE — contratos creados en los últimos 7/30 días +
//    monto acordado de los últimos 30. Sin filtro de estado (incluye
//    'cancelado'): mide entradas al funnel de conversión, no solo cierres
//    exitosos. Si más adelante se quiere medir solo conversión "limpia",
//    agregar AND estado != 'cancelado'.
// -----------------------------------------------------------------------
$sql_contratos = "
    SELECT
        COALESCE(SUM(fecha_creacion >= NOW() - INTERVAL 7  DAY), 0) AS c7,
        COALESCE(SUM(fecha_creacion >= NOW() - INTERVAL 30 DAY), 0) AS c30,
        COALESCE(SUM(CASE WHEN fecha_creacion >= NOW() - INTERVAL 30 DAY THEN monto_acordado ELSE 0 END), 0) AS monto30
    FROM contratos
";
$row_contratos        = $conn->query($sql_contratos)->fetch_assoc();
$contratos_7d          = (int)$row_contratos['c7'];
$contratos_30d         = (int)$row_contratos['c30'];
$monto_contratos_30d   = (float)$row_contratos['monto30'];

// -----------------------------------------------------------------------
// 4. OFERTA POR CATEGORÍA — servicios aprobados + visibles.
// -----------------------------------------------------------------------
$oferta_por_categoria = [];
$res = $conn->query("
    SELECT categoria, COUNT(*) AS n
    FROM servicios
    WHERE estado = 'aprobado' AND COALESCE(visible, 1) = 1
    GROUP BY categoria
    ORDER BY n DESC
");
while ($r = $res->fetch_assoc()) {
    $oferta_por_categoria[$r['categoria']] = (int)$r['n'];
}

// -----------------------------------------------------------------------
// 5. DEMANDA — vistas en vistas_detalle (últimos 30 días), separadas por
//    tipo de publicación (servicio/apunte) y categoría de esa publicación.
// -----------------------------------------------------------------------
$demanda_vistas_por_categoria = ['servicio' => [], 'apunte' => []];

$res = $conn->query("
    SELECT s.categoria, COUNT(*) AS n
    FROM vistas_detalle v
    INNER JOIN servicios s ON s.id = v.publicacion_id
    WHERE v.tipo = 'servicio' AND v.fecha_inicio >= NOW() - INTERVAL 30 DAY
    GROUP BY s.categoria
    ORDER BY n DESC
");
while ($r = $res->fetch_assoc()) {
    $demanda_vistas_por_categoria['servicio'][$r['categoria']] = (int)$r['n'];
}

$res = $conn->query("
    SELECT ap.categoria, COUNT(*) AS n
    FROM vistas_detalle v
    INNER JOIN apuntes ap ON ap.id = v.publicacion_id
    WHERE v.tipo = 'apunte' AND v.fecha_inicio >= NOW() - INTERVAL 30 DAY
    GROUP BY ap.categoria
    ORDER BY n DESC
");
while ($r = $res->fetch_assoc()) {
    $cat = $r['categoria'] ?? 'Sin categoría'; // apuntes.categoria es nullable
    $demanda_vistas_por_categoria['apunte'][$cat] = (int)$r['n'];
}

// -----------------------------------------------------------------------
// 6. BÚSQUEDAS FALLIDAS — top 15 términos (últimos 30 días).
// -----------------------------------------------------------------------
$busquedas_fallidas_top = [];
$res = $conn->query("
    SELECT termino, COUNT(*) AS n
    FROM busquedas_fallidas
    WHERE fecha >= NOW() - INTERVAL 30 DAY
    GROUP BY termino
    ORDER BY n DESC
    LIMIT 15
");
while ($r = $res->fetch_assoc()) {
    $busquedas_fallidas_top[$r['termino']] = (int)$r['n'];
}

// -----------------------------------------------------------------------
// 7. UPSERT DEL SNAPSHOT DEL DÍA
// -----------------------------------------------------------------------
$fecha_hoy  = date('Y-m-d');
$json_flags = JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT;

$oferta_json    = json_encode($oferta_por_categoria, $json_flags);
$demanda_json   = json_encode($demanda_vistas_por_categoria, $json_flags);
$busquedas_json = json_encode($busquedas_fallidas_top, $json_flags);

$stmt = $conn->prepare("
    INSERT INTO copiloto_snapshots
        (fecha, dormidos_total, leads_sin_contactar, contratos_7d, contratos_30d,
         monto_contratos_30d, oferta_por_categoria, demanda_vistas_por_categoria, busquedas_fallidas_top)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        dormidos_total = VALUES(dormidos_total),
        leads_sin_contactar = VALUES(leads_sin_contactar),
        contratos_7d = VALUES(contratos_7d),
        contratos_30d = VALUES(contratos_30d),
        monto_contratos_30d = VALUES(monto_contratos_30d),
        oferta_por_categoria = VALUES(oferta_por_categoria),
        demanda_vistas_por_categoria = VALUES(demanda_vistas_por_categoria),
        busquedas_fallidas_top = VALUES(busquedas_fallidas_top)
");

$stmt->bind_param(
    'siiiidsss',
    $fecha_hoy,
    $dormidos_total,
    $leads_sin_contactar,
    $contratos_7d,
    $contratos_30d,
    $monto_contratos_30d,
    $oferta_json,
    $demanda_json,
    $busquedas_json
);
$stmt->execute();
$stmt->close();

// -----------------------------------------------------------------------
// 8. GENERACIÓN DEL BRIEF (Gemini) — best-effort, NUNCA rompe el cron.
//    El snapshot de arriba ya quedó guardado pase lo que pase acá abajo.
//    Solo se pasan señales AGREGADAS (conteos, categorías, términos) —
//    nunca correos, nombres ni filas de usuarios individuales.
// -----------------------------------------------------------------------
$monto_30d_fmt = number_format($monto_contratos_30d, 0, ',', '.');

$prompt_brief = <<<PROMPT
Eres un analista de marketing para Nubira, un marketplace chileno donde estudiantes universitarios contratan tutores particulares (clases) y compran apuntes/resúmenes de otros estudiantes. Moneda: pesos chilenos (CLP).

PRIORIDAD DEL NEGOCIO HOY: 1) ADQUISICIÓN (traer usuarios nuevos) y 2) CONVERSIÓN (que los usuarios existentes contraten o compren). No optimices por otras métricas.

Estas son las señales de negocio de HOY ({$fecha_hoy}), ya agregadas (NUNCA recibes correos, nombres ni datos de usuarios individuales):

- Alumnos "dormidos" (registrados hace 31+ días, sin ninguna publicación ni compra): {$dormidos_total}
- Leads sin contactar (interesados que dejaron su correo pero nunca recibieron ninguna campaña): {$leads_sin_contactar}
- Contratos creados en los últimos 7 días: {$contratos_7d}
- Contratos creados en los últimos 30 días: {$contratos_30d}
- Monto acordado total de esos contratos de 30 días: \${$monto_30d_fmt} CLP
- Oferta de servicios aprobados y visibles, por categoría: {$oferta_json}
- Vistas de detalle de los últimos 30 días, por categoría y tipo (servicio/apunte): {$demanda_json}
- Top términos buscados sin resultados en los últimos 30 días: {$busquedas_json}

LIMITACIONES DE LOS DATOS QUE DEBES RESPETAR:
- "Contratos" incluye TODOS los estados, incluido 'cancelado' — son entradas al funnel de conversión, no ventas cerradas confirmadas. No los trates como ingreso garantizado.
- "Dormidos" es una aproximación: un alumno que solo vendió (nunca compró) puede aparecer acá aunque esté activo. No lo presentes como un número exacto.
- Si una señal viene vacía o en cero (por ejemplo un objeto vacío {}), NO inventes demanda ni tendencias que los datos no muestran — dilo explícitamente como "sin datos suficientes esta semana" en vez de rellenar con una suposición.

QUÉ HERRAMIENTAS YA TIENE NUBIRA PARA ACTUAR (sugiere acciones ejecutables con esto, no herramientas que no existen):
- Campaña de correo a alumnos dormidos.
- Campaña de correo a leads sin contactar.
- Generador de carrusel de imágenes de servicios para redes sociales (Instagram/Facebook), por categoría.
- Publicación de novedades/anuncios de plataforma con imagen generada.

TAREA: Escribe un brief ejecutivo breve, en español de Chile neutro (trato de "tú", nunca "vos"), con tono de analista de marketing: directo, sin relleno, sin frases motivacionales genéricas. Sigue este formato EXACTO — se renderiza automáticamente como HTML, así que el formato importa:

1. Un párrafo único de diagnóstico (2 a 3 frases), sin viñetas ni numeración, basado SOLO en las señales de arriba.
2. Una línea en blanco.
3. Entre 3 y 5 acciones concretas, priorizadas de más a menos urgente, en una lista numerada (1., 2., 3., ...), UNA acción por línea, cada una con este formato exacto: "N. **Nombre corto de la acción** — Por qué, citando el dato concreto que la justifica". Usa **negrita** en Markdown SOLO alrededor del nombre corto de la acción, en ningún otro lugar del texto.

No agregues introducción, títulos, viñetas con guion (-) ni cierre genérico. No uses ningún otro formato Markdown (nada de #, listas con -, backticks, etc.) fuera de la negrita indicada. Empieza directo con el párrafo de diagnóstico.
PROMPT;

$resultado_ia = nb_gemini_generar($prompt_brief, [
    'temperature'   => 0.6,
    'response_json' => false,
    'timeout'       => 30,
]);

$brief_ok = $resultado_ia['ok'] && !empty(trim($resultado_ia['texto'] ?? ''));

if ($brief_ok) {
    $brief_texto = trim($resultado_ia['texto']);
    $stmt_brief = $conn->prepare("UPDATE copiloto_snapshots SET brief_texto = ?, brief_generado_en = NOW(), brief_error = NULL WHERE fecha = ?");
    $stmt_brief->bind_param('ss', $brief_texto, $fecha_hoy);
    $stmt_brief->execute();
    $stmt_brief->close();
    log_cron("Brief OK (" . mb_strlen($brief_texto) . " caracteres)");
} else {
    $brief_error = $resultado_ia['error'] ?? 'Gemini no devolvió texto';
    $stmt_brief = $conn->prepare("UPDATE copiloto_snapshots SET brief_error = ? WHERE fecha = ?");
    $stmt_brief->bind_param('ss', $brief_error, $fecha_hoy);
    $stmt_brief->execute();
    $stmt_brief->close();
    log_cron("Brief FALLÓ (snapshot igual quedó guardado): " . $brief_error);
}

// -----------------------------------------------------------------------
// 9. RESUMEN
// -----------------------------------------------------------------------
$resumen = sprintf(
    "Snapshot %s | dormidos=%d | leads_sin_contactar=%d | contratos_7d=%d | contratos_30d=%d | monto_30d=%s | categorias_oferta=%d | categorias_demanda_servicio=%d | categorias_demanda_apunte=%d | busquedas_top=%d | brief=%s",
    $fecha_hoy,
    $dormidos_total,
    $leads_sin_contactar,
    $contratos_7d,
    $contratos_30d,
    $monto_30d_fmt,
    count($oferta_por_categoria),
    count($demanda_vistas_por_categoria['servicio']),
    count($demanda_vistas_por_categoria['apunte']),
    count($busquedas_fallidas_top),
    $brief_ok ? 'OK' : 'ERROR'
);

log_cron($resumen);
log_cron("=== FIN cron copiloto_recolector ===");
log_cron("");

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain');
    echo "OK | $resumen\n";
} else {
    echo $resumen . PHP_EOL;
}
