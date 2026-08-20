import { env } from "../../config/env.js";
import { resolverBanner } from "../../lib/media.js";
import { mapApunteRow } from "../apuntes/apuntes.mapper.js";
import { mapServicioRow } from "../servicios/servicios.mapper.js";
import type { HomeDataRaw } from "./home.repository.js";
import type { BannerPublico, BannerRow, HomeData } from "./home.types.js";

function mapBannerRow(row: BannerRow): BannerPublico {
  return {
    id: row.id,
    titulo: row.titulo,
    imagenUrl: resolverBanner(row.imagen, env.assetsBaseUrl),
    enlace: row.enlace,
  };
}

export function mapHomeData(raw: HomeDataRaw): HomeData {
  return {
    banner: raw.banner ? mapBannerRow(raw.banner) : null,
    serviciosRecomendados: raw.serviciosRecomendados.map(mapServicioRow),
    serviciosNuevos: raw.serviciosNuevos.map(mapServicioRow),
    apuntesRecomendados: raw.apuntesRecomendados.map(mapApunteRow),
    clasesPaes: raw.clasesPaes.map(mapServicioRow),
    ofertas: raw.ofertas.map(mapServicioRow),
  };
}
