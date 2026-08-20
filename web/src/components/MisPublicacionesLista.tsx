"use client";

import { useState } from "react";
import Link from "next/link";
import type { ApuntePublicado, ServicioPublicado } from "@/lib/api";
import { formatoCLP } from "@/lib/formato";

// Puerto de app/mis_servicios.php:180-326 — acordeón cerrado por defecto, mismos 2
// grupos. Las 3 acciones reales (eliminar_servicio/reactivar_servicio/eliminar_apunte,
// líneas 24-82) SÍ se portan acá — a diferencia de la pieza de /clases-vendidas y
// /ventas-apuntes, estas confirmadas como soft-delete real (`UPDATE ... SET visible = 0` /
// `estado = 'aprobado'`, ver server/src/modules/misPublicaciones/misPublicaciones.repository.ts),
// no un DELETE permanente — no había motivo para pausar y preguntar acá.
//
// Las mutaciones pasan por 3 Route Handlers same-origin de web/ (web/src/app/api/mis-
// publicaciones/...), no por un fetch directo del navegador a server/ — evita abrir
// CORS_ORIGIN solo para esto (ver el comentario de esos route.ts para el porqué completo).
//
// "Ver publicación" de un servicio enlaza INTERNO a /servicios/{id} (ya construido en esta
// misma migración, mismo contenido) en vez del link externo url_servicio() del PHP real —
// mejora deliberada (mantiene al usuario dentro del piloto para algo que web/ ya sabe
// renderizar), documentada, no un descuido. "Ver documento" de un apunte SÍ sigue siendo
// externo (/ver-apunte?archivo=...) porque esa es una vista de archivo crudo, no la misma
// página que /apunte/{id} — no hay certeza de que sean equivalentes, así que se mantiene
// fiel al link real en vez de asumir.

const ESTADO_COLOR: Record<string, string> = {
  aprobado: "text-emerald-500 bg-emerald-50 border border-emerald-100",
  pendiente: "text-amber-500 bg-amber-50 border border-amber-100",
  rechazado: "text-red-500 bg-red-50 border border-red-100",
  pausado: "text-orange-600 bg-orange-100 border border-orange-200",
};

function IconoOjo() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3.5 h-3.5">
      <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
      <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    </svg>
  );
}

function IconoLapiz() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3.5 h-3.5">
      <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
      <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 19.5H4.5V4.5" />
    </svg>
  );
}

function IconoBasura() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3.5 h-3.5">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
      />
    </svg>
  );
}

function IconoPlay() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" className="w-2.5 h-2.5">
      <path d="M8 5v14l11-7z" />
    </svg>
  );
}

function FilaServicio({ servicio, onEliminar, onReactivar, phpSiteUrl }: { servicio: ServicioPublicado; onEliminar: (id: number) => void; onReactivar: (id: number) => void; phpSiteUrl: string }) {
  const estaPausado = servicio.estado === "pausado";
  const claseEstado = ESTADO_COLOR[servicio.estado] ?? "text-gray-500 bg-gray-50 border border-gray-100";

  return (
    <li className="flex items-center justify-between p-4 gap-3">
      <div className="flex items-center gap-3 flex-1 min-w-0">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={servicio.imagenUrl} alt="" className="w-12 h-12 rounded-xl object-cover shrink-0 border border-gray-100 bg-gray-100" />
        <div className="flex-1 min-w-0">
          <h3 className="font-medium text-[#222222] text-[14px] line-clamp-1 leading-tight mb-0.5">{servicio.titulo}</h3>
          <div className="flex items-center gap-1.5 text-[11px] text-gray-400 font-medium truncate">
            <span className={`${claseEstado} px-1.5 py-0.5 rounded text-[10px] font-medium uppercase tracking-wide`}>{servicio.estado}</span>
            <span>•</span>
            <span className="truncate">{servicio.modalidad}</span>
          </div>
        </div>
      </div>

      <div className="flex flex-col items-end gap-1.5 shrink-0 pl-2">
        <span className="font-medium text-[#222222] text-[15px] tabular-nums tracking-[-0.01em] leading-none text-right">
          {servicio.precio && servicio.precio > 0 ? formatoCLP(servicio.precio) : "Gratis"}
        </span>

        <div className="flex justify-end items-center gap-1 bg-gray-100 p-1 rounded-full">
          {estaPausado ? (
            <button
              type="button"
              onClick={() => onReactivar(servicio.id)}
              className="flex items-center gap-1.5 bg-[#54A6D8] text-white px-3 py-1 rounded-full text-[11px] font-bold hover:bg-blue-600 transition-colors"
              title="Reactivar publicación"
            >
              <IconoPlay /> Reactivar
            </button>
          ) : (
            <>
              <Link href={`/servicios/${servicio.id}`} className="w-7 h-7 flex items-center justify-center rounded-full text-gray-500 hover:bg-white transition" title="Ver publicación">
                <IconoOjo />
              </Link>
              <a
                href={`${phpSiteUrl}/app/editar_servicio.php?id=${servicio.id}`}
                target="_blank"
                rel="noopener noreferrer"
                className="w-7 h-7 flex items-center justify-center rounded-full text-gray-500 hover:bg-white hover:text-[#54A6D8] transition"
                title="Editar"
              >
                <IconoLapiz />
              </a>
              <button
                type="button"
                onClick={() => {
                  if (confirm("¿Eliminar esta clase definitivamente?")) onEliminar(servicio.id);
                }}
                className="w-7 h-7 flex items-center justify-center rounded-full text-red-400 hover:bg-white hover:text-red-600 transition"
                title="Eliminar"
              >
                <IconoBasura />
              </button>
            </>
          )}
        </div>
      </div>
    </li>
  );
}

