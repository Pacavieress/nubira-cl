<?php
/**
 * NUBIRA DEBUGGER - Caza-errores de API
 * Este archivo muestra la respuesta CRUDA de Google.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain');

$API_KEY = 'AIzaSyDMdKaw-D43BPGOjmH-yqxOHUeon0vGPJY'; // Tu Key
$MODELO = 'gemini-2.0-flash';

echo "=== INICIANDO DIAGNÓSTICO ===\n";
echo "Servidor: " . $_SERVER['SERVER_SOFTWARE'] . "\n";
echo "PHP Version: " . phpversion() . "\n";
echo "cURL instalado: " . (function_exists('curl_init') ? 'SÍ' : 'NO') . "\n";

// 1. PRUEBA DE CONECTIVIDAD BÁSICA (Ping a Google)
echo "\n--- PASO 1: Ping a Google ---\n";
$ch = curl_init("https://www.google.com");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$res = curl_exec($ch);
if($res) echo "✅ Conexión a Internet OK.\n";
else echo "❌ Error conexión: " . curl_error($ch) . "\n";
curl_close($ch);

// 2. PRUEBA DE API GEMINI (Llamada Real)
echo "\n--- PASO 2: Llamada a Gemini ($MODELO) ---\n";
$url = "https://generativelanguage.googleapis.com/v1beta/models/$MODELO:generateContent?key=$API_KEY";

$payload = [
    "contents" => [[ 
        "parts" => [[ "text" => "Responde solo la palabra: FUNCIONA" ]] 
    ]]
    // NOTA: Quitamos 'tools' (Google Search) para aislar si ese es el problema
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Desactiva SSL estricto para probar
curl_setopt($ch, CURLOPT_VERBOSE, true); // Muestra todo

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

echo "Código HTTP: $http_code (200 es Éxito)\n";

if ($http_code === 200) {
    echo "✅ RESPUESTA DE GEMINI:\n";
    echo $response;
} else {
    echo "❌ ERROR DE GEMINI:\n";
    echo "Error cURL: $curl_error\n";
    echo "Respuesta Servidor: $response\n";
}
?>