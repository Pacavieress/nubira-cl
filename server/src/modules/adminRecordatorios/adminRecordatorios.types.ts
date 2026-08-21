// Puerto de admin_recordatorios.php — monitor de solo lectura de la tabla
// acciones_pendientes (correos automáticos de reenganche: recordatorio_3dias/7dias/14dias).
// SIN el botón "Ejecutar ahora" del PHP real (dispara ejecutar_recordatorios.php, un
// trigger manual del cron de envío masivo — fuera de alcance, esta pieza es de monitoreo,
// no de operar el cron).
export interface RecordatorioItem {
  id: number;
  alumno: string | null;
  correo: string | null;
  tipo: string;
  etapa: number;
  programadoPara: string;
  enviadoEn: string | null;
  estado: string;
  motivoOmision: string | null;
}

export interface RecordatoriosFiltros {
  fecha?: string;
  tipo?: string;
  estado?: string;
}

export interface RecordatoriosResumen {
  enviadosHoy: number;
  pendientesHoy: number;
  registros: RecordatorioItem[];
}
