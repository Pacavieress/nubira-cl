<?php
/**
 * VISTA ADMIN: GESTIÓN DE SOLICITUDES
 * ARQUITECTURA: NUBIRA 2.0 (Full UI + Nav Mobile + Bulk Actions + Flat Design)
 */

// 1. CONFIGURACIÓN
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php';

// 2. SEGURIDAD
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header('Location: /');
    exit;
}

// 3. PREPARACIÓN DE DATOS
$institucion = "Panel Admin";
$nombre_usuario = $_SESSION['usuario_nombre'] ?? 'Administrador';
$foto_perfil = $_SESSION['foto'] ?? 'default.png'; 
$rol = 'admin';
$es_admin = true;
$page_title = "Solicitudes de Institución";

// Helpers
if (!function_exists('icon')) { function icon($name, $classes='') { return "<i class='fa-solid fa-$name $classes'></i>"; } }

$ruta_actual = $_SERVER['REQUEST_URI'] ?? '/';
if (!function_exists('nav_class')) {
    function nav_class($path) {
        global $ruta_actual;
        $base = 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent';
        $active = ' bg-blue-50 text-[#54A6D8] border-blue-100';
        $inactive = ' text-slate-500 hover:bg-slate-50';
        return (strpos($ruta_actual, $path) !== false) ? $base . $active : $base . $inactive;
    }
}

// Token CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// 4. CONTROLADOR (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) die("Token inválido.");

    try {
        // A) Aprobar
        if (isset($_POST['aprobar_id'])) {
            $id = (int)$_POST['aprobar_id'];
            $stmt = $conn->prepare("SELECT email, institucion FROM solicitudes_instituciones WHERE id=?");
            $stmt->bind_param("i", $id); $stmt->execute(); $stmt->bind_result($email, $inst); $stmt->fetch(); $stmt->close();
            
            if ($email) {
                $stmtUpd = $conn->prepare("UPDATE solicitudes_instituciones SET estado='revisada', correo_enviado=1 WHERE id=?");
                $stmtUpd->bind_param("i", $id); $stmtUpd->execute(); $stmtUpd->close();
                enviarCorreoSolicitudInstitucion($email, 'Usuario', $inst, 'aprobada');
                $_SESSION['toast'] = "✅ Solicitud #$id aprobada.";
            }
        }
        // B) Rechazar
        if (isset($_POST['rechazar_id'])) {
            $id = (int)$_POST['rechazar_id'];
            $stmt = $conn->prepare("SELECT email, institucion FROM solicitudes_instituciones WHERE id=?");
            $stmt->bind_param("i", $id); $stmt->execute(); $stmt->bind_result($email, $inst); $stmt->fetch(); $stmt->close();
            
            if ($email) {
                $stmtUpd = $conn->prepare("UPDATE solicitudes_instituciones SET estado='revisada', correo_enviado=1 WHERE id=?");
                $stmtUpd->bind_param("i", $id); $stmtUpd->execute(); $stmtUpd->close();
                enviarCorreoSolicitudInstitucion($email, 'Usuario', $inst, 'rechazada');
                $_SESSION['toast'] = "❌ Solicitud #$id rechazada.";
            }
        }
        // C) Solo Revisar
        if (isset($_POST['marcar_revisada'])) {
            $id = (int)$_POST['marcar_revisada'];
            $stmtUpd = $conn->prepare("UPDATE solicitudes_instituciones SET estado='revisada' WHERE id=?");
            $stmtUpd->bind_param("i", $id); $stmtUpd->execute(); $stmtUpd->close();
            $_SESSION['toast'] = "👁️ Marcada como vista.";
        }
        
        // D) ELIMINACIÓN MASIVA (NUEVO)
        if (isset($_POST['eliminar_masivo']) && !empty($_POST['ids'])) {
            $ids = array_map('intval', $_POST['ids']);
            if (count($ids) > 0) {
                $lista_ids = implode(',', $ids);
                $conn->query("DELETE FROM solicitudes_instituciones WHERE id IN ($lista_ids)");
                $_SESSION['toast'] = "🗑️ " . count($ids) . " solicitudes eliminadas permanentemente.";
            }
        }

    } catch (Exception $e) { $_SESSION['toast'] = "Error: " . $e->getMessage(); }
    
    header("Location: " . $_SERVER['PHP_SELF'] . ($_GET['estado'] ? '?estado='.$_GET['estado'] : '')); exit;
}

// 5. CONSULTA
$filtro = $_GET['estado'] ?? '';
$sql = "SELECT * FROM solicitudes_instituciones";
$params = [];
$types = "";

if ($filtro === 'pendiente') { $sql .= " WHERE estado = ?"; $params[] = 'pendiente'; $types .= 's'; } 
elseif ($filtro === 'revisada') { $sql .= " WHERE estado = ?"; $params[] = 'revisada'; $types .= 's'; }

