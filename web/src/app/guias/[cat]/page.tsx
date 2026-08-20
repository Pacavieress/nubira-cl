import type { Metadata } from "next";
import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { getGuiasHubCategoria } from "@/lib/api";
import { Header } from "@/components/Header";

interface CategoriaProps {
  params: Promise<{ cat: string }>;
}

// Puerto de app/guias.php MODO 2 (hub de categoría /guias/{slug}) — mismo gate real de
// "Para Tutores" (solo_tutores=1): sin sesión -> equivalente de /login?redir=..., con
// sesión pero sin ser tutor activo -> equivalente de /publicar-servicio (ver
// server/src/modules/guias/guias.controller.ts para el detalle completo del gate).
export async function generateMetadata({ params }: CategoriaProps): Promise<Metadata> {
  const { cat } = await params;
  const data = await getGuiasHubCategoria(cat);
  if (!data.encontrada) return {};

  return {
    title: `Guías de ${data.categoria.nombre} | Nubira`,
    description: data.categoria.descripcionCorta || `Guías y recursos sobre ${data.categoria.nombre} para estudiantes universitarios en Chile.`,
    robots: data.noindex ? "noindex, follow" : "index, follow",
  };
}

export default async function GuiaCategoriaPage({ params }: CategoriaProps) {
  const { cat } = await params;
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const data = await getGuiasHubCategoria(cat);

  if (!data.encontrada) {
    if (data.razon === "sin_sesion") {
      redirect(`${phpSiteUrl}/login?redir=${encodeURIComponent(`/guias/${cat}`)}`);
    }
    if (data.razon === "no_tutor") {
      redirect(`${phpSiteUrl}/publicar-servicio`);
    }
    notFound();
  }

  const { categoria, articulos } = data;
  // Puerto exacto de guias.php:96-99 — "Para Tutores" no es indexable en la práctica (el
  // gate de arriba siempre redirige a Googlebot), así que tampoco muestra breadcrumb.
  const mostrarBreadcrumb = !categoria.soloTutores;

  const breadcrumbLd = mostrarBreadcrumb
    ? {
        "@context": "https://schema.org",
        "@type": "BreadcrumbList",
        itemListElement: [
          { "@type": "ListItem", position: 1, name: "Inicio", item: "https://nubira.cl/explorar" },
          { "@type": "ListItem", position: 2, name: "Guías", item: "https://nubira.cl/guias" },
          { "@type": "ListItem", position: 3, name: categoria.nombre },
        ],
      }
    : null;

  return (
    <>
      {breadcrumbLd && <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbLd) }} />}

      <Header titulo={`Guías de ${categoria.nombre}`} />
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
            <span className="text-gray-800 font-medium">{categoria.nombre}</span>
          </nav>
        )}

        <header className="mb-6">
          <h1 className="text-2xl md:text-3xl font-bold text-gray-900 tracking-tight">Guías de {categoria.nombre}</h1>
          {categoria.descripcionCorta && (
            <p className="sr-only md:not-sr-only text-sm md:text-base text-gray-600 mt-2 max-w-3xl leading-relaxed">{categoria.descripcionCorta}</p>
          )}
        </header>

        {articulos.length > 0 ? (
          <div className="flex flex-col gap-3 w-full">
            {articulos.map((art) => (
              <Link
                key={art.slug}
                href={`/guias/${cat}/${art.slug}`}
                className="group flex items-center gap-4 p-3 sm:p-4 rounded-2xl border border-gray-100 hover:border-gray-200 hover:bg-gray-50/60 transition-colors duration-150 ease-out min-w-0"
              >
                {art.portadaCardUrl ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={art.portadaCardUrl} alt={art.titulo} className="w-20 h-20 sm:w-24 sm:h-24 rounded-xl object-cover shrink-0" />
                ) : (
                  <div className="w-20 h-20 sm:w-24 sm:h-24 rounded-xl bg-gray-50 border border-gray-100 flex items-center justify-center text-gray-300 shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-6 h-6">
                      <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"
                      />
                      <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z" />
                    </svg>
                  </div>
                )}
                <div className="min-w-0 flex-1">
                  <h2 className="text-base font-medium tracking-[-0.01em] text-[#222222] leading-snug line-clamp-2">{art.titulo}</h2>
                  {art.resumen && <p className="text-sm font-light tracking-[0.01em] text-gray-500 leading-relaxed line-clamp-2 mt-1">{art.resumen}</p>}
                </div>
              </Link>
            ))}
          </div>
        ) : (
          <div className="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-10 text-center text-gray-500">
            <p className="font-medium">Aún no hay guías publicadas en {categoria.nombre}.</p>
            <Link href="/guias" className="inline-block mt-4 text-[#54A6D8] font-semibold hover:underline">
              Ver todas las guías &rarr;
            </Link>
          </div>
        )}
      </main>
    </>
  );
}
