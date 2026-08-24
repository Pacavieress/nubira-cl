// Puerto de admin_avisos.php — SOLO lectura (métricas globales, historial de campañas,
// detalle de lectores). Deliberadamente SIN crear/enviar campaña (INSERT masivo de 1 aviso
// por destinatario a potencialmente TODOS los usuarios activos del sitio,
// admin_enviar_aviso_masivo.php:146-229 — efecto real amplio, no una mutación acotada como
// el toggle-visibilidad de otros paneles), SIN eliminar campaña (hard DELETE en cascada de
// avisos_admin/avisos_imagenes + archivos en disco, admin_eliminar_campana.php), SIN
// duplicar/subir imagen/buscar usuario (los tres atados al flujo de creación ya excluido) —
// mismo criterio que el resto de esta ronda de paneles admin: acciones con efecto externo
// real o amplio quedan fuera, documentadas, no un olvido.
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
