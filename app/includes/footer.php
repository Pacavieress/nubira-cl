</main> 

<?php 
// Buscador dinámico para nav_bottom.php (ya que está en /componentes)
$rutas_nav = [
    __DIR__ . '/../componentes/nav_bottom.php',
    __DIR__ . '/componentes/nav_bottom.php',
    $_SERVER['DOCUMENT_ROOT'] . '/app/componentes/nav_bottom.php',
    $_SERVER['DOCUMENT_ROOT'] . '/componentes/nav_bottom.php'
];

foreach ($rutas_nav as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        break;
    }
}
?>
</main> 

<?php 
// Buscador dinámico de la carpeta de componentes oficiales Nubira 2.0
$rutas_comp = [
    __DIR__ . '/../componentes/',
    __DIR__ . '/componentes/',
    $_SERVER['DOCUMENT_ROOT'] . '/app/componentes/',
    $_SERVER['DOCUMENT_ROOT'] . '/componentes/'
];

$dir_comp = '';
foreach ($rutas_comp as $ruta) {
    if (is_dir($ruta)) {
        $dir_comp = $ruta;
        break;
    }
}

// Cargar estrictamente los módulos oficiales (si se encontró la carpeta)
if ($dir_comp !== '') {
    if(file_exists($dir_comp . 'nav_bottom.php')) require_once $dir_comp . 'nav_bottom.php'; 
    if(file_exists($dir_comp . 'modal_publicar.php')) require_once $dir_comp . 'modal_publicar.php'; 
    if(file_exists($dir_comp . 'modal_explora.php')) require_once $dir_comp . 'modal_explora.php'; 
}
?>

<script src="https://cdn.tailwindcss.com"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Buscar la etiqueta maestra en el HTML
    const tracker = document.querySelector('main[data-track-type]');
    
    if (tracker) {
        const tipo = tracker.getAttribute('data-track-type'); // 'servicio' o 'apunte'
        const id = tracker.getAttribute('data-track-id');     // '15'

        console.log("👀 Nubira Sensor: Detectado", tipo, id);

        // 2. Enviar señal al backend
        fetch('/app/api/sensor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                evento: 'view',
                tipo: tipo,
                id: id
            })
        })
        .then(r => r.json())
        .then(data => console.log("📡 Nubira Sensor:", data))
        .catch(err => console.error("❌ Nubira Sensor Error:", err));
    }
});
</script>
</body>
</html>