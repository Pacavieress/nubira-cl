import type { Request, Response } from "express";
import { env } from "../../config/env.js";
import { getStats, listarCampanas, listarImagenesDeCampanas, listarLectores } from "./adminAvisos.repository.js";
import type { AvisoCampana, AvisoLector, AvisosResumen } from "./adminAvisos.types.js";

export async function getAvisos(_req: Request, res: Response): Promise<void> {
  const [stats, campanas] = await Promise.all([getStats(), listarCampanas()]);

  const campanaIds = campanas.map((c) => c.id);
  const imagenes = await listarImagenesDeCampanas(campanaIds);

  const body: AvisosResumen = {
    totalCampanas: Number(stats.total ?? 0),
    totalDestinatarios: Number(stats.destinatarios ?? 0),
    campanas: campanas.map(
      (c): AvisoCampana => ({
        id: c.id,
        titulo: c.titulo,
        mensaje: c.mensaje,
        tipo: c.tipo,
        segmento: c.segmento,
        totalDestinatarios: c.total_destinatarios,
        leidos: Number(c.leidos),
        fechaCreacion: c.fecha_creacion.toISOString(),
        imagenes: imagenes
          .filter((img) => img.campana_id === c.id)
          .map((img) => ({
            archivo: img.archivo,
            url: `${env.assetsBaseUrl}/upload/avisos/${c.id}/${img.archivo}`,
          })),
      }),
    ),
  };

  res.status(200).json(body);
}

export async function getLectoresDeCampana(req: Request, res: Response): Promise<void> {
  const campanaId = Number(req.params.id);
  if (!Number.isInteger(campanaId) || campanaId <= 0) {
    res.status(400).json({ error: "id_invalido" });
    return;
  }

  const filas = await listarLectores(campanaId);
  const body: AvisoLector[] = filas.map((r) => ({
    nombre: r.nombre,
    institucion: r.institucion,
    fechaLeido: r.fecha_leido.toISOString(),
  }));

  res.status(200).json(body);
}
