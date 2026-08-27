import { redirect } from "next/navigation";
import { getBandejaChats } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { BandejaChats } from "@/components/BandejaChats";

// Puerto de app/bandeja_entrada.php — Grupo Mensajes/Chat, Pieza 1 (26/08/2026). Es la
// bandeja REAL (enlazada desde sidebar.php/nav_bottom.php como "Mensajes") — NO
// app/mis_chats.php, que es una UI secundaria sin ruta propia. Ver
// server/src/modules/chat/chat.types.ts para el detalle completo de esa decisión.
export default async function BandejaEntradaPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();
  if (!sesion) {
    redirect(`${phpSiteUrl}/login?redir=${encodeURIComponent("/bandeja-entrada")}`);
  }

  const items = await getBandejaChats();

  return (
    <>
      <Header titulo="Mensajes" />
      <main className="pt-20 pb-28 md:pb-10 lg:ml-64 px-4 max-w-4xl mx-auto min-h-screen">
        <BandejaChats itemsIniciales={items} phpSiteUrl={phpSiteUrl} />
      </main>
    </>
  );
}
