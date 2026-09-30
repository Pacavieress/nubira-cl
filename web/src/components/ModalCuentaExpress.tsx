"use client";

import { useId, useState, type FormEvent, type ReactNode } from "react";

// Puerto de #modal-chat-visitante + form-cuenta-express (detalle_servicio.php:1756-1853).
// Solo para visitantes sin sesión: crea una cuenta mínima (nombre + email + términos) vía el
// proxy /api/cuenta-express (-> app/crear_cuenta_express.php) y deja al visitante con sesión
// PHP iniciada (el proxy traslada la cookie PHPSESSID).
//
// Destino tras crear la cuenta: el que devuelve PHP es una ruta relativa al sitio PHP
// (/app/iniciar_chat.php?servicio_id=N, o /vitrina), no a esta app — por eso se antepone
// phpSiteUrl. El chat queda en el sitio PHP a propósito: la sesión recién creada no está
// espejada en sesiones_api, así que el backend de Node todavía no la reconoce.
//
// Disparador + modal en un solo componente (mismo patrón que ComprarInvitadoApunteBoton): se
// puede montar en varios lugares de la página, cada instancia con su propio estado. En PHP
// el modal intercepta TODOS los enlaces a iniciar_chat.php; acá cada botón es una instancia.
export function ModalCuentaExpress({
  servicioId,
  nombreTutor,
  loginHref,
  phpSiteUrl,
  className,
  children,
}: {
  servicioId: number;
  nombreTutor: string;
  // URL completa de login con ?redir= de vuelta a esta página.
  loginHref: string;
  phpSiteUrl: string;
  className: string;
  children: ReactNode;
}) {
  const id = useId();
  const [abierto, setAbierto] = useState(false);
  const [nombre, setNombre] = useState("");
  const [email, setEmail] = useState("");
  const [aceptaTerminos, setAceptaTerminos] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);

  async function enviar(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError(null);
    setEnviando(true);

    try {
      const res = await fetch("/api/cuenta-express", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          nombre: nombre.trim(),
          email: email.trim().toLowerCase(),
          acepta_terminos: aceptaTerminos,
          servicio_id: servicioId,
        }),
        credentials: "same-origin",
      });
      const data = (await res.json()) as { ok?: boolean; redirect?: string; error?: string };

      // Solo rutas relativas del sitio PHP ("/..." pero no "//host"): nunca se navega a un
      // destino arbitrario aunque la respuesta venga alterada.
      if (data.ok && typeof data.redirect === "string" && data.redirect.startsWith("/") && !data.redirect.startsWith("//")) {
        window.location.href = `${phpSiteUrl}${data.redirect}`;
        return;
      }
      setError(data.error || "Error desconocido.");
    } catch {
      setError("Error de conexión. Intenta de nuevo.");
    }
    setEnviando(false);
  }

  return (
    <>
      <button type="button" onClick={() => setAbierto(true)} className={className}>
        {children}
      </button>

      {abierto && (
        <div
          className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4"
          role="dialog"
          aria-modal="true"
          onClick={(e) => {
            if (e.target === e.currentTarget) setAbierto(false);
          }}
        >
          <div className="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-bold text-gray-900">Para chatear con {nombreTutor}</h2>
              <button
                type="button"
                onClick={() => setAbierto(false)}
                aria-label="Cerrar"
                className="text-gray-400 hover:text-gray-600 text-2xl leading-none"
              >
                ×
              </button>
            </div>
            <p className="text-sm text-gray-600 mb-4">Te avisaremos por correo cuando responda.</p>
            <form onSubmit={enviar} className="space-y-3">
              <div>
                <label htmlFor={`${id}-nombre`} className="block text-xs font-semibold text-gray-700 mb-1">
                  Tu nombre
                </label>
                <input
                  id={`${id}-nombre`}
                  type="text"
                  name="nombre"
                  required
                  minLength={2}
                  maxLength={100}
                  value={nombre}
                  onChange={(e) => setNombre(e.target.value)}
                  className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#54A6D8]"
                  placeholder="Ej: Camila Soto"
                />
              </div>
              <div>
                <label htmlFor={`${id}-email`} className="block text-xs font-semibold text-gray-700 mb-1">
                  Tu email
                </label>
                <input
                  id={`${id}-email`}
                  type="email"
                  name="email"
                  required
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#54A6D8]"
                  placeholder="camila@ejemplo.cl"
                />
              </div>
              <label className="flex items-start gap-2 text-xs text-gray-700 cursor-pointer">
                <input
                  type="checkbox"
                  name="acepta_terminos"
                  required
                  checked={aceptaTerminos}
                  onChange={(e) => setAceptaTerminos(e.target.checked)}
                  className="mt-0.5 accent-[#54A6D8]"
                />
                <span>
                  Acepto los{" "}
                  <a href="/terminos" target="_blank" rel="noopener" className="text-[#54A6D8] underline">
                    términos
                  </a>
                </span>
              </label>
              {error && (
                <div role="alert" className="text-red-600 text-xs py-1">
                  {error}
                </div>
              )}
              <button
                type="submit"
                disabled={enviando}
                className="w-full bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl py-3 text-sm transition-all active:scale-95 disabled:opacity-60 disabled:cursor-not-allowed"
              >
                {enviando ? "Creando cuenta..." : "Empezar conversación"}
              </button>
              <p className="text-center text-xs text-gray-500">
                ¿Ya tienes cuenta?{" "}
                <a href={loginHref} className="text-[#54A6D8] underline">
                  Inicia sesión
                </a>
              </p>
            </form>
          </div>
        </div>
      )}
    </>
  );
}
