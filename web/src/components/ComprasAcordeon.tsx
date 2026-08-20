"use client";

import { useState } from "react";
import type { ApunteComprado, ServicioContratado } from "@/lib/api";
import { formatoCLP } from "@/lib/formato";

// Puerto de las 2 secciones-acordeón de app/mis_compras.php:144-293. Ambas empiezan
// cerradas (mismo estado inicial que la clase `collapsed` del PHP real).
//
// Íconos: el PHP real usa FontAwesome (fa-file-pdf/fa-file-word/fa-image/fa-download/etc.)
// — web/ no ha incorporado FontAwesome en ninguna página construida hasta ahora, usa
// exclusivamente SVG inline estilo Heroicons (ver app/iconos.php -> "Migrando a
// Heroicons-style outline SVGs" en CLAUDE.md). Mejora de consistencia deliberada, no
// simplificación: en vez de sumar una dependencia nueva (CDN de FontAwesome) solo para
// esta página, se usan los mismos SVG outline que ya usa el resto de web/. La distinción
// PDF/DOC/imagen se preserva igual — el PHP real YA la comunica sobre todo vía el badge de
// texto ($iconTxt: "PDF"/"DOC"/"JPG"), no solo el glifo, así que un ícono de documento
// genérico + el mismo badge de texto no pierde información real.
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

function IconoDescargar() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3.5 h-3.5">
      <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
    </svg>
  );
}

function IconoCandado() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3.5 h-3.5">
      <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
    </svg>
  );
}

function IconoPuerta() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3.5 h-3.5">
      <path strokeLinecap="round" strokeLinejoin="round" d="M9 4.5v15m4.5-15v15m3.75-15h-15A2.25 2.25 0 0 0 0 6.75v10.5A2.25 2.25 0 0 0 2.25 19.5h15A2.25 2.25 0 0 0 19.5 17.25V6.75A2.25 2.25 0 0 0 17.25 4.5Z" />
    </svg>
  );
}

function IconoChevron({ abierto }: { abierto: boolean }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      fill="none"
      viewBox="0 0 24 24"
      strokeWidth={2}
      stroke="currentColor"
      className={`w-3 h-3 text-gray-400 transition-transform duration-300 ${abierto ? "rotate-180" : ""}`}
    >
      <path strokeLinecap="round" strokeLinejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
    </svg>
  );
}

function extensionArchivo(archivo: string | null): string {
  if (!archivo) return "DOC";
  const partes = archivo.split(".");
  const ext = partes.length > 1 ? partes[partes.length - 1] : "";
  return ext ? ext.toUpperCase() : "DOC";
}

function formatearNombrePrivado(nombreCompleto: string): string {
  const partes = nombreCompleto.trim().split(/\s+/).filter(Boolean);
  if (partes.length === 0) return "Usuario";
  const pNombre = partes[0]!.charAt(0).toUpperCase() + partes[0]!.slice(1).toLowerCase();
  if (partes.length < 2) return pNombre;
  const idx = partes.length >= 3 ? 2 : 1;
  const inicial = partes[idx]?.charAt(0).toUpperCase() ?? "";
  return inicial ? `${pNombre} ${inicial}.` : pNombre;
}

const ESTILOS_ESTADO: Record<string, string> = {
  pendiente_pago: "bg-amber-50 text-amber-500 border border-amber-100",
  en_progreso: "bg-blue-50 text-[#54A6D8] border border-blue-100",
  finalizado_vendedor: "bg-purple-50 text-purple-500 border border-purple-100",
  finalizado_comprador: "bg-purple-50 text-purple-500 border border-purple-100",
  liberado: "bg-emerald-50 text-emerald-500 border border-emerald-100",
  cancelado: "bg-red-50 text-red-500 border border-red-100",
};

const TEXTO_ESTADO: Record<string, string> = {
  pendiente_pago: "Pendiente",
  en_progreso: "En Curso",
  finalizado_vendedor: "Finalizado",
  finalizado_comprador: "Finalizado",
  liberado: "Completado",
  cancelado: "Cancelado",
};

