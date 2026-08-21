"use client";

import { DIAS_SEMANA_NUBIRA } from "@/lib/horarios";

// Puerto de app/componentes/grilla_horarios.php + su JS serializarHorarioGrilla() —
// controlado (value/onChange) en vez del enfoque imperativo del PHP real (que lee el DOM
// a mano al enviar). El shape de `HorarioValor` es EXACTAMENTE el mismo horarios_json que
// ya consume el lado de lectura (web/src/lib/horarios.ts) — sin transformar nada al guardar.
export type HorarioValor = Record<string, string[]>;

export function horarioVacio(): HorarioValor {
  const v: HorarioValor = {};
  for (const dia of DIAS_SEMANA_NUBIRA) v[dia] = [];
  return v;
}

function partirBloque(bloque: string): [string, string] {
  const [desde, hasta] = bloque.split(" - ");
  return [desde ?? "", hasta ?? ""];
}

function armarBloque(desde: string, hasta: string): string {
  return `${desde} - ${hasta}`;
}

function aMinutos(hhmm: string): number {
  const [h, m] = hhmm.split(":").map(Number);
  return (h ?? 0) * 60 + (m ?? 0);
}

// Puerto exacto de serializarHorarioGrilla() (grilla_horarios.php) — mismas 3 validaciones
// client-side (desde<hasta, sin solapes, al menos un bloque). El servidor valida formato +
// desde<hasta de nuevo (validarHorariosJson en server/), pero NO solapes — igual asimetría
// que el PHP real, donde el chequeo de solapes es solo ayuda de UX, nunca un gate server-side.
export function validarHorarioClient(valor: HorarioValor): string | null {
  let tieneAlgunBloque = false;

  for (const dia of DIAS_SEMANA_NUBIRA) {
    const bloques = valor[dia] ?? [];
    const rangos: Array<{ desdeMin: number; hastaMin: number; desde: string; hasta: string }> = [];

    for (const bloque of bloques) {
      const [desde, hasta] = partirBloque(bloque);
      if (!desde || !hasta) continue;
      tieneAlgunBloque = true;
      if (desde >= hasta) {
        return `Error en ${dia}: la hora de inicio (${desde}) debe ser menor a la de fin (${hasta}).`;
      }
      rangos.push({ desdeMin: aMinutos(desde), hastaMin: aMinutos(hasta), desde, hasta });
    }

    rangos.sort((a, b) => a.desdeMin - b.desdeMin);
    for (let i = 1; i < rangos.length; i++) {
      if (rangos[i]!.desdeMin < rangos[i - 1]!.hastaMin) {
        return `Error en ${dia}: el bloque ${rangos[i]!.desde} - ${rangos[i]!.hasta} se solapa con ${rangos[i - 1]!.desde} - ${rangos[i - 1]!.hasta}.`;
      }
    }
  }

  if (!tieneAlgunBloque) return "Marca al menos un bloque de disponibilidad antes de publicar.";
  return null;
}

function IconoBasura() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
      />
    </svg>
  );
}

function IconoMas() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3.5 h-3.5">
      <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
    </svg>
  );
}

export function HorarioGrid({ value, onChange }: { value: HorarioValor; onChange: (v: HorarioValor) => void }) {
  function toggleDia(dia: string, activo: boolean) {
    onChange({ ...value, [dia]: activo ? [armarBloque("09:00", "11:00")] : [] });
  }

  function actualizarBloque(dia: string, index: number, desde: string, hasta: string) {
    const bloques = [...(value[dia] ?? [])];
    bloques[index] = armarBloque(desde, hasta);
    onChange({ ...value, [dia]: bloques });
  }

  function agregarBloque(dia: string) {
    onChange({ ...value, [dia]: [...(value[dia] ?? []), armarBloque("09:00", "11:00")] });
  }

  function quitarBloque(dia: string, index: number) {
    const bloques = (value[dia] ?? []).filter((_, i) => i !== index);
    onChange({ ...value, [dia]: bloques });
  }

  return (
    <div className="space-y-4">
      {DIAS_SEMANA_NUBIRA.map((dia) => {
        const bloques = value[dia] ?? [];
        const activo = bloques.length > 0;
        return (
          <div key={dia} className={`border rounded-2xl p-4 transition-all ${activo ? "bg-white border-gray-200" : "bg-gray-50 border-gray-200 opacity-70"}`}>
            <div className="flex items-center justify-between">
              <h3 className={`font-bold w-24 ${activo ? "text-gray-900" : "text-gray-400"}`}>{dia}</h3>
              <label className="relative inline-flex items-center cursor-pointer mr-2">
                <input
                  type="checkbox"
                  className="sr-only peer"
                  checked={activo}
                  onChange={(e) => toggleDia(dia, e.target.checked)}
                  aria-label={`Activar ${dia}`}
                />
                <div className="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#34C759]" />
              </label>
            </div>

            {activo && (
              <div className="mt-4 space-y-2">
                {bloques.map((bloque, index) => {
                  const [desde, hasta] = partirBloque(bloque);
                  return (
                    <div key={index} className="flex items-center gap-2">
                      <input
                        type="time"
                        value={desde}
                        onChange={(e) => actualizarBloque(dia, index, e.target.value, hasta)}
                        className="bg-gray-100 border-0 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-[#54A6D8] block w-full p-2.5 font-bold outline-none"
                      />
                      <span className="text-gray-400 font-bold">-</span>
                      <input
                        type="time"
                        value={hasta}
                        onChange={(e) => actualizarBloque(dia, index, desde, e.target.value)}
                        className="bg-gray-100 border-0 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-[#54A6D8] block w-full p-2.5 font-bold outline-none"
                      />
                      <button
                        type="button"
                        onClick={() => quitarBloque(dia, index)}
                        className="w-10 h-10 shrink-0 text-gray-400 hover:text-red-500 bg-white hover:bg-red-50 rounded-xl transition-all flex items-center justify-center"
                        aria-label="Quitar bloque"
                      >
                        <IconoBasura />
                      </button>
                    </div>
                  );
                })}
                <button
                  type="button"
                  onClick={() => agregarBloque(dia)}
                  className="flex items-center gap-1.5 text-xs font-bold text-[#54A6D8] hover:text-sky-600 mt-1"
                >
                  <IconoMas /> Agregar bloque
                </button>
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}
