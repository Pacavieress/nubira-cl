// Puerto de admin_avisos.php. Historial de campañas + detalle de lectores (lectura), y
// crear+enviar campaña (autorizado explícitamente por el usuario — puerto de
// admin_enviar_aviso_masivo.php, INSERT masivo de 1 aviso por destinatario). Buscar
// usuario también se porta (necesario para el segmento "usuario específico"). Sigue SIN
// eliminar campaña (hard DELETE en cascada de avisos_admin/avisos_imagenes + archivos en
// disco, admin_eliminar_campana.php), SIN duplicar campaña, y SIN subir/adjuntar imágenes
// (las 3 imágenes opcionales del PHP real) — mismo criterio del resto de esta migración:
// acciones de gestión de contenido ya creado, o que requieren almacenamiento de archivos
// nuevo, quedan fuera de esta pieza, documentadas, no un olvido.
export type TipoAviso = "info" | "novedad" | "importante";
export type SegmentoAviso = "todos" | "tutores" | "no_tutores" | "usuario";

export const TIPOS_AVISO: readonly TipoAviso[] = ["info", "novedad", "importante"];
export const SEGMENTOS_AVISO: readonly SegmentoAviso[] = ["todos", "tutores", "no_tutores", "usuario"];

export interface NuevaCampanaInput {
  titulo: string;
  mensaje: string;
  tipo: TipoAviso;
  segmento: SegmentoAviso;
  usuarioId: number | null;
}

export interface NuevaCampanaResultado {
  campanaId: number;
  enviados: number;
}

export interface UsuarioBusqueda {
  id: number;
  nombre: string;
  correo: string;
  institucion: string;
}
export interface AvisoImagen {
  archivo: string;
  url: string;
}

export interface AvisoCampana {
  id: number;
  titulo: string;
  mensaje: string;
  tipo: string;
  segmento: string;
  totalDestinatarios: number;
  leidos: number;
  fechaCreacion: string;
  imagenes: AvisoImagen[];
}

export interface AvisoLector {
  nombre: string;
  institucion: string | null;
  fechaLeido: string;
}

export interface AvisosResumen {
  totalCampanas: number;
  totalDestinatarios: number;
  campanas: AvisoCampana[];
}
