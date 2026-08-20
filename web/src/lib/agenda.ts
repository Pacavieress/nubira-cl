import type { ContratoAgenda } from "./api";

// Puerto exacto de las 3 funciones de mis_contratos.php:91-147. Corre en el Server
// Component de la página (Node, request-time) — mismo criterio que el resto de esta
// migración: server/ expone fechas crudas, la presentación (agrupar/formatear) vive acá.
//
// Asimetría real replicada a propósito: la AGRUPACIÓN usa fechaClase ?? fechaEstimada
// (línea 214/318 del PHP), pero el texto "amigable"/"tiempo restante" de cada card usa
// SOLO fechaClase (línea 246-248) — un contrato con fechaEstimada pero sin fechaClase cae
// en el grupo correcto pero no muestra ninguna fecha/cuenta regresiva en su card. No es un
// bug de este puerto, es el comportamiento real de la página fuente.

export type GrupoTemporal = "pasada" | "hoy" | "esta_semana" | "mas_adelante" | "sin_fecha";

export function grupoTemporal(fechaIso: string | null): GrupoTemporal {
  if (!fechaIso) return "sin_fecha";
  const ts = new Date(fechaIso).getTime();
  const ahora = new Date();

  const inicioHoy = new Date(ahora.getFullYear(), ahora.getMonth(), ahora.getDate()).getTime();
  const finHoy = inicioHoy + 24 * 60 * 60 * 1000 - 1;

  const diaSemana = ahora.getDay(); // 0=domingo
  const diasHastaDomingo = (7 - diaSemana) % 7;
  const finSemana = new Date(ahora.getFullYear(), ahora.getMonth(), ahora.getDate() + diasHastaDomingo, 23, 59, 59).getTime();

  if (ts < inicioHoy) return "pasada";
  if (ts >= inicioHoy && ts <= finHoy) return "hoy";
  if (ts > finHoy && ts <= finSemana) return "esta_semana";
  return "mas_adelante";
}

const DIAS = ["Dom", "Lun", "Mar", "Mié", "Jue", "Vie", "Sáb"];
const MESES = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];

export function formatearFechaClase(fechaIso: string | null): string | null {
  if (!fechaIso) return null;
  const fecha = new Date(fechaIso);
  const ahora = new Date();
  const hoy = new Date(ahora.getFullYear(), ahora.getMonth(), ahora.getDate());
  const manana = new Date(hoy);
  manana.setDate(hoy.getDate() + 1);
  const pasado = new Date(hoy);
  pasado.setDate(hoy.getDate() + 2);

  const hora = `${String(fecha.getHours()).padStart(2, "0")}:${String(fecha.getMinutes()).padStart(2, "0")}`;
  const esHoraLegacy = hora === "23:59"; // Contratos viejos sin hora real

  if (fecha >= hoy && fecha < manana) return esHoraLegacy ? "Hoy" : `Hoy a las ${hora}`;
  if (fecha >= manana && fecha < pasado) return esHoraLegacy ? "Mañana" : `Mañana a las ${hora}`;

  const base = `${DIAS[fecha.getDay()]} ${fecha.getDate()} ${MESES[fecha.getMonth()]}`;
  return esHoraLegacy ? base : `${base}, ${hora}`;
}

export function tiempoHastaClase(fechaIso: string | null): string | null {
  if (!fechaIso) return null;
  const diffMs = new Date(fechaIso).getTime() - Date.now();
  if (diffMs <= 0) return null;

  const diffMin = diffMs / 60000;
  if (diffMin < 60) return `En ${Math.max(1, Math.round(diffMin))} min`;
  const diffHr = diffMs / 3600000;
  if (diffHr < 24) {
    const h = Math.round(diffHr);
    return `En ${h} hr${h > 1 ? "s" : ""}`;
  }
  const diffDias = Math.round(diffMs / 86400000);
  return `En ${diffDias} día${diffDias > 1 ? "s" : ""}`;
}

export interface ContratosAgrupados {
  hoy: ContratoAgenda[];
  esta_semana: ContratoAgenda[];
  mas_adelante: ContratoAgenda[];
  sin_fecha: ContratoAgenda[];
  pasada: ContratoAgenda[];
}

export function agruparContratos(rows: ContratoAgenda[]): ContratosAgrupados {
  const grupos: ContratosAgrupados = { hoy: [], esta_semana: [], mas_adelante: [], sin_fecha: [], pasada: [] };
  for (const r of rows) {
    const fechaRef = r.fechaClase ?? r.fechaEstimada;
    grupos[grupoTemporal(fechaRef)].push(r);
  }
  return grupos;
}

export function contarActivas(rows: ContratoAgenda[]): number {
  return rows.filter((r) => grupoTemporal(r.fechaClase ?? r.fechaEstimada) !== "pasada").length;
}
