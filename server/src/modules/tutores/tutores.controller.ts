import type { Request, Response } from "express";
import { searchApuntesPublicos } from "../apuntes/apuntes.repository.js";
import { DEMO_TUTOR_USER_ID, viewerCalificaDemo } from "../../lib/demoTutorVisibility.js";
import { getMinutosRespuestaTutor, searchServiciosAprobados } from "../servicios/servicios.repository.js";
import { mapTutorRow } from "./tutores.mapper.js";
import { getResenasPorRol, getTutorById } from "./tutores.repository.js";

export async function getTutorDetail(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  if (!Number.isInteger(id) || id <= 0) {
    res.status(400).json({ error: "invalid_id" });
    return;
  }

  const tutor = await getTutorById(id);
  if (!tutor) {
    res.status(404).json({ error: "not_found" });
    return;
  }

  // [DEMO] Perfil de la cuenta demo (contacto@nubira.cl) — invisible a quien no califica,
  // salvo el propio dueño viendo su propio perfil.
  const isOwner = req.usuarioId === id;
  const viewerCalifica = isOwner || (await viewerCalificaDemo(req.usuarioId));
  if (id === DEMO_TUTOR_USER_ID && !viewerCalifica) {
    res.status(404).json({ error: "not_found" });
    return;
  }

  // Mismos LIMIT que perfil.php: servicios/apuntes 30 c/u (perfil.php:352,363), reseñas 20
  // c/u (getResenasPorRol). Todo en paralelo — son 5 queries independientes por id.
  const [{ rows: servicios }, { rows: apuntes }, resenasComoTutor, resenasComoAlumno, minutosRespuesta] =
    await Promise.all([
      searchServiciosAprobados({ alumnoId: id, page: 1, limit: 30 }, viewerCalifica),
      searchApuntesPublicos({ alumnoId: id, page: 1, limit: 30 }, viewerCalifica),
      getResenasPorRol(id, "vendedor"),
      getResenasPorRol(id, "comprador"),
      getMinutosRespuestaTutor(id),
    ]);

  res.status(200).json(
    mapTutorRow(tutor, servicios, apuntes, resenasComoTutor, resenasComoAlumno, minutosRespuesta),
  );
}
