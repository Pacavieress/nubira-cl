<?php
session_start();
require_once '../app/conexion.php';
require_once __DIR__ . '/helpers/imagen_servicio.php'; // [BANCO] resolver unificado

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /login');
    exit;
}

$id = intval($_GET['id'] ?? 0);

$stmt = $conn->prepare("SELECT s.*, a.nombre AS nombre_alumno, bi.archivo AS banco_archivo
                        FROM servicios s
                        LEFT JOIN alumnos a ON s.alumno_id = a.id
                        LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
                        WHERE s.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$servicio = $res->fetch_assoc();
$stmt->close();

if (!$servicio) {
    echo "<h2 class='text-red-600 text-center mt-12'>Servicio no encontrado</h2>";
    exit;
}

/* --- Imagen lógica (banco → legacy → placeholder, vía helper unificado) --- */
$imgURL = url_portada($servicio);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalle Servicio — Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen">

<div class="max-w-3xl mx-auto mt-10 bg-white rounded-xl shadow overflow-hidden">

    <!-- Imagen superior -->
    <div class="w-full h-72 bg-gray-200 overflow-hidden">
        <img src="<?= $imgURL ?>" 
             class="w-full h-full object-cover hover:scale-105 transition-transform duration-300 cursor-pointer"
             onclick="abrirZoom('<?= $imgURL ?>')">
    </div>

    <div class="p-8">

        <!-- Título -->
        <h1 class="text-3xl font-bold text-gray-900 mb-4"><?= htmlspecialchars($servicio['titulo']) ?></h1>

        <!-- Datos principales -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-base">

            <p><b>Oferente:</b> <?= htmlspecialchars($servicio['nombre_oferente'] ?? $servicio['nombre_alumno'] ?? '—') ?></p>
            <p><b>Categoría:</b> <?= htmlspecialchars($servicio['categoria']) ?></p>

            <p><b>Modalidad:</b> <?= htmlspecialchars($servicio['modalidad']) ?></p>
            <p><b>Publicado:</b> <?= htmlspecialchars($servicio['fecha_publicacion'] ?? '-') ?></p>

            <p><b>Fecha revisión:</b> <?= htmlspecialchars($servicio['fecha_revision'] ?? '-') ?></p>

            <p><b>Estado:</b>
                <?php
                if ($servicio['estado'] === 'pendiente') {
                    echo '<span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded text-xs">Pendiente</span>';
                } elseif ($servicio['estado'] === 'aprobado') {
                    echo '<span class="bg-green-100 text-green-800 px-2 py-1 rounded text-xs">Aprobado</span>';
                } elseif ($servicio['estado'] === 'rechazado') {
                    echo '<span class="bg-red-100 text-red-800 px-2 py-1 rounded text-xs">Rechazado</span>';
                }
                ?>
            </p>

        </div>

        <?php if (!empty($servicio['motivo_rechazo'])): ?>
            <div class="mt-4 text-red-700 bg-red-50 p-3 rounded border border-red-200">
                <b>Motivo de rechazo:</b> <?= htmlspecialchars($servicio['motivo_rechazo']) ?>
            </div>
        <?php endif; ?>

        <!-- Descripción -->
        <div class="mt-6">
            <p class="font-semibold mb-2 text-gray-800">Descripción:</p>
            <div class="p-4 border rounded bg-gray-50 text-gray-800 whitespace-pre-line break-words max-h-60 overflow-y-auto">
                <?= nl2br(htmlspecialchars($servicio['descripcion'])) ?>
            </div>
        </div>

        <!-- Acciones -->
        <div class="flex flex-wrap gap-3 mt-8">

            <a href="/admin/servicios"
               class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded shadow transition">
               ← Volver
            </a>

            <?php if ($servicio['estado'] === 'pendiente'): ?>

                <form method="POST" action="/admin/admin_servicios_accion.php">
                    <input type="hidden" name="id_servicio" value="<?= $servicio['id'] ?>">
                    <input type="hidden" name="accion" value="aprobar">
                    <button class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded shadow transition">
                        Aprobar
                    </button>
                </form>

                <button onclick="abrirModalRechazo(<?= $servicio['id'] ?>)"
                        class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded shadow transition">
                    Rechazar
                </button>

            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODAL ZOOM -->
<div id="zoom-modal" class="hidden fixed inset-0 bg-black bg-opacity-90 z-50 flex items-center justify-center">
    <img id="zoom-img" class="max-w-full max-h-full rounded shadow-lg">
    <button onclick="cerrarZoom()" class="absolute top-6 right-6 text-white text-2xl">✕</button>
</div>

<!-- MODAL RECHAZO -->
<div id="modal-rechazo"
     class="hidden fixed inset-0 bg-black bg-opacity-40 z-50 flex items-center justify-center">

    <form id="form-rechazo" action="/admin/admin_servicios_accion.php"
          method="POST" class="bg-white p-6 rounded shadow-md w-full max-w-sm">

        <input type="hidden" name="id_servicio" id="modal_id_servicio">
        <input type="hidden" name="accion" value="rechazar">

        <label class="block mb-2 font-semibold">Motivo del rechazo</label>
        <textarea name="motivo_rechazo" id="modal_motivo_rechazo" required
                  class="w-full border rounded px-3 py-2 mb-4"></textarea>

        <div class="flex justify-end gap-2">
            <button type="button" onclick="cerrarModalRechazo()" 
                    class="bg-gray-200 px-4 py-2 rounded">Cancelar</button>
            <button type="submit"
                    class="bg-red-600 text-white px-4 py-2 rounded">Rechazar</button>
        </div>
    </form>
</div>

<script>
function abrirZoom(src){
    document.getElementById('zoom-img').src = src;
    document.getElementById('zoom-modal').classList.remove('hidden');
}
function cerrarZoom(){
    document.getElementById('zoom-modal').classList.add('hidden');
}

function abrirModalRechazo(id){
    document.getElementById('modal_id_servicio').value = id;
    document.getElementById('modal_motivo_rechazo').value = '';
    document.getElementById('modal-rechazo').classList.remove('hidden');
}
function cerrarModalRechazo(){
    document.getElementById('modal-rechazo').classList.add('hidden');
}
</script>

</body>
</html>
