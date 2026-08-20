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
  viewer: { isAuthenticated: boolean; isOwner: boolean };
  valoraciones: ValoracionPublica[];
  tiempoRespuesta: { texto: string; tono: TonoRespuesta };
}

export async function getServicioDetalle(id: number): Promise<ServicioDetalle | null> {
  const res = await fetch(`${API_URL}/api/servicios/${id}`, { cache: "no-store" });
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
}

export async function getApuntes(filtros: ApuntesFiltros = {}): Promise<ApuntesResponse> {
  const params = new URLSearchParams();
  if (filtros.nivel) params.set("nivel", filtros.nivel);
  if (filtros.precio) params.set("precio", filtros.precio);
  if (filtros.orden) params.set("orden", filtros.orden);
  if (filtros.q) params.set("q", filtros.q);

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

export async function getApunteDetalle(id: number): Promise<ApunteDetalle | null> {
  const res = await fetch(`${API_URL}/api/apuntes/${id}`, { cache: "no-store" });
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
