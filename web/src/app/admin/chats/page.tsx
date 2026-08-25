import { redirect } from "next/navigation";
import { getAdminChats, getAdminChatsContadores } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminChatsPanel } from "@/components/AdminChatsPanel";

// Puerto de admin_chats.php ("Master Tracker" — monitoreo de chats + sistema DLP). Alcance
// confirmado explícitamente con el usuario antes de construir: se portan 3 mutaciones seguras
// (eliminar/restaurar chat, marcar DLP revisado, aprobar archivo de moderación), todas
// DB-only. "liberar_mensaje_dlp" y "rechazar_archivo" quedan excluidas — ver
// AdminChatsPanel.tsx y server/.../adminChats.types.ts para el detalle completo. Se omiten
// deliberadamente el polling "en vivo", los atajos de teclado y el panel lateral
// redimensionable con drag del PHP real — decorativos, sin pérdida de información ni de
// ninguna mutación real.
export default async function AdminChatsPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const [{ chats }, contadores] = await Promise.all([getAdminChats("activos", "desc", ""), getAdminChatsContadores()]);

  return (
    <>
      <Header titulo="Monitor Chats" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1600px] mx-auto space-y-6">
        <div>
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Master Tracker</h1>
          <p className="text-sm text-gray-500 mt-1">Monitoreo de conversaciones y alertas del sistema DLP.</p>
        </div>

        <AdminChatsPanel chatsIniciales={chats} contadoresIniciales={contadores} phpSiteUrl={phpSiteUrl} />
      </main>
    </>
  );
}
