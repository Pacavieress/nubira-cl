"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { HorarioGrid, horarioVacio, validarHorarioClient, type HorarioValor } from "./HorarioGrid";
import { VideoServicioSection, type VideoSeleccionado } from "./VideoServicioSection";

// Puerto de app/publicar_servicio.php — creación real (título, descripción, categoría,
// PAES, precio, horario obligatorio, video de presentación opcional). Deliberadamente SIN:
// generación por IA (no aplica a servicios en el PHP real de todos modos) y sin el flujo
// de pago de republicación ($3.000 desde la 2da publicación) — ver publicar.controller.ts
// en server/ para el detalle completo de alcance ya confirmado.

const CATEGORIAS = ["Matemáticas", "Química", "Física", "Biología", "Programación", "Idiomas", "Historia", "Lenguaje", "Economía", "Diseño", "Derecho", "Asesoría", "Otros"];

const MENSAJES_ERROR: Record<string, string> = {
  campos_obligatorios_faltantes: "Faltan campos obligatorios.",
  titulo_o_descripcion_excede_limite: "El título o descripción exceden el límite permitido.",
  precio_bajo_minimo: "El precio mínimo es $10.000.",
  contiene_contacto: "Por seguridad, no incluyas teléfonos ni correos.",
  sin_imagenes_para_categoria: "No hay imágenes disponibles para esta categoría. Contacta a soporte.",
  cupo_gratis_agotado: "Ya usaste tu publicación gratuita. Publicar una nueva clase pronto tendrá costo — mientras tanto, contáctanos por soporte si necesitas publicar otra.",
  usuario_no_encontrado: "No pudimos identificar tu cuenta. Recarga la página e intenta de nuevo.",
  no_autenticado: "Tu sesión expiró. Recarga la página e intenta de nuevo.",
};

function formatoCLPInput(valor: number): string {
  return valor === 0 ? "" : valor.toLocaleString("es-CL");
}

