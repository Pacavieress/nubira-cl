import type { ServicioPublico } from "../servicios/servicios.types.js";

export interface TutorRow {
  id: number;
  nombre: string | null;
  bio: string | null;
  foto_perfil: string | null;
  institucion_maestra: string | null;
  verificacion_estado: string | null;
  total_votos: number;
  rating_promedio: string | null;
}

export interface TutorPublico {
  id: number;
  nombre: string | null;
  bio: string | null;
  fotoUrl: string;
  institucion: string | null;
  verificado: boolean;
  rating: { promedio: number | null; votos: number };
  servicios: ServicioPublico[];
}
