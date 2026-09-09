import { mapApunteRow } from "../apuntes/apuntes.mapper.js";
import { mapServicioRow } from "../servicios/servicios.mapper.js";
import type { ItemOrdenado } from "./vistosRecientes.repository.js";
import type { VistoRecienteItem } from "./vistosRecientes.types.js";

export function mapVistosRecientes(items: ItemOrdenado[]): VistoRecienteItem[] {
  return items.map((item) =>
    item.tipo === "servicio"
      ? { tipo: "servicio" as const, servicio: mapServicioRow(item.fila) }
      : { tipo: "apunte" as const, apunte: mapApunteRow(item.fila) },
  );
}
