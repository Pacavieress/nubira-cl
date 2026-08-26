import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { getLandingApuntes } from "@/lib/api";
import { Header } from "@/components/Header";
import { ApunteCard } from "@/components/ApunteCard";

interface LandingProps {
  params: Promise<{ cat: string }>;
}

// Puerto de app/landing_categoria.php — tipo=apuntes, cierra la asimetría con
// clases/[cat]/page.tsx (mismo archivo PHP real, misma estructura de página — ver
// server/src/modules/landings/landings.types.ts para el historial de por qué esto quedó
// fuera antes y por qué esa razón ya no aplica). Prácticamente idéntico a
// clases/[cat]/page.tsx a propósito: es el mismo motor con `apuntes`/`ApunteCard` en vez
// de `servicios`/`ServicioCard` — no se buscó diferenciar donde el PHP real no diferencia.
export async function generateMetadata({ params }: LandingProps): Promise<Metadata> {
  const { cat } = await params;
  const landing = await getLandingApuntes(cat);
  if (!landing) return {};

  return {
    title: landing.seo.titulo,
    description: landing.seo.descripcion,
    robots: landing.seo.noindex ? "noindex, follow" : "index, follow",
  };
}

export default async function LandingApuntesPage({ params }: LandingProps) {
  const { cat } = await params;
  const landing = await getLandingApuntes(cat);
  if (!landing) {
    notFound();
  }

  // JSON-LD — puerto exacto de nubira_breadcrumb_ld()/nubira_faq_ld() en
  // landing_categoria.php:161-171. Dominio hardcodeado a "https://nubira.cl" a propósito,
  // mismo criterio que clases/[cat]/page.tsx.
  const breadcrumbLd = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: "Inicio", item: "https://nubira.cl/explorar" },
      { "@type": "ListItem", position: 2, name: "Apuntes", item: "https://nubira.cl/apuntes" },
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
      {/* lg:pl-64 en vez de lg:ml-64 — overflow horizontal bajo <body flex flex-col>, ver
          web/src/app/apuntes/page.tsx para el diagnóstico completo. */}
      <main className="w-full max-w-[1600px] mx-auto px-4 md:px-8 pt-20 pb-24 lg:pb-10 lg:pl-64">
        <nav className="text-sm text-gray-500 mb-4" aria-label="Breadcrumb">
          <Link href="/" className="hover:text-gray-700">
            Inicio
          </Link>
          <span className="mx-1">/</span>
          <Link href="/apuntes" className="hover:text-gray-700">
            Apuntes
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
            {landing.apuntes.map((a) => (
              <ApunteCard key={a.id} apunte={a} />
            ))}
          </div>
        ) : (
          <div className="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-10 text-center text-gray-500">
            <p className="font-medium">Aún no hay apuntes de {landing.categoria} publicados.</p>
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
