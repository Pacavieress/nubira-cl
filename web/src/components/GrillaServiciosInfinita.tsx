"use client";

import { useEffect, useRef, useState } from "react";
import type { ServicioListado, ServiciosResponse } from "@/lib/api";
import { ServicioCard } from "./ServicioCard";

const LIMITE = 12;

interface GrillaServiciosInfinitaProps {
  itemsIniciales: ServicioListado[];
  hayMasInicial: boolean;
  filtros: { categoria?: string; q?: string };
}

// Puerto de clases_servicios.php:396-423 (scroll infinito por IntersectionObserver) — el
// primer lote llega por props (SSR real, ver servicios/page.tsx), este componente solo
// agrega lotes siguientes al hacer scroll, sin re-fetchear el primero.
//
// No recibe los filtros como state propio ni los sincroniza con un useEffect: page.tsx
// remonta este componente con una `key` nueva cada vez que cambian (categoría/búsqueda), así
// que cada instancia nace y muere atada a un único filtro — más simple que reconciliar un
// cambio de filtro a mitad de una paginación en curso.
export function GrillaServiciosInfinita({ itemsIniciales, hayMasInicial, filtros }: GrillaServiciosInfinitaProps) {
  const [items, setItems] = useState(itemsIniciales);
  const [pagina, setPagina] = useState(1);
  const [hayMas, setHayMas] = useState(hayMasInicial);
  const [cargando, setCargando] = useState(false);
  const centinelaRef = useRef<HTMLDivElement>(null);
  // Guard anti-duplicado — mismo mecanismo que clases_servicios.php:397 (`let cargando =
  // false`, variable síncrona, no state de framework): un ref porque el state de React es
  // asíncrono/batcheado, así que dos disparos del observer muy seguidos podrían leer el
  // mismo `cargando=false` desde el render viejo antes de que el primer setState se refleje.
  // El ref se lee/escribe en el momento, sin esperar un re-render.
  const cargandoRef = useRef(false);

  useEffect(() => {
    if (!hayMas) return;
    const centinela = centinelaRef.current;
    if (!centinela) return;

    const observer = new IntersectionObserver((entries) => {
      const entrada = entries[0];
      if (!entrada?.isIntersecting || cargandoRef.current) return;
      cargandoRef.current = true;
      setCargando(true);

      const siguientePagina = pagina + 1;
      const params = new URLSearchParams();
      if (filtros.categoria) params.set("categoria", filtros.categoria);
      if (filtros.q) params.set("q", filtros.q);
      params.set("page", String(siguientePagina));
      params.set("limit", String(LIMITE));

      fetch(`/api/servicios?${params.toString()}`)
        .then((res) => res.json())
        .then((data: ServiciosResponse) => {
          setItems((prev) => [...prev, ...data.data]);
          setPagina(siguientePagina);
          setHayMas(data.meta.hayMas);
        })
        .catch(() => {
          // Mismo criterio de tolerancia que clases_servicios.php:414-424: si un lote
          // falla, no se agrega nada y el observer sigue vivo — un próximo cruce del
          // centinela (el usuario sigue scrolleando) reintenta solo.
        })
        .finally(() => {
          cargandoRef.current = false;
          setCargando(false);
        });
    });

    observer.observe(centinela);
    return () => observer.disconnect();
  }, [pagina, hayMas, filtros.categoria, filtros.q]);

  if (items.length === 0) {
    // Calcado de app/cargar_servicios.php:166 (estado vacío) — se movió acá desde
    // servicios/page.tsx porque este componente ahora es dueño de la lista completa,
    // incluido el caso "0 resultados desde el primer lote".
    return (
      <div className="flex flex-col items-center justify-center text-center py-12 text-gray-400">
        <svg className="w-10 h-10 mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={1.5}
            d="M4 4h16v10.5a2 2 0 01-2 2H6a2 2 0 01-2-2V4zM4 14.5h4l1.5 2h5l1.5-2h4"
          />
        </svg>
        <p className="text-sm">No encontramos servicios con estos filtros.</p>
      </div>
    );
  }

  return (
    <>
      <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6 w-full">
        {items.map((servicio) => (
          <ServicioCard key={servicio.id} servicio={servicio} />
        ))}
      </div>
      {hayMas && <div ref={centinelaRef} aria-hidden="true" />}
      {cargando && (
        <div className="flex justify-center py-6">
          <div className="animate-spin h-6 w-6 border-4 border-blue-200 border-t-[#54A6D8] rounded-full" />
        </div>
      )}
    </>
  );
}
