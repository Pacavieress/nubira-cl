"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import type { ContratoAgenda } from "@/lib/api";
import { agruparContratos, contarActivas, formatearFechaClase, tiempoHastaClase } from "@/lib/agenda";
import { formatoCLP } from "@/lib/formato";

// Puerto de app/mis_contratos.php:223-476 — 2 tabs (Soy Alumno / Soy Profesor), secciones
// por bloque temporal (Hoy/Esta semana/Más adelante/Sin fecha) + historial colapsable de
// clases pasadas (abierto por defecto solo si no hay activas, igual que el PHP real).
// Sin acciones de escritura — página 100% de lectura en el PHP real también.

const BARRA_COLOR: Record<string, string> = {
  finalizado: "bg-blue-400",
  liberado: "bg-blue-400",
  finalizado_vendedor: "bg-blue-400",
  finalizado_comprador: "bg-blue-400",
  cancelado: "bg-rose-400",
  activo: "bg-emerald-400",
  en_progreso: "bg-emerald-400",
};

function IconoCalendario() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3 h-3">
      <path strokeLinecap="round" strokeLinejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
    </svg>
  );
}

function CardContrato({ contrato, tipoVista }: { contrato: ContratoAgenda; tipoVista: "comprador" | "vendedor" }) {
  const fechaAmigable = formatearFechaClase(contrato.fechaClase);
  const tiempoRestante = tiempoHastaClase(contrato.fechaClase);
  const personaLabel = tipoVista === "comprador" ? "con" : "Alumno:";
  const primerNombre = contrato.otraPersonaNombre.split(" ")[0];
  const [pagando, setPagando] = useState(false);
  const router = useRouter();

  // Checkpoint 2 (Pago) — puerto de iniciar_pago_contrato.php (botón "Pagar" para
  // reintentar un contrato pendiente_pago), unificado con el mismo endpoint que ya usa
  // ContratarForm.tsx en vez de puentear al PHP real.
  async function pagar() {
    setPagando(true);
    try {
      const res = await fetch(`/api/me/pago-contratos/${contrato.id}/preferencia`, { method: "POST" });
      const data = (await res.json().catch(() => null)) as { ok?: boolean; yaProcesado?: boolean; initPoint?: string } | null;
      if (res.ok && data?.ok && data.initPoint) {
        window.location.href = data.initPoint;
        return;
      }
      if (res.ok && data?.ok && data.yaProcesado) {
        router.push(`/aula/${contrato.id}`);
        return;
      }
      setPagando(false);
    } catch {
      setPagando(false);
    }
  }

  return (
    <div className="bg-white rounded-2xl border border-gray-100 shadow-[0_1px_3px_rgba(0,0,0,0.04)] p-4 flex flex-col md:flex-row gap-4 md:items-center hover:shadow-md transition">
      <div className="w-full md:w-20 h-20 bg-gray-50 rounded-xl overflow-hidden shrink-0 border border-gray-100">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={contrato.imagenUrl} alt="" className="w-full h-full object-cover" />
      </div>

      <div className="flex-1 w-full text-center md:text-left min-w-0">
        <h3 className="font-medium text-[#222222] text-base leading-tight mb-1 truncate">{contrato.servicioTitulo}</h3>

        <p className="text-sm text-gray-500 mb-2">
          {personaLabel} <span className="font-medium text-gray-700">{primerNombre}</span>
          {tipoVista === "comprador" && contrato.monto > 0 && (
            <>
              <span className="text-gray-300 mx-1">·</span>
              <span className="font-medium text-[#222222]">{formatoCLP(contrato.monto)}</span>
            </>
          )}
        </p>

        <div className="flex flex-wrap items-center gap-2 justify-center md:justify-start">
          {fechaAmigable && (
            <span className="inline-flex items-center gap-1.5 text-xs text-gray-600 font-medium">
              <span className="text-[#54A6D8]">
                <IconoCalendario />
              </span>
              {fechaAmigable}
            </span>
          )}
          {tiempoRestante && (
            <>
              <span className="text-gray-300">·</span>
              <span className="text-xs text-emerald-600 font-bold">{tiempoRestante}</span>
            </>
          )}
        </div>
      </div>

      <div className="w-full md:w-auto shrink-0">
        {tipoVista === "comprador" && contrato.estado === "pendiente_pago" ? (
          <button
            type="button"
            onClick={pagar}
            disabled={pagando}
            className="flex items-center justify-center gap-2 bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-2.5 px-5 rounded-xl text-sm transition w-full md:w-auto disabled:opacity-50"
          >
            {pagando ? "Procesando..." : "Pagar"}
          </button>
        ) : (
          <Link
            href={`/aula/${contrato.id}`}
            className="flex items-center justify-center gap-2 bg-[#54A6D8] hover:bg-blue-600 text-white font-bold py-2.5 px-5 rounded-xl text-sm transition w-full md:w-auto"
          >
            Ir al Aula
          </Link>
        )}
      </div>
    </div>
  );
}

