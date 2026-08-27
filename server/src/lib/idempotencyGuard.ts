// Guard anti-doble-submit en memoria — pensado para mutaciones de un solo disparo (crear
// campaña, enviar campaña) que no tienen una columna de estado natural contra la cual
// poner un guard tipo `WHERE estado='pendiente'` (ver adminRetiros.repository.ts para ese
// otro patrón). Cubre el caso real: un admin hace doble click en "Enviar" antes de que el
// botón se deshabilite con la respuesta del primer request.
//
// Deliberadamente en memoria (no una tabla) — el propio server/.env.example documenta que
// esta migración evita infraestructura nueva salvo que se pida explícitamente. Vive por
// proceso: sobrevive un restart perdiendo el registro (aceptable, la ventana es de segundos)
// y no sirve si el server corre en más de una instancia (no es el caso hoy).
const huellasRecientes = new Map<string, number>();
const VENTANA_MS = 15_000;

// Limpieza perezosa: se purgan huellas vencidas cada vez que se registra una nueva, en vez
// de mantener un timer de fondo — evita otro intervalo corriendo indefinidamente por una
// guard que solo importa en una ventana de segundos.
function purgarVencidas(ahora: number): void {
  for (const [clave, expira] of huellasRecientes) {
    if (expira <= ahora) huellasRecientes.delete(clave);
  }
}

// Devuelve true si esta huella ya se vio dentro de la ventana (== posible doble-submit,
// el caller debe rechazar) y la registra si no. Atómico en la práctica: Node es
// single-threaded, no hay carrera entre el check y el set dentro de la misma llamada.
export function esDobleSubmit(clave: string): boolean {
  const ahora = Date.now();
  purgarVencidas(ahora);

  if (huellasRecientes.has(clave)) return true;

  huellasRecientes.set(clave, ahora + VENTANA_MS);
  return false;
}
