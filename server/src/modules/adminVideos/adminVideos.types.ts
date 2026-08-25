// Puerto de admin_videos.php ("Videos de presentación") — modera videos que los tutores
// suben a sus servicios. Deliberadamente 100% lectura: 'aprobar'/'rechazar' envían un correo
// real al tutor Y una push notification (admin_videos.php:62-103) — mismo criterio de
// exclusión que el resto de esta ronda de paneles admin. El botón "Descargar" no se porta
// como acción de servidor porque no lo es en el PHP real tampoco: es un link directo al
// archivo (`<a href download>`), se replica igual acá sin backend de por medio.
export type EstadoVideo = "pendiente" | "aprobado" | "rechazado" | "todos";

export interface VideoServicio {
  id: number;
  titulo: string;
  categoria: string | null;
  materia: string | null;
  precio: number;
  videoPath: string;
  videoEstado: "pendiente" | "aprobado" | "rechazado";
  videoMotivoRechazo: string | null;
  videoSubidoEn: string | null;
  alumnoId: number;
  tutorNombre: string;
  tutorFotoPerfil: string | null;
  tutorCorreo: string;
}

export interface VideosResumen {
  filtro: EstadoVideo;
  totalPendientes: number;
  videos: VideoServicio[];
}
