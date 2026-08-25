import type { Request, Response } from "express";
import { contarPendientes, listarVideos } from "./adminVideos.repository.js";
import type { EstadoVideo, VideoServicio, VideosResumen } from "./adminVideos.types.js";

function normalizarFiltro(v: unknown): EstadoVideo {
  return v === "aprobado" || v === "rechazado" || v === "todos" ? v : "pendiente";
}

export async function getVideos(req: Request, res: Response): Promise<void> {
  const filtro = normalizarFiltro(req.query.filtro);
  const [filas, totalPendientes] = await Promise.all([listarVideos(filtro), contarPendientes()]);

  const videos: VideoServicio[] = filas.map((v) => ({
    id: v.id,
    titulo: v.titulo,
    categoria: v.categoria,
    materia: v.materia,
    precio: v.precio,
    videoPath: v.video_path,
    videoEstado: v.video_estado,
    videoMotivoRechazo: v.video_motivo_rechazo,
    videoSubidoEn: v.video_subido_en ? v.video_subido_en.toISOString() : null,
    alumnoId: v.alumno_id,
    tutorNombre: v.tutor_nombre,
    tutorFotoPerfil: v.foto_perfil,
    tutorCorreo: v.tutor_correo,
  }));

  const body: VideosResumen = { filtro, totalPendientes, videos };
  res.status(200).json(body);
}
