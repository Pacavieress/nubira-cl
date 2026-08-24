import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

interface ServicioAdminRow extends RowDataPacket {
  id: number;
  titulo: string;
  nombre_oferente: string | null;
  categoria: string | null;
  imagen: string | null;
  imagen_banco_id: number | null;
  estado: string;
  fecha_publicacion: Date;
  alumno_id: number;
  motivo_rechazo: string | null;
  visible: number | null;
  nombre_alumno: string | null;
  banco_archivo: string | null;
}

// Puerto exacto de admin_servicios.php:75-89 (mismo LIMIT 100, mismo ORDER BY id DESC,
// mismo LIKE sobre titulo/nombre_oferente).
export async function listarServiciosAdmin(q?: string): Promise<ServicioAdminRow[]> {
  const where = q ? "WHERE s.titulo LIKE ? OR s.nombre_oferente LIKE ?" : "";
  const params = q ? [`%${q}%`, `%${q}%`] : [];
  const [rows] = await pool.query<ServicioAdminRow[]>(
    `SELECT s.id, s.titulo, s.nombre_oferente, s.categoria, s.imagen, s.imagen_banco_id, s.estado, s.fecha_publicacion, s.alumno_id, s.motivo_rechazo, s.visible,
            a.nombre AS nombre_alumno, bi.archivo AS banco_archivo
     FROM servicios s
     LEFT JOIN alumnos a ON s.alumno_id = a.id
     LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
     ${where}
     ORDER BY s.id DESC
     LIMIT 100`,
    params,
  );
  return rows;
}

// Puerto exacto de la rama 'toggle_visibilidad' de admin_servicios_accion.php:135-145 —
// UPDATE puro, sin correo/push, la única acción de escritura que se porta en esta pieza.
export async function actualizarVisibilidad(id: number, visible: boolean): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>("UPDATE servicios SET visible = ? WHERE id = ?", [visible ? 1 : 0, id]);
  return res.affectedRows > 0;
}
