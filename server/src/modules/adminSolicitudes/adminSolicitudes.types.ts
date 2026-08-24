// Puerto de admin_solicitudes.php ("Solicitudes de Institución" — solicitudes_instituciones,
// 19 filas reales en local). Deliberadamente 100% lectura: 'aprobar_id'/'rechazar_id' envían
// un correo real a quien pidió el dominio (enviarCorreoSolicitudInstitucion, admin_solicitudes.php:61,74);
// 'eliminar_masivo' es un hard DELETE sin soft-delete (admin_solicitudes.php:87-94, mismo
// criterio de exclusión que 'eliminar_pendiente' en adminLoginFallos); 'marcar_revisada' no
// tiene correo pero tampoco tiene camino de vuelta a 'pendiente' en el PHP real — a diferencia
// del toggle de VIP (activo 0/1) o del bloqueo de adminReportesServicios, es de un solo
// sentido, así que se excluye junto con el resto en vez de portarse a medias. Las 3 acciones
// quedan documentadas acá y enlazan al sitio PHP real.
export type EstadoSolicitud = "" | "pendiente" | "revisada";

export interface SolicitudInstitucion {
  id: number;
  institucion: string;
  email: string;
  fecha: string | null;
  estado: "pendiente" | "revisada";
  correoEnviado: boolean;
}

export interface SolicitudesResumen {
  estado: EstadoSolicitud;
  solicitudes: SolicitudInstitucion[];
}
