// Puerto de admin_chats_aula.php ("Monitor Aulas") — 100% lectura, sin ninguna acción de
// escritura en el PHP real (a diferencia de admin_chats.php / "Monitor Chats", que sí tiene
// un sistema DLP completo con múltiples acciones de moderación — admin_chats.php:215-338 —
// y queda deliberadamente fuera de esta pieza, candidata a su propia sesión dedicada).
export interface AulaListado {
  id: number;
  estado: string;
  fechaReferencia: string | null;
  enVivo: boolean;
  cerrado: boolean;
  compradorNombre: string;
  compradorFotoUrl: string;
  vendedorNombre: string;
  vendedorFotoUrl: string;
  ultimoMensaje: string | null;
}

export interface AulaMensaje {
  remitenteId: number;
  mensaje: string;
  enviadoEn: string;
  origen: "previo" | "aula";
}

export interface AulaDetalle {
  compradorId: number;
  compradorNombre: string;
  vendedorNombre: string;
  estado: string;
  mensajes: AulaMensaje[];
}