$sql .= " ORDER BY fecha DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    $res = $conn->query($sql);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Solicitudes | Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #ffffff; -webkit-tap-highlight-color: transparent;}
        .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
        .force-no-shadow * { text-shadow: none !important; }
        .scrollbar-hide::-webkit-scrollbar { height: 6px; }
        .scrollbar-hide::-webkit-scrollbar-thumb { background-color: #e2e8f0; border-radius: 10px; }
    </style>
</head>
<body class="text-slate-800 antialiased force-no-shadow bg-white">

<div id="loader" class="fixed inset-0 bg-white flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-8 w-8 border-4 border-slate-100 border-t-[#54A6D8] rounded-full"></div>
</div>

    <?php 
    if(file_exists(__DIR__ . '/componentes/header.php')) require_once __DIR__ . '/componentes/header.php';
    if(file_exists(__DIR__ . '/componentes/sidebar.php')) require_once __DIR__ . '/componentes/sidebar.php';
    ?>

    <main class="pt-16 pb-32 md:pb-16 lg:ml-64 px-4 md:px-6 w-full md:w-[calc(100%-16rem)]">
        
        <form method="POST" id="form-tabla" class="max-w-7xl mx-auto space-y-6">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

            <div class="sticky top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-slate-100 py-4 flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-xl md:text-2xl font-extrabold text-slate-900 tracking-tight">Solicitudes</h1>
                    <p class="text-slate-400 text-xs font-medium mt-0.5">Gestión de peticiones universitarias.</p>
                </div>
                
                <div class="flex items-center gap-3">
                    <button type="submit" name="eliminar_masivo" onclick="return confirm('¿Estás seguro de eliminar las solicitudes seleccionadas? Esta acción no se puede deshacer.');" 
                            class="bg-red-50 text-red-500 active:bg-red-100 px-4 py-2 rounded-xl text-xs font-bold transition-colors hidden group-has-checked:flex items-center" id="btn-eliminar-masivo">
                        <i class="fa-solid fa-trash-can mr-1.5"></i> Eliminar Selección
                    </button>

                    <div class="flex bg-slate-50 p-1 rounded-xl border border-slate-100">
                        <a href="?estado=" class="px-4 py-1.5 text-[11px] font-bold uppercase tracking-widest rounded-lg transition-colors <?= $filtro===''?'bg-white text-slate-800 shadow-sm':'text-slate-400 hover:text-slate-600' ?>">Todas</a>
                        <a href="?estado=pendiente" class="px-4 py-1.5 text-[11px] font-bold uppercase tracking-widest rounded-lg transition-colors <?= $filtro==='pendiente'?'bg-white text-amber-600 shadow-sm':'text-slate-400 hover:text-amber-500' ?>">Pendientes</a>
                        <a href="?estado=revisada" class="px-4 py-1.5 text-[11px] font-bold uppercase tracking-widest rounded-lg transition-colors <?= $filtro==='revisada'?'bg-white text-emerald-600 shadow-sm':'text-slate-400 hover:text-emerald-500' ?>">Revisadas</a>
                    </div>
                </div>
            </div>

            <?php if (!empty($_SESSION['toast'])): ?>
                <div id="toast" class="fixed bottom-6 md:top-24 right-1/2 translate-x-1/2 md:translate-x-0 md:right-5 px-5 py-3 rounded-xl bg-slate-800 text-white z-[90] flex items-center gap-3 animate-bounce">
                    <i class="fa-solid fa-bell text-[#54A6D8]"></i>
                    <span class="font-bold text-sm tracking-wide"><?= htmlspecialchars($_SESSION['toast']) ?></span>
                </div>
                <?php unset($_SESSION['toast']); ?>
                <script>setTimeout(()=>document.getElementById('toast').remove(), 3500);</script>
            <?php endif; ?>

            <div class="bg-white border border-slate-100 rounded-3xl overflow-hidden min-h-[400px]">
                <div class="overflow-x-auto scrollbar-hide">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 border-b border-slate-100 text-slate-400 font-bold uppercase text-[11px] tracking-widest">
                            <tr>
                                <th class="px-6 py-4 w-10 text-center">
                                    <input type="checkbox" id="check-all" class="w-4 h-4 rounded bg-white border-slate-300 text-[#54A6D8] focus:ring-0 cursor-pointer">
                                </th>
                                <th class="px-6 py-4">Institución</th>
                                <th class="px-6 py-4">Solicitante</th>
                                <th class="px-6 py-4 text-center">Estado</th>
                                <th class="px-6 py-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php if ($res && $res->num_rows > 0): ?>
                                <?php while($row = $res->fetch_assoc()): ?>
                                <tr class="hover:bg-slate-50 transition-colors group align-middle">
                                    
                                    <td class="px-6 py-4 text-center">
                                        <input type="checkbox" name="ids[]" value="<?= $row['id'] ?>" class="check-item w-4 h-4 rounded bg-white border-slate-300 text-[#54A6D8] focus:ring-0 cursor-pointer">
                                    </td>

                                    <td class="px-6 py-4">
                                        <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($row['institucion']) ?></p>
                                        <div class="text-[10px] text-slate-400 font-medium font-mono mt-0.5">ID: #<?= $row['id'] ?></div>
                                    </td>
                                    
                                    <td class="px-6 py-4">
                                        <div class="text-slate-700 font-medium text-xs md:text-sm"><?= htmlspecialchars($row['email']) ?></div>
                                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5"><?= date("d M Y, H:i", strtotime($row['fecha'])) ?></div>
                                    </td>
                                    
                                    <td class="px-6 py-4 text-center">
                                        <?php if ($row['estado'] === 'pendiente'): ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest bg-amber-50 text-amber-600">
                                                <span class="w-1.5 h-1.5 bg-amber-500 rounded-full animate-pulse"></span> Pendiente
                                            </span>
                                        <?php else: ?>
                                            <div class="flex flex-col items-center gap-1">
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest bg-slate-100 text-slate-500">
                                                    Revisada
                                                </span>
                                                <?php if ($row['correo_enviado']): ?>
                                                    <span class="text-[9px] text-emerald-500 font-bold flex items-center gap-1 uppercase tracking-widest">
                                                        <i class="fa-solid fa-check-double"></i> Notificado
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-1.5 opacity-100 md:opacity-60 md:group-hover:opacity-100 transition-opacity">
                                            <?php if ($row['estado'] === 'pendiente'): ?>
                                                <button type="submit" name="aprobar_id" value="<?= $row['id'] ?>" onclick="return confirm('¿Aprobar y notificar por correo?');" 
                                                        class="bg-emerald-50 active:bg-emerald-100 text-emerald-600 p-2 rounded-xl transition-colors text-xs" title="Aprobar">
                                                    <i class="fa-solid fa-check"></i>
                                                </button>

                                                <button type="submit" name="rechazar_id" value="<?= $row['id'] ?>" onclick="return confirm('¿Rechazar y notificar por correo?');"
                                                        class="bg-red-50 active:bg-red-100 text-red-500 p-2 rounded-xl transition-colors text-xs" title="Rechazar">
                                                    <i class="fa-solid fa-xmark"></i>
                                                </button>

                                                <button type="submit" name="marcar_revisada" value="<?= $row['id'] ?>"
                                                        class="bg-slate-100 active:bg-slate-200 text-slate-500 p-2 rounded-xl transition-colors text-xs" title="Marcar como leída (Sin correo)">
                                                    <i class="fa-regular fa-eye-slash"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-slate-300 text-[10px] font-bold uppercase tracking-widest">Procesada</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-16 text-center">
                                        <div class="flex flex-col items-center justify-center">
                                            <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mb-3">
                                                <i class="fa-solid fa-inbox text-slate-300 text-2xl"></i>
                                            </div>
                                            <p class="text-slate-800 font-bold text-sm">No hay solicitudes <?= $filtro ? htmlspecialchars($filtro).'s' : '' ?>.</p>
                                            <p class="text-[11px] font-medium text-slate-400 mt-0.5">¡Todo está al día!</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form> 
    </main>

    <?php 
    // INYECCIÓN MODULAR OFICIAL DE NUBIRA 2.0
    require_once __DIR__ . '/componentes/nav_bottom.php'; 
    require_once __DIR__ . '/componentes/modal_publicar.php'; 
    require_once __DIR__ . '/componentes/modal_explora.php'; 
    ?>

    <script>
        window.onload = () => { 
            const l = document.getElementById('loader'); 
            if(l) { l.style.opacity='0'; setTimeout(()=>l.style.display='none',300); } 
        };

        // Lógica de Selección Masiva
        document.addEventListener('DOMContentLoaded', () => {
            const checkAll = document.getElementById('check-all');
            const checkItems = document.querySelectorAll('.check-item');
            const btnDelete = document.getElementById('btn-eliminar-masivo');

            function toggleButton() {
                const anyChecked = Array.from(checkItems).some(c => c.checked);
                if (btnDelete) {
                    if (anyChecked) btnDelete.classList.remove('hidden');
                    else btnDelete.classList.add('hidden');
                }
            }

            if (checkAll) {
                checkAll.addEventListener('change', () => {
                    checkItems.forEach(c => c.checked = checkAll.checked);
                    toggleButton();
                });
            }

            checkItems.forEach(c => {
                c.addEventListener('change', toggleButton);
            });

            // Lógica Modal Nativa (Nubira 2.0)
            function setupModal(triggerId, modalId, cardId, closeId) {
                const btn=document.getElementById(triggerId), modal=document.getElementById(modalId), card=document.getElementById(cardId), close=document.getElementById(closeId);
                if(!btn||!modal) return;
                const open=()=>{ modal.classList.remove('hidden'); requestAnimationFrame(()=>card?.classList.remove('translate-y-full','opacity-0')); document.body.style.overflow='hidden'; };
                const shut=()=>{ card?.classList.add('translate-y-full','opacity-0'); setTimeout(()=>{modal.classList.add('hidden');document.body.style.overflow='';},300); };
                btn.onclick=(e)=>{e.preventDefault();open();}; 
                if(close) close.onclick=shut; 
                modal.onclick=(e)=>{if(e.target===modal)shut();};
            }

            setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
            setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
        });
    </script>

</body>
</html>
