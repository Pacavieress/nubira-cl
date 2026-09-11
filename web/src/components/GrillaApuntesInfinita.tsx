"use client";

import { useEffect, useRef, useState } from "react";
import type { ApunteListado, ApuntesResponse } from "@/lib/api";
import { ApunteCard } from "./ApunteCard";

const LIMITE = 12;

interface GrillaApuntesInfinitaProps {
  itemsIniciales: ApunteListado[];
  hayMasInicial: boolean;
  filtros: { nivel?: string; precio?: "gratis" | "pagado"; orden?: string; q?: string; categoria?: string };
}

// Espejo exacto de GrillaServiciosInfinita.tsx (mismo patrón, misma razón de cada decisión
// — ver ese archivo) para /apuntes. Única diferencia real: ApunteCard en vez de ServicioCard
// y las clases de grilla propias de apuntes/page.tsx (gap-8, no gap-6).
export function GrillaApuntesInfinita({ itemsIniciales, hayMasInicial, filtros }: GrillaApuntesInfinitaProps) {
  const [items, setItems] = useState(itemsIniciales);
  const [pagina, setPagina] = useState(1);
  const [hayMas, setHayMas] = useState(hayMasInicial);
  const [cargando, setCargando] = useState(false);
  const centinelaRef = useRef<HTMLDivElement>(null);
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
      if (filtros.nivel) params.set("nivel", filtros.nivel);
      if (filtros.precio) params.set("precio", filtros.precio);
      if (filtros.orden) params.set("orden", filtros.orden);
      if (filtros.q) params.set("q", filtros.q);
      if (filtros.categoria) params.set("categoria", filtros.categoria);
      params.set("page", String(siguientePagina));
      params.set("limit", String(LIMITE));

      fetch(`/api/apuntes?${params.toString()}`)
        .then((res) => res.json())
        .then((data: ApuntesResponse) => {
          setItems((prev) => [...prev, ...data.data]);
          setPagina(siguientePagina);
          setHayMas(data.meta.hayMas);
        })
        .catch(() => {
          // Mismo criterio de tolerancia que clases_servicios.php:414-424 (ver
          // GrillaServiciosInfinita.tsx): si un lote falla, no se agrega nada, el observer
          // sigue vivo y un próximo cruce del centinela reintenta solo.
        })
        .finally(() => {
          cargandoRef.current = false;
          setCargando(false);
        });
    });

    observer.observe(centinela);
    return () => observer.disconnect();
  }, [pagina, hayMas, filtros.nivel, filtros.precio, filtros.orden, filtros.q, filtros.categoria]);

  if (items.length === 0) {
    // Calcado del estado vacío en cargar_apuntes.php:199 — se movió acá desde
    // apuntes/page.tsx, mismo motivo que en GrillaServiciosInfinita.tsx.
    return (
      <div className="flex flex-col items-center justify-center text-center py-12 text-gray-400">
        <svg className="w-10 h-10 mb-3 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={1.5}
            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
          />
        </svg>
        <p className="text-sm">No hay apuntes disponibles.</p>
      </div>
    );
  }

  return (
    <>
      <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-8 w-full">
        {items.map((apunte) => (
          <ApunteCard key={apunte.id} apunte={apunte} />
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
