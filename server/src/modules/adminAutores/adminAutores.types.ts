// Puerto de admin_autores_servicios.php — SOLO la parte de lectura (directorio de
// autores/tutores con servicios publicados, indicadores de completitud de perfil,
// historial de correos administrativos ya enviados). Deliberadamente SIN el modal
// "Escribir correo" del PHP real (envía un correo real vía
// /app/enviar_correo_autor.php) — decisión de alcance, mismo criterio que el resto de
// esta ronda de paneles admin (ver nota en adminContratos.types.ts).
export interface AutorServicio {
  idUsuario: number;
  nombre: string;
  correo: string;
  institucion: string | null;
  fotoPerfil: string | null;
  bio: string | null;
  tipo: string | null;
  cantidadServicios: number;
  serviciosConHorario: number;
  ultimaPublicacion: string | null;
  totalConversaciones: number;
  portadaUrl: string;
  ultimoCorreo: {
    asunto: string | null;
    mensaje: string | null;
    fecha: string;
    exito: boolean;
  } | null;
}

export interface AutoresFiltros {
  q?: string;
  filtro?: "incompleto";
}