function CardCompacta({ contrato }: { contrato: ContratoAgenda }) {
  const fechaAmigable = formatearFechaClase(contrato.fechaClase ?? contrato.fechaEstimada);
  const primerNombre = contrato.otraPersonaNombre.split(" ")[0];
  const barra = BARRA_COLOR[contrato.estado] ?? "bg-gray-300";

  return (
    <Link
      href={`/aula/${contrato.id}`}
      className="flex items-center justify-between gap-3 px-4 py-3 bg-white border border-gray-100 rounded-xl hover:bg-gray-50 hover:border-gray-200 transition-all group"
    >
      <div className="flex items-center gap-3 min-w-0 flex-1">
        <div className={`w-1 h-10 rounded-full ${barra} flex-shrink-0`} />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-[#222222] truncate">{contrato.servicioTitulo}</p>
          <div className="flex items-center gap-2 text-xs text-gray-500 mt-0.5 flex-wrap">
            <span className="truncate">{primerNombre}</span>
            {fechaAmigable && (
              <>
                <span className="text-gray-300">·</span>
                <span className="truncate">{fechaAmigable}</span>
              </>
            )}
          </div>
        </div>
      </div>
      <span className="text-[#54A6D8] text-xs font-bold opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-1 flex-shrink-0">
        Ver
      </span>
    </Link>
  );
}

function Seccion({
  titulo,
  contratos,
  tipoVista,
  colorTitulo = "text-gray-900",
}: {
  titulo: string;
  contratos: ContratoAgenda[];
  tipoVista: "comprador" | "vendedor";
  colorTitulo?: string;
}) {
  if (contratos.length === 0) return null;
  return (
    <div className="mb-8">
      <h2 className={`text-xs font-extrabold ${colorTitulo} uppercase tracking-widest mb-3 flex items-center gap-2`}>
        {titulo}
        <span className="bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full text-[10px] font-bold normal-case tracking-normal">{contratos.length}</span>
      </h2>
      <div className="space-y-3">
        {contratos.map((c) => (
          <CardContrato key={c.id} contrato={c} tipoVista={tipoVista} />
        ))}
      </div>
    </div>
  );
}

function TabContenido({
  contratos,
  tipoVista,
  textoVacioActivo,
  tituloVacio,
  subtituloVacio,
}: {
  contratos: ContratoAgenda[];
  tipoVista: "comprador" | "vendedor";
  textoVacioActivo: { titulo: string; subtitulo: string; cta?: boolean };
  tituloVacio: string;
  subtituloVacio: string;
}) {
  if (contratos.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center py-16 bg-gray-50 rounded-2xl border border-dashed border-gray-200">
        <h3 className="text-lg font-medium text-[#222222] tracking-[-0.01em]">{tituloVacio}</h3>
        <p className="text-gray-500 text-sm mt-1">{subtituloVacio}</p>
      </div>
    );
  }

  const grupos = agruparContratos(contratos);
  const activas = contarActivas(contratos);
  const tieneActivas = activas > 0;

  return (
    <div className="space-y-4">
      {tieneActivas ? (
        <>
          <Seccion titulo="Hoy" contratos={grupos.hoy} tipoVista={tipoVista} colorTitulo="text-emerald-600" />
          <Seccion titulo="Esta semana" contratos={grupos.esta_semana} tipoVista={tipoVista} colorTitulo="text-[#54A6D8]" />
          <Seccion titulo="Más adelante" contratos={grupos.mas_adelante} tipoVista={tipoVista} />
          <Seccion titulo="Sin fecha agendada" contratos={grupos.sin_fecha} tipoVista={tipoVista} colorTitulo="text-amber-600" />
        </>
      ) : (
        <div className="bg-blue-50 border border-blue-100 rounded-2xl p-5 text-center mb-6">
          <p className="text-sm font-bold text-gray-800 mb-1">{textoVacioActivo.titulo}</p>
          <p className="text-xs text-gray-500 mb-3">{textoVacioActivo.subtitulo}</p>
          {textoVacioActivo.cta && (
            <Link href="/servicios" className="inline-flex bg-[#54A6D8] text-white text-xs font-bold px-4 py-2 rounded-full hover:bg-blue-600 transition">
              Explorar
            </Link>
          )}
        </div>
      )}

      {grupos.pasada.length > 0 && (
        <details className="group" open={!tieneActivas}>
          <summary className="cursor-pointer flex items-center justify-between py-3 border-t border-gray-100 mt-4">
            <h2 className="text-xs font-extrabold text-gray-400 uppercase tracking-widest flex items-center gap-2">
              Historial
              <span className="bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full text-[10px] font-bold normal-case tracking-normal">{grupos.pasada.length}</span>
            </h2>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3 h-3 text-gray-400 transition-transform group-open:rotate-180">
              <path strokeLinecap="round" strokeLinejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
          </summary>
          <div className="space-y-2 pt-3">
            {grupos.pasada.map((c) => (
              <CardCompacta key={c.id} contrato={c} />
            ))}
          </div>
        </details>
      )}
    </div>
  );
}

