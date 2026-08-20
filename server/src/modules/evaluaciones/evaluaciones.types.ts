// Puerto de app/mis_evaluaciones.php — NO del query "ideal" (OPCION A en el PHP real,
// líneas 59-63: `u.apellidos, u.foto_perfil`), sino del que REALMENTE se ejecuta hoy en
// producción. Verificado con evidencia real (query directa contra la BD local): tanto
// OPCION A como OPCION B fallan con "Unknown column 'u.apellidos'" — `alumnos` no tiene
// columna `apellidos` (confirmado con SHOW COLUMNS). El try/catch en cascada del PHP real
// (mis_evaluaciones.php:79-109) atrapa esos 2 fallos silenciosamente y cae siempre a
// OPCION C ("Modo Supervivencia"): `'' as apellidos, '' as foto_perfil` — literales vacíos,
// no columnas reales. Efecto real, visible hoy en producción: esta página SIEMPRE muestra
// solo el primer nombre (nunca apellido) y SIEMPRE el avatar de iniciales (nunca la foto
// real), a diferencia de perfil.php (getResenasPorRol en tutores.repository.ts), que SÍ
// trae foto_perfil real porque esa consulta nunca referenció la columna inexistente.
// Portar la versión "ideal" acá habría sido una mejora silenciosa no pedida — se porta el
// comportamiento real tal cual es hoy.
export interface EvaluacionRow {
  id: number;
  calificacion: number;
  comentario: string | null;
  fecha: Date;
  nombre: string | null;
  servicio_titulo: string | null;
}

export interface EvaluacionRecibida {
  id: number;
  nombreEvaluador: string;
  calificacion: number;
  comentario: string | null;
  fecha: Date;
  servicioTitulo: string | null;
}

// Mismos nombres de campo que TutorPerfil (tutores.types.ts: resenasComoTutor/
// resenasComoAlumno) — datos parcialmente distintos (ver nota arriba: sin filtro
// calificacion>0, sin LIMIT, a diferencia de esa consulta), pero misma convención de
// nombres para no introducir un tercer vocabulario para "lo mismo".
export interface MisEvaluacionesPublico {
  resenasComoTutor: EvaluacionRecibida[];
  resenasComoAlumno: EvaluacionRecibida[];
}
