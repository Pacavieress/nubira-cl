// Puerto de cupones.php ("Bóveda de Becas") + admin_procesar_cupon.php (INSERT/DELETE sobre
// `cupones`, 5 filas reales en local). Sin efecto externo (no envía correo, no mueve dinero
// real — solo define un % de descuento que se aplicará en una compra futura), mismo nivel de
// riesgo que adminDominios/adminConfigPrecios ya portados: se porta completo (listar, crear,
// eliminar). `codigo` es UNIQUE en la tabla — se pre-chequea antes del INSERT, mismo patrón
// que adminDominios.existeDominio, en vez de dejar reventar el constraint como hace el PHP
// real (que atrapa mysqli_sql_exception código 1062).
export interface CuponBeca {
  id: number;
  codigo: string;
  porcentajeDescuento: number;
  usosActuales: number;
  usosMaximos: number;
  servicioId: number | null;
  servicioTitulo: string | null;
  fechaExpiracion: string | null;
}

export interface ServicioParaCupon {
  id: number;
  titulo: string;
  precio: number;
}

export interface CuponesResumen {
  cupones: CuponBeca[];
  servicios: ServicioParaCupon[];
}

export interface NuevoCuponInput {
  codigo: string;
  porcentajeDescuento: number;
  usosMaximos: number;
  servicioId: number | null;
  fechaExpiracion: string | null;
}
