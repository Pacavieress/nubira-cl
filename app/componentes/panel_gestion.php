<?php
/**
 * COMPONENTE: PANEL DE GESTIÓN (GRID DE ACCESOS)
 * ESTADO: PULIDO - Nubira 2.0 (Bento Grid + Soft Native UI)
 * * [UI] FLAT DESIGN: Sin sombras por defecto, elevación solo al hacer hover.
 * * [UI] LAYOUT: Botones en formato App (Ícono arriba, texto abajo) para evitar cortes.
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();

if ((!isset($es_propio) || !$es_propio) && (!isset($es_admin) || !$es_admin)) return;

// 1. PRE-CARGA PHP (Mensajes no leídos)
$panel_msgs = 0;
if (isset($_SESSION['usuario_id'])) {
    if (!isset($conn)) require_once __DIR__ . '/../../app/conexion.php'; 
    $uid = $_SESSION['usuario_id'];
    
    $sql = "SELECT COUNT(m.id) as total 
            FROM mensajes m
            INNER JOIN conversaciones c ON m.conversacion_id = c.id
            WHERE m.leido = 0 
            AND m.remitente_id != ? 
            AND (c.comprador_id = ? OR c.vendedor_id = ?)
            AND c.eliminado = 0";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("iii", $uid, $uid, $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) $panel_msgs = $row['total'];
        $stmt->close();
    }
}

// 2. DETECCIÓN DE DATOS BANCARIOS
$alerta_banco_activa = isset($falta_banco) ? $falta_banco : false;

// 2.5 [NUBIRA 2.0] BEHAVIOR-DRIVEN UI: AUTONOMÍA DEL PANEL
if (!isset($ha_comprado_algo) && isset($_SESSION['usuario_id'])) {
    $ha_comprado_algo = false;
    $uid_check = $_SESSION['usuario_id'];
    $stmt_chk = $conn->prepare("SELECT 1 FROM compras WHERE usuario_id = ? LIMIT 1");
    if ($stmt_chk) {
        $stmt_chk->bind_param("i", $uid_check);
        $stmt_chk->execute();
        $stmt_chk->store_result();
        $ha_comprado_algo = ($stmt_chk->num_rows > 0);
        $stmt_chk->close();
    }
}

// 3. CONFIGURACIÓN DE ACCESOS [titulo, link, icono, color_icon, bg_icon, badgeId]
$accesos_user = [
    ['Mis Publicaciones', '/mis-publicaciones', '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />', 'text-[#54A6D8]', 'bg-blue-50', null],
    ['Clases Vendidas', '/clases-vendidas', '<path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.499 5.516 50.636 50.636 0 0 1-2.657.813m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 10.499-3.342M6.75 12V18.75m10.5-6V18.75" />', 'text-[#54A6D8]', 'bg-blue-50', 'badge-ventas-clases'],
    ['Apuntes Vendidos', '/ventas-apuntes', '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />', 'text-[#54A6D8]', 'bg-blue-50', 'badge-ventas-apuntes'],
    ['Mis Compras', '/mis-compras', '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />', 'text-[#54A6D8]', 'bg-blue-50', null],
    ['Mis Ventas', '/mis-ventas', '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />', 'text-[#54A6D8]', 'bg-blue-50', null],
    ['Mis Contratos', '/mis-contratos', '<path stroke-linecap="round" stroke-linejoin="round" d="M10.125 2.25h-4.5c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125v-9M10.125 2.25h-4.5h.375a9 9 0 0 1 9 9v.375M10.125 2.25A3.375 3.375 0 0 1 13.5 5.625v1.5c0 .621.504 1.125 1.125 1.125h1.5a3.375 3.375 0 0 1 3.375 3.375M9 15l2.25 2.25L15 12" />', 'text-[#54A6D8]', 'bg-blue-50', null],
    ['Mi Billetera', '/mi-billetera', '<path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" />', 'text-[#54A6D8]', 'bg-blue-50', null],
    ['Configurar Cuenta', '/configurar-cuenta', '<path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />', 'text-[#54A6D8]', 'bg-blue-50', null],
    ['Mis Evaluaciones', '/mis-evaluaciones', '<path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.563.563 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.563.563 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" />', 'text-[#54A6D8]', 'bg-blue-50', 'badge-mis-evaluaciones'],
   ['Soporte', '/soporte', '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />', 'text-[#54A6D8]', 'bg-blue-50', 'badge-reclamos-user'],
    ['Métricas', '/app/metricas.php', '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />', 'text-[#54A6D8]', 'bg-blue-50', null]
];

$accesos_admin = [
    ['Usuarios', '/admin/usuarios', '<path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />', 'text-gray-500', 'bg-gray-50', 'badge-usuarios'],
    ['Servicios', '/admin/servicios', '<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 16.875h3.375m0 0h3.375m-3.375 0V13.5m0 3.375v3.375M6 10.5h2.25a2.25 2.25 0 002.25-2.25V6a2.25 2.25 0 00-2.25-2.25H6A2.25 2.25 0 003.75 6v2.25A2.25 2.25 0 006 10.5zm0 9.75h2.25A2.25 2.25 0 0010.5 18v-2.25a2.25 2.25 0 00-2.25-2.25H6a2.25 2.25 0 00-2.25 2.25V18A2.25 2.25 0 006 20.25zm9.75-9.75H18a2.25 2.25 0 002.25-2.25V6A2.25 2.25 0 0018 3.75h-2.25A2.25 2.25 0 0013.5 6v2.25a2.25 2.25 0 002.25 2.25z" />', 'text-gray-500', 'bg-gray-50', 'badge-servicios'],
    ['Subsidios', '/admin/ofertas', '<path stroke-linecap="round" stroke-linejoin="round" d="M15.362 5.214A8.252 8.252 0 0112 21 8.25 8.25 0 016.038 7.048 8.287 8.287 0 009 9.6a8.983 8.983 0 013.361-6.867 8.21 8.21 0 003 2.48z" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 18a3.75 3.75 0 00.495-7.467 5.99 5.99 0 00-1.925 3.546 5.974 5.974 0 01-2.133-1A3.75 3.75 0 0012 18z" />', 'text-gray-500', 'bg-gray-50', null],
    ['Compras Apuntes', '/app/admin_compras_apuntes.php', '<path stroke-linecap="round" stroke-linejoin="round" d="M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0c1.1.128 1.907 1.077 1.907 2.185zM9.75 9h.008v.008H9.75V9zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 4.5h.008v.008h-.008V13.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />', 'text-gray-500', 'bg-gray-50', null],
    ['Promo Apuntes', '/admin/ofertas-apuntes', '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />', 'text-gray-500', 'bg-gray-50', null],
    ['Becas / Cupones', '/admin/cupones', '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375z" />', 'text-gray-500', 'bg-gray-50', 'badge-admin-cupones'],
    ['Apuntes', '/admin/apuntes', '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 00-1.883 2.542l.857 6a2.25 2.25 0 002.227 1.932H19.05a2.25 2.25 0 002.227-1.932l.857-6a2.25 2.25 0 00-1.883-2.542m-16.5 0V6A2.25 2.25 0 016 3.75h3.879a1.5 1.5 0 011.06.44l2.122 2.12a1.5 1.5 0 001.06.44H18A2.25 2.25 0 0120.25 9v.776" />', 'text-gray-500', 'bg-gray-50', 'badge-apuntes'],
    ['Videos', '/admin/videos', '<path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />', 'text-gray-500', 'bg-gray-50', 'badge-admin-videos'],
    ['Retiros', '/admin/retiros', '<path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />', 'text-gray-500', 'bg-gray-50', 'badge-retiros'],
    ['Monitor Chats', '/admin/chats', '<path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.139 2.25 6.741v6.018z" />', 'text-gray-500', 'bg-gray-50', 'badge-admin-chats'],
    ['Monitor Aulas', '/app/admin_chats_aula.php', '<path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5" />', 'text-gray-500', 'bg-gray-50', null],
    ['Cuentas Bancarias', '/admin/cuentas-bancarias', '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0012 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75z" />', 'text-gray-500', 'bg-gray-50', null],
    ['Contratos', '/admin/contratos', '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />', 'text-gray-500', 'bg-gray-50', 'badge-admin-contratos'],
    ['Dominios', '/admin/dominios', '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.974 0-5.699-.533-8.15-1.467m16.3 0a8.996 8.996 0 01-.165 5.918" />', 'text-gray-500', 'bg-gray-50', null],
    ['Recordatorios', '/admin/recordatorios', '<path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zm6-3.75v.008h-.008v-.008h.008zm0-3v.008h-.008V12.75h.008zm0-3v.008h-.008V9.75h.008z" />', 'text-gray-500', 'bg-gray-50', null],
    ['Banners', '/admin/banners', '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />', 'text-gray-500', 'bg-gray-50', null],
    ['Marketing / Cards', '/admin/marketing-cards', '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />', 'text-gray-500', 'bg-gray-50', null],
    ['Banco de Imágenes', '/admin/banco-imagenes', '<path stroke-linecap="round" stroke-linejoin="round" d="M6 6.878V6a2.25 2.25 0 012.25-2.25h7.5A2.25 2.25 0 0118 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 004.5 9v.878m13.5-3A2.25 2.25 0 0119.5 9v.878m0 0a2.246 2.246 0 00-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0121 12v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6c0-.98.626-1.813 1.5-2.122" />', 'text-gray-500', 'bg-gray-50', null],
    ['Guías', '/admin/guias', '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />', 'text-gray-500', 'bg-gray-50', null],
    ['Avisos', '/admin/avisos', '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12z" /><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" />', 'text-gray-500', 'bg-gray-50', null],
    // TODO: tabla soporte pendiente
    // ['Soporte', '/admin/soporte', '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zm0 0c0 1.657 1.007 3 2.25 3S21 13.657 21 12a9 9 0 10-2.636 6.364M16.5 12V8.25" />', 'text-gray-500', 'bg-gray-50', 'badge-soporte'],
    ['Sugerencias', '/admin/reclamos', '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />', 'text-gray-500', 'bg-gray-50', 'badge-reclamos'],
    ['Solicitudes', '/admin/solicitudes', '<path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.766z" />', 'text-gray-500', 'bg-gray-50', 'badge-solicitudes'],
    ['Log Fail', '/admin/login-fallos', '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 3c-1.2 0-2.4.3-3.5.8L4.2 6.5C3.5 6.8 3 7.5 3 8.3v3.4c0 3.6 2.4 6.9 5.8 8.1 1.1.4 2.2.6 3.2.6s2.1-.2 3.2-.6c3.4-1.2 5.8-4.5 5.8-8.1V8.3c0-.8-.5-1.5-1.2-1.8l-4.3-2.7C14.4 3.3 13.2 3 12 3z" />', 'text-gray-500', 'bg-gray-50', 'badge-login-fallos'],
    ['Leads Gmail', '/admin/leads-gmail', '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Zm0 0c0 1.657 1.007 3 2.25 3S21 13.657 21 12a9 9 0 1 0-2.636 6.364M16.5 12V8.25" />', 'text-gray-500', 'bg-gray-50', null],
    ['Anuncio Video', '/app/enviar_anuncio_video_tutores.php', '<path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 1 8.835-2.535m0 0A23.74 23.74 0 0 1 18.795 3m.38 1.125a23.91 23.91 0 0 1 1.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 0 0 1.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 0 1 0 3.46" />', 'text-gray-500', 'bg-gray-50', 'badge-anuncio-video'],
    ['Despertar Dormidos', '/app/enviar_despertar_dormidos.php', '<path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.332.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />', 'text-gray-500', 'bg-gray-50', 'badge-despertar-dormidos'],
    ['Campaña de recuperación', '/app/enviar_cupon_alternativas.php', '<path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H4.5a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />', 'text-gray-500', 'bg-gray-50', null],
    ['Reportes', '/admin/reporte-servicios', '<path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 107.5 7.5h-7.5V6zM13.5 10.5H21A7.5 7.5 0 0013.5 3v7.5z" />', 'text-gray-500', 'bg-gray-50', null],
    ['Autores', '/admin/autores_servicios', '<path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />', 'text-gray-500', 'bg-gray-50', 'badge-autores'],
    ['Precios', '/admin/precios', '<path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />', 'text-gray-500', 'bg-gray-50', null],
    ['Accesos', '/admin/accesos', '<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />', 'text-gray-500', 'bg-gray-50', 'badge-accesos-vitrina'],
];
?>

<style>
.feature-badge{position:absolute;top:-4px;right:-8px;background-color:#54A6D8;color:#fff;font-size:9px;font-weight:700;letter-spacing:.3px;padding:2px 6px;border-radius:999px;box-shadow:0 2px 6px rgba(84,166,216,.45);border:1.5px solid #fff;line-height:1;text-transform:uppercase;pointer-events:none;white-space:nowrap;transition:opacity .3s ease,transform .3s ease;}
.feature-badge.is-hidden{opacity:0;transform:scale(.5);}
</style>

<div class="w-full space-y-8 animate-fade-in">
    <div class="flex flex-col">
        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 w-full">
            <?php foreach($accesos_user as $au): 
            $titulo = $au[0]; $linkRaw = $au[1]; $icono = $au[2]; $color = $au[3]; $bg = $au[4]; $badgeSelector = $au[5];
            
            // [NUBIRA 2.0] BEHAVIOR-DRIVEN UI: ESTADO INICIAL BLINDADO
            $es_tutor = (isset($es_creador) && $es_creador === true);
            $es_comprador = (isset($ha_comprado_algo) && $ha_comprado_algo === true);

            // Listas de control
            $herramientas_tutor = ['Mis Publicaciones', 'Clases Vendidas', 'Apuntes Vendidos', 'Mis Ventas', 'Mis Contratos', 'Mi Billetera', 'Métricas'];
            $herramientas_alumno = ['Mis Compras'];

            // 1. Si NO es tutor, eliminamos herramientas de tutor.
            if (!$es_tutor && in_array($titulo, $herramientas_tutor)) {
                continue;
            }

            // 2. Si NO es comprador real (pagado), eliminamos herramientas de alumno.
            if (!$es_comprador && in_array($titulo, $herramientas_alumno)) {
                continue;
            }

            // Lógica de enlaces (sin cambios)
            $esJS = (strpos($linkRaw, 'javascript:') === 0); 
            $href = $esJS ? '#' : $linkRaw;
            $onclick = $esJS ? 'onclick="' . str_replace('javascript:', '', $linkRaw) . '; return false;"' : '';
                
            $bVal = 0; $bCls = 'hidden';
            if ($badgeSelector === 'badge-sync' && $panel_msgs > 0) {
                $bVal = $panel_msgs; $bCls = 'flex';
            }
            $mostrar_punto_banco = ($titulo === 'Mi Billetera' && $alerta_banco_activa);
            $mostrar_punto_sugerencia = ($badgeSelector === 'punto-sugerencia' && isset($_SESSION['es_tutor_activo']) && $_SESSION['es_tutor_activo'] === true && isset($_SESSION['notif_sugerencia_vista']) && $_SESSION['notif_sugerencia_vista'] == 0);
        ?>
               <a href="<?= $href ?>" <?= $onclick ?> 
   class="group flex flex-col items-center justify-center gap-3 p-4 rounded-2xl bg-white border border-gray-100 hover:border-gray-200 hover:bg-gray-50/50 hover:shadow-md hover:scale-[1.01] active:scale-[0.98] transition-all duration-200 relative select-none cursor-pointer text-center h-full">
                    
                    <div class="w-12 h-12 rounded-xl <?= $bg ?> <?= $color ?> flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform duration-200 relative">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                            <?= $icono ?>
                        </svg>
                        
                        <?php if($mostrar_punto_banco): ?>
                            <span class="absolute -top-1 -right-1 flex h-3 w-3">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-3 w-3 bg-rose-500 border-2 border-white"></span>
                            </span>
                        <?php elseif($mostrar_punto_sugerencia): ?>
                            <span id="dot-sugerencia" class="absolute -top-1 -right-1 flex h-3 w-3">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-3 w-3 bg-rose-500 border-2 border-white"></span>
                            </span>
                        <?php elseif($badgeSelector && $badgeSelector !== 'badge-sync' && $badgeSelector !== 'punto-sugerencia'): ?>
                            <span class="<?= $badgeSelector ?> hidden absolute -top-1 -right-1 h-3 w-3">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-3 w-3 bg-rose-500 border-2 border-white"></span>
                            </span>
                        <?php endif; ?>
                    </div>

                   <span class="text-[13px] font-bold text-gray-700 group-hover:text-gray-900 tracking-tight leading-tight transition-colors duration-200">
    <?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?>
</span>
                    
                    <?php if($badgeSelector === 'badge-sync'): ?>
                        <span class="badge-sync <?= $bCls ?> absolute top-3 right-3 min-w-[1.5rem] h-5 px-1.5 items-center justify-center bg-[#54A6D8] text-white text-[10px] font-bold rounded-full border-2 border-white shadow-sm">
                            <?= $panel_msgs ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($titulo === 'Métricas'): ?>
                        <span class="feature-badge" data-feature-key="metricas" data-feature-launch="2026-05-29">Nuevo</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($es_admin): ?>
    <div class="pt-6 border-t border-gray-100 flex flex-col">
        <h3 class="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-4 pl-1">Administración</h3>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 w-full">
            <?php foreach($accesos_admin as $aa): 
                $titulo = $aa[0]; $link = $aa[1]; $icono = $aa[2]; $color = $aa[3]; $bg = $aa[4]; $badgeId = $aa[5];
            ?>
                <a href="<?= $link ?>" class="group flex flex-col items-center justify-center gap-2.5 p-3.5 rounded-xl bg-white border border-gray-100 hover:border-gray-200 hover:bg-gray-50/50 hover:shadow-md hover:scale-[1.01] active:scale-[0.98] transition-all duration-200 relative select-none cursor-pointer text-center h-full">
                    
                    <div class="w-10 h-10 rounded-lg <?= $bg ?> <?= $color ?> flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform duration-200 relative">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                            <?= $icono ?>
                        </svg>
                        
                        <?php if(!empty($badgeId)): ?>
                            <span id="<?= $badgeId ?>" class="hidden absolute -top-1.5 -right-1.5 min-w-[1rem] px-1 h-4 flex items-center justify-center bg-red-500 text-white text-[9px] font-bold rounded-full border border-white shadow-sm">0</span>
                        <?php endif; ?>
                    </div>
                    
                    <span class="text-[12px] font-semibold text-gray-700 group-hover:text-gray-900 tracking-tight leading-tight transition-colors duration-200">
                        <?= $titulo ?>
                    </span>
                    <?php if ($titulo === 'Marketing / Cards'): ?>
                        <span class="feature-badge" data-feature-key="marketing-cards" data-feature-launch="2026-07-09">Nuevo</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <a href="/logout" class="group flex items-center justify-center gap-2 w-full py-3.5 rounded-2xl border border-red-100 text-red-500 bg-white hover:bg-red-50 hover:scale-[1.01] active:scale-[0.98] transition-all duration-200 font-bold text-[13px] mt-4">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 group-hover:translate-x-0.5 transition-transform duration-200">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
        </svg>
        Cerrar Sesión
    </a>
</div>

<script>
function renderBadge(selector, total, isId) {
    const cantidad = parseInt(total || 0);
    
    // NUBIRA 2.0 BUGFIX: Usamos selector de atributo para atrapar IDs duplicados (Móvil + Escritorio)
    const queryString = isId ? `[id="${selector}"]` : `.${selector}`;
    const elements = document.querySelectorAll(queryString);
    
    elements.forEach(el => {
        if (cantidad > 0) {
            if (el.classList.contains('badge-sync') || isId) {
                el.textContent = cantidad > 99 ? '99+' : cantidad;
            }
            el.classList.remove('hidden');
            el.style.display = ''; 
        } else {
            if (el.classList.contains('badge-sync') || isId) {
                el.textContent = '0';
            }
            el.classList.add('hidden');
            el.style.display = 'none';
        }
    });
}

function actualizarPanelConDatos(data) {
    renderBadge('badge-ventas-clases', data.ventas_clases || data.ventas, false);
    renderBadge('badge-ventas-apuntes', data.ventas_apuntes || data.ventas, false);
    renderBadge('badge-reclamos-user', data.reclamos, false);
    renderBadge('badge-soporte-user', data.soporte, false);
    renderBadge('badge-mis-evaluaciones', data.valoraciones, false);
    
    renderBadge('badge-usuarios', data.admin_usuarios, true);
    renderBadge('badge-servicios', data.admin_servicios, true);
    renderBadge('badge-apuntes', data.admin_apuntes, true);
    renderBadge('badge-admin-videos', data.admin_videos, true);
    renderBadge('badge-retiros', data.admin_retiros, true);
    renderBadge('badge-admin-chats', data.admin_chats, true);
    renderBadge('badge-admin-pagos', data.admin_pagos, true);
    renderBadge('badge-admin-contratos', data.admin_contratos, true);
    renderBadge('badge-soporte', data.admin_soporte, true);
    renderBadge('badge-reclamos', data.admin_reclamos, true);
    renderBadge('badge-solicitudes', data.admin_solicitudes, true);
    renderBadge('badge-login-fallos', data.admin_login_fallos, true);
    renderBadge('badge-accesos-vitrina', data.admin_accesos, true);
    renderBadge('badge-autores', data.admin_perfil_incompleto, true);
    renderBadge('badge-anuncio-video', data.admin_anuncio_video, true);
    renderBadge('badge-despertar-dormidos', data.admin_despertar_dormidos, true);

    if (typeof window.updateHeaderDot === 'function') window.updateHeaderDot(data);
}

window.updateNubiraPanel = function() {
    fetch('/app/contar_alertas_sistema.php?v=' + Date.now())
        .then(res => res.json())
        .then(actualizarPanelConDatos)
        .catch(() => {});

    fetch('/app/contar_mensajes_nuevos.php?v=' + Date.now())
        .then(res => res.json())
        .then(data => renderBadge('badge-sync', data.total, false))
        .catch(() => {});
};

document.addEventListener('DOMContentLoaded', () => {
    window.updateNubiraPanel();
    
    // NUBIRA 2.0: Frecuencia de actualización unificada (5 segundos para todos)
    const intervalo = 5000; 
    
    if (window.nubiraInterval) clearInterval(window.nubiraInterval);
    window.nubiraInterval = setInterval(window.updateNubiraPanel, intervalo);
});

window.addEventListener('pageshow', function(event) {
    if (event.persisted) window.updateNubiraPanel();
});

let lastFocusUpdate = 0;
window.addEventListener('focus', () => {
    const now = Date.now();
    if (now - lastFocusUpdate > 3000) {
        lastFocusUpdate = now;
        window.updateNubiraPanel();
    }
});
// --- [NUBIRA 2.0] INTERCEPTOR BLINDADO MEJORADO ---
document.addEventListener('click', function(e) {
    
    // ---------------------------------------------------------
    // 1. INTERCEPTOR PARA USUARIO: Sugerir Idea
    // ---------------------------------------------------------
    const btnSugerencia = e.target.closest('a[href="/reclamos_sugerencias"]');
    if (btnSugerencia) {
        // NUBIRA 2.0: Ahora usamos la clase estándar, buscando clones (Móvil/Escritorio)
        const badgesSugerencia = document.querySelectorAll('.badge-reclamos-user');
        badgesSugerencia.forEach(b => b.remove());

        fetch('/app/apagar_notif_sugerencia.php', {
            method: 'POST',
            keepalive: true,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        }).catch(() => {});
    }

   // ---------------------------------------------------------
    // 2. INTERCEPTOR PARA ADMIN: Sugerencias / Reclamos
    // ---------------------------------------------------------
    const btnAdminReclamos = e.target.closest('a[href="/admin/reclamos"]');
    if (btnAdminReclamos) {
        // NUBIRA 2.0: Buscamos TODOS los badges (móvil y escritorio) y los destruimos
        const badgesReclamos = document.querySelectorAll('#badge-reclamos, .badge-reclamos');
        badgesReclamos.forEach(badge => badge.remove());

        // Petición silenciosa para apagar la notificación en BD
        fetch('/app/apagar_notif_admin_reclamos.php', {
            method: 'POST',
            keepalive: true,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        })
        .then(res => res.json())
        .then(data => console.log("Apagar notif:", data)) // <- Esto nos avisará en la consola si funciona
        .catch(() => {});
    }
});

// [NUBIRA 2.0] Badges "Nuevo" para features recientes
(function() {
    var SK = 'nubira_features_vistas', DAYS = 14;
    function read() { try { return JSON.parse(localStorage.getItem(SK) || '{}'); } catch(e) { return {}; } }
    function mark(k) { try { var v = read(); v[k] = Date.now(); localStorage.setItem(SK, JSON.stringify(v)); } catch(e) {} }
    function hide(b) { if (!b || b.classList.contains('is-hidden')) return; b.classList.add('is-hidden'); setTimeout(function() { b.remove(); }, 350); }
    var vistas = read(), now = Date.now();
    document.querySelectorAll('.feature-badge[data-feature-key]').forEach(function(badge) {
        var key = badge.dataset.featureKey;
        var launch = badge.dataset.featureLaunch;
        if (vistas[key]) { hide(badge); return; }
        if (launch && (now - new Date(launch + 'T00:00:00').getTime()) / 86400000 > DAYS) { hide(badge); return; }
        var trigger = badge.closest('a, button') || badge.parentElement;
        trigger.addEventListener('click', function() { mark(key); hide(badge); }, { once: true });
    });
})();
</script>