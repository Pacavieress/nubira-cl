import type { CategoriaTicket, MensajeHilo, MensajeHiloRow, Ticket, TicketMaestroRow } from "./soporte.types.js";
import { CATEGORIAS_VALIDAS } from "./soporte.types.js";

function esCategoriaValida(valor: string): valor is CategoriaTicket {
  return (CATEGORIAS_VALIDAS as readonly string[]).includes(valor);
}

// Puerto exacto de reclamos_sugerencias.php:421-424 — la tabla real no tiene columna
// `asunto` separada, se extrae del prefijo "ASUNTO:\n" guardado en `texto`
// (ver crearTicket() en soporte.repository.ts, mismo formato de escritura).
function extraerAsunto(mensaje: string): string {
  const idx = mensaje.indexOf(":\n");
  if (idx === -1) return "(Sin asunto)";
  const prefijo = mensaje.slice(0, idx).toLowerCase();
  return prefijo.charAt(0).toUpperCase() + prefijo.slice(1);
}

// Puerto exacto de reclamos_sugerencias.php:241-257 — arma el hilo completo de un ticket:
// 1) el mensaje inicial del usuario (con el prefijo "ASUNTO:\n" recortado, línea 474-476,
//    solo si sigue siendo el primer mensaje tras ordenar); 2) `respuesta_admin` (columna
//    legacy) si existe y NO está duplicada en reclamos_mensajes (evita mostrar la misma
//    respuesta 2 veces cuando ambos sistemas coexisten); 3) todos los reclamos_mensajes
//    reales. Todo ordenado por fecha ascendente al final, igual que el PHP real.
export function buildTicket(row: TicketMaestroRow, mensajesTicket: MensajeHiloRow[]): Ticket {
  const idxAsunto = row.mensaje.indexOf(":\n");
  const primerMensajeTexto = idxAsunto === -1 ? row.mensaje : row.mensaje.slice(idxAsunto + 2);

  const hilo: MensajeHilo[] = [{ remitente: "usuario", mensaje: primerMensajeTexto, fecha: row.fecha_creacion }];

  if (row.respuesta) {
    const esDuplicada = mensajesTicket.some((mt) => mt.remitente === "admin" && mt.mensaje.trim() === row.respuesta!.trim());
    if (!esDuplicada) hilo.push({ remitente: "admin", mensaje: row.respuesta, fecha: row.fecha_creacion });
  }

  for (const mt of mensajesTicket) hilo.push({ remitente: mt.remitente, mensaje: mt.mensaje, fecha: mt.fecha });

  hilo.sort((a, b) => a.fecha.getTime() - b.fecha.getTime());

  const ultimo = hilo[hilo.length - 1]!;
  const tieneRespuestaNueva = ultimo.remitente === "admin" && row.revisado_usuario === 0;

  return {
    id: row.id,
    fechaCreacion: row.fecha_creacion,
    categoria: esCategoriaValida(row.categoria) ? row.categoria : "otro",
    estado: row.estado,
    revisadoUsuario: row.revisado_usuario === 1,
    asunto: extraerAsunto(row.mensaje),
    hilo,
    tieneRespuestaNueva,
  };
}
