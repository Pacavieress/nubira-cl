import { fetchConSesion } from "./sesion";

const API_URL = process.env.API_URL ?? "http://localhost:4000";

export type Tier = "leyenda" | "elite" | "pro" | "top" | null;

// Refleja ServicioPublico (server/src/modules/servicios/servicios.types.ts). Sigue sin
// ser un import compartido a propósito (web/ y server/ son proyectos deliberadamente
// separados) — si esto empieza a divergir de verdad, se evalúa compartir tipos, no antes.
export interface ServicioListado {
  id: number;
  slug: string | null;
  titulo: string;
  categoria: string;
  modalidad: string;
  precio: number | null;
  precioOferta: number | null;
  cuposOferta: number;
  portada: { thumb: string; card: string; main: string };
  tutor: {
    id: number;
    nombre: string | null;
    fotoUrl: string | null;
    institucion: string | null;
  };
  rating: { promedio: number | null; votos: number };
  esPaes: boolean;
  videoEstado: string;
  tier: Tier;
  ofertaVigente: boolean;
}

interface ServiciosResponse {
  data: ServicioListado[];
  meta: { page: number; limit: number; hayMas: boolean };
}

export interface ServiciosFiltros {
  categoria?: string;
  modalidad?: string;
  q?: string;
}

export async function getServicios(filtros: ServiciosFiltros = {}): Promise<ServiciosResponse> {
  const params = new URLSearchParams();
  if (filtros.categoria) params.set("categoria", filtros.categoria);
  if (filtros.modalidad) params.set("modalidad", filtros.modalidad);
  if (filtros.q) params.set("q", filtros.q);

  const qs = params.toString();
  const res = await fetch(`${API_URL}/api/servicios${qs ? `?${qs}` : ""}`, { cache: "no-store" });
  if (!res.ok) {
    throw new Error(`La API de servicios respondió ${res.status}`);
  }
  return res.json();
}

export async function getCategorias(): Promise<string[]> {
  const res = await fetch(`${API_URL}/api/categorias`, { cache: "no-store" });
  if (!res.ok) {
    throw new Error(`La API de categorías respondió ${res.status}`);
  }
  const body = (await res.json()) as { data: string[] };
  return body.data;
}

export type TonoRespuesta = "verde" | "azul" | "naranjo" | "gris";

export interface ValoracionPublica {
  id: number;
  calificacion: number;
  comentario: string | null;
  fecha: string;
  evaluador: { nombre: string | null; fotoUrl: string };
}

// Refleja ServicioDetallePublico (server/.../servicios.types.ts). Igual que
// ServicioListado, no es un import compartido a propósito.
export interface ServicioDetalle extends Omit<ServicioListado, "tutor"> {
  tutor: ServicioListado["tutor"] & { verificado: boolean };
  descripcion: string;
  ubicacion: string | null;
  duracionMinutos: number;
  horarios: Record<string, string[]> | null;
  nivel: string;
  materia: string | null;
  area: string | null;
  asignatura: string | null;
  // esFavorito agregado para el toggle de favoritos (Fase 7 de la migración) — ver
  // FavoritoToggle.tsx.
  viewer: { isAuthenticated: boolean; isOwner: boolean; esFavorito: boolean };
  valoraciones: ValoracionPublica[];
  tiempoRespuesta: { texto: string; tono: TonoRespuesta };
}

// BUG real pre-existente corregido acá (Fase 7, al construir favoritos): esta función
// nunca reenviaba la cookie de sesión, así que `viewer.isAuthenticated/isOwner` llegaban
// SIEMPRE en false sin importar si el visitante real tenía sesión — invisible hasta ahora
// porque nada en la página consumía `viewer` todavía (confirmado con grep). Mismo patrón
// que getGuiaArticulo: fetchConSesion primero (null sin cookie, sin pagar el roundtrip
// extra para el caso común de visitante anónimo), fallback a fetch público sin sesión.
export async function getServicioDetalle(id: number): Promise<ServicioDetalle | null> {
  const resConSesion = await fetchConSesion(`/api/servicios/${id}`);
  const res = resConSesion ?? (await fetch(`${API_URL}/api/servicios/${id}`, { cache: "no-store" }));
  if (res.status === 404) return null;
  if (!res.ok) {
    throw new Error(`La API de servicios respondió ${res.status}`);
  }
  return res.json();
}

