import type { Metadata } from "next";
import Link from "next/link";
import { getGuiasHubGeneral } from "@/lib/api";
import { Header } from "@/components/Header";

// Puerto de app/guias.php MODO 1 (hub general /guias) — solo categorías habilitadas,
// no-solo_tutores, CON al menos 1 artículo publicado (mismo INNER JOIN real: una
// categoría habilitada pero sin artículos no aparece acá). "Para Tutores" nunca aparece
// en este hub sin importar sesión — tiene su acceso directo propio
// (/guias/para-tutores, tile del panel de gestión), no es un ítem más del catálogo
// público (mismo criterio documentado en guias.php:22-26).
export async function generateMetadata(): Promise<Metadata> {
  const categorias = await getGuiasHubGeneral();
  return {
    title: "Centro de Recursos | Nubira",
    description:
      "Guías, estrategias de estudio y recursos para estudiantes universitarios chilenos: Matemáticas, PAES, métodos de estudio y becas.",
    robots: categorias.length === 0 ? "noindex, follow" : "index, follow",
  };
}

export default async function GuiasHubPage() {
  const categorias = await getGuiasHubGeneral();

  const breadcrumbLd = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: "Inicio", item: "https://nubira.cl/explorar" },
      { "@type": "ListItem", position: 2, name: "Guías" },
    ],
  };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbLd) }} />

      <Header titulo="Centro de Recursos" />
      <main className="w-full max-w-[1600px] mx-auto px-4 md:px-8 pt-20 pb-24 lg:pb-10 lg:ml-64">
        <nav className="text-sm text-gray-500 mb-4" aria-label="Breadcrumb">
          <Link href="/" className="hover:text-gray-700">
            Inicio
          </Link>
          <span className="mx-1">/</span>
          <span className="text-gray-800 font-medium">Guías</span>
        </nav>

        <header className="mb-6">
          <h1 className="text-2xl md:text-3xl font-bold text-gray-900 tracking-tight">Centro de Recursos</h1>
          <p className="sr-only md:not-sr-only text-sm md:text-base text-gray-600 mt-2 max-w-3xl leading-relaxed">
            Guías y recursos para ayudarte a rendir mejor en la universidad.
          </p>
          {categorias.length > 0 && <p className="text-xs text-gray-400 mt-1">Más categorías y guías próximamente.</p>}
        </header>

        {categorias.length > 0 ? (
          <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 md:gap-6 w-full">
            {categorias.map((cat) => (
              <Link
                key={cat.slug}
                href={`/guias/${cat.slug}`}
                className="group relative block overflow-hidden rounded-2xl p-6 pl-7 bg-[#54A6D8]/[0.06] border border-[#54A6D8]/10 hover:bg-[#54A6D8]/10 hover:border-[#54A6D8]/30 transition-colors duration-150 ease-out min-w-0"
              >
                <span className="absolute left-0 top-0 bottom-0 w-[5px] bg-[#54A6D8]" />
                <h2 className="text-lg font-extrabold text-gray-900 tracking-tight mb-1.5">{cat.nombre}</h2>
                {cat.descripcionCorta && <p className="text-sm text-gray-600 leading-relaxed">{cat.descripcionCorta}</p>}
                <div className="flex items-center justify-between mt-6">
                  <span className="text-xs font-bold text-[#2f84ba] uppercase tracking-wide">
                    {cat.totalArticulos} artículo{cat.totalArticulos === 1 ? "" : "s"}
                  </span>
                  <span className="text-xs font-semibold text-gray-500 group-hover:text-[#2f84ba] transition-colors duration-150 ease-out">
                    Ver guías &rarr;
                  </span>
                </div>
              </Link>
            ))}
          </div>
        ) : (
          <div className="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-10 text-center text-gray-500">
            <p className="font-medium">Aún no hay guías publicadas.</p>
          </div>
        )}
      </main>
    </>
  );
}
