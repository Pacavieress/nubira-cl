<?php
/**
 * VISTA: CONFIGURAR DISPONIBILIDAD (HORARIOS DEL TUTOR)
 * ESTADO: FINAL - UI Nubira 2.0 (Flat / Sin Sombras)
 */
session_start();

// 1. Inclusión de dependencias seguras
$ruta_raiz = __DIR__;
require_once $ruta_raiz . '/conexion.php'; 

// Importar Iconos
if (file_exists($ruta_raiz . '/iconos.php')) {
    require_once $ruta_raiz . '/iconos.php';
}

// IMPORTANTE: Importar seguridad_url
if (file_exists($ruta_raiz . '/seguridad_url.php')) {
    require_once $ruta_raiz . '/seguridad_url.php';
} elseif (file_exists(dirname($ruta_raiz) . '/seguridad_url.php')) {
    require_once dirname($ruta_raiz) . '/seguridad_url.php';
}

if (!function_exists('nubira_encriptar_id')) {
    function nubira_encriptar_id($id) { return $id; }
}

// 2. Verificación de sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login?redir=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
$uid = (int)$_SESSION['usuario_id'];

// 3. Validación de Servicio
$servicio_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($servicio_id === 0) { die("ID de servicio no válido."); }

$stmt = $conn->prepare("SELECT titulo, horarios_json, alumno_id FROM servicios WHERE id = ?");
$stmt->bind_param("i", $servicio_id);
$stmt->execute();
$servicio = $stmt->get_result()->fetch_assoc();

if (!$servicio || $servicio['alumno_id'] != $uid) {
    http_response_code(403);
    die("Acceso denegado. No eres el propietario de este servicio.");
}

// 4. Lógica POST
$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['horarios_json'])) {
    $nuevo_json = trim($_POST['horarios_json']);
    
    json_decode($nuevo_json);
    if (json_last_error() === JSON_ERROR_NONE) {
        $upd = $conn->prepare("UPDATE servicios SET horarios_json = ? WHERE id = ? AND alumno_id = ?");
        $upd->bind_param("sii", $nuevo_json, $servicio_id, $uid);
        if ($upd->execute()) {
            $mensaje = 'ok';
            $servicio['horarios_json'] = $nuevo_json; 
        } else {
            $mensaje = 'error';
        }
    } else {
        $mensaje = 'error_json';
    }
}

// 5. Preparar datos para la UI
$horarios_db = !empty($servicio['horarios_json']) ? json_decode($servicio['horarios_json'], true) : [];
if (!is_array($horarios_db)) $horarios_db = [];

