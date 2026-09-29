"use client";

import { useRef, useState } from "react";
import { useRouter } from "next/navigation";
import type { DisponibilidadServicio } from "@/lib/horarios";

const MESES = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
const DIA_A_DOW: Record<string, number> = { Lunes: 1, Martes: 2, Miércoles: 3, Jueves: 4, Viernes: 5, Sábado: 6, Domingo: 0 };

interface SlotDisponible {
  datetime: string;
  hora: string;
  disponible: boolean;
  motivo: "pasado" | "ocupado" | null;
}

// Puerto de renderFechasProximas() (app/js/agenda_slots.js:59-96): las próximas 4 fechas
// que caen en ese día de la semana, contando hoy. La etiqueta "En N días" es idx*7 igual
// que el PHP (no la distancia real desde hoy) — el PHP manda.
function proximasFechas(targetDow: number, cantidad = 4): Date[] {
  const hoy = new Date();
  const cursor = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate());
  const out: Date[] = [];
  while (out.length < cantidad) {
    if (cursor.getDay() === targetDow) out.push(new Date(cursor));
    cursor.setDate(cursor.getDate() + 1);
  }
  return out;
}

function fechaISO(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

// Puerto del selector de horarios de detalle_servicio.php:752-801 + app/js/agenda_slots.js
// (tarjetas de día -> tira de 4 fechas -> grilla de slots de 30 min, con estados libre/
// ocupado/pasado/seleccionado). Reemplaza el bloque de tarjetas estáticas de
// servicios/[id]/page.tsx.
//
// Visitante sin sesión (decisión distinta al PHP, que deja caer el fetch al mensaje
// "No hay horarios disponibles" — engañoso): las tarjetas de día son enlaces a login con
// redir de vuelta a este servicio, así que el endpoint autenticado nunca se llama sin sesión.
//
// Al elegir un slot navega a /contratar/[id]?fechaClase=<datetime> (equivalente al
// window.location.href de detalle_servicio.php:1301-1303). La navegación vive acá adentro
// porque este componente es cliente y la página que lo monta es Server Component: no se le
// puede pasar un callback onSlotSelected como prop.
export function SelectorSlotsDetalle({
  servicioId,
  disponibilidad,
  esGuest,
  loginHref,
}: {
  servicioId: number;
  disponibilidad: DisponibilidadServicio;
  esGuest: boolean;
  // URL completa de login con ?redir= de vuelta al servicio; solo se usa si esGuest.
  loginHref: string;
}) {
  const router = useRouter();
  const [diaSeleccionado, setDiaSeleccionado] = useState<string | null>(null);
  const [fechas, setFechas] = useState<Date[]>([]);
  const [fechaActiva, setFechaActiva] = useState<string | null>(null);
  const [slots, setSlots] = useState<SlotDisponible[]>([]);
  const [cargando, setCargando] = useState(false);
  const [sinSlots, setSinSlots] = useState(false);
  const [slotElegido, setSlotElegido] = useState<string | null>(null);
  // Descarta respuestas de una fecha vieja si el usuario cambió de fecha/día mientras cargaba.
  const pedidoActual = useRef(0);

  async function cargarSlots(fecha: string) {
    const pedido = ++pedidoActual.current;
    setSlots([]);
    setSinSlots(false);
    setSlotElegido(null);
    setCargando(true);
    try {
      const res = await fetch(`/api/me/contratos/slots-disponibles?servicioId=${servicioId}&fecha=${fecha}`);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = (await res.json()) as { slots?: SlotDisponible[] };
      if (pedido !== pedidoActual.current) return;
      const lista = data.slots ?? [];
      setSlots(lista);
      setSinSlots(lista.length === 0);
    } catch {
      if (pedido !== pedidoActual.current) return;
      setSinSlots(true);
    } finally {
      if (pedido === pedidoActual.current) setCargando(false);
    }
  }

  function seleccionarFecha(fecha: string) {
    setFechaActiva(fecha);
    void cargarSlots(fecha);
  }

  function seleccionarDia(dia: string) {
    const lista = proximasFechas(DIA_A_DOW[dia] ?? 1);
    setDiaSeleccionado(dia);
    setFechas(lista);
    seleccionarFecha(fechaISO(lista[0]!));
  }

  function seleccionarSlot(datetime: string) {
    setSlotElegido(datetime);
    router.push(`/contratar/${servicioId}?fechaClase=${encodeURIComponent(datetime)}`);
  }

  const hayOcupado = slots.some((s) => !s.disponible && s.motivo === "ocupado");
  const hayPasado = slots.some((s) => !s.disponible && s.motivo === "pasado");

  return (
    <div>
      <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
        {disponibilidad.dias.map(({ dia, bloques }) => {
          const esProximo = dia === disponibilidad.diaProximo;
          const activo = dia === diaSeleccionado;
          // Borde: seleccionado > próximo > (si ya se eligió otro día: azul claro) > base.
          const borde = activo
            ? "border-[#54A6D8] ring-2 ring-blue-100 bg-blue-50"
            : esProximo
              ? "border-[#54A6D8] ring-2 ring-blue-100"
              : diaSeleccionado
                ? "border-blue-100"
                : "border-[#f0f0f0]";
          const clases = `text-left bg-white border ${borde} rounded-xl p-3 shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:border-[#54A6D8] hover:shadow-md transition-all group relative`;
          const contenido = (
            <>
              {esProximo && (
                <span className="absolute -top-2 -right-2 bg-[#54A6D8] text-white text-[9px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full shadow-sm">Próximo</span>
              )}
              <p className={`text-xs font-medium mb-2 group-hover:text-[#54A6D8] transition-colors ${esProximo ? "text-[#54A6D8]" : "text-[#222222]"}`}>{dia}</p>
              <div className="flex flex-col gap-1.5">
                {bloques.map((bloque) => (
                  <span key={bloque} className="bg-blue-50 text-[#54A6D8] text-[10px] font-medium px-2 py-1 rounded-md text-center border border-blue-100/50 truncate">
                    {bloque}
                  </span>
                ))}
              </div>
            </>
          );
          return esGuest ? (
            <a key={dia} href={loginHref} className={clases}>
              {contenido}
            </a>
          ) : (
            <button key={dia} type="button" onClick={() => seleccionarDia(dia)} className={clases}>
              {contenido}
            </button>
          );
        })}
      </div>

      {diaSeleccionado && (
        <div className="mt-6 pt-6 border-t border-gray-100">
          <p className="text-xs font-bold text-gray-500 uppercase tracking-wide mb-3">
            Elige una hora para <span className="text-[#54A6D8]">{diaSeleccionado.toLowerCase()}</span>
          </p>

          <div className="flex gap-2 overflow-x-auto pb-3 mb-4 no-scrollbar">
            {fechas.map((d, idx) => {
              const iso = fechaISO(d);
              const activa = iso === fechaActiva;
              return (
                <button
                  key={iso}
                  type="button"
                  onClick={() => seleccionarFecha(iso)}
                  className={`flex-shrink-0 w-20 py-2.5 rounded-xl border hover:border-[#54A6D8] transition-all text-center ${
                    activa ? "border-[#54A6D8] bg-blue-50 ring-2 ring-blue-100" : "border-gray-200 bg-white"
                  }`}
                >
                  <p className="text-[10px] font-bold text-gray-400 uppercase">{idx === 0 ? "Próximo" : `En ${idx * 7} días`}</p>
                  <p className="text-base font-extrabold text-gray-900 leading-tight">
                    {d.getDate()} {MESES[d.getMonth()]}
                  </p>
                </button>
              );
            })}
          </div>

          {cargando && (
            <div className="text-center py-6">
              <div className="inline-block animate-spin h-6 w-6 border-4 border-blue-200 border-t-[#54A6D8] rounded-full" />
            </div>
          )}

          {!cargando && sinSlots && (
            <div className="bg-gray-50 border border-dashed border-gray-200 rounded-xl p-5 text-center">
              <p className="text-xs text-gray-500">No hay horarios disponibles para esta fecha.</p>
            </div>
          )}

          {!cargando && slots.length > 0 && (
            <>
              <div className="grid grid-cols-3 sm:grid-cols-4 gap-2">
                {slots.map((s) => {
                  const seleccionado = slotElegido === s.datetime;
                  const cls = s.disponible
                    ? seleccionado
                      ? "bg-[#54A6D8] text-white border border-[#54A6D8] cursor-pointer"
                      : "bg-white border border-gray-200 text-gray-900 hover:border-[#54A6D8] hover:bg-blue-50 cursor-pointer"
                    : s.motivo === "pasado"
                      ? "bg-gray-50 border border-dashed border-gray-200 text-gray-300 cursor-not-allowed"
                      : "bg-gray-50 border border-gray-100 text-gray-300 cursor-not-allowed line-through";
                  const title = s.disponible ? undefined : s.motivo === "pasado" ? "Muy pronto para agendar" : "Este horario ya está ocupado";
                  return (
                    <button
                      key={s.datetime}
                      type="button"
                      disabled={!s.disponible}
                      title={title}
                      onClick={() => seleccionarSlot(s.datetime)}
                      className={`${cls} py-2.5 rounded-xl text-sm font-bold transition-all`}
                    >
                      {s.hora}
                    </button>
                  );
                })}
              </div>
              {(hayOcupado || hayPasado) && (
                <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[11px] text-gray-400">
                  {hayOcupado && (
                    <span className="flex items-center gap-1.5">
                      <span className="w-3 h-3 rounded border border-gray-200 bg-gray-50" />
                      Ocupado
                    </span>
                  )}
                  {hayPasado && (
                    <span className="flex items-center gap-1.5">
                      <span className="w-3 h-3 rounded border border-dashed border-gray-200 bg-gray-50" />
                      Muy pronto para agendar
                    </span>
                  )}
                </div>
              )}
            </>
          )}
        </div>
      )}
    </div>
  );
}
