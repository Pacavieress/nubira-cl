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
