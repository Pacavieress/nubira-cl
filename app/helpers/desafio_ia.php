<?php
/**
 * NUBIRA — GENERADOR DE EJERCICIOS DEL DESAFÍO CON IA (Lote 3, solo Desafío)
 *
 * nb_desafio_ia_generar($conn, $materia_slug, $dificultad, $n, $opts) pide a la IA (con failover, ver
 * helpers/ia_proveedores.php) un lote de ejercicios, VALIDA cada uno en el servidor, descarta los inválidos y los
 * duplicados, y guarda el resto en desafio_preguntas con origen='ia' y revisado_por_admin=0: NO se sirven a los
 * usuarios hasta que un admin los apruebe en /admin/desafio-ia (cargar_desafio.php exige revisado_por_admin=1).
 *
 * Tipos soportados en este lote: 'alternativas' (4 opciones) y 'vf' (Verdadero/Falso). Los tipos de opinión,
 * 'completar' y 'encuentra_error' se quedan manuales. Sin PAES ni temario (eso es el Lote 7).
 *
 * Decisiones de calidad/seguridad:
 *  - El modelo NO decide el orden de las alternativas: el servidor las baraja y recalcula la letra correcta
 *    (evita sesgo de posición). Por eso se rechazan explicaciones que mencionen "opción B", "alternativa c", etc.
 *  - Se rechazan: contactos/URLs/redes/"Nubira", HTML, referencias a figuras/imágenes que no existen,
 *    "todas/ninguna de las anteriores", opciones repetidas, textos fuera de rango.
 *  - Deduplicación por hash (enunciado + opciones normalizadas, sin orden) contra TODO el banco de la materia,
 *    incluidas las preguntas manuales que aún no tienen hash.
 *  - Los errores de IA nunca devuelven claves ni prompts (ver nb_ia_redactar en ia_proveedores.php).
 */

require_once __DIR__ . '/ia_proveedores.php';

const NB_DESAFIO_IA_TIPOS         = ['alternativas', 'vf'];
const NB_DESAFIO_IA_MAX_POR_LOTE  = 10;

/** Normaliza texto para comparar/deduplicar: minúsculas, sin tildes, espacios colapsados, sin signos de pregunta. */
function nb_desafio_ia_normalizar(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $s = preg_replace('/[¿?¡!;:"“”«»\']+/u', '', $s) ?? $s;       // NO se tocan operadores, paréntesis ni decimales (matemática)
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return rtrim(trim($s), '.,');
}

/** Hash de deduplicación: enunciado + opciones (ordenadas, para que barajar no cambie el hash). */
function nb_desafio_ia_hash(string $enunciado, array $opciones): string {
    $op = array_map('nb_desafio_ia_normalizar', array_values(array_filter($opciones, fn($o) => $o !== null && $o !== '')));
    sort($op, SORT_STRING);
    return md5(nb_desafio_ia_normalizar($enunciado) . '|' . implode('|', $op));
}

/** ¿El texto trae contacto, enlaces, redes, marca o HTML? */
function nb_desafio_ia_texto_prohibido(string $t): ?string {
    if (preg_match('/<[a-z\/!][^>]*>/i', $t)) return 'contiene HTML';
    if (preg_match('~(https?://|www\.|[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}|\b\d{8,}\b)~i', $t)) return 'contiene URL, correo o número largo';
    if (preg_match('/\b(whatsapp|wsp|instagram|facebook|tiktok|telegram|discord|youtube|linkedin|nubira)\b/i', $t)) return 'menciona redes sociales o la plataforma';
    if (preg_match('/\p{Cc}/u', preg_replace('/[\r\n\t]/', '', $t))) return 'contiene caracteres de control';
    return null;
}

/**
 * Valida un ejercicio crudo del modelo.
 * @return array{ok:bool, motivo?:string, item?:array}  item = ejercicio normalizado y listo para insertar (sin barajar).
 */
