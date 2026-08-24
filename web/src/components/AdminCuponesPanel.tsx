"use client";

import { useState } from "react";
import type { CuponBeca, ServicioParaCupon } from "@/lib/api";
import { formatoCLP } from "@/lib/formato";

// Puerto de cupones.php ("Bóveda de Becas") + admin_procesar_cupon.php — crear/eliminar
// cupones de descuento. Sin efecto externo (ver la nota de alcance en
// server/src/modules/adminCupones/adminCupones.types.ts), se porta completo. Mejora
// deliberada sobre el PHP real (documentada, no un descuido): el badge de servicio muestra
// el título real (`servicioTitulo`, vía LEFT JOIN) en vez de solo "Servicio #<id>" como hace
// cupones.php:96 — el dato ya estaba disponible, no tenía sentido no usarlo. Igual que
// AdminDominiosPanel, se actualiza la lista en memoria tras cada respuesta 2xx en vez de
// recargar la página completa como hace el PHP real (POST -> redirect -> reload).
export function AdminCuponesPanel({ cuponesIniciales, servicios }: { cuponesIniciales: CuponBeca[]; servicios: ServicioParaCupon[] }) {
  const [cupones, setCupones] = useState(cuponesIniciales);
  const [modalAbierto, setModalAbierto] = useState(false);
  const [codigo, setCodigo] = useState("");
  const [porcentaje, setPorcentaje] = useState("15");
  const [usosMaximos, setUsosMaximos] = useState("1");
  const [servicioId, setServicioId] = useState("");
  const [fechaExpiracion, setFechaExpiracion] = useState("");
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [eliminando, setEliminando] = useState<number | null>(null);

  function resetForm() {
    setCodigo("");
    setPorcentaje("15");
    setUsosMaximos("1");
    setServicioId("");
    setFechaExpiracion("");
    setError(null);
  }

  async function crear(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setEnviando(true);
    try {
      const res = await fetch("/api/admin/cupones", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          codigo,
          porcentajeDescuento: Number(porcentaje),
          usosMaximos: Number(usosMaximos),
          servicioId: servicioId ? Number(servicioId) : null,
          fechaExpiracion: fechaExpiracion || null,
        }),
      });
      const data = await res.json();
      if (!res.ok) {
        setError(data.mensaje ?? "No se pudo crear la beca.");
        return;
      }
      const servicioTitulo = servicioId ? (servicios.find((s) => s.id === Number(servicioId))?.titulo ?? null) : null;
      setCupones((prev) => [{ ...data, servicioTitulo }, ...prev]);
      resetForm();
      setModalAbierto(false);
    } catch {
      setError("No se pudo crear la beca.");
    } finally {
      setEnviando(false);
    }
  }

  async function eliminar(cupon: CuponBeca) {
    if (!confirm("¿Eliminar beca permanentemente?")) return;
    setEliminando(cupon.id);
    try {
      const res = await fetch(`/api/admin/cupones/${cupon.id}`, { method: "DELETE" });
      if (res.ok) setCupones((prev) => prev.filter((c) => c.id !== cupon.id));
    } finally {
      setEliminando(null);
    }
  }

  return (
    <div>
      <div className="flex justify-end mb-6">
        <button
          type="button"
          onClick={() => setModalAbierto(true)}
          className="bg-[#54A6D8] text-white font-bold py-3 px-6 rounded-2xl text-xs uppercase tracking-widest transition-all hover:scale-[1.01] hover:shadow-md hover:bg-blue-600 shadow-sm"
        >
          + Nueva Beca
        </button>
      </div>

      <div className="bg-white border border-gray-100 rounded-3xl overflow-hidden shadow-sm">
        {cupones.length === 0 ? (
          <div className="text-center py-24 bg-gray-50/50">
            <h3 className="text-gray-800 font-bold text-lg tracking-tight">Sin becas activas</h3>
            <p className="text-gray-400 text-xs font-bold mt-1 uppercase tracking-widest">No hay códigos de descuento creados en el sistema.</p>
          </div>
        ) : (
          <div className="flex flex-col">
            {cupones.map((c) => (
              <div key={c.id} className="flex flex-col md:flex-row md:items-center justify-between p-5 border-b border-gray-50 last:border-0 hover:bg-gray-50/50 transition-all gap-4">
                <div className="flex items-center gap-4">
                  <div className="w-12 h-12 rounded-2xl bg-sky-50 text-[#54A6D8] flex items-center justify-center font-black border border-sky-100 shrink-0 text-xl">%</div>
                  <div>
                    <div className="flex items-center gap-2">
                      <code className="text-sm font-extrabold text-gray-900 tracking-tight uppercase">{c.codigo}</code>
                      {c.servicioId ? (
                        <span className="px-2 py-0.5 rounded-md bg-indigo-50 border border-indigo-100 text-indigo-600 text-[9px] font-black uppercase tracking-widest">
                          {c.servicioTitulo ?? `Servicio #${c.servicioId}`}
                        </span>
                      ) : (
                        <span className="px-2 py-0.5 rounded-md bg-gray-100 border border-gray-200 text-gray-500 text-[9px] font-black uppercase tracking-widest">Global</span>
                      )}
                    </div>
                    <div className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mt-1">
                      Expira: {c.fechaExpiracion ? new Date(c.fechaExpiracion).toLocaleDateString("es-CL", { day: "2-digit", month: "short", year: "numeric" }) : "Sin límite"}
                    </div>
                  </div>
                </div>

                <div className="flex items-center justify-between md:justify-end gap-6 w-full md:w-auto">
                  <div className="text-left md:text-right">
                    <span className="block font-black text-emerald-500 text-lg leading-none">{c.porcentajeDescuento}% OFF</span>
                    <span className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mt-1">
                      Usos: <span className="text-gray-700">{c.usosActuales}</span> / {c.usosMaximos}
                    </span>
                  </div>
                  <button
                    type="button"
                    onClick={() => eliminar(c)}
                    disabled={eliminando === c.id}
                    className="w-10 h-10 flex items-center justify-center rounded-2xl bg-white border border-gray-100 text-gray-400 transition-all hover:scale-[1.01] hover:shadow-md hover:text-rose-500 hover:border-rose-200 hover:bg-rose-50 shrink-0 disabled:opacity-50"
                    title="Eliminar beca"
                  >
                    ✕
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {modalAbierto && (
        <div className="fixed inset-0 z-[100] flex items-center justify-center px-4">
          <div className="absolute inset-0 bg-gray-900/40 backdrop-blur-sm" onClick={() => setModalAbierto(false)} />
          <div className="relative w-full max-w-[450px] bg-white rounded-3xl shadow-xl border border-gray-100 p-6 md:p-8">
            <div className="flex justify-between items-center mb-6">
              <div>
                <h3 className="text-xl font-extrabold tracking-tight text-gray-900">Crear Beca</h3>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mt-1">Nuevo código de descuento</p>
              </div>
              <button type="button" onClick={() => setModalAbierto(false)} className="w-8 h-8 flex items-center justify-center rounded-full bg-gray-50 text-gray-400 hover:text-gray-700 transition-all">
                ✕
              </button>
            </div>

            <form onSubmit={crear} className="space-y-4">
              <div>
                <label className="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2 pl-1">Código Identificador</label>
                <input
                  type="text"
                  value={codigo}
                  onChange={(e) => setCodigo(e.target.value)}
                  placeholder="Ej: BECA-JUAN-2026"
                  required
                  className="w-full bg-gray-50 border border-gray-100 rounded-2xl px-4 py-3 outline-none font-bold text-gray-700 uppercase focus:bg-white focus:border-[#54A6D8] focus:ring-2 focus:ring-[#54A6D8]/20 transition-all placeholder:normal-case placeholder:font-medium placeholder:text-gray-400"
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2 pl-1">Descuento (%)</label>
                  <input
                    type="number"
                    value={porcentaje}
                    onChange={(e) => setPorcentaje(e.target.value)}
                    min={1}
                    max={100}
                    required
                    className="w-full bg-gray-50 border border-gray-100 rounded-2xl px-4 py-3 outline-none font-bold text-gray-700 focus:bg-white focus:border-[#54A6D8] focus:ring-2 focus:ring-[#54A6D8]/20 transition-all"
                  />
                </div>
                <div>
                  <label className="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2 pl-1">Límite Usos</label>
                  <input
                    type="number"
                    value={usosMaximos}
                    onChange={(e) => setUsosMaximos(e.target.value)}
                    min={1}
                    required
                    className="w-full bg-gray-50 border border-gray-100 rounded-2xl px-4 py-3 outline-none font-bold text-gray-700 focus:bg-white focus:border-[#54A6D8] focus:ring-2 focus:ring-[#54A6D8]/20 transition-all"
                  />
                </div>
              </div>

              <div>
                <label className="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2 pl-1">Fecha de Expiración (Opcional)</label>
                <input
                  type="date"
                  value={fechaExpiracion}
                  onChange={(e) => setFechaExpiracion(e.target.value)}
                  className="w-full bg-gray-50 border border-gray-100 rounded-2xl px-4 py-3 outline-none font-bold text-gray-700 focus:bg-white focus:border-[#54A6D8] focus:ring-2 focus:ring-[#54A6D8]/20 transition-all text-sm"
                />
              </div>

              <div className="p-4 rounded-2xl border border-indigo-50 bg-indigo-50/30">
                <label className="block text-[10px] font-bold text-indigo-500 uppercase tracking-widest mb-2 pl-1">Exclusividad de Servicio (Opcional)</label>
                <select
                  value={servicioId}
                  onChange={(e) => setServicioId(e.target.value)}
                  className="w-full bg-white border border-indigo-100 rounded-xl px-4 py-3 outline-none font-bold text-gray-700 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-400/20 transition-all cursor-pointer text-sm"
                >
                  <option value="">Cualquier Servicio (Beca Global)</option>
                  {servicios.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.titulo.length > 20 ? `${s.titulo.slice(0, 17)}...` : s.titulo} — {formatoCLP(s.precio)}
                    </option>
                  ))}
                </select>
              </div>

              {error && <p className="text-xs font-medium text-red-600">{error}</p>}

              <button
                type="submit"
                disabled={enviando}
                className="w-full bg-[#54A6D8] text-white font-extrabold py-4 rounded-2xl shadow-sm transition-all mt-4 hover:shadow-md hover:scale-[1.01] text-[11px] uppercase tracking-widest disabled:opacity-50"
              >
                {enviando ? "Activando..." : "Activar Beca"}
              </button>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
