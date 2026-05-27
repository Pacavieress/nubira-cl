<?php
/**
 * VISTA ADMIN: GESTOR DE DOMINIOS (MEJORADO)
 * FEATURES: LIVE SEARCH + CONTEXTO DE USUARIOS + VALIDACIÓN
 */

// =============================================================================
// 1. CONFIGURACIÓN
// =============================================================================
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/conexion.php';

// Fallback Iconos
if (file_exists(__DIR__ . '/iconos.php')) {
    require_once __DIR__ . '/iconos.php';
} elseif (!function_exists('icon')) {
    function icon($name, $classes='') { return "<i class='fa-solid fa-$name $classes'></i>"; }
}

// Seguridad
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') { header('Location: /'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// View Models
$institucion    = "Panel Admin";
$nombre_usuario = $_SESSION['usuario_nombre'] ?? 'Administrador';
$foto_perfil    = $_SESSION['foto'] ?? 'default.png';
$rol            = 'admin';
$es_admin       = true;
$page_title     = "Gestor de Universidades";

// Helper Nav
$ruta_actual = $_SERVER['REQUEST_URI'] ?? '/';
if (!function_exists('nav_class')) {
    function nav_class($path) {
        global $ruta_actual;
        return (strpos($ruta_actual, $path) !== false) 
            ? 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent bg-blue-50 text-[#54A6D8] border-blue-100' 
            : 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent text-gray-500 hover:bg-gray-50';
    }
}

// =============================================================================
// 2. CONTROLADOR
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) die("Token Error.");

    try {
        $action = $_POST['action'] ?? '';

        // AGREGAR
        if ($action === 'agregar') {
            $dominio = str_replace(['@', 'www.', 'https://', 'http://'], '', strtolower(trim($_POST['dominio'])));
            $inst    = strtoupper(trim($_POST['institucion']));
            
            $check = $conn->query("SELECT id FROM dominios_permitidos WHERE dominio = '$dominio'");
            if ($check->num_rows > 0) {
                $_SESSION['toast'] = "⚠️ El dominio @$dominio ya existe.";
            } else {
                $stmt = $conn->prepare("INSERT INTO dominios_permitidos (dominio, institucion) VALUES (?, ?)");
                $stmt->bind_param("ss", $dominio, $inst);
                $stmt->execute();
                $_SESSION['toast'] = "✅ Universidad agregada correctamente.";
            }
        }

        // EDITAR
        if ($action === 'editar') {
            $id   = intval($_POST['id']);
            $inst = strtoupper(trim($_POST['institucion']));
            $stmt = $conn->prepare("UPDATE dominios_permitidos SET institucion = ? WHERE id = ?");
            $stmt->bind_param("si", $inst, $id);
            if($stmt->execute()) $_SESSION['toast'] = "✏️ Nombre actualizado.";
        }

        // ELIMINAR
        if ($action === 'eliminar') {
            $id = intval($_POST['id']);
            $conn->query("DELETE FROM dominios_permitidos WHERE id = $id");
            $_SESSION['toast'] = "🗑️ Universidad eliminada.";
        }

    } catch (Exception $e) {
        $_SESSION['toast'] = "Error: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['REQUEST_URI']); exit;
}

// CONSULTA CORREGIDA (Usa 'correo' en lugar de 'email')
$sql = "SELECT d.*, 
        (SELECT COUNT(*) FROM alumnos a WHERE a.correo LIKE CONCAT('%@', d.dominio)) as total_usuarios
        FROM dominios_permitidos d 
        ORDER BY d.institucion ASC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dominios | Nubira Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap'); body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }</style>