// Refleja ApuntePublico (server/src/modules/apuntes/apuntes.types.ts). Mismo criterio que
// ServicioListado: no es un import compartido a propósito.
export interface ApunteListado {
  id: number;
  titulo: string;
  precio: number;
  descripcionCorta: string | null;
  portadaUrl: string;
  institucion: string | null;
  ventasTotales: number;
  esNuevo: boolean;
  promo: { activa: boolean; restantes: number } | null;
  url: string;
}

interface ApuntesResponse {
  data: ApunteListado[];
  meta: { page: number; limit: number; hayMas: boolean };
}

export interface ApuntesFiltros {
  nivel?: string;
  precio?: "gratis" | "pagado";
  orden?: string;
  q?: string;
  // Agregado para las recomendaciones de /desafio — ver SearchApuntesFilters.materia en
  // server/src/modules/apuntes/apuntes.types.ts.
  materia?: string;
}

export async function getApuntes(filtros: ApuntesFiltros = {}): Promise<ApuntesResponse> {
  const params = new URLSearchParams();
  if (filtros.nivel) params.set("nivel", filtros.nivel);
  if (filtros.precio) params.set("precio", filtros.precio);
  if (filtros.orden) params.set("orden", filtros.orden);
  if (filtros.q) params.set("q", filtros.q);
  if (filtros.materia) params.set("materia", filtros.materia);

  const qs = params.toString();
  const res = await fetch(`${API_URL}/api/apuntes${qs ? `?${qs}` : ""}`, { cache: "no-store" });
  if (!res.ok) {
    throw new Error(`La API de apuntes respondió ${res.status}`);
  }
  return res.json();
}

// Refleja ApunteDetallePublico (server/src/modules/apuntes/apuntes.types.ts). Mismo
// criterio que ServicioDetalle: no es un import compartido a propósito.
export interface ApunteDetalle extends Omit<ApunteListado, "descripcionCorta"> {
  descripcion: string | null;
  asignatura: string | null;
  materia: string | null;
  nivelAcademico: string | null;
  categoria: string | null;
  iaTags: string[];
  publicador: {
    nombre: string | null;
    fotoUrl: string;
    institucion: string | null;
    verificado: boolean;
  };
  viewer: { isAuthenticated: boolean; isOwner: boolean };
}

// Mismo bug real que tenía getServicioDetalle (corregido en la Fase 7, favoritos): esta
// función nunca reenviaba la cookie de sesión, así que `viewer.isAuthenticated/isOwner`
// llegaban SIEMPRE en false sin importar si el visitante real tenía sesión — encontrado por
// analogía al auditar los otros 2 endpoints con optionalAuth (apuntes/:id, guias/*) tras
// detectar el mismo patrón en servicios/:id. Sigue invisible hoy (nada en
// apunte/[id]/page.tsx consume `viewer` todavía, confirmado con grep), pero es la misma
// trampa latente — se corrige ahora en vez de esperar a que otra feature la exponga.
export async function getApunteDetalle(id: number): Promise<ApunteDetalle | null> {
  const resConSesion = await fetchConSesion(`/api/apuntes/${id}`);
  const res = resConSesion ?? (await fetch(`${API_URL}/api/apuntes/${id}`, { cache: "no-store" }));
  if (res.status === 404) return null;
  if (!res.ok) {
    throw new Error(`La API de apuntes respondió ${res.status}`);
  }
  return res.json();
}

