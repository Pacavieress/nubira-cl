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
  // FavoritoToggle.tsx. contratoId agregado al portar detalle_servicio.php completo
  // (CTA "Ir al Aula Virtual") — ver servicios.types.ts::ViewerContext.
  viewer: { isAuthenticated: boolean; isOwner: boolean; esFavorito: boolean; contratoId: number | null };
  valoraciones: ValoracionPublica[];
  tiempoRespuesta: { texto: string; tono: TonoRespuesta };
  // Agregados al portar detalle_servicio.php completo (banner propietario, video, carrusel
  // de recomendados) — ver ServicioDetallePublico en servicios.types.ts para las notas de
  // alcance completas (especialmente la simplificación del motor de recomendación).
  estado: string;
  video: { path: string; thumbUrl: string | null } | null;
  recomendaciones: ServicioListado[];
  tutorEnClase: boolean;
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
// (cómo un visitante ve el perfil de OTRO). `accesos` ahora es un espejo tal cual del grid
// Bento de panel_gestion.php (26/08/2026, ver web/src/app/mi-perfil/page.tsx::PanelGestion)
// — icono real + gating de 3 vías, sin badges de contador en vivo.
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
  iconoSvg: string;
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

// Refleja LandingApuntes (server/src/modules/landings/landings.types.ts) [26/08/2026,
// Grupo C] — mismo motor que LandingClases (landing_categoria.php, rama tipo=apuntes).
export interface LandingApuntes {
  categoria: string;
  seo: { titulo: string; descripcion: string; h1: string; intro: string | null; noindex: boolean };
  total: number;
  apuntes: ApunteListado[];
  faqs: { pregunta: string; respuesta: string }[];
}

