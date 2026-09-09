import type { ApuntePublico } from "../apuntes/apuntes.types.js";
import type { ServicioPublico } from "../servicios/servicios.types.js";

// Réplica de $items_ordenados en app/cargar_vistos.php: a diferencia del resto de
// home.repository.ts (que nunca mezcla servicios y apuntes en una misma lista), "Sigue
// donde lo dejaste" los intercala en el orden real en que el usuario los vio.
export type VistoRecienteItem =
  | { tipo: "servicio"; servicio: ServicioPublico }
  | { tipo: "apunte"; apunte: ApuntePublico };