// Refleja TutorPublico (server/src/modules/tutores/tutores.types.ts). Mismo criterio que
// los otros tipos de detalle: no es un import compartido a propósito.
export interface TutorPerfil {
  id: number;
  nombre: string | null;
  bio: string | null;
  fotoUrl: string;
  institucion: string | null;
  verificado: boolean;
  subtitulo: string;
  statsAcademicas: { universidad: string | null; anioEgreso: number | null; aniosExperiencia: number | null };
  tiempoRespuesta: { texto: string; tono: TonoRespuesta } | null;
  rating: { promedio: number | null; votos: number };
  resenasComoTutor: ValoracionPublica[];
  resenasComoAlumno: ValoracionPublica[];
  servicios: ServicioListado[];
  apuntes: ApunteListado[];
}

export async function getTutorPerfil(id: number): Promise<TutorPerfil | null> {
  const res = await fetch(`${API_URL}/api/tutores/${id}`, { cache: "no-store" });
  if (res.status === 404) return null;
  if (!res.ok) {
    throw new Error(`La API de tutores respondió ${res.status}`);
  }
  return res.json();
}

// Refleja PerfilPropio (server/src/modules/perfil/perfil.types.ts) — la vista de "mi
// propio perfil" (perfil.php con $es_propio=true), a diferencia de TutorPerfil arriba
// (cómo un visitante ve el perfil de OTRO). Alcance confirmado con el usuario: header +
// banner de completitud + bio editable + gamificación + lista simple de accesos (NO el
// grid visual de 34 tiles de panel_gestion.php, eso es una pieza aparte).
export interface CompletitudPerfil {
  faltaFoto: boolean;
  faltaBio: boolean;
  faltaBanco: boolean;
  faltaHorarios: boolean;
  servicioFaltaHorariosId: number | null;
  faltaVideo: boolean;
  servicioFaltaVideoId: number | null;
}

export interface MisionesGamificacion {
  foto: boolean;
  bioLarga: boolean;
  descripcionLarga: boolean;
  apuntePublico: boolean;
  tresResenas: boolean;
  video: boolean;
}

export interface GamificacionPerfil {
  maxScore: number;
  tier: "basico" | "top" | "pro" | "leyenda";
  progresoPorcentaje: number;
  misiones: MisionesGamificacion;
}

export interface AccesoPanel {
  titulo: string;
  href: string;
}

export interface PerfilPropio extends TutorPerfil {
  vistasPerfil: number;
  esCreador: boolean;
  completitud: CompletitudPerfil;
  gamificacion: GamificacionPerfil;
  accesos: AccesoPanel[];
}

export async function getMiPerfil(): Promise<PerfilPropio | null> {
  const res = await fetchConSesion("/api/me/perfil");
  if (!res || !res.ok) return null;
  return res.json();
}

// Refleja HomeData (server/src/modules/home/home.types.ts). Mismo criterio que los
// demás tipos de esta página: no es un import compartido a propósito. Sin banner:
// vitrina.php lo consulta (líneas 678-701) pero nunca lo renderiza en ningún lugar del
// archivo — confirmado con grep, es código muerto, no una sección condicional.
export interface HomeData {
  serviciosRecomendados: ServicioListado[];
  serviciosNuevos: ServicioListado[];
  apuntesRecomendados: ApunteListado[];
  clasesPaes: ServicioListado[];
  ofertas: ServicioListado[];
}

export async function getHome(): Promise<HomeData> {
  const res = await fetch(`${API_URL}/api/home`, { cache: "no-store" });
  if (!res.ok) {
    throw new Error(`La API de home respondió ${res.status}`);
  }
  return res.json();
}

// Refleja LandingClases (server/src/modules/landings/landings.types.ts). Mismo criterio
// que los demás tipos de esta página: no es un import compartido a propósito.
export interface LandingClases {
  categoria: string;
  seo: { titulo: string; descripcion: string; h1: string; intro: string | null; noindex: boolean };
  total: number;
  servicios: ServicioListado[];
  faqs: { pregunta: string; respuesta: string }[];
}

