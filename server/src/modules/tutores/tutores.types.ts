import type { ApuntePublico } from "../apuntes/apuntes.types.js";
import type { ServicioPublico, TiempoRespuesta, ValoracionPublica } from "../servicios/servicios.types.js";

export interface TutorRow {
  id: number;
  nombre: string | null;
  bio: string | null;
  foto_perfil: string | null;
  institucion_maestra: string | null;
  verificacion_estado: string | null;
  total_votos: number;
  rating_promedio: string | null;
  // Puerto de perfil.php:184-200 (subtítulo bajo el nombre) y :590-598 (stats académicas).
  tipo: string | null;
  universidad: string | null;
  anio_egreso: number | null;
  anios_experiencia: number | null;
}

export interface EstadisticasAcademicas {
  universidad: string | null;
  anioEgreso: number | null;
  aniosExperiencia: number | null;
}

export interface TutorPublico {
  id: number;
  nombre: string | null;
  bio: string | null;
  fotoUrl: string;
  institucion: string | null;
  verificado: boolean;
  subtitulo: string;
  statsAcademicas: EstadisticasAcademicas;
  // null = perfil.php lo OCULTA (tiene reseñas como tutor pero sin métrica de tiempo aún)
  // — no es lo mismo que "Tutor nuevo". Ver mapTiempoRespuestaPerfil() en tutores.mapper.ts.
  tiempoRespuesta: TiempoRespuesta | null;
  rating: { promedio: number | null; votos: number };
  resenasComoTutor: ValoracionPublica[];
  resenasComoAlumno: ValoracionPublica[];
  servicios: ServicioPublico[];
  apuntes: ApuntePublico[];
}
