// Puerto de admin_servicios.php — SOLO lectura + toggle de visibilidad (la única acción
// del PHP real sin efectos externos: es un UPDATE puro, sin correo/push). Deliberadamente
// SIN aprobar/rechazar (envían correo Y push real al tutor, admin_servicios_accion.php:
// 65-90/103-128), SIN eliminar (DELETE FROM servicios permanente, sin soft-delete,
// admin_servicios_accion.php:147-151) y SIN el editor de censura de imagen (canvas +
// sobrescritura irreversible del archivo original) — mismo criterio que el resto de esta
// ronda de paneles admin: acciones con efectos externos reales o irreversibles quedan
// fuera, documentadas, no son un olvido.
export interface ServicioAdmin {
  id: number;
  titulo: string;
  nombreOferente: string | null;
  nombreAlumno: string | null;
  categoria: string | null;
  estado: string;
  motivoRechazo: string | null;
  visible: boolean;
  fechaPublicacion: string;
  portadaUrl: string;
}
