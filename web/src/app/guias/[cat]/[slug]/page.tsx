import type { Metadata } from "next";
import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { getGuiaArticulo } from "@/lib/api";
import { Header } from "@/components/Header";
import { CtaGuiaApuntes, CtaGuiaTutores } from "@/components/CtaGuia";

interface ArticuloProps {
  params: Promise<{ cat: string; slug: string }>;
}

function truncar(texto: string, max: number): string {
  return texto.length > max ? `${texto.slice(0, max - 3)}...` : texto;
}

// Puerto de app/guia_post.php — mismo gate de "Para Tutores" que /guias/[cat] (ver
// server/src/modules/guias/guias.controller.ts::getArticuloDetalle). Sin
// nb_insertar_tras_primer_h2() (CTA de tutores insertado a mitad del cuerpo del
// artículo vía DOMDocument) — se renderiza como bloque propio junto al contenido
// relacionado, documentado en guias.types.ts. Prose vía utilidades arbitrarias de
// Tailwind ([&_h2]:..., etc.) en vez del plugin @tailwindcss/typography que usa el PHP
// real (?plugins=typography en el CDN): el whitelist real de tags del cuerpo
// (nb_sanitizar_html: p/h2/h3/ul/ol/li/strong/em/img/br/hr) es angosto y conocido, no
// vale la pena sumar una dependencia nueva (con riesgo de compatibilidad Tailwind v4,
// que web/ ya usa) solo para esos ~8 tags.
export async function generateMetadata({ params }: ArticuloProps): Promise<Metadata> {
  const { cat, slug } = await params;
  const data = await getGuiaArticulo(cat, slug);
  if (!data.encontrada) return {};

  const tituloCompleto = `${data.articulo.titulo} | Guías Nubira`;
  const descFuente = data.articulo.metaDescription || data.articulo.resumen || data.articulo.cuerpoHtml.replace(/<[^>]*>/g, "");

  return {
    title: truncar(tituloCompleto, 65),
    description: truncar(descFuente, 155),
  };
}

