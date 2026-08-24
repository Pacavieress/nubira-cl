import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { ComprasApuntesFiltros, OrdenComprasApuntes } from "./adminComprasApuntes.types.js";

const ORDEN_SQL: Record<OrdenComprasApuntes, string> = {
  mayor_monto: "total_monto DESC",
  mas_ventas: "total_ventas DESC, total_monto DESC",
  recientes: "ultima_venta DESC",
  menor_monto: "total_monto ASC",
  alfabetico: "vend.nombre ASC",
};

// Puerto exacto de admin_compras_apuntes.php:56-91 (mismo WHERE dinámico, mismos 6 filtros
// posibles) — compartido entre las 3 queries (KPI, agrupada por tutor, detalle), igual que
// el PHP real reutiliza $where_sql/$bind_types/$bind_vals en las 3.
function construirWhere(filtros: ComprasApuntesFiltros): { where: string; params: (string | number)[] } {
  const condiciones: string[] = [];
  const params: (string | number)[] = [];

  if (filtros.qApunte) {
    condiciones.push("a.titulo LIKE ?");
    params.push(`%${filtros.qApunte}%`);
  }
  if (filtros.qComprador) {
    condiciones.push("comp.correo LIKE ?");
    params.push(`%${filtros.qComprador}%`);
  }
  if (filtros.qVendedor) {
    condiciones.push("vend.correo LIKE ?");
    params.push(`%${filtros.qVendedor}%`);
  }
  if (filtros.estadoPago !== undefined) {
    condiciones.push("va.pagado_al_vendedor = ?");
    params.push(Number(filtros.estadoPago));
  }
  if (filtros.fechaDesde) {
    condiciones.push("DATE(va.fecha) >= ?");
    params.push(filtros.fechaDesde);
  }
  if (filtros.fechaHasta) {
    condiciones.push("DATE(va.fecha) <= ?");
    params.push(filtros.fechaHasta);
  }

  return { where: condiciones.length > 0 ? `WHERE ${condiciones.join(" AND ")}` : "", params };
}

// Puerto exacto de admin_compras_apuntes.php:32 — corre en CADA GET, mismo filtro
// (precio > 0), no toca la columna 'revisado' (esa es la notificación del vendedor).
export async function marcarComprasRevisadas(): Promise<void> {
  await pool.query("UPDATE ventas_apuntes SET revisado_por_admin = 1 WHERE precio > 0 AND revisado_por_admin = 0");
}

// Mismo caso que TutorGrupoRow más abajo: COUNT/SUM sin GROUP BY también vienen como
// string desde mysql2 — ya se convierten con Number() en el return de esta función.
interface KpiRow extends RowDataPacket {
  total: string;
  suma: string;
  total_tutores: string;
}

export async function getKpis(filtros: ComprasApuntesFiltros): Promise<{ totalCompras: number; totalMonto: number; totalTutores: number }> {
  const { where, params } = construirWhere(filtros);
  const [rows] = await pool.query<KpiRow[]>(
    `SELECT COUNT(va.id) AS total, COALESCE(SUM(va.precio), 0) AS suma, COUNT(DISTINCT va.vendedor_id) AS total_tutores
     FROM ventas_apuntes va
     JOIN apuntes a ON va.apunte_id = a.id
     JOIN alumnos comp ON va.comprador_id = comp.id
     JOIN alumnos vend ON va.vendedor_id = vend.id
     ${where}`,
    params,
  );
  const row = rows[0];
  return { totalCompras: Number(row?.total ?? 0), totalMonto: Number(row?.suma ?? 0), totalTutores: Number(row?.total_tutores ?? 0) };
}

