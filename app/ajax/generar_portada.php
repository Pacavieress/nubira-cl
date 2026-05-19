<?php
/**
 * AJAX: GENERADOR DE PORTADAS IA (V6 - OBJETOS FÍSICOS)
 * UBICACIÓN: app/ajax/generar_portada.php
 */
require_once __DIR__ . '/../conexion.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']); exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$tituloRaw = trim($data['titulo'] ?? '');

if (strlen($tituloRaw) < 3) {
    echo json_encode(['status' => 'error', 'message' => 'Título muy corto']); exit;
}

// Directorios
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$tempDirRelative = '/upload/temp/';
$tempDirPath = $docRoot . $tempDirRelative;
if (!file_exists($tempDirPath)) { @mkdir($tempDirPath, 0755, true); }

// --- 1. NORMALIZACIÓN TOTAL (A PRUEBA DE TODO) ---
function limpiarTexto($str) {
    $str = mb_strtolower($str, 'UTF-8');
    // Reemplazo manual seguro para servidores sin locales configurados
    $str = str_replace(
        ['á','é','í','ó','ú','ñ','ü','Á','É','Í','Ó','Ú','Ñ','Ü'],
        ['a','e','i','o','u','n','u','a','e','i','o','u','n','u'],
        $str
    );
    return $str; // Mantenemos espacios para buscar frases
}

$tituloClean = limpiarTexto($tituloRaw);

// --- 2. DICCIONARIO DE OBJETOS FÍSICOS ---
// La clave es la palabra en español (sin tilde).
// El valor es un OBJETO TANGIBLE en inglés.

$dictionary = [
    // MÚSICA (Instrumentos específicos, no conceptos)
    'guitarra' => ['prompt' => 'acoustic guitar leaning against a wall, studio lighting', 'score' => 100],
    'piano'    => ['prompt' => 'hands playing a grand piano keyboard close up', 'score' => 100],
    'violin'   => ['prompt' => 'close up of a violin lying on sheet music', 'score' => 100],
    'bateria'  => ['prompt' => 'drum kit set up on a stage with lights', 'score' => 100],
    'canto'    => ['prompt' => 'vintage microphone in a recording studio', 'score' => 100],
    'musica'   => ['prompt' => 'acoustic guitar and piano keys close up, music room', 'score' => 90], // Prioridad alta

    // DEPORTE (Acciones concretas)
    'futbol'   => ['prompt' => 'soccer ball on green grass field close up', 'score' => 100],
    'tennis'   => ['prompt' => 'tennis racket and yellow ball on clay court', 'score' => 100],
    'yoga'     => ['prompt' => 'woman doing yoga pose on a mat in nature, sunrise', 'score' => 100],
    'entrena'  => ['prompt' => 'dumbbells and water bottle on a gym floor', 'score' => 80],
    'deporte'  => ['prompt' => 'running shoes and fitness gear on a track', 'score' => 80],

    // ACADÉMICO (Objetos de estudio)
    'matematica' => ['prompt' => 'green chalkboard full of math equations, chalk', 'score' => 90],
    'calculo'    => ['prompt' => 'whiteboard with complex calculus formulas', 'score' => 90],
    'quimica'    => ['prompt' => 'chemistry glass beakers with colorful liquids', 'score' => 90],
    'fisica'     => ['prompt' => 'physics pendulum experiment on a desk', 'score' => 90],
    'ingles'     => ['prompt' => 'english dictionary and a cup of coffee on a table', 'score' => 80],
    'idioma'     => ['prompt' => 'stack of language books and a world globe', 'score' => 80],
    'historia'   => ['prompt' => 'old antique books stacked on a wooden desk', 'score' => 80],
    'derecho'    => ['prompt' => 'wooden judge gavel and law books on a desk', 'score' => 90],
    'legal'      => ['prompt' => 'scales of justice on a lawyer desk', 'score' => 90],
    'tesis'      => ['prompt' => 'open laptop and stack of books on a study desk', 'score' => 80],

    // TÉCNICO (Herramientas)
    'pc'           => ['prompt' => 'modern gaming computer setup with rgb lights', 'score' => 90],
    'computa'      => ['prompt' => 'technician hands repairing a laptop motherboard', 'score' => 90],
    'programacion' => ['prompt' => 'computer monitor screen displaying colorful code', 'score' => 90],
    'web'          => ['prompt' => 'computer screen showing a website design layout', 'score' => 80],
    'flete'        => ['prompt' => 'cardboard boxes stacked in a room ready to move', 'score' => 90],
    'mudanza'      => ['prompt' => 'moving truck parked outside a house', 'score' => 90],
    'jardin'       => ['prompt' => 'gardening tools, shovel and plants on grass', 'score' => 90],

    // MASCOTAS (Animales reales)
    'perro'    => ['prompt' => 'happy golden retriever dog portrait in a park', 'score' => 100],
    'gato'     => ['prompt' => 'cute fluffy cat sitting by a window', 'score' => 100],
    'mascota'  => ['prompt' => 'cute dog and cat sitting together on grass', 'score' => 95],
    'paseo'    => ['prompt' => 'person holding a dog leash walking in a park', 'score' => 85], // Menos score que 'perro'

    // PALABRAS "PELIGROSAS" (Score MUY BAJO para que no ganen nunca)
    'clase'    => ['prompt' => 'modern empty university classroom', 'score' => 1],
    'curso'    => ['prompt' => 'notebook and pen on a desk', 'score' => 1],
    'tutor'    => ['prompt' => 'two people studying at a library table', 'score' => 1],
    'asesor'   => ['prompt' => 'professional handshake in an office', 'score' => 1],
    'servicio' => ['prompt' => 'professional workspace desk', 'score' => 1]
];

