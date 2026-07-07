<?php
// app/cargar_descubre.php (versión tolerante)
// Devuelve JSON para Descubre (apuntes/servicios/oportunidades)
// - Relaja filtros de estado/aprobado
// - Excluye lo ya visto
// - Ordena por relevancia sencilla
// - Soporta filtros de asignatura/semestre/tipos

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['usuario_id'])) {
  http_response_code(401);
  echo json_encode([]);
  exit;
}

$usuario_id   = (int)($_SESSION['usuario_id'] ?? 0);
$inst_usuario = trim((string)($_SESSION['institucion'] ?? ''));
$sem_usuario  = trim((string)($_SESSION['semestre'] ?? ''));
$carrera      = trim((string)($_SESSION['carrera'] ?? ''));

// Inputs
$pagina      = max(1, (int)($_POST['pagina'] ?? 1));
$limite      = 10;
$offset      = ($pagina - 1) * $limite;

$asig_filter = trim((string)($_POST['asignatura'] ?? ''));
$sem_filter  = trim((string)($_POST['semestre'] ?? ''));
$tipos       = $_POST['tipos'] ?? ['apunte','servicio'];
if (!is_array($tipos) || empty($tipos)) $tipos = ['apunte','servicio'];

$asig_score_pattern = $asig_filter !== '' ? $asig_filter : $carrera;

// Helpers
function q($conn, $sql, $types = '', $params = []) {
  $stmt = $conn->prepare($sql);
  if (!$stmt) { throw new Exception("Prepare failed: " . $conn->error); }
  if ($types && $params) { $stmt->bind_param($types, ...$params); }
  if (!$stmt->execute()) { throw new Exception("Execute failed: " . $stmt->error); }
  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) $rows[] = $r;
  $stmt->close();
  return $rows;
}
function url_desbloqueo($tipo, $id) {
  return "/pago/desbloquear.php?tipo=" . rawurlencode($tipo) . "&id=" . rawurlencode($id);
}
function map_item($r, $tipo) {
  $img = trim((string)($r['_img'] ?? ''));
  return [
    'id'            => (int)$r['_id'],
    'tipo'          => $tipo,
    'titulo'        => $r['_titulo'] ?? '',
    'descripcion'   => $r['_desc'] ?? '',
    'imagen'        => $img ?: 'https://picsum.photos/800/480',
    'premium'       => (int)($r['_precio'] ?? 0) > 0,
    'precio'        => isset($r['_precio']) ? (int)$r['_precio'] : null,
    'asignatura'    => $r['_asig'] ?? '',
    'semestre'      => $r['_sem'] ?? '',
    'institucion'   => $r['_inst'] ?? '',
    'url_desbloqueo'=> url_desbloqueo($tipo, (int)$r['_id']),
    '_score'        => (int)($r['_score'] ?? 0),
    '_fecha'        => $r['_fecha'] ?? null,
  ];
}