function nb_desafio_ia_validar_item(array $it, int $dificultad, array $tipos_permitidos = ['alternativas']): array {
    $no = fn(string $m) => ['ok' => false, 'motivo' => $m];

    $tipo = $it['tipo'] ?? null;
    if (!is_string($tipo) || !in_array($tipo, $tipos_permitidos, true) || !in_array($tipo, NB_DESAFIO_IA_TIPOS, true)) {
        return $no('tipo no permitido');
    }

    foreach (['enunciado', 'explicacion', 'eje_tematico'] as $campo) {
        if (!isset($it[$campo]) || !is_string($it[$campo])) return $no("falta o no es texto: $campo");
    }
    $enun = trim($it['enunciado']);
    $expl = trim($it['explicacion']);
    $eje  = trim($it['eje_tematico']);

    $len = mb_strlen($enun);
    if ($len < 15 || $len > 500) return $no('enunciado fuera de rango (15-500 caracteres)');
    $len = mb_strlen($expl);
    if ($len < 20 || $len > 700) return $no('explicación fuera de rango (20-700 caracteres)');
    $len = mb_strlen($eje);
    if ($len < 2 || $len > 120) return $no('eje temático fuera de rango');

    if (preg_match('/(la siguiente (figura|imagen|tabla|gr[aá]fico|diagrama)|(figura|imagen|gr[aá]fico|tabla|diagrama) adjunt[ao]|observa (la|el) (figura|imagen|gr[aá]fico|tabla|diagrama)|seg[uú]n (la|el) (figura|imagen|gr[aá]fico|diagrama))/iu', $enun)) {
        return $no('depende de una figura o imagen que no existe');
    }
    if (preg_match('/\b(opci[oó]n|alternativa|letra|respuesta)\s+\(?[a-d]\)?(?![a-z0-9])/iu', $expl)) {
        return $no('la explicación menciona una letra de alternativa (el orden se baraja en el servidor)');
    }

    if ($tipo === 'vf') {
        $c = strtolower((string)($it['correcta'] ?? ''));
        if (!in_array($c, ['a', 'b'], true)) return $no("vf: 'correcta' debe ser a (Verdadero) o b (Falso)");
        $opciones = ['Verdadero', 'Falso'];
        $idx = $c === 'a' ? 0 : 1;
    } else {
        $op = $it['opciones'] ?? null;
        if (!is_array($op) || count($op) !== 4) return $no('alternativas: se requieren exactamente 4 opciones');
        $opciones = [];
        foreach ($op as $o) {
            if (!is_string($o) && !is_int($o) && !is_float($o)) return $no('opción que no es texto');
            $o = trim((string)$o);
            if ($o === '' || mb_strlen($o) > 300) return $no('opción vacía o demasiado larga');
            $opciones[] = $o;
        }
        $norm = array_map('nb_desafio_ia_normalizar', $opciones);
        if (count(array_unique($norm)) !== 4) return $no('opciones repetidas');
        foreach ($opciones as $o) {
            if (preg_match('/\b(todas|ninguna)\s+(de\s+)?las\s+(anteriores|alternativas|opciones)\b|\b(a|b|c|d)\s+y\s+(a|b|c|d)\b|\bambas\b/iu', $o)) {
                return $no('opción ambigua (todas/ninguna de las anteriores, combinaciones)');
            }
        }
        $c = strtolower((string)($it['correcta'] ?? ''));
        if (!in_array($c, ['a', 'b', 'c', 'd'], true)) return $no("'correcta' debe ser a, b, c o d");
        $idx = ord($c) - ord('a');
    }

    foreach (array_merge([$enun, $expl, $eje], $opciones) as $t) {
        $p = nb_desafio_ia_texto_prohibido($t);
        if ($p !== null) return $no($p);
    }

    return ['ok' => true, 'item' => [
        'tipo' => $tipo, 'dificultad' => $dificultad, 'enunciado' => $enun, 'opciones' => $opciones,
        'idx_correcta' => $idx, 'explicacion' => $expl, 'eje_tematico' => $eje,
    ]];
}

/** Baraja las alternativas en el servidor y devuelve la letra correcta resultante. V/F no se baraja. */
function nb_desafio_ia_barajar(array $item, ?callable $permutar = null): array {
    if ($item['tipo'] === 'vf') {
        $item['letra_correcta'] = $item['idx_correcta'] === 0 ? 'a' : 'b';
        return $item;
    }
    $orden = [0, 1, 2, 3];
    if ($permutar !== null) {
        $orden = $permutar($orden);
    } else {
        for ($i = 3; $i > 0; $i--) {          // Fisher-Yates con random_int
            $j = random_int(0, $i);
            [$orden[$i], $orden[$j]] = [$orden[$j], $orden[$i]];
        }
    }
    $nuevas = [];
    $nueva_idx = 0;
    foreach ($orden as $pos => $orig) {
        $nuevas[] = $item['opciones'][$orig];
        if ($orig === $item['idx_correcta']) $nueva_idx = $pos;
    }
    $item['opciones'] = $nuevas;
    $item['letra_correcta'] = chr(ord('a') + $nueva_idx);
    return $item;
}