const CERRADOS = ["finalizado_vendedor", "finalizado_comprador", "liberado", "cancelado"];

export function ComprasAcordeon({
  apuntes,
  servicios,
  phpSiteUrl,
}: {
  apuntes: ApunteComprado[];
  servicios: ServicioContratado[];
  phpSiteUrl: string;
}) {
  const [apuntesAbierto, setApuntesAbierto] = useState(false);
  const [serviciosAbierto, setServiciosAbierto] = useState(false);

  return (
    <div className="space-y-2 mt-2">
      <div className="space-y-1">
        <button
          type="button"
          onClick={() => setApuntesAbierto((v) => !v)}
          className="w-full px-1 pt-4 pb-2 flex items-center justify-between"
        >
          <h2 className="text-xs font-bold text-gray-400 uppercase tracking-widest">Apuntes Comprados ({apuntes.length})</h2>
          <IconoChevron abierto={apuntesAbierto} />
        </button>

        {apuntesAbierto && (
          <div className="bg-white border border-[#f0f0f0] rounded-2xl shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
            {apuntes.length > 0 ? (
              <ul className="divide-y divide-gray-100">
                {apuntes.map((c) => {
                  const aprobado = c.estadoPago === "pagado";
                  return (
                    <li key={c.id} className="flex items-center justify-between p-4 gap-3">
                      <div className="flex items-center gap-3 flex-1 min-w-0">
                        <div className="w-12 h-12 rounded-xl bg-gray-50 text-gray-500 flex flex-col items-center justify-center shrink-0 border border-gray-100">
                          <IconoDocumento />
                          <span className="text-[7px] font-black uppercase tracking-widest opacity-70 mt-0.5">
                            {extensionArchivo(c.archivo)}
                          </span>
                        </div>
                        <div className="flex-1 min-w-0">
                          <h3 className="font-medium text-[#222222] text-[14px] line-clamp-1 leading-tight mb-0.5">{c.titulo}</h3>
                          <div className="flex items-center gap-1.5 text-[11px] text-gray-400 font-medium truncate">
                            <span
                              className={`${
                                aprobado ? "text-emerald-500 bg-emerald-50 border border-emerald-100" : "text-amber-500 bg-amber-50 border border-amber-100"
                              } px-1.5 py-0.5 rounded text-[10px] font-medium uppercase tracking-wide`}
                            >
                              {aprobado ? "Pagado" : "Pendiente"}
                            </span>
                            <span>•</span>
                            <span className="truncate">{c.asignatura ?? ""}</span>
                          </div>
                        </div>
                      </div>

                      <div className="flex flex-col items-end gap-1.5 shrink-0 pl-2">
                        <span className="font-medium text-[#222222] text-[15px] tabular-nums tracking-[-0.01em] leading-none text-right">
                          {formatoCLP(c.monto)}
                        </span>
                        <div className="flex justify-end items-center gap-1 bg-gray-100 p-1 rounded-full">
                          {aprobado ? (
                            <a
                              href={`${phpSiteUrl}/ver-apunte?archivo=${encodeURIComponent(c.archivo ?? "")}`}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="w-7 h-7 flex items-center justify-center rounded-full text-gray-500 hover:bg-white hover:text-[#54A6D8] transition"
                              title="Descargar/Ver Apunte"
                            >
                              <IconoDescargar />
                            </a>
                          ) : (
                            <button disabled className="w-7 h-7 flex items-center justify-center rounded-full text-gray-300 cursor-not-allowed" title="Pago Pendiente">
                              <IconoCandado />
                            </button>
                          )}
                        </div>
                      </div>
                    </li>
                  );
                })}
              </ul>
            ) : (
              <div className="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-6 text-center">
                <p className="text-sm font-medium text-gray-400">No has comprado apuntes aún.</p>
              </div>
            )}
          </div>
        )}
      </div>

      <div className="space-y-1 mt-4">
        <button
          type="button"
          onClick={() => setServiciosAbierto((v) => !v)}
          className="w-full px-1 pt-4 pb-2 flex items-center justify-between"
        >
          <h2 className="text-xs font-bold text-gray-400 uppercase tracking-widest">Servicios Contratados ({servicios.length})</h2>
          <IconoChevron abierto={serviciosAbierto} />
        </button>

        {serviciosAbierto && (
          <div className="bg-white border border-[#f0f0f0] rounded-2xl shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
            {servicios.length > 0 ? (
              <ul className="divide-y divide-gray-100">
                {servicios.map((s) => {
                  const clase = ESTILOS_ESTADO[s.estado] ?? "bg-gray-50 text-gray-500 border border-gray-100";
                  const texto = TEXTO_ESTADO[s.estado] ?? "Revisión";
                  const cerrado = CERRADOS.includes(s.estado);
                  const nombrePrivado = formatearNombrePrivado(s.vendedorNombre);

                  return (
                    <li key={s.id} className="flex items-center justify-between p-4 gap-3">
                      <div className="flex items-center gap-3 flex-1 min-w-0">
                        <div className="w-12 h-12 rounded-xl bg-gray-50 text-gray-400 flex items-center justify-center shrink-0 border border-gray-100">
                          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5">
                            <path
                              strokeLinecap="round"
                              strokeLinejoin="round"
                              d="M4.26 10.147a60.436 60.436 0 0 0-.491 6.347A48.627 48.627 0 0 1 12 20.904a48.627 48.627 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.57 50.57 0 0 0-2.658-.813A59.905 59.905 0 0 1 12 3.493a59.902 59.902 0 0 1 10.499 5.516 51.55 51.55 0 0 1-2.657.813m-15.482 0A50.923 50.923 0 0 1 12 13.489a50.92 50.92 0 0 1 10.491-3.342"
                            />
                          </svg>
                        </div>
                        <div className="flex-1 min-w-0">
                          <h3 className="font-medium text-[#222222] text-[14px] line-clamp-1 leading-tight mb-0.5">{s.titulo}</h3>
                          <div className="flex items-center gap-1.5 text-[11px] text-gray-400 font-medium truncate">
                            <div className="w-4 h-4 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-[7px] font-bold shrink-0">
                              {nombrePrivado.charAt(0).toUpperCase()}
                            </div>
                            <span className="truncate">{nombrePrivado}</span>
                            <span>•</span>
                            <span className={`${clase} px-1.5 py-0.5 rounded text-[10px] font-medium uppercase tracking-wide`}>{texto}</span>
                          </div>
                        </div>
                      </div>

                      <div className="flex flex-col items-end gap-1.5 shrink-0 pl-2">
                        <span className="font-medium text-[#222222] text-[15px] tabular-nums tracking-[-0.01em] leading-none text-right">
                          {formatoCLP(s.monto)}
                        </span>
                        <div className="flex justify-end items-center gap-1 bg-gray-100 p-1 rounded-full">
                          {!cerrado && s.estado !== "" ? (
                            <a
                              href={`${phpSiteUrl}/app/mini_aula.php?id=${s.id}`}
                              className="w-7 h-7 flex items-center justify-center rounded-full text-gray-500 hover:bg-white hover:text-emerald-500 transition"
                              title="Ir al Aula"
                            >
                              <IconoPuerta />
                            </a>
                          ) : (
                            <button disabled className="w-7 h-7 flex items-center justify-center rounded-full text-gray-300 cursor-not-allowed" title="Servicio Cerrado">
                              <IconoCandado />
                            </button>
                          )}
                        </div>
                      </div>
                    </li>
                  );
                })}
              </ul>
            ) : (
              <div className="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-6 text-center">
                <p className="text-sm font-medium text-gray-400">No has contratado servicios aún.</p>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