$items = [];
try {
  $limit_pool = $limite * 3;
  $offset_pool = 0;

  // =============== APUNTES ===============
  if (in_array('apunte', $tipos, true)) {
    // Campos reales en tu BD (ajustados a lo que compartiste)
    // apuntes: id, titulo, descripcion, asignatura, semestre, precio, institucion, fecha_subida, publico, preview
    $sqlA = "
      SELECT
        a.id             AS _id,
        a.titulo         AS _titulo,
        a.descripcion    AS _desc,
        a.asignatura     AS _asig,
        a.semestre       AS _sem,
        a.precio         AS _precio,
        a.institucion    AS _inst,
        a.fecha_subida   AS _fecha,
        a.preview        AS _img,
        (CASE WHEN a.institucion = ? THEN 2 ELSE 0 END)
        + (CASE WHEN ? = '' OR a.semestre IS NULL THEN 0
                WHEN CAST(a.semestre AS CHAR) = ? THEN 1 ELSE 0 END)
        + (CASE WHEN ? = '' THEN 0
                WHEN a.asignatura LIKE CONCAT('%', ?, '%') THEN 1 ELSE 0 END)
        AS _score
      FROM apuntes a
      WHERE a.publico = 1
        AND (? = '' OR a.asignatura LIKE CONCAT('%', ?, '%'))
        AND (? = '' OR CAST(a.semestre AS CHAR) = ?)
        AND NOT EXISTS (
          SELECT 1 FROM interacciones_descubre i
          WHERE i.usuario_id = ?
            AND i.item_id = a.id
            AND i.tipo = 'apunte'
        )
      ORDER BY _score DESC, a.fecha_subida DESC
      LIMIT ?, ?
    ";
    $rows = q(
      $conn, $sqlA,
      'ssssssssssiii',
      [
        $inst_usuario,
        $sem_usuario, $sem_usuario,
        $asig_score_pattern, $asig_score_pattern,
        $asig_filter, $asig_filter,
        $sem_filter, $sem_filter,
        $usuario_id,
        $offset_pool, $limit_pool
      ]
    );
    foreach ($rows as $r) $items[] = map_item($r, 'apunte');
  }

  // =============== SERVICIOS ===============
  if (in_array('servicio', $tipos, true)) {
    // servicios: id, titulo, descripcion, institucion, precio, estado, fecha_publicacion (según tu dump)
    // Relajamos estado: permitimos NULL, '', 'aprobado', 'Aprobado', 'publicado', 'activo'
    $sqlS = "
      SELECT
        s.id                 AS _id,
        s.titulo             AS _titulo,
        s.descripcion        AS _desc,
        ''                   AS _asig,
        ''                   AS _sem,
        s.precio             AS _precio,
        s.institucion        AS _inst,
        s.fecha_publicacion  AS _fecha,
        NULL                 AS _img,
        (CASE WHEN s.institucion = ? THEN 2 ELSE 0 END) AS _score
      FROM servicios s
      WHERE
        (s.estado IS NULL OR s.estado IN ('aprobado','Aprobado','publicado','Publicada','activo','Activo','Aprobada','aprobada',''))
        AND NOT EXISTS (
          SELECT 1 FROM interacciones_descubre i
          WHERE i.usuario_id = ?
            AND i.item_id = s.id
            AND i.tipo = 'servicio'
        )
      ORDER BY COALESCE(s.fecha_publicacion, NOW()) DESC
      LIMIT ?, ?
    ";
    $rows = q(
      $conn, $sqlS,
      'siii',
      [
        $inst_usuario,
        $usuario_id,
        $offset_pool, $limit_pool
      ]
    );
    foreach ($rows as $r) $items[] = map_item($r, 'servicio');
  }

  // Mezcla + orden por score y fecha
  usort($items, function($a, $b) {
    if ($a['_score'] === $b['_score']) {
      return strcmp((string)$b['_fecha'], (string)$a['_fecha']);
    }
    return $b['_score'] <=> $a['_score'];
  });

  // Pagina
  $items = array_slice($items, $offset, $limite);

} catch (Throwable $e) {
  error_log("cargar_descubre error: " . $e->getMessage());
}

// Fallback visual si quedó vacío (para comprobar UI)
if (empty($items)) {
  $items = [
    ['id'=>1,'tipo'=>'servicio','titulo'=>'Tutoría Estructuras de Datos','descripcion'=>'Refuerzo 1:1 (C++/Java).','imagen'=>'','premium'=>true,'precio'=>5000,'asignatura'=>'','semestre'=>'','institucion'=>$inst_usuario,'url_desbloqueo'=>url_desbloqueo('servicio',1)],
    ['id'=>2,'tipo'=>'oportunidad','titulo'=>'Beca de Conectividad Estudiantil','descripcion'=>'Postula este mes.','imagen'=>'','premium'=>false,'precio'=>null,'asignatura'=>'','semestre'=>'','institucion'=>$inst_usuario,'url_desbloqueo'=>url_desbloqueo('oportunidad',2)],
  ];
}

echo json_encode($items, JSON_UNESCAPED_UNICODE);