function FilaApunte({ apunte, onEliminar, phpSiteUrl }: { apunte: ApuntePublicado; onEliminar: (id: number) => void; phpSiteUrl: string }) {
  return (
    <li className="flex items-center justify-between p-4 gap-3">
      <div className="flex items-center gap-3 flex-1 min-w-0">
        <div className="w-12 h-12 rounded-xl bg-red-50 text-red-500 flex flex-col items-center justify-center shrink-0 border border-red-100">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5">
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"
            />
          </svg>
          <span className="text-[7px] font-black uppercase tracking-widest opacity-60 mt-0.5">PDF</span>
        </div>
        <div className="flex-1 min-w-0">
          <h3 className="font-medium text-[#222222] text-[14px] line-clamp-1 leading-tight mb-0.5">{apunte.titulo}</h3>
          <div className="flex items-center gap-1.5 text-[11px] text-gray-400 font-medium truncate">
            <span
              className={`${
                apunte.esPublico ? "text-emerald-500 bg-emerald-50 border border-emerald-100" : "text-amber-500 bg-amber-50 border border-amber-100"
              } px-1.5 py-0.5 rounded text-[10px] font-medium uppercase tracking-wide`}
            >
              {apunte.esPublico ? "Visible" : "Pendiente"}
            </span>
            <span>•</span>
            <span className="truncate">General</span>
          </div>
        </div>
      </div>

      <div className="flex flex-col items-end gap-1.5 shrink-0 pl-2">
        <span className="font-medium text-[#222222] text-[15px] tabular-nums tracking-[-0.01em] leading-none text-right">
          {apunte.precio && apunte.precio > 0 ? formatoCLP(apunte.precio) : "Gratis"}
        </span>

        <div className="flex justify-end items-center gap-1 bg-gray-100 p-1 rounded-full">
          <a
            href={`${phpSiteUrl}/ver-apunte?archivo=${encodeURIComponent(apunte.archivo ?? "")}`}
            target="_blank"
            rel="noopener noreferrer"
            className="w-7 h-7 flex items-center justify-center rounded-full text-gray-500 hover:bg-white transition"
            title="Ver documento"
          >
            <IconoOjo />
          </a>
          <a
            href={`${phpSiteUrl}/app/editar_apunte.php?id=${apunte.id}`}
            target="_blank"
            rel="noopener noreferrer"
            className="w-7 h-7 flex items-center justify-center rounded-full text-gray-500 hover:bg-white hover:text-[#54A6D8] transition"
            title="Editar"
          >
            <IconoLapiz />
          </a>
          <button
            type="button"
            onClick={() => {
              if (confirm("¿Eliminar este apunte definitivamente?")) onEliminar(apunte.id);
            }}
            className="w-7 h-7 flex items-center justify-center rounded-full text-red-400 hover:bg-white hover:text-red-600 transition"
            title="Eliminar"
          >
            <IconoBasura />
          </button>
        </div>
      </div>
    </li>
  );
}

