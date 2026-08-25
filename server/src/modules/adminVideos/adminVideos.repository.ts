import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { EstadoVideo } from "./adminVideos.types.js";

interface VideoRow extends RowDataPacket {
  id: number;
  titulo: string;
  categoria: string | null;
  materia: string | null;
  precio: number;
  video_path: string;
  video_estado: "pendiente" | "aprobado" | "rechazado";
  video_motivo_rechazo: string | null;
  video_subido_en: Date;
  alumno_id: number;
  tutor_nombre: string;
  foto_perfil: string | null;
  tutor_correo: string;
}

interface CountRow extends RowDataPacket {
  total: string;
}

// Puerto exacto del SQL de admin_videos.php:112-122 — mismo WHERE condicional según filtro,
// mismo ORDER BY (pendientes primero, luego más recientes), mismo LIMIT 200.
export async function listarVideos(filtro: EstadoVideo): Promise<VideoRow[]> {
  const where = filtro === "todos" ? "" : "AND s.video_estado = ?";
  const params = filtro === "todos" ? [] : [filtro];

  const [rows] = await pool.query<VideoRow[]>(
    `SELECT s.id, s.titulo, s.categoria, s.materia, s.precio,
            s.video_path, s.video_estado, s.video_motivo_rechazo, s.video_subido_en,
            a.id AS alumno_id, a.nombre AS tutor_nombre, a.foto_perfil, a.correo AS tutor_correo
     FROM servicios s
     JOIN alumnos a ON s.alumno_id = a.id
     WHERE s.video_path IS NOT NULL AND s.video_path != ''
     ${where}
     ORDER BY CASE s.video_estado WHEN 'pendiente' THEN 0 ELSE 1 END, s.video_subido_en DESC
     LIMIT 200`,
    params,
  );
  return rows;
}

// Puerto exacto de admin_videos.php:130-136.
export async function contarPendientes(): Promise<number> {
  const [rows] = await pool.query<CountRow[]>(
    "SELECT COUNT(*) AS total FROM servicios WHERE video_path IS NOT NULL AND video_path != '' AND video_estado = 'pendiente'",
  );
  return Number(rows[0]?.total ?? 0);
}
