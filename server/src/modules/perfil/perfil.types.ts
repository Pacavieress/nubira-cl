import type { TutorPublico } from "../tutores/tutores.types.js";

// Puerto de perfil.php:256-264 — 4 niveles según max_score (MAX(score_nubira) de los
// servicios aprobados/visibles del tutor).
export type TierPerfil = "basico" | "top" | "pro" | "leyenda";

// Puerto del checklist de "Tu Nivel de Tutor" (perfil.php:684-746) — mismos 6 factores que
// actualizar_score_servicio() (app/helpers/usuario_helper.php:56-141), cada uno +20 pts.
export interface MisionesGamificacion {
  foto: boolean;
  bioLarga: boolean;
  descripcionLarga: boolean;
  apuntePublico: boolean;
  tresResenas: boolean;
  video: boolean;
}

export interface GamificacionPerfil {
  maxScore: number;
  tier: TierPerfil;
  progresoPorcentaje: number;
  misiones: MisionesGamificacion;
}

// Puerto del banner "Completa tu perfil" (perfil.php:502-526) — cada bandera decide si el
// banner completo se muestra Y qué botones aparecen dentro. servicioFalta*Id apunta al
// PRIMER servicio propio (aprobado/visible) que le falta ese dato — mismo criterio que el
// PHP real (perfil.php:238-254, corta en el primer hallazgo por campo, no junta todos).
export interface CompletitudPerfil {
  faltaFoto: boolean;
  faltaBio: boolean;
  faltaBanco: boolean;
  faltaHorarios: boolean;
  servicioFaltaHorariosId: number | null;
  faltaVideo: boolean;
  servicioFaltaVideoId: number | null;
}

// Puerto de accesos_user (app/componentes/panel_gestion.php:54-67/117-139) — [REHECHO
// 26/08/2026, ver perfil.mapper.ts::construirAccesos] mismos 12 destinos + ícono real +
// mismo gating de 3 vías (herramientas_tutor / herramientas_alumno / ocultar_para_tutor).
// Sigue SIN badges de contador en vivo (mensajes no leídos, ventas pendientes, etc. —
// mismo criterio ya decidido para el hub /admin: cada uno exigiría su propio endpoint de
// conteo, fuera de alcance de esta pieza).
export interface AccesoPanel {
  titulo: string;
  href: string;
  iconoSvg: string;
}

export interface PerfilPropio extends TutorPublico {
  vistasPerfil: number;
  esCreador: boolean;
  completitud: CompletitudPerfil;
  gamificacion: GamificacionPerfil;
  accesos: AccesoPanel[];
}

export interface ActualizarBioExito {
  ok: true;
  bio: string;
  gamificacion: GamificacionPerfil;
}

export interface ActualizarBioError {
  ok: false;
  mensaje: string;
}