</head>
<body class="bg-slate-50 text-slate-800">

    <?php 
    if(file_exists(__DIR__ . '/componentes/header.php')) require_once __DIR__ . '/componentes/header.php';
    if(file_exists(__DIR__ . '/componentes/sidebar.php')) require_once __DIR__ . '/componentes/sidebar.php';
    ?>

    <main class="pt-24 pb-20 lg:ml-64 px-6 max-w-6xl mx-auto min-h-screen">
        
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Gestión de Universidades</h1>
                <p class="text-slate-500 text-sm">Administra los accesos permitidos.</p>
            </div>
            
            <div class="relative w-full md:w-64">
                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                <input type="text" id="liveSearch" placeholder="Buscar universidad..." 
                       class="w-full pl-9 pr-4 py-2 bg-white border border-slate-200 rounded-lg text-sm focus:ring-2 focus:ring-[#54A6D8] focus:outline-none transition-shadow">
            </div>
        </div>

        <?php if (!empty($_SESSION['toast'])): ?>
            <div class="fixed top-24 right-6 z-50 bg-white border border-blue-100 text-slate-700 px-4 py-3 rounded-xl shadow-lg flex items-center gap-3 animate-bounce">
                <i class="fa-solid fa-info-circle text-blue-500"></i>
                <span><?= htmlspecialchars($_SESSION['toast']) ?></span>
            </div>
            <?php unset($_SESSION['toast']); ?>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <div class="lg:col-span-1">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 sticky top-24">
                    <h2 class="font-bold text-slate-800 mb-4 flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-blue-50 text-[#54A6D8] flex items-center justify-center text-sm">
                            <i class="fa-solid fa-plus"></i>
                        </div>
                        Nueva Institución
                    </h2>
                    
                    <form method="POST" action="" onsubmit="return validarDominio()">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="agregar">
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Nombre Oficial</label>
                                <input type="text" name="institucion" placeholder="Ej: U. DE SANTIAGO" required 
                                       class="w-full px-4 py-2 border border-slate-200 rounded-lg uppercase focus:ring-2 focus:ring-blue-100 outline-none text-sm font-semibold text-slate-700">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Dominio de Correo</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400 font-bold text-sm">@</div>
                                    <input type="text" name="dominio" id="inputDominio" placeholder="usach.cl" required 
                                           class="w-full pl-7 pr-4 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-100 outline-none text-sm font-mono text-slate-600">
                                </div>
                                <p class="text-[10px] text-slate-400 mt-1">Sin "www" ni "http"</p>
                            </div>
                            <button type="submit" class="w-full bg-[#54A6D8] hover:bg-blue-500 text-white font-bold py-2.5 rounded-lg transition-all shadow-sm flex items-center justify-center gap-2 text-sm">
                                <span>Habilitar Acceso</span>
                                <i class="fa-solid fa-arrow-right"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden flex flex-col h-full">
                    <div class="bg-slate-50 px-6 py-3 border-b border-slate-200 flex justify-between items-center">
                        <span class="font-bold text-xs text-slate-500 uppercase tracking-wider">Listado Maestro</span>
                        <span class="bg-slate-200 text-slate-600 px-2 py-0.5 rounded text-[10px] font-bold"><?= $result->num_rows ?></span>
                    </div>
                    
                    <div class="overflow-x-auto max-h-[75vh]">
                        <table class="w-full text-left text-sm" id="tablaDominios">
                            <tbody class="divide-y divide-slate-100">
                                <?php if($result->num_rows > 0): ?>
                                    <?php while($row = $result->fetch_assoc()): 
                                        $usuarios = (int)$row['total_usuarios'];
                                        $tiene_usuarios = $usuarios > 0;
                                    ?>
                                    <tr class="hover:bg-slate-50 group transition-colors domain-row">
                                        <td class="px-6 py-4">
                                            <div class="flex items-center gap-4">
                                                <div class="w-10 h-10 rounded-full <?= $tiene_usuarios ? 'bg-blue-100 text-blue-600 border-blue-200' : 'bg-slate-100 text-slate-400 border-slate-200' ?> flex items-center justify-center font-bold text-sm border shadow-sm shrink-0">
                                                    <?= substr($row['institucion'], 0, 2) ?>
                                                </div>
                                                <div>
                                                    <div class="font-bold text-slate-800 domain-name"><?= htmlspecialchars($row['institucion']) ?></div>
                                                    <div class="flex items-center gap-2 mt-0.5">
                                                        <span class="text-xs text-slate-500 font-mono bg-slate-100 px-1.5 rounded border border-slate-200">@<?= htmlspecialchars($row['dominio']) ?></span>
                                                        
                                                        <?php if($tiene_usuarios): ?>
                                                            <span class="text-[10px] text-green-600 bg-green-50 px-1.5 rounded font-bold flex items-center gap-1 border border-green-100" title="Usuarios Activos">
                                                                <i class="fa-solid fa-user"></i> <?= $usuarios ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-[10px] text-slate-400 bg-slate-50 px-1.5 rounded border border-slate-100">Sin usuarios</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        
                                        <td class="px-6 py-4 text-right">
                                            <div class="flex justify-end gap-2 opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-opacity">
                                                <button onclick="abrirEditar(<?= $row['id'] ?>, '<?= htmlspecialchars($row['institucion']) ?>')" 
                                                        class="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-blue-500 rounded-lg hover:bg-blue-50 border border-transparent hover:border-blue-100 transition-all" 
                                                        title="Editar">
                                                    <i class="fa-solid fa-pencil"></i>
                                                </button>
                                                
                                                <form method="POST" onsubmit="return confirmarEliminacion(<?= $usuarios ?>);" class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                    <input type="hidden" name="action" value="eliminar">
                                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                    
                                                    <button class="w-8 h-8 flex items-center justify-center rounded-lg border border-transparent transition-all <?= $tiene_usuarios ? 'text-red-300 hover:text-red-500 hover:bg-red-50 hover:border-red-100' : 'text-slate-300 hover:text-red-500 hover:bg-red-50' ?>" 
                                                            title="<?= $tiene_usuarios ? '¡Cuidado! Tiene usuarios activos' : 'Eliminar' ?>">
                                                        <i class="fa-regular fa-trash-can"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="2" class="px-6 py-10 text-center text-slate-400 text-sm">
                                            No hay universidades registradas.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <div id="noResults" class="hidden px-6 py-10 text-center text-slate-400 text-sm">
                            No se encontraron coincidencias.
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
    
    <?php 
