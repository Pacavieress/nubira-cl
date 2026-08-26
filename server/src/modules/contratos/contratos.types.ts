// Puerto de app/contratar_servicio.php + app/crear_contrato.php + app/api/slots_disponibles.php
// + app/generar_slot_excepcion.php + app/pagar_slot_excepcion.php + app/finalizar_servicio.php
// + app/finalizar_servicio_tutor.php (Checkpoint 1 — "Contratación", ver inventario del
// 26/08/2026 y la nota de reemplazo en contratos.repository.ts sobre por qué NO es
// finalizar_contrato.php). Los correos de confirmación
// y push notifications del PHP real quedan diferidos a propósito — server/ no tiene ninguna
// infraestructura de envío de correo/push todavía (ninguna pieza portada hasta ahora la
// construyó, ver miBilletera.types.ts para el mismo criterio ya aplicado a "retiro
// solicitado"), confirmado con el usuario antes de construir esta pieza.
//
// Pago (iniciar_pago_servicio.php, iniciar_pago_contrato.php, notificaciones_mp.php,
// pago_exitoso_contrato.php) es el Checkpoint 2 — el checkout de acá termina con el
// contrato en 'pendiente_pago' y redirige al sitio PHP real para pagar, mismo patrón puente
// ya usado en otras piezas de esta migración mientras la siguiente pasada no está lista.

export type EstadoContrato =
  | "pendiente_pago"
  | "en_progreso"
  | "finalizado_comprador"
  | "finalizado_vendedor"
  | "liberado"
  | "cancelado";

// --- Checkout (GET contratar_servicio.php) ---

export interface ServicioCheckoutRow {
  id: number;
  titulo: string;
  alumno_id: number;
  precio: number;
  precio_oferta: number | null;
  cupos_oferta: number | null;
  is_subvencionado: number;
  modalidad: string;
  categoria: string;
  imagen: string | null;
  imagen_banco_id: number | null;
  horarios_json: string | null;
  nombre_vendedor: string;
  institucion: string | null;
  banco_archivo: string | null;
}

export interface ServicioCheckout {
  id: number;
  titulo: string;
  vendedorId: number;
  vendedorNombre: string;
  institucion: string | null;
  precioOriginal: number;
  montoInicial: number;
  esOferta: boolean;
  modalidad: string;
  categoria: string;
  imagenUrl: string;
  horarios: Record<string, string[]> | null;
}

// --- Cupón (validación de beca, puerto de contratar_servicio.php:102-151) ---

export interface CuponRow {
  id: number;
  porcentaje_descuento: number;
  usos_actuales: number;
  usos_maximos: number;
  fecha_expiracion: string | null;
  servicio_id: number | null;
}

export type ResultadoCupon =
  | { ok: true; cuponId: number; descuentoPorcentaje: number; montoFinal: number; mensaje: string }
  | { ok: false; error: string };

// --- Slots disponibles (puerto de app/api/slots_disponibles.php) ---

export interface SlotDisponible {
  datetime: string;
  hora: string;
  disponible: boolean;
  motivo: "pasado" | "ocupado" | null;
}

export type SlotsDisponiblesResultado =
  | { ok: true; fecha: string; duracion: number; slots: SlotDisponible[] }
  | { ok: false; fecha: string; motivo: "fecha_pasada" | "sin_horarios" | "dia_no_disponible" | "sin_slots_validos" | "servicio_no_encontrado" };

// --- Crear contrato (puerto de app/crear_contrato.php) ---

export interface CrearContratoInput {
  servicioId: number;
  vendedorId: number;
  fechaClase: string; // 'YYYY-MM-DD HH:mm:ss', ya validada
  notas: string;
  codigoBeca: string | null;
  precioEsperadoUsuario: number;
}

export type CrearContratoResultado =
  | { ok: true; contratoId: number; montoFinal: number }
  | { ok: false; error: string; mensaje: string };

// --- Reserva de excepción (puerto de generar_slot_excepcion.php / pagar_slot_excepcion.php) ---

export interface GenerarSlotExcepcionInput {
  conversacionId: number;
  fecha: string; // YYYY-MM-DD
  hora: string; // HH:mm
}

export type GenerarSlotExcepcionResultado = { ok: true } | { ok: false; error: string };

export interface SlotExcepcionRow {
  id: number;
  servicio_id: number;
  tutor_id: number;
  alumno_id: number;
  conversacion_id: number;
  fecha_clase: string;
  monto: number;
  expira_en: string;
  estado: string;
  contrato_id: number | null;
  servicio_titulo: string;
  duracion_minutos: number | null;
  servicio_estado: string;
  tutor_nombre?: string;
}

export interface SlotExcepcionPublico {
  servicioTitulo: string;
  tutorNombre: string;
  fechaClase: string;
  duracionMinutos: number;
  monto: number;
  expiraEn: string;
  yaExpirado: boolean;
}

export type PagarSlotExcepcionResultado =
  | { ok: true; contratoId: number }
  | { ok: false; error: string; mensaje: string; redirigirA?: string };
