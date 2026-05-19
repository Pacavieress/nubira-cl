<?php
session_start();
require_once '../app/conexion.php';
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(403); exit('No autorizado');
}
$estado = $_GET['estado'] ?? 'pendiente';
$where = $estado === 'todos' ? '' : "WHERE estado = ?";
$sql = "SELECT r.*, a.nombre AS usuario_nombre, a.correo 
        FROM reclamos_sugerencias r
        JOIN alumnos a ON r.usuario_id = a.id
        $where
        ORDER BY r.fecha DESC";
$stmt = $conn->prepare($where ? $sql : str_replace('WHERE estado = ?', '', $sql));
if ($where) $stmt->bind_param('s', $estado);
$stmt->execute();
$reclamos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<?php if (!$reclamos): ?>
    <tr>
        <td colspan="7" class="py-6 text-center text-gray-500">Sin reclamos o sugerencias.</td>
    </tr>
<?php else: foreach ($reclamos as $r): ?>
    <tr class="border-b">
        <td class="py-2 px-4"><?= date('d-m-Y H:i', strtotime($r['fecha'])) ?></td>
        <td class="py-2 px-4">
            <div class="font-bold"><?= htmlspecialchars($r['usuario_nombre']) ?></div>
            <div class="text-xs text-gray-400"><?= htmlspecialchars($r['correo']) ?></div>
        </td>
        <td class="py-2 px-4"><?= htmlspecialchars($r['categoria']) ?></td>
        <td class="py-2 px-4"><?= nl2br(htmlspecialchars($r['texto'])) ?></td>
        <td class="py-2 px-4">
            <span class="px-2 py-1 rounded <?= $r['estado']=='pendiente'?'bg-yellow-200 text-yellow-900':'bg-green-200 text-green-900' ?>">
                <?= ucfirst($r['estado']) ?>
            </span>
        </td>
        <td class="py-2 px-4">
            <?= $r['respuesta_admin'] ? nl2br(htmlspecialchars($r['respuesta_admin'])) : '<span class="italic text-gray-400">Sin respuesta</span>' ?>
        </td>
        <td class="py-2 px-4">
            <?php if ($r['estado']=='pendiente'): ?>
                <form method="POST" class="flex flex-col gap-2" action="/admin/reclamos?estado=<?= htmlspecialchars($estado) ?>">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <textarea name="respuesta_admin" placeholder="Responder..." rows="2"
                              class="border rounded px-2 py-1 mb-2 w-full"></textarea>
                    <button name="responder" class="bg-green-600 text-white rounded px-3 py-1 hover:bg-green-700">Responder y cerrar</button>
                    <button name="resolver" type="submit" class="bg-blue-600 text-white rounded px-3 py-1 hover:bg-blue-700">Marcar como resuelto</button>
                </form>
            <?php else: ?>
                <span class="text-green-700">Resuelto</span>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; endif; ?>
