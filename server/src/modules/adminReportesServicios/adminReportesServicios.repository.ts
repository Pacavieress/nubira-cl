import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { EstadoReporte } from "./adminReportesServicios.types.js";

interface ReporteRow extends RowDataPacket {
  id: number;
  servicio_id: number;
  motivo: string;
  mensaje: string | null;
  fecha: Date;
  revisado: number;
  titulo_servicio: string;
  usuario_reporta: string;
  correo_reporta: string;
  usuario_reportado: string;
  correo_reportado: string;
  id_usuario_reportado: number;
  bloqueado_reportado: number;
}

interface CountRow extends RowDataPacket {
  total: string;
}

// Puerto exacto de admin_reportes_servicios.php:136-137.
export async function contarPendientes(): Promise<number> {
  const [rows] = await pool.query<CountRow[]>("SELECT COUNT(*) AS total FROM reportes_servicio WHERE revisado = 0");
  return Number(rows[0]?.total ?? 0);
}

// Puerto exacto del SQL de admin_reportes_servicios.php:139-153 — mismo WHERE condicional
// según estado (pendientes -> revisado=0, revisados -> revisado=1, cualquier otro valor
// incl. 'todos' -> sin filtro), mismo ORDER BY r.id DESC. Única diferencia deliberada: el
// PHP hace `SELECT r.*` y luego lee `$r['fecha']` — la columna real es `fecha_reporte`, así
// que esa lectura siempre falla en el PHP (index inexistente, degrada a date('d M Y', 0) =
// "01 Jan 1970" en cada fila) y con display_errors=1 activo en ese archivo también emite un
// warning visible. Acá se lee la columna real (`fecha_reporte AS fecha`) para mostrar la
// fecha real del reporte en vez de replicar ese bug latente del PHP.
export async function listarReportes(estado: EstadoReporte): Promise<ReporteRow[]> {
  const where = estado === "pendientes" ? "WHERE r.revisado = 0" : estado === "revisados" ? "WHERE r.revisado = 1" : "";

  const [rows] = await pool.query<ReporteRow[]>(
    `SELECT r.id, r.servicio_id, r.motivo, r.mensaje, r.fecha_reporte AS fecha, r.revisado,
            s.titulo AS titulo_servicio,
            a.nombre AS usuario_reporta, a.correo AS correo_reporta,
            b.nombre AS usuario_reportado, b.correo AS correo_reportado,
            b.id AS id_usuario_reportado, b.bloqueado AS bloqueado_reportado
     FROM reportes_servicio r
     JOIN servicios s ON r.servicio_id = s.id
     JOIN alumnos a ON r.usuario_id = a.id
     JOIN alumnos b ON s.alumno_id = b.id
     ${where}
     ORDER BY r.id DESC`,
  );
  return rows;
}

// Puerto exacto de la rama 'bloquear_usuario' (admin_reportes_servicios.php:46-59) — UPDATE
// puro, sin correo/push, la única acción de escritura que se porta en esta pieza.
export async function actualizarBloqueo(usuarioId: number, bloqueado: boolean): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>("UPDATE alumnos SET bloqueado = ? WHERE id = ? LIMIT 1", [bloqueado ? 1 : 0, usuarioId]);
  return res.affectedRows > 0;
}
