// Puerto de admin_usuarios.php ("Gestión de Usuarios") — deliberadamente 100% lectura, igual
// que el patrón ya usado en Contratos/Log Fail: las 6 mutaciones del PHP real (toggle_ban,
// cambiar_rol, editar_usuario, eliminar_usuario con soft-delete en cascada,
// suspender_usuario, levantar_suspension) actúan sobre cuentas reales de forma consecuente
// (algunas difíciles de revertir sin intervención manual) y quedan fuera de este puerto — se
// tratan en una sesión aparte, con aprobación explícita acción por acción. "Reenviar
// confirmación" además envía un correo real (mismo criterio de exclusión que aprobar/rechazar
// en Videos). Las auto-migraciones de columnas (admin_usuarios.php:16-77) tampoco se replican:
// son ALTER TABLE idempotentes que ya corrieron hace meses — confirmado con SHOW COLUMNS que
// suspendido_hasta/motivo_suspension/visto_admin/ultimo_reenvio y auditoria_admin ya existen.
export type FiltroRol = "" | "admin" | "alumno";
export type FiltroVerificado = "" | "si" | "no";

export interface UsuarioListado {
  id: number;
  nombre: string | null;
  correo: string | null;
  fotoPerfil: string | null;
  fechaRegistro: string | null;
  bloqueado: boolean;
  confirmado: boolean;
  suspendidoHasta: string | null;
  ultimoReenvio: string | null;
  rol: string;
  totalServicios: number;
  totalApuntes: number;
  totalReclamos: number;
}

export interface UsuariosResumen {
  page: number;
  totalPages: number;
  totalUsers: number;
  totalUsersGlobal: number;
  usuarios: UsuarioListado[];
}
