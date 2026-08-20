"use client";

import { useState } from "react";
import type { VentaClase } from "@/lib/api";
import { claveDia, etiquetaDia, formatoCLP } from "@/lib/formato";

// Puerto de lectura de app/ventas_clases.php:132-291 — agrupado por día, mismo desglose
// bruto/subsidio/comisión que la página real. SIN selección múltiple ni botón "Ocultar"
// (ver server/src/modules/ventasClases/ventasClases.types.ts para el porqué: esa acción
// dispara un DELETE permanente sobre `contratos` en el PHP real, decisión explícita de
// dejarla pendiente para otra sesión). Grupos cerrados por defecto, igual que el PHP real.

function formatearHora(fechaIso: string): string {
  return new Date(fechaIso).toLocaleTimeString("es-CL", { hour: "2-digit", minute: "2-digit", hour12: false });
}

function subtextoMonto(bruto: number, subsidio: number, comision: number): string {
  if (subsidio > 0 && comision > 0) {
    return `Alumno ${formatoCLP(bruto)} + Subsidio ${formatoCLP(subsidio)} − Comisión ${formatoCLP(comision)}`;
  }
  if (subsidio > 0) return `Alumno ${formatoCLP(bruto)} + Subsidio ${formatoCLP(subsidio)}`;
  if (comision > 0) return `Bruto ${formatoCLP(bruto)} − Comisión ${formatoCLP(comision)}`;
  return "";
}

function agruparPorDia(ventas: VentaClase[]): { clave: string; etiqueta: string; items: VentaClase[] }[] {
  const grupos = new Map<string, VentaClase[]>();
  for (const v of ventas) {
    const fechaBase = v.fechaPago ?? v.fechaCreacion;
    const clave = claveDia(fechaBase);
    if (!grupos.has(clave)) grupos.set(clave, []);
    grupos.get(clave)!.push(v);
  }
  return Array.from(grupos.entries()).map(([clave, items]) => ({
    clave,
    etiqueta: etiquetaDia(items[0]!.fechaPago ?? items[0]!.fechaCreacion),
    items,
  }));
}