/** Construye el prompt del lote. */
function nb_desafio_ia_prompt(string $materia_nombre, int $dificultad, int $n, array $tipos, array $evitar = []): string {
    $nivel = [
        1 => 'FÁCIL: definiciones y conceptos básicos, se resuelve en un solo paso.',
        2 => 'MEDIO: aplicación directa de un concepto, de 1 a 2 pasos.',
        3 => 'DIFÍCIL: razonamiento de varios pasos o un caso con una trampa conceptual frecuente.',
    ][$dificultad] ?? '';
    $con_vf = in_array('vf', $tipos, true);
    $tipos_txt = $con_vf
        ? "Mezcla los tipos: la mayoría \"alternativas\" y algunos \"vf\" (afirmación Verdadera o Falsa)."
        : "Todos los ejercicios son del tipo \"alternativas\".";
    $evitar_txt = '';
    if ($evitar) {
        $evitar_txt = "\nYA EXISTEN estas preguntas (NO repitas ni parafrasees ninguna):\n- " . implode("\n- ", array_map(fn($e) => mb_substr($e, 0, 110), $evitar)) . "\n";
    }

    return <<<PROMPT
Eres un profesor universitario chileno que redacta ejercicios de práctica de opción múltiple.
Escribe en español neutro de Chile (tuteo: "tú", no "vos").

MATERIA: {$materia_nombre}
DIFICULTAD: {$nivel}
CANTIDAD: exactamente {$n} ejercicios.
{$tipos_txt}

REGLAS:
- Cada ejercicio debe poder resolverse SOLO con el texto: prohibido referirse a figuras, imágenes, gráficos o tablas.
- Matemática y fórmulas en texto plano con símbolos Unicode (x², √, π, ∫, ≤). No uses LaTeX.
- Una única alternativa correcta, sin ambigüedad. Las otras 3 deben ser plausibles (errores típicos de estudiantes).
- Prohibido "todas las anteriores", "ninguna de las anteriores" o combinaciones tipo "A y B".
- La explicación (2 a 4 frases) justifica la respuesta correcta y NO menciona letras de alternativas.
- Prohibido incluir enlaces, correos, teléfonos, nombres de redes sociales o de plataformas.
- Cada ejercicio es independiente y puede resolverse en menos de 90 segundos.
{$evitar_txt}
FORMATO DE SALIDA: responde SOLO con un objeto JSON válido (sin texto adicional ni markdown) con esta forma exacta:
{"ejercicios":[
  {"tipo":"alternativas","enunciado":"...","opciones":["...","...","...","..."],"correcta":"b","explicacion":"...","eje_tematico":"..."},
  {"tipo":"vf","enunciado":"Afirmación ...","correcta":"a","explicacion":"...","eje_tematico":"..."}
]}
Para "vf" no incluyas "opciones"; "correcta" es "a" si la afirmación es Verdadera y "b" si es Falsa.
"correcta" para "alternativas" es la letra (a, b, c o d) de la opción correcta tal como la escribes en "opciones".
"eje_tematico" es el subtema general del ejercicio (por ejemplo "Derivadas", "Equilibrio químico").
PROMPT;
}

/**
 * Genera, valida, deduplica y guarda un lote de ejercicios (todos quedan sin aprobar).
 *
 * @param array $opts tipos (['alternativas']), usuario_id, permutar (callable para pruebas), debug_prompt (bool)
 * @return array{ok:bool, error?:string, proveedor?:string, modelo?:string, pedidos:int, recibidos:int, validos:int,
 *               insertados:int, duplicados:int, descartados:array, ids:array, intentos?:array}
 */
