// Puerto de app/mis_contratos.php:32-65 — 2 queries reales (compras: soy comprador,
// ventas: soy vendedor), mismo JOIN a reservas_slots/banco_imagenes. Sin las funciones de
// agrupación temporal (get_grupo_temporal, formatear_fecha_clase, tiempo_hasta_clase) acá
// — esas quedan del lado de web/ (Server Component, corre en el mismo Node en request-time,
// mismo criterio que otras páginas de esta migración: server/ expone datos crudos, la
// presentación vive en web/).

export interface ContratoAgendaRow {
  id: number;
  estado: string;
  monto: number;
  fecha_creacion: Date;
  fecha_estimada: Date | null;
  fecha_clase: Date | null;
  duracion_minutos: number | null;
  servicio_titulo: string;
  imagen: string | null;
  imagen_banco_id: number | null;
  categoria: string;
  banco_archivo: string | null;
  otra_persona_nombre: string;
}

export interface ContratoAgenda {
  id: number;
  estado: string;
  monto: number;
  fechaCreacion: Date;
  fechaEstimada: Date | null;
  fechaClase: Date | null;
  duracionMinutos: number | null;
  servicioTitulo: string;
  imagenUrl: string;
  categoria: string;
  otraPersonaNombre: string;
}

export interface MisContratosPublico {
  comoComprador: ContratoAgenda[];
  comoVendedor: ContratoAgenda[];
}
