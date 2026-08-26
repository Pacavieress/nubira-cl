"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";

// Puerto de la acción real de datos_bancarios.php:180-186 (<form method="POST"
// action="/solicitar-retiro">) — a diferencia del PHP real, que redirige a
// /app/datos_bancarios.php?error=X o ?retiro=ok y la propia página NUNCA lee esos
// parámetros (bug real documentado en CLAUDE.md: "datos_bancarios.php no muestra el
// resultado de solicitar_retiro.php al usuario"), acá el resultado se muestra en el momento,
// en la misma pantalla — no hay redirect ciego que perder. No es una mejora cosmética
// gratuita: es la corrección natural de ese bug real, como consecuencia directa de construir
// el flujo como fetch() en vez de como un POST+redirect de formulario clásico.
export function SolicitarRetiroButton() {
  const router = useRouter();
  const [enviando, setEnviando] = useState(false);
  const [mensaje, setMensaje] = useState<{ texto: string; exito: boolean } | null>(null);

  async function solicitar() {
    setEnviando(true);
    setMensaje(null);

    const res = await fetch("/api/mi-billetera/solicitar-retiro", { method: "POST" });
    const body = await res.json().catch(() => null);

    setEnviando(false);
    if (res.ok) {
      setMensaje({ texto: "Retiro solicitado. Lo verás reflejado en el historial abajo.", exito: true });
      router.refresh();
    } else {
      setMensaje({ texto: body?.mensaje ?? "No se pudo solicitar el retiro.", exito: false });
    }
  }

  return (
    <div className="w-full space-y-2">
      <button
        type="button"
        onClick={solicitar}
        disabled={enviando}
        className="w-full bg-[#54A6D8] text-white active:bg-blue-600 md:hover:bg-[#4392c3] py-3 rounded-xl font-bold transition-colors text-sm flex items-center justify-center gap-2 shadow-md disabled:opacity-60"
      >
        {enviando ? "Solicitando..." : "Solicitar Retiro"}
      </button>
      {mensaje && <p className={`text-[11px] font-medium text-center ${mensaje.exito ? "text-emerald-600" : "text-red-500"}`}>{mensaje.texto}</p>}
    </div>
  );
}