export function MisContratosTabs({
  comoComprador,
  comoVendedor,
}: {
  comoComprador: ContratoAgenda[];
  comoVendedor: ContratoAgenda[];
}) {
  const [tab, setTab] = useState<"comprador" | "vendedor">("comprador");
  const activasComprador = contarActivas(comoComprador);
  const activasVendedor = contarActivas(comoVendedor);

  return (
    <div>
      <div className="flex border-b border-gray-200 mb-6 bg-white rounded-t-2xl overflow-hidden shadow-sm">
        <button
          type="button"
          onClick={() => setTab("comprador")}
          className={`flex-1 md:flex-none px-6 py-4 font-bold text-sm transition flex items-center justify-center gap-2 ${
            tab === "comprador" ? "text-[#54A6D8] border-b-2 border-[#54A6D8] bg-[#eef6fb]" : "text-gray-500 hover:bg-gray-50"
          }`}
        >
          Soy Alumno
          {activasComprador > 0 && <span className="bg-[#54A6D8] text-white px-2 py-0.5 rounded-full text-xs ml-1 font-bold">{activasComprador}</span>}
        </button>
        <button
          type="button"
          onClick={() => setTab("vendedor")}
          className={`flex-1 md:flex-none px-6 py-4 font-bold text-sm transition flex items-center justify-center gap-2 ${
            tab === "vendedor" ? "text-[#54A6D8] border-b-2 border-[#54A6D8] bg-[#eef6fb]" : "text-gray-500 hover:bg-gray-50"
          }`}
        >
          Soy Profesor
          {activasVendedor > 0 && <span className="bg-[#54A6D8] text-white px-2 py-0.5 rounded-full text-xs ml-1 font-bold">{activasVendedor}</span>}
        </button>
      </div>

      {tab === "comprador" ? (
        <TabContenido
          contratos={comoComprador}
          tipoVista="comprador"
          textoVacioActivo={{ titulo: "No tienes clases próximas", subtitulo: "Explora servicios y agenda tu siguiente clase.", cta: true }}
          tituloVacio="No tienes clases contratadas"
          subtituloVacio="Busca un profesor y comienza a aprender."
        />
      ) : (
        <TabContenido
          contratos={comoVendedor}
          tipoVista="vendedor"
          textoVacioActivo={{ titulo: "No tienes clases agendadas próximamente", subtitulo: "Cuando un alumno agende una clase, aparecerá aquí." }}
          tituloVacio="No tienes alumnos activos"
          subtituloVacio="Publica un nuevo servicio para atraer estudiantes."
        />
      )}
    </div>
  );
}