export function MisPublicacionesLista({
  serviciosIniciales,
  apuntesIniciales,
  phpSiteUrl,
}: {
  serviciosIniciales: ServicioPublicado[];
  apuntesIniciales: ApuntePublicado[];
  phpSiteUrl: string;
}) {
  const [servicios, setServicios] = useState(serviciosIniciales);
  const [apuntes, setApuntes] = useState(apuntesIniciales);
  const [abiertoServicios, setAbiertoServicios] = useState(false);
  const [abiertoApuntes, setAbiertoApuntes] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function eliminarServicio(id: number) {
    setError(null);
    const anterior = servicios;
    setServicios((prev) => prev.filter((s) => s.id !== id));
    const res = await fetch(`/api/mis-publicaciones/servicios/${id}`, { method: "DELETE" });
    if (!res.ok) {
      setServicios(anterior);
      setError("No pudimos eliminar la publicación. Intenta de nuevo.");
    }
  }

  async function reactivarServicio(id: number) {
    setError(null);
    const anterior = servicios;
    setServicios((prev) => prev.map((s) => (s.id === id ? { ...s, estado: "aprobado" } : s)));
    const res = await fetch(`/api/mis-publicaciones/servicios/${id}/reactivar`, { method: "POST" });
    if (!res.ok) {
      setServicios(anterior);
      setError("No pudimos reactivar la publicación. Intenta de nuevo.");
    }
  }

  async function eliminarApunte(id: number) {
    setError(null);
    const anterior = apuntes;
    setApuntes((prev) => prev.filter((a) => a.id !== id));
    const res = await fetch(`/api/mis-publicaciones/apuntes/${id}`, { method: "DELETE" });
    if (!res.ok) {
      setApuntes(anterior);
      setError("No pudimos eliminar el apunte. Intenta de nuevo.");
    }
  }

  return (
    <div className="space-y-2 mt-2">
      {error && (
        <div className="bg-red-50 border border-red-100 text-red-600 text-sm font-medium rounded-xl px-4 py-3">{error}</div>
      )}

      <div className="space-y-1">
        <button type="button" onClick={() => setAbiertoServicios((v) => !v)} className="w-full px-1 pt-4 pb-2 flex items-center justify-between">
          <h2 className="text-xs font-bold text-gray-400 uppercase tracking-widest">Clases o Servicios ({servicios.length})</h2>
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            strokeWidth={2}
            stroke="currentColor"
            className={`w-3 h-3 text-gray-400 transition-transform duration-300 ${abiertoServicios ? "rotate-180" : ""}`}
          >
            <path strokeLinecap="round" strokeLinejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
          </svg>
        </button>

        {abiertoServicios && (
          <div className="bg-white border border-[#f0f0f0] rounded-2xl shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
            {servicios.length > 0 ? (
              <ul className="divide-y divide-gray-100">
                {servicios.map((s) => (
                  <FilaServicio key={s.id} servicio={s} onEliminar={eliminarServicio} onReactivar={reactivarServicio} phpSiteUrl={phpSiteUrl} />
                ))}
              </ul>
            ) : (
              <div className="bg-gray-50 p-6 text-center rounded-2xl">
                <p className="text-sm font-medium text-gray-400">No ofreces clases aún.</p>
              </div>
            )}
          </div>
        )}
      </div>

      <div className="space-y-1 mt-4">
        <button type="button" onClick={() => setAbiertoApuntes((v) => !v)} className="w-full px-1 pt-4 pb-2 flex items-center justify-between">
          <h2 className="text-xs font-bold text-gray-400 uppercase tracking-widest">Apuntes ({apuntes.length})</h2>
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            strokeWidth={2}
            stroke="currentColor"
            className={`w-3 h-3 text-gray-400 transition-transform duration-300 ${abiertoApuntes ? "rotate-180" : ""}`}
          >
            <path strokeLinecap="round" strokeLinejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
          </svg>
        </button>

        {abiertoApuntes && (
          <div className="bg-white border border-[#f0f0f0] rounded-2xl shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
            {apuntes.length > 0 ? (
              <ul className="divide-y divide-gray-100">
                {apuntes.map((a) => (
                  <FilaApunte key={a.id} apunte={a} onEliminar={eliminarApunte} phpSiteUrl={phpSiteUrl} />
                ))}
              </ul>
            ) : (
              <div className="bg-gray-50 p-6 text-center rounded-2xl">
                <p className="text-sm font-medium text-gray-400">No tienes apuntes subidos.</p>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
