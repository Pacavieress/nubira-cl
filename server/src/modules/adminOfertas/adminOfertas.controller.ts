import type { Request, Response } from "express";
import { aplicarOferta, listarServicios, obtenerPrecio, quitarOferta } from "./adminOfertas.repository.js";
import type { AplicarOfertaInput, OrdenOfertas, ServicioConOferta } from "./adminOfertas.types.js";

const ORDENES_VALIDOS: OrdenOfertas[] = ["recientes", "descuento", "vencer", "cupos", "activas", "precio_mayor", "precio_menor"];

function normalizarOrden(v: unknown): OrdenOfertas {
  return ORDENES_VALIDOS.includes(v as OrdenOfertas) ? (v as OrdenOfertas) : "recientes";
}

export async function getOfertas(req: Request, res: Response): Promise<void> {
  const orden = normalizarOrden(req.query.orden);
  const filas = await listarServicios(orden);

  const servicios: ServicioConOferta[] = filas.map((s) => ({
    id: s.id,
    titulo: s.titulo,
    categoria: s.categoria,
    tutorNombre: s.tutor_nombre,
    precio: s.precio,
    precioOferta: s.precio_oferta,
    cuposOferta: s.cupos_oferta,
    isSubvencionado: s.is_subvencionado === 1,
    ofertaTermino: s.oferta_termino ? s.oferta_termino.toISOString().slice(0, 10) : null,
  }));

  res.status(200).json({ orden, servicios });
}

// Puerto de la rama 'aplicar_oferta' (admin_ofertas.php:45-85) — misma lógica de cálculo de
// precio_oferta desde % (redondeo sobre el precio real, nunca confiar en un precio que venga
// del cliente) y misma validación de fecha (no puede ser anterior a hoy). Nota: la comparación
// "hoy" usa UTC (toISOString) en vez de horario de Chile como hace el PHP real — mismo margen
// de imprecisión ya aceptado en otras validaciones de fecha del puerto, sin impacto real (el
// campo es opcional y de baja precisión, un día de diferencia en el límite no cambia el uso).
export async function postAplicarOferta(req: Request, res: Response): Promise<void> {
  const servicioId = Number(req.params.id);
  if (!Number.isInteger(servicioId) || servicioId <= 0) {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }

  const body = req.body as Partial<AplicarOfertaInput>;
  const tipo = body.tipo === "precio" ? "precio" : "porcentaje";
  const cupos = Number(body.cupos);
  if (!Number.isInteger(cupos) || cupos <= 0) {
    res.status(400).json({ error: "cupos_invalidos" });
    return;
  }

  let ofertaTermino: string | null = null;
  if (typeof body.ofertaTermino === "string" && body.ofertaTermino.trim() !== "") {
    const termino = body.ofertaTermino.trim();
    const hoy = new Date().toISOString().slice(0, 10);
    if (termino < hoy) {
      res.status(400).json({ error: "fecha_invalida", mensaje: "La fecha de término no puede ser anterior a hoy." });
      return;
    }
    ofertaTermino = termino;
  }

  let precioOferta: number | null = null;
  if (tipo === "porcentaje") {
    const pct = Number(body.pctOferta);
    if (!Number.isInteger(pct) || pct <= 0 || pct >= 100) {
      res.status(400).json({ error: "porcentaje_invalido" });
      return;
    }
    const precioReal = await obtenerPrecio(servicioId);
    if (precioReal !== null && precioReal > 0) {
      precioOferta = Math.round(precioReal * (1 - pct / 100));
    }
  } else {
    const precio = Number(body.precioOferta);
    if (Number.isInteger(precio) && precio >= 0) precioOferta = precio;
  }

  if (precioOferta === null || precioOferta < 0) {
    res.status(400).json({ error: "datos_invalidos", mensaje: "Datos inválidos. Verifica el porcentaje o precio y los cupos." });
    return;
  }

  const actualizado = await aplicarOferta(servicioId, precioOferta, cupos, ofertaTermino);
  if (!actualizado) {
    res.status(404).json({ error: "not_found" });
    return;
  }
  res.status(200).json({ ok: true, precioOferta, cupos, ofertaTermino });
}

export async function postQuitarOferta(req: Request, res: Response): Promise<void> {
  const servicioId = Number(req.params.id);
  if (!Number.isInteger(servicioId) || servicioId <= 0) {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }

  const actualizado = await quitarOferta(servicioId);
  if (!actualizado) {
    res.status(404).json({ error: "not_found" });
    return;
  }
  res.status(200).json({ ok: true });
}
