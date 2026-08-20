"use client";

import { useState } from "react";
import type { PerfilCuenta } from "@/lib/api";

// Puerto de la card "Información Básica" de app/editar_datos.php:238-317 — mismos campos,
// mismos required/validaciones (nombre y carrera obligatorios, tipo del whitelist de 4
// valores). "Cambiar contraseña" y "Eliminar cuenta" NO se portan acá (ver
// server/src/modules/configurarCuenta/configurarCuenta.types.ts) — sección aparte que
// enlaza a la página PHP real, misma pestaña.
const TIPOS = [
  { value: "", label: "Sin especificar" },
  { value: "estudiante", label: "Estudiante" },
  { value: "egresado", label: "Egresado" },
  { value: "profesor", label: "Profesor" },
  { value: "particular", label: "Tutor Particular" },
];

export function ConfigurarCuentaForm({ perfilInicial }: { perfilInicial: PerfilCuenta }) {
  const [nombre, setNombre] = useState(perfilInicial.nombre);
  const [carrera, setCarrera] = useState(perfilInicial.carrera ?? "");
  const [tipo, setTipo] = useState(perfilInicial.tipo ?? "");
  const [universidad, setUniversidad] = useState(perfilInicial.universidad ?? "");
  const [anioEgreso, setAnioEgreso] = useState(perfilInicial.anioEgreso?.toString() ?? "");
  const [aniosExperiencia, setAniosExperiencia] = useState(perfilInicial.aniosExperiencia?.toString() ?? "");
  const [bio, setBio] = useState(perfilInicial.bio ?? "");

  const [guardando, setGuardando] = useState(false);
  const [mensaje, setMensaje] = useState<{ texto: string; exito: boolean } | null>(null);

  async function guardar(e: React.FormEvent) {
    e.preventDefault();
    setGuardando(true);
    setMensaje(null);

    if (nombre.trim() === "" || carrera.trim() === "") {
      setMensaje({ texto: "El nombre y la carrera o área son obligatorios.", exito: false });
      setGuardando(false);
      return;
    }

    const res = await fetch("/api/configurar-cuenta", {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        nombre,
        carrera,
        tipo,
        bio,
        universidad,
        anioEgreso: anioEgreso === "" ? null : Number(anioEgreso),
        aniosExperiencia: aniosExperiencia === "" ? null : Number(aniosExperiencia),
      }),
    });

    setGuardando(false);
    if (res.ok) {
      setMensaje({ texto: "Datos actualizados correctamente.", exito: true });
    } else {
      const body = await res.json().catch(() => null);
      setMensaje({ texto: body?.mensaje ?? "Error al actualizar.", exito: false });
    }
  }

  return (
    <div className="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 md:p-8">
      <h2 className="text-lg font-bold text-gray-900 mb-6">Información Básica</h2>

      {mensaje && (
        <div
          className={`rounded-xl px-4 py-3 mb-6 flex items-center gap-3 text-sm font-medium ${
            mensaje.exito ? "bg-green-50 text-green-700 border border-green-200" : "bg-red-50 text-red-700 border border-red-200"
          }`}
        >
          {mensaje.texto}
        </div>
      )}

      <form onSubmit={guardar} className="space-y-6">
        <div>
          <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Correo Institucional</label>
          <input
            type="email"
            value={perfilInicial.correo}
            readOnly
            className="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-500 cursor-not-allowed text-sm"
          />
          <p className="text-[10px] text-gray-400 mt-1">No editable por seguridad.</p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Nombre Completo</label>
            <input
              type="text"
              value={nombre}
              onChange={(e) => setNombre(e.target.value)}
              required
              className="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none"
            />
          </div>
          <div>
            <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Carrera / Área</label>
            <input
              type="text"
              value={carrera}
              onChange={(e) => setCarrera(e.target.value)}
              required
              className="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none"
            />
          </div>
        </div>

        <div>
          <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Tipo de cuenta</label>
          <select
            value={tipo}
            onChange={(e) => setTipo(e.target.value)}
            className="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none"
          >
            {TIPOS.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </select>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Universidad / Institución</label>
            <input
              type="text"
              value={universidad}
              onChange={(e) => setUniversidad(e.target.value)}
              maxLength={100}
              placeholder="Ej: USACH, UC, AIEP"
              className="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none"
            />
          </div>
          <div>
            <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Año de egreso</label>
            <input
              type="number"
              value={anioEgreso}
              onChange={(e) => setAnioEgreso(e.target.value)}
              min={1970}
              max={2030}
              placeholder="2020"
              className="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none"
            />
          </div>
        </div>

        <div>
          <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Años de experiencia enseñando</label>
          <input
            type="number"
            value={aniosExperiencia}
            onChange={(e) => setAniosExperiencia(e.target.value)}
            min={0}
            max={50}
            placeholder="Ej: 3"
            className="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none"
          />
        </div>

        <div>
          <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Bio profesional</label>
          <textarea
            value={bio}
            onChange={(e) => setBio(e.target.value)}
            rows={4}
            maxLength={500}
            placeholder="Cuéntanos tu experiencia y qué enseñas..."
            className="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none resize-none"
          />
        </div>

        <div className="flex justify-end pt-2">
          <button
            type="submit"
            disabled={guardando}
            className="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold py-2.5 px-6 rounded-xl shadow-sm transition text-sm disabled:opacity-60"
          >
            {guardando ? "Guardando..." : "Guardar Cambios"}
          </button>
        </div>
      </form>
    </div>
  );
}
