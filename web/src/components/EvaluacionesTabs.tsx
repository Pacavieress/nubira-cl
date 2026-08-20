"use client";

import { useState } from "react";
import type { EvaluacionRecibida } from "@/lib/api";

// Puerto de las 2 pestañas de app/mis_evaluaciones.php:151-266 (Tutor/Estudiante,
// mismo texto de tabs, misma pestaña "Tutor" abierta por defecto). Sin foto de evaluador
// (ver evaluaciones.types.ts en server/ — nunca está poblada en el PHP real tampoco) —
// siempre avatar de inicial, mismo resultado visible que hoy en producción.
function Estrellas({ calificacion }: { calificacion: number }) {
  return (
    <div className="flex text-amber-400 text-xs gap-0.5" aria-label={`${calificacion} de 5 estrellas`}>
      {[1, 2, 3, 4, 5].map((i) => (
        <svg key={i} viewBox="0 0 20 20" fill="currentColor" className={`w-3 h-3 ${i > calificacion ? "text-gray-200" : ""}`}>
          <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.957a1 1 0 0 0 .95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.368 2.447a1 1 0 0 0-.364 1.118l1.287 3.957c.3.922-.755 1.688-1.54 1.118l-3.367-2.447a1 1 0 0 0-1.176 0l-3.367 2.447c-.784.57-1.838-.196-1.539-1.118l1.286-3.957a1 1 0 0 0-.363-1.118L2.98 9.384c-.783-.57-.38-1.81.588-1.81h4.163a1 1 0 0 0 .95-.69z" />
        </svg>
      ))}
    </div>
  );
}

function fechaSegura(fecha: string): string {
  return new Date(fecha).toLocaleDateString("es-CL", { day: "2-digit", month: "short", year: "numeric" });
}

function ListaEvaluaciones({ items, vacioTexto }: { items: EvaluacionRecibida[]; vacioTexto: string }) {
  if (items.length === 0) {
    return (
      <div className="text-center py-12 px-4">
        <div className="w-12 h-12 bg-gray-50 rounded-full flex items-center justify-center mx-auto mb-3 text-gray-300">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-6 h-6">
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.563.563 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.563.563 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z"
            />
          </svg>
        </div>
        <h3 className="text-sm font-bold text-gray-800">Sin evaluaciones</h3>
        <p className="text-xs font-medium text-gray-400 mt-1">{vacioTexto}</p>
      </div>
    );
  }

  return (
    <ul className="divide-y divide-gray-100">
      {items.map((r) => (
        <li key={r.id} className="flex items-start gap-4 p-4 hover:bg-gray-50 transition-colors">
          <div className="w-10 h-10 rounded-full bg-gray-100 flex-shrink-0 flex items-center justify-center text-gray-400 font-bold text-xs border border-gray-100 mt-1">
            {r.nombreEvaluador.charAt(0).toUpperCase()}
          </div>

          <div className="flex-1 min-w-0">
            <div className="flex justify-between items-center mb-0.5">
              <h4 className="font-bold text-sm text-gray-900 truncate pr-2">{r.nombreEvaluador}</h4>
              <span className="text-[10px] font-medium text-gray-400 shrink-0">{fechaSegura(r.fecha)}</span>
            </div>

            <div className="mb-1.5">
              <Estrellas calificacion={r.calificacion} />
            </div>

            {r.comentario && <p className="text-sm text-gray-700 leading-snug break-words">&quot;{r.comentario}&quot;</p>}

            {r.servicioTitulo && (
              <div className="mt-2 text-[9px] font-bold text-gray-500 bg-gray-100 inline-flex items-center gap-1.5 px-2 py-1 rounded-md uppercase tracking-wider">
                <span className="truncate max-w-[200px]">{r.servicioTitulo}</span>
              </div>
            )}
          </div>
        </li>
      ))}
    </ul>
  );
}

export function EvaluacionesTabs({ resenasComoTutor, resenasComoAlumno }: { resenasComoTutor: EvaluacionRecibida[]; resenasComoAlumno: EvaluacionRecibida[] }) {
  const [tab, setTab] = useState<"tutor" | "alumno">("tutor");

  return (
    <div>
      <div className="sticky top-14 bg-white/95 backdrop-blur-sm z-20 border-b border-gray-100 flex">
        <button
          type="button"
          onClick={() => setTab("tutor")}
          className={`flex-1 py-3.5 text-xs font-bold uppercase tracking-widest transition-all border-b-2 ${
            tab === "tutor" ? "text-[#54A6D8] border-[#54A6D8]" : "text-gray-400 hover:text-gray-600 border-transparent"
          }`}
        >
          Tutor ({resenasComoTutor.length})
        </button>
        <button
          type="button"
          onClick={() => setTab("alumno")}
          className={`flex-1 py-3.5 text-xs font-bold uppercase tracking-widest transition-all border-b-2 ${
            tab === "alumno" ? "text-orange-500 border-orange-500" : "text-gray-400 hover:text-gray-600 border-transparent"
          }`}
        >
          Estudiante ({resenasComoAlumno.length})
        </button>
      </div>

      <div className="pt-2">
        {tab === "tutor" ? (
          <ListaEvaluaciones items={resenasComoTutor} vacioTexto="No tienes evaluaciones como tutor aún." />
        ) : (
          <ListaEvaluaciones items={resenasComoAlumno} vacioTexto="No tienes evaluaciones como estudiante aún." />
        )}
      </div>
    </div>
  );
}
