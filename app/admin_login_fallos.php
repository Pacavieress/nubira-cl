<?php
/**
 * VISTA ADMIN: MONITOR DE ACCESOS
 * ARQUITECTURA: NUBIRA 2.0 (Con Business Intelligence y Gestión VIP)
 */

// --- 1. CONFIGURACIÓN ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// --- 2. CONEXIÓN ---
if (file_exists(__DIR__ . '/conexion.php')) {
    require_once __DIR__ . '/conexion.php';
} else {
    die("❌ Error Crítico: No se encuentra conexion.php en " . __DIR__);
}

// --- 3. SEGURIDAD ---
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header('Location: /');
    exit;
}

// --- 4. VIEW MODELS ---
$page_title = "Centro de Monitoreo";

// Helpers
if (!function_exists('icon')) { function icon($name, $classes='') { return "<i class='fa-solid fa-$name $classes'></i>"; } }

// Token CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// --- 5. CONTROLADOR ---
$tab = $_GET['tab'] ?? 'fallos';
// NUEVO: Agregamos la pestaña 'vips'
$valid_tabs = ['fallos', 'vips', 'pendientes'];
if (!in_array($tab, $valid_tabs)) $tab = 'fallos';

$page = max(1, intval($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Acciones POST

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'], $_POST['token'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['token'])) die("Token inválido.");

    try {
        // --- LÓGICA VIP (NUBIRA 2.0) ---
        if ($_POST['accion'] === 'autorizar_gmail') {
            $correo = filter_var(strtolower(trim($_POST['correo'])), FILTER_SANITIZE_EMAIL);
            $stmt_vip = $conn->prepare("INSERT INTO excepciones_email (correo, activo) VALUES (?, 1) ON DUPLICATE KEY UPDATE activo = 1");
            $stmt_vip->bind_param("s", $correo);
            if ($stmt_vip->execute()) {
                // Si el correo estaba en rebotes, lo podemos borrar para limpiar la bandeja
                $conn->query("DELETE FROM interesados_registro WHERE correo = '$correo'");
                $_SESSION['toast'] = "✨ VIP Creado: $correo ahora puede registrarse.";
            } else {
                $_SESSION['toast'] = "❌ Error al autorizar.";
            }
            $stmt_vip->close();
        }
        
        // NUEVO: Revocar VIP
        if ($_POST['accion'] === 'revocar_vip') {
            $correo = filter_var(strtolower(trim($_POST['correo'])), FILTER_SANITIZE_EMAIL);
            $stmt_rev = $conn->prepare("UPDATE excepciones_email SET activo = 0 WHERE correo = ?");
            $stmt_rev->bind_param("s", $correo);
            if ($stmt_rev->execute()) {
                $_SESSION['toast'] = "🔒 VIP Revocado: $correo ya no puede registrarse.";
            }
            $stmt_rev->close();
        }

        // --- LÓGICAS ORIGINALES ---
        if ($_POST['accion'] === 'limpiar_fallos') {
            $correo = filter_var($_POST['correo'], FILTER_SANITIZE_EMAIL);
            $conn->query("DELETE FROM login_fallos WHERE correo = '$correo'");
            $_SESSION['toast'] = "✅ Historial login limpiado.";
        }
        if ($_POST['accion'] === 'eliminar_pendiente') {
            $id = intval($_POST['id']);
            $conn->query("DELETE FROM alumnos WHERE id = $id AND confirmado = 0");
            $_SESSION['toast'] = "✅ Usuario eliminado.";
        }
        if ($_POST['accion'] === 'eliminar_solicitud') {
            $id = intval($_POST['id']);
            $conn->query("DELETE FROM solicitudes_instituciones WHERE id = $id");
            $_SESSION['toast'] = "✅ Solicitud archivada.";
        }
        if ($_POST['accion'] === 'eliminar_rebote') {
            $id = intval($_POST['id']);
            if(is_numeric($id)) {
                $conn->query("DELETE FROM interesados_registro WHERE id = $id");
                $_SESSION['toast'] = "✅ Registro eliminado.";
            }
        }
        
        if ($_POST['accion'] === 'enviar_aviso_rebote') {
            $destinatario = filter_var($_POST['correo'], FILTER_SANITIZE_EMAIL);
            $ruta_correo = __DIR__ . '/correo.php';
            if (file_exists($ruta_correo)) require_once $ruta_correo;

            if (function_exists('enviarCorreoRecuperacionRegistro')) {
                if ($destinatario && enviarCorreoRecuperacionRegistro($destinatario)) {
                    $conn->query("UPDATE interesados_registro SET fecha_envio_correo = NOW() WHERE correo = '$destinatario'");
                    $_SESSION['toast'] = "✅ Invitación enviada a $destinatario";
                } else {
                    $_SESSION['toast'] = "❌ Error técnico al enviar. Revisa log_correos.txt";
                }
            } else {
                $_SESSION['toast'] = "⚠️ Error: La función de correo no se cargó.";
            }
        }

    } catch (Exception $e) { $_SESSION['toast'] = "❌ Error: " . $e->getMessage(); }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=" . $tab);
    exit;
}

// Consultas SQL
$sql = "";
$total_registros = 0;

try {
    if ($tab === 'fallos') {
        $total_registros = $conn->query("SELECT COUNT(*) FROM login_fallos")->fetch_row()[0];
        $sql = "SELECT lf.correo, lf.ip, lf.fecha, 'Login' as origen, (SELECT COUNT(*) FROM alumnos a WHERE a.correo = lf.correo) as es_alumno FROM login_fallos lf ORDER BY lf.fecha DESC LIMIT ?, ?";

    } elseif ($tab === 'pendientes') {
        $total_registros = $conn->query("SELECT COUNT(*) FROM alumnos WHERE confirmado = 0")->fetch_row()[0];
        $sql = "SELECT id, nombre, correo, carrera, dominio FROM alumnos WHERE confirmado = 0 ORDER BY id DESC LIMIT ?, ?";

    } elseif ($tab === 'vips') {
        $total_registros = $conn->query("SELECT COUNT(*) FROM excepciones_email WHERE activo = 1")->fetch_row()[0];
        $sql = "SELECT id, correo, fecha_creacion, activo FROM excepciones_email WHERE activo = 1 ORDER BY fecha_creacion DESC LIMIT ?, ?";
    }

    $result = null;
    if ($sql) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $offset, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
    }

    // Contadores para las Badges
    $cnt_fallos     = $conn->query("SELECT COUNT(*) FROM login_fallos")->fetch_row()[0] ?? 0;
    $cnt_pendientes = $conn->query("SELECT COUNT(*) FROM alumnos WHERE confirmado = 0")->fetch_row()[0] ?? 0;
    $cnt_vips       = $conn->query("SELECT COUNT(*) FROM excepciones_email WHERE activo = 1")->fetch_row()[0] ?? 0;

} catch (Exception $e) {
    die("<div class='p-4 bg-red-100 text-red-700'>Error SQL: " . $e->getMessage() . "</div>");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Monitor Nubira | Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #ffffff; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .tab-active { color: #54A6D8; border-bottom: 2px solid #54A6D8; background-color: #f8fcff; }
        .tab-inactive { color: #6b7280; border-bottom: 2px solid transparent; }
        .tab-inactive:hover { color: #374151; background-color: #f9fafb; }
        @keyframes fadeIn { from { opacity: 0; transform: scale(0.95) translateY(-10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        .animate-fade-in { animation: fadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    </style>
</head>
<body class="bg-white text-gray-900 antialiased">

    <?php 
    $header_loaded = false;
    if (file_exists(__DIR__ . '/componentes/header.php')) {
        require_once __DIR__ . '/componentes/header.php';
        $header_loaded = true;
    }
    if (file_exists(__DIR__ . '/componentes/sidebar.php')) {
        require_once __DIR__ . '/componentes/sidebar.php';
    }
    ?>

    <?php if(!$header_loaded): ?>
    <header class="fixed top-0 w-full z-50 bg-white border-b h-16 flex items-center px-4">
        <span class="text-xl font-bold text-[#54A6D8]">Nubira Admin</span>
    </header>
    <?php endif; ?>

    <main class="pt-24 pb-28 md:pb-10 lg:ml-64 px-4 max-w-[1600px] mx-auto min-h-screen">
        
        <div class="flex items-center justify-between mb-6 px-1">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Centro de Monitoreo</h1>
                <p class="text-sm text-gray-500">Gestión de accesos, seguridad y registros.</p>
            </div>
            <div>
                <button onclick="document.getElementById('modalVip').classList.remove('hidden'); document.getElementById('modalVip').classList.add('flex');" class="bg-[#54A6D8] hover:bg-sky-500 text-white text-sm font-bold py-2.5 px-4 rounded-xl shadow-sm hover:shadow-md transition-all flex items-center gap-2 transform active:scale-95">
                    <i class="fa-solid fa-plus"></i> <span class="hidden sm:inline">Autorizar VIP</span>
                </button>
            </div>
        </div>

        <?php if (!empty($_SESSION['toast'])): ?>
            <div class="mb-6 p-4 rounded-xl <?= strpos($_SESSION['toast'], '❌') !== false ? 'bg-red-50 text-red-700 border-red-200' : 'bg-green-50 text-green-700 border-green-200' ?> border flex items-center gap-3 shadow-sm animate-pulse">
                <i class="fa-solid <?= strpos($_SESSION['toast'], '❌') !== false ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
                <span class="font-medium"><?= htmlspecialchars($_SESSION['toast']) ?></span>
            </div>
            <?php unset($_SESSION['toast']); ?>
        <?php endif; ?>

        <div class="bg-white border border-gray-200 rounded-t-2xl shadow-sm overflow-hidden mb-0">
            <div class="flex overflow-x-auto no-scrollbar">
                <a href="?tab=fallos" class="flex-1 min-w-[120px] text-center py-3 text-sm font-bold transition-colors <?= $tab === 'fallos' ? 'tab-active' : 'tab-inactive' ?>">
                    <i class="fa-solid fa-shield-halved mr-1 opacity-70"></i> Intentos
                    <span class="ml-1 bg-red-100 text-red-600 px-1.5 rounded-full text-[10px]"><?= $cnt_fallos ?></span>
                </a>
                <a href="?tab=vips" class="flex-1 min-w-[120px] text-center py-3 text-sm font-bold transition-colors <?= $tab === 'vips' ? 'tab-active' : 'tab-inactive' ?>">
                    <i class="fa-solid fa-star mr-1 opacity-70 text-yellow-500"></i> VIPs
                    <span class="ml-1 bg-yellow-100 text-yellow-700 px-1.5 rounded-full text-[10px]"><?= $cnt_vips ?></span>
                </a>
                <a href="?tab=pendientes" class="flex-1 min-w-[120px] text-center py-3 text-sm font-bold transition-colors <?= $tab === 'pendientes' ? 'tab-active' : 'tab-inactive' ?>">
                    <i class="fa-regular fa-clock mr-1 opacity-70"></i> Pendientes
                    <span class="ml-1 bg-orange-100 text-orange-600 px-1.5 rounded-full text-[10px]"><?= $cnt_pendientes ?></span>
                </a>
            </div>
        </div>

        <div class="bg-white border border-gray-200 border-t-0 rounded-b-2xl shadow-sm overflow-hidden mb-8">
            

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 border-b border-gray-100 text-gray-500 font-semibold uppercase text-xs tracking-wider">
                        <tr>
                            <?php if($tab==='vips'): ?>
                                <th class="p-4 align-top">Correo VIP</th>
                                <th class="p-4 align-top">Estado</th>
                                <th class="p-4 align-top">Fecha Autorización</th>
                                <th class="p-4 align-top text-right">Acción</th>

                            <?php elseif($tab==='fallos'): ?>
                                <th class="p-4 align-top">Usuario / Correo</th>
                                <th class="p-4 align-top">IP Origen</th>
                                <th class="p-4 align-top">Fecha</th>
                                <th class="p-4 align-top text-right">Acción</th>

                            <?php elseif($tab==='pendientes'): ?>
                                <th class="p-4 align-top">Candidato</th>
                                <th class="p-4 align-top">Carrera</th>
                                <th class="p-4 align-top text-right">Acción</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr class="hover:bg-gray-50 transition-colors group">
                                    
                                    <?php if($tab==='vips'): ?>
                                        <td class="p-4 font-bold text-gray-800">
                                            <i class="fa-solid fa-star text-yellow-400 text-xs mr-1"></i>
                                            <?= htmlspecialchars($row['correo']) ?>
                                        </td>
                                        <td class="p-4"><span class="bg-green-100 text-green-700 px-2 py-1 rounded text-xs font-bold">Autorizado</span></td>
                                        <td class="p-4 text-xs text-gray-500"><?= date('d/m/Y', strtotime($row['fecha_creacion'])) ?></td>
                                        <td class="p-4 text-right">
                                            <form method="POST" onsubmit="return confirm('¿Revocar acceso VIP a <?= htmlspecialchars($row['correo']) ?>?')" class="inline">
                                                <input type="hidden" name="accion" value="revocar_vip">
                                                <input type="hidden" name="correo" value="<?= htmlspecialchars($row['correo']) ?>">
                                                <input type="hidden" name="token" value="<?= $csrf_token ?>">
                                                <button class="text-red-500 hover:text-red-700 bg-red-50 px-2 py-1 rounded text-xs font-bold transition">Revocar</button>
                                            </form>
                                        </td>

                                    <?php elseif($tab==='fallos'): ?>
                                        <td class="p-4 font-bold text-gray-700"><?= htmlspecialchars($row['correo']) ?>
                                            <div class="text-[10px] text-gray-400 font-normal"><?= $row['es_alumno']?'Registrado':'Desconocido' ?></div></td>
                                        <td class="p-4 font-mono text-xs text-gray-500"><?= htmlspecialchars($row['ip']) ?></td>
                                        <td class="p-4 text-gray-500 text-xs"><?= date('d/m H:i', strtotime($row['fecha'])) ?></td>
                                        <td class="p-4 text-right">
                                            <form method="POST" onsubmit="return confirm('¿Limpiar?')" class="inline"><input type="hidden" name="accion" value="limpiar_fallos"><input type="hidden" name="correo" value="<?= htmlspecialchars($row['correo']) ?>"><input type="hidden" name="token" value="<?= $csrf_token ?>"><button class="text-red-400 hover:text-red-600 px-2"><i class="fa-solid fa-trash"></i></button></form>
                                        </td>

                                    <?php elseif($tab==='pendientes'): ?>
                                        <td class="p-4"><div class="font-bold text-gray-800"><?= htmlspecialchars($row['nombre']) ?></div><div class="text-xs text-[#54A6D8]"><?= htmlspecialchars($row['correo']) ?></div></td>
                                        <td class="p-4 text-xs"><?= htmlspecialchars($row['carrera']) ?><br><span class="text-gray-400">@<?= htmlspecialchars($row['dominio']) ?></span></td>
                                        <td class="p-4 text-right"><form method="POST" onsubmit="return confirm('¿Eliminar?')" class="inline"><input type="hidden" name="accion" value="eliminar_pendiente"><input type="hidden" name="id" value="<?= $row['id'] ?>"><input type="hidden" name="token" value="<?= $csrf_token ?>"><button class="text-red-500 text-xs font-bold border border-red-200 px-2 py-1 rounded hover:bg-red-50">Eliminar</button></form></td>
                                    <?php endif; ?>

                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="p-8 text-center text-gray-400">Sin registros.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex justify-center gap-2 pb-8">
            <?php if($page > 1): ?><a href="?tab=<?= $tab ?>&page=<?= $page - 1 ?>" class="px-4 py-2 bg-white border rounded-xl text-sm font-bold text-gray-600 hover:bg-gray-50 shadow-sm">Anterior</a><?php endif; ?>
            <?php if($result && $result->num_rows == $limit): ?><a href="?tab=<?= $tab ?>&page=<?= $page + 1 ?>" class="px-4 py-2 bg-white border rounded-xl text-sm font-bold text-gray-600 hover:bg-gray-50 shadow-sm">Siguiente</a><?php endif; ?>
        </div>

    </main>

    <div id="modalVip" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm items-center justify-center z-[100] hidden p-4 transition-all duration-300">
        <div class="bg-white p-8 rounded-3xl shadow-2xl w-full max-w-sm relative animate-fade-in border border-gray-100">
            
            <div class="flex justify-between items-center mb-6">
                <div class="w-12 h-12 bg-sky-50 text-[#54A6D8] rounded-2xl flex items-center justify-center shadow-sm">
                    <i class="fa-solid fa-star text-xl"></i>
                </div>
                <button onclick="document.getElementById('modalVip').classList.add('hidden'); document.getElementById('modalVip').classList.remove('flex');" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fa-solid fa-xmark text-2xl"></i>
                </button>
            </div>

            <h2 class="text-xl font-bold text-gray-900 mb-2 tracking-tight">Autorizar VIP</h2>
            <p class="text-gray-500 text-sm mb-6 leading-relaxed">Otorga acceso manual a una cuenta externa (Gmail, Hotmail, etc) para que pueda registrarse.</p>

            <form method="POST" action="">
                <input type="hidden" name="accion" value="autorizar_gmail">
                <input type="hidden" name="token" value="<?= $csrf_token ?>">
                
                <div class="mb-6">
                    <label class="block text-xs font-bold text-gray-700 mb-2 uppercase tracking-wider">Correo a autorizar</label>
                    <input type="email" name="correo" required placeholder="ejemplo@gmail.com" 
                           class="w-full px-4 py-3 rounded-xl bg-gray-50 border border-gray-200 focus:bg-white focus:ring-2 focus:ring-[#54A6D8] outline-none transition text-gray-800">
                </div>

                <button type="submit" class="w-full bg-[#54A6D8] hover:bg-sky-500 text-white font-bold py-3.5 rounded-xl shadow-md transition-all transform active:scale-[0.98] flex items-center justify-center gap-2">
                    <i class="fa-solid fa-check"></i> Conceder Acceso
                </button>
            </form>
        </div>
    </div>

    <?php 
    if(file_exists(__DIR__ . '/componentes/nav_bottom.php')) require_once __DIR__ . '/componentes/nav_bottom.php'; 
    $modals_loaded = false;
    if (file_exists(__DIR__ . '/componentes/modal_publicar.php')) { require_once __DIR__ . '/componentes/modal_publicar.php'; $modals_loaded = true; }
    if (file_exists(__DIR__ . '/componentes/modal_explora.php')) { require_once __DIR__ . '/componentes/modal_explora.php'; $modals_loaded = true; }
    ?>

    <?php if (!$modals_loaded): ?>
        <div id="modal-quick" class="fixed inset-0 z-50 hidden" role="dialog"><div class="absolute inset-0 bg-black/50" id="quick-close"></div></div>
        <div id="modal-explora" class="fixed inset-0 z-50 hidden" role="dialog"><div class="absolute inset-0 bg-black/50" id="explora-close"></div></div>
    <?php endif; ?>

    <script>
        function setupModal(triggerId, modalId, cardId, closeId) {
            const btn=document.getElementById(triggerId), modal=document.getElementById(modalId), card=document.getElementById(cardId), close=document.getElementById(closeId);
            if(!btn||!modal) return;
            const open=()=>{ modal.classList.remove('hidden'); requestAnimationFrame(()=>card?.classList.remove('translate-y-full','opacity-0')); document.body.style.overflow='hidden'; };
            const shut=()=>{ card?.classList.add('translate-y-full','opacity-0'); setTimeout(()=>{modal.classList.add('hidden');document.body.style.overflow='';},300); };
            btn.onclick=(e)=>{e.preventDefault();open();}; 
            if(close) close.onclick=shut; 
            modal.onclick=(e)=>{if(e.target===modal)shut();};
        }
        document.addEventListener('DOMContentLoaded', ()=>{
            setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
            setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
        });
    </script>

</body>
</html>
