"use client";

import { useState } from "react";
import type { VentaApunte } from "@/lib/api";
import { claveDia, etiquetaDia, formatoCLP } from "@/lib/formato";

// Puerto de lectura de app/ventas_apuntes.php:147-271 — agrupado por día, mismo criterio
// que VentasClasesLista.tsx: SIN selección múltiple/swipe-to-delete/botón "Ocultar" (ver
// server/src/modules/ventasApuntes/ventasApuntes.types.ts — esa acción real es un DELETE
// permanente sobre `ventas_apuntes`, decisión explícita de dejarla para otra sesión).
// Cada fila SÍ conserva el click-through real (ventas_apuntes.php:207/433-435: abre
// /ver-apunte?id={apunte_id} en pestaña nueva) — es navegación de lectura, no la acción
// destructiva, así que se mantiene.

function IconoDocumento() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"
      />
    </svg>
  );
}

function extensionArchivo(archivo: string | null): string {
  if (!archivo) return "DOC";
  const partes = archivo.split(".");
  const ext = partes.length > 1 ? partes[partes.length - 1] : "";
  return ext ? ext.toUpperCase() : "DOC";
}

function formatearHora(fechaIso: string): string {
  return new Date(fechaIso).toLocaleTimeString("es-CL", { hour: "2-digit", minute: "2-digit", hour12: false });
}

function agruparPorDia(ventas: VentaApunte[]): { clave: string; etiqueta: string; items: VentaApunte[] }[] {
  const grupos = new Map<string, VentaApunte[]>();
  for (const v of ventas) {
    const clave = claveDia(v.fecha);
    if (!grupos.has(clave)) grupos.set(clave, []);
    grupos.get(clave)!.push(v);
  }
  return Array.from(grupos.entries()).map(([clave, items]) => ({
    clave,
    etiqueta: etiquetaDia(items[0]!.fecha),
    items,
  }));
}

function exportarCSV(ventas: VentaApunte[]) {
  let csv = "data:text/csv;charset=utf-8,";
  csv += "ID de Venta,Fecha,Hora,Titulo del Apunte,Comprador,Monto (CLP),Estado\n";
  for (const v of ventas) {
    const d = new Date(v.fecha);
    const fecha = d.toISOString().slice(0, 10);
    const hora = d.toISOString().slice(11, 19);
    csv += `"${v.id}","${fecha}","${hora}","${v.titulo.replace(/"/g, '""')}","${v.compradorNombre.replace(/"/g, '""')}","${v.precio}","${
      v.pagadoAlVendedor ? "Pagado" : "Pendiente"
    }"\n`;
  }
  const link = document.createElement("a");
  link.setAttribute("href", encodeURI(csv));
  link.setAttribute("download", "Mis_Ventas_Apuntes.csv");
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

export function VentasApuntesLista({ ventas, phpSiteUrl }: { ventas: VentaApunte[]; phpSiteUrl: string }) {
  const grupos = agruparPorDia(ventas);
  const [abiertos, setAbiertos] = useState<Set<string>>(new Set());

  const toggle = (clave: string) => {
    setAbiertos((prev) => {
      const next = new Set(prev);
      if (next.has(clave)) next.delete(clave);
      else next.add(clave);
      return next;
    });
  };

  if (ventas.length === 0) {
    return (
      <div className="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-8 text-center mt-4">
        <h3 className="text-base font-medium text-[#222222] tracking-[-0.01em]">Aún no hay movimientos</h3>
        <p className="text-gray-400 text-sm mt-1">Tus ventas y descargas aparecerán aquí agrupadas por día.</p>
      </div>
    );
  }

  return (
    <div className="pb-4 space-y-2 mt-2">
      <div className="flex justify-end">
        <button
          type="button"
          onClick={() => exportarCSV(ventas)}
          className="inline-flex items-center justify-center gap-1.5 bg-emerald-50 text-emerald-600 font-bold py-2 px-3.5 rounded-xl hover:bg-emerald-100 transition text-xs tracking-wide"
        >
          Exportar CSV
        </button>
      </div>

      {grupos.map((grupo) => (
        <div key={grupo.clave} className="mt-4">
          <button type="button" onClick={() => toggle(grupo.clave)} className="w-full px-1 pt-4 pb-2 flex items-center justify-between">
            <h2 className="text-xs font-bold text-gray-400 uppercase tracking-widest">
              {grupo.etiqueta} ({grupo.items.length})
            </h2>
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              strokeWidth={2}
              stroke="currentColor"
              className={`w-3 h-3 text-gray-400 transition-transform duration-300 ${abiertos.has(grupo.clave) ? "rotate-180" : ""}`}
            >
              <path strokeLinecap="round" strokeLinejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
          </button>

          {abiertos.has(grupo.clave) && (
            <div className="bg-white border border-[#f0f0f0] rounded-2xl shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
              <ul className="divide-y divide-gray-100">
                {grupo.items.map((v) => (
                  <li key={v.id}>
                    <a
                      href={`${phpSiteUrl}/ver-apunte?id=${v.apunteId}`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="flex items-center py-4 px-4 hover:bg-gray-50 transition-colors"
                    >
                      <div className="w-12 h-12 rounded-xl bg-gray-50 text-gray-500 flex flex-col items-center justify-center shrink-0 border border-gray-100">
                        <IconoDocumento />
                        <span className="text-[7px] font-black uppercase tracking-widest opacity-70 mt-0.5">{extensionArchivo(v.archivo)}</span>
                      </div>

                      <div className="flex-1 min-w-0 mx-3.5">
                        <h3 className="font-medium text-[#222222] text-[14px] line-clamp-1 leading-tight mb-0.5">{v.titulo}</h3>
                        <div className="flex items-center gap-1.5 text-[11px] text-gray-400 font-medium truncate">
                          <div className="w-4 h-4 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-[7px] font-bold shrink-0">
                            {v.compradorNombre.charAt(0).toUpperCase()}
                          </div>
                          <span className="truncate">{v.compradorNombre}</span>
                          <span>•</span>
                          <span className="font-mono text-gray-400">{formatearHora(v.fecha)}</span>
                        </div>
                      </div>

                      <div className="shrink-0 text-right flex flex-col items-end">
                        {v.precio > 0 ? (
                          <span className="font-medium text-[#222222] text-[15px] tabular-nums tracking-[-0.01em] leading-none">
                            +{formatoCLP(v.precio)}
                          </span>
                        ) : (
                          <span className="text-gray-400 font-medium text-xs">Gratis</span>
                        )}
                        <p
                          className={`${
                            v.pagadoAlVendedor
                              ? "text-emerald-500 bg-emerald-50 border border-emerald-100"
                              : "text-amber-500 bg-amber-50 border border-amber-100"
                          } px-1.5 py-0.5 rounded text-[9px] font-medium uppercase tracking-wider mt-1.5`}
                        >
                          {v.pagadoAlVendedor ? "Pagado" : "Pendiente"}
                        </p>
                      </div>
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      ))}
    </div>
  );
}