export async function getLandingClases(slug: string): Promise<LandingClases | null> {
  const res = await fetch(`${API_URL}/api/landings/clases/${slug}`, { cache: "no-store" });
  if (res.status === 404) return null;
  if (!res.ok) {
    throw new Error(`La API de landings respondió ${res.status}`);
  }
  return res.json();
}

// Refleja ApunteComprado/ServicioContratado/MisComprasPublico
// (server/src/modules/compras/compras.types.ts). Mismo criterio que los demás tipos de
// esta página: no es un import compartido a propósito.
export interface ApunteComprado {
  id: number;
  titulo: string;
  asignatura: string | null;
  institucion: string | null;
  archivo: string | null;
  monto: number;
  fecha: string;
  estadoPago: string;
}

export interface ServicioContratado {
  id: number;
  titulo: string;
  vendedorNombre: string;
  monto: number;
  fechaPago: string | null;
  estado: string;
}

export interface MisCompras {
  apuntes: ApunteComprado[];
  servicios: ServicioContratado[];
}

// A diferencia de todo lo demás en este archivo, es un endpoint autenticado
// (GET /api/me/compras, requireAuth en server/) — usa fetchConSesion (web/src/lib/sesion.ts)
// para reenviar la cookie PHPSESSID en vez de un fetch() público. null cubre 2 casos que
// el caller no necesita distinguir acá: sin sesión, o server/ respondió 401 — ambos
// significan "no hay compras que mostrar para este visitante", igual que getSesion().
export async function getMisCompras(): Promise<MisCompras | null> {
  const res = await fetchConSesion("/api/me/compras");
  if (!res || !res.ok) return null;
  return res.json();
}

// Refleja EvaluacionRecibida/MisEvaluacionesPublico
// (server/src/modules/evaluaciones/evaluaciones.types.ts) — ver ese archivo para el
// hallazgo real detrás de por qué nombreEvaluador nunca trae apellido y no hay foto de
// evaluador (bug de columna inexistente en el PHP real, replicado fielmente, no una
// limitación de este puerto).
export interface EvaluacionRecibida {
  id: number;
  nombreEvaluador: string;
  calificacion: number;
  comentario: string | null;
  fecha: string;
  servicioTitulo: string | null;
}

export interface MisEvaluaciones {
  resenasComoTutor: EvaluacionRecibida[];
  resenasComoAlumno: EvaluacionRecibida[];
}

export async function getMisEvaluaciones(): Promise<MisEvaluaciones | null> {
  const res = await fetchConSesion("/api/me/evaluaciones");
  if (!res || !res.ok) return null;
  return res.json();
}

// Refleja VentaClase (server/src/modules/ventasClases/ventasClases.types.ts). Sin acción
// de "Ocultar" (delete real de contratos, ver ese archivo) — decisión explícita de portar
// solo lectura por ahora.
export interface VentaClase {
  idContrato: number;
  titulo: string;
  imagenUrl: string;
  compradorNombre: string;
  compradorEmail: string;
  bruto: number;
  subsidio: number;
  comision: number;
  neto: number;
  fechaCreacion: string;
  fechaPago: string | null;
  estado: string;
  yaCalificado: boolean;
}

export async function getMisVentasClases(): Promise<VentaClase[] | null> {
  const res = await fetchConSesion("/api/me/ventas-clases");
  if (!res || !res.ok) return null;
  const body = (await res.json()) as { data: VentaClase[] };
  return body.data;
}

// Refleja VentaApunte (server/src/modules/ventasApuntes/ventasApuntes.types.ts). Mismo
// criterio que VentaClase: sin acción de "Ocultar".
export interface VentaApunte {
  id: number;
  apunteId: number;
  titulo: string;
  archivo: string | null;
  compradorNombre: string;
  precio: number;
  fecha: string;
  pagadoAlVendedor: boolean;
}

