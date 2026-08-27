"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import type { ResultadoCupon, ServicioCheckout } from "@/lib/api";
import type { DisponibilidadServicio } from "@/lib/horarios";
import { formatoCLP } from "@/lib/formato";

const DIAS_ES = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"];
const MESES_CORTOS = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];

function proximosDias(n: number): { fecha: string; diaSemana: string; diaMes: number; mes: string }[] {
  const hoy = new Date();
  const out: { fecha: string; diaSemana: string; diaMes: number; mes: string }[] = [];
  for (let i = 0; i < n; i++) {
    const d = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate() + i);
    const fecha = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    out.push({ fecha, diaSemana: DIAS_ES[d.getDay()]!, diaMes: d.getDate(), mes: MESES_CORTOS[d.getMonth()]! });
  }
  return out;
}

interface SlotDisponible {
  datetime: string;
  hora: string;
  disponible: boolean;
  motivo: "pasado" | "ocupado" | null;
}

// Puerto de contratar_servicio.php (formulario) + crear_contrato.php (submit) +
// app/api/slots_disponibles.php (picker) + app/js/agenda_slots.js (mismo comportamiento,
// reescrito en React en vez de portado línea a línea). Termina en un contrato
// 'pendiente_pago' y hace el mismo puente a PHP real para pagar que crear_contrato.php ya
// hacía (iniciar_pago_servicio.php) — Checkpoint 2 (Pago) todavía no está construido.
export function ContratarForm({
  servicio,
  disponibilidad,
  cuponInicial,
  phpSiteUrl,
}: {
  servicio: ServicioCheckout;
  disponibilidad: DisponibilidadServicio;
  cuponInicial: ResultadoCupon | null;
  phpSiteUrl: string;
}) {
  const router = useRouter();
  const dias = proximosDias(14);
  const diasHabilitados = new Set(disponibilidad.dias.map((d) => d.dia));

  const [fecha, setFecha] = useState<string | null>(null);
  const [slots, setSlots] = useState<SlotDisponible[]>([]);
  const [cargandoSlots, setCargandoSlots] = useState(false);
  const [motivoSinSlots, setMotivoSinSlots] = useState<string | null>(null);
  const [slotElegido, setSlotElegido] = useState<string | null>(null);

  const [codigoBeca, setCodigoBeca] = useState("");
  const [cupon, setCupon] = useState<ResultadoCupon | null>(cuponInicial);
  const [aplicandoCupon, setAplicandoCupon] = useState(false);

  const [notas, setNotas] = useState("");
  const [enviando, setEnviando] = useState(false);
  const [errorEnvio, setErrorEnvio] = useState<string | null>(null);
  const [redirigiendoAPasarela, setRedirigiendoAPasarela] = useState(false);

  const montoFinal = cupon && cupon.ok ? cupon.montoFinal : servicio.montoInicial;

  async function seleccionarDia(fechaElegida: string) {
    setFecha(fechaElegida);
    setSlotElegido(null);
    setMotivoSinSlots(null);
    setCargandoSlots(true);
    try {
      const res = await fetch(`/api/me/contratos/slots-disponibles?servicioId=${servicio.id}&fecha=${fechaElegida}`);
      const data = (await res.json()) as { slots: SlotDisponible[]; motivo?: string };
      setSlots(data.slots ?? []);
      setMotivoSinSlots(data.slots?.length ? null : (data.motivo ?? "sin_slots_validos"));
    } finally {
      setCargandoSlots(false);
    }
  }

  if (!disponibilidad.tieneHorarios) {
    return (
      <div className="bg-white rounded-2xl border border-gray-100 shadow-[0_1px_3px_rgba(0,0,0,0.04)] p-6 text-center">
        <p className="text-gray-600 text-sm mb-4">Este servicio no acepta reservas en línea. Coordina el horario directo con el tutor por chat.</p>
        <a
          href={`${phpSiteUrl}/app/iniciar_chat.php?servicio_id=${servicio.id}`}
          className="inline-flex bg-[#54A6D8] text-white font-bold text-sm px-5 py-2.5 rounded-xl hover:bg-blue-600 transition"
        >
          Iniciar chat
        </a>
      </div>
    );
  }

  async function aplicarCupon() {
    if (!codigoBeca.trim()) return;
    setAplicandoCupon(true);
    try {
      const res = await fetch(`/api/me/contratos/checkout/${servicio.id}?codigoBeca=${encodeURIComponent(codigoBeca.trim())}`);
      const data = (await res.json().catch(() => null)) as { cupon?: ResultadoCupon } | null;
      setCupon(data?.cupon ?? { ok: false, error: "No se pudo validar la beca." });
    } finally {
      setAplicandoCupon(false);
    }
  }

  async function confirmar() {
    if (!slotElegido) return;
    setEnviando(true);
    setErrorEnvio(null);
    try {
      const res = await fetch("/api/me/contratos", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          servicioId: servicio.id,
          vendedorId: servicio.vendedorId,
          fechaClase: slotElegido,
          notas,
          codigoBeca: cupon && cupon.ok ? codigoBeca.trim() : "",
          monto: montoFinal,
          precioOriginal: montoFinal,
        }),
      });
      const data = (await res.json().catch(() => null)) as { ok?: boolean; error?: string; mensaje?: string; contratoId?: number; montoFinal?: number } | null;
      if (!res.ok || !data?.ok || data.contratoId === undefined) {
        setErrorEnvio(data?.mensaje ?? "No se pudo crear la reserva. Intenta nuevamente.");
        setEnviando(false);
        return;
      }
      if ((data.montoFinal ?? 0) === 0) {
        router.push(`/aula/${data.contratoId}`);
        return;
      }

      // Checkpoint 2 — genera la preferencia real en MercadoPago (server/src/lib/
      // mercadoPago.ts) y redirige al checkout real, en vez de puentear a
      // iniciar_pago_servicio.php como hacía esta pieza en el Checkpoint 1.
      setRedirigiendoAPasarela(true);
      const resPref = await fetch(`/api/me/pago-contratos/${data.contratoId}/preferencia`, { method: "POST" });
      const dataPref = (await resPref.json().catch(() => null)) as { ok?: boolean; yaProcesado?: boolean; initPoint?: string; mensaje?: string } | null;

      if (!resPref.ok || !dataPref?.ok) {
        setErrorEnvio(dataPref?.mensaje ?? "Tu reserva quedó guardada, pero no pudimos generar el enlace de pago. Puedes intentar pagar de nuevo desde Mis Contratos.");
        setEnviando(false);
        setRedirigiendoAPasarela(false);
        return;
      }
      if (dataPref.yaProcesado || !dataPref.initPoint) {
        router.push(`/aula/${data.contratoId}`);
        return;
      }
      window.location.href = dataPref.initPoint;
    } catch {
      setErrorEnvio("No se pudo conectar con el servidor. Intenta nuevamente.");
      setEnviando(false);
      setRedirigiendoAPasarela(false);
    }
  }

  if (redirigiendoAPasarela) {
    return (
      <div className="bg-white rounded-2xl border border-gray-100 shadow-[0_1px_3px_rgba(0,0,0,0.04)] p-10 text-center">
        <div className="w-12 h-12 border-4 border-sky-100 border-t-[#54A6D8] rounded-full animate-spin mx-auto mb-6" />
        <h2 className="font-medium text-[#222222] mb-1">Asegurando tu pago...</h2>
        <p className="text-gray-500 text-sm">Serás redirigido a la pasarela en un instante.</p>
      </div>
    );
  }

  return (
    <div className="space-y-5">
      <div className="bg-white rounded-2xl border border-gray-100 shadow-[0_1px_3px_rgba(0,0,0,0.04)] p-5">
        <h3 className="font-medium text-[#222222] mb-3">Elige un día</h3>
        <div className="flex gap-2 overflow-x-auto pb-1 -mx-1 px-1">
          {dias.map((d) => {
            const habilitado = diasHabilitados.has(d.diaSemana);
            const activo = fecha === d.fecha;
            return (
              <button
                key={d.fecha}
                type="button"
                disabled={!habilitado}
                onClick={() => seleccionarDia(d.fecha)}
                className={`shrink-0 w-16 py-2.5 rounded-xl border text-center transition-all ${
                  activo
                    ? "bg-[#54A6D8] border-[#54A6D8] text-white"
                    : habilitado
                      ? "bg-white border-gray-200 text-[#222222] hover:border-[#54A6D8]"
                      : "bg-gray-50 border-gray-100 text-gray-300 cursor-not-allowed"
                }`}
              >
                <div className="text-[10px] uppercase font-bold tracking-wide">{d.diaSemana.slice(0, 3)}</div>
                <div className="text-lg font-medium leading-tight">{d.diaMes}</div>
                <div className="text-[9px] uppercase">{d.mes}</div>
              </button>
            );
          })}
        </div>

        {fecha && (
          <div className="mt-5 pt-5 border-t border-gray-100">
            <h3 className="font-medium text-[#222222] mb-3">Elige un horario</h3>
            {cargandoSlots ? (
              <div className="grid grid-cols-3 sm:grid-cols-4 gap-2">
                {Array.from({ length: 8 }, (_, i) => (
                  <div key={i} className="h-10 rounded-lg bg-gray-100 animate-pulse" />
                ))}
              </div>
            ) : motivoSinSlots ? (
              <p className="text-sm text-gray-400 text-center py-4">No hay horarios disponibles ese día.</p>
            ) : (
              <div className="grid grid-cols-3 sm:grid-cols-4 gap-2">
                {slots.map((s) => (
                  <button
                    key={s.datetime}
                    type="button"
                    disabled={!s.disponible}
                    onClick={() => setSlotElegido(s.datetime)}
                    className={`py-2.5 rounded-lg border text-sm font-medium transition-all ${
                      slotElegido === s.datetime
                        ? "bg-[#54A6D8] border-[#54A6D8] text-white"
                        : s.disponible
                          ? "bg-white border-gray-200 text-[#222222] hover:border-[#54A6D8]"
                          : "bg-gray-50 border-gray-100 text-gray-300 line-through cursor-not-allowed"
                    }`}
                  >
                    {s.hora}
                  </button>
                ))}
              </div>
            )}
          </div>
        )}
      </div>

      {servicio.montoInicial > 0 && (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-[0_1px_3px_rgba(0,0,0,0.04)] p-5">
          <h3 className="font-medium text-[#222222] mb-3">¿Tienes un código de beca?</h3>
          <div className="flex gap-2">
            <input
              type="text"
              value={codigoBeca}
              onChange={(e) => setCodigoBeca(e.target.value.toUpperCase())}
              placeholder="CÓDIGO"
              className="flex-1 border border-gray-200 rounded-xl px-4 py-2.5 text-sm uppercase focus:outline-none focus:ring-2 focus:ring-sky-200"
            />
            <button
              type="button"
              onClick={aplicarCupon}
              disabled={aplicandoCupon || !codigoBeca.trim()}
              className="bg-gray-100 text-gray-700 font-bold text-sm px-5 py-2.5 rounded-xl hover:bg-gray-200 transition disabled:opacity-50"
            >
              {aplicandoCupon ? "..." : "Aplicar"}
            </button>
          </div>
          {cupon && (cupon.ok ? <p className="text-xs text-emerald-600 font-medium mt-2">{cupon.mensaje}</p> : <p className="text-xs text-red-500 mt-2">{cupon.error}</p>)}
        </div>
      )}

      <div className="bg-white rounded-2xl border border-gray-100 shadow-[0_1px_3px_rgba(0,0,0,0.04)] p-5">
        <h3 className="font-medium text-[#222222] mb-3">¿Algo que el tutor deba saber? (opcional)</h3>
        <textarea
          value={notas}
          onChange={(e) => setNotas(e.target.value)}
          rows={3}
          placeholder="Ej: Necesito ayuda con derivadas para el próximo control."
          className="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-sky-200 resize-none"
        />
      </div>

      {errorEnvio && <div className="bg-red-50 border border-red-100 text-red-700 text-sm rounded-xl p-3.5">{errorEnvio}</div>}

      <div className="bg-white rounded-2xl border border-gray-100 shadow-[0_1px_3px_rgba(0,0,0,0.04)] p-5 flex items-center justify-between gap-4 sticky bottom-4">
        <div>
          <p className="text-xs text-gray-400 font-bold uppercase">Total a pagar</p>
          <p className="text-2xl font-medium text-[#222222]">{montoFinal > 0 ? formatoCLP(montoFinal) : "Gratis"}</p>
        </div>
        <button
          type="button"
          onClick={confirmar}
          disabled={!slotElegido || enviando}
          className="bg-[#54A6D8] text-white font-bold px-6 py-3 rounded-xl hover:bg-blue-600 transition disabled:opacity-40 disabled:cursor-not-allowed shrink-0"
        >
          {enviando ? "Procesando..." : "Confirmar reserva"}
        </button>
      </div>
    </div>
  );
}
