<?php
session_start();
require_once 'conexion.php';

// Seguridad: Solo admin
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') { die("Acceso denegado"); }

$id_retiro = (int)($_GET['id'] ?? 0);
if ($id_retiro <= 0) die("ID Inválido");

$sql = "SELECT id, monto, monto_subsidio, monto_comision, fecha_creacion FROM contratos WHERE solicitud_retiro_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id_retiro);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo "<p class='text-center text-slate-500 py-6 font-medium'><i class='fa-solid fa-book-open mr-2'></i>Este retiro corresponde únicamente a ventas de Apuntes/PDFs.</p>";
    exit;
}
?>
<div class="space-y-4">
    <table class="w-full text-sm text-left">
        <thead class="text-[10px] uppercase text-slate-400 font-bold border-b border-slate-100">
            <tr>
                <th class="pb-2">Contrato</th>
                <th class="pb-2 text-right">Pagado (Alumno)</th>
                <th class="pb-2 text-right">Cupón (Nubira)</th>
                <th class="pb-2 text-right">Comisión</th>
                <th class="pb-2 text-right">Líquido Tutor</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            <?php 
            $t_alumno = 0; $t_subsidio = 0; $t_comision = 0; $t_liquido = 0;
            while($c = $res->fetch_assoc()): 
                $liquido = ($c['monto'] + $c['monto_subsidio']) - $c['monto_comision'];
                $t_alumno += $c['monto']; $t_subsidio += $c['monto_subsidio'];
                $t_comision += $c['monto_comision']; $t_liquido += $liquido;
            ?>
            <tr class="text-slate-600 hover:bg-slate-50 transition-colors">
                <td class="py-3 font-mono text-xs font-semibold text-slate-800">#<?= $c['id'] ?></td>
                <td class="py-3 text-right">$<?= number_format($c['monto'],0,',','.') ?></td>
                <td class="py-3 text-right text-emerald-600"><?= $c['monto_subsidio'] > 0 ? '+$'.number_format($c['monto_subsidio'],0,',','.') : '-' ?></td>
                <td class="py-3 text-right text-red-500"><?= $c['monto_comision'] > 0 ? '-$'.number_format($c['monto_comision'],0,',','.') : '-' ?></td>
                <td class="py-3 text-right font-bold text-[#54A6D8]">$<?= number_format($liquido,0,',','.') ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
        <tfoot class="border-t-2 border-slate-200 font-bold text-slate-800 bg-slate-50">
            <tr>
                <td class="py-4 px-2 text-xs uppercase text-slate-500 rounded-bl-lg">Totales (Servicios)</td>
                <td class="py-4 text-right">$<?= number_format($t_alumno,0,',','.') ?></td>
                <td class="py-4 text-right text-emerald-600">+$<?= number_format($t_subsidio,0,',','.') ?></td>
                <td class="py-4 text-right text-red-500">-$<?= number_format($t_comision,0,',','.') ?></td>
                <td class="py-4 px-2 text-right text-lg text-slate-900 rounded-br-lg">$<?= number_format($t_liquido,0,',','.') ?></td>
            </tr>
        </tfoot>
    </table>
    <p class="text-[11px] text-slate-500 italic flex items-center gap-2 bg-blue-50/50 p-3 rounded-lg border border-blue-100 mt-4">
        <i class="fa-solid fa-circle-info text-blue-400"></i> Si el monto liquidado es menor al total solicitado, la diferencia corresponde a ventas de Apuntes.
    </p>
</div>