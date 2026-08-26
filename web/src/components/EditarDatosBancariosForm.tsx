"use client";

import { useState } from "react";
import type { DatosBancariosCompletos } from "@/lib/api";

// Puerto de la card de app/editar_datos_bancarios.php:151-228 — mismos 5 campos, mismas
// validaciones client-side (número de cuenta solo dígitos, RUT autoformateado con guión al
// perder foco) que el server (server/src/modules/miBilletera/miBilletera.controller.ts::
// putMiDatosBancarios) vuelve a validar de todos modos — nunca se confía solo en el cliente
// para un formulario que mueve datos de dinero real.
const TIPOS_CUENTA = ["Cuenta Rut", "Cuenta Corriente", "Cuenta Vista", "Cuenta de Ahorro"];

export function EditarDatosBancariosForm({ bancos, datosIniciales }: { bancos: string[]; datosIniciales: DatosBancariosCompletos | null }) {
  const [banco, setBanco] = useState(datosIniciales?.banco ?? "");
  const [tipoCuenta, setTipoCuenta] = useState(datosIniciales?.tipoCuenta ?? TIPOS_CUENTA[0]);
  const [numeroCuenta, setNumeroCuenta] = useState(datosIniciales?.numeroCuenta ?? "");
  const [titularNombre, setTitularNombre] = useState(datosIniciales?.titularNombre ?? "");
  const [rut, setRut] = useState(datosIniciales?.rut ?? "");

  const [guardando, setGuardando] = useState(false);
  const [mensaje, setMensaje] = useState<{ texto: string; exito: boolean } | null>(null);

  // Puerto de editar_datos_bancarios.php:258-264 (formatea el RUT al perder foco: limpia
  // todo salvo dígitos/k, inserta el guión antes del dígito verificador).
  function formatearRutAlSalir() {
    let limpio = rut.replace(/[^0-9kK]/g, "");
    if (limpio.length > 1) limpio = limpio.slice(0, -1) + "-" + limpio.slice(-1);
    setRut(limpio.toUpperCase());
  }

  async function guardar(e: React.FormEvent) {
    e.preventDefault();
    setGuardando(true);
    setMensaje(null);

    const res = await fetch("/api/mi-billetera/datos-bancarios", {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ banco, tipoCuenta, numeroCuenta, titularNombre, rut }),
    });

    setGuardando(false);
    if (res.status === 204) {
      setMensaje({ texto: "Datos bancarios guardados correctamente.", exito: true });
    } else {
      const body = await res.json().catch(() => null);
      setMensaje({ texto: body?.mensaje ?? "Error al guardar.", exito: false });
    }
  }

  return (
    <div className="bg-white border border-[#f0f0f0] rounded-3xl p-6 md:p-8">
      {mensaje && (
        <div
          className={`rounded-2xl p-5 mb-6 text-sm font-medium ${
            mensaje.exito ? "bg-emerald-50 border border-emerald-100 text-emerald-700" : "bg-red-50 border border-red-100 text-red-600"
          }`}
        >
          {mensaje.texto}
        </div>
      )}

      <form onSubmit={guardar} className="space-y-6">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-5 md:gap-6">
          <div className="space-y-2">
            <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest pl-1">Institución Bancaria</label>
            <select
              value={banco}
              onChange={(e) => setBanco(e.target.value)}
              required
              className="w-full border border-gray-200 bg-gray-50 px-4 py-3.5 rounded-2xl focus:ring-0 focus:border-[#54A6D8] focus:bg-white text-gray-800 font-medium transition-colors cursor-pointer outline-none"
            >
              <option value="" disabled>
                Selecciona tu banco...
              </option>
              {bancos.map((b) => (
                <option key={b} value={b}>
                  {b}
                </option>
              ))}
            </select>
          </div>

          <div className="space-y-2">
            <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest pl-1">Tipo de Cuenta</label>
            <select
              value={tipoCuenta}
              onChange={(e) => setTipoCuenta(e.target.value)}
              required
              className="w-full border border-gray-200 bg-gray-50 px-4 py-3.5 rounded-2xl focus:ring-0 focus:border-[#54A6D8] focus:bg-white text-gray-800 font-medium transition-colors cursor-pointer outline-none"
            >
              {TIPOS_CUENTA.map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </select>
          </div>

          <div className="space-y-2">
            <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest pl-1">Número de Cuenta</label>
            <input
              type="text"
              value={numeroCuenta}
              onChange={(e) => setNumeroCuenta(e.target.value.replace(/[^0-9]/g, ""))}
              required
              placeholder="Ej: 123456789"
              className="w-full border border-gray-200 bg-gray-50 px-4 py-3.5 rounded-2xl focus:ring-0 focus:border-[#54A6D8] focus:bg-white text-gray-800 font-medium transition-colors placeholder-gray-300 outline-none"
            />
            <p className="text-[10px] font-medium text-gray-400 pl-1 mt-1">Solo números, sin guiones.</p>
          </div>

          <div className="space-y-2">
            <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest pl-1">Nombre del Titular</label>
            <input
              type="text"
              value={titularNombre}
              onChange={(e) => setTitularNombre(e.target.value)}
              required
              placeholder="Juan Pérez González"
              className="w-full border border-gray-200 bg-gray-50 px-4 py-3.5 rounded-2xl focus:ring-0 focus:border-[#54A6D8] focus:bg-white text-gray-800 font-medium transition-colors placeholder-gray-300 outline-none"
            />
          </div>

          <div className="space-y-2 md:col-span-2">
            <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest pl-1">RUT del Titular</label>
            <input
              type="text"
              value={rut}
              onChange={(e) => setRut(e.target.value.replace(/[^0-9kK-]/g, ""))}
              onBlur={formatearRutAlSalir}
              required
              placeholder="Ej: 12345678-9"
              className="w-full md:w-1/2 border border-gray-200 bg-gray-50 px-4 py-3.5 rounded-2xl focus:ring-0 focus:border-[#54A6D8] focus:bg-white text-gray-800 font-medium transition-colors placeholder-gray-300 uppercase outline-none"
            />
            <p className="text-[10px] font-medium text-gray-400 pl-1 mt-1">Debe coincidir exactamente con el titular del banco.</p>
          </div>
        </div>

        <div className="pt-6 mt-8 border-t border-[#f0f0f0] flex items-center justify-between">
          <span className="text-[10px] font-bold text-gray-400 uppercase tracking-widest hidden sm:flex items-center gap-1.5">
            Conexión Segura
          </span>
          <button
            type="submit"
            disabled={guardando}
            className="w-full sm:w-auto bg-[#54A6D8] text-white hover:bg-[#4392c3] font-bold py-3.5 px-8 rounded-2xl active:scale-[0.98] transition-all text-sm disabled:opacity-60"
          >
            {guardando ? "Guardando..." : "Guardar Cambios"}
          </button>
        </div>
      </form>
    </div>
  );
}
