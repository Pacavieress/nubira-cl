// Puerto de app/publicar_servicio.php + app/formulario_subir_apunte.php (solo la porción
// de creación — SIN generación por IA, SIN pago de republicación/créditos IA, SIN video de
// presentación de servicio). Ver publicar.controller.ts para el detalle de cada exclusión.

export interface AlumnoParaPublicarRow {
  nombre: string | null;
  correo: string | null;
  institucion: string | null;
  universidad: string | null;
  servicios_publicados_total: number;
}

export interface CrearServicioInput {
  titulo: string;
  descripcion: string;
  categoria: string;
  modalidad: string;
  ubicacion: string;
  precio: number;
  esPaes: boolean;
}

export type CrearServicioResultado =
  | { ok: true; servicioId: number }
  | { ok: false; error: string };

export interface GuardarHorarioInput {
  servicioId: number;
  horariosJson: string;
}

export type GuardarHorarioResultado = { ok: true } | { ok: false; error: string };

// Solo los campos que realmente llegan a la fila de `apuntes` — el archivo/preview los
// maneja publicar.controller.ts directo contra el filesystem (fs/sharp), no pasan por acá.
export interface CrearApunteInput {
  titulo: string;
  descripcion: string;
  semestre: number;
  anio: number;
  precio: number;
  asignatura: string;
  materia: string | null;
  nivelAcademico: "universitario" | "paes" | "escolar";
  subtema: string | null;
}

export type CrearApunteResultado =
  | { ok: true; apunteId: number }
  | { ok: false; error: string };
