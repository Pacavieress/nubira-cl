import { env } from "../../config/env.js";
import { resolverPortada } from "../../lib/media.js";
import type { ContratoAgenda, ContratoAgendaRow, MisContratosPublico } from "./misContratos.types.js";

// Reutiliza resolverPortada() (server/src/lib/media.ts, ya extraído del módulo servicios)
// en vez de reimplementar la prioridad banco > legacy > placeholder de url_portada()
// (app/helpers/imagen_servicio.php) — misma lógica, mismas columnas de entrada
// (imagen_banco_id/banco_archivo/imagen). Se toma solo la variante 'main': la página real
// (mis_contratos.php:244) usa url_portada() a secas, sin srcset responsivo.
export function mapContratoAgendaRow(row: ContratoAgendaRow): ContratoAgenda {
  return {
    id: row.id,
    estado: row.estado,
    monto: row.monto,
    fechaCreacion: row.fecha_creacion,
    fechaEstimada: row.fecha_estimada,
    fechaClase: row.fecha_clase,
    duracionMinutos: row.duracion_minutos,
    servicioTitulo: row.servicio_titulo,
    imagenUrl: resolverPortada(row.banco_archivo, row.imagen, env.assetsBaseUrl).main,
    categoria: row.categoria,
    otraPersonaNombre: row.otra_persona_nombre,
  };
}

export function mapMisContratos(compradorRows: ContratoAgendaRow[], vendedorRows: ContratoAgendaRow[]): MisContratosPublico {
  return {
    comoComprador: compradorRows.map(mapContratoAgendaRow),
    comoVendedor: vendedorRows.map(mapContratoAgendaRow),
  };
}