export async function getMisVentasApuntes(): Promise<VentaApunte[] | null> {
  const res = await fetchConSesion("/api/me/ventas-apuntes");
  if (!res || !res.ok) return null;
  const body = (await res.json()) as { data: VentaApunte[] };
  return body.data;
}

// Refleja ServicioPublicado/ApuntePublicado/MisPublicacionesPublico
// (server/src/modules/misPublicaciones/misPublicaciones.types.ts). Ver ese archivo para
// el hallazgo real sobre por qué la institución de un apunte nunca se expone acá (bug de
// columna inexistente en el PHP real, replicado a propósito).
export interface ServicioPublicado {
  id: number;
  titulo: string;
  imagenUrl: string;
  estado: string;
  modalidad: string;
  precio: number | null;
  url: string;
}

export interface ApuntePublicado {
  id: number;
  titulo: string;
  archivo: string | null;
  precio: number | null;
  esPublico: boolean;
}

export interface MisPublicaciones {
  servicios: ServicioPublicado[];
  apuntes: ApuntePublicado[];
}

export async function getMisPublicaciones(): Promise<MisPublicaciones | null> {
  const res = await fetchConSesion("/api/me/mis-publicaciones");
  if (!res || !res.ok) return null;
  return res.json();
}

// Refleja ContratoAgenda/MisContratosPublico (server/src/modules/misContratos/misContratos.types.ts).
export interface ContratoAgenda {
  id: number;
  estado: string;
  monto: number;
  fechaCreacion: string;
  fechaEstimada: string | null;
  fechaClase: string | null;
  duracionMinutos: number | null;
  servicioTitulo: string;
  imagenUrl: string;
  categoria: string;
  otraPersonaNombre: string;
}

export interface MisContratos {
  comoComprador: ContratoAgenda[];
  comoVendedor: ContratoAgenda[];
}

export async function getMisContratos(): Promise<MisContratos | null> {
  const res = await fetchConSesion("/api/me/mis-contratos");
  if (!res || !res.ok) return null;
  return res.json();
}

// Refleja MiBilleteraPublico (server/src/modules/miBilletera/miBilletera.types.ts). El
// número de cuenta completo nunca llega hasta acá — server/ ya lo enmascara antes de
// responder (ver ese archivo).
export interface SolicitudRetiro {
  monto: number;
  fechaSolicitud: string;
  estado: string;
}

export interface MiBilletera {
  saldoDisponible: number;
  saldoParaMostrar: number;
  minimoRetiro: number;
  comisionActual: number;
  gananciasApuntes: number;
  gananciasServicios: number;
  totalRetirado: number;
  datosBancarios: { banco: string; numeroCuentaEnmascarado: string } | null;
  historialRetiros: SolicitudRetiro[];
}

export async function getMiBilletera(): Promise<MiBilletera | null> {
  const res = await fetchConSesion("/api/me/mi-billetera");
  if (!res || !res.ok) return null;
  return res.json();
}

// Refleja PerfilCuenta (server/src/modules/configurarCuenta/configurarCuenta.types.ts).
// Cambiar contraseña y eliminar cuenta NO están acá — ver ese archivo para el porqué
// (siguen enlazando a la página PHP real).
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

export async function getMiPerfilCuenta(): Promise<PerfilCuenta | null> {
  const res = await fetchConSesion("/api/me/configurar-cuenta");
  if (!res || !res.ok) return null;
  return res.json();
}

// Refleja PublicacionMetrica (server/src/modules/metricas/metricas.types.ts). Sin la
// página de detalle por publicación (/metricas/:tipo/:id) — ver ese archivo.
export interface PublicacionMetrica {
  id: number;
  tipo: "servicio" | "apunte";
  titulo: string;
  precio: number | null;
  imagenUrl: string;
  fechaOrden: string;
  visitas30d: number;
  tendencia: "up" | "down" | null;
}

