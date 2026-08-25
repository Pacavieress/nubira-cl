// Puerto de admin_ofertas.php ("Centro de Subsidios") — subsidia el precio de un servicio
// (precio_oferta/cupos_oferta/is_subvencionado/oferta_termino en `servicios`). Igual que
// adminOfertasApuntes (Promo Apuntes), se portan las 2 acciones de escritura completas: son
// UPDATE puros, sin correo, sin push, sin DELETE — mismo nivel de riesgo ya aceptado.
export type OrdenOfertas = "recientes" | "descuento" | "vencer" | "cupos" | "activas" | "precio_mayor" | "precio_menor";

export interface ServicioConOferta {
  id: number;
  titulo: string;
  categoria: string | null;
  tutorNombre: string;
  precio: number;
  precioOferta: number | null;
  cuposOferta: number;
  isSubvencionado: boolean;
  ofertaTermino: string | null;
}

export interface AplicarOfertaInput {
  tipo: "porcentaje" | "precio";
  pctOferta: number | null;
  precioOferta: number | null;
  cupos: number;
  ofertaTermino: string | null;
}
