import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { OrdenOfertas } from "./adminOfertas.types.js";

interface ServicioRow extends RowDataPacket {
  id: number;
  titulo: string;
  categoria: string | null;
  precio: number;
  precio_oferta: number | null;
  cupos_oferta: number;
  is_subvencionado: number;
  oferta_termino: Date | null;
  tutor_nombre: string;
}

interface PrecioRow extends RowDataPacket {
  precio: number;
}

// Puerto exacto de admin_ofertas.php:107-116 — mismo whitelist de columnas de ORDER BY
// (nunca interpolar `orden` crudo del query string).
const ORDEN_SQL: Record<OrdenOfertas, string> = {
  recientes: "s.id DESC",
  descuento: "(s.precio - s.precio_oferta) / s.precio DESC, s.id DESC",
  vencer: "CASE WHEN s.oferta_termino IS NULL THEN 1 ELSE 0 END ASC, s.oferta_termino ASC, s.id DESC",
  cupos: "s.cupos_oferta DESC, s.id DESC",
  activas: "s.is_subvencionado DESC, s.id DESC",
  precio_mayor: "s.precio DESC",
  precio_menor: "s.precio ASC",
};

// Puerto exacto de admin_ofertas.php:120-125 — mismo WHERE (solo aprobados), mismo LIMIT 50.
export async function listarServicios(orden: OrdenOfertas): Promise<ServicioRow[]> {
  const [rows] = await pool.query<ServicioRow[]>(
    `SELECT s.id, s.titulo, s.categoria, s.precio, s.precio_oferta, s.cupos_oferta, s.is_subvencionado, s.oferta_termino,
            a.nombre AS tutor_nombre
     FROM servicios s
     JOIN alumnos a ON s.alumno_id = a.id
     WHERE s.estado = 'aprobado'
     ORDER BY ${ORDEN_SQL[orden]}
     LIMIT 50`,
  );
  return rows;
}

export async function obtenerPrecio(servicioId: number): Promise<number | null> {
  const [rows] = await pool.query<PrecioRow[]>("SELECT precio FROM servicios WHERE id = ?", [servicioId]);
  return rows[0]?.precio ?? null;
}

// Puerto exacto de la rama 'aplicar_oferta' (admin_ofertas.php:76-82).
export async function aplicarOferta(servicioId: number, precioOferta: number, cupos: number, ofertaTermino: string | null): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>(
    "UPDATE servicios SET precio_oferta = ?, cupos_oferta = ?, is_subvencionado = 1, oferta_termino = ? WHERE id = ?",
    [precioOferta, cupos, ofertaTermino, servicioId],
  );
  return res.affectedRows > 0;
}

// Puerto exacto de la rama 'quitar_oferta' (admin_ofertas.php:88-96).
export async function quitarOferta(servicioId: number): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>(
    "UPDATE servicios SET precio_oferta = NULL, cupos_oferta = 0, is_subvencionado = 0, oferta_termino = NULL WHERE id = ?",
    [servicioId],
  );
  return res.affectedRows > 0;
}
