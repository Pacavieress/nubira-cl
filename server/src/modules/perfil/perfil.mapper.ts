import type { ApunteRow } from "../apuntes/apuntes.types.js";
import type { ServicioRow, ValoracionRow } from "../servicios/servicios.types.js";
import { mapTutorRow } from "../tutores/tutores.mapper.js";
import type { TutorRow } from "../tutores/tutores.types.js";
import { esFotoValida } from "./perfil.repository.js";
import type { AccesoPanel, CompletitudPerfil, GamificacionPerfil, PerfilPropio, TierPerfil } from "./perfil.types.js";

// Puerto exacto de perfil.php:18-39 (parsear_horarios_servicio, versión reducida a solo el
// booleano 'tiene_horarios' — acá no hace falta la lista de días para armar un calendario,
// solo saber si hay AL MENOS un bloque cargado en algún día).
function tieneHorarios(horariosJson: string | null): boolean {
  if (!horariosJson) return false;
  try {
    const obj: unknown = JSON.parse(horariosJson);
    if (typeof obj !== "object" || obj === null) return false;
    return Object.values(obj as Record<string, unknown>).some((bloques) => Array.isArray(bloques) && bloques.length > 0);
  } catch {
    return false;
  }
}

// Puerto de perfil.php:256-264.
export function computeTierPerfil(maxScore: number): TierPerfil {
  if (maxScore >= 100) return "leyenda";
  if (maxScore >= 80) return "pro";
  if (maxScore >= 60) return "top";
  return "basico";
}

interface ServicioResumen {
  id: number;
  horarios_json: string | null;
  video_estado: string;
  descripcion: string | null;
}

// Agregados compartidos entre la lectura completa del perfil (mapPerfilPropio) y la
// respuesta de guardar bio (putMiPerfilBio) — evita que el endpoint de guardado devuelva
// misiones/completitud desactualizadas o con valores placeholder tras la escritura.
export function computeCompletitudDesdeServicios(serviciosResumen: ServicioResumen[]): {
  faltaHorarios: boolean;
  servicioFaltaHorariosId: number | null;
  faltaVideo: boolean;
  servicioFaltaVideoId: number | null;
  tieneDescLarga: boolean;
  tieneVideo: boolean;
} {
  let faltaHorarios = false;
  let servicioFaltaHorariosId: number | null = null;
  let faltaVideo = false;
  let servicioFaltaVideoId: number | null = null;
  let tieneDescLarga = false;
  for (const s of serviciosResumen) {
    if (!faltaHorarios && !tieneHorarios(s.horarios_json)) {
      faltaHorarios = true;
      servicioFaltaHorariosId = s.id;
    }
    if (!faltaVideo && s.video_estado !== "aprobado") {
      faltaVideo = true;
      servicioFaltaVideoId = s.id;
    }
    if (!tieneDescLarga && (s.descripcion ?? "").trim().length >= 300) {
      tieneDescLarga = true;
    }
  }
  const tieneVideo = serviciosResumen.some((s) => s.video_estado === "aprobado");
  return { faltaHorarios, servicioFaltaHorariosId, faltaVideo, servicioFaltaVideoId, tieneDescLarga, tieneVideo };
}

export function computeGamificacion(
  maxScore: number,
  faltaFoto: boolean,
  bio: string | null,
  tieneDescLarga: boolean,
  tieneApuntePublico: boolean,
  resenasVendedorParaScore: number,
  tieneVideo: boolean,
): GamificacionPerfil {
  return {
    maxScore,
    tier: computeTierPerfil(maxScore),
    progresoPorcentaje: Math.min(100, Math.max(0, maxScore)),
    misiones: {
      foto: !faltaFoto,
      bioLarga: [...(bio ?? "").trim()].length >= 60,
      descripcionLarga: tieneDescLarga,
      apuntePublico: tieneApuntePublico,
      tresResenas: resenasVendedorParaScore >= 3,
      video: tieneVideo,
    },
  };
}

// Puerto de accesos_user (panel_gestion.php:54-67/122-129) — SOLO como lista de links, ver
// nota de alcance en perfil.types.ts. herramientas_tutor son visibles solo si esCreador;
// "Desafío de hoy" se OCULTA para tutores (mismo criterio invertido que el PHP real,
// panel_gestion.php:124 ocultar_para_tutor).
function construirAccesos(esCreador: boolean): AccesoPanel[] {
  const accesos: AccesoPanel[] = [];
  if (esCreador) accesos.push({ titulo: "Mis Publicaciones", href: "/mis-publicaciones" });
  if (esCreador) accesos.push({ titulo: "Clases Vendidas", href: "/clases-vendidas" });
  if (esCreador) accesos.push({ titulo: "Apuntes Vendidos", href: "/ventas-apuntes" });
  accesos.push({ titulo: "Mis Compras", href: "/mis-compras" });
  if (!esCreador) accesos.push({ titulo: "Desafío de hoy", href: "/desafio" });
  if (esCreador) accesos.push({ titulo: "Mis Contratos", href: "/mis-contratos" });
  if (esCreador) accesos.push({ titulo: "Mi Billetera", href: "/mi-billetera" });
  if (esCreador) accesos.push({ titulo: "Para Tutores", href: "/guias/para-tutores" });
  accesos.push({ titulo: "Configurar Cuenta", href: "/configurar-cuenta" });
  accesos.push({ titulo: "Mis Evaluaciones", href: "/mis-evaluaciones" });
  accesos.push({ titulo: "Soporte", href: "/soporte" });
  if (esCreador) accesos.push({ titulo: "Métricas", href: "/metricas" });
  return accesos;
}

export function mapPerfilPropio(
  tutorRow: TutorRow,
  servicios: ServicioRow[],
  apuntes: ApunteRow[],
  resenasComoTutor: ValoracionRow[],
  resenasComoAlumno: ValoracionRow[],
  minutosRespuesta: number | null,
  datosBancarios: { banco: string; numero_cuenta: string | null } | null,
  serviciosResumen: ServicioResumen[],
  vistasPerfil: number,
  maxScore: number,
  resenasVendedorParaScore: number,
): PerfilPropio {
  const base = mapTutorRow(tutorRow, servicios, apuntes, resenasComoTutor, resenasComoAlumno, minutosRespuesta);

  // Puerto de perfil.php:227-228.
  const faltaFoto = !esFotoValida(tutorRow.foto_perfil);
  const faltaBio = (tutorRow.bio ?? "").trim() === "";
  // Puerto de perfil.php:267-269.
  const faltaBanco = !datosBancarios || !datosBancarios.banco || !datosBancarios.numero_cuenta;

  const { faltaHorarios, servicioFaltaHorariosId, faltaVideo, servicioFaltaVideoId, tieneDescLarga, tieneVideo } =
    computeCompletitudDesdeServicios(serviciosResumen);

  const completitud: CompletitudPerfil = {
    faltaFoto,
    faltaBio,
    faltaBanco,
    faltaHorarios,
    servicioFaltaHorariosId,
    faltaVideo,
    servicioFaltaVideoId,
  };

  const gamificacion = computeGamificacion(maxScore, faltaFoto, tutorRow.bio, tieneDescLarga, apuntes.length > 0, resenasVendedorParaScore, tieneVideo);

  // Puerto de perfil.php:384 ($es_creador = !empty($publicaciones) || $total_v_qty > 0).
  const esCreador = servicios.length > 0 || apuntes.length > 0 || resenasComoTutor.length > 0;

  return {
    ...base,
    vistasPerfil,
    esCreador,
    completitud,
    gamificacion,
    accesos: construirAccesos(esCreador),
  };
}