// --- 3. SELECCIÓN DEL GANADOR ---
$bestPrompt = "modern minimalist workspace desk with laptop, bright natural light"; // Fallback seguro
$maxScore = 0;
$winnerWord = "Ninguna";

foreach ($dictionary as $word => $data) {
    // Buscamos la palabra dentro del título limpio
    if (strpos($tituloClean, $word) !== false) {
        if ($data['score'] > $maxScore) {
            $maxScore = $data['score'];
            $bestPrompt = $data['prompt'];
            $winnerWord = $word;
        }
    }
}

// --- 4. GENERACIÓN DE IMAGEN ---
// Prompt Literal: "Una fotografía realista de [OBJETO]"
$finalPrompt = "photorealistic photograph of " . $bestPrompt . ", 4k, highly detailed, professional photography, soft lighting";
$encodedPrompt = urlencode($finalPrompt);
$seed = rand(1000, 999999);

// Usamos el modelo 'turbo' (Más literal, menos alucinaciones que Flux)
$url = "https://image.pollinations.ai/prompt/{$encodedPrompt}?width=800&height=600&seed={$seed}&nologo=true&model=turbo";

// Descarga
$context = stream_context_create(["http" => ["timeout" => 15]]);
$imageData = @file_get_contents($url, false, $context);

// Si falla, reintento con stock (Picsum)
if ($imageData === false || empty($imageData)) {
    $urlBackup = "https://picsum.photos/seed/{$seed}/800/600";
    $imageData = @file_get_contents($urlBackup);
    $winnerWord .= " (Fallback Stock)";
}

if ($imageData === false) {
    echo json_encode(['status' => 'error', 'message' => 'Error de red total']); exit;
}

// Guardar
$tempFilename = 'ia_' . uniqid() . '.jpg';
if (@file_put_contents($tempDirPath . $tempFilename, $imageData) === false) {
    echo json_encode(['status' => 'error', 'message' => 'Error escritura']); exit;
}

// Retorno con Debug para que sepas qué pasó
echo json_encode([
    'status' => 'success',
    'filename' => $tempFilename,
    'filepath' => $tempDirRelative . $tempFilename,
    'debug_detected' => $winnerWord
]);