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

// Puerto EXACTO de accesos_user (panel_gestion.php:54-67, íconos copiados tal cual) + su
// gating de 3 vías (panel_gestion.php:122-139):
//   - herramientas_tutor: visibles SOLO si esCreador (es_tutor real).
//   - herramientas_alumno ("Mis Compras"): visible SOLO si haCompradoAlgo — bug real
//     encontrado el 26/08/2026: la versión anterior de este puerto la mostraba siempre.
//   - ocultar_para_tutor ("Desafío de hoy"): oculto SI esCreador (criterio invertido).
// "Mis Ventas" del array $herramientas_tutor real NO se replica: es una referencia muerta
// a un tile que no existe en $accesos_user (ya documentado como pendiente de limpieza en
// CLAUDE.md, panel_gestion.php:120-122).
function construirAccesos(esCreador: boolean, haCompradoAlgo: boolean): AccesoPanel[] {
  const accesos: AccesoPanel[] = [];
  if (esCreador)
    accesos.push({
      titulo: "Mis Publicaciones",
      href: "/mis-publicaciones",
      iconoSvg:
        '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />',
    });
  if (esCreador)
    accesos.push({
      titulo: "Clases Vendidas",
      href: "/clases-vendidas",
      iconoSvg:
        '<path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.499 5.516 50.636 50.636 0 0 1-2.657.813m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 10.499-3.342M6.75 12V18.75m10.5-6V18.75" />',
    });
  if (esCreador)
    accesos.push({
      titulo: "Apuntes Vendidos",
      href: "/ventas-apuntes",
      iconoSvg:
        '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />',
    });
  if (haCompradoAlgo)
    accesos.push({
      titulo: "Mis Compras",
      href: "/mis-compras",
      iconoSvg:
        '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />',
    });
  if (!esCreador)
    accesos.push({
      titulo: "Desafío de hoy",
      href: "/desafio",
      iconoSvg:
        '<path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 00-.491 6.347A48.62 48.62 0 0112 20.904a48.62 48.62 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.636 50.636 0 00-2.658-.813A59.906 59.906 0 0112 3.493a59.903 59.903 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5" />',
    });
  if (esCreador)
    accesos.push({
      titulo: "Mis Contratos",
      href: "/mis-contratos",
      iconoSvg:
        '<path stroke-linecap="round" stroke-linejoin="round" d="M10.125 2.25h-4.5c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125v-9M10.125 2.25h-4.5h.375a9 9 0 0 1 9 9v.375M10.125 2.25A3.375 3.375 0 0 1 13.5 5.625v1.5c0 .621.504 1.125 1.125 1.125h1.5a3.375 3.375 0 0 1 3.375 3.375M9 15l2.25 2.25L15 12" />',
    });
  if (esCreador)
    accesos.push({
      titulo: "Mi Billetera",
      href: "/mi-billetera",
      iconoSvg:
        '<path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" />',
    });
  if (esCreador)
    accesos.push({
      titulo: "Para Tutores",
      href: "/guias/para-tutores",
      iconoSvg:
        '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />',
    });
  accesos.push({
    titulo: "Configurar Cuenta",
    href: "/configurar-cuenta",
    iconoSvg:
      '<path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />',
  });
  accesos.push({
    titulo: "Mis Evaluaciones",
    href: "/mis-evaluaciones",
    iconoSvg:
      '<path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.563.563 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.563.563 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" />',
  });
  accesos.push({
    titulo: "Soporte",
    href: "/soporte",
    iconoSvg:
      '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />',
  });
  if (esCreador)
    accesos.push({
      titulo: "Métricas",
      href: "/metricas",
      iconoSvg:
        '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />',
    });
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
  haCompradoAlgo: boolean,
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
    accesos: construirAccesos(esCreador, haCompradoAlgo),
  };
}
