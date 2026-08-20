import { mapApunteRow } from "../apuntes/apuntes.mapper.js";
import { mapServicioRow } from "../servicios/servicios.mapper.js";
import type { HomeDataRaw } from "./home.repository.js";
import type { HomeData } from "./home.types.js";

export function mapHomeData(raw: HomeDataRaw): HomeData {
  return {
    serviciosRecomendados: raw.serviciosRecomendados.map(mapServicioRow),
    serviciosNuevos: raw.serviciosNuevos.map(mapServicioRow),
    apuntesRecomendados: raw.apuntesRecomendados.map(mapApunteRow),
    clasesPaes: raw.clasesPaes.map(mapServicioRow),
    ofertas: raw.ofertas.map(mapServicioRow),
  };
}
