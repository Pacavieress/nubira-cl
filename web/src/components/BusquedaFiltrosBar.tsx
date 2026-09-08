"use client";

import { useRouter, useSearchParams } from "next/navigation";

// Puerto exacto de busqueda.php:562-567 — mismas 4 opciones, mismo orden.
const ORDENES = [
  { value: "", label: "Recomendados" },
  { value: "calificacion", label: "Mejor Calificados" },
  { value: "precio_desc", label: "Mayor Precio" },
  { value: "precio_asc", label: "Menor Precio" },
];

function ChevronDown({ className }: { className?: string }) {
  return (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={3}>
      <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
    </svg>
  );
}

// Puerto de la función irA() global de busqueda.php:928-934 — navega de inmediato al
// cambiar de valor, preservando el resto de los filtros y reseteando "pagina" (cambiar de
// orden/categoría invalida la página en la que estaba el usuario).
export function BusquedaFiltrosBar({ categorias }: { categorias: string[] }) {
  const router = useRouter();
  const searchParams = useSearchParams();

  const orden = searchParams.get("orden") ?? "";
  const categoria = searchParams.get("categoria") ?? "";

  function irA(param: string, valor: string) {
    const params = new URLSearchParams(searchParams.toString());
    if (valor === "") {
      params.delete(param);
    } else {
      params.set(param, valor);
    }
    params.delete("pagina");
    router.push(`/busqueda?${params.toString()}`);
  }

  return (
    <>
      <div className="relative shrink-0">
        <select
          value={orden}
          onChange={(e) => irA("orden", e.target.value)}
          className="appearance-none pl-3 pr-7 py-1.5 text-xs font-bold bg-gray-900 text-white border border-gray-900 rounded-full outline-none cursor-pointer focus:ring-2 focus:ring-gray-300 transition-all"
        >
          {ORDENES.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
        <div className="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-white">
          <ChevronDown className="w-2.5 h-2.5" />
        </div>
      </div>

      <div className="h-4 w-px bg-gray-200 shrink-0 mx-0.5" />

      <div className="relative shrink-0">
        <select
          value={categoria}
          onChange={(e) => irA("categoria", e.target.value)}
          className={`appearance-none pl-3 pr-7 py-1.5 text-xs font-bold bg-white border rounded-full outline-none cursor-pointer focus:ring-2 focus:ring-gray-300 transition-all ${
            categoria ? "border-gray-900 text-gray-900" : "border-gray-200 text-gray-600"
          }`}
        >
          <option value="">Toda categoría</option>
          {categorias.map((cat) => (
            <option key={cat} value={cat}>
              {cat}
            </option>
          ))}
        </select>
        <div
          className={`pointer-events-none absolute inset-y-0 right-2.5 flex items-center ${categoria ? "text-gray-900" : "text-gray-400"}`}
        >
          <ChevronDown className="w-2.5 h-2.5" />
        </div>
      </div>
    </>
  );
}
