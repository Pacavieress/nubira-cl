import type { Request, Response } from "express";
import { getDesync, getDetalle, getKpis, getTutoresAgrupados, marcarComprasRevisadas } from "./adminComprasApuntes.repository.js";
import type { ComprasApuntesFiltros, ComprasApuntesResumen, OrdenComprasApuntes, TutorVentas } from "./adminComprasApuntes.types.js";

const ORDENES_VALIDOS: OrdenComprasApuntes[] = ["mayor_monto", "mas_ventas", "recientes", "menor_monto", "alfabetico"];

function leerFiltros(req: Request): ComprasApuntesFiltros {
  const q = req.query;
  const estadoPago = q.estado_pago === "0" || q.estado_pago === "1" ? q.estado_pago : undefined;
  const orden = typeof q.orden === "string" && (ORDENES_VALIDOS as string[]).includes(q.orden) ? (q.orden as OrdenComprasApuntes) : undefined;
  return {
    qApunte: typeof q.q_apunte === "string" ? q.q_apunte.trim() || undefined : undefined,
    qComprador: typeof q.q_comprador === "string" ? q.q_comprador.trim() || undefined : undefined,
    qVendedor: typeof q.q_vendedor === "string" ? q.q_vendedor.trim() || undefined : undefined,
    estadoPago,
    fechaDesde: typeof q.fecha_desde === "string" ? q.fecha_desde || undefined : undefined,
    fechaHasta: typeof q.fecha_hasta === "string" ? q.fecha_hasta || undefined : undefined,
    orden,
  };
}

export async function getComprasApuntes(req: Request, res: Response): Promise<void> {
  const filtros = leerFiltros(req);

  await marcarComprasRevisadas();
  const [kpis, desync, gruposRaw, detalleRaw] = await Promise.all([
    getKpis(filtros),
    getDesync(),
    getTutoresAgrupados(filtros),
    getDetalle(filtros),
  ]);

  // Puerto de admin_compras_apuntes.php:201-203 — agrupación del detalle por vendedor_id,
  // hecha en el propio PHP (no en SQL).
  const detallePorTutor = new Map<number, typeof detalleRaw>();
  for (const fila of detalleRaw) {
    const lista = detallePorTutor.get(fila.vendedor_id) ?? [];
    lista.push(fila);
    detallePorTutor.set(fila.vendedor_id, lista);
  }

  const tutores: TutorVentas[] = gruposRaw.map((t) => ({
    vendedorId: t.vendedor_id,
    vendedorNombre: t.vendedor_nombre,
    vendedorCorreo: t.vendedor_correo,
    // COUNT/SUM en un GROUP BY vienen como string desde mysql2, no number — sin el Number()
    // explícito, sumarlos en el cliente concatena en vez de sumar (bug real, atrapado por
    // el test de este mismo archivo: 11 + 0 devolvía "110", no 11).
    totalVentas: Number(t.total_ventas),
    totalMonto: Number(t.total_monto),
    ultimaVenta: t.ultima_venta.toISOString(),
    pagadas: Number(t.pagadas),
    pendientes: Number(t.pendientes),
    detalle: (detallePorTutor.get(t.vendedor_id) ?? []).map((d) => ({
      id: d.id,
      fecha: d.fecha.toISOString(),
      apunteTitulo: d.apunte_titulo,
      asignatura: d.asignatura,
      compradorNombre: d.comprador_nombre,
      compradorCorreo: d.comprador_correo,
      precio: Number(d.precio),
      pagadoAlVendedor: d.pagado_al_vendedor === 1,
      paymentId: d.payment_id,
    })),
  }));

  const body: ComprasApuntesResumen = {
    kpis,
    desync,
    tutores,
    detalleTruncado: detalleRaw.length >= 1000,
  };

  res.status(200).json(body);
}
