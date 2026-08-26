import Link from "next/link";
import { redirect } from "next/navigation";
import { getMiBilletera } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { SolicitarRetiroButton } from "@/components/SolicitarRetiroButton";
import { formatoCLP } from "@/lib/formato";

// Puerto de app/datos_bancarios.php — mismo gate (línea 9: sin sesión -> /login) y misma
// lógica de saldo (ganancias apuntes + servicios - retirado/en proceso).
//
// [26/08/2026, Grupo B] "Solicitar Retiro" y "Configurar Banco"/"Cuenta Bancaria de
// Destino" ya NO enlazan a la página PHP real — el razonamiento viejo de este comentario
// (csrf_token de sesión PHP inaccesible desde Node) quedó obsoleto: ver la nota de alcance
// en server/src/modules/miBilletera/miBilletera.types.ts. Ahora "Solicitar Retiro" es un
// fetch() real (SolicitarRetiroButton) y "Configurar Banco" enlaza a /editar-datos-bancarios,
// la página propia de este mismo port.
export default async function MiBilleteraPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login`);
  }

  const billetera = await getMiBilletera();
  const saldoParaMostrar = billetera?.saldoParaMostrar ?? 0;
  const saldoDisponible = billetera?.saldoDisponible ?? 0;
  const minimoRetiro = billetera?.minimoRetiro ?? 10000;
  const comisionActual = billetera?.comisionActual ?? 0;
  const gananciasApuntes = billetera?.gananciasApuntes ?? 0;
  const gananciasServicios = billetera?.gananciasServicios ?? 0;
  const totalRetirado = billetera?.totalRetirado ?? 0;
  const totalGanancias = gananciasApuntes + gananciasServicios;
  const datosBancarios = billetera?.datosBancarios ?? null;
  const historial = billetera?.historialRetiros ?? [];

  const puedeRetirar = saldoDisponible >= minimoRetiro;
  const porcentajeProgreso = Math.min((saldoParaMostrar / minimoRetiro) * 100, 100);

  return (
    <>
      <Header titulo="Mi Billetera" />
      {/* lg:pl-64 en vez de lg:ml-64 — overflow horizontal bajo <body flex flex-col>, ver
          web/src/app/apuntes/page.tsx para el diagnóstico completo. */}
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-8 lg:pl-64 max-w-[1000px] mx-auto space-y-6">
        <header className="mb-2">
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Mi Billetera</h1>
          <p className="text-gray-400 text-xs font-medium">Administra tus ganancias y retiros.</p>
        </header>

        <div className="bg-white rounded-2xl md:rounded-3xl p-5 md:p-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
          <div>
            <p className="text-[10px] md:text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Saldo Disponible</p>
            <p className="text-3xl md:text-4xl font-medium text-[#222222] tracking-[-0.01em]">{formatoCLP(saldoParaMostrar)}</p>

            {saldoDisponible < minimoRetiro && (
              <span className="inline-flex items-center gap-1 mt-1.5 px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-100 text-[10px] font-semibold uppercase tracking-wide">
                Aún no retirable
              </span>
            )}

            <div
              className={`mt-2 inline-flex items-center gap-1.5 ${
                comisionActual === 0 ? "bg-emerald-500/10 text-emerald-600 border-emerald-500/20" : "bg-sky-500/10 text-sky-600 border-sky-500/20"
              } px-2.5 py-1 rounded-md text-[10px] font-medium border`}
            >
              <span>
                {comisionActual === 0 ? (
                  <>
                    Comisión de plataforma: <b>0%</b>. ¡Aprovecha, estás recibiendo el 100% de tus ventas!
                  </>
                ) : (
                  <>
                    Tus ganancias ya reflejan la comisión actual de la plataforma (<b>{comisionActual}%</b>).
                  </>
                )}
              </span>
            </div>

            {totalGanancias > 0 && (
              <div className="flex flex-wrap items-center gap-3 mt-3 text-[11px] text-gray-500 font-medium">
                {gananciasApuntes > 0 && <span className="bg-gray-100 px-2 py-1 rounded-md">Apuntes: {formatoCLP(gananciasApuntes)}</span>}
                {gananciasServicios > 0 && <span className="bg-gray-100 px-2 py-1 rounded-md">Servicios: {formatoCLP(gananciasServicios)}</span>}
                {totalRetirado > 0 && <span className="bg-gray-100 px-2 py-1 rounded-md">Ya retirado/en proceso: {formatoCLP(totalRetirado)}</span>}
              </div>
            )}
          </div>

          <div className="w-full sm:w-1/2 lg:w-1/3 flex flex-col gap-3">
            {puedeRetirar ? (
              datosBancarios ? (
                <SolicitarRetiroButton />
              ) : (
                <Link
                  href="/editar-datos-bancarios"
                  className="w-full bg-[#54A6D8] text-white py-3 rounded-xl font-bold text-sm flex items-center justify-center gap-2 shadow-md"
                >
                  Configurar Banco
                </Link>
              )
            ) : (
              <div className="w-full space-y-3">
                <div className="flex justify-between items-baseline mb-1.5">
                  <span className="text-[12px] font-semibold text-[#222222]">Te faltan {formatoCLP(Math.max(0, minimoRetiro - saldoParaMostrar))} para retirar</span>
                  <span className="text-[11px] font-bold text-[#54A6D8]">{Math.round(porcentajeProgreso)}%</span>
                </div>
                <div className="w-full bg-gray-100 h-2 rounded-full overflow-hidden">
                  <div className="bg-[#54A6D8] h-full transition-all duration-1000 ease-out" style={{ width: `${porcentajeProgreso}%` }} />
                </div>
                <p className="text-[10px] text-gray-400 font-medium text-right mt-1.5">Mínimo: {formatoCLP(minimoRetiro)}</p>
                <button type="button" disabled className="w-full bg-[#54A6D8] text-white py-3 rounded-xl font-bold text-sm opacity-40 cursor-not-allowed">
                  Mínimo {formatoCLP(minimoRetiro)}
                </button>
              </div>
            )}
          </div>
        </div>

        <Link
          href="/editar-datos-bancarios"
          className="flex items-center justify-between bg-white p-4 rounded-2xl border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] hover:bg-gray-50 transition-colors"
        >
          <div className="flex items-center gap-4">
            <div
              className={`w-12 h-12 rounded-xl flex items-center justify-center shrink-0 ${
                datosBancarios ? "bg-gray-50 text-[#54A6D8] border border-[#f0f0f0]" : "bg-red-50 text-red-500 border border-red-100"
              }`}
            >
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0012 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75z"
                />
              </svg>
            </div>
            <div>
              <h3 className="font-medium tracking-[-0.01em] text-[#222222] text-sm md:text-base">Cuenta Bancaria de Destino</h3>
              <p className={`text-xs md:text-sm font-medium mt-0.5 ${datosBancarios ? "text-gray-500" : "text-red-400"}`}>
                {datosBancarios ? `${datosBancarios.banco} • Cta. terminada en ${datosBancarios.numeroCuentaEnmascarado}` : "Atención: Configura tu cuenta para poder retirar"}
              </p>
            </div>
          </div>
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4 text-gray-300">
            <path strokeLinecap="round" strokeLinejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
          </svg>
        </Link>

        <div>
          <h2 className="text-xs font-medium text-gray-400 uppercase tracking-widest mb-3">Historial de Retiros</h2>

          {historial.length > 0 ? (
            <div className="bg-white rounded-2xl border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)] overflow-hidden">
              <ul className="divide-y divide-gray-50">
                {historial.map((r, i) => {
                  const estado = r.estado.toLowerCase();
                  const fecha = new Date(r.fechaSolicitud).toLocaleDateString("es-CL", { day: "2-digit", month: "short", year: "numeric" });
                  const hora = new Date(r.fechaSolicitud).toLocaleTimeString("es-CL", { hour: "2-digit", minute: "2-digit", hour12: false });

                  let bgIcono = "bg-amber-50";
                  let colorEstado = "text-gray-800";
                  let txtEstado = "En proceso";
                  if (estado === "aprobado") {
                    bgIcono = "bg-emerald-50";
                    colorEstado = "text-emerald-500";
                    txtEstado = "Transferencia Exitosa";
                  } else if (estado === "rechazado") {
                    bgIcono = "bg-red-50";
                    colorEstado = "text-red-500 line-through";
                    txtEstado = "Rechazada / Devuelta";
                  }

                  return (
                    <li key={i} className="flex items-center justify-between p-4">
                      <div className="flex items-center gap-4 flex-1 min-w-0">
                        <div className={`w-10 h-10 rounded-full ${bgIcono} flex items-center justify-center shrink-0`} />
                        <div className="flex-1 min-w-0">
                          <h3 className="font-medium tracking-[-0.01em] text-[#222222] text-[13px] md:text-sm truncate">Retiro a Banco</h3>
                          <p className="text-[11px] text-gray-400 font-medium mt-0.5 truncate">
                            {fecha}, {hora} • {txtEstado}
                          </p>
                        </div>
                      </div>
                      <div className="text-right pl-4 shrink-0">
                        <span className={`font-medium ${colorEstado} text-sm tracking-[-0.01em]`}>-{formatoCLP(r.monto)}</span>
                      </div>
                    </li>
                  );
                })}
              </ul>
            </div>
          ) : (
            <div className="flex flex-col items-center justify-center py-12 px-4 text-center bg-white rounded-2xl border border-[#f0f0f0] shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
              <h3 className="text-sm font-medium tracking-[-0.01em] text-[#222222]">No hay retiros registrados</h3>
              <p className="text-gray-400 text-xs mt-1 max-w-xs mx-auto font-medium">Tus transferencias hacia el banco aparecerán aquí.</p>
            </div>
          )}
        </div>
      </main>
    </>
  );
}
