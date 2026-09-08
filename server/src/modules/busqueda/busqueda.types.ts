// Puerto de app/busqueda.php — motor de búsqueda global (clases + apuntes), distinto de
// /api/servicios y /api/apuntes (esos sirven las vitrinas, con su propio WHERE_VISIBLE
// más estricto — ver la nota en busqueda.repository.ts sobre por qué esta pieza tiene su
// propio WHERE en vez de reutilizar esos).

export type OrdenBusqueda = "" | "precio_asc" | "precio_desc" | "calificacion";
export type TabBusqueda = "todo" | "clases" | "apuntes";

export interface BusquedaFilters {
  q: string;
  orden: OrdenBusqueda;
  categoria: string;
  precioMin: number | null;
  precioMax: number | null;
  video: boolean;
  tab: TabBusqueda;
  pagina: number;
}