// Puerto exacto de admin_compras_apuntes.php:120-136 — compras confirmadas ('pagado') sin
// fila correspondiente en ventas_apuntes (ver bug_sync_compras_ventas_apuntes en memoria:
// hallazgo ya conocido, este panel es justamente donde se detectó).
export async function getDesync(): Promise<number> {
  const [rows] = await pool.query<RowDataPacket[]>(
    `SELECT COUNT(*) AS total FROM compras c
     WHERE c.id_apunte > 0
       AND c.estado_pago = 'pagado'
       AND NOT EXISTS (
           SELECT 1 FROM ventas_apuntes va
           WHERE va.apunte_id = c.id_apunte AND va.comprador_id = c.usuario_id
       )`,
  );
  return Number((rows[0] as { total: number } | undefined)?.total ?? 0);
}

// total_ventas/total_monto/pagadas/pendientes vienen como STRING desde mysql2 (COUNT/SUM
// dentro de un GROUP BY, sin CAST) — no number pese a que SQL los calcula como enteros. El
// controller los convierte explícitamente con Number() antes de sumarlos (bug real
// encontrado por el test de este módulo: sin esa conversión, sumar "11" + "0" concatena
// como texto → "110", no 11).
interface TutorGrupoRow extends RowDataPacket {
  vendedor_id: number;
  vendedor_nombre: string;
  vendedor_correo: string;
  total_ventas: string;
  total_monto: string;
  ultima_venta: Date;
  pagadas: string;
  pendientes: string;
}

export async function getTutoresAgrupados(filtros: ComprasApuntesFiltros): Promise<TutorGrupoRow[]> {
  const { where, params } = construirWhere(filtros);
  const ordenSql = ORDEN_SQL[filtros.orden ?? "mayor_monto"];
  const [rows] = await pool.query<TutorGrupoRow[]>(
    `SELECT va.vendedor_id, vend.nombre AS vendedor_nombre, vend.correo AS vendedor_correo,
            COUNT(va.id) AS total_ventas, COALESCE(SUM(va.precio), 0) AS total_monto, MAX(va.fecha) AS ultima_venta,
            SUM(va.pagado_al_vendedor = 1) AS pagadas, SUM(va.pagado_al_vendedor = 0) AS pendientes
     FROM ventas_apuntes va
     JOIN apuntes a ON va.apunte_id = a.id
     JOIN alumnos comp ON va.comprador_id = comp.id
     JOIN alumnos vend ON va.vendedor_id = vend.id
     ${where}
     GROUP BY va.vendedor_id, vend.nombre, vend.correo
     ORDER BY ${ordenSql}`,
    params,
  );
  return rows;
}

interface DetalleRow extends RowDataPacket {
  id: number;
  vendedor_id: number;
  fecha: Date;
  apunte_titulo: string;
  asignatura: string | null;
  comprador_nombre: string;
  comprador_correo: string;
  precio: string | number;
  pagado_al_vendedor: number;
  payment_id: string | null;
}

// Puerto exacto de admin_compras_apuntes.php:171-205 — mismo LIMIT 1000, mismo ORDER BY
// (vendedor_id ASC, fecha DESC), mismo subquery de payment_id (última compra 'pagado'
// asociada a ese apunte+comprador).
export async function getDetalle(filtros: ComprasApuntesFiltros): Promise<DetalleRow[]> {
  const { where, params } = construirWhere(filtros);
  const [rows] = await pool.query<DetalleRow[]>(
    `SELECT va.id, va.vendedor_id, va.fecha, a.titulo AS apunte_titulo, a.asignatura,
            comp.nombre AS comprador_nombre, comp.correo AS comprador_correo,
            va.precio, va.pagado_al_vendedor,
            (SELECT c.payment_id FROM compras c
             WHERE c.id_apunte = va.apunte_id AND c.usuario_id = va.comprador_id AND c.estado_pago = 'pagado'
             ORDER BY c.id DESC LIMIT 1) AS payment_id
     FROM ventas_apuntes va
     JOIN apuntes a ON va.apunte_id = a.id
     JOIN alumnos comp ON va.comprador_id = comp.id
     JOIN alumnos vend ON va.vendedor_id = vend.id
     ${where}
     ORDER BY va.vendedor_id ASC, va.fecha DESC
     LIMIT 1000`,
    params,
  );
  return rows;
}
