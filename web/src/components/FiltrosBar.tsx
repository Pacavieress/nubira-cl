"use client";

import { useRouter, useSearchParams } from "next/navigation";

// Valores reales confirmados en la BD (no inventados) — "Online"/"Híbrido" son los que
// existen hoy en servicios.modalidad; "Presencial" es el tercer valor que el propio
// código PHP reconoce (lógica de ícono en cargar_servicios.php/vitrina.php chequea
// online/presencial/otro), aunque no haya ningún servicio con ese valor ahora mismo.
const MODALIDADES = ["Online", "Presencial", "Híbrido"];

// Mismo criterio visual que los selects de app/busqueda.php:539-559 (pill redondeada,
// borde marcado cuando el filtro está activo) — no hay una UI de filtros en /servicios
// hoy, así que se reutiliza el lenguaje visual real que Nubira ya usa para esto en
// /busqueda, en vez de inventar un estilo nuevo.
function selectClass(activo: boolean): string {
  const base =
    "appearance-none pl-3 pr-7 py-1.5 text-xs font-bold rounded-full outline-none cursor-pointer focus:ring-2 focus:ring-gray-300 transition-all border";
  return activo ? `${base} bg-white border-gray-900 text-gray-900` : `${base} bg-white border-gray-200 text-gray-600`;
}

export function FiltrosBar({ categorias }: { categorias: string[] }) {
  const router = useRouter();
  const searchParams = useSearchParams();

  const categoriaActual = searchParams.get("categoria") ?? "";
  const modalidadActual = searchParams.get("modalidad") ?? "";

  function actualizarFiltro(clave: string, valor: string) {
    const params = new URLSearchParams(searchParams.toString());
    if (valor) {
      params.set(clave, valor);
    } else {
      params.delete(clave);
    }
    router.push(`/?${params.toString()}`);
  }

  return (
    <div className="flex items-center gap-2 overflow-x-auto no-scrollbar py-1 mb-4">
      <div className="relative shrink-0">
        <select
          value={categoriaActual}
          onChange={(e) => actualizarFiltro("categoria", e.target.value)}
          className={selectClass(categoriaActual !== "")}
        >
          <option value="">Toda categoría</option>
          {categorias.map((cat) => (
            <option key={cat} value={cat}>
              {cat}
            </option>
          ))}
        </select>
      </div>

      <div className="relative shrink-0">
        <select
          value={modalidadActual}
          onChange={(e) => actualizarFiltro("modalidad", e.target.value)}
          className={selectClass(modalidadActual !== "")}
        >
          <option value="">Toda modalidad</option>
          {MODALIDADES.map((mod) => (
            <option key={mod} value={mod}>
              {mod}
            </option>
          ))}
        </select>
      </div>
    </div>
  );
}