function exportarCSV(ventas: VentaClase[]) {
  let csv = "data:text/csv;charset=utf-8,";
  csv += "ID de Venta,Fecha,Titulo,Comprador,Neto,Estado\n";
  for (const v of ventas) {
    const fecha = new Date(v.fechaCreacion).toISOString().slice(0, 10);
    csv += `"${v.idContrato}","${fecha}","${v.titulo.replace(/"/g, '""')}","${v.compradorNombre.replace(/"/g, '""')}","${v.neto}","${v.estado}"\n`;
  }
  const link = document.createElement("a");
  link.setAttribute("href", encodeURI(csv));
  link.setAttribute("download", "Mis_Ganancias.csv");
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

function FilaVenta({ venta, phpSiteUrl }: { venta: VentaClase; phpSiteUrl: string }) {
  const esEnCurso = venta.estado === "en_progreso" || venta.estado === "finalizado_comprador" || venta.estado === "finalizado_vendedor";
  const esLiberado = venta.estado === "liberado";
  const esCancelado = venta.estado === "cancelado";

  let estadoColor = "text-amber-500 bg-amber-50 border border-amber-100";
  let textoEstado = "Pendiente";
  if (esLiberado) {
    estadoColor = "text-emerald-500 bg-emerald-50 border border-emerald-100";
    textoEstado = "Terminada";
  } else if (esEnCurso) {
    estadoColor = "text-blue-500 bg-blue-50 border border-blue-100";
    textoEstado = "En Curso";
  } else if (esCancelado) {
    estadoColor = "text-red-500 bg-red-50 border border-red-100";
    textoEstado = "Cancelada";
  }

  const subtexto = subtextoMonto(venta.bruto, venta.subsidio, venta.comision);

  return (
    <li className="flex items-center justify-between p-4 gap-3">
      <div className="flex items-center gap-3 flex-1 min-w-0">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={venta.imagenUrl}
          alt=""
          className="w-12 h-12 rounded-xl object-cover shrink-0 border border-gray-100 bg-gray-100"
        />
        <div className="flex-1 min-w-0">
          <h3 className="font-medium text-[#222222] text-[14px] line-clamp-1 leading-tight mb-0.5">{venta.titulo}</h3>
          <div className="flex items-center gap-1.5 text-[11px] text-gray-400 font-medium truncate">
            <div className="w-4 h-4 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-[7px] font-bold shrink-0">
              {venta.compradorNombre.charAt(0).toUpperCase()}
            </div>
            <span className="truncate">{venta.compradorNombre}</span>
            <span>•</span>
            <span className="font-mono text-gray-400">{formatearHora(venta.fechaCreacion)}</span>
          </div>
        </div>
      </div>

      <div className="flex flex-col items-end gap-1.5 shrink-0 pl-2">
        <div className="flex flex-col items-end gap-0.5">
          <span className="font-medium text-[#222222] text-[15px] tabular-nums tracking-[-0.01em] leading-none text-right">
            {formatoCLP(venta.neto)}
          </span>
          {subtexto && <span className="text-[10px] text-gray-400 font-medium tabular-nums text-right leading-tight">{subtexto}</span>}
        </div>

        {esEnCurso ? (
          <a
            href={`mailto:${venta.compradorEmail}`}
            className="inline-flex items-center justify-center gap-1.5 bg-gray-100 text-gray-600 hover:bg-gray-200 text-[10px] font-bold px-2 py-1 rounded-md transition-colors"
          >
            Chat
          </a>
        ) : esLiberado && !venta.yaCalificado ? (
          <a
            href={`${phpSiteUrl}/app/evaluar_servicio.php?id=${venta.idContrato}`}
            className="inline-flex items-center justify-center gap-1.5 bg-[#54A6D8]/10 text-[#54A6D8] hover:bg-[#54A6D8]/20 text-[10px] font-bold px-2 py-1 rounded-md transition-colors"
          >
            Nota
          </a>
        ) : venta.yaCalificado ? (
          <span className="text-emerald-500 bg-emerald-50 border border-emerald-100 px-1.5 py-0.5 rounded text-[9px] font-medium uppercase tracking-wider">
            Listo
          </span>
        ) : (
          <span className={`${estadoColor} px-1.5 py-0.5 rounded text-[9px] font-medium uppercase tracking-wider`}>{textoEstado}</span>
        )}
      </div>
    </li>
  );
}

export function VentasClasesLista({ ventas, phpSiteUrl }: { ventas: VentaClase[]; phpSiteUrl: string }) {
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
      <div className="bg-gray-50 p-8 text-center border border-dashed border-gray-200 rounded-2xl mt-4">
        <h3 className="text-base font-medium text-[#222222] tracking-[-0.01em]">Sin ventas operativas</h3>
        <p className="text-gray-400 text-sm mt-1">Cuando recibas compras, aparecerán aquí agrupadas por fecha.</p>
      </div>
    );
  }

  return (
    <div className="pb-4 space-y-4 mt-2">
      {ventas.length > 0 && (
        <div className="flex justify-end">
          <button
            type="button"
            onClick={() => exportarCSV(ventas)}
            className="inline-flex items-center justify-center gap-1.5 bg-emerald-50 text-emerald-700 font-bold py-1.5 px-3 rounded-xl hover:bg-emerald-100 transition text-[11px] tracking-wide"
          >
            Exportar CSV
          </button>
        </div>
      )}

      {grupos.map((grupo) => (
        <div key={grupo.clave}>
          <button
            type="button"
            onClick={() => toggle(grupo.clave)}
            className="w-full px-1 py-2 flex items-center justify-between"
          >
            <div className="flex items-center gap-2">
              <span className="font-bold text-gray-400 text-xs uppercase tracking-widest">{grupo.etiqueta}</span>
              <span className="bg-gray-100 text-gray-600 text-[9px] font-medium px-1.5 py-0.5 rounded-md">{grupo.items.length}</span>
            </div>
            <svg
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              strokeWidth={2}
              stroke="currentColor"
              className={`w-3 h-3 text-gray-400 transition-transform duration-200 ${abiertos.has(grupo.clave) ? "rotate-180" : ""}`}
            >
              <path strokeLinecap="round" strokeLinejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
          </button>

          {abiertos.has(grupo.clave) && (
            <div className="bg-white border border-[#f0f0f0] rounded-2xl shadow-[0_1px_3px_rgba(0,0,0,0.04)] overflow-hidden">
              <ul className="divide-y divide-gray-100">
                {grupo.items.map((v) => (
                  <FilaVenta key={v.idContrato} venta={v} phpSiteUrl={phpSiteUrl} />
                ))}
              </ul>
            </div>
          )}
        </div>
      ))}
    </div>
  );
}
