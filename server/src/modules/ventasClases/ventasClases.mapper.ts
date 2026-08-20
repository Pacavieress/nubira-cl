import { env } from "../../config/env.js";
import type { VentaClase, VentaClaseRow } from "./ventasClases.types.js";

const NB_SERVICIOS_WEB = "/upload/servicios/";
const NB_PLACEHOLDER_CLASES = "/img/portadas/servicios/clases.webp";

// Puerto exacto de ventas_clases.php:182 — imagen única (basename, sin el pipeline de 3
// tamaños de resolverPortada en media.ts, porque esta página real tampoco lo usa).
function resolverImagenVenta(imagen: string | null): string {
  if (!imagen) return `${env.assetsBaseUrl}${NB_PLACEHOLDER_CLASES}`;
  const nombreArchivo = imagen.split(/[\\/]/).pop() ?? imagen;
  return `${env.assetsBaseUrl}${NB_SERVICIOS_WEB}${nombreArchivo}`;
}

// neto = bruto + subsidio - comisión — puerto exacto de ventas_clases.php:186-189. El
// desglose (subtexto "Alumno $X + Subsidio $Y − Comisión $Z") queda como responsabilidad
// del cliente (web/), igual que el resto de la presentación de montos en esta migración —
// server/ expone los 3 números crudos, no un string ya armado.
export function mapVentaClaseRow(row: VentaClaseRow): VentaClase {
  const bruto = row.monto;
  const subsidio = row.monto_subsidio ?? 0;
  const comision = row.monto_comision ?? 0;

  return {
    idContrato: row.id_contrato,
    titulo: row.titulo,
    imagenUrl: resolverImagenVenta(row.imagen),
    compradorNombre: row.comprador_nombre,
    compradorEmail: row.comprador_email,
    bruto,
    subsidio,
    comision,
    neto: bruto + subsidio - comision,
    fechaCreacion: row.fecha_creacion,
    fechaPago: row.fecha_pago,
    estado: row.estado,
    yaCalificado: row.calificacion_vendedor > 0,
  };
}
