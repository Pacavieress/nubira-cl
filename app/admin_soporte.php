<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/conexion.php';

// FIX ICONOS
if (file_exists(__DIR__ . '/iconos.php')) {
    require_once __DIR__ . '/iconos.php';
} else {
    if (!function_exists('icon')) { function icon($n, $c='') { return "<i class='fa-solid fa-$n $c'></i>"; } }
}

// Solo admins pueden entrar
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /login');
    exit;
}

// Filtrar por estado
$estado = $_GET['estado'] ?? 'pendiente'; // 'pendiente', 'resuelto', 'todos'

$alerta = '';

// Manejar respuesta admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ticket_id'])) {
    $ticket_id = intval($_POST['ticket_id']);
    $respuesta = trim($_POST['respuesta'] ?? '');
    
    if ($respuesta) {
        $stmt = $conn->prepare("UPDATE soporte SET respuesta=?, estado='resuelto', fecha_respuesta=NOW() WHERE id=?");
        $stmt->bind_param("si", $respuesta, $ticket_id);
        if($stmt->execute()) {
            $_SESSION['flash'] = '✅ Respuesta técnica enviada al usuario.';
        }
        $stmt->close();
        header("Location: ?estado=$estado");
        exit;
    } else {
        $_SESSION['flash'] = '❌ Debes escribir una respuesta.';
        header("Location: ?estado=$estado");
        exit;
    }
}

// Flash feedback
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Total pendientes para el badge
$total_pendientes = $conn->query("SELECT COUNT(*) as c FROM soporte WHERE estado='pendiente'")->fetch_assoc()['c'] ?? 0;

// Consulta principal
$where = ($estado === 'todos') ? '' : 'WHERE estado = ?';
$sql = "SELECT s.*, a.nombre AS nombre_usuario, a.correo AS correo_usuario
        FROM soporte s
        JOIN alumnos a ON s.usuario_id = a.id
        $where
        ORDER BY s.fecha_creacion DESC";

$stmt = $conn->prepare($sql);
if ($where) $stmt->bind_param("s", $estado);
$stmt->execute();
$result = $stmt->get_result();
$tickets = [];
while ($row = $result->fetch_assoc()) $tickets[] = $row;
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Admin - Soporte Técnico | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
  </style>
