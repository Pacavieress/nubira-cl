<?php
declare(strict_types=1);

session_start();
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/conexion.php';
date_default_timezone_set('America/Santiago');


$pagina = max(1, intval($_GET['pagina'] ?? 1));
$limite = min(max(intval($_GET['limit'] ?? 8), 1), 30);
$offset = ($pagina - 1) * $limite;

$debug = !empty($_GET['debug']);


$filtros = ["aprobado = 1"];  
$params  = [];
$tipos   = "";

$institucion_param = trim($_GET['institucion'] ?? '');
$ver_todas         = !empty($_GET['ver_todas']); 


if (!$ver_todas && $institucion_param !== '') {
    $filtros[] = "institucion = ?";
    $params[]  = $institucion_param;
    $tipos    .= "s";
}

$buscar = trim($_GET['q'] ?? $_GET['buscar'] ?? '');
if ($buscar !== '') {
    $filtros[] = "(titulo LIKE ? OR descripcion LIKE ? OR organizador LIKE ? OR area LIKE ? OR ciudad LIKE ?)";
    $like = '%' . $buscar . '%';
    array_push($params, $like, $like, $like, $like, $like);
    $tipos .= "sssss";
}


foreach (['tipo','organizador','modalidad','ciudad','area'] as $f) {
    if (!empty($_GET[$f])) {
        $filtros[] = "$f LIKE ?";
        $params[]  = '%' . trim($_GET[$f]) . '%';
        $tipos    .= "s";
    }
}


$modo = $_GET['modo'] ?? 'default';

// 🔹 Ordenar siempre del más nuevo al más antiguo
$modo = $_GET['modo'] ?? 'recientes';

switch ($modo) {
    case 'termina_pronto':
        $filtros[] = "fecha_termino >= NOW() AND fecha_termino <= DATE_ADD(NOW(), INTERVAL 2 DAY)";
        $orderBy   = "fecha_termino ASC, id ASC";
        break;

    // 🔹 Cualquier otro modo (semana, default, recientes, etc.)
    default:
        $orderBy = "fecha_publicacion DESC, id DESC";
        break;
}



$sql = "
    SELECT
        id, titulo, tipo, organizador, fecha_inicio, fecha_termino, estado,
        descripcion, institucion, imagen, fecha_publicacion
    FROM oportunidades
    WHERE " . implode(" AND ", $filtros) . "
    ORDER BY {$orderBy}
    LIMIT ? OFFSET ?
";

$params[] = $limite;
$params[] = $offset;
$tipos   .= "ii";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit('Error en la consulta: ' . $conn->error);
}
$stmt->bind_param($tipos, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$oportunidades = [];
while ($row = $res->fetch_assoc()) $oportunidades[] = $row;
$stmt->close();

if (empty($oportunidades)) {
    http_response_code(204);
    exit;
}


function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function img_url(?string $p): string {
    $p = trim((string)$p);
    $ruta = __DIR__ . '/upload/oportunidades/' . $p;
    if ($p === '' || !file_exists($ruta)) return '/upload/oportunidades/default.webp';
    return '/upload/oportunidades/' . ltrim($p, '/');
}




foreach ($oportunidades as $row):
    $id        = (int)$row['id'];
    $titulo    = h($row['titulo']);
    $tipo      = h($row['tipo']);
    $org       = h($row['organizador']);
    $fi        = !empty($row['fecha_inicio'])  ? date('d/m/Y', strtotime($row['fecha_inicio']))  : '';
    $ft        = !empty($row['fecha_termino']) ? date('d/m/Y', strtotime($row['fecha_termino'])) : '';
    $desc      = strip_tags((string)($row['descripcion'] ?? ''));
    $instTx    = h(strtoupper($row['institucion']));
    $imgUrl    = img_url($row['imagen'] ?? '');
?>

<a href="/detalle-oportunidad/<?= $id ?>"
   class="block bg-white rounded-xl shadow-md flex flex-col h-full min-h-[180px]
          w-full max-w-[340px] mx-auto mb-4 transition hover:shadow-lg hover:-translate-y-1
          border border-gray-300 cursor-pointer">

  

  <div class="relative aspect-[16/10] bg-gray-100 rounded-t-lg overflow-hidden">
    <img src="<?= h($imgUrl) ?>"
         alt="Imagen de <?= $titulo ?>"
         class="w-full h-full object-cover"
         draggable="false" oncontextmenu="return false">
    <div class="absolute top-2 left-2 flex flex-wrap gap-2">
      <?php if ($instTx): ?>
        <span class="inline-block bg-blue-100 text-blue-800 text-[10px] font-semibold px-1.5 py-0.5 rounded">
          <?= $instTx ?>
        </span>
      <?php endif; ?>
      <span class="inline-block bg-orange-100 text-orange-700 text-[10px] font-bold px-1.5 py-0.5 rounded">
        <?= ucfirst($tipo) ?>
      </span>
    </div>
  </div>


  <div class="p-3 flex flex-col justify-between text-sm flex-1">

    <h6 class="font-semibold text-left leading-snug mb-1 line-clamp-2 break-words">
      <?= $titulo ?>
    </h6>


    <?php if ($org): ?>
      <p class="text-gray-500 text-xs truncate">
        Organiza: <span class="font-medium"><?= $org ?></span>
      </p>
    <?php endif; ?>

    <p class="text-gray-400 text-[10px] mt-1">
      <?= ($fi && $ft) ? "Del {$fi} al {$ft}" : ($fi ? "Desde {$fi}" : ($ft ? "Hasta {$ft}" : "")) ?>
    </p>
  </div>
</a>


<?php endforeach; ?>
