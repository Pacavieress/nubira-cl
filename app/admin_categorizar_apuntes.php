<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate");

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /login'); exit;
}

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/iconos.php';

// Materias
$materias = [];
$res_m = $conn->query("SELECT slug, nombre FROM materias WHERE activa = 1 ORDER BY orden ASC");
while ($m = $res_m->fetch_assoc()) $materias[] = $m;

// Filtro
$filtro = $_GET['filtro'] ?? 'sin_materia';
$where = match($filtro) {
    'con_materia' => "AND ap.materia IS NOT NULL AND ap.materia != ''",
    'paes'        => "AND ap.nivel_academico = 'paes'",
    'todos'       => "",
    default       => "AND (ap.materia IS NULL OR ap.materia = '')",
};

$sql = "SELECT ap.id, ap.titulo, ap.asignatura, ap.materia, ap.subtema, ap.nivel_academico, 
               ap.precio, ap.descargas, ap.portada, ap.archivo,
               a.nombre AS autor, a.institucion
        FROM apuntes ap
        INNER JOIN alumnos a ON ap.id_alumno = a.id
        WHERE ap.estado = 'aprobado' $where
        ORDER BY ap.id DESC";
$res = $conn->query($sql);

// Contadores
$cnt = $conn->query("SELECT 
    SUM(CASE WHEN materia IS NULL OR materia = '' THEN 1 ELSE 0 END) sin_mat,
    SUM(CASE WHEN materia IS NOT NULL AND materia != '' THEN 1 ELSE 0 END) con_mat,
    SUM(CASE WHEN nivel_academico = 'paes' THEN 1 ELSE 0 END) paes,
    COUNT(*) total
    FROM apuntes WHERE estado = 'aprobado'")->fetch_assoc();

$pct = $cnt['total'] > 0 ? round(($cnt['con_mat'] / $cnt['total']) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Categorizar apuntes | Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>body{font-family:'Inter',sans-serif} .no-scrollbar::-webkit-scrollbar{display:none} .no-scrollbar{scrollbar-width:none}</style>
</head>
<body class="bg-gray-50 text-gray-900 antialiased">

<div class="max-w-[1100px] mx-auto px-4 md:px-8 py-6 md:py-10">
    <a href="/panel_gestion" class="inline-flex items-center gap-2 text-sm font-medium text-gray-500 hover:text-[#54A6D8] mb-6 transition">
        <?= icon('arrow-left', 'w-4 h-4') ?> Volver al panel
    </a>

    <header class="mb-8">
        <h1 class="text-3xl md:text-4xl font-bold text-gray-900 tracking-tight leading-tight">Categorizar apuntes</h1>
        <p class="text-sm text-gray-500 mt-2">Asigna materia y nivel académico a cada apunte.</p>
        
        <div class="mt-6 bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between mb-3">
                <span class="text-sm font-semibold text-gray-700">Progreso</span>
                <span class="text-sm font-bold text-[#54A6D8]" id="progreso-texto"><?= (int)$cnt['con_mat'] ?> de <?= (int)$cnt['total'] ?> categorizados</span>
            </div>
            <div class="w-full h-2 bg-gray-100 rounded-full overflow-hidden">
                <div id="progreso-barra" class="h-full bg-gradient-to-r from-sky-400 to-[#54A6D8] rounded-full transition-all duration-500" style="width: <?= $pct ?>%"></div>
            </div>
        </div>
    </header>

    <div class="flex gap-2 mb-6 overflow-x-auto pb-2 no-scrollbar">
        <a href="?filtro=sin_materia" class="shrink-0 px-4 py-2 rounded-full text-xs font-bold transition-all <?= $filtro==='sin_materia'?'bg-gray-900 text-white':'bg-white border border-gray-200 text-gray-700 hover:border-gray-400' ?>">Sin materia (<?= (int)$cnt['sin_mat'] ?>)</a>
        <a href="?filtro=con_materia" class="shrink-0 px-4 py-2 rounded-full text-xs font-bold transition-all <?= $filtro==='con_materia'?'bg-gray-900 text-white':'bg-white border border-gray-200 text-gray-700 hover:border-gray-400' ?>">Categorizados (<?= (int)$cnt['con_mat'] ?>)</a>
        <a href="?filtro=paes" class="shrink-0 px-4 py-2 rounded-full text-xs font-bold transition-all <?= $filtro==='paes'?'bg-gray-900 text-white':'bg-white border border-gray-200 text-gray-700 hover:border-gray-400' ?>">PAES (<?= (int)$cnt['paes'] ?>)</a>
        <a href="?filtro=todos" class="shrink-0 px-4 py-2 rounded-full text-xs font-bold transition-all <?= $filtro==='todos'?'bg-gray-900 text-white':'bg-white border border-gray-200 text-gray-700 hover:border-gray-400' ?>">Todos (<?= (int)$cnt['total'] ?>)</a>
    </div>

    <div class="space-y-3">
        <?php if ($res && $res->num_rows > 0): ?>
            <?php while ($a = $res->fetch_assoc()): 
                $img = !empty($a['portada']) 
                    ? '/upload/portadas/' . htmlspecialchars(basename($a['portada']))
                    : 'https://nubira.cl/upload/servicios/default_clases.webp';
            ?>
            <article class="apunte-card bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-all p-4" data-apunte-id="<?= (int)$a['id'] ?>">
                <div class="flex gap-4 mb-3">
                    <div class="w-20 h-20 md:w-24 md:h-24 rounded-xl overflow-hidden bg-gray-100 shrink-0">
                        <img src="<?= $img ?>" alt="" class="w-full h-full object-cover" onerror="this.src='https://nubira.cl/upload/servicios/default_clases.webp';">
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="text-sm md:text-base font-bold text-gray-900 leading-tight line-clamp-2 mb-1"><?= htmlspecialchars($a['titulo']) ?></h3>
                        <p class="text-xs text-gray-500 truncate">
                            <?= htmlspecialchars($a['autor']) ?>
                            <?php if (!empty($a['asignatura'])): ?> · <span class="font-medium text-gray-700"><?= htmlspecialchars($a['asignatura']) ?></span><?php endif; ?>
                            <?php if (!empty($a['institucion'])): ?> · <?= htmlspecialchars($a['institucion']) ?><?php endif; ?>
                            · <?= (int)$a['descargas'] ?> descargas
                        </p>
                    </div>
                    <span class="estado-indicador shrink-0 self-start">
                        <?php if (!empty($a['materia'])): ?>
                            <span class="text-emerald-500"><?= icon('check', 'w-5 h-5') ?></span>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                    <select class="select-nivel text-sm bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 font-medium focus:border-[#54A6D8] focus:ring-2 focus:ring-sky-100 outline-none transition">
                        <option value="universitario" <?= $a['nivel_academico']==='universitario'?'selected':'' ?>>🎓 Universitario</option>
                        <option value="paes" <?= $a['nivel_academico']==='paes'?'selected':'' ?>>📘 PAES</option>
                        <option value="escolar" <?= $a['nivel_academico']==='escolar'?'selected':'' ?>>📒 Escolar</option>
                    </select>
                    <select class="select-materia text-sm bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 font-medium focus:border-[#54A6D8] focus:ring-2 focus:ring-sky-100 outline-none transition">
                        <option value="">Sin materia...</option>
                        <?php foreach ($materias as $m): ?>
                            <option value="<?= htmlspecialchars($m['slug']) ?>" <?= ($a['materia']===$m['slug'])?'selected':'' ?>><?= htmlspecialchars($m['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="input-subtema text-sm bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 font-medium focus:border-[#54A6D8] focus:ring-2 focus:ring-sky-100 outline-none transition" placeholder="Subtema (opcional)" maxlength="80" value="<?= htmlspecialchars($a['subtema'] ?? '') ?>">
                </div>
            </article>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="bg-white border border-dashed border-gray-200 rounded-2xl p-10 text-center">
                <p class="text-sm text-gray-500 font-medium">No hay apuntes en este filtro.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.apunte-card').forEach(card => {
        const id = card.dataset.apunteId;
        const selNivel = card.querySelector('.select-nivel');
        const selMat = card.querySelector('.select-materia');
        const inpSub = card.querySelector('.input-subtema');
        const ind = card.querySelector('.estado-indicador');

        let timeout;
        const guardar = async () => {
            ind.innerHTML = '<span class="text-gray-400 animate-pulse text-[11px]">Guardando…</span>';
            try {
                const fd = new FormData();
                fd.append('apunte_id', id);
                fd.append('materia_slug', selMat.value);
                fd.append('subtema', inpSub.value);
                fd.append('nivel', selNivel.value);
                const r = await fetch('/app/api/asignar_materia_apunte.php', { method: 'POST', body: fd });
                const d = await r.json();
                if (!d.ok) throw new Error(d.error);
                
                if (selMat.value) {
                    ind.innerHTML = '<span class="text-emerald-500"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg></span>';
                } else {
                    ind.innerHTML = '';
                }

                const params = new URLSearchParams(window.location.search);
                const filtroActual = params.get('filtro') ?? 'sin_materia';
                if (filtroActual === 'sin_materia' && selMat.value) {
                    setTimeout(() => {
                        card.style.transition = 'all 0.4s ease';
                        card.style.opacity = '0';
                        card.style.transform = 'translateX(20px)';
                        setTimeout(() => card.remove(), 400);
                    }, 600);
                }
            } catch (err) {
                ind.innerHTML = '<span class="text-red-500 text-[11px] font-bold">Error</span>';
                setTimeout(() => ind.innerHTML = '', 3000);
            }
        };

        const debounce = () => { clearTimeout(timeout); timeout = setTimeout(guardar, 600); };
        selNivel.addEventListener('change', guardar);
        selMat.addEventListener('change', guardar);
        inpSub.addEventListener('input', debounce);
    });
});
</script>
</body>
</html>