</head>
<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden">

    <?php 
    if(file_exists(__DIR__ . '/componentes/header.php')) require_once __DIR__ . '/componentes/header.php';
    if(file_exists(__DIR__ . '/componentes/sidebar.php')) require_once __DIR__ . '/componentes/sidebar.php';
    ?>

    <main class="pt-20 pb-32 md:pb-16 lg:ml-64 px-4 md:px-8 w-auto">
        <div class="w-full max-w-[1600px] mx-auto">
            
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Soporte Técnico</h1>
                        <?php if ($total_pendientes > 0): ?>
                          <span class="bg-red-500 text-white rounded-full px-2.5 py-0.5 text-[10px] font-bold shadow-sm uppercase tracking-wide animate-pulse">
                              <?= $total_pendientes ?> pendientes
                          </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-gray-500 text-sm mt-1">Resolución de errores de sistema, pagos o bugs técnicos.</p>
                </div>

                <div class="flex bg-white p-1 rounded-xl border border-gray-100 shadow-sm w-full md:w-auto">
                    <?php 
                    $btns = ['pendiente'=>'Pendientes', 'resuelto'=>'Resueltos', 'todos'=>'Todos'];
                    foreach($btns as $k => $l): 
                        $act = ($estado === $k) ? 'bg-blue-50 text-[#54A6D8] font-bold shadow-sm' : 'text-gray-500 hover:bg-gray-50 font-medium';
                    ?>
                    <a href="?estado=<?= $k ?>" class="flex-1 md:flex-none text-center px-4 py-2 rounded-lg text-xs transition-all <?= $act ?>">
                        <?= $l ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="mb-8 rounded-xl px-4 py-3 shadow-sm flex items-center gap-3 <?= strpos($flash,'✅')!==false ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
                    <span class="font-medium text-sm flex-1"><?= htmlspecialchars(str_replace(['✅', '❌'], '', $flash)) ?></span>
                </div>
            <?php endif; ?>

            <div class="space-y-4">
                <?php if (empty($tickets)): ?>
                    <div class="flex flex-col items-center justify-center py-20 text-center bg-white rounded-3xl border border-dashed border-gray-200">
                        <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-4 text-gray-400">
                            <i class="fa-solid fa-check-double text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900">Sistema Estable</h3>
                        <p class="text-gray-500 text-sm mt-1">No hay tickets de soporte técnico en esta categoría.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($tickets as $t): 
                        $es_pendiente = ($t['estado'] === 'pendiente');
                        $badge_estado = $es_pendiente ? 'bg-yellow-100 text-yellow-700 border-yellow-200' : 'bg-green-100 text-green-700 border-green-200';
                        $texto_estado = $es_pendiente ? 'Pendiente' : 'Resuelto';
                    ?>
                    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm hover:shadow-md transition-all p-5 md:p-6 group">
                        
                        <div class="flex flex-col md:flex-row justify-between md:items-start gap-4 mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center font-bold text-[#54A6D8] text-sm border border-blue-100">
                                    <?= strtoupper(substr($t['nombre_usuario'], 0, 1)) ?>
                                </div>
                                <div>
                                    <h3 class="font-bold text-gray-900 text-sm md:text-base">
                                        <?= htmlspecialchars($t['nombre_usuario']) ?> 
                                        <span class="text-xs font-normal text-gray-400 ml-1">(<?= htmlspecialchars($t['correo_usuario']) ?>)</span>
                                    </h3>
                                    <div class="flex items-center gap-2 text-[10px] md:text-xs text-gray-500 mt-0.5">
                                        <span><i class="fa-regular fa-clock mr-1"></i> <?= date('d M Y, H:i', strtotime($t['fecha_creacion'])) ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between w-full md:w-auto gap-3">
                                <span class="<?= $badge_estado ?> border px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wide">
                                    <?= $texto_estado ?>
                                </span>
                            </div>
                        </div>

                        <div class="mb-4">
                            <h4 class="font-bold text-gray-900 text-sm mb-2">Asunto: <?= htmlspecialchars($t['asunto']) ?></h4>
                            <div class="bg-gray-50 p-4 rounded-xl text-gray-700 text-sm leading-relaxed border border-gray-100 font-mono text-[13px]">
                                <?= nl2br(htmlspecialchars($t['mensaje'])) ?>
                            </div>
                        </div>

                        <?php if (!empty($t['respuesta'])): ?>
                            <div class="bg-blue-50/50 border border-blue-100 p-4 rounded-xl mt-4">
                                <div class="flex items-center gap-2 mb-1">
                                    <i class="fa-solid fa-headset text-[#54A6D8] text-xs"></i>
                                    <p class="text-[10px] font-bold text-[#54A6D8] uppercase tracking-widest">Respuesta Técnica Enviada:</p>
                                </div>
                                <p class="text-sm text-blue-900 leading-relaxed"><?= nl2br(htmlspecialchars($t['respuesta'])) ?></p>
                                <?php if (!empty($t['fecha_respuesta'])): ?>
                                    <p class="text-[10px] text-blue-400 font-bold mt-2 text-right">
                                        <i class="fa-solid fa-check"></i> Respondido el <?= date("d/m/y H:i", strtotime($t['fecha_respuesta'])) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($es_pendiente): ?>
                            <form method="post" class="mt-4 pt-4 border-t border-gray-100">
                                <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                                <label class="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-2">Resolución Técnica</label>
                                <textarea name="respuesta" rows="3" placeholder="Describe aquí la solución o la respuesta al problema técnico..." required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:border-[#54A6D8] focus:bg-white outline-none transition-all mb-4 resize-none"></textarea>
                                
                                <div class="flex justify-end items-center">
                                    <button type="submit" class="w-full md:w-auto bg-[#54A6D8] hover:bg-blue-600 text-white font-bold py-2.5 px-6 rounded-xl text-sm transition-transform active:scale-95 shadow-md shadow-blue-100 flex items-center justify-center gap-2">
                                        <i class="fa-solid fa-paper-plane"></i> Enviar Resolución
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>

                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </main>

    <?php 
    if(file_exists(__DIR__ . '/componentes/nav_bottom.php')) {
        require_once __DIR__ . '/componentes/nav_bottom.php'; 
    }
    ?>

</body>
</html>