// Puerto de app/mis_servicios.php (título real de la página: "Mis Publicaciones") —
// SOLO columnas realmente usadas en el render (líneas 194-326), no `SELECT *` completo.
//
// Hallazgo real replicado a propósito (NO un bug de este puerto): mis_servicios.php:291
// lee `$a['universidad']`, pero la columna real de `apuntes` es `institucion` — no existe
// ninguna columna `universidad` (confirmado con SHOW COLUMNS). Como la fila viene de
// `SELECT *`, esa clave simplemente no existe en el array -> `?? 'General'` siempre gana.
// Efecto real, visible hoy en producción: TODOS los apuntes en esta página muestran
// "General" como institución, sin importar la real. Se replica tal cual (no se expone
// institucion en el tipo público ni se usa en el mapper) — mostrar la institución real acá
// sería una mejora silenciosa no pedida.
//
// `apuntes.publico` es NOT NULL (default 0) — el fallback `isset(...) ? ... : (estado ===
// 'aprobado')` de la línea 275 nunca se ejecuta en la práctica (isset() de una columna
// NOT NULL siempre es true), así que se usa directamente `publico === 1`.

export interface ServicioPublicadoRow {
  id: number;
  titulo: string | null;
  imagen: string | null;
  estado: string;
  modalidad: string;
  precio: string | null;
  slug: string | null;
}

export interface ServicioPublicado {
  id: number;
  titulo: string;
  imagenUrl: string;
  estado: string;
  modalidad: string;
  precio: number | null;
  url: string;
}

export interface ApuntePublicadoRow {
  id: number;
  titulo: string | null;
  archivo: string | null;
  precio: string | null;
  publico: number;
}

export interface ApuntePublicado {
  id: number;
  titulo: string;
  archivo: string | null;
  precio: number | null;
  esPublico: boolean;
}

export interface MisPublicacionesPublico {
  servicios: ServicioPublicado[];
  apuntes: ApuntePublicado[];
}
