"use client";

import { useState, type ReactNode } from "react";

// Puerto exacto de #modal-comprar-invitado (ver_apunte.php:1008-1031) — cero campos
// obligatorios, email opcional de respaldo. El form real es un GET a /iniciar-pago, que
// vive solo en el sitio PHP (confirmado: no existe ruta /iniciar-pago en web/) — por eso
// `action` apunta a `${phpSiteUrl}/iniciar-pago` en vez de una ruta propia de Next.
//
// Sin campo `archivo` a diferencia del PHP: app/iniciar_pago.php (línea 74) resuelve el
// apunte completo desde `id_apunte` vía DB — nunca lee $_GET['archivo'] — así que ese
// campo era peso muerto en el form original. Coincide además con que la API pública de
// apuntes no expone el nombre real de archivo a un visitante sin acceso (ver
// apuntes.types.ts), así que no había forma de replicarlo aunque quisiéramos.
//
// Disparador + modal en un solo componente (mismo patrón que CompartirApunteBoton) — se
// usa en 2 lugares mutuamente excluyentes por breakpoint (card del rail derecho desktop y
// barra fija móvil), cada uno con su propia instancia/estado, igual que el share button.
export function ComprarInvitadoApunteBoton({
  apunteId,
  precioFmt,
  loginHref,
  phpSiteUrl,
  className,
  children,
}: {
  apunteId: number;
  precioFmt: string;
  loginHref: string;
  phpSiteUrl: string;
  className: string;
  children: ReactNode;
}) {
  const [abierto, setAbierto] = useState(false);

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
              <h2 className="text-lg font-bold text-gray-900">Comprar apunte</h2>
              <button
                type="button"
                onClick={() => setAbierto(false)}
                aria-label="Cerrar"
                className="text-gray-400 hover:text-gray-600 text-2xl leading-none"
              >
                ×
              </button>
            </div>
            <form method="GET" action={`${phpSiteUrl}/iniciar-pago`} className="space-y-4">
              <input type="hidden" name="id_apunte" value={apunteId} />
              <button
                type="submit"
                className="w-full bg-[#54A6D8] hover:bg-blue-600 text-white font-bold rounded-xl py-3.5 text-sm transition-all active:scale-95"
              >
                Comprar ahora por {precioFmt}
              </button>
              <div>
                <label className="block text-xs font-medium text-gray-500 mb-1">Recibir el link por correo, opcional</label>
                <input
                  type="email"
                  name="email"
                  placeholder="tucorreo@ejemplo.cl"
                  className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#54A6D8]"
                />
              </div>
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