export function PublicarServicioForm() {
  const router = useRouter();
  const [categoria, setCategoria] = useState("");
  const [esPaes, setEsPaes] = useState(false);
  const [titulo, setTitulo] = useState("");
  const [descripcion, setDescripcion] = useState("");
  const [precio, setPrecio] = useState(0);
  const [horario, setHorario] = useState<HorarioValor>(horarioVacio());
  const [video, setVideo] = useState<VideoSeleccionado | null>(null);
  const [consentimientoVideo, setConsentimientoVideo] = useState(false);

  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pasoActual, setPasoActual] = useState<string | null>(null);
  const [progresoVideo, setProgresoVideo] = useState(0);

  function onPrecioChange(texto: string) {
    const digitos = texto.replace(/\D/g, "").slice(0, 9);
    setPrecio(digitos === "" ? 0 : parseInt(digitos, 10));
  }

  function subirVideoConProgreso(servicioId: number): Promise<void> {
    return new Promise((resolve) => {
      if (!video) {
        resolve();
        return;
      }
      const fd = new FormData();
      fd.append("video", video.archivo, video.archivo.name);
      fd.append("consentimientoRrss", "1");
      if (video.thumbBlob) fd.append("thumb", video.thumbBlob, "thumb.jpg");

      const xhr = new XMLHttpRequest();
      xhr.upload.addEventListener("progress", (ev) => {
        if (!ev.lengthComputable) return;
        setProgresoVideo(Math.round((ev.loaded / ev.total) * 100));
      });
      // El video es opcional y el servicio+horario YA quedaron guardados en este punto —
      // una falla acá no debe bloquear la publicación ya exitosa (mismo criterio que
      // continuarTrasHorario() en publicar_servicio.php: el video se puede reintentar
      // después). No hay página "editar-servicio" en web/ todavía para ese reintento —
      // simplificación real de esta pieza, no un olvido.
      xhr.addEventListener("load", () => resolve());
      xhr.addEventListener("error", () => resolve());
      xhr.addEventListener("timeout", () => resolve());
      xhr.open("POST", `/api/publicar/servicios/${servicioId}/video`);
      xhr.timeout = 120000;
      xhr.send(fd);
    });
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (enviando) return;
    setError(null);

    if (video && !consentimientoVideo) {
      setError("Debes aceptar el consentimiento de redes sociales para subir tu video, o quitarlo para publicar sin video.");
      return;
    }
    if (precio < 10000) {
      setError("El precio mínimo es $10.000.");
      return;
    }
    const errorHorario = validarHorarioClient(horario);
    if (errorHorario) {
      setError(errorHorario);
      return;
    }

    setEnviando(true);
    setPasoActual("Publicando...");

    try {
      const resServicio = await fetch("/api/publicar/servicios", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ titulo, descripcion, categoria, modalidad: "Online", ubicacion: "", precio, esPaes }),
      });
      const dataServicio = await resServicio.json();
      if (!dataServicio.ok) {
        setError(MENSAJES_ERROR[dataServicio.error] ?? "Error al publicar el servicio. Intenta de nuevo.");
        setEnviando(false);
        setPasoActual(null);
        return;
      }

      const servicioId = dataServicio.servicioId as number;
      setPasoActual("Guardando horario...");

      const resHorario = await fetch(`/api/publicar/servicios/${servicioId}/horario`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ horariosJson: JSON.stringify(horario) }),
      });
      const dataHorario = await resHorario.json();

      if (!dataHorario.ok) {
        await fetch(`/api/publicar/servicios/${servicioId}/incompleto`, { method: "DELETE" });
        setError(`No se pudo guardar el horario (${dataHorario.error ?? "error desconocido"}). Tu servicio NO quedó publicado — completa el formulario de nuevo.`);
        setEnviando(false);
        setPasoActual(null);
        return;
      }

      if (video) {
        setPasoActual("Subiendo video...");
        await subirVideoConProgreso(servicioId);
      }

      setPasoActual("¡Listo!");
      setTimeout(() => router.push("/mis-publicaciones"), 700);
    } catch {
      setError("Ocurrió un error de red. Intenta de nuevo.");
      setEnviando(false);
      setPasoActual(null);
    }
  }

  return (
    <form onSubmit={onSubmit} className="space-y-6">
      {error && (
        <div className="px-4 py-3 rounded-xl flex items-center gap-3 shadow-sm bg-red-50 text-red-700 border border-red-200">
          <span className="text-sm font-bold flex-1">{error}</span>
        </div>
      )}

      <div className="bg-white border border-gray-100 rounded-2xl p-4 md:p-8 shadow-sm">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
          <div>
            <div className="flex items-center justify-between mb-1.5">
              <label className="block text-xs font-bold text-gray-900 uppercase tracking-wide">Categoría</label>
              <span className="text-[10px] font-semibold text-gray-400 bg-gray-50 border border-gray-200 rounded-full px-2 py-0.5">Modalidad: Online</span>
            </div>
            <select
              required
              value={categoria}
              onChange={(e) => setCategoria(e.target.value)}
              className="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent block p-3.5 outline-none appearance-none cursor-pointer"
            >
              <option value="">Selecciona una opción...</option>
              {CATEGORIAS.map((cat) => (
                <option key={cat} value={cat}>
                  {cat}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">PAES</label>
            <label
              title="Márcalo si este servicio ayuda a rendir la prueba de admisión"
              className="flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-xl p-3.5 cursor-pointer select-none h-[50px]"
            >
              <input type="checkbox" checked={esPaes} onChange={(e) => setEsPaes(e.target.checked)} className="w-4 h-4 rounded border-gray-300 text-[#54A6D8] focus:ring-[#54A6D8] shrink-0" />
              <span className="text-sm font-bold text-gray-900">Prepara para la PAES</span>
            </label>
          </div>
        </div>

        <div className="mb-6">
          <label className="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">Título del anuncio</label>
          <input
            type="text"
            required
            maxLength={50}
            value={titulo}
            onChange={(e) => setTitulo(e.target.value)}
            placeholder="Ej: Clases de Cálculo / Asesoría de Tesis"
            className="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent block p-3.5 outline-none"
          />
          <div className="text-right text-xs mt-1 text-gray-400">{titulo.length}/50</div>
        </div>

        <div className="mb-6">
          <label className="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">Descripción</label>
          <textarea
            required
            rows={8}
            maxLength={1500}
            value={descripcion}
            onChange={(e) => setDescripcion(e.target.value)}
            placeholder="Describe detalladamente el servicio que ofreces..."
            className="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent block p-4 resize-none outline-none leading-relaxed"
          />
          <div className="text-xs text-gray-400 text-right mt-1">{descripcion.length}/1500</div>
        </div>

        <div>
          <label className="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">Precio Base (CLP)</label>
          <div className="relative">
            <span className="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 font-bold pointer-events-none">$</span>
            <input
              type="text"
              inputMode="numeric"
              value={formatoCLPInput(precio)}
              onChange={(e) => onPrecioChange(e.target.value)}
              placeholder="Mínimo $10.000"
              className="w-full bg-gray-50 border border-gray-200 text-gray-900 text-lg font-bold rounded-xl pl-8 p-3.5 focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent outline-none"
            />
          </div>
          <p className="text-xs text-gray-400 mt-1 ml-1">El precio mínimo es $10.000.</p>
        </div>
      </div>

      <div className="bg-white border border-gray-100 rounded-2xl shadow-sm p-4 md:p-8">
        <div className="flex items-center gap-2 mb-1">
          <h2 className="text-base font-bold text-gray-900">Horario de disponibilidad</h2>
          <span className="text-[10px] font-bold bg-red-50 text-red-600 px-2 py-0.5 rounded-full border border-red-100 uppercase tracking-widest">Requerido</span>
        </div>
        <p className="text-xs text-gray-400 leading-relaxed max-w-lg mb-5">Define al menos un bloque en el que estés disponible. Es obligatorio para poder aprobar tu servicio.</p>
        <HorarioGrid value={horario} onChange={setHorario} />
      </div>

      <div className="bg-white border border-gray-100 rounded-2xl shadow-sm p-4 md:p-8">
        <div className="flex items-center gap-2 mb-1">
          <h2 className="text-base font-bold text-gray-900">Video de presentación</h2>
          <span className="text-[10px] font-bold bg-blue-50 text-[#54A6D8] px-2 py-0.5 rounded-full border border-blue-100 uppercase tracking-widest">Opcional</span>
        </div>
        <p className="text-xs text-gray-400 leading-relaxed max-w-lg mb-5">Los alumnos eligen primero a los tutores que pueden ver antes de escribirles.</p>
        <VideoServicioSection video={video} onVideoChange={setVideo} consentimiento={consentimientoVideo} onConsentimientoChange={setConsentimientoVideo} />
        {enviando && video && pasoActual === "Subiendo video..." && (
          <div className="mt-4">
            <div className="h-1.5 rounded-full bg-gray-200 overflow-hidden">
              <div className="h-full rounded-full bg-gradient-to-r from-sky-400 to-[#54A6D8] transition-all" style={{ width: `${progresoVideo}%` }} />
            </div>
          </div>
        )}
      </div>

      <button
        type="submit"
        disabled={enviando}
        className="w-full text-white bg-[#54A6D8] hover:bg-sky-600 font-bold rounded-2xl text-base px-5 py-4 text-center shadow-lg shadow-blue-200 hover:shadow-blue-300 transition-all disabled:opacity-60"
      >
        {pasoActual ?? "Publicar Servicio"}
      </button>
    </form>
  );
}
