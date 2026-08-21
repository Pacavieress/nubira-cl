"use client";

import { useState } from "react";
import type { ConfigPrecios } from "@/lib/api";
import { formatoCLP } from "@/lib/formato";

// Puerto de admin_config_precios.php — 2 formularios independientes (precio base de
// desbloqueo de contacto, y promo "Costo Cero" con fecha de término). Sin acciones
// destructivas — solo UPDATE. Mejora deliberada sobre el PHP real: actualiza en memoria
// tras cada guardado en vez de recargar la página completa (mismo criterio ya aplicado en
// AdminDominiosPanel.tsx).
export function AdminConfigPreciosPanel({ configInicial }: { configInicial: ConfigPrecios }) {
  const [config, setConfig] = useState(configInicial);
  const [precioInput, setPrecioInput] = useState(String(configInicial.precioDesbloqueoContacto));
  const [guardandoPrecio, setGuardandoPrecio] = useState(false);
  const [mensajePrecio, setMensajePrecio] = useState<{ tipo: "ok" | "error"; texto: string } | null>(null);

  const ofertaInicial = configInicial.ofertaGratisHasta ? configInicial.ofertaGratisHasta.slice(0, 16).replace(" ", "T") : "";
  const [ofertaInput, setOfertaInput] = useState(ofertaInicial);
  const [guardandoOferta, setGuardandoOferta] = useState(false);
  const [mensajeOferta, setMensajeOferta] = useState<{ tipo: "ok" | "error"; texto: string } | null>(null);

  async function guardarPrecio(e: React.FormEvent) {
    e.preventDefault();
    setGuardandoPrecio(true);
    setMensajePrecio(null);
    try {
      const res = await fetch("/api/admin/config-precios/precio", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ precio: Number(precioInput) }),
      });
      const data = await res.json();
      if (!res.ok) {
        setMensajePrecio({ tipo: "error", texto: data.mensaje ?? "No se pudo actualizar el precio." });
        return;
      }
      setConfig((prev) => ({ ...prev, precioDesbloqueoContacto: data.precioDesbloqueoContacto }));
      setMensajePrecio({ tipo: "ok", texto: "Precio base actualizado correctamente." });
    } catch {
      setMensajePrecio({ tipo: "error", texto: "No se pudo actualizar el precio." });
    } finally {
      setGuardandoPrecio(false);
    }
  }

  async function guardarOferta(e: React.FormEvent) {
    e.preventDefault();
    setGuardandoOferta(true);
    setMensajeOferta(null);
    try {
      const res = await fetch("/api/admin/config-precios/oferta", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ fecha: ofertaInput }),
      });
      const data = await res.json();
      if (!res.ok) {
        setMensajeOferta({ tipo: "error", texto: data.mensaje ?? "No se pudo actualizar la promoción." });
        return;
      }
      setConfig((prev) => ({ ...prev, ofertaGratisHasta: data.ofertaGratisHasta, ofertaVigente: data.ofertaVigente }));
      setMensajeOferta({
        tipo: "ok",
        texto: data.ofertaGratisHasta
          ? `Promoción "Costo Cero" activada hasta ${new Date(data.ofertaGratisHasta.replace(" ", "T")).toLocaleString("es-CL", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" })}`
          : "Promoción \"Costo Cero\" desactivada.",
      });
    } catch {
      setMensajeOferta({ tipo: "error", texto: "No se pudo actualizar la promoción." });
    } finally {
      setGuardandoOferta(false);
    }
  }

  return (
    <div className="max-w-2xl space-y-6">
      <div className="bg-white border border-gray-100 rounded-3xl p-6 md:p-8">
        <div className="flex items-center gap-3 mb-6 border-b border-gray-50 pb-4">
          <div className="w-10 h-10 rounded-full bg-blue-50 text-[#54A6D8] flex items-center justify-center shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5">
              <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
          </div>
          <div>
            <h2 className="text-base font-bold text-gray-900">Tarifa de Desbloqueo</h2>
            <p className="text-xs text-gray-400 font-medium">Costo estándar por abrir una conversación de servicio.</p>
          </div>
        </div>

        <p className="text-xs text-gray-400 mb-4">
          Valor actual: <span className="font-bold text-gray-700">{formatoCLP(config.precioDesbloqueoContacto)}</span>
        </p>

        <form onSubmit={guardarPrecio} className="space-y-4">
          <div>
            <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Precio Base ($ CLP)</label>
            <div className="relative">
              <span className="absolute left-4 top-1/2 -translate-y-1/2 font-bold text-gray-400">$</span>
              <input
                type="number"
                min={100}
                max={99999}
                value={precioInput}
                onChange={(e) => setPrecioInput(e.target.value)}
                required
                className="w-full border border-gray-200 bg-gray-50 pl-8 pr-4 py-3 rounded-2xl focus:border-[#54A6D8] focus:bg-white outline-none text-gray-800 font-bold"
              />
            </div>
          </div>
          {mensajePrecio && (
            <p className={`text-xs font-medium ${mensajePrecio.tipo === "ok" ? "text-emerald-600" : "text-red-600"}`}>{mensajePrecio.texto}</p>
          )}
          <button
            type="submit"
            disabled={guardandoPrecio}
            className="bg-[#54A6D8] text-white font-bold py-3 px-6 rounded-2xl text-sm disabled:opacity-50"
          >
            {guardandoPrecio ? "Guardando..." : "Guardar Tarifa"}
          </button>
        </form>
      </div>

      <div className="bg-white border border-gray-100 rounded-3xl p-6 md:p-8">
        <div className="flex items-center gap-3 mb-6 border-b border-gray-50 pb-4">
          <div className="w-10 h-10 rounded-full bg-emerald-50 text-emerald-500 flex items-center justify-center shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5">
              <path strokeLinecap="round" strokeLinejoin="round" d="M12 8.25v-1.5m0 1.5c-1.355 0-2.697.056-4.024.166C6.845 8.51 6 9.473 6 10.608v2.513m6-4.87c1.355 0 2.697.055 4.024.165C17.155 8.51 18 9.473 18 10.608v2.513m-3-4.87v-1.5m-6 1.5v-1.5m12 9.75-1.5.75a3.354 3.354 0 0 1-3 0 3.354 3.354 0 0 0-3 0 3.354 3.354 0 0 1-3 0 3.354 3.354 0 0 0-3 0 3.354 3.354 0 0 1-3 0L3 16.5m15-3.38a48.474 48.474 0 0 0-6-.37c-2.032 0-4.034.126-6 .37m12 0c.39.049.777.102 1.163.16 1.07.16 1.837 1.094 1.837 2.175v5.169c0 .621-.504 1.125-1.125 1.125H4.125A1.125 1.125 0 0 1 3 20.945v-5.17c0-1.08.768-2.014 1.837-2.174A47.78 47.78 0 0 1 6 13.12M12.265 3.11a.375.375 0 1 1-.53 0L12 2.845l.265.265Zm-3 0a.375.375 0 1 1-.53 0L9 2.845l.265.265Zm6 0a.375.375 0 1 1-.53 0L15 2.845l.265.265Z" />
            </svg>
          </div>
          <div className="flex-1 min-w-0">
            <h2 className="text-base font-bold text-gray-900">Promoción &quot;Costo Cero&quot;</h2>
            <p className="text-xs text-gray-400 font-medium">Todos los servicios gratuitos hasta la fecha fijada.</p>
          </div>
          {config.ofertaGratisHasta && (
            <span className={`shrink-0 px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest ${config.ofertaVigente ? "bg-emerald-500 text-white" : "bg-gray-100 text-gray-400"}`}>
              {config.ofertaVigente ? "Activa" : "Inactiva"}
            </span>
          )}
        </div>

        <form onSubmit={guardarOferta} className="space-y-4">
          <div>
            <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Fecha de Término de Promoción</label>
            <input
              type="datetime-local"
              value={ofertaInput}
              onChange={(e) => setOfertaInput(e.target.value)}
              className="w-full border border-gray-200 bg-gray-50 px-4 py-3 rounded-2xl focus:border-emerald-400 focus:bg-white outline-none text-gray-800 text-sm"
            />
            <p className="text-[10px] font-medium text-gray-400 mt-1.5">Deja el campo vacío y guarda para desactivar la promoción.</p>
          </div>
          {mensajeOferta && (
            <p className={`text-xs font-medium ${mensajeOferta.tipo === "ok" ? "text-emerald-600" : "text-red-600"}`}>{mensajeOferta.texto}</p>
          )}
          <button
            type="submit"
            disabled={guardandoOferta}
            className="bg-gray-800 text-white font-bold py-3 px-6 rounded-2xl text-sm disabled:opacity-50"
          >
            {guardandoOferta ? "Guardando..." : "Actualizar Promoción"}
          </button>
        </form>
      </div>
    </div>
  );
}