$dias_semana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Horarios | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f9fafb; }
        
        /* Switch toggle tipo iOS */
        .toggle-checkbox:checked { right: 0; border-color: #34C759; }
        .toggle-checkbox:checked + .toggle-label { background-color: #34C759; }
        .toggle-checkbox { right: 0; z-index: 1; border-color: #e2e8f0; transition: all 0.3s; }
        .toggle-label { width: 3rem; height: 1.5rem; background-color: #e2e8f0; transition: all 0.3s; border-radius: 9999px; }
    </style>
</head>
<body class="text-gray-900 pb-20 md:pb-0">

<?php 
// COMPONENTES (Recuperamos Sidebar y Header)
$ruta_comp = dirname(__DIR__) . '/componentes';
if (!is_dir($ruta_comp)) $ruta_comp = __DIR__ . '/componentes'; // Fallback
if(file_exists($ruta_comp . '/header.php')) require_once $ruta_comp . '/header.php'; 
if(file_exists($ruta_comp . '/sidebar.php')) require_once $ruta_comp . '/sidebar.php'; 
?>

<main class="pt-24 pb-12 md:ml-64 px-4 max-w-[800px] mx-auto animate-fade-in-up">
    
    <div class="mb-8 flex items-center gap-4">
        <a href="/detalle-servicio/<?= nubira_encriptar_id($servicio_id) ?>" class="w-10 h-10 bg-white border border-gray-200 rounded-full flex items-center justify-center text-gray-500 hover:bg-gray-50 transition-all">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div>
            <h1 class="text-2xl font-extrabold tracking-tight text-gray-900">Disponibilidad</h1>
            <p class="text-sm text-gray-500 font-medium truncate max-w-xs md:max-w-md">Para: <?= htmlspecialchars($servicio['titulo']) ?></p>
        </div>
    </div>

    <?php if ($mensaje === 'ok'): ?>
        <div class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-700 p-4 rounded-xl flex items-center gap-3" id="alert-success">
            <i class="fa-solid fa-circle-check text-lg"></i>
            <span class="text-sm font-bold">¡Tus horarios se han guardado correctamente!</span>
        </div>
    <?php elseif ($mensaje === 'error' || $mensaje === 'error_json'): ?>
        <div class="mb-6 bg-red-50 border border-red-200 text-red-700 p-4 rounded-xl flex items-center gap-3">
            <i class="fa-solid fa-triangle-exclamation text-lg"></i>
            <span class="text-sm font-bold">Hubo un problema al guardar. Inténtalo de nuevo.</span>
        </div>
    <?php endif; ?>

    <div class="bg-white border border-gray-200 rounded-2xl p-6 mb-6">
        <p class="text-sm text-gray-600 mb-6 font-medium">Define los días y horas en los que estás disponible para brindar este servicio. Esto ayudará a los estudiantes a coordinar contigo más rápido.</p>

        <form id="form-horarios" method="POST" action="">
            <input type="hidden" name="horarios_json" id="input-horarios-json">

            <div class="space-y-4" id="dias-container">
                <?php foreach ($dias_semana as $dia): 
                    $bloques = (isset($horarios_db[$dia]) && is_array($horarios_db[$dia])) ? $horarios_db[$dia] : [];
                    $activo = count($bloques) > 0;
                ?>
                    <div class="dia-block border border-gray-200 rounded-2xl p-4 transition-all <?= $activo ? 'bg-white' : 'bg-gray-50 opacity-70' ?>" data-dia="<?= $dia ?>">
                        
                        <div class="flex items-center justify-between">
                            <h3 class="font-bold <?= $activo ? 'text-gray-900' : 'text-gray-400' ?> w-24 dia-titulo"><?= $dia ?></h3>
                            
                           <label class="relative inline-flex items-center cursor-pointer mr-2">
                                <input type="checkbox" class="sr-only peer toggle-checkbox" 
                                       <?= $activo ? 'checked' : '' ?> 
                                       onchange="toggleDia(this)">
                                <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#34C759]"></div>
                            </label>
                        </div>

                        <div class="slots-container mt-4 space-y-2 <?= $activo ? '' : 'hidden' ?>">
                            <?php if ($activo): ?>
                                <?php foreach ($bloques as $b): 
                                    $horas = explode(' - ', $b);
                                    $desde = $horas[0] ?? '';
                                    $hasta = $horas[1] ?? '';
                                ?>
                                    <div class="flex items-center gap-2 slot-row animate-fade-in">
                                        <input type="time" class="bg-gray-100 border-0 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-[#54A6D8] block w-full p-2.5 font-bold time-desde" value="<?= htmlspecialchars($desde) ?>" required>
                                        <span class="text-gray-400 font-bold">-</span>
                                        <input type="time" class="bg-gray-100 border-0 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-[#54A6D8] block w-full p-2.5 font-bold time-hasta" value="<?= htmlspecialchars($hasta) ?>" required>
                                        <button type="button" class="w-10 h-10 shrink-0 text-gray-400 hover:text-red-500 bg-white hover:bg-red-50 rounded-xl transition-all flex items-center justify-center" onclick="eliminarSlot(this)">
                                            <i class="fa-solid fa-circle-minus text-lg"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="flex items-center gap-2 slot-row">
                                    <input type="time" class="bg-gray-100 border-0 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-[#54A6D8] block w-full p-2.5 font-bold time-desde" value="09:00">
                                    <span class="text-gray-400 font-bold">-</span>
                                    <input type="time" class="bg-gray-100 border-0 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-[#54A6D8] block w-full p-2.5 font-bold time-hasta" value="12:00">
                                    <button type="button" class="w-10 h-10 shrink-0 text-gray-400 hover:text-red-500 bg-white hover:bg-red-50 rounded-xl transition-all flex items-center justify-center" onclick="eliminarSlot(this)">
                                        <i class="fa-solid fa-circle-minus text-lg"></i>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="btn-add-container mt-3 <?= $activo ? '' : 'hidden' ?>">
                            <button type="button" onclick="añadirSlot(this)" class="text-xs font-bold text-[#54A6D8] hover:text-blue-700 bg-blue-50 px-3 py-1.5 rounded-full transition-colors inline-flex items-center gap-1">
                                <i class="fa-solid fa-circle-plus"></i> Añadir bloque
                            </button>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mt-8 pt-6 border-t border-gray-100 flex justify-end">
                <button type="submit" class="text-white bg-[#54A6D8] hover:bg-blue-600 focus:ring-4 focus:ring-blue-300 font-bold rounded-xl text-sm px-8 py-3.5 text-center transform active:scale-95 transition-all">
                    Guardar Horarios
                </button>
            </div>
        </form>
    </div>
</main>

<?php 
if(file_exists($ruta_comp . '/nav_bottom.php')) require_once $ruta_comp . '/nav_bottom.php'; 
?>

<script>
    setTimeout(() => {
        const al = document.getElementById('alert-success');
        if(al) { al.style.transition = 'opacity 0.5s'; al.style.opacity = '0'; setTimeout(()=>al.remove(), 500); }
    }, 3000);

    window.toggleDia = function(checkbox) {
        const block = checkbox.closest('.dia-block');
        const slotsContainer = block.querySelector('.slots-container');
        const btnAdd = block.querySelector('.btn-add-container');
        const titulo = block.querySelector('.dia-titulo');

        if (checkbox.checked) {
            block.classList.remove('bg-gray-50', 'opacity-70');
            block.classList.add('bg-white');
            titulo.classList.remove('text-gray-400');
            titulo.classList.add('text-gray-900');
            slotsContainer.classList.remove('hidden');
            btnAdd.classList.remove('hidden');
            
            if (slotsContainer.children.length === 0) añadirSlot(btnAdd.querySelector('button'));
            slotsContainer.querySelectorAll('input').forEach(i => i.required = true);
        } else {
            block.classList.add('bg-gray-50', 'opacity-70');
            block.classList.remove('bg-white');
            titulo.classList.add('text-gray-400');
            titulo.classList.remove('text-gray-900');
            slotsContainer.classList.add('hidden');
            btnAdd.classList.add('hidden');
            
            slotsContainer.querySelectorAll('input').forEach(i => i.required = false);
        }
    };

    window.eliminarSlot = function(btn) {
        const row = btn.closest('.slot-row');
        const container = row.parentElement;
        row.remove();
        
        if (container.children.length === 0) {
            const block = container.closest('.dia-block');
            const checkbox = block.querySelector('.toggle-checkbox');
            checkbox.checked = false;
            toggleDia(checkbox);
        }
    };

    window.añadirSlot = function(btn) {
        const block = btn.closest('.dia-block');
        const container = block.querySelector('.slots-container');
        
        const html = `
            <div class="flex items-center gap-2 slot-row animate-fade-in">
                <input type="time" class="bg-gray-100 border-0 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-[#54A6D8] block w-full p-2.5 font-bold time-desde" value="15:00" required>
                <span class="text-gray-400 font-bold">-</span>
                <input type="time" class="bg-gray-100 border-0 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-[#54A6D8] block w-full p-2.5 font-bold time-hasta" value="18:00" required>
                <button type="button" class="w-10 h-10 shrink-0 text-gray-400 hover:text-red-500 bg-white hover:bg-red-50 rounded-xl transition-all flex items-center justify-center" onclick="eliminarSlot(this)">
                    <i class="fa-solid fa-circle-minus text-lg"></i>
                </button>
            </div>
        `;
        container.insertAdjacentHTML('beforeend', html);
    };

    document.getElementById('form-horarios').addEventListener('submit', function(e) {
        e.preventDefault();
        const data = {};
        let hayError = false;

        document.querySelectorAll('.dia-block').forEach(block => {
            const dia = block.getAttribute('data-dia');
            const activo = block.querySelector('.toggle-checkbox').checked;
            
            if (activo) {
                const horarios = [];
                block.querySelectorAll('.slot-row').forEach(row => {
                    const desde = row.querySelector('.time-desde').value;
                    const hasta = row.querySelector('.time-hasta').value;
                    
                    if (desde && hasta) {
                        if (desde >= hasta) {
                            alert(`Error en el día ${dia}: La hora de inicio (${desde}) debe ser menor a la hora de fin (${hasta}).`);
                            hayError = true;
                        }
                        horarios.push(`${desde} - ${hasta}`);
                    }
                });
                data[dia] = horarios;
            } else {
                data[dia] = [];
            }
        });

        if (hayError) return;

        document.getElementById('input-horarios-json').value = JSON.stringify(data);
        this.submit();
    });
</script>
</body>
</html>