<?php
/**
 * VISTA: GESTIÓN DEL CENTRO DE TUTORES (CMS)
 * ESTADO: Nubira 2.0 - Admin Panel (Estricto, Dinámico, Flat UI)
 */
session_start();

// 1. SEGURIDAD NIVEL ADMIN (Estándar Nubira)
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] !== 'admin')) {
    header("Location: /dashboard");
    exit;
}

// 2. RESOLUCIÓN DE RUTAS ROBUSTAS
$base_path = $_SERVER['DOCUMENT_ROOT'];
require_once $base_path . '/app/conexion.php';
require_once $base_path . '/app/iconos.php';

$mensaje = "";

// 3. LÓGICA DE PROCESAMIENTO (Sentencias Preparadas Obligatorias)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $titulo = htmlspecialchars($_POST['titulo']);
    $desc = htmlspecialchars($_POST['descripcion']);
    $html = $_POST['contenido_html']; // HTML crudo permitido para el CMS
    $icono = htmlspecialchars($_POST['icono']);
    $c_bg = htmlspecialchars($_POST['color_bg']);
    $c_txt = htmlspecialchars($_POST['color_text']);
    $orden = (int)$_POST['orden'];

    if ($_POST['accion'] === 'crear') {
        $stmt = $conn->prepare("INSERT INTO guia_tutores_contenido (titulo, descripcion, contenido_html, icono, color_bg, color_text, orden) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssi", $titulo, $desc, $html, $icono, $c_bg, $c_txt, $orden);
        if ($stmt->execute()) $mensaje = "Tarjeta creada con éxito.";
        $stmt->close();
    } elseif ($_POST['accion'] === 'editar') {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE guia_tutores_contenido SET titulo=?, descripcion=?, contenido_html=?, icono=?, color_bg=?, color_text=?, orden=? WHERE id=?");
        $stmt->bind_param("ssssssii", $titulo, $desc, $html, $icono, $c_bg, $c_txt, $orden, $id);
        if ($stmt->execute()) $mensaje = "Actualizado correctamente.";
        $stmt->close();
    }
}

// 4. OBTENER DATOS PARA EDICIÓN
$edit_data = null;
if (isset($_GET['edit'])) {
    $id_edit = (int)$_GET['edit'];
    $stmt_e = $conn->prepare("SELECT * FROM guia_tutores_contenido WHERE id = ?");
    $stmt_e->bind_param("i", $id_edit);
    $stmt_e->execute();
    $edit_data = $stmt_e->get_result()->fetch_assoc();
    $stmt_e->close();
}

// 5. LISTADO DE GUÍAS (Consistencia Visual)
$guias = $conn->query("SELECT * FROM guia_tutores_contenido ORDER BY orden ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>CMS Guía Tutores | Nubira Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { nubira: '#54A6D8' } } } }
    </script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f9fafb; }
        .form-input { @apply w-full border border-gray-200 rounded-xl px-4 py-2.5 focus:border-nubira focus:ring-0 transition-all outline-none text-sm bg-white; }
        .hide-scrollbar::-webkit-scrollbar { display: none; }
    </style>
</head>
<body class="text-gray-800 antialiased hide-scrollbar">

<?php 
// Componentes oficiales (Rutas absolutas)
require_once $base_path . '/componentes/header.php'; 
require_once $base_path . '/componentes/sidebar.php'; 
?>

