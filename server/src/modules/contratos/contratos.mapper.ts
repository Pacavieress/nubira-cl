import { resolverPortada } from "../../lib/media.js";
import { env } from "../../config/env.js";
import type { ResultadoCupon, ServicioCheckout, ServicioCheckoutRow, SlotExcepcionPublico, SlotExcepcionRow } from "./contratos.types.js";

// Puerto exacto de contratar_servicio.php:84-92 (lógica de precio) + 153 (imagen vía
// helper unificado).
export function mapServicioCheckout(row: ServicioCheckoutRow): ServicioCheckout {
  const esOferta = row.is_subvencionado === 1 && (row.cupos_oferta ?? 0) > 0;
  // row.precio/row.precio_oferta son columnas DECIMAL — mysql2 las devuelve como string sin
  // decimalNumbers:true en el pool (ver db/pool.ts); mismo cast explícito que el resto del
  // codebase (misPublicaciones.mapper.ts, metricas.mapper.ts, etc.).
  const precioOriginal = Number(row.precio);
  const montoInicial = esOferta ? Number(row.precio_oferta ?? 0) : precioOriginal;

  return {
    id: row.id,
    titulo: row.titulo,
    vendedorId: row.alumno_id,
    vendedorNombre: row.nombre_vendedor,
    institucion: row.institucion,
    precioOriginal,
    montoInicial,
    esOferta,
    modalidad: row.modalidad,
    categoria: row.categoria,
    imagenUrl: resolverPortada(row.banco_archivo, row.imagen, env.assetsBaseUrl).main,
    horarios: parseHorarios(row.horarios_json),
  };
}

// Mismo helper que servicios.mapper.ts::parseHorarios — reshape de datos ya presentes, sin
// consulta nueva. Se repite acá (no se comparte) porque web/ y server/ son proyectos
// deliberadamente separados (ver nota en web/src/lib/api.ts sobre no compartir tipos).
function parseHorarios(horariosJson: string | null): Record<string, string[]> | null {
  if (!horariosJson) return null;
  try {
    return JSON.parse(horariosJson);
  } catch {
    return null;
  }
}

// Puerto exacto de contratar_servicio.php:102-151 (motor de validación de becas, versión
// de PREVISUALIZACIÓN — no bloqueante, sin FOR UPDATE; la validación que de verdad importa
// vuelve a correr completa y con bloqueo en crearContrato).
export function validarCuponPreview(
  cupon: { id: number; porcentaje_descuento: number; usos_actuales: number; usos_maximos: number; fecha_expiracion: string | null; servicio_id: number | null } | null,
  servicioId: number,
  montoInicial: number,
): ResultadoCupon {
  if (!cupon) return { ok: false, error: "El código de beca ingresado no existe." };
  if (cupon.usos_maximos > 0 && cupon.usos_actuales >= cupon.usos_maximos) {
    return { ok: false, error: "La beca agotó sus usos." };
  }
  if (cupon.fecha_expiracion) {
    const hoy = new Date().toLocaleDateString("en-CA", { timeZone: "America/Santiago" }); // 'YYYY-MM-DD'
    const expira = cupon.fecha_expiracion.slice(0, 10);
    if (hoy > expira) return { ok: false, error: "La beca ingresada está caducada." };
  }
  const esGlobal = cupon.servicio_id === null || cupon.servicio_id === 0;
  if (!esGlobal && cupon.servicio_id !== servicioId) {
    return { ok: false, error: "La beca ingresada es exclusiva para otro servicio." };
  }
  const montoDescuento = Math.trunc((montoInicial * cupon.porcentaje_descuento) / 100);
  const montoFinal = Math.max(0, montoInicial - montoDescuento);
  return {
    ok: true,
    cuponId: cupon.id,
    descuentoPorcentaje: cupon.porcentaje_descuento,
    montoFinal,
    mensaje: `Beca Nubira (${cupon.porcentaje_descuento}%)`,
  };
}

// Puerto de pagar_slot_excepcion.php:297-325 (datos de display de la card de resumen).
export function mapSlotExcepcionPublico(row: SlotExcepcionRow): SlotExcepcionPublico {
  const ahoraChile = new Date().toLocaleString("sv-SE", { timeZone: "America/Santiago" }).replace(" ", "T");
  return {
    servicioTitulo: row.servicio_titulo,
    tutorNombre: row.tutor_nombre ?? "",
    fechaClase: row.fecha_clase,
    duracionMinutos: row.duracion_minutos || 60,
    monto: Number(row.monto),
    expiraEn: row.expira_en,
    yaExpirado: row.expira_en.replace(" ", "T") < ahoraChile,
  };
}