if(file_exists(__DIR__ . '/componentes/nav_bottom.php')) require_once __DIR__ . '/componentes/nav_bottom.php'; 
if(file_exists(__DIR__ . '/componentes/modal_publicar.php')) require_once __DIR__ . '/componentes/modal_publicar.php'; 
if(file_exists(__DIR__ . '/componentes/modal_explora.php')) require_once __DIR__ . '/componentes/modal_explora.php'; 
?>



    <div id="modalEditar" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 hidden items-center justify-center p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 relative animate-fade-in-up border border-slate-200">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg font-bold text-gray-900">Editar Institución</h3>
                <button onclick="cerrarModal()" class="w-8 h-8 flex items-center justify-center rounded-full bg-gray-100 text-gray-400 hover:text-gray-600 hover:bg-gray-200 transition"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" id="edit_id">
                
                <div class="mb-6">
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Nombre Oficial</label>
                    <input type="text" name="institucion" id="edit_nombre" required 
                           class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#54A6D8] outline-none uppercase font-semibold text-slate-700">
                </div>
                
                <div class="flex gap-3">
                    <button type="button" onclick="cerrarModal()" class="flex-1 py-3 rounded-xl bg-gray-100 text-gray-600 font-bold hover:bg-gray-200 transition-colors">Cancelar</button>
                    <button type="submit" class="flex-1 py-3 rounded-xl bg-[#54A6D8] text-white font-bold hover:bg-blue-600 transition-colors shadow-lg shadow-blue-100">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        
        // SISTEMA DE MODALES NUBIRA 2.0 (Para el Nav Bottom)
        const NubiraModales = {
            setup(triggerId, modalId, cardId, closeId) {
                const btn = document.getElementById(triggerId);
                const modal = document.getElementById(modalId);
                const card = document.getElementById(cardId);
                const close = document.getElementById(closeId);
                if(!btn || !modal) return;
                
                const open = () => { 
                    modal.classList.remove('hidden'); 
                    requestAnimationFrame(() => { card.classList.remove('translate-y-full', 'opacity-0'); });
                    document.body.style.overflow = 'hidden'; 
                };
                const shut = () => { 
                    card.classList.add('translate-y-full', 'opacity-0'); 
                    setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); 
                };
                
                btn.onclick = (e) => { 
                    e.preventDefault(); 
                    open(); 
                }; 
                if(close) close.onclick = shut; 
                modal.onclick = (e) => { if(e.target === modal) shut(); };
            }
        };

        document.addEventListener('DOMContentLoaded', () => {
            NubiraModales.setup('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
            NubiraModales.setup('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
        });
        
        // Modal Logic
        function abrirEditar(id, nombre) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_nombre').value = nombre;
            const modal = document.getElementById('modalEditar');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
        function cerrarModal() {
            const modal = document.getElementById('modalEditar');
            modal.classList.remove('flex');
            modal.classList.add('hidden');
        }

        // Validación Pre-Envío
        function validarDominio() {
            const input = document.getElementById('inputDominio');
            if (input.value.includes('@') || input.value.includes('http')) {
                alert('Por favor, ingresa solo el dominio (ej: uc.cl) sin @ ni http.');
                return false;
            }
            return true;
        }

        // Alerta Inteligente
        function confirmarEliminacion(usuarios) {
            if (usuarios > 0) {
                return confirm(`⚠️ ¡ADVERTENCIA CRÍTICA!\n\nHay ${usuarios} usuarios registrados bajo este dominio.\nSi lo eliminas, estos usuarios podrían perder acceso a sus cuentas o tener problemas.\n\n¿Estás 100% seguro de eliminarlo?`);
            }
            return confirm('¿Estás seguro de eliminar esta institución?');
        }

        // Live Search
        document.getElementById('liveSearch').addEventListener('keyup', function() {
            const query = this.value.toLowerCase();
            const rows = document.querySelectorAll('.domain-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                if (text.includes(query)) {
                    row.classList.remove('hidden');
                    visibleCount++;
                } else {
                    row.classList.add('hidden');
                }
            });

            const noRes = document.getElementById('noResults');
            if(visibleCount === 0) noRes.classList.remove('hidden');
            else noRes.classList.add('hidden');
        });
    </script>
</body>
</html>