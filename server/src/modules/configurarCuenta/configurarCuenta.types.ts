// Puerto de app/editar_datos.php — SOLO el formulario "Información Básica" (líneas
// 82-116, 243-316). Cambiar contraseña y eliminar cuenta NO se portan en esta pieza —
// decisión explícita del usuario tras encontrar que esas 2 acciones tocan credenciales
// (requiere replicar bcrypt de PHP en Node) y borrado de cuenta (irreversible desde la
// perspectiva del usuario). Esos 2 formularios siguen enlazando a la página PHP real.

export interface PerfilCuentaRow {
  nombre: string;
  correo: string;
  carrera: string | null;
  tipo: string | null;
  bio: string | null;
  universidad: string | null;
  anio_egreso: number | null;
  anios_experiencia: number | null;
}

export interface PerfilCuenta {
  nombre: string;
  correo: string;
  carrera: string | null;
  tipo: string | null;
  bio: string | null;
  universidad: string | null;
  anioEgreso: number | null;
  aniosExperiencia: number | null;
}

export type TipoCuenta = "estudiante" | "egresado" | "profesor" | "particular";

export interface ActualizarPerfilInput {
  nombre: string;
  carrera: string;
  tipo: TipoCuenta | "" | null;
  bio: string | null;
  universidad: string | null;
  anioEgreso: number | null;
  aniosExperiencia: number | null;
}