<main class="pt-20 pb-32 md:pb-12 md:ml-64 mx-auto max-w-[1200px] px-4 md:px-8 animate-fade-in-up">
    
    <div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1 text-[10px] font-extrabold text-gray-400 uppercase tracking-widest">
                <i class="fa-solid fa-screwdriver-wrench"></i> Administración de Contenido
            </div>
            <h1 class="text-2xl md:text-3xl font-black tracking-tight text-gray-900 leading-none">Gestionar Guía de Tutores</h1>
        </div>
        <a href="/centro-tutores" target="_blank" class="inline-flex items-center gap-2 text-xs font-bold text-nubira bg-sky-50 px-4 py-2 rounded-xl hover:bg-sky-100 transition-all">
            Ver Centro de Tutores <i class="fa-solid fa-arrow-up-right-from-square"></i>
        </a>
    </div>

    <?php if ($mensaje): ?>
        <div class="bg-emerald-50 text-emerald-600 p-4 rounded-2xl border border-emerald-100 mb-6 text-sm font-bold flex items-center gap-3">
            <i class="fa-solid fa-circle-check"></i> <?= $mensaje ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- COLUMNA FORMULARIO (Editor) -->
        <div class="lg:col-span-5">
            <div class="bg-white border border-gray-100 rounded-3xl p-6 sticky top-24 shadow-sm">
                <h2 class="text-lg font-bold mb-5 flex items-center gap-2">
                    <?= $edit_data ? '<i class="fa-solid fa-pen text-orange-500"></i> Editar Tarjeta' : '<i class="fa-solid fa-plus text-nubira"></i> Nueva Tarjeta' ?>
                </h2>
                <form action="" method="POST" class="space-y-4">
                    <input type="hidden" name="accion" value="<?= $edit_data ? 'editar' : 'crear' ?>">
                    <?php if ($edit_data): ?><input type="hidden" name="id" value="<?= $edit_data['id'] ?>"><?php endif; ?>
                    
                    <div>
                        <label class="text-[10px] font-black text-gray-400 uppercase ml-1 tracking-widest">Título de la Card</label>
                        <input type="text" name="titulo" class="form-input" value="<?= $edit_data['titulo'] ?? '' ?>" required placeholder="Ej: Tu Mini Aula Virtual">
                    </div>

                    <div>
                        <label class="text-[10px] font-black text-gray-400 uppercase ml-1 tracking-widest">Descripción Principal</label>
                        <textarea name="descripcion" class="form-input h-24 resize-none" required placeholder="Texto breve para la vista principal..."><?= $edit_data['descripcion'] ?? '' ?></textarea>
                    </div>

                    <div>
                        <label class="text-[10px] font-black text-gray-400 uppercase ml-1 tracking-widest">HTML Expandible (Acordeón)</label>
                        <textarea name="contenido_html" class="form-input font-mono text-[11px] h-40 bg-gray-50" placeholder="<p>Escribe aquí el contenido detallado...</p>"><?= $edit_data['contenido_html'] ?? '' ?></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] font-black text-gray-400 uppercase ml-1 tracking-widest">Icono (FontAwesome)</label>
                            <input type="text" name="icono" class="form-input" value="<?= $edit_data['icono'] ?? 'fa-solid fa-circle-info' ?>">
                        </div>
                        <div>
                            <label class="text-[10px] font-black text-gray-400 uppercase ml-1 tracking-widest">Orden</label>
                            <input type="number" name="orden" class="form-input" value="<?= $edit_data['orden'] ?? '0' ?>">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] font-black text-gray-400 uppercase ml-1 tracking-widest">Color Fondo (CSS)</label>
                            <input type="text" name="color_bg" class="form-input" value="<?= $edit_data['color_bg'] ?? 'bg-sky-50 border-gray-100' ?>">
                        </div>
                        <div>
                            <label class="text-[10px] font-black text-gray-400 uppercase ml-1 tracking-widest">Color Texto (CSS)</label>
                            <input type="text" name="color_text" class="form-input" value="<?= $edit_data['color_text'] ?? 'text-nubira' ?>">
                        </div>
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="w-full bg-gray-900 text-white font-bold py-3.5 rounded-2xl hover:bg-black transition-all active:scale-[0.98] shadow-lg shadow-gray-200">
                            <?= $edit_data ? 'Guardar Cambios' : 'Publicar en la Guía' ?>
                        </button>
                        <?php if ($edit_data): ?>
                            <a href="/admin/gestionar_guia" class="block text-center text-[11px] text-gray-400 font-bold uppercase tracking-tighter pt-4 hover:text-gray-600 transition-colors">Cancelar edición</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- COLUMNA LISTADO (Vista Previa) -->
        <div class="lg:col-span-7">
            <h2 class="text-sm font-black uppercase tracking-widest text-gray-400 mb-5 ml-2">Estructura actual</h2>
            <div class="grid grid-cols-1 gap-4">
                <?php if($guias && $guias->num_rows > 0): ?>
                    <?php while($g = $guias->fetch_assoc()): ?>
                    <div class="bg-white border border-gray-200 rounded-2xl p-5 flex items-center justify-between group hover:border-nubira transition-all duration-300">
                        <div class="flex items-center gap-4 min-w-0">
                            <div class="w-12 h-12 rounded-xl <?= $g['color_bg'] ?> <?= $g['color_text'] ?> flex items-center justify-center border border-white/50 shrink-0">
                                <i class="<?= $g['icono'] ?> text-lg"></i>
                            </div>
                            <div class="min-w-0">
                                <h3 class="font-bold text-gray-900 leading-none mb-1 truncate"><?= $g['titulo'] ?></h3>
                                <div class="flex items-center gap-2">
                                    <span class="text-[10px] font-bold bg-gray-100 px-2 py-0.5 rounded text-gray-500">Orden: <?= $g['orden'] ?></span>
                                    <span class="text-[10px] font-bold text-gray-400">ID: #<?= $g['id'] ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 ml-4">
                            <a href="?edit=<?= $g['id'] ?>" class="w-9 h-9 rounded-full bg-gray-50 flex items-center justify-center text-gray-400 hover:bg-orange-50 hover:text-orange-500 transition-all border border-transparent hover:border-orange-100">
                                <i class="fa-solid fa-pencil text-xs"></i>
                            </a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="p-12 text-center border-2 border-dashed border-gray-200 rounded-3xl bg-white">
                        <i class="fa-solid fa-box-open text-3xl text-gray-200 mb-3"></i>
                        <p class="text-gray-400 font-bold text-sm">No hay tarjetas creadas todavía.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</main>

<script>
    // Loader nativo Nubira
    window.onload = () => {
        const loader = document.getElementById('loader');
        if(loader) {
            loader.style.opacity = '0';
            setTimeout(() => loader.style.display = 'none', 300);
        }
    };
</script>

</body>
</html>