export async function getMisMetricas(): Promise<PublicacionMetrica[] | null> {
  const res = await fetchConSesion("/api/me/metricas");
  if (!res || !res.ok) return null;
  const body = (await res.json()) as { data: PublicacionMetrica[] };
  return body.data;
}

// Refleja CategoriaHub/GuiasHubGeneral (server/src/modules/guias/guias.types.ts).
export interface CategoriaHub {
  id: number;
  nombre: string;
  slug: string;
  descripcionCorta: string | null;
  totalArticulos: number;
}

export async function getGuiasHubGeneral(): Promise<CategoriaHub[]> {
  const res = await fetch(`${API_URL}/api/guias`, { cache: "no-store" });
  if (!res.ok) throw new Error(`La API de guías respondió ${res.status}`);
  const body = (await res.json()) as { categorias: CategoriaHub[] };
  return body.categorias;
}

// Refleja ArticuloListado/GuiasHubCategoria (server/src/modules/guias/guias.types.ts).
export interface ArticuloListado {
  id: number;
  titulo: string;
  slug: string;
  resumen: string | null;
  portadaCardUrl: string | null;
  fechaPublicacion: string | null;
}

export type GuiasHubCategoria =
  | {
      encontrada: true;
      categoria: { nombre: string; slug: string; descripcionCorta: string | null; soloTutores: boolean };
      articulos: ArticuloListado[];
      noindex: boolean;
    }
  | { encontrada: false; razon: "not_found" | "sin_sesion" | "no_tutor" };

// A diferencia del resto de fetchers públicos de este archivo, éste SÍ necesita
// fetchConSesion (no un fetch() plano): el gate de "Para Tutores" depende de si hay
// sesión y si esa sesión es de un tutor activo — server/ solo puede evaluar eso si recibe
// la cookie PHPSESSID reenviada (ver guias.controller.ts::getHubCategoria, optionalAuth).
export async function getGuiasHubCategoria(slug: string): Promise<GuiasHubCategoria> {
  const resConSesion = await fetchConSesion(`/api/guias/${slug}`);
  const res = resConSesion ?? (await fetch(`${API_URL}/api/guias/${slug}`, { cache: "no-store" }));

  if (res.status === 404) return { encontrada: false, razon: "not_found" };
  if (res.status === 401) return { encontrada: false, razon: "sin_sesion" };
  if (res.status === 403) return { encontrada: false, razon: "no_tutor" };
  if (!res.ok) throw new Error(`La API de guías respondió ${res.status}`);

  const body = (await res.json()) as Omit<Extract<GuiasHubCategoria, { encontrada: true }>, "encontrada">;
  return { ...body, encontrada: true };
}

// Refleja GuiaArticuloDetalle (server/src/modules/guias/guias.types.ts).
export interface TutorRelacionado {
  id: number;
  url: string;
  titulo: string;
  nombreTutor: string;
  fotoUrl: string | null;
  institucion: string;
}

export interface ApunteRelacionado {
  id: number;
  titulo: string;
}

export interface ArticuloRelacionado {
  slug: string;
  titulo: string;
  portadaThumbUrl: string | null;
}

export type GuiaArticuloDetalle =
  | {
      encontrada: true;
      categoria: { nombre: string; slug: string; soloTutores: boolean };
      articulo: {
        titulo: string;
        resumen: string | null;
        cuerpoHtml: string;
        autorNombre: string;
        fechaPublicacion: string | null;
        portadaMainUrl: string | null;
        metaDescription: string | null;
      };
      faqs: { pregunta: string; respuesta: string }[];
      tutoresRelacionados: TutorRelacionado[];
      apuntesRelacionados: ApunteRelacionado[];
      linkVerClases: string | null;
      linkVerApuntes: string | null;
      articulosRelacionados: ArticuloRelacionado[];
      mostrarBreadcrumb: boolean;
    }
  | { encontrada: false; razon: "not_found" | "sin_sesion" | "no_tutor" };

