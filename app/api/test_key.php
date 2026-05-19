<?php
// app/api/test_key.php
header('Content-Type: text/html; charset=utf-8');

// ⚠️ TU API KEY
$api_key = 'AIzaSyCq_cnFeX-wL-HUVn2uX9us6Vfc9V5FJrM'; 

$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $api_key;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); // Usamos tu parche IPv4 que sí funciona
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

echo "<h1>Diagnóstico de API Key</h1>";

if (isset($data['error'])) {
    echo "<h3 style='color:red'>ERROR: " . $data['error']['message'] . "</h3>";
} elseif (isset($data['models'])) {
    echo "<h3>✅ Modelos disponibles para tu cuenta:</h3><ul>";
    foreach ($data['models'] as $model) {
        // Filtramos solo los que sirven para generar texto (generateContent)
        if (in_array("generateContent", $model['supportedGenerationMethods'])) {
            $color = (strpos($model['name'], 'flash') !== false) ? 'green' : 'black';
            echo "<li style='color:$color; font-weight:bold'>" . $model['name'] . "</li>";
        }
    }
    echo "</ul>";
    echo "<p>Copia uno de los nombres verdes (sin 'models/') y ponlo en motor_ia.php</p>";
} else {
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
}
?>