export default async function GuiaArticuloPage({ params }: ArticuloProps) {
  const { cat, slug } = await params;
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const data = await getGuiaArticulo(cat, slug);

  if (!data.encontrada) {
    if (data.razon === "sin_sesion") {
      redirect(`${phpSiteUrl}/login?redir=${encodeURIComponent(`/guias/${cat}/${slug}`)}`);
    }
    if (data.razon === "no_tutor") {
      redirect(`${phpSiteUrl}/publicar-servicio`);
    }
    notFound();
  }

  const { categoria, articulo, faqs, tutoresRelacionados, apuntesRelacionados, linkVerClases, linkVerApuntes, articulosRelacionados, mostrarBreadcrumb } = data;

  const urlCanonica = `https://nubira.cl/guias/${cat}/${slug}`;
  const articleLd = {
    "@context": "https://schema.org",
    "@type": "Article",
    headline: articulo.titulo,
    description: truncar(articulo.metaDescription || articulo.resumen || "", 155),
    author: { "@type": "Organization", name: articulo.autorNombre },
    publisher: { "@type": "Organization", name: "Nubira", logo: { "@type": "ImageObject", url: "https://nubira.cl/img/logo.webp" } },
    mainEntityOfPage: { "@type": "WebPage", "@id": urlCanonica },
    ...(articulo.portadaMainUrl ? { image: [articulo.portadaMainUrl] } : {}),
    ...(articulo.fechaPublicacion ? { datePublished: new Date(articulo.fechaPublicacion).toISOString() } : {}),
  };

  const faqLd =
    faqs.length > 0
      ? {
          "@context": "https://schema.org",
          "@type": "FAQPage",
          mainEntity: faqs.map((f) => ({ "@type": "Question", name: f.pregunta, acceptedAnswer: { "@type": "Answer", text: f.respuesta } })),
        }
      : null;

  const breadcrumbLd = mostrarBreadcrumb
    ? {
        "@context": "https://schema.org",
        "@type": "BreadcrumbList",
        itemListElement: [
          { "@type": "ListItem", position: 1, name: "Inicio", item: "https://nubira.cl/explorar" },
          { "@type": "ListItem", position: 2, name: "Guías", item: "https://nubira.cl/guias" },
          { "@type": "ListItem", position: 3, name: categoria.nombre, item: `https://nubira.cl/guias/${categoria.slug}` },
          { "@type": "ListItem", position: 4, name: articulo.titulo },
        ],
      }
    : null;

  const fechaLegible = articulo.fechaPublicacion
    ? new Date(articulo.fechaPublicacion).toLocaleDateString("es-CL", { day: "2-digit", month: "2-digit", year: "numeric" })
    : null;

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(articleLd) }} />
      {faqLd && <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(faqLd) }} />}
      {breadcrumbLd && <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbLd) }} />}

      <Header titulo={articulo.titulo} />
      <main className="w-full max-w-[1600px] mx-auto px-4 md:px-8 pt-20 pb-24 lg:pb-10 lg:ml-64">
        {mostrarBreadcrumb && (
          <nav className="text-sm text-gray-500 mb-4" aria-label="Breadcrumb">
            <Link href="/" className="hover:text-gray-700">
              Inicio
            </Link>
            <span className="mx-1">/</span>
            <Link href="/guias" className="hover:text-gray-700">
              Guías
            </Link>
            <span className="mx-1">/</span>
            <Link href={`/guias/${cat}`} className="hover:text-gray-700">
              {categoria.nombre}
            </Link>
          </nav>
        )}

        <div className="max-w-[900px] w-full">
          <header className="mb-6">
            <h1 className="text-2xl md:text-4xl font-medium text-[#222222] tracking-[-0.01em] leading-tight">{articulo.titulo}</h1>
            {articulo.resumen && <p className="text-base text-gray-600 mt-3 leading-relaxed">{articulo.resumen}</p>}
            <p className="text-xs text-gray-400 mt-3 uppercase tracking-wide font-bold">
              Por {articulo.autorNombre}
              {fechaLegible && ` · ${fechaLegible}`}
            </p>
          </header>

          {articulo.portadaMainUrl && (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={articulo.portadaMainUrl}
              alt={articulo.titulo}
              className="w-full aspect-video md:aspect-[21/9] rounded-2xl border border-gray-100 object-cover mb-8"
            />
          )}

          {/* Sin htmlspecialchars/escape: cuerpoHtml ya viene sanitizado por nb_sanitizar_html()
              del lado de PHP antes de guardarse (único punto de escritura, admin_guias.php,
              rol admin) — HTML de confianza, whitelisteado, se renderiza como HTML real. */}
          <article
            className="max-w-none [&_h2]:text-xl [&_h2]:md:text-2xl [&_h2]:font-medium [&_h2]:tracking-[-0.01em] [&_h2]:text-[#222222] [&_h2]:mt-8 [&_h2]:mb-3 [&_h3]:text-lg [&_h3]:font-medium [&_h3]:text-[#222222] [&_h3]:mt-6 [&_h3]:mb-2 [&_p]:text-base [&_p]:text-gray-700 [&_p]:leading-relaxed [&_p]:mb-4 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:mb-4 [&_ul]:space-y-1 [&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:mb-4 [&_ol]:space-y-1 [&_li]:text-gray-700 [&_strong]:font-semibold [&_strong]:text-[#222222] [&_a]:text-[#54A6D8] [&_a]:no-underline [&_a:hover]:underline [&_img]:rounded-xl [&_img]:my-4 [&_img]:w-full"
            dangerouslySetInnerHTML={{ __html: articulo.cuerpoHtml }}
          />

          {faqs.length > 0 && (
            <section className="mt-10 max-w-3xl">
              <h2 className="text-xl md:text-2xl font-bold text-gray-900 mb-4">Preguntas frecuentes</h2>
              <div className="space-y-3">
                {faqs.map((f) => (
                  <div key={f.pregunta} className="bg-gray-50 border border-gray-100 rounded-xl p-4">
                    <p className="font-bold text-gray-900 text-sm mb-1">{f.pregunta}</p>
                    <p className="text-sm text-gray-600 leading-relaxed">{f.respuesta}</p>
                  </div>
                ))}
              </div>
            </section>
          )}

          {(tutoresRelacionados.length > 0 || apuntesRelacionados.length > 0) && (
            <section className="mt-10 border-t border-gray-100 pt-8">
              <h2 className="text-xl font-bold text-gray-900 mb-4">Tutores y recursos relacionados</h2>

              {tutoresRelacionados.length > 0 && (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                  {tutoresRelacionados.map((t) => (
                    <a key={t.id} href={t.url} className="flex items-center gap-3 bg-white border border-gray-100 rounded-xl p-3 hover:shadow-md transition-all min-w-0">
                      <div className="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center text-[#54A6D8] font-bold text-sm shrink-0 overflow-hidden">
                        {t.fotoUrl ? (
                          // eslint-disable-next-line @next/next/no-img-element
                          <img src={t.fotoUrl} alt="" className="w-full h-full object-cover" />
                        ) : (
                          t.nombreTutor.charAt(0).toUpperCase()
                        )}
                      </div>
                      <div className="min-w-0">
                        <p className="text-sm font-bold text-gray-900 truncate">{t.titulo}</p>
                        <p className="text-xs text-gray-400 truncate">{t.institucion}</p>
                      </div>
                    </a>
                  ))}
                </div>
              )}

              {linkVerClases && (
                <a href={`/clases/${linkVerClases}`} className="inline-flex items-center gap-1.5 text-sm font-bold text-[#54A6D8] hover:underline mb-4">
                  Ver todas las clases de {categoria.nombre}
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3 h-3">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                  </svg>
                </a>
              )}

              {apuntesRelacionados.length > 0 && (
                <div className="mt-2">
                  <p className="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Apuntes relacionados</p>
                  <ul className="space-y-1">
                    {apuntesRelacionados.map((ap) => (
                      <li key={ap.id}>
                        <Link href={`/apunte/${ap.id}`} className="text-sm text-[#54A6D8] hover:underline">
                          {ap.titulo}
                        </Link>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </section>
          )}

          <CtaGuiaTutores
            cantidad={tutoresRelacionados.length}
            categoria={categoria.nombre}
            link={linkVerClases}
            avatares={tutoresRelacionados.map((t) => t.fotoUrl).filter((u): u is string => !!u)}
          />
          <CtaGuiaApuntes cantidad={apuntesRelacionados.length} categoria={categoria.nombre} link={linkVerApuntes} />

          {articulosRelacionados.length > 0 && (
            <section className="mt-10 border-t border-gray-100 pt-8">
              <h2 className="text-xl font-bold text-gray-900 mb-4">Más guías de {categoria.nombre}</h2>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                {articulosRelacionados.map((rel) => (
                  <Link key={rel.slug} href={`/guias/${cat}/${rel.slug}`} className="block bg-white border border-gray-100 rounded-xl overflow-hidden hover:shadow-md transition-all min-w-0">
                    {rel.portadaThumbUrl && (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img src={rel.portadaThumbUrl} alt="" className="w-full h-24 object-cover" />
                    )}
                    <p className="text-sm font-bold text-gray-900 p-3 leading-snug">{rel.titulo}</p>
                  </Link>
                ))}
              </div>
            </section>
          )}
        </div>
      </main>
    </>
  );
}
