import type { Request, Response } from "express";
import { searchApuntesPublicos } from "../apuntes/apuntes.repository.js";
import { getDatosBancarios } from "../miBilletera/miBilletera.repository.js";
import { getMinutosRespuestaTutor, searchServiciosAprobados } from "../servicios/servicios.repository.js";
import { getResenasPorRol, getTutorById } from "../tutores/tutores.repository.js";
import { validarBio } from "../../lib/bioFilter.js";
import { computeCompletitudDesdeServicios, computeGamificacion, mapPerfilPropio } from "./perfil.mapper.js";
import {
  actualizarBioAlumno,
  actualizarScoreServicio,
  contarApuntesAprobadosParaScore,
  contarResenasVendedorParaScore,
  esFotoValida,
  getServiciosPropiosResumen,
  getTodosServiciosPropiosParaScore,
  getVistasYMaxScore,
} from "./perfil.repository.js";
import type { ActualizarBioError, ActualizarBioExito } from "./perfil.types.js";

// Puerto de perfil.php con $es_propio siempre true (perfil.php:112) — requireAuth ya
// garantiza que req.usuarioId ES el dueño del perfil que se está pidiendo, así que acá no
// existe la rama "ver el perfil de otro" que sí tiene el PHP real (esa es
// /api/tutores/:id, ya construida). Mismos LIMIT/queries que esa ruta (30 servicios/
// apuntes, 20 reseñas c/u) más las 3 queries propias de $es_propio (bancarios,
// completitud+gamificación, vistas+score).
export async function getMiPerfil(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;

  const [tutor, resenasComoTutor, resenasComoAlumno, { rows: servicios }, { rows: apuntes }, minutosRespuesta, datosBancarios, serviciosResumen, { vistasPerfil, maxScore }, resenasVendedorParaScore] =
    await Promise.all([
      getTutorById(usuarioId),
      getResenasPorRol(usuarioId, "vendedor"),
      getResenasPorRol(usuarioId, "comprador"),
      searchServiciosAprobados({ alumnoId: usuarioId, page: 1, limit: 30 }),
      searchApuntesPublicos({ alumnoId: usuarioId, page: 1, limit: 30 }),
      getMinutosRespuestaTutor(usuarioId),
      getDatosBancarios(usuarioId),
      getServiciosPropiosResumen(usuarioId),
      getVistasYMaxScore(usuarioId),
      contarResenasVendedorParaScore(usuarioId),
    ]);

  if (!tutor) {
    res.status(404).json({ error: "not_found" });
    return;
  }

  const perfil = mapPerfilPropio(
    tutor,
    servicios,
    apuntes,
    resenasComoTutor,
    resenasComoAlumno,
    minutosRespuesta,
    datosBancarios,
    serviciosResumen,
    vistasPerfil,
    maxScore,
    resenasVendedorParaScore,
  );

  res.status(200).json(perfil);
}

// Puerto de actualizar_score_servicio() en bucle (app/actualizar_bio.php:148-159) — se
// llama SOLO tras un guardado de bio exitoso, igual que el PHP real. fotoPerfil/bio ya
// reflejan el valor recién guardado (no hace falta releer alumnos).
async function recalcularScoresPropios(alumnoId: number, fotoPerfil: string | null, bioNueva: string): Promise<void> {
  const fotoOk = esFotoValida(fotoPerfil);
  const bioOk = [...bioNueva.trim()].length >= 60;

  const [apunteCount, resenaCount, serviciosPropios] = await Promise.all([
    contarApuntesAprobadosParaScore(alumnoId),
    contarResenasVendedorParaScore(alumnoId),
    getTodosServiciosPropiosParaScore(alumnoId),
  ]);
  const apunteOk = apunteCount >= 1;
  const resenaOk = resenaCount >= 3;

  await Promise.all(
    serviciosPropios.map((s) => {
      let score = 0;
      if (fotoOk) score += 20;
      if (bioOk) score += 20;
      if ([...(s.descripcion ?? "").trim()].length >= 300) score += 20;
      if (apunteOk) score += 20;
      if (resenaOk) score += 20;
      if (s.video_estado === "aprobado") score += 20;
      return actualizarScoreServicio(s.id, score);
    }),
  );
}

// Puerto de app/actualizar_bio.php completo — mismas validaciones/mensajes (ver
// bioFilter.ts), mismo recalculo de score al guardar. A diferencia del PHP real, acá no
// hay CSRF de sesión PHP que validar (requireAuth ya cubre el mismo problema que el CSRF
// busca prevenir en este endpoint: que un tercero no autenticado dispare la escritura).
export async function putMiPerfilBio(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const body = req.body as { bio?: unknown };
  const bio = typeof body.bio === "string" ? body.bio.trim() : "";

  const validacion = validarBio(bio);
  if (!validacion.ok) {
    const error: ActualizarBioError = { ok: false, mensaje: validacion.mensaje };
    res.status(400).json(error);
    return;
  }

  await actualizarBioAlumno(usuarioId, bio);

  const tutor = await getTutorById(usuarioId);
  const fotoPerfil = tutor?.foto_perfil ?? null;
  await recalcularScoresPropios(usuarioId, fotoPerfil, bio);

  // Recompone completitud/gamificación con la MISMA lógica que el GET (computeCompletitudDesdeServicios
  // + computeGamificacion) para que el widget del cliente quede consistente tras guardar,
  // sin placeholders ni necesidad de un refetch completo del perfil.
  const [{ maxScore }, serviciosResumen, resenasVendedorParaScore, apunteCount] = await Promise.all([
    getVistasYMaxScore(usuarioId),
    getServiciosPropiosResumen(usuarioId),
    contarResenasVendedorParaScore(usuarioId),
    contarApuntesAprobadosParaScore(usuarioId),
  ]);
  const { tieneDescLarga, tieneVideo } = computeCompletitudDesdeServicios(serviciosResumen);
  const gamificacion = computeGamificacion(maxScore, !esFotoValida(fotoPerfil), bio, tieneDescLarga, apunteCount > 0, resenasVendedorParaScore, tieneVideo);

  const exito: ActualizarBioExito = { ok: true, bio, gamificacion };
  res.status(200).json(exito);
}
