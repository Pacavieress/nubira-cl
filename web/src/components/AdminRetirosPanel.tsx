"use client";

import { useState } from "react";
import type { ConfiguracionFinanciera, SolicitudRetiroAdmin } from "@/lib/api";
import { formatoCLP } from "@/lib/formato";

const BADGE_ESTADO: Record<string, string> = {
  pendiente: "bg-amber-50 text-amber-700 border border-amber-200",
  aprobado: "bg-emerald-50 text-emerald-700 border border-emerald-200",
  rechazado: "bg-red-50 text-red-700 border border-red-200",
};

interface AuditoriaLinea {
  id: number;
  montoAlumno: number;
  montoSubsidio: number;
  montoComision: number;
  liquido: number;
}
interface Auditoria {
  contratos: AuditoriaLinea[];
  totales: { alumno: number; subsidio: number; comision: number; liquido: number };
}

// Puerto de app/admin_retiros.php (544 líneas) + app/api_auditoria_retiro.php — Panel Admin
// Retiros, autorizado por el usuario con alcance completo (aprobar/rechazar dinero real), a
// diferencia de los paneles admin anteriores de esta migración.
//
// El PHP real no integra con MercadoPago ni ningún API bancario: "aprobar" es un registro
// contable — el admin transfiere el dinero manualmente usando los datos bancarios que este
// panel muestra, y RECIÉN DESPUÉS aprueba acá. Aprobar/rechazar mandan un correo real al
// tutor (server/src/lib/correo.ts, SMTP real de Hostinger) — misma redacción exacta que el
// PHP real.
//
// CORRECCIÓN DELIBERADA vs. el PHP real: el guard `estado='pendiente'` en el UPDATE
// (server/src/modules/adminRetiros/adminRetiros.repository.ts) hace que un doble-submit
// devuelva 409 en vez de reprocesar/reenviar el correo — el PHP real no lo tenía.
//
// ACTIVADO A PEDIDO DEL USUARIO: transferencia_id (antes una columna muerta en el PHP real)
// ahora es un campo requerido que el admin completa al aprobar — trazabilidad real de la
// transferencia manual, mostrada después en la fila de la solicitud.
export function AdminRetirosPanel({
  solicitudesIniciales,
  configuracionInicial,
}: {
  solicitudesIniciales: SolicitudRetiroAdmin[];
  configuracionInicial: ConfiguracionFinanciera;
}) {
  const [solicitudes, setSolicitudes] = useState(solicitudesIniciales);
  const [config, setConfig] = useState(configuracionInicial);
  const [guardandoConfig, setGuardandoConfig] = useState(false);
  const [configGuardada, setConfigGuardada] = useState(false);
  const [procesando, setProcesando] = useState<number | null>(null);

  const [modalAprobar, setModalAprobar] = useState<SolicitudRetiroAdmin | null>(null);
  const [transferenciaInput, setTransferenciaInput] = useState("");
  const [errorAprobar, setErrorAprobar] = useState<string | null>(null);

  const [modalAuditoria, setModalAuditoria] = useState<{ solicitudId: number; cargando: boolean; datos: Auditoria | null } | null>(null);

  async function guardarConfiguracion(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setGuardandoConfig(true);
    setConfigGuardada(false);
    try {
      const res = await fetch("/api/admin/retiros/configuracion", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ montoMinimo: config.minimoRetiro, comision: config.comisionActual }),
      });
      if (res.ok) {
        setConfigGuardada(true);
        setTimeout(() => setConfigGuardada(false), 2500);
      } else {
        window.alert("No se pudo guardar la configuración. Revisa los valores.");
      }
    } finally {
      setGuardandoConfig(false);
    }
  }

  function abrirModalAprobar(s: SolicitudRetiroAdmin) {
    setModalAprobar(s);
    setTransferenciaInput("");
    setErrorAprobar(null);
  }

  async function confirmarAprobar() {
    if (!modalAprobar) return;
    const transferenciaId = transferenciaInput.trim();
    if (!transferenciaId) {
      setErrorAprobar("Ingresa la referencia de la transferencia real antes de aprobar.");
      return;
    }
    setProcesando(modalAprobar.id);
    setErrorAprobar(null);
    try {
      const res = await fetch(`/api/admin/retiros/${modalAprobar.id}/aprobar`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ transferenciaId }),
      });
      const data = (await res.json().catch(() => null)) as { ok?: boolean; mensaje?: string; correoEnviado?: boolean } | null;
      if (res.ok && data?.ok) {
        setSolicitudes((prev) =>
          prev.map((x) => (x.id === modalAprobar.id ? { ...x, estado: "aprobado", transferenciaId, fechaPago: new Date().toISOString() } : x)),
        );
        setModalAprobar(null);
        if (data.correoEnviado === false) {
          window.alert("Se aprobó el retiro, pero el correo de confirmación al tutor no pudo enviarse. Avísale por otro medio.");
        }
      } else {
        setErrorAprobar(data?.mensaje ?? "No se pudo aprobar. La solicitud puede haber cambiado de estado — recarga la página.");
      }
    } finally {
      setProcesando(null);
    }
  }

  async function confirmarRechazo(s: SolicitudRetiroAdmin) {
    if (!window.confirm(`¿Rechazar el retiro de ${formatoCLP(s.monto)} de ${s.tutorNombre}? Se le avisará por correo para que revise sus datos bancarios.`)) return;
    setProcesando(s.id);
    try {
      const res = await fetch(`/api/admin/retiros/${s.id}/rechazar`, { method: "POST" });
      const data = (await res.json().catch(() => null)) as { ok?: boolean; mensaje?: string; correoEnviado?: boolean } | null;
      if (res.ok && data?.ok) {
        setSolicitudes((prev) => prev.map((x) => (x.id === s.id ? { ...x, estado: "rechazado" } : x)));
        if (data.correoEnviado === false) {
          window.alert("Se rechazó el retiro, pero el correo al tutor no pudo enviarse. Avísale por otro medio.");
        }
      } else {
        window.alert(data?.mensaje ?? "No se pudo rechazar. La solicitud puede haber cambiado de estado — recarga la página.");
      }
    } finally {
      setProcesando(null);
    }
  }

  async function abrirAuditoria(solicitudId: number) {
    setModalAuditoria({ solicitudId, cargando: true, datos: null });
    const res = await fetch(`/api/admin/retiros/${solicitudId}/auditoria`);
    const datos = (await res.json().catch(() => null)) as Auditoria | null;
    setModalAuditoria({ solicitudId, cargando: false, datos });
  }

  return (
    <>
      <div className="bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
        <form onSubmit={guardarConfiguracion} className="flex flex-col md:flex-row gap-4 md:items-end">
          <div className="flex-1">
            <label className="text-[11px] font-bold text-gray-500 uppercase tracking-wider block mb-1.5">Mínimo Retiro</label>
            <div className="relative">
              <span className="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400 text-sm">$</span>
              <input
                type="number"
                min={1}
                step={1}
                required
                value={config.minimoRetiro}
                onChange={(e) => setConfig((c) => ({ ...c, minimoRetiro: Number(e.target.value) }))}
                className="w-full pl-7 pr-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[#54A6D8]/50 focus:border-[#54A6D8]"
              />
            </div>
          </div>
          <div className="flex-1">
            <label className="text-[11px] font-bold text-gray-500 uppercase tracking-wider block mb-1.5">Comisión Plataforma (%)</label>
            <div className="relative">
              <input
                type="number"
                min={0}
                max={100}
                step={1}
                required
                value={config.comisionActual}
                onChange={(e) => setConfig((c) => ({ ...c, comisionActual: Number(e.target.value) }))}
                className="w-full pl-3 pr-8 py-2 bg-gray-50 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[#54A6D8]/50 focus:border-[#54A6D8]"
              />
              <span className="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 text-sm">%</span>
            </div>
          </div>
          <button
            type="submit"
            disabled={guardandoConfig}
            className="bg-[#54A6D8] hover:bg-blue-600 text-white px-6 py-2.5 rounded-xl text-sm font-bold transition-colors shadow-sm disabled:opacity-50 shrink-0"
          >
            {guardandoConfig ? "Guardando..." : configGuardada ? "✓ Guardado" : "Guardar Ajustes"}
          </button>
        </form>
      </div>

      {solicitudes.length === 0 ? (
        <div className="bg-white rounded-3xl border border-gray-200 border-dashed p-12 text-center">
          <h3 className="text-lg font-bold text-gray-800 mb-1">Sin solicitudes</h3>
          <p className="text-gray-500 text-sm">No hay retiros en esta categoría por el momento.</p>
        </div>
      ) : (
        <>
          <div className="hidden md:block bg-white shadow-sm border border-gray-100 rounded-2xl overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse">
                <thead>
                  <tr className="bg-gray-50 border-b border-gray-100 text-xs font-bold text-gray-500 uppercase tracking-wider">
                    <th className="px-6 py-4">Usuario</th>
                    <th className="px-6 py-4">Datos Bancarios</th>
                    <th className="px-6 py-4">Monto</th>
                    <th className="px-6 py-4">Estado</th>
                    <th className="px-6 py-4 text-right">Acciones</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 text-sm">
                  {solicitudes.map((s) => (
                    <tr key={s.id} className="hover:bg-gray-50/50 transition-colors">
                      <td className="px-6 py-4 align-top">
                        <p className="font-bold text-gray-800">{s.tutorNombre}</p>
                        <p className="text-xs text-gray-500">{s.tutorCorreo}</p>
                        <p className="text-[10px] text-gray-400 mt-1">{new Date(s.fechaSolicitud).toLocaleString("es-CL", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" })}</p>
                        {s.transferenciaId && <p className="text-[10px] text-gray-400 mt-1">Ref: {s.transferenciaId}</p>}
                      </td>
                      <td className="px-6 py-4 align-top">
                        {s.datosBancarios ? (
                          <div className="bg-gray-50 p-3 rounded-lg border border-gray-100 text-[13px] text-gray-600">
                            <p className="mb-1">
                              <span className="font-semibold text-gray-800">Banco:</span> {s.datosBancarios.banco}
                            </p>
                            <p className="mb-1">
                              <span className="font-semibold text-gray-800">Cta:</span> {s.datosBancarios.numeroCuenta} <span className="text-gray-400">({s.datosBancarios.tipoCuenta})</span>
                            </p>
                            <p className="mb-1">
                              <span className="font-semibold text-gray-800">Nombre:</span> {s.datosBancarios.titularNombre}
                            </p>
                            <p>
                              <span className="font-semibold text-gray-800">RUT:</span> {s.datosBancarios.rut}
                            </p>
                          </div>
                        ) : (
                          <span className="text-xs text-red-500 font-medium">Sin datos bancarios</span>
                        )}
                      </td>
                      <td className="px-6 py-4 align-top">
                        <span className="text-lg font-bold text-gray-800">{formatoCLP(s.monto)}</span>
                      </td>
                      <td className="px-6 py-4 align-top">
                        <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-semibold ${BADGE_ESTADO[s.estado]}`}>{s.estado}</span>
                      </td>
                      <td className="px-6 py-4 align-top text-right">
                        <div className="flex flex-col gap-2 w-32 ml-auto">
                          <button type="button" onClick={() => abrirAuditoria(s.id)} className="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-bold px-3 py-2 rounded-lg border border-gray-200">
                            Detalle
                          </button>
                          {s.estado === "pendiente" ? (
                            <>
                              <button
                                type="button"
                                disabled={procesando === s.id}
                                onClick={() => abrirModalAprobar(s)}
                                className="w-full bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold px-3 py-2 rounded-lg shadow-sm disabled:opacity-50"
                              >
                                Aprobar
                              </button>
                              <button
                                type="button"
                                disabled={procesando === s.id}
                                onClick={() => confirmarRechazo(s)}
                                className="w-full bg-white border border-red-200 text-red-600 hover:bg-red-50 text-xs font-bold px-3 py-2 rounded-lg disabled:opacity-50"
                              >
                                Rechazar
                              </button>
                            </>
                          ) : (
                            <span className="text-xs font-medium text-gray-400 bg-gray-100 px-3 py-1.5 rounded-lg border border-gray-200 text-center">Procesada</span>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          <div className="grid gap-4 md:hidden">
            {solicitudes.map((s) => (
              <div key={s.id} className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 relative overflow-hidden">
                <div className={`absolute top-0 left-0 w-full h-1 ${s.estado === "aprobado" ? "bg-emerald-500" : s.estado === "rechazado" ? "bg-red-500" : "bg-amber-400"}`} />
                <div className="flex justify-between items-start mb-3 pt-1">
                  <div>
                    <p className="font-bold text-gray-800 text-sm">{s.tutorNombre}</p>
                    <p className="text-[11px] text-gray-500">{s.tutorCorreo}</p>
                  </div>
                  <p className="text-lg font-extrabold text-gray-800">{formatoCLP(s.monto)}</p>
                </div>
                {s.datosBancarios ? (
                  <div className="bg-gray-50 p-4 rounded-xl border border-gray-100 text-xs text-gray-600 mb-3">
                    <p className="mb-1">
                      <span className="font-semibold text-gray-800">Banco:</span> {s.datosBancarios.banco}
                    </p>
                    <p className="mb-1">
                      <span className="font-semibold text-gray-800">Cta:</span> {s.datosBancarios.numeroCuenta}
                    </p>
                    <p>
                      <span className="font-semibold text-gray-800">RUT:</span> {s.datosBancarios.rut}
                    </p>
                  </div>
                ) : (
                  <p className="text-xs text-red-500 font-medium mb-3">Sin datos bancarios</p>
                )}
                <div className="flex items-center justify-between mb-3">
                  <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[11px] font-bold ${BADGE_ESTADO[s.estado]}`}>{s.estado}</span>
                  <button type="button" onClick={() => abrirAuditoria(s.id)} className="bg-gray-100 border border-gray-200 text-gray-700 text-xs font-bold px-3 py-1.5 rounded-lg">
                    Detalle
                  </button>
                </div>
                {s.estado === "pendiente" && (
                  <div className="flex gap-2">
                    <button
                      type="button"
                      disabled={procesando === s.id}
                      onClick={() => confirmarRechazo(s)}
                      className="w-1/2 bg-white border border-red-200 text-red-600 hover:bg-red-50 text-xs font-bold px-3 py-2 rounded-lg disabled:opacity-50"
                    >
                      Rechazar
                    </button>
                    <button
                      type="button"
                      disabled={procesando === s.id}
                      onClick={() => abrirModalAprobar(s)}
                      className="w-1/2 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold px-3 py-2 rounded-lg disabled:opacity-50"
                    >
                      Aprobar
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>
        </>
      )}

      {modalAprobar && (
        <div className="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-gray-900/40 backdrop-blur-sm" onClick={() => setModalAprobar(null)}>
          <div className="bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden" onClick={(e) => e.stopPropagation()}>
            <div className="px-6 py-4 border-b border-gray-100 bg-emerald-50/50">
              <h3 className="font-bold text-gray-800 text-lg">Aprobar retiro</h3>
              <p className="text-xs text-gray-500 mt-0.5">Confirma que ya transferiste el dinero manualmente antes de continuar.</p>
            </div>
            <div className="p-6 space-y-4">
              <div className="bg-gray-50 rounded-xl p-4 border border-gray-100 text-sm">
                <p className="text-gray-500">
                  Tutor: <b className="text-gray-800">{modalAprobar.tutorNombre}</b>
                </p>
                <p className="text-gray-500 mt-1">
                  Monto: <b className="text-emerald-600 text-lg">{formatoCLP(modalAprobar.monto)}</b>
                </p>
              </div>
              <div>
                <label className="text-[11px] font-bold text-gray-500 uppercase tracking-wider block mb-1.5">Referencia de la transferencia</label>
                <input
                  type="text"
                  autoFocus
                  value={transferenciaInput}
                  onChange={(e) => setTransferenciaInput(e.target.value)}
                  placeholder="Ej. N° de comprobante o folio del banco"
                  className="w-full px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[#54A6D8]/50 focus:border-[#54A6D8]"
                />
                {errorAprobar && <p className="text-red-600 text-xs mt-2">{errorAprobar}</p>}
              </div>
              <div className="flex gap-3">
                <button type="button" onClick={() => setModalAprobar(null)} className="w-1/2 py-3 bg-gray-100 text-gray-700 font-bold rounded-xl hover:bg-gray-200">
                  Cancelar
                </button>
                <button
                  type="button"
                  disabled={procesando === modalAprobar.id}
                  onClick={confirmarAprobar}
                  className="w-1/2 py-3 bg-emerald-500 hover:bg-emerald-600 text-white font-bold rounded-xl disabled:opacity-50"
                >
                  {procesando === modalAprobar.id ? "Procesando..." : "Confirmar"}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {modalAuditoria && (
        <div className="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-gray-900/60 backdrop-blur-sm" onClick={() => setModalAuditoria(null)}>
          <div className="bg-white w-full max-w-2xl rounded-3xl shadow-2xl overflow-hidden" onClick={(e) => e.stopPropagation()}>
            <div className="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
              <div>
                <h3 className="text-lg font-bold text-gray-800">Desglose de Pago</h3>
                <p className="text-xs text-gray-500">Auditoría de contratos vinculados a este retiro</p>
              </div>
              <button type="button" onClick={() => setModalAuditoria(null)} className="text-gray-400 hover:text-gray-600 p-2 hover:bg-gray-100 rounded-full">
                ✕
              </button>
            </div>
            <div className="p-6 max-h-[60vh] overflow-y-auto">
              {modalAuditoria.cargando ? (
                <div className="flex justify-center py-10">
                  <div className="animate-spin h-8 w-8 border-4 border-[#54A6D8] border-t-transparent rounded-full" />
                </div>
              ) : !modalAuditoria.datos || modalAuditoria.datos.contratos.length === 0 ? (
                <p className="text-center text-gray-500 py-6 font-medium">Este retiro corresponde únicamente a ventas de Apuntes/PDFs.</p>
              ) : (
                <div className="space-y-4">
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm text-left">
                      <thead className="text-[10px] uppercase text-gray-400 font-bold border-b border-gray-100">
                        <tr>
                          <th className="pb-2">Contrato</th>
                          <th className="pb-2 text-right">Pagado (Alumno)</th>
                          <th className="pb-2 text-right">Cupón</th>
                          <th className="pb-2 text-right">Comisión</th>
                          <th className="pb-2 text-right">Líquido Tutor</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-50">
                        {modalAuditoria.datos.contratos.map((c) => (
                          <tr key={c.id} className="text-gray-600">
                            <td className="py-3 font-mono text-xs font-semibold text-gray-800">#{c.id}</td>
                            <td className="py-3 text-right">{formatoCLP(c.montoAlumno)}</td>
                            <td className="py-3 text-right text-emerald-600">{c.montoSubsidio > 0 ? `+${formatoCLP(c.montoSubsidio)}` : "-"}</td>
                            <td className="py-3 text-right text-red-500">{c.montoComision > 0 ? `-${formatoCLP(c.montoComision)}` : "-"}</td>
                            <td className="py-3 text-right font-bold text-[#54A6D8]">{formatoCLP(c.liquido)}</td>
                          </tr>
                        ))}
                      </tbody>
                      <tfoot className="border-t-2 border-gray-200 font-bold text-gray-800 bg-gray-50">
                        <tr>
                          <td className="py-4 px-2 text-xs uppercase text-gray-500">Totales</td>
                          <td className="py-4 text-right">{formatoCLP(modalAuditoria.datos.totales.alumno)}</td>
                          <td className="py-4 text-right text-emerald-600">+{formatoCLP(modalAuditoria.datos.totales.subsidio)}</td>
                          <td className="py-4 text-right text-red-500">-{formatoCLP(modalAuditoria.datos.totales.comision)}</td>
                          <td className="py-4 px-2 text-right text-lg text-gray-900">{formatoCLP(modalAuditoria.datos.totales.liquido)}</td>
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                  <p className="text-[11px] text-gray-500 italic bg-blue-50/50 p-3 rounded-lg border border-blue-100">
                    Si el monto liquidado es menor al total solicitado, la diferencia corresponde a ventas de Apuntes.
                  </p>
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </>
  );
}
