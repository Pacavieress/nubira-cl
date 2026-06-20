<?php
/**
 * VISTA: ADMINISTRACIÓN DE CUPONES / BECAS
 * UBICACIÓN: public_html/app/cupones.php
 * ESTADO: Nubira 2.0 (Componentes Nativos + UI Ledger)
 */
session_start();
require_once __DIR__ . '/conexion.php';

// --- CONFIGURACIÓN DE ICONOS ---
if (file_exists(__DIR__ . '/iconos.php')) {
    require_once __DIR__ . '/iconos.php';
}

// 1. Seguridad estricta Nubira 2.0
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header("Location: /vitrina");
    exit;
}

// Feedback visual sanitizado
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// 2. Obtener todos los cupones existentes
$query = "SELECT id, codigo, porcentaje_descuento, usos_actuales, usos_maximos, fecha_expiracion, servicio_id FROM cupones ORDER BY creado_en DESC";
$resultado = $conn->query($query);

// 3. Obtener servicios para el menú desplegable (NUBIRA FIX: Solo aprobados para evitar romper la UI con servicios fantasma)
$query_servicios = "SELECT id, titulo, precio FROM servicios WHERE estado = 'aprobado' ORDER BY titulo ASC";
$servicios_db = $conn->query($query_servicios);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <title>Bóveda de Becas | Nubira Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="text-slate-800 antialiased overflow-x-hidden">

    <?php 
    $page_title = "Bóveda de Becas";
    if(file_exists(__DIR__ . '/componentes/header.php')) require_once __DIR__ . '/componentes/header.php';
    if(file_exists(__DIR__ . '/componentes/sidebar.php')) require_once __DIR__ . '/componentes/sidebar.php';
    ?>

    <main class="pt-24 pb-28 lg:ml-64 px-4 w-full max-w-[1100px] mx-auto">
        
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
            <div>
                <h1 class="text-2xl md:text-3xl font-extrabold text-slate-900 tracking-tight">Bóveda de Becas</h1>
                <p class="text-slate-400 text-xs font-bold uppercase tracking-widest mt-1">Control de beneficios</p>
            </div>
            <button id="btn-nueva-beca" class="bg-[#54A6D8] text-white font-extrabold py-3 px-6 rounded-2xl text-[11px] uppercase tracking-widest transition-all hover:scale-[1.01] hover:shadow-md hover:bg-blue-600 shadow-sm flex items-center justify-center gap-2">
                <span class="text-sm"><?= function_exists('icon') ? icon('plus') : '+' ?></span> Nueva Beca
            </button>
        </div>

        <?php if ($flash): ?>
            <div class="mb-6 p-4 rounded-2xl border bg-emerald-50 border-emerald-100 text-emerald-800 text-sm font-bold flex items-center gap-3 animate-fade-in">
                <?= function_exists('icon') ? icon('check-circle') : '✓' ?> <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="bg-white border border-gray-100 rounded-3xl overflow-hidden shadow-sm">
            <?php if ($resultado && $resultado->num_rows > 0): ?>
                <div class="flex flex-col">
                    <?php while($c = $resultado->fetch_assoc()): ?>
                        <div class="group flex flex-col md:flex-row md:items-center justify-between p-5 border-b border-gray-50 last:border-0 hover:bg-slate-50/50 transition-all gap-4">
                            
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 rounded-2xl bg-sky-50 text-[#54A6D8] flex items-center justify-center font-black border border-sky-100 shrink-0">
                                    <span class="text-xl"><?= function_exists('icon') ? icon('ticket') : 'T' ?></span>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <code class="text-sm font-extrabold text-slate-900 tracking-tight uppercase"><?= htmlspecialchars($c['codigo'], ENT_QUOTES, 'UTF-8') ?></code>
                                        <?php if (!empty($c['servicio_id'])): ?>
                                            <span class="px-2 py-0.5 rounded-md bg-indigo-50 border border-indigo-100 text-indigo-600 text-[9px] font-black uppercase tracking-widest">
                                                Servicio #<?= htmlspecialchars($c['servicio_id'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded-md bg-slate-100 border border-slate-200 text-slate-500 text-[9px] font-black uppercase tracking-widest">
                                                Global
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">
                                        Expira: <?= $c['fecha_expiracion'] ? date('d M, Y', strtotime($c['fecha_expiracion'])) : 'Sin límite' ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between md:justify-end gap-6 w-full md:w-auto">
                                <div class="text-left md:text-right">
                                    <span class="block font-black text-emerald-500 text-lg leading-none"><?= (int)$c['porcentaje_descuento'] ?>% OFF</span>
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">
                                        Usos: <span class="text-slate-700"><?= (int)$c['usos_actuales'] ?></span> / <?= (int)$c['usos_maximos'] ?>
                                    </span>
                                </div>
                                <button onclick="if(confirm('¿Eliminar beca permanentemente?')) window.location.href='/app/admin_procesar_cupon.php?del=<?= $c['id'] ?>'" 
                                        class="w-10 h-10 flex items-center justify-center rounded-2xl bg-white border border-gray-100 text-slate-400 transition-all hover:scale-[1.01] hover:shadow-md hover:text-rose-500 hover:border-rose-200 hover:bg-rose-50 shrink-0" title="Eliminar beca">
                                    <?= function_exists('icon') ? icon('trash') : 'X' ?>
                                </button>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-24 bg-gray-50/50">
                    <div class="w-20 h-20 bg-white shadow-sm border border-gray-100 rounded-3xl flex items-center justify-center mx-auto mb-4 text-slate-300">
                        <span class="text-3xl"><?= function_exists('icon') ? icon('ticket') : 'T' ?></span>
                    </div>
                    <h3 class="text-slate-800 font-bold text-lg tracking-tight">Sin becas activas</h3>
                    <p class="text-slate-400 text-xs font-bold mt-1 uppercase tracking-widest">No hay códigos de descuento creados en el sistema.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <div id="modal-nuevo-cupon" class="fixed inset-0 z-[100] hidden items-center justify-center px-4">
        <div id="backdrop-nuevo-cupon" class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm opacity-0 transition-opacity duration-300"></div>
        <div id="card-nuevo-cupon" class="relative w-full max-w-[450px] bg-white rounded-3xl shadow-xl border border-gray-100 p-6 md:p-8 transform translate-y-full scale-95 opacity-0 transition-all duration-300">
            
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h3 class="text-xl font-extrabold tracking-tight text-slate-900">Crear Beca</h3>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">Nuevo código de descuento</p>
                </div>
                <button id="close-nuevo-cupon" class="w-8 h-8 flex items-center justify-center rounded-full bg-gray-50 text-slate-400 hover:text-slate-700 transition-all hover:scale-[1.01]">
                    <?= function_exists('icon') ? icon('close') : 'x' ?>
                </button>
            </div>

            <form action="/app/admin_procesar_cupon.php" method="POST" class="space-y-4">
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2 pl-1">Código Identificador</label>
                    <input type="text" name="codigo" placeholder="Ej: BECA-JUAN-2026" required 
                           class="w-full bg-gray-50 border border-gray-100 rounded-2xl px-4 py-3 outline-none font-bold text-slate-700 uppercase focus:bg-white focus:border-[#54A6D8] focus:ring-2 focus:ring-[#54A6D8]/20 transition-all placeholder:normal-case placeholder:font-medium placeholder:text-slate-400">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2 pl-1">Descuento (%)</label>
                        <input type="number" name="porcentaje_descuento" value="100" min="1" max="100" required
                               class="w-full bg-gray-50 border border-gray-100 rounded-2xl px-4 py-3 outline-none font-bold text-slate-700 focus:bg-white focus:border-[#54A6D8] focus:ring-2 focus:ring-[#54A6D8]/20 transition-all">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2 pl-1">Límite Usos</label>
                        <input type="number" name="usos_maximos" value="1" min="1" required
                               class="w-full bg-gray-50 border border-gray-100 rounded-2xl px-4 py-3 outline-none font-bold text-slate-700 focus:bg-white focus:border-[#54A6D8] focus:ring-2 focus:ring-[#54A6D8]/20 transition-all">
                    </div>
                </div>

                <!-- CAMPO NUEVO INYECTADO AQUÍ -->
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2 pl-1">Fecha de Expiración (Opcional)</label>
                    <input type="date" name="fecha_expiracion" 
                           class="w-full bg-gray-50 border border-gray-100 rounded-2xl px-4 py-3 outline-none font-bold text-slate-700 focus:bg-white focus:border-[#54A6D8] focus:ring-2 focus:ring-[#54A6D8]/20 transition-all text-sm">
                </div>

                <div class="p-4 rounded-2xl border border-indigo-50 bg-indigo-50/30">
                    <label class="block text-[10px] font-bold text-indigo-500 uppercase tracking-widest mb-2 pl-1">Exclusividad de Servicio (Opcional)</label>
                    <select name="servicio_id" class="w-full bg-white border border-indigo-100 rounded-xl px-4 py-3 outline-none font-bold text-slate-700 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-400/20 transition-all cursor-pointer text-sm">
                        <option value="">Cualquier Servicio (Beca Global)</option>
                        
                        <?php if (isset($servicios_db) && $servicios_db->num_rows > 0): ?>
                            <?php while($srv = $servicios_db->fetch_assoc()): ?>
                                <?php 
                                    // Lógica Nubira 2.0: Nombre corto + Precio formateado
                                    $nombre_corto = (strlen($srv['titulo']) > 20) ? substr($srv['titulo'], 0, 17) . '...' : $srv['titulo'];
                                    $precio_f = number_format($srv['precio'], 0, ',', '.');
                                ?>
                                <option value="<?= $srv['id'] ?>">
                                    <?= htmlspecialchars($nombre_corto, ENT_QUOTES, 'UTF-8') ?> — $<?= $precio_f ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <button type="submit" class="w-full bg-[#54A6D8] text-white font-extrabold py-4 rounded-2xl shadow-sm transition-all mt-4 hover:shadow-md hover:scale-[1.01] text-[11px] uppercase tracking-widest">
                    Activar Beca
                </button>
            </form>
        </div>
    </div>

    <?php 
    if(file_exists(__DIR__ . '/componentes/nav_bottom.php')) require_once __DIR__ . '/componentes/nav_bottom.php'; 
    if(file_exists(__DIR__ . '/componentes/modal_publicar.php')) require_once __DIR__ . '/componentes/modal_publicar.php'; 
    if(file_exists(__DIR__ . '/componentes/modal_explora.php')) require_once __DIR__ . '/componentes/modal_explora.php'; 
    ?>

   <script>
        document.addEventListener('DOMContentLoaded', () => {
            const trigger = document.getElementById('btn-nueva-beca');
            const modal = document.getElementById('modal-nuevo-cupon');
            const card = document.getElementById('card-nuevo-cupon');
            const closeBtn = document.getElementById('close-nuevo-cupon');
            const backdrop = document.getElementById('backdrop-nuevo-cupon');

            // Función para abrir suavemente
            const openModal = (e) => {
                e.preventDefault();
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                
                // Esperamos un frame para que Tailwind pueda animar la transición
                requestAnimationFrame(() => {
                    backdrop.classList.remove('opacity-0');
                    card.classList.remove('translate-y-full', 'opacity-0', 'scale-95');
                });
                document.body.style.overflow = 'hidden'; // Evita scroll de fondo
            };

            // Función para cerrar suavemente
            const closeModal = () => {
                backdrop.classList.add('opacity-0');
                card.classList.add('translate-y-full', 'opacity-0', 'scale-95');
                
                // Esperamos que termine la animación (300ms) antes de ocultar el div
                setTimeout(() => {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                    document.body.style.overflow = '';
                }, 300);
            };

            // Asignar eventos
            if (trigger) trigger.addEventListener('click', openModal);
            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            if (backdrop) backdrop.addEventListener('click', closeModal);
        });
    </script>
</body>
</html>