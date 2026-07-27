<?php
// app/helpers/institucion.php
// [NUBIRA] Formato unificado de la institución para DISPLAY.
//   abreviar_institucion()  → abrevia vía diccionario; vacío => '' (comportamiento histórico, apuntes).
//   institucion_tutor()     → igual, pero vacío => 'Particular' (superficies de tutores de servicios).
if (!function_exists('abreviar_institucion')) {
    function abreviar_institucion(string $inst_raw, int $max_len = 22, bool $escapar = true): string {
        if (empty($inst_raw)) return '';
        $inst_clean = $inst_raw;
        $dicc = [
            'Economía y Negocios' => 'FEN U. Chile', 'ECONOMíA Y NEGOCIOS' => 'FEN U. Chile',
            'Servicio Local de Educ' => 'SLEP', 'SERVICIO LOCAL DE EDUC' => 'SLEP',
            'Santísima Concepci' => 'UCSC', 'SANTíSIMA CONCEPCI' => 'UCSC', 'Santisima Concepci' => 'UCSC',
            'Konrad Lorenz' => 'Konrad Lorenz',
            'Universidad Andr' => 'UNAB', 'Universidad Nac' => 'UNAB',
            'Católica de Valpara' => 'PUCV', 'CATóLICA DE VALPARA' => 'PUCV', 'Catolica de Valpara' => 'PUCV',
            'Pontificia Universidad Cat' => 'PUC', 'Universidad de Santiago' => 'USACH',
            'Universidad de Concepci' => 'UdeC', 'Universidad T' => 'USM',
            'Federico Santa Mar' => 'USM', 'Adolfo Ib' => 'UAI', 'Universidad de Chile' => 'U. de Chile',
            'Universidad del B' => 'UBB', 'Bío Bío' => 'UBB', 'Bio Bio' => 'UBB',
            'Instituto Profesional' => 'IP', 'Centro de Formación Técnica' => 'CFT',
            'iacc' => 'IACC'
        ];
        foreach ($dicc as $k => $v) {
            if (stripos($inst_clean, $k) !== false) {
                if (strlen($v) <= 6) $inst_clean = $v; else $inst_clean = str_ireplace($k, $v, $inst_clean);
                break;
            }
        }
        if (stripos($inst_clean, 'universidad ') === 0) {
            $inst_clean = 'U. ' . substr($inst_clean, 12);
        }
        $inst_clean = mb_strimwidth($inst_clean, 0, $max_len, '...');
        return $escapar ? htmlspecialchars($inst_clean) : $inst_clean;
    }
}

if (!function_exists('institucion_tutor')) {
    // $abreviar=true → string ya escapado y abreviado (cards). false → string crudo (el caller debe escapar).
    function institucion_tutor($inst_raw, bool $abreviar = true, int $max_len = 22): string {
        $raw = trim((string)($inst_raw ?? ''));
        if ($raw === '') return 'Particular';
        return $abreviar ? abreviar_institucion($raw, $max_len) : $raw;
    }
}