export async function getLandingApuntes(slug: string): Promise<LandingApuntes | null> {
  const res = await fetch(`${API_URL}/api/landings/apuntes/${slug}`, { cache: "no-store" });
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

// Refleja DatosBancariosCompletos/DatosBancariosParaEditar (server/src/modules/miBilletera/
// miBilletera.types.ts) [26/08/2026] — a diferencia de MiBilletera.datosBancarios (arriba,
// solo banco + últimos 4 dígitos para el resumen), esta trae la fila completa sin
// enmascarar: es lo que el propio dueño necesita para editar su formulario.
export interface DatosBancariosCompletos {
  banco: string;
  tipoCuenta: string;
  numeroCuenta: string;
  titularNombre: string;
  rut: string;
}

export interface DatosBancariosParaEditar {
  bancos: string[];
  datos: DatosBancariosCompletos | null;
}

export async function getDatosBancariosParaEditar(): Promise<DatosBancariosParaEditar | null> {
  const res = await fetchConSesion("/api/me/mi-billetera/datos-bancarios");
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

// Refleja MetricaDetalle (server/src/modules/metricas/metricas.types.ts) [26/08/2026,
// Grupo C] — puerto de app/metricas_detalle.php completo (funnel, sparkline de 30 días,
// dispositivos, orígenes, ubicación). Página de solo lectura: se llama directo desde el
// Server Component de la página (mismo patrón que getMisMetricas arriba), sin proxy en
// web/api/ porque ningún Client Component la necesita.
export interface Delta {
  dir: "up" | "down" | "flat";
  label: string;
}

export interface FunnelEtapa {
  label: string;
  valor: number;
}

export interface OrigenStat {
  origen: string;
  total: number;
}

export interface UbicacionStat {
  ciudad: string | null;
  pais: string | null;
  visitas: number;
}

export interface MetricaDetalle {
  publicacion: {
    id: number;
    tipo: "servicio" | "apunte";
    titulo: string;
    precio: number | null;
    imagenUrl: string;
    editarHref: string;
  };
  visitas30d: number;
  deltaVisitas: Delta | null;
  tiempoPromedioSegundos: number;
  deltaTiempo: Delta | null;
  pctLeyo: number;
  deltaLeyo: Delta | null;
  visitasTotal: number;
  funnel: FunnelEtapa[];
  visitasPorDia: number[];
  dispositivos: { movil: number; tablet: number; desktop: number };
  origenes: OrigenStat[];
  ubicaciones: UbicacionStat[];
}

export async function getMiMetricaDetalle(tipo: string, id: string): Promise<MetricaDetalle | null> {
  const res = await fetchConSesion(`/api/me/metricas/${tipo}/${id}`);
  if (!res || !res.ok) return null;
  return res.json();
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

// Refleja DominioPermitido (server/src/modules/adminDominios/adminDominios.types.ts) —
// panel admin "Dominios" (admin_dominios.php), gestor de instituciones/dominios de correo
// permitidos. Lectura server-side (esta función); altas/edición/borrado van por Route
// Handlers (web/src/app/api/admin/dominios/...) porque las dispara un Client Component.
export interface DominioPermitido {
  id: number;
  dominio: string;
  institucion: string;
  totalUsuarios: number;
}

export async function getAdminDominios(): Promise<DominioPermitido[]> {
  const res = await fetchConSesion("/api/admin/dominios");
  if (!res || !res.ok) return [];
  return res.json();
}

// Refleja ConfigPrecios (server/src/modules/adminConfigPrecios/adminConfigPrecios.types.ts)
// — panel admin "Precios" (admin_config_precios.php).
export interface ConfigPrecios {
  precioDesbloqueoContacto: number;
  ofertaGratisHasta: string | null;
  ofertaVigente: boolean;
}

export async function getAdminConfigPrecios(): Promise<ConfigPrecios | null> {
  const res = await fetchConSesion("/api/admin/config-precios");
  if (!res || !res.ok) return null;
  return res.json();
}

// Refleja RecordatoriosResumen (server/src/modules/adminRecordatorios/adminRecordatorios.types.ts)
// — panel admin "Recordatorios" (admin_recordatorios.php), monitor 100% lectura de
// acciones_pendientes (correos automáticos de reenganche).
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

export interface RecordatoriosResumen {
  enviadosHoy: number;
  pendientesHoy: number;
  registros: RecordatorioItem[];
}

export async function getAdminRecordatorios(filtros: { fecha?: string; tipo?: string; estado?: string }): Promise<RecordatoriosResumen | null> {
  const params = new URLSearchParams();
  if (filtros.fecha) params.set("fecha", filtros.fecha);
  if (filtros.tipo) params.set("tipo", filtros.tipo);
  if (filtros.estado) params.set("estado", filtros.estado);
  const qs = params.toString();
  const res = await fetchConSesion(`/api/admin/recordatorios${qs ? `?${qs}` : ""}`);
  if (!res || !res.ok) return null;
  return res.json();
}

// Refleja CuentaBancariaAdmin (server/src/modules/adminCuentas/adminCuentas.types.ts) —
// panel admin "Cuentas Bancarias" (admin_cuentas.php), 100% lectura.
export interface CuentaBancariaAdmin {
  idUsuario: number;
  nombre: string;
  correo: string;
  bloqueado: boolean;
  visible: boolean;
  banco: string;
  tipoCuenta: string;
  numeroCuenta: string;
  titularNombre: string;
  rut: string;
  fechaConfiguracion: string;
}

export async function getAdminCuentas(mostrarTodos: boolean): Promise<CuentaBancariaAdmin[]> {
  const res = await fetchConSesion(`/api/admin/cuentas${mostrarTodos ? "?mostrarTodos=1" : ""}`);
  if (!res || !res.ok) return [];
  return res.json();
}

// Refleja ContratosResumen (server/src/modules/adminContratos/adminContratos.types.ts) —
// panel admin "Contratos" (admin_contratos.php). SOLO lectura (stats + listado + detalle) —
// ver nota de alcance en el tipo del server sobre las acciones de escritura excluidas.
export interface ContratoAdmin {
  id: number;
  estado: string;
  monto: number;
  fechaCreacion: string;
  fechaEstimada: string | null;
  fechaCierre: string | null;
  conversacionId: number | null;
  servicioTitulo: string;
  compradorNombre: string;
  vendedorNombre: string;
}

export interface ContratosResumen {
  stats: Record<string, number>;
  total: number;
  contratos: ContratoAdmin[];
}

export async function getAdminContratos(estado?: string): Promise<ContratosResumen | null> {
  const res = await fetchConSesion(`/api/admin/contratos${estado ? `?estado=${estado}` : ""}`);
  if (!res || !res.ok) return null;
  return res.json();
}

// Refleja AutorServicio (server/src/modules/adminAutores/adminAutores.types.ts) — panel
// admin "Autores de Servicios" (admin_autores_servicios.php). SOLO lectura — ver nota de
// alcance en el tipo del server sobre la acción "Escribir correo" excluida.
export interface AutorServicio {
  idUsuario: number;
  nombre: string;
  correo: string;
  institucion: string | null;
  fotoPerfil: string | null;
  bio: string | null;
  tipo: string | null;
  cantidadServicios: number;
  serviciosConHorario: number;
  ultimaPublicacion: string | null;
  totalConversaciones: number;
  portadaUrl: string;
  ultimoCorreo: { asunto: string | null; mensaje: string | null; fecha: string; exito: boolean } | null;
}

export async function getAdminAutores(filtros: { q?: string; filtro?: "incompleto" }): Promise<AutorServicio[]> {
  const params = new URLSearchParams();
  if (filtros.q) params.set("q", filtros.q);
  if (filtros.filtro) params.set("filtro", filtros.filtro);
  const qs = params.toString();
  const res = await fetchConSesion(`/api/admin/autores${qs ? `?${qs}` : ""}`);
  if (!res || !res.ok) return [];
  return res.json();
}

// Refleja ServicioAdmin (server/src/modules/adminServicios/adminServicios.types.ts) —
// panel admin "Servicios" (admin_servicios.php). Lectura + toggle de visibilidad — ver nota
// de alcance en el tipo del server sobre aprobar/rechazar/eliminar/censura de imagen
// excluidos (correo/push real, DELETE permanente, editor de imagen irreversible).
export interface ServicioAdmin {
  id: number;
  titulo: string;
  nombreOferente: string | null;
  nombreAlumno: string | null;
  categoria: string | null;
  estado: string;
  motivoRechazo: string | null;
  visible: boolean;
  fechaPublicacion: string;
  portadaUrl: string;
}

export async function getAdminServicios(q?: string): Promise<ServicioAdmin[]> {
  const res = await fetchConSesion(`/api/admin/servicios${q ? `?q=${encodeURIComponent(q)}` : ""}`);
  if (!res || !res.ok) return [];
  return res.json();
}

// Refleja ComprasApuntesResumen (server/src/modules/adminComprasApuntes/adminComprasApuntes.types.ts)
// — panel admin "Compras de Apuntes" (admin_compras_apuntes.php). 100% lectura.
export interface VentaApunteDetalle {
  id: number;
  fecha: string;
  apunteTitulo: string;
  asignatura: string | null;
  compradorNombre: string;
  compradorCorreo: string;
  precio: number;
  pagadoAlVendedor: boolean;
  paymentId: string | null;
}

export interface TutorVentas {
  vendedorId: number;
  vendedorNombre: string;
  vendedorCorreo: string;
  totalVentas: number;
  totalMonto: number;
  ultimaVenta: string;
  pagadas: number;
  pendientes: number;
  detalle: VentaApunteDetalle[];
}

export interface ComprasApuntesResumen {
  kpis: { totalCompras: number; totalMonto: number; totalTutores: number };
  desync: number;
  tutores: TutorVentas[];
  detalleTruncado: boolean;
}

export interface ComprasApuntesFiltros {
  q_apunte?: string;
  q_comprador?: string;
  q_vendedor?: string;
  estado_pago?: string;
  fecha_desde?: string;
  fecha_hasta?: string;
  orden?: string;
}

export async function getAdminComprasApuntes(filtros: ComprasApuntesFiltros): Promise<ComprasApuntesResumen | null> {
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(filtros)) {
    if (v) params.set(k, v);
  }
  const qs = params.toString();
  const res = await fetchConSesion(`/api/admin/compras-apuntes${qs ? `?${qs}` : ""}`);
  if (!res || !res.ok) return null;
  return res.json();
}

// Refleja AvisosResumen (server/src/modules/adminAvisos/adminAvisos.types.ts) — panel admin
// "Avisos a usuarios" (admin_avisos.php). Solo lectura: métricas + historial de campañas +
// detalle de lectores. Crear/enviar/eliminar/duplicar campaña quedan fuera de alcance — ver
// nota de exclusión en adminAvisos.types.ts.
export interface AvisoImagen {
  archivo: string;
  url: string;
}

export interface AvisoCampana {
  id: number;
  titulo: string;
  mensaje: string;
  tipo: string;
  segmento: string;
  totalDestinatarios: number;
  leidos: number;
  fechaCreacion: string;
  imagenes: AvisoImagen[];
}

export interface AvisosResumen {
  totalCampanas: number;
  totalDestinatarios: number;
  campanas: AvisoCampana[];
}

export interface AvisoLector {
  nombre: string;
  institucion: string | null;
  fechaLeido: string;
}

export async function getAdminAvisos(): Promise<AvisosResumen | null> {
  const res = await fetchConSesion("/api/admin/avisos");
  if (!res || !res.ok) return null;
  return res.json();
}

// Refleja OfertaApunte (server/src/modules/adminOfertasApuntes/adminOfertasApuntes.types.ts)
// — panel admin "Promo Apuntes" (admin_ofertas_apuntes.php). Las 3 mutaciones (precio,
// aplicar/quitar promo) se portan completas: son UPDATE puros, sin efecto externo.
export interface OfertaApunte {
  id: number;
  titulo: string;
  tutorNombre: string;
  precio: number;
  promoGratis: boolean;
  promoLimite: number;
  promoContador: number;
}

export async function getAdminOfertasApuntes(tutor?: string): Promise<OfertaApunte[]> {
  const res = await fetchConSesion(`/api/admin/ofertas-apuntes${tutor ? `?tutor=${encodeURIComponent(tutor)}` : ""}`);
  if (!res || !res.ok) return [];
  return res.json();
}

// Refleja AulaListado/AulaDetalle (server/src/modules/adminAulas/adminAulas.types.ts) —
// panel admin "Monitor Aulas" (admin_chats_aula.php). 100% lectura, sin ninguna mutación.
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

export async function getAdminAulas(q?: string, orden?: "asc" | "desc"): Promise<AulaListado[]> {
  const params = new URLSearchParams();
  if (q) params.set("q", q);
  if (orden) params.set("orden", orden);
  const qs = params.toString();
  const res = await fetchConSesion(`/api/admin/aulas${qs ? `?${qs}` : ""}`);
  if (!res || !res.ok) return [];
  return res.json();
}

// Refleja MonitoreoResumen (server/src/modules/adminLoginFallos/adminLoginFallos.types.ts)
// — panel admin "Log Fail" / "Centro de Monitoreo" (admin_login_fallos.php). 3 tabs:
// Intentos, VIPs, Pendientes. Ver AdminLoginFallosPanel.tsx para la nota de alcance sobre
// 'eliminar_pendiente' (hard delete), excluido.
export type MonitoreoTab = "fallos" | "vips" | "pendientes";

export interface LoginFalloItem {
  correo: string;
  ip: string;
  fecha: string;
  esAlumno: boolean;
}

export interface VipItem {
  id: number;
  correo: string;
  fechaCreacion: string;
}

export interface PendienteItem {
  id: number;
  nombre: string;
  correo: string;
  carrera: string | null;
  dominio: string | null;
}

export interface MonitoreoResumen {
  tab: MonitoreoTab;
  page: number;
  limit: number;
  total: number;
  contadores: { fallos: number; vips: number; pendientes: number };
  itemsFallos?: LoginFalloItem[];
  itemsVips?: VipItem[];
  itemsPendientes?: PendienteItem[];
}

export async function getAdminMonitoreo(tab: MonitoreoTab, page: number): Promise<MonitoreoResumen | null> {
  const res = await fetchConSesion(`/api/admin/login-fallos?tab=${tab}&page=${page}`);
  if (!res || !res.ok) return null;
  return res.json();
}

// Refleja ReportesResumen (server/src/modules/adminReportesServicios/adminReportesServicios.types.ts)
// — panel admin "Reportes" (admin_reportes_servicios.php). Ver AdminReportesServiciosPanel.tsx
// para la nota de alcance sobre 'marcar_revisado' (envía 2 correos reales), excluido.
export type EstadoReporte = "pendientes" | "revisados" | "todos";

export interface ReporteServicio {
  id: number;
  servicioId: number;
  tituloServicio: string;
  motivo: string;
  mensaje: string | null;
  fecha: string;
  revisado: boolean;
  usuarioReporta: { nombre: string; correo: string };
  usuarioReportado: { id: number; nombre: string; correo: string; bloqueado: boolean };
}

export interface ReportesResumen {
  estado: EstadoReporte;
  countPendientes: number;
  reportes: ReporteServicio[];
}

export async function getAdminReportesServicios(estado: EstadoReporte): Promise<ReportesResumen | null> {
  const res = await fetchConSesion(`/api/admin/reportes-servicios?estado=${estado}`);
  if (!res || !res.ok) return null;
  return res.json();
}

// Refleja SolicitudesResumen (server/src/modules/adminSolicitudes/adminSolicitudes.types.ts)
// — panel admin "Solicitudes" (admin_solicitudes.php, solicitudes de institución). 100%
// lectura: ver la nota de alcance en adminSolicitudes.types.ts (aprobar/rechazar envían
// correo real, eliminar_masivo es hard DELETE, marcar_revisada es de un solo sentido).
export type EstadoSolicitud = "" | "pendiente" | "revisada";

export interface SolicitudInstitucion {
  id: number;
  institucion: string;
  email: string;
  fecha: string | null;
  estado: "pendiente" | "revisada";
  correoEnviado: boolean;
}

export interface SolicitudesResumen {
  estado: EstadoSolicitud;
  solicitudes: SolicitudInstitucion[];
}

export async function getAdminSolicitudes(estado: EstadoSolicitud): Promise<SolicitudesResumen | null> {
  const res = await fetchConSesion(`/api/admin/solicitudes?estado=${estado}`);
  if (!res || !res.ok) return null;
  return res.json();
}

// Refleja CuponesResumen (server/src/modules/adminCupones/adminCupones.types.ts) — panel
// admin "Becas / Cupones" (cupones.php, "Bóveda de Becas"). Lectura server-side (esta
// función); crear/eliminar van por Route Handlers (web/src/app/api/admin/cupones/...) porque
// las dispara un Client Component, mismo patrón que adminDominios.
export interface CuponBeca {
  id: number;
  codigo: string;
  porcentajeDescuento: number;
  usosActuales: number;
  usosMaximos: number;
  servicioId: number | null;
  servicioTitulo: string | null;
  fechaExpiracion: string | null;
}

export interface ServicioParaCupon {
  id: number;
  titulo: string;
  precio: number;
}

export interface CuponesResumen {
  cupones: CuponBeca[];
  servicios: ServicioParaCupon[];
}

export async function getAdminCupones(): Promise<CuponesResumen> {
  const res = await fetchConSesion("/api/admin/cupones");
  if (!res || !res.ok) return { cupones: [], servicios: [] };
  return res.json();
}

// Refleja ServicioConOferta (server/src/modules/adminOfertas/adminOfertas.types.ts) — panel
// admin "Subsidios" (admin_ofertas.php, "Centro de Subsidios"). Lectura server-side (esta
// función); aplicar/quitar oferta van por Route Handlers (web/src/app/api/admin/ofertas/...)
// porque las dispara un Client Component, mismo patrón que adminOfertasApuntes.
export type OrdenOfertas = "recientes" | "descuento" | "vencer" | "cupos" | "activas" | "precio_mayor" | "precio_menor";

export interface ServicioConOferta {
  id: number;
  titulo: string;
  categoria: string | null;
  tutorNombre: string;
  precio: number;
  precioOferta: number | null;
  cuposOferta: number;
  isSubvencionado: boolean;
  ofertaTermino: string | null;
}

export async function getAdminOfertas(orden: OrdenOfertas): Promise<ServicioConOferta[]> {
  const res = await fetchConSesion(`/api/admin/ofertas?orden=${orden}`);
  if (!res || !res.ok) return [];
  const body = (await res.json()) as { servicios: ServicioConOferta[] };
  return body.servicios;
}

// Refleja VideoServicio (server/src/modules/adminVideos/adminVideos.types.ts) — panel admin
// "Videos" (admin_videos.php, moderación de videos de presentación). 100% lectura:
// aprobar/rechazar envían correo real + push notification al tutor, quedan excluidos y
// enlazan al sitio PHP real, mismo criterio que el resto de esta ronda de paneles.
export type EstadoVideo = "pendiente" | "aprobado" | "rechazado" | "todos";

export interface VideoServicio {
  id: number;
  titulo: string;
  categoria: string | null;
  materia: string | null;
  precio: number;
  videoPath: string;
  videoEstado: "pendiente" | "aprobado" | "rechazado";
  videoMotivoRechazo: string | null;
  videoSubidoEn: string | null;
  alumnoId: number;
  tutorNombre: string;
  tutorFotoPerfil: string | null;
  tutorCorreo: string;
}

export interface VideosResumen {
  filtro: EstadoVideo;
  totalPendientes: number;
  videos: VideoServicio[];
}

export async function getAdminVideos(filtro: EstadoVideo): Promise<VideosResumen> {
  const res = await fetchConSesion(`/api/admin/videos?filtro=${filtro}`);
  if (!res || !res.ok) return { filtro, totalPendientes: 0, videos: [] };
  return res.json();
}

// Refleja los tipos de server/src/modules/adminReclamos/adminReclamos.types.ts — panel admin
// "Sugerencias" (admin_reclamos.php, "Gestión de Reclamos"). Lectura server-side (esta
// función); responder/resolver/papelera/restaurar/eliminar/lote van por Route Handlers
// (web/src/app/api/admin/reclamos/...) porque las dispara AdminReclamosPanel (Client Component).
export type EstadoFiltroReclamos = "activos" | "resuelto" | "todos" | "eliminado";

export type AccionLoteReclamos = "papelera" | "restaurar" | "eliminar_hard";

export interface MensajeHiloReclamo {
  remitente: "usuario" | "admin";
  mensaje: string;
  fecha: string;
}

export interface TicketReclamo {
  id: number;
  fecha: string;
  texto: string;
  estado: string;
  respuestaAdmin: string | null;
  usuarioNombre: string;
  fotoPerfil: string | null;
  chatThread: MensajeHiloReclamo[];
  urgente: boolean;
}

export interface ContadoresReclamos {
  activos: number;
  resuelto: number;
  eliminado: number;
  todos: number;
}

export interface ReclamosResumen {
  estado: EstadoFiltroReclamos;
  contadores: ContadoresReclamos;
  tickets: TicketReclamo[];
}

export async function getAdminReclamos(estado: EstadoFiltroReclamos): Promise<ReclamosResumen> {
  const res = await fetchConSesion(`/api/admin/reclamos?estado=${estado}`);
  if (!res || !res.ok) return { estado, contadores: { activos: 0, resuelto: 0, eliminado: 0, todos: 0 }, tickets: [] };
  return res.json();
}

// Refleja los tipos de server/src/modules/adminUsuarios/adminUsuarios.types.ts — panel admin
// "Usuarios" (admin_usuarios.php, "Gestión de Usuarios"). Lectura server-side (esta función);
// las mutaciones van por Route Handlers (web/src/app/api/admin/usuarios/...) porque las
// dispara AdminUsuariosPanel (Client Component). "Reenviar confirmación" queda excluida
// (envía correo real) — mismo criterio que aprobar/rechazar en Videos.
export type FiltroRolUsuarios = "" | "admin" | "alumno";
export type FiltroVerificadoUsuarios = "" | "si" | "no";

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

export interface FiltrosUsuarios {
  q: string;
  rol: FiltroRolUsuarios;
  verificado: FiltroVerificadoUsuarios;
  page: number;
}

export async function getAdminUsuarios(f: FiltrosUsuarios): Promise<UsuariosResumen> {
  const params = new URLSearchParams();
  if (f.q) params.set("q", f.q);
  if (f.rol) params.set("rol", f.rol);
  if (f.verificado) params.set("verificado", f.verificado);
  params.set("page", String(f.page));
  const res = await fetchConSesion(`/api/admin/usuarios?${params.toString()}`);
  if (!res || !res.ok) return { page: f.page, totalPages: 1, totalUsers: 0, totalUsersGlobal: 0, usuarios: [] };
  return res.json();
}

// Refleja los tipos de server/src/modules/adminApuntes/adminApuntes.types.ts — panel admin
// "Apuntes" (admin_apuntes.php, "Gestión de Apuntes"). Lectura server-side (esta función);
// "alternar" (única mutación portada) va por Route Handler porque la dispara
// AdminApuntesPanel (Client Component). aprobar/rechazar/eliminar/censura de miniatura quedan
// excluidos — ver la nota de alcance completa en el módulo del servidor.
export interface ApunteAdminListado {
  id: number;
  titulo: string;
  autor: string;
  asignatura: string;
  fechaSubida: string;
  publico: boolean;
  estado: string;
  totalVentas: number;
  miniaturaUrl: string;
}

export interface ApuntesAdminResumen {
  q: string;
  apuntes: ApunteAdminListado[];
}

export async function getAdminApuntes(q: string): Promise<ApuntesAdminResumen> {
  const res = await fetchConSesion(`/api/admin/apuntes${q ? `?q=${encodeURIComponent(q)}` : ""}`);
  if (!res || !res.ok) return { q, apuntes: [] };
  return res.json();
}

// Refleja los tipos de server/src/modules/adminAccesos/adminAccesos.types.ts — panel admin
// "Accesos" (admin_accesos_vitrina.php, "Analíticas"). Portado completo, incluidas sus 2
// mutaciones (eliminar selección, purgar bots — DELETE puros sobre historial_actividad, log
// de analítica). Lectura server-side (esta función); mutaciones y navegación de tabs/detalle
// van por Route Handlers porque las dispara AdminAccesosPanel (Client Component).
export type TabAccesos = "trafico" | "bots" | "paginas" | "fallidas";

export interface UsuarioTrafico {
  usuarioId: number;
  ipUsuario: string | null;
  ultimaActividad: string;
  totalAcciones: number;
  ultimaUrl: string | null;
  ultimaAccionTxt: string | null;
  nombre: string | null;
  fotoPerfil: string | null;
  institucion: string | null;
  correo: string | null;
}
export interface ContadoresTrafico {
  alumnos: number;
  invitados: number;
  bots: number;
}
export interface BotFila {
  ipUsuario: string;
  userAgent: string | null;
  totalHits: number;
  urlsUnicas: number;
  ultimaVisita: string;
  primeraVisita: string;
}
export interface StatsBots {
  totalEventos: number;
  ipsUnicas: number;
  botsUnicos: number;
}
export interface PaginaFila {
  url: string;
  hits: number;
  uniques: number;
}
export interface BusquedaFallida {
  termino: string;
  totalIntentos: number;
  ultimaBusqueda: string;
}
export interface AccesosResumen {
  tab: TabAccesos;
  trafico?: { contadores: ContadoresTrafico; usuarios: UsuarioTrafico[] };
  bots?: { stats: StatsBots; bots: BotFila[] };
  paginas?: { totalHits: number; paginas: PaginaFila[] };
  fallidas?: { busquedas: BusquedaFallida[] };
}

export interface EventoHistorial {
  id: number;
  accion: string;
  detalle: string | null;
  url: string | null;
  ipUsuario: string | null;
  fecha: string;
  esBot: boolean;
}
export interface DetalleUsuario {
  usuarioId: number;
  esGuest: boolean;
  ip: string | null;
  nombre: string;
  correo: string | null;
  fotoPerfil: string | null;
  totalEventos: number;
  accionFav: string;
  primeraVisita: string | null;
  ultimaVisita: string | null;
  online: boolean;
  fueBot: boolean;
  urlsUnicas: number;
  diasDesdePrimera: number;
  primerReferrer: string | null;
  primerUtm: string | null;
  primerContacto: string | null;
  primerApunte: string | null;
}
export interface DetalleResumen {
  usuario: DetalleUsuario;
  eventos: EventoHistorial[];
}

export async function getAdminAccesos(tab: TabAccesos): Promise<AccesosResumen> {
  const res = await fetchConSesion(`/api/admin/accesos?tab=${tab}`);
  if (!res || !res.ok) return { tab };
  return res.json();
}

// Refleja los tipos de server/src/modules/adminChats/adminChats.types.ts — panel admin
// "Monitor Chats" (admin_chats.php, "Master Tracker"). Alcance confirmado con el usuario
// antes de construir: se portan 3 mutaciones seguras (eliminar/restaurar chat, marcar DLP
// revisado, aprobar archivo), todas DB-only. "liberar_mensaje_dlp" (inserta un mensaje
// bloqueado en una conversación real + notificación real) NUNCA se porta sin aprobación
// explícita dedicada del usuario — no asumir que queda disponible para retomar después.
// "rechazar_archivo" (borra el archivo del disco) tampoco se porta — mismo bucket que
// "eliminar" en Apuntes. Ambas quedan como link al sitio PHP real.
export type FiltroChats = "activos" | "cerrados" | "contrato" | "cotizacion" | "inactivos" | "alertas_dlp" | "moderacion";

export interface ChatListado {
  id: number;
  contratoId: number | null;
  fechaOrden: string | null;
  eliminado: boolean;
  compradorId: number;
  compradorNombre: string;
  compradorFoto: string | null;
  vendedorId: number;
  vendedorNombre: string;
  vendedorFoto: string | null;
  servicioTitulo: string | null;
}

export interface ContadoresChats {
  activos: number;
  cerrados: number;
  contrato: number;
  cotizacion: number;
  inactivos: number;
  alertasDlp: number;
  moderacion: number;
}

export interface MensajeChat {
  id: number;
  remitenteId: number;
  mensaje: string;
  archivoNombre: string | null;
  archivoRuta: string | null;
  archivoTipo: string | null;
  archivoPeso: number | null;
  enviadoEn: string | null;
}

export interface DlpIntento {
  id: number;
  categoria: string;
  textoIntentado: string;
  fecha: string | null;
  revisadoAdmin: boolean;
  remitenteNombre: string;
}

export interface ChatInfo {
  id: number;
  compradorId: number;
  compradorNombre: string;
  compradorFoto: string | null;
  vendedorId: number;
  vendedorNombre: string;
  vendedorFoto: string | null;
  servicioTitulo: string | null;
  contratoId: number | null;
  eliminado: boolean;
}

export interface MetadataChat {
  totalMensajes: number;
  archivos: number;
  primero: string | null;
  ultimo: string | null;
}

export interface ChatDetalle {
  info: ChatInfo;
  mensajes: MensajeChat[];
  dlp: DlpIntento[];
  metadata: MetadataChat;
}

export interface ArchivoModeracion {
  id: number;
  conversacionId: number;
  archivoRuta: string;
  archivoNombre: string | null;
  archivoTipo: string | null;
  archivoPeso: number | null;
  enviadoEn: string | null;
  remitenteNombre: string;
}

export async function getAdminChats(filtro: FiltroChats, orden: "asc" | "desc", q: string): Promise<{ chats: ChatListado[] }> {
  const params = new URLSearchParams({ estado: filtro, orden });
  if (q) params.set("q", q);
  const res = await fetchConSesion(`/api/admin/chats?${params.toString()}`);
  if (!res || !res.ok) return { chats: [] };
  return res.json();
}

export async function getAdminChatsContadores(): Promise<ContadoresChats> {
  const res = await fetchConSesion("/api/admin/chats/contadores");
  if (!res || !res.ok) return { activos: 0, cerrados: 0, contrato: 0, cotizacion: 0, inactivos: 0, alertasDlp: 0, moderacion: 0 };
  return res.json();
}

// Refleja server/src/modules/contratos/contratos.types.ts — Grupo de Contratación
// (26/08/2026). El checkout termina en un contrato 'pendiente_pago' y redirige al sitio PHP
// real para pagar (Checkpoint 2, no construido todavía) — mismo puente usado en el resto de
// esta migración.
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

export type ResultadoCupon =
  | { ok: true; cuponId: number; descuentoPorcentaje: number; montoFinal: number; mensaje: string }
  | { ok: false; error: string };

export async function getContratoCheckout(servicioId: number): Promise<{ servicio: ServicioCheckout; cupon: ResultadoCupon | null } | null> {
  const res = await fetchConSesion(`/api/me/contratos/checkout/${servicioId}`);
  if (!res || !res.ok) return null;
  return res.json();
}

// Checkpoint 2 (Pago) — 26/08/2026. Puerto unificado de iniciar_pago_servicio.php +
// iniciar_pago_contrato.php + notificaciones_mp.php + pago_exitoso_contrato.php +
// pago_error_contrato.php + pago_pendiente_contrato.php. Ver server/src/modules/
// pagoContratos/pagoContratos.types.ts para los 2 hallazgos reales corregidos al portar.
export type AccionProcesarPago = "aprobado" | "aprobado_ya_procesado" | "pendiente" | "rechazado" | "sin_cambios";

export interface ResultadoRetornoPago {
  ok: boolean;
  error?: string;
  accion?: AccionProcesarPago;
  status?: string;
  contrato?: { id: number; estado: string; monto: number; servicioTitulo: string };
}

export async function getConfirmarRetornoPago(paymentId: string): Promise<ResultadoRetornoPago | null> {
  const res = await fetchConSesion(`/api/me/pago-contratos/retorno?paymentId=${encodeURIComponent(paymentId)}`);
  if (!res) return null;
  return res.json();
}

// Grupo Mensajes/Chat pre-contrato — Pieza 1 (26/08/2026). Puerto de bandeja_entrada.php +
// chat_previo_contrato.php. Ver server/src/modules/chat/chat.types.ts para el alcance
// completo y las 2 decisiones de puerto documentadas ahí (bandeja real vs. mis_chats.php
// secundario; adjuntos nuevos diferidos junto con su moderación).
export interface ChatBandejaItem {
  id: number;
  tipo: "negociacion" | "aula";
  fechaSort: string;
  servicioTitulo: string;
  otroId: number;
  otroNombre: string;
  otroFotoUrl: string | null;
  ultimoMensaje: string | null;
  sinLeer: number;
}

export async function getBandejaChats(): Promise<ChatBandejaItem[]> {
  const res = await fetchConSesion("/api/me/chat/bandeja");
  if (!res || !res.ok) return [];
  const data = (await res.json()) as { items: ChatBandejaItem[] };
  return data.items;
}

export interface ChatDetalle {
  id: number;
  servicioId: number;
  servicioTitulo: string;
  esVendedor: boolean;
  otroId: number;
  otroNombre: string;
  otroFotoUrl: string | null;
  otroOnline: boolean;
  destinatarioSuspendido: boolean;
  tutorInactivo: boolean;
  limiteMensajesAlcanzado: boolean;
  contratoId: number | null;
  servicio: { precio: number; precioOferta: number | null; esOferta: boolean; duracionMinutos: number };
}

export async function getChatDetalle(chatId: number): Promise<ChatDetalle | null> {
  const res = await fetchConSesion(`/api/me/chat/${chatId}`);
  if (!res || !res.ok) return null;
  return res.json();
}

export interface MensajeChatPrevio {
  id: number;
  remitenteId: number;
  esSistema: boolean;
  mensaje: string;
  enviadoEn: string;
  leido: boolean;
  archivo: { nombre: string; tipo: string; peso: number; url: string } | null;
}

export async function getMensajesChatPrevio(chatId: number): Promise<{ mensajes: MensajeChatPrevio[]; otroEscribiendo: boolean } | null> {
  const res = await fetchConSesion(`/api/me/chat/${chatId}/mensajes`);
  if (!res || !res.ok) return null;
  return res.json();
}
