"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";

// Puerto de iniciar_chat.php para usuarios YA autenticados — Grupo Mensajes/Chat, Pieza 1
// (26/08/2026). El botón equivalente para visitantes SIN sesión sigue bridgeando al PHP
// real (ver servicios/[id]/page.tsx): ese camino además maneja el redirect a /login, que
// este flujo interno no necesita replicar (requireAuth ya lo cubre con un 401 limpio).
export function IniciarChatBoton({ servicioId, className, children }: { servicioId: number; className: string; children: React.ReactNode }) {
  const [enviando, setEnviando] = useState(false);
  const router = useRouter();

  async function iniciar() {
    if (enviando) return;
    setEnviando(true);
    try {
      const res = await fetch("/api/me/chat/iniciar", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ servicioId }),
      });
      const data = (await res.json().catch(() => null)) as { ok?: boolean; chatId?: number } | null;
      if (res.ok && data?.ok && data.chatId) {
        router.push(`/chat/${data.chatId}`);
        return;
      }
      setEnviando(false);
    } catch {
      setEnviando(false);
    }
  }

  return (
    <button type="button" onClick={iniciar} disabled={enviando} className={className}>
      {children}
    </button>
  );
}
