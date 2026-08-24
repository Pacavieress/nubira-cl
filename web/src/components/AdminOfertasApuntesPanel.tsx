"use client";

import { useState } from "react";
import type { OfertaApunte } from "@/lib/api";

// Puerto de admin_ofertas_apuntes.php — listado + las 3 mutaciones completas (modificar
// precio, aplicar promo, quitar promo). Todas son UPDATE puros sobre `apuntes`, sin efecto
// externo — a diferencia de otros paneles de esta ronda, acá no hay nada excluido.
export function AdminOfertasApuntesPanel({ apuntes: apuntesIniciales }: { apuntes: OfertaApunte[] }) {
  const [apuntes, setApuntes] = useState(apuntesIniciales);
  const [precios, setPrecios] = useState<Record<number, string>>(() => Object.fromEntries(apuntesIniciales.map((a) => [a.id, String(a.precio)])));
  const [cupos, setCupos] = useState<Record<number, string>>({});
  const [guardando, setGuardando] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  function actualizarLocal(id: number, cambios: Partial<OfertaApunte>) {
    setApuntes((prev) => prev.map((a) => (a.id === id ? { ...a, ...cambios } : a)));
  }

  async function guardarPrecio(a: OfertaApunte) {
    const nuevoPrecio = Number(precios[a.id]);
    if (!Number.isInteger(nuevoPrecio) || nuevoPrecio < 0) {
      setError("Precio inválido.");
      return;
    }
    setError(null);
    setGuardando(a.id);
    try {
      const res = await fetch(`/api/admin/ofertas-apuntes/${a.id}/precio`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ precio: nuevoPrecio }),
      });
      if (res.ok) actualizarLocal(a.id, { precio: nuevoPrecio });
      else setError("No se pudo actualizar el precio.");
    } finally {
      setGuardando(null);
    }
  }

  async function aplicarPromo(a: OfertaApunte) {
    const cantidadCupos = Number(cupos[a.id]);
    if (!Number.isInteger(cantidadCupos) || cantidadCupos <= 0) {
      setError("Ingresa la cantidad de cupos.");
      return;
    }
    setError(null);
    setGuardando(a.id);
    try {
      const res = await fetch(`/api/admin/ofertas-apuntes/${a.id}/aplicar-promo`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ cupos: cantidadCupos }),
      });
      if (res.ok) {
        actualizarLocal(a.id, { promoGratis: true, promoLimite: cantidadCupos, promoContador: 0 });
        setCupos((prev) => ({ ...prev, [a.id]: "" }));
      } else {
        setError("No se pudo activar la promo.");
      }
    } finally {
      setGuardando(null);
    }
  }

  async function quitarPromo(a: OfertaApunte) {
    if (!confirm("¿Apagar promo de este apunte?")) return;
    setError(null);
    setGuardando(a.id);
    try {
      const res = await fetch(`/api/admin/ofertas-apuntes/${a.id}/quitar-promo`, { method: "POST" });
      if (res.ok) actualizarLocal(a.id, { promoGratis: false, promoLimite: 0, promoContador: 0 });
      else setError("No se pudo desactivar la promo.");
    } finally {
      setGuardando(null);
    }
  }

  return (
    <div className="space-y-3">
      {error && <div className="bg-red-50 text-red-600 p-3 rounded-xl text-sm font-medium border border-red-200">{error}</div>}

      <div className="bg-white border border-gray-100 rounded-3xl overflow-hidden min-h-[300px]">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm whitespace-nowrap">
            <thead className="bg-gray-50 text-gray-500 text-[11px] uppercase font-bold tracking-widest border-b border-gray-100">
              <tr>
                <th className="px-6 py-4">Apunte</th>
                <th className="px-6 py-4">Tarifa Normal</th>
                <th className="px-6 py-4">Estado Promo</th>
                <th className="px-6 py-4 text-right">Acción</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {apuntes.length === 0 ? (
                <tr>
                  <td colSpan={4} className="text-center py-16 text-gray-400">
                    No se encontraron apuntes aprobados.
                  </td>
                </tr>
              ) : (
                apuntes.map((a) => {
                  const activa = a.promoGratis && a.promoContador < a.promoLimite;
                  const agotada = a.promoGratis && a.promoContador >= a.promoLimite;

                  return (
                    <tr key={a.id} className="hover:bg-gray-50/50 transition-colors align-middle">
                      <td className="px-6 py-4">
                        <p className="font-bold text-gray-900 truncate max-w-[300px]">{a.titulo}</p>
                        <p className="text-xs text-gray-500 mt-0.5">Por {a.tutorNombre}</p>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2">
                          <span className="text-gray-400 font-bold">$</span>
                          <input
                            type="number"
                            min={0}
                            value={precios[a.id] ?? ""}
                            onChange={(e) => setPrecios((prev) => ({ ...prev, [a.id]: e.target.value }))}
                            className="w-24 px-2 py-1.5 text-sm font-bold text-gray-600 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#54A6D8] outline-none"
                          />
                          <button
                            type="button"
                            onClick={() => guardarPrecio(a)}
                            disabled={guardando === a.id}
                            title="Guardar Precio"
                            className="text-gray-400 hover:text-[#54A6D8] transition-colors p-1 disabled:opacity-50"
                          >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="w-4 h-4">
                              <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M9 13.5h6m-6-3h6m3.75 8.25H5.25a1.5 1.5 0 0 1-1.5-1.5V5.25a1.5 1.5 0 0 1 1.5-1.5h6.879a1.5 1.5 0 0 1 1.06.44l4.372 4.372a1.5 1.5 0 0 1 .439 1.06V18.75a1.5 1.5 0 0 1-1.5 1.5Z"
                              />
                            </svg>
                          </button>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        {a.promoGratis ? (
                          <>
                            <span
                              className={`inline-flex items-center px-2 py-1 rounded-md text-[10px] font-bold uppercase mb-1 ${
                                activa ? "bg-orange-500 text-white" : agotada ? "bg-red-100 text-red-600" : "bg-gray-100 text-gray-500"
                              }`}
                            >
                              {activa ? "Activa" : "Agotada"}
                            </span>
                            <p className="text-xs font-bold text-gray-500">
                              Usados: {a.promoContador}/{a.promoLimite}
                            </p>
                          </>
                        ) : (
                          <span className="inline-flex items-center px-2 py-1 rounded-md text-[10px] font-bold uppercase bg-gray-100 text-gray-500">Normal</span>
                        )}
                      </td>
                      <td className="px-6 py-4 text-right">
                        {a.promoGratis ? (
                          <button
                            type="button"
                            onClick={() => quitarPromo(a)}
                            disabled={guardando === a.id}
                            className="text-red-500 border border-red-200 hover:bg-red-500 hover:text-white font-bold text-xs px-4 py-2 rounded-xl transition-all disabled:opacity-50"
                          >
                            Apagar Promo
                          </button>
                        ) : (
                          <div className="flex items-center justify-end gap-2">
                            <input
                              type="number"
                              min={1}
                              placeholder="Cant. Descargas"
                              value={cupos[a.id] ?? ""}
                              onChange={(e) => setCupos((prev) => ({ ...prev, [a.id]: e.target.value }))}
                              className="w-32 px-3 py-2 text-sm font-bold border border-gray-200 rounded-xl focus:ring-2 focus:ring-[#54A6D8] outline-none"
                            />
                            <button
                              type="button"
                              onClick={() => aplicarPromo(a)}
                              disabled={guardando === a.id}
                              className="bg-[#54A6D8] text-white px-4 py-2 rounded-xl text-xs font-bold hover:bg-blue-600 transition-all disabled:opacity-50"
                            >
                              Liberar Gratis
                            </button>
                          </div>
                        )}
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
