"use client";

import { useState } from "react";
import type { AutorServicio } from "@/lib/api";

const TIPOS_LABEL: Record<string, string> = { estudiante: "Estudiante", egresado: "Egresado", profesor: "Profesor", particular: "Particular" };

// Puerto de admin_autores_servicios.php — SOLO el directorio + historial de correos ya
// enviados (100% lectura). El modal "Escribir correo" del PHP real (envía un correo nuevo
// vía /app/enviar_correo_autor.php) queda fuera de alcance — ver nota en
// server/src/modules/adminAutores/adminAutores.types.ts.
export function AdminAutoresPanel({ autores }: { autores: AutorServicio[] }) {
  const [detalle, setDetalle] = useState<AutorServicio["ultimoCorreo"] | null>(null);

  return (
    <>
      <div className="bg-white border border-gray-100 rounded-3xl overflow-hidden min-h-[400px]">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[1000px] text-sm text-left">
            <thead className="text-[11px] text-gray-400 uppercase tracking-widest bg-gray-50 border-b border-gray-100">
              <tr>
                <th className="px-6 py-4 font-bold text-center w-12">#</th>
                <th className="px-6 py-4 font-bold">Autor</th>
                <th className="px-6 py-4 font-bold">Institución</th>
                <th className="px-6 py-4 font-bold text-center">Rendimiento</th>
                <th className="px-6 py-4 font-bold text-center">Última Pub.</th>
                <th className="px-6 py-4 font-bold text-right">Comunicación</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {autores.length === 0 ? (
                <tr>
                  <td colSpan={6} className="text-center py-16 text-gray-400">
                    No se encontraron autores registrados.
                  </td>
                </tr>
              ) : (
                autores.map((a, i) => {
                  const todosConHorario = a.cantidadServicios > 0 && a.serviciosConHorario === a.cantidadServicios;
                  const tipoLabel = a.tipo ? (TIPOS_LABEL[a.tipo] ?? "Sin definir") : "Sin definir";
                  return (
                    <tr key={a.idUsuario} className="hover:bg-gray-50 transition-colors align-middle">
                      <td className="px-6 py-4 text-center text-gray-400 font-mono text-xs">{i + 1}</td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          {/* eslint-disable-next-line @next/next/no-img-element -- portada dinámica de servicio */}
                          <img
                            src={a.portadaUrl}
                            className="w-12 h-10 rounded-xl object-cover border border-gray-200 shrink-0"
                            loading="lazy"
                            alt="Portada"
                            onError={(e) => {
                              // Puerto de admin_autores_servicios.php:206 (onerror inline) — hallazgo real
                              // (no una suposición): varias filas de servicios.imagen en la BD local
                              // apuntan a archivos .webp que ya no existen en disco (gap de sincronización
                              // de assets, no un bug de esta pieza). Sin este fallback, esas filas muestran
                              // el ícono de imagen rota en vez de la portada genérica.
                              const target = e.currentTarget;
                              if (target.dataset.fallbackAplicado) return;
                              target.dataset.fallbackAplicado = "1";
                              try {
                                const url = new URL(target.src);
                                url.pathname = "/upload/servicios/default_clases.webp";
                                target.src = url.toString();
                              } catch {
                                target.src = "/upload/servicios/default_clases.webp";
                              }
                            }}
                          />
                          <div className="min-w-0">
                            <div className="flex items-center gap-1.5 mb-0.5">
                              <p className="font-bold text-gray-900 text-sm truncate max-w-[160px]">{a.nombre}</p>
                              <span title={a.fotoPerfil ? "Con foto de perfil" : "Sin foto de perfil"} className={`${a.fotoPerfil ? "text-emerald-500" : "text-amber-400"} text-xs`}>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" className="w-3 h-3 inline">
                                  <path d="M12 9a3.75 3.75 0 100 7.5A3.75 3.75 0 0012 9z" />
                                  <path fillRule="evenodd" d="M9.344 3.071a49.52 49.52 0 015.312 0c.967.052 1.83.585 2.332 1.39l.821 1.317c.24.383.645.643 1.11.71.386.054.77.113 1.152.177 1.432.239 2.429 1.493 2.429 2.909V18a3 3 0 01-3 3h-15a3 3 0 01-3-3V9.574c0-1.416.997-2.67 2.429-2.909.382-.064.766-.123 1.151-.178a1.56 1.56 0 001.11-.71l.822-1.315a2.942 2.942 0 012.332-1.39zM6.75 12.75a5.25 5.25 0 1110.5 0 5.25 5.25 0 01-10.5 0zm12-1.5a.75.75 0 100-1.5.75.75 0 000 1.5z" clipRule="evenodd" />
                                </svg>
                              </span>
                              <span title={a.bio ? "Con bio" : "Sin bio"} className={`${a.bio ? "text-emerald-500" : "text-amber-400"} text-xs`}>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3 h-3 inline">
                                  <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                                </svg>
                              </span>
                              <span title={todosConHorario ? "Todos con horario" : "Servicios sin horario"} className={`${todosConHorario ? "text-emerald-500" : "text-amber-400"} text-[10px] font-bold ml-0.5`}>
                                {a.serviciosConHorario}/{a.cantidadServicios}
                              </span>
                            </div>
                            <p className="text-[10px] font-medium text-gray-500 truncate max-w-[200px]">{a.correo}</p>
                            <p className="text-[10px] font-medium text-gray-400 mt-0.5">{tipoLabel}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-xs font-medium text-gray-600 truncate max-w-[150px]">{a.institucion ?? "-"}</td>
                      <td className="px-6 py-4 text-center">
                        <div className="flex items-center justify-center gap-2">
                          <div className="flex items-center gap-1 bg-blue-50 text-[#54A6D8] px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-widest" title="Servicios Publicados">
                            {a.cantidadServicios}
                          </div>
                          <div className={`flex items-center gap-1 px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-widest ${a.totalConversaciones > 0 ? "bg-emerald-50 text-emerald-600" : "bg-gray-50 text-gray-400"}`} title="Chats">
                            {a.totalConversaciones}
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-center text-xs font-medium text-gray-500">
                        {a.ultimaPublicacion ? (
                          <>
                            {new Date(a.ultimaPublicacion).toLocaleDateString("es-CL", { day: "2-digit", month: "2-digit", year: "numeric" })}
                            <br />
                            <span className="text-[10px] text-gray-400">{new Date(a.ultimaPublicacion).toLocaleTimeString("es-CL", { hour: "2-digit", minute: "2-digit" })}</span>
                          </>
                        ) : (
                          <span className="text-gray-300">-</span>
                        )}
                      </td>
                      <td className="px-6 py-4 text-right">
                        {a.ultimoCorreo ? (
                          <button
                            type="button"
                            onClick={() => setDetalle(a.ultimoCorreo)}
                            className="text-gray-400 hover:text-gray-600 text-[10px] font-bold uppercase tracking-widest transition-colors"
                          >
                            Historial
                          </button>
                        ) : (
                          <span className="text-gray-300 text-[9px] font-bold uppercase tracking-widest">Sin envíos</span>
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

      {detalle && (
        <div className="fixed inset-0 bg-gray-900/40 backdrop-blur-sm z-[120] flex items-center justify-center p-4" onClick={() => setDetalle(null)}>
          <div className="bg-white rounded-3xl w-full max-w-md relative p-6 md:p-8" onClick={(e) => e.stopPropagation()}>
            <button type="button" onClick={() => setDetalle(null)} className="absolute top-5 right-5 text-gray-400 w-8 h-8 bg-gray-50 rounded-full flex items-center justify-center">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3.5 h-3.5">
                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
            <h2 className="text-lg font-bold text-gray-900 mb-5">Detalle de Envío</h2>
            <div className="space-y-4">
              <div className="bg-gray-50 p-4 rounded-2xl border border-gray-100">
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Asunto</p>
                <p className="font-bold text-gray-800 text-sm">{detalle.asunto ?? "—"}</p>
              </div>
              <div className="flex gap-4">
                <div className="flex-1 bg-gray-50 p-4 rounded-2xl border border-gray-100">
                  <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Fecha</p>
                  <p className="font-bold text-gray-800 text-sm">{new Date(detalle.fecha).toLocaleString("es-CL", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" })}</p>
                </div>
                <div className="flex-1 bg-gray-50 p-4 rounded-2xl border border-gray-100">
                  <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Estado</p>
                  <p className={`font-bold text-sm ${detalle.exito ? "text-emerald-600" : "text-red-500"}`}>{detalle.exito ? "Exitoso" : "Fallido"}</p>
                </div>
              </div>
              <div className="bg-white border border-gray-200 p-4 rounded-2xl max-h-60 overflow-y-auto">
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Cuerpo del Mensaje</p>
                <div className="text-sm text-gray-600 font-medium leading-relaxed whitespace-pre-line">{detalle.mensaje ?? "—"}</div>
              </div>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