function nb_desafio_ia_generar(mysqli $conn, string $materia_slug, int $dificultad, int $n = 5, array $opts = []): array {
    $base = ['ok' => false, 'pedidos' => 0, 'recibidos' => 0, 'validos' => 0, 'insertados' => 0, 'duplicados' => 0, 'descartados' => [], 'ids' => []];

    if ($dificultad < 1 || $dificultad > 3) return ['error' => 'dificultad inválida (1 a 3)'] + $base;
    $n = max(1, min(NB_DESAFIO_IA_MAX_POR_LOTE, $n));
    $base['pedidos'] = $n;
    $tipos = array_values(array_intersect($opts['tipos'] ?? ['alternativas'], NB_DESAFIO_IA_TIPOS));
    if (!$tipos) return ['error' => 'tipos inválidos'] + $base;

    $st = $conn->prepare("SELECT nombre FROM materias WHERE slug = ? AND activa = 1 LIMIT 1");
    $st->bind_param('s', $materia_slug);
    $st->execute();
    $mat = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$mat) return ['error' => 'materia inexistente o inactiva'] + $base;

    // Banco existente de la materia: hashes (de las filas con hash) y hashes calculados al vuelo (filas manuales).
    $hashes = [];
    $evitar = [];
    $st = $conn->prepare("SELECT enunciado, opcion_a, opcion_b, opcion_c, opcion_d, hash_enunciado FROM desafio_preguntas WHERE materia_slug = ?");
    $st->bind_param('s', $materia_slug);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        if (!empty($r['hash_enunciado'])) $hashes[$r['hash_enunciado']] = true;
        $hashes[nb_desafio_ia_hash((string)$r['enunciado'], [$r['opcion_a'], $r['opcion_b'], $r['opcion_c'], $r['opcion_d']])] = true;
        $evitar[] = (string)$r['enunciado'];
    }
    $st->close();
    shuffle($evitar);
    $evitar = array_slice($evitar, 0, 12);

    $prompt = nb_desafio_ia_prompt($mat['nombre'], $dificultad, $n, $tipos, $evitar);

    $validador = function (array $j) use ($dificultad, $tipos) {
        $lista = $j['ejercicios'] ?? null;
        if (!is_array($lista) || $lista === [] || array_keys($lista) !== range(0, count($lista) - 1)) return 'falta la lista "ejercicios"';
        $buenos = 0;
        $motivos = [];
        foreach ($lista as $it) {
            if (!is_array($it)) { $motivos[] = 'elemento que no es objeto'; continue; }
            $v = nb_desafio_ia_validar_item($it, $dificultad, $tipos);
            if ($v['ok']) $buenos++; else $motivos[] = $v['motivo'];
        }
        return $buenos > 0 ? true : 'ningún ejercicio válido (' . implode('; ', array_slice(array_unique($motivos), 0, 3)) . ')';
    };

    $res = nb_ia_generar($prompt, [
        'uso'           => 'desafio',
        'funcion'       => 'desafio_generar',
        'usuario_id'    => $opts['usuario_id'] ?? null,
        'conn'          => $conn,
        'temperature'   => 0.8,
        'response_json' => true,
        'validador'     => $validador,
    ]);
    $base['intentos'] = $res['intentos'] ?? [];
    if (!$res['ok']) return ['error' => 'La IA no entregó ejercicios utilizables: ' . ($res['error'] ?? 'sin detalle')] + $base;

    $base['proveedor'] = $res['proveedor'];
    $base['modelo']    = $res['modelo'];
    $lista = $res['json']['ejercicios'];
    $base['recibidos'] = count($lista);

    $ins = $conn->prepare(
        "INSERT INTO desafio_preguntas
            (materia_slug, tipo, dificultad, enunciado, opcion_a, opcion_b, opcion_c, opcion_d, respuesta_correcta,
             explicacion, eje_tematico, proveedor_ia, modelo_ia, hash_enunciado,
             ambito, origen, activa, revisado_por_admin, nivel_paes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'desafio', 'ia', 1, 0, 0)"
    );

    foreach ($lista as $it) {
        if ($base['insertados'] >= $n) break;
        if (!is_array($it)) { $base['descartados'][] = 'elemento que no es objeto'; continue; }
        $v = nb_desafio_ia_validar_item($it, $dificultad, $tipos);
        if (!$v['ok']) { $base['descartados'][] = $v['motivo']; continue; }
        $base['validos']++;

        $item = nb_desafio_ia_barajar($v['item'], $opts['permutar'] ?? null);
        $hash = nb_desafio_ia_hash($item['enunciado'], $item['opciones']);
        if (isset($hashes[$hash])) { $base['duplicados']++; continue; }

        $o = $item['opciones'];
        $a = $o[0]; $b = $o[1]; $c = $o[2] ?? null; $d = $o[3] ?? null;
        $tipo = $item['tipo']; $corr = $item['letra_correcta'];
        $ins->bind_param('ssisssssssssss', $materia_slug, $tipo, $dificultad, $item['enunciado'], $a, $b, $c, $d, $corr,
            $item['explicacion'], $item['eje_tematico'], $res['proveedor'], $res['modelo'], $hash);
        try {
            $ins->execute();
        } catch (\mysqli_sql_exception $e) {
            if ((int)$e->getCode() === 1062) { $base['duplicados']++; continue; }
            throw $e;
        }
        $hashes[$hash] = true;
        $base['insertados']++;
        $base['ids'][] = (int)$conn->insert_id;
    }
    $ins->close();

    $base['ok'] = true;
    return $base;
}