// Mismo criterio que getGuiasHubCategoria: fetchConSesion primero (el gate de "Para
// Tutores" depende de la sesión), fallback a fetch() plano solo si no hay cookie.
// Refleja Ticket/MisTicketsPublico (server/src/modules/soporte/soporte.types.ts).
export type CategoriaTicket = "tecnico" | "chat" | "pago" | "apunte" | "cuenta" | "sugerencia" | "otro";

export interface MensajeHilo {
  remitente: "usuario" | "admin";
  mensaje: string;
  fecha: string;
}

export interface Ticket {
  id: number;
  fechaCreacion: string;
  categoria: CategoriaTicket;
  estado: string;
  revisadoUsuario: boolean;
  asunto: string;
  hilo: MensajeHilo[];
  tieneRespuestaNueva: boolean;
}

export interface MisTickets {
  tickets: Ticket[];
  contadores: { total: number; activos: number; resueltos: number; noLeidos: number };
}

export async function getMisTickets(): Promise<MisTickets | null> {
  const res = await fetchConSesion("/api/me/soporte");
  if (!res || !res.ok) return null;
  return res.json();
}

export async function getGuiaArticulo(cat: string, slug: string): Promise<GuiaArticuloDetalle> {
  const resConSesion = await fetchConSesion(`/api/guias/${cat}/${slug}`);
  const res = resConSesion ?? (await fetch(`${API_URL}/api/guias/${cat}/${slug}`, { cache: "no-store" }));

  if (res.status === 404) return { encontrada: false, razon: "not_found" };
  if (res.status === 401) return { encontrada: false, razon: "sin_sesion" };
  if (res.status === 403) return { encontrada: false, razon: "no_tutor" };
  if (!res.ok) throw new Error(`La API de guías respondió ${res.status}`);

  const body = (await res.json()) as Omit<Extract<GuiaArticuloDetalle, { encontrada: true }>, "encontrada">;
  return { ...body, encontrada: true };
}

// ---- Desafío de hoy (puerto de app/desafio.php) ----
// Refleja PreguntaPublica/ResultadoDesafio (server/src/modules/desafio/desafio.types.ts).
// Mismo criterio que ServicioListado/ApunteListado: no es un import compartido a propósito.
export type TipoPreguntaDesafio = "alternativas" | "vf" | "completar" | "encuentra_error" | "cual_elegirias" | "que_harias_primero";

export interface DesafioMateria {
  slug: string;
  nombre: string;
}

export interface DesafioPregunta {
  id: number;
  tipo: TipoPreguntaDesafio;
  enunciado: string;
  desarrollo: string | null;
  opciones: Partial<Record<"a" | "b" | "c" | "d", string>>;
  tiempoLimiteSegundos: number | null;
  nivelPaes: boolean;
}

export interface DesafioResultado {
  ok: true;
  materia: string;
  aciertos: number;
  resultado: "bien" | "mal";
  categoriaServicio: string | null;
}

// ---- Favoritos de servicio (Fase 7 de la migración, feature nueva sin equivalente PHP —
// ver sql/pendientes/migracion_arquitectura_fase7_favoritos_servicios.sql) ----
export async function getMisFavoritos(): Promise<ServicioListado[] | null> {
  const res = await fetchConSesion("/api/me/favoritos");
  if (!res || !res.ok) return null;
  const body = (await res.json()) as { data: ServicioListado[] };
  return body.data;
}

export async function getDesafioMaterias(): Promise<DesafioMateria[]> {
  const res = await fetchConSesion("/api/desafio/materias");
  if (!res || !res.ok) return [];
  const body = (await res.json()) as { data: DesafioMateria[] };
  return body.data;
}
