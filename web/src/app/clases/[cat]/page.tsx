import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { getLandingClases } from "@/lib/api";
import { Header } from "@/components/Header";
import { ServicioCard } from "@/components/ServicioCard";

interface LandingProps {
  params: Promise<{ cat: string }>;
}

// Puerto de app/landing_categoria.php — SOLO tipo=clases. Ver
// server/src/modules/landings/landings.types.ts para por qué tipo=apuntes queda fuera
// de esta pieza (cero contenido SEO real configurado hoy + colisión de ruta con /apuntes).
//
// Mejora deliberada (no simplificación): esta página SÍ usa generateMetadata() de
// Next.js para title/description/robots reales — a diferencia del resto de web/, que no
// arma <head> dinámico por página. Se justifica acá porque el propósito completo de esta
// pieza es SEO; omitir metadata real la dejaría sin cumplir su función.
export async function generateMetadata({ params }: LandingProps): Promise<Metadata> {
  const { cat } = await params;
  const landing = await getLandingClases(cat);
  if (!landing) return {};

  return {
    title: landing.seo.titulo,
    description: landing.seo.descripcion,
    robots: landing.seo.noindex ? "noindex, follow" : "index, follow",
  };
}

export default async function LandingClasesPage({ params }: LandingProps) {
  const { cat } = await params;
  const landing = await getLandingClases(cat);
  if (!landing) {
    notFound();
  }

  // JSON-LD — puerto exacto de nubira_breadcrumb_ld()/nubira_faq_ld() en
  // landing_categoria.php:161-171. Dominio hardcodeado a "https://nubira.cl" a propósito,
  // igual que el PHP real (no usa PHP_SITE_URL): un JSON-LD de producción debe apuntar
  // siempre al dominio canónico, sin importar contra qué entorno corra este build.
  const breadcrumbLd = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: "Inicio", item: "https://nubira.cl/explorar" },
      { "@type": "ListItem", position: 2, name: "Clases", item: "https://nubira.cl/servicios" },
      { "@type": "ListItem", position: 3, name: landing.categoria },
    ],
  };
  const faqLd =
    landing.faqs.length > 0
      ? {
          "@context": "https://schema.org",
          "@type": "FAQPage",
          mainEntity: landing.faqs.map((f) => ({
            "@type": "Question",
            name: f.pregunta,
            acceptedAnswer: { "@type": "Answer", text: f.respuesta },
          })),
        }
      : null;

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbLd) }} />
      {faqLd && <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(faqLd) }} />}

      <Header titulo={landing.categoria} />
      <main className="w-full max-w-[1600px] mx-auto px-4 md:px-8 pt-20 pb-24 lg:pb-10 lg:ml-64">
        <nav className="text-sm text-gray-500 mb-4" aria-label="Breadcrumb">
          <Link href="/" className="hover:text-gray-700">
            Inicio
          </Link>
          <span className="mx-1">/</span>
          <Link href="/servicios" className="hover:text-gray-700">
            Clases
          </Link>
          <span className="mx-1">/</span>
          <span className="text-gray-800 font-medium">{landing.categoria}</span>
        </nav>

        <header className="mb-4">
          <h1 className="text-2xl md:text-3xl font-bold text-gray-900 tracking-tight">{landing.seo.h1}</h1>
          {landing.seo.intro && (
            <p className="sr-only md:not-sr-only text-sm md:text-base text-gray-600 mt-2 max-w-3xl leading-relaxed">
              {landing.seo.intro}
            </p>
          )}
          {landing.total > 0 && (
            <p className="text-xs text-gray-400 mt-2 uppercase tracking-wide font-bold">
              {landing.total} resultado{landing.total === 1 ? "" : "s"}
            </p>
          )}
        </header>

        {landing.total > 0 ? (
          <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6 w-full">
            {landing.servicios.map((s) => (
              <ServicioCard key={s.id} servicio={s} />
            ))}
          </div>
        ) : (
          <div className="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-10 text-center text-gray-500">
            <p className="font-medium">Aún no hay clases de {landing.categoria} publicadas.</p>
            <Link href="/" className="inline-block mt-4 text-[#54A6D8] font-semibold hover:underline">
              Explorar todo &rarr;
            </Link>
          </div>
        )}

        {landing.faqs.length > 0 && (
          <section className="mt-10 max-w-3xl">
            <h2 className="text-xl md:text-2xl font-bold text-gray-900 mb-4">Preguntas frecuentes</h2>
            <div className="space-y-3">
              {landing.faqs.map((f) => (
                <details key={f.pregunta} className="bg-gray-50 border border-gray-100 rounded-2xl p-4">
                  <summary className="font-semibold text-gray-900 cursor-pointer">{f.pregunta}</summary>
                  <p className="text-sm text-gray-600 mt-2 leading-relaxed">{f.respuesta}</p>
                </details>
              ))}
            </div>
          </section>
        )}
      </main>
    </>
  );
}
