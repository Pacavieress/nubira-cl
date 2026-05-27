<?php
/**
 * NUBIRA 2.0 - IA MOTOR (V5: TONOS + MATERIA + NIVEL + ANTI-CONTACTO + MICROHINTS)
 * Modelo: Gemini 2.0 Flash (Visión + Texto)
 *
 * V5 cambios:
 * - Reglas de seguridad anti-contacto inyectadas en TODOS los prompts
 * - Sanitización defensiva de la respuesta (regex post-IA)
 * - Microhints por materia (vocabulario, dolores, ejemplos específicos)
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); 
    echo json_encode(['error' => 'Acceso denegado']); 
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$filename  = $input['filename'] ?? 'Documento_Nubira';
$text_data = $input['text'] ?? '';
$image_b64 = $input['image'] ?? null;
$tono      = $input['tono'] ?? 'default'; // default | academico | vendedor

require_once __DIR__ . '/../config.php';
$API_KEY = GEMINI_API_KEY;

// =========================================================================
// CATÁLOGO DE MATERIAS (sincronizado con tabla `materias`)
// =========================================================================
$materias_validas = ['calculo','fisica','algebra','programacion','quimica','biologia','contabilidad','economia','derecho','psicologia','idiomas','redaccion'];
$niveles_validos  = ['universitario','paes','escolar'];

// =========================================================================
// REGLAS DE SEGURIDAD ANTI-CONTACTO (INYECTADAS EN TODOS LOS PROMPTS)
// =========================================================================
$reglas_seguridad = "
REGLAS DE SEGURIDAD ABSOLUTAS (PRIORIDAD MÁXIMA):
- PROHIBIDO mencionar URLs, dominios, sitios web (.com, .cl, .net, etc.).
- PROHIBIDO mencionar 'Nubira', 'nubira.cl', 'la plataforma', 'esta plataforma', 'nuestro sitio'.
- PROHIBIDO mencionar redes sociales: Instagram, Facebook, TikTok, Twitter, X, YouTube, LinkedIn.
- PROHIBIDO mencionar apps de mensajería: WhatsApp, Telegram, Signal, Discord, WSP, WA, DM.
- PROHIBIDO incluir números de teléfono (8 dígitos o más).
- PROHIBIDO incluir direcciones de correo electrónico (cualquier formato con @).
- PROHIBIDO invitar al usuario a contactar al autor por canales externos.
- PROHIBIDO frases tipo 'escríbeme', 'contáctame', 'búscame en', 'mi perfil de'.
- El usuario está en una plataforma educativa: el contenido se vende dentro de esta misma interfaz.
- Si detectas que el documento original contiene contactos, IGNÓRALOS al redactar.
";

// =========================================================================
// MICROHINTS POR MATERIA (vocabulario técnico, dolores típicos, ejemplos)
// =========================================================================
$microhints_materia = [
    'calculo' => "
MATERIA DETECTADA: CÁLCULO / MATEMÁTICAS.
VOCABULARIO TÉCNICO: derivadas, integrales, límites, regla de la cadena, integración por partes, sustitución, teorema fundamental del cálculo, series de Taylor, convergencia, optimización, Lagrange, gradientes, ecuaciones diferenciales.
DOLORES TÍPICOS DEL ALUMNO: confusión con la regla de la cadena, no sabe qué método de integración usar, falla en límites indeterminados (L'Hôpital), errores en derivadas implícitas.
EJEMPLO IDEAL: 'Aborda los métodos de integración por sustitución y partes con ejercicios resueltos. Desarrolla teorema fundamental, series de Taylor y optimización de funciones. La base sólida para certámenes de Cálculo II.'",

    'fisica' => "
MATERIA DETECTADA: FÍSICA.
VOCABULARIO TÉCNICO: cinemática, dinámica, leyes de Newton, energía cinética, energía potencial, conservación, momentum, fuerzas, fricción, MRU, MRUA, electromagnetismo, ondas, termodinámica, leyes de Kirchhoff.
DOLORES TÍPICOS DEL ALUMNO: confunde fuerza neta con peso, no identifica el sistema de referencia correcto, problemas con diagramas de cuerpo libre, errores de unidades.
EJEMPLO IDEAL: 'Desglosa cinemática y dinámica con diagramas de cuerpo libre paso a paso. Cubre las tres leyes de Newton, conservación de energía y momentum lineal. La estructura exacta para resolver problemas tipo certamen.'",

    'algebra' => "
MATERIA DETECTADA: ÁLGEBRA.
VOCABULARIO TÉCNICO: ecuaciones lineales, sistemas, matrices, determinantes, productos notables, factorización, polinomios, vectores, espacios vectoriales, transformaciones lineales, valores propios.
DOLORES TÍPICOS DEL ALUMNO: errores de signo, confusión entre productos notables, olvido de la fórmula general, no identifica cuándo factorizar.
EJEMPLO IDEAL: 'Cubre productos notables, factorización y sistemas de ecuaciones con ejercicios resueltos. Aborda matrices, determinantes y método de Cramer. Material directo para asignaturas de Álgebra Lineal.'",

    'programacion' => "
MATERIA DETECTADA: PROGRAMACIÓN.
VOCABULARIO TÉCNICO: variables, ciclos, condicionales, funciones, recursividad, estructuras de datos, listas, arrays, diccionarios, programación orientada a objetos, herencia, polimorfismo, complejidad algorítmica, SQL, JOINs.
DOLORES TÍPICOS DEL ALUMNO: confusión entre paso por valor y referencia, off-by-one en loops, no entiende recursividad, errores de sintaxis básicos.
EJEMPLO IDEAL: 'Aborda estructuras de control, funciones y POO con ejemplos en código. Cubre listas, diccionarios, manejo de excepciones y consultas SQL básicas. Base técnica para Introducción a la Programación.'",

    'quimica' => "
MATERIA DETECTADA: QUÍMICA.
VOCABULARIO TÉCNICO: estequiometría, mol, balanceo, ácidos, bases, pH, equilibrio químico, termoquímica, cinética, reacciones redox, enlaces, geometría molecular, tabla periódica, soluciones.
DOLORES TÍPICOS DEL ALUMNO: confusión entre moles y gramos, balanceo redox, cálculo de pH en soluciones tampón, identificar reactivo limitante.
EJEMPLO IDEAL: 'Desglosa estequiometría, balanceo de reacciones y cálculos de mol con ejercicios paso a paso. Aborda ácidos-bases, pH y equilibrio químico. Material esencial para Química General.'",

    'biologia' => "
MATERIA DETECTADA: BIOLOGÍA / ANATOMÍA / SALUD.
VOCABULARIO TÉCNICO: células, organelos, mitosis, meiosis, ADN, ARN, transcripción, traducción, genética, sistemas (nervioso, circulatorio, respiratorio), homeostasis, anatomía topográfica, fisiología.
DOLORES TÍPICOS DEL ALUMNO: confunde mitosis con meiosis, dificultad memorizando nombres anatómicos, no relaciona estructura con función.
EJEMPLO IDEAL: 'Cubre estructura celular, mitosis, meiosis y replicación del ADN con esquemas claros. Desarrolla los principales sistemas anatómicos y su fisiología. Material clave para Biología Celular y Anatomía.'",

    'contabilidad' => "
MATERIA DETECTADA: CONTABILIDAD / FINANZAS.
VOCABULARIO TÉCNICO: activo, pasivo, patrimonio, balance, estado de resultados, flujo de caja, partida doble, IFRS, depreciación, valor presente, VAN, TIR, costo de capital, ratios financieros.
DOLORES TÍPICOS DEL ALUMNO: confunde activo con ingreso, errores en partida doble, no interpreta ratios, dificultad con ajustes contables.
EJEMPLO IDEAL: 'Aborda partida doble, balance general y estado de resultados con casos prácticos. Desglosa flujo de caja, VAN y TIR para evaluación de proyectos. Material directo para Contabilidad I y Finanzas.'",

    'economia' => "
MATERIA DETECTADA: ECONOMÍA.
VOCABULARIO TÉCNICO: oferta, demanda, equilibrio de mercado, elasticidad, costos, ingresos, competencia perfecta, monopolio, PIB, inflación, política monetaria, política fiscal, externalidades.
DOLORES TÍPICOS DEL ALUMNO: confunde elasticidad ingreso con elasticidad precio, no aplica costo de oportunidad, errores en gráficos de equilibrio.
EJEMPLO IDEAL: 'Desglosa oferta, demanda y equilibrio de mercado con gráficos resueltos. Cubre elasticidades, estructuras de mercado y costos de producción. Estructura sólida para Microeconomía y Macroeconomía.'",

    'derecho' => "
MATERIA DETECTADA: DERECHO.
VOCABULARIO TÉCNICO: norma jurídica, fuentes del derecho, jurisprudencia, contratos, obligaciones, persona natural, persona jurídica, delito, dolo, culpa, debido proceso, principios constitucionales.
DOLORES TÍPICOS DEL ALUMNO: confunde dolo con culpa, no distingue tipos de obligaciones, dificultad con causales de nulidad.
EJEMPLO IDEAL: 'Cubre fuentes del derecho, jerarquía normativa y principios constitucionales. Aborda obligaciones civiles, contratos y responsabilidad extracontractual con casos prácticos. Material clave para Derecho Civil I.'",

    'psicologia' => "
MATERIA DETECTADA: PSICOLOGÍA / ESTADÍSTICA SOCIAL.
VOCABULARIO TÉCNICO: conductismo, psicoanálisis, cognitivismo, desarrollo, Piaget, Vygotsky, neurociencia, prueba t, ANOVA, correlación, regresión, validez, confiabilidad, muestreo.
DOLORES TÍPICOS DEL ALUMNO: confunde escuelas teóricas, no aplica el test estadístico correcto, errores interpretando p-valor.
EJEMPLO IDEAL: 'Aborda las principales escuelas psicológicas y los modelos del desarrollo cognitivo. Desglosa pruebas estadísticas comunes (t, ANOVA, correlación) con su interpretación. Material útil para Psicología General y Estadística Aplicada.'",

    'idiomas' => "
MATERIA DETECTADA: IDIOMAS.
VOCABULARIO TÉCNICO: tiempos verbales, present perfect, past simple, conditionals, reported speech, modal verbs, phrasal verbs, vocabulario académico, comprensión lectora, listening, writing.
DOLORES TÍPICOS DEL ALUMNO: confunde present perfect con past simple, errores con preposiciones, dificultad con phrasal verbs.
EJEMPLO IDEAL: 'Cubre tiempos verbales en inglés con ejemplos contextualizados: present perfect, past simple, conditionals. Aborda phrasal verbs y vocabulario académico. Recurso directo para certámenes de inglés universitario.'",

    'redaccion' => "
MATERIA DETECTADA: LENGUAJE / REDACCIÓN / TESIS.
VOCABULARIO TÉCNICO: tesis, hipótesis, marco teórico, metodología, citas APA, paráfrasis, coherencia, cohesión, conectores, tipos de texto, comprensión lectora, inferencia, progresión temática.
DOLORES TÍPICOS DEL ALUMNO: dificultad citando en formato APA, no diferencia paráfrasis de plagio, problemas con conectores lógicos.
EJEMPLO IDEAL: 'Aborda estructura de tesis, marco teórico y metodología de investigación. Cubre normas APA, citación, paráfrasis y conectores discursivos. Material esencial para Tesis y Redacción Académica.'",

    'general' => ""
];

// =========================================================================
// PROMPTS POR TONO (con seguridad inyectada)
// =========================================================================
$instrucciones_tono = match($tono) {
    'academico' => "
TONO REQUERIDO: ACADÉMICO FORMAL
- Lenguaje técnico, objetivo, despersonalizado.
- Estructura: 3 oraciones máximo 50 palabras.
- Oración 1: Tema central del documento en términos técnicos.
- Oración 2: Conceptos específicos abordados (cita 3 reales del documento o del vocabulario sugerido).
- Oración 3: Aplicación o utilidad académica.
- PROHIBIDO: emojis, signos de exclamación, lenguaje coloquial, FOMO.
",
    'vendedor' => "
TONO REQUERIDO: VENDEDOR / FOMO
- Lenguaje persuasivo, marketing directo, sentido de urgencia.
- Estructura: 3 oraciones máximo 50 palabras.
- Oración 1: Hook fuerte (un dolor real del alumno en esta materia).
- Oración 2: Solución concreta con 3 conceptos del documento.
- Oración 3: Llamado a la acción o beneficio inmediato (sin sacar de la plataforma).
- PERMITIDO: 1 emoji al inicio (🔥/💡/⚡), palabras como 'salva', 'domina', 'asegura'.
- PROHIBIDO: signos de exclamación múltiples (!!!), promesas de éxito mágico.
",
    default => "
TONO REQUERIDO: PREMIUM EQUILIBRADO (estilo Airbnb)
- Directo, premium, táctico. Sin autoayuda ni publicidad barata.
- Estructura: 3 oraciones máximo 45 palabras.
- Oración 1: Afirmación directa sobre la dificultad técnica.
- Oración 2: Desglose con 3 conceptos técnicos del documento o del vocabulario sugerido.
- Oración 3: Resultado estratégico de usar el material.
- PROHIBIDO: signos de interrogación/exclamación, palabras motivacionales.
"
};

// =========================================================================
// PROMPT PRINCIPAL (combina seguridad + tono + microhints disponibles)
// =========================================================================
$prompt = "
Actúa como Copywriter Académico de élite para una plataforma educativa.

$reglas_seguridad

$instrucciones_tono

ADEMÁS de la descripción, debes inferir 3 campos críticos del documento:

1. **MATERIA**: Elige UNA del catálogo. Slug exacto:
   - calculo → Cálculo, Matemáticas, Probabilidad, Ecuaciones Diferenciales, Geometría
   - fisica → Física, Mecánica, Termodinámica
   - algebra → Álgebra, Productos Notables
   - programacion → Programación, Código, Python, Java, SQL, Bases de Datos
   - quimica → Química, Bioquímica, Fisicoquímica
   - biologia → Biología, Anatomía, Kinesiología, Enfermería, Medicina
   - contabilidad → Contabilidad, Auditoría, Finanzas
   - economia → Economía, Econometría, Marketing
   - derecho → Derecho, Legal, Jurídico
   - psicologia → Psicología, Psicopedagogía, Estadística social
   - idiomas → Inglés, Francés, Alemán
   - redaccion → Lenguaje, Literatura, Géneros, Redacción, Tesis
   Si no calza ninguna, usa 'general'.

2. **NIVEL ACADÉMICO**: Detecta menciones explícitas o implícitas:
   - 'paes' si menciona PAES, M1, M2, prueba de admisión, DEMRE
   - 'escolar' si menciona enseñanza media, IV medio, colegio
   - 'universitario' por defecto

3. **SUBTEMA** (opcional): Tema específico dentro de la materia. Máx 60 chars. Si no es obvio, deja vacío.

CONTEXTO TÉCNICO ESPECÍFICO POR MATERIA: Si detectas alguna de las 12 materias del catálogo, usa el vocabulario técnico real de esa disciplina al redactar la descripción. Sé específico, no genérico.

FORMATO DE SALIDA EXACTO (JSON PURO):
{
    \"titulo\": \"Título (Max 60 chars, sin contactos ni dominios)\",
    \"descripcion\": \"Redacción según tono indicado, respetando reglas de seguridad.\",
    \"asignatura\": \"Nombre del ramo (Max 3 palabras)\",
    \"materia\": \"slug del catálogo o 'general'\",
    \"nivel_academico\": \"universitario|paes|escolar\",
    \"subtema\": \"Tema específico o vacío\",
    \"categoria\": \"[Salud, Ingeniería, Derecho, Humanidades, Negocios, PAES, General]\",
    \"keywords\": \"5 etiquetas minúsculas separadas por coma (sin marcas, sin dominios)\"
}
";

// =========================================================================
// PAYLOAD MULTIMODAL
// =========================================================================
$parts = [];
$contexto = "NOMBRE ARCHIVO: $filename\n";

if (!empty($text_data)) {
    $clean_text = mb_convert_encoding(substr($text_data, 0, 15000), 'UTF-8', 'auto');
    $contexto .= "CONTENIDO TEXTUAL:\n$clean_text";
    $parts[] = ["text" => $prompt . "\n---\n" . $contexto];
} elseif (!empty($image_b64)) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mimeType = match($ext) {
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        default => 'image/jpeg'
    };
    $parts[] = ["text" => $prompt . "\n---\n" . $contexto . "\nAnaliza la imagen adjunta."];
    $parts[] = ["inlineData" => ["mimeType" => $mimeType, "data" => $image_b64]];
} else {
    $parts[] = ["text" => $prompt . "\n---\n" . $contexto . "\nDeduce solo por el título."];
}

$payload = [
    "contents" => [["parts" => $parts]],
    "generationConfig" => [
        "temperature" => 0.7,
        "responseMimeType" => "application/json"
    ]
];

$json_payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);

if (!$json_payload) {
    error_log("Nubira IA Error: Fallo al codificar payload");
    emitir_fallback($filename, $tono);
    exit;
}

// =========================================================================
// LLAMADA A GEMINI
// =========================================================================
$ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=$API_KEY");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response   = curl_exec($ch);
$http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

// =========================================================================
// SEGUNDA PASADA: Si la respuesta es genérica, reintentar con microhint
// (Solo si ya conocemos la materia)
// =========================================================================
function llamar_gemini_con_microhint($API_KEY, $prompt_base, $contexto, $microhint, $image_b64 = null, $filename = '') {
    $prompt_potenciado = $prompt_base . "\n\n" . $microhint . "\n---\n" . $contexto;
    
    $parts = [["text" => $prompt_potenciado]];
    if (!empty($image_b64)) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType = match($ext) {
            'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic', default => 'image/jpeg'
        };
        $parts[] = ["inlineData" => ["mimeType" => $mimeType, "data" => $image_b64]];
    }
    
    $payload = [
        "contents" => [["parts" => $parts]],
        "generationConfig" => ["temperature" => 0.7, "responseMimeType" => "application/json"]
    ];
    
    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=$API_KEY");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r;
}

// =========================================================================
// SANITIZACIÓN ANTI-CONTACTO (defensa en profundidad)
// =========================================================================
function sanitizar_anti_contacto($texto) {
    if (empty($texto) || !is_string($texto)) return $texto;
    
    // Remover correos
    $texto = preg_replace('/[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '', $texto);
    
    // Remover URLs explícitas
    $texto = preg_replace('/(https?:\/\/|www\.)[^\s]+/i', '', $texto);
    
    // Remover dominios sueltos (.cl, .com, .net, .org)
    $texto = preg_replace('/\b[a-zA-Z0-9-]+\.(cl|com|net|org|io|app|me|gg)\b/i', '', $texto);
    
    // Remover números largos (8+ dígitos consecutivos = teléfonos)
    $texto = preg_replace('/\b\d{8,}\b/', '', $texto);
    
    // Remover menciones a redes sociales y mensajería
    $patrones_redes = [
        '/\b(instagram|insta|ig)\b/i',
        '/\b(facebook|fb)\b/i',
        '/\b(tiktok|tt)\b/i',
        '/\b(twitter|x\.com)\b/i',
        '/\b(youtube|yt)\b/i',
        '/\b(linkedin)\b/i',
        '/\b(whatsapp|wsp|wa\.me)\b/i',
        '/\b(telegram|tg)\b/i',
        '/\b(discord)\b/i',
        '/\bnubira\b/i',
        '/escr[íi]beme/i',
        '/cont[áa]ctame/i',
        '/b[úu]scame en/i',
    ];
    foreach ($patrones_redes as $p) {
        $texto = preg_replace($p, '', $texto);
    }
    
    // Limpiar espacios múltiples y signos sueltos
    $texto = preg_replace('/\s{2,}/', ' ', $texto);
    $texto = preg_replace('/\s+([.,;:])/', '$1', $texto);
    $texto = trim($texto);
    
    return $texto;
}

// =========================================================================
// PROCESAR Y VALIDAR RESPUESTA
// =========================================================================
if ($http_code === 200 && $response) {
    $decoded = json_decode($response, true);
    
    if (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
        $final_text = $decoded['candidates'][0]['content']['parts'][0]['text'];
        
        if (preg_match('/\{[\s\S]*\}/', $final_text, $m)) {
            $final_text = $m[0];
        }
        
        $obj = json_decode($final_text, true);
        if (is_array($obj)) {
            // Normalizar materia
            $materia_ia = strtolower(trim($obj['materia'] ?? 'general'));
            if (!in_array($materia_ia, $materias_validas, true)) {
                $obj['materia'] = '';
            } else {
                $obj['materia'] = $materia_ia;
            }
            
            // Normalizar nivel
            $nivel_ia = strtolower(trim($obj['nivel_academico'] ?? 'universitario'));
            if (!in_array($nivel_ia, $niveles_validos, true)) {
                $obj['nivel_academico'] = 'universitario';
            } else {
                $obj['nivel_academico'] = $nivel_ia;
            }
            
            // Limpiar subtema
            $obj['subtema'] = mb_substr(trim($obj['subtema'] ?? ''), 0, 60);
            
            // ====================================================
            // SEGUNDA PASADA con microhint si la materia se detectó
            // (Solo si el alumno está en SUBIR; en EDIT el frontend
            //  ya maneja regeneración manual de tonos)
            // ====================================================
            // Comentario: deshabilitada por defecto para no duplicar costo de API.
            // Si quieres activarla, descomenta el bloque siguiente:
            /*
            if (!empty($obj['materia']) && isset($microhints_materia[$obj['materia']]) && !empty($microhints_materia[$obj['materia']])) {
                $microhint = $microhints_materia[$obj['materia']];
                $segunda_resp = llamar_gemini_con_microhint($API_KEY, $prompt, $contexto, $microhint, $image_b64, $filename);
                $segunda_dec = json_decode($segunda_resp, true);
                if (isset($segunda_dec['candidates'][0]['content']['parts'][0]['text'])) {
                    $segunda_text = $segunda_dec['candidates'][0]['content']['parts'][0]['text'];
                    if (preg_match('/\{[\s\S]*\}/', $segunda_text, $m2)) {
                        $segunda_obj = json_decode($m2[0], true);
                        if (is_array($segunda_obj) && !empty($segunda_obj['descripcion'])) {
                            $obj['descripcion'] = $segunda_obj['descripcion'];
                        }
                    }
                }
            }
            */
            
            // ====================================================
            // SANITIZACIÓN DEFENSIVA: limpia título, descripción y keywords
            // ====================================================
            $obj['titulo']      = sanitizar_anti_contacto($obj['titulo'] ?? '');
            $obj['descripcion'] = sanitizar_anti_contacto($obj['descripcion'] ?? '');
            $obj['asignatura']  = sanitizar_anti_contacto($obj['asignatura'] ?? '');
            $obj['keywords']    = sanitizar_anti_contacto($obj['keywords'] ?? '');
            $obj['subtema']     = sanitizar_anti_contacto($obj['subtema'] ?? '');
            
            echo json_encode($obj, JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Si no se pudo parsear como objeto, devolvemos el texto crudo (sanitizado)
        echo sanitizar_anti_contacto($final_text);
        exit;
    } else {
        error_log("Nubira IA Error: Estructura inesperada. Body: " . $response);
    }
} else {
    error_log("Nubira IA HTTP Error ($http_code): " . ($response ?: $curl_error));
}

emitir_fallback($filename, $tono);

function emitir_fallback($filename, $tono = 'default') {
    $nombre_limpio = ucwords(str_replace(['_','-'], ' ', pathinfo($filename, PATHINFO_FILENAME)));
    $nombre_limpio = sanitizar_anti_contacto($nombre_limpio);
    
    $desc_fallback = match($tono) {
        'academico' => "Material académico de apoyo al estudio del ramo. Revisado por la comunidad universitaria.",
        'vendedor'  => "🔥 El apunte que necesitas para tu próxima evaluación. Material verificado por estudiantes.",
        default     => "Material de estudio procesado para este ramo. Revisado por la comunidad."
    };
    
    echo json_encode([
        "titulo" => $nombre_limpio ?: "Apunte Universitario",
        "descripcion" => $desc_fallback,
        "asignatura" => "General",
        "materia" => "",
        "nivel_academico" => "universitario",
        "subtema" => "",
        "categoria" => "General",
        "keywords" => "apuntes, estudio, resumen, universidad, material"
    ], JSON_UNESCAPED_UNICODE);
}