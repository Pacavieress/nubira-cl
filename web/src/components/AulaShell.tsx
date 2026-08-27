"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import type { AulaDetalle, ArchivoContrato, MensajeAula } from "@/lib/api";
import { AulaChat } from "@/components/AulaChat";
import { AulaMateriales } from "@/components/AulaMateriales";

// Puerto de app/mini_aula.php (shell) — Grupo Mini Aula, Pieza 2 (27/08/2026). Sin
// video/WebRTC (Fase 4, decisión de arquitectura propia) ni pizarra (agrupada con video) —
// "Entrar a la Sala" bridgea al sitio PHP real, que sí tiene la videollamada funcionando.
//
// La ventana de gracia post-horario (detalle.videoHabilitado) ya incluye la extensión real
// por actividad (Fase 3, sala_presencia) además de la gracia fija de 60 min — ver
// aula.repository.ts. Nada acá necesitó cambiar: este componente solo consume el booleano
// ya calculado, sin conocer la lógica de heartbeat detrás.

type Vista = "material" | "reunion";

function Countdown({ objetivoIso }: { objetivoIso: string }) {
  const [restante, setRestante] = useState({ d: 0, h: 0, m: 0, s: 0, listo: false });

  useEffect(() => {
    function tick() {
      // Interpretado como hora local del navegador — asunción razonable para una
      // plataforma exclusivamente chilena (mismo criterio que el countdown JS original,
      // que comparaba un epoch ya calculado en el servidor contra Date.now() del navegador).
      const objetivo = new Date(objetivoIso.replace(" ", "T")).getTime();
      let diff = Math.floor((objetivo - Date.now()) / 1000);
      if (diff <= 0) {
        setRestante({ d: 0, h: 0, m: 0, s: 0, listo: true });
        return;
      }
      const d = Math.floor(diff / 86400);
      diff -= d * 86400;
      const h = Math.floor(diff / 3600);
      diff -= h * 3600;
      const m = Math.floor(diff / 60);
      const s = diff - m * 60;
      setRestante({ d, h, m, s, listo: false });
    }
    tick();
    const timer = setInterval(tick, 1000);
    return () => clearInterval(timer);
  }, [objetivoIso]);

  if (restante.listo) {
    return (
      <button type="button" onClick={() => window.location.reload()} className="text-xs text-[#54A6D8] font-bold underline">
        Ya casi — actualizar
      </button>
    );
  }

  const casilla = (valor: number, etiqueta: string) => (
    <div>
      <div className="text-3xl md:text-4xl font-black text-[#54A6D8] tabular-nums">{String(valor).padStart(2, "0")}</div>
      <div className="text-[10px] text-gray-400 font-bold uppercase mt-1">{etiqueta}</div>
    </div>
  );

  return (
    <div className="grid grid-cols-4 gap-2">
      {casilla(restante.d, "Días")}
      {casilla(restante.h, "Horas")}
      {casilla(restante.m, "Min")}
      {casilla(restante.s, "Seg")}
    </div>
  );
}

export function AulaShell({
  detalleInicial,
  mensajesIniciales,
  archivosIniciales,
  usuarioId,
  phpSiteUrl,
}: {
  detalleInicial: AulaDetalle;
  mensajesIniciales: MensajeAula[];
  archivosIniciales: ArchivoContrato[];
  usuarioId: number;
  phpSiteUrl: string;
}) {
  const [detalle, setDetalle] = useState(detalleInicial);
  const [vista, setVista] = useState<Vista>("material");
  const [chatAbierto, setChatAbierto] = useState(false);
  const [badges, setBadges] = useState({ chatNoLeidos: 0, totalArchivos: archivosIniciales.length });
  const [procesando, setProcesando] = useState(false);

  const bloqueadoChat = ["cancelado", "finalizado", "disputa"].includes(detalle.estado);

  async function refrescarDetalle() {
    const res = await fetch(`/api/me/aula/${detalle.id}`, { cache: "no-store" });
    if (res.ok) setDetalle(await res.json());
  }

  useEffect(() => {
    async function poll() {
      if (document.hidden) return;
      const res = await fetch(`/api/me/aula/${detalle.id}/estado`, { cache: "no-store" });
      if (res.ok) {
        const data = (await res.json()) as { chatNoLeidos: number; totalArchivos: number };
        setBadges(data);
      }
    }
    const timer = setInterval(poll, 8000);
    // Repetir el chequeo de horario cada 30s para reflejar transiciones pre_clase -> activa
    // sin que el usuario tenga que recargar manualmente.
    const timerHorario = setInterval(refrescarDetalle, 30000);
    document.addEventListener("visibilitychange", () => {
      if (!document.hidden) {
        poll();
        refrescarDetalle();
      }
    });
    return () => {
      clearInterval(timer);
      clearInterval(timerHorario);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [detalle.id]);

  async function confirmarFinalizacion() {
    if (!window.confirm("¿Confirmas que el servicio fue entregado y deseas liberar el pago?")) return;
    setProcesando(true);
    try {
      await fetch(`/api/me/contratos/${detalle.id}/finalizar`, { method: "POST" });
      await refrescarDetalle();
    } finally {
      setProcesando(false);
    }
  }

  async function confirmarVendedor() {
    if (!window.confirm("El alumno ya liberó el pago. ¿Confirmas el cierre del contrato por tu parte?")) return;
    setProcesando(true);
    try {
      await fetch(`/api/me/contratos/${detalle.id}/confirmar-cierre`, { method: "POST" });
      await refrescarDetalle();
    } finally {
      setProcesando(false);
    }
  }

  const urlAulaPhp = `${phpSiteUrl}/app/mini_aula.php?id=${detalle.id}`;

  return (
    <div className="w-full h-dvh flex flex-col bg-gray-50 overflow-hidden">
      <header className="h-16 bg-white/95 backdrop-blur-md flex items-center justify-between px-4 border-b border-gray-100 shrink-0 z-30">
        <div className="flex items-center gap-2">
          <Link href="/mis-contratos" className="w-9 h-9 flex items-center justify-center rounded-full bg-gray-50 border border-gray-200 text-gray-600 hover:bg-gray-100">
            ←
          </Link>
          <div>
            <p className="font-bold text-gray-900 text-[15px] truncate">Aula #{detalle.id}</p>
            <p className="text-[11px] text-gray-500 truncate">{detalle.servicioTitulo}</p>
          </div>
        </div>
        <div className="w-full max-w-[200px] absolute left-1/2 -translate-x-1/2 bottom-0 h-[2px] bg-sky-100 hidden md:block">
          <div className="h-full bg-[#54A6D8] transition-all duration-1000" style={{ width: detalle.esFinalizado ? "100%" : detalle.finalizadoComprador || detalle.finalizadoVendedor ? "75%" : "40%" }} />
        </div>
      </header>

      <main className="flex-1 flex relative overflow-hidden">
        <div className="flex-1 flex flex-col min-w-0 relative">
          <div className="sticky top-0 z-20 w-full flex items-center justify-center gap-2 p-4">
            <div className="bg-white/90 backdrop-blur-md border border-gray-200 rounded-full p-1.5 shadow-lg flex items-center gap-1">
              <button
                type="button"
                onClick={() => setVista("material")}
                className={`px-5 py-2 rounded-full text-xs font-bold flex items-center gap-2 transition-all relative ${vista === "material" ? "bg-[#54A6D8] text-white shadow-md" : "text-gray-500 hover:bg-gray-100"}`}
              >
                Material
                {badges.totalArchivos > 0 && vista !== "material" && <span className="absolute -top-1 -right-1 w-3 h-3 bg-red-500 rounded-full border-2 border-white" />}
              </button>
              <button
                type="button"
                onClick={() => setVista("reunion")}
                className={`px-5 py-2 rounded-full text-xs font-bold flex items-center gap-2 transition-all ${vista === "reunion" ? "bg-[#54A6D8] text-white shadow-md" : "text-gray-500 hover:bg-gray-100"}`}
              >
                Reunión
              </button>
              <button
                type="button"
                onClick={() => setChatAbierto((v) => !v)}
                className="px-5 py-2 rounded-full text-xs font-bold text-gray-600 hover:bg-gray-100 relative"
              >
                Chat
                {badges.chatNoLeidos > 0 && !chatAbierto && <span className="absolute -top-1 -right-1 w-3 h-3 bg-red-500 rounded-full border-2 border-white" />}
              </button>
            </div>

            {(detalle.esComprador || detalle.esAdmin) && detalle.compradorPuedeFinalizar && (
              <button
                type="button"
                onClick={confirmarFinalizacion}
                disabled={procesando}
                className="bg-yellow-400 hover:bg-yellow-500 text-yellow-900 px-5 py-2.5 rounded-full text-xs font-bold shadow-md disabled:opacity-50"
              >
                Finalizar y Pagar
              </button>
            )}
            {(detalle.esComprador || detalle.esAdmin) && detalle.compradorEsperandoInicio && (
              <button disabled className="bg-gray-50 border border-gray-200 text-gray-400 px-5 py-2.5 rounded-full text-xs font-bold cursor-not-allowed">
                Esperando inicio
              </button>
            )}
            {detalle.esVendedor && !detalle.esAdmin && detalle.vendedorEsperandoAlumno && (
              <button disabled className="bg-gray-50 border border-gray-200 text-gray-400 px-5 py-2.5 rounded-full text-xs font-bold cursor-not-allowed">
                Esperando al alumno
              </button>
            )}
            {detalle.esVendedor && !detalle.esAdmin && detalle.vendedorPuedeConfirmar && (
              <button
                type="button"
                onClick={confirmarVendedor}
                disabled={procesando}
                className="bg-[#54A6D8] hover:bg-sky-500 text-white px-5 py-2.5 rounded-full text-xs font-bold shadow-md disabled:opacity-50"
              >
                Confirmar Cierre
              </button>
            )}
          </div>

          <div className="flex-1 p-4 md:p-6 overflow-hidden">
            {vista === "material" ? (
              <AulaMateriales contratoId={detalle.id} archivosIniciales={archivosIniciales} puedeSubir={!["cancelado", "finalizado"].includes(detalle.estado)} />
            ) : (
              <div className="w-full h-full bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden relative flex items-center justify-center">
                {detalle.esPreClase ? (
                  <div className="max-w-md w-full text-center p-6">
                    <h2 className="text-xl font-bold mb-2 text-gray-800">Tu clase aún no comienza</h2>
                    <p className="text-sm text-gray-500 mb-6">{detalle.fechaAmigable}</p>
                    <div className="bg-gray-50 border border-gray-100 rounded-3xl p-6 mb-4">
                      <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-3">Faltan</p>
                      <Countdown objetivoIso={detalle.ventanaAperturaTs} />
                    </div>
                    <p className="text-xs text-gray-400">Podrás entrar al aula 5 minutos antes del inicio.</p>
                  </div>
                ) : (
                  <div className="text-center p-6">
                    <h2 className="text-xl font-bold mb-2 text-gray-800">Sala de Reunión</h2>
                    {detalle.estado === "cancelado" ? (
                      <p className="text-gray-500 text-sm mb-6 max-w-xs mx-auto">Esta clase fue cancelada.</p>
                    ) : detalle.esPostClase && !detalle.videoHabilitado ? (
                      <p className="text-gray-500 text-sm mb-6 max-w-xs mx-auto">Esta clase ya finalizó.</p>
                    ) : detalle.esPostClase && detalle.videoHabilitado ? (
                      <p className="text-gray-500 text-sm mb-6 max-w-xs mx-auto">El horario programado terminó, pero la sala sigue disponible por si necesitas reconectarte.</p>
                    ) : detalle.tieneReserva ? (
                      <p className="text-gray-500 text-sm mb-6 max-w-xs mx-auto">
                        Clase agendada: <strong className="text-gray-700">{detalle.fechaAmigable}</strong>
                      </p>
                    ) : (
                      <p className="text-gray-500 text-sm mb-6 max-w-xs mx-auto">Videollamada segura e integrada.</p>
                    )}

                    {detalle.videoHabilitado ? (
                      <a
                        href={urlAulaPhp}
                        className="inline-block bg-[#54A6D8] text-white px-8 py-3 rounded-2xl font-bold shadow-lg shadow-sky-100 hover:scale-105 transition-transform"
                      >
                        Entrar a la Sala
                      </a>
                    ) : (
                      <>
                        <button disabled className="bg-gray-200 text-gray-400 px-8 py-3 rounded-2xl font-bold cursor-not-allowed">
                          Sala cerrada
                        </button>
                        <p className="text-xs text-gray-400 mt-3 max-w-xs mx-auto">El horario de la videollamada finalizó. Puedes seguir usando el chat y el material para coordinar.</p>
                      </>
                    )}
                  </div>
                )}
              </div>
            )}
          </div>
        </div>

        <aside className={`bg-white border-l border-gray-100 flex flex-col shadow-2xl transition-all duration-300 overflow-hidden ${chatAbierto ? "w-full md:w-[380px]" : "w-0"}`}>
          <div className="h-16 flex items-center justify-between px-3 border-b border-gray-100 shrink-0">
            <span className="font-bold text-gray-900 text-[15px] pl-2">Chat del aula</span>
            <button type="button" onClick={() => setChatAbierto(false)} className="text-gray-400 hover:text-[#54A6D8] w-10 h-10 flex items-center justify-center rounded-full hover:bg-gray-50">
              ✕
            </button>
          </div>
          {chatAbierto && (
            <div className="flex-1 min-h-0">
              <AulaChat contratoId={detalle.id} mensajesIniciales={mensajesIniciales} usuarioId={usuarioId} bloqueado={bloqueadoChat} />
            </div>
          )}
        </aside>
      </main>
    </div>
  );
}
