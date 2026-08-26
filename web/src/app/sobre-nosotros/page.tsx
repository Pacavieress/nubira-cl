import type { Metadata } from "next";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { Footer } from "@/components/Footer";

export const metadata: Metadata = {
  title: "Sobre Nubira | Plataforma universitaria chilena",
  description:
    "Nubira nace de estudiantes para estudiantes. Marketplace chileno de tutorías y apuntes con pago protegido y verificación institucional.",
};

// Puerto de sobre-nosotros.php — página de contenido 100% estático (cero query a BD),
// "modo lectura" (ocultarBuscador/ocultarBotonesPublicar en el Header, igual que
// $ocultar_buscador/$ocultar_botones_publicar del PHP real).
//
// Deliberadamente NO portado (cosmético, sin equivalente ya establecido en web/):
//   - Animaciones de entrada (.reveal / animate.css) — el PHP las carga vía CDN externo
//     que web/ no usa en ningún otro lado; el resto del port no tiene una convención de
//     fade-in propia, así que agregar una nueva solo para esta página sería inconsistente.
//   - onerror del avatar de Pablo (fallback a /img/default_avatar.webp) — requeriría un
//     Client Component solo para eso; team_pablo.webp existe de verdad, así que el caso
//     de fallback es el borde raro, no el camino común.
//
// Nota de fidelidad deliberada, NO un bug mío: la sección "Los principios" (h4 bajo h2,
// sin h3 intermedio) y "El equipo" (mismo salto) reproducen tal cual un salto de jerarquía
// de encabezados que ya existe en el PHP real y está documentado como pendiente menor en
// CLAUDE.md — no lo corregí acá porque esta pieza es un espejo, no una auditoría de
// accesibilidad.
export default async function SobreNosotrosPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();
  const primerNombre = sesion?.nombre?.trim().split(" ")[0] ?? "Estudiante";

  return (
    <>
      <Header titulo="Sobre Nubira" ocultarBuscador ocultarBotonesPublicar />
      <main className="flex-grow pt-20 md:pt-24 pb-28 md:pb-16 w-full lg:pl-64 transition-all duration-300 max-w-[1600px] mx-auto">
        <article className="w-full max-w-2xl mx-auto px-5 md:px-8">
          <header className="mb-16 md:mb-20">
            <span className="inline-block py-1 px-3 rounded-full bg-gray-100 text-gray-600 text-[10px] font-bold uppercase tracking-widest mb-6">
              Una nota del fundador
            </span>
            <h1 className="text-3xl md:text-5xl font-extrabold text-[#222222] mb-6 tracking-[-0.01em] leading-[1.1]">
              Hola. Soy Pablo,
              <br />
              y esto es <span className="text-[#54A6D8]">Nubira</span>.
            </h1>
            <p className="text-lg text-gray-600 leading-relaxed">
              No es una startup financiada por un fondo. No tiene un equipo de cincuenta personas detrás. Hoy es una sola persona
              construyéndolo, desde adentro de una universidad chilena, intentando resolver algo que viví antes de que se me
              ocurriera la idea.
            </p>
          </header>

          <section className="mb-16 md:mb-20">
            <p className="text-xs font-bold uppercase tracking-widest text-[#54A6D8] mb-4">El problema</p>
            <h2 className="text-2xl md:text-3xl font-bold text-[#222222] mb-6 tracking-[-0.01em] leading-tight">
              Estudiar en Chile no debería depender de la suerte.
            </h2>
            <p className="text-base text-gray-600 leading-relaxed mb-6">
              Si has buscado un tutor o un apunte para tu próxima prueba, ya conoces el camino:
            </p>

            <ul className="space-y-3 text-sm md:text-base text-gray-700 mb-8">
              {[
                "Grupos de Facebook llenos de PDFs basura que no se abren.",
                "Tutores en Instagram que cobran por adelantado y desaparecen.",
                "Drives compartidos que llevan meses muertos.",
                "Mensajes leídos sin respuesta, justo el día antes de la prueba.",
              ].map((item) => (
                <li key={item} className="relative pl-7">
                  <span className="absolute left-0 top-0 text-red-500 font-bold text-[0.9rem]">✕</span>
                  {item}
                </li>
              ))}
            </ul>

            <p className="text-base text-gray-600 leading-relaxed">
              Lo viví. Lo vieron mis compañeros. Lo siguen viviendo miles de estudiantes cada semestre. Es un sistema improvisado
              donde aprobar o reprobar depende de a qué grupo de WhatsApp llegaste primero.
            </p>
          </section>

          <section className="mb-16 md:mb-20">
            <p className="text-xs font-bold uppercase tracking-widest text-[#54A6D8] mb-4">Lo que estoy construyendo</p>
            <h2 className="text-2xl md:text-3xl font-bold text-[#222222] mb-6 tracking-[-0.01em] leading-tight">
              Un solo lugar. Sin estafas. Sin perseguir a nadie.
            </h2>

            <p className="text-base text-gray-600 leading-relaxed mb-6">
              Nubira reúne en un mismo lugar lo que hoy está roto y disperso:
            </p>

            <ul className="space-y-3 text-sm md:text-base text-gray-700 mb-8">
              {[
                "El alumno paga una vez, la plataforma protege el dinero hasta que la clase ocurre.",
                "El tutor se profesionaliza: agenda, perfil, reseñas reales.",
                "Los apuntes se descargan al instante, no hay que rogar acceso.",
                "Todo dentro de la misma plataforma. Sin sacarte a Instagram, sin pedir tu WhatsApp.",
              ].map((item) => (
                <li key={item} className="relative pl-7">
                  <span className="absolute left-0 top-0 text-emerald-500 font-bold">✓</span>
                  {item}
                </li>
              ))}
            </ul>

            <div className="border-l-[3px] border-[#54A6D8] pl-5">
              <p className="text-base md:text-lg text-gray-800 leading-relaxed italic">
                Nubira no nace en una oficina con post-its. Nace desde adentro de una universidad chilena, hecha por alguien que
                vive cada día los mismos problemas que intenta resolver.
              </p>
            </div>
          </section>

          <section className="mb-16 md:mb-20">
            <p className="text-xs font-bold uppercase tracking-widest text-[#54A6D8] mb-4">Los principios</p>
            <h2 className="text-2xl md:text-3xl font-bold text-[#222222] mb-8 tracking-[-0.01em] leading-tight">
              Tres reglas que no se negocian.
            </h2>

            <div className="space-y-8">
              {[
                {
                  n: "01",
                  t: "El estudiante primero, siempre.",
                  d: "Cada decisión de producto se toma pensando en si protege al alumno. Si una función ayuda a vender pero perjudica al que paga, no entra.",
                },
                {
                  n: "02",
                  t: "Sin trampas, sin letra chica.",
                  d: "La comisión es transparente. El precio del tutor es el que ves. No hay cobros sorpresa, ni planes premium escondidos.",
                },
                {
                  n: "03",
                  t: "Construido lento, hecho bien.",
                  d: "Prefiero soltar una cosa que funcione bien antes que diez funciones a medias. Cada actualización se piensa con calma.",
                },
              ].map(({ n, t, d }) => (
                <div key={n}>
                  <div className="flex items-baseline gap-3 mb-2">
                    <span className="text-3xl font-extrabold text-gray-200 leading-none">{n}</span>
                    <h4 className="text-lg font-bold text-gray-900">{t}</h4>
                  </div>
                  <p className="text-sm md:text-base text-gray-600 leading-relaxed pl-12">{d}</p>
                </div>
              ))}
            </div>
          </section>

          <section className="mb-16 md:mb-20">
            <p className="text-xs font-bold uppercase tracking-widest text-[#54A6D8] mb-4">El equipo</p>
            <h2 className="text-2xl md:text-3xl font-bold text-[#222222] mb-8 tracking-[-0.01em] leading-tight">
              Hoy somos uno. Mañana, los que se sumen.
            </h2>

            <div className="bg-gray-50 border border-gray-100 rounded-3xl p-8 md:p-10 flex flex-col md:flex-row items-center gap-6 md:gap-8">
              {/* eslint-disable-next-line @next/next/no-img-element -- foto servida por el sitio PHP real (/img/), no un asset de Next. */}
              <img
                src={`${phpSiteUrl}/img/team_pablo.webp`}
                alt="Pablo, fundador de Nubira"
                className="w-24 h-24 md:w-28 md:h-28 rounded-full object-cover border-4 border-white shadow-md flex-shrink-0"
              />
              <div className="text-center md:text-left">
                <h4 className="text-xl font-bold text-gray-900 mb-1">Pablo</h4>
                <p className="text-xs text-[#54A6D8] font-bold uppercase tracking-widest mb-3">
                  Fundador · Producto · Soporte · Todo lo demás
                </p>
                <p className="text-sm text-gray-600 leading-relaxed">
                  Diseño la plataforma, escribo el código, modero los apuntes y respondo los correos. Si algo no funciona, soy yo.
                  Si algo te ayudó, también.
                </p>
              </div>
            </div>
          </section>

          <section>
            {!sesion ? (
              <div className="border-t border-gray-100 pt-10 md:pt-12 text-center">
                <h3 className="text-2xl md:text-3xl font-bold text-gray-900 mb-3 tracking-tight">Si te suena, súmate.</h3>
                <p className="text-sm md:text-base text-gray-500 mb-7 max-w-md mx-auto leading-relaxed">
                  Estoy construyendo esto público y lento. Si decides usarlo, eres parte del proceso.
                </p>
                <a
                  href={`${phpSiteUrl}/register`}
                  className="inline-block bg-[#54A6D8] text-white font-bold px-8 py-3.5 rounded-xl hover:bg-blue-600 transition-all shadow-sm hover:shadow-md hover:-translate-y-0.5 text-sm md:text-base"
                >
                  Crear mi cuenta
                </a>
                <p className="text-[11px] text-gray-400 mt-4 tracking-wide">Gratis · Sin tarjeta · Sin compromiso</p>
              </div>
            ) : (
              <div className="border-t border-gray-100 pt-10 md:pt-12">
                <p className="text-center text-sm md:text-base text-gray-600 leading-relaxed max-w-md mx-auto">
                  Gracias por estar acá, {primerNombre}. Esta plataforma se construye con tu uso. Si encuentras algo que arreglar,
                  dímelo.
                </p>
              </div>
            )}
          </section>
        </article>

        <div className="w-full max-w-2xl mx-auto px-5 md:px-8">
          <Footer />
        </div>
      </main>
    </>
  );
}
