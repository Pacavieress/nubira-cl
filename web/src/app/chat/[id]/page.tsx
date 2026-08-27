import { notFound, redirect } from "next/navigation";
import { getChatDetalle, getMensajesChatPrevio } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { ChatWindow } from "@/components/ChatWindow";

interface ChatPageProps {
  params: Promise<{ id: string }>;
}

// Puerto de app/chat_previo_contrato.php — Grupo Mensajes/Chat, Pieza 1 (26/08/2026).
export default async function ChatPage({ params }: ChatPageProps) {
  const { id } = await params;
  const chatId = Number(id);
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";

  if (!Number.isInteger(chatId) || chatId <= 0) {
    notFound();
  }

  const sesion = await getSesion();
  if (!sesion) {
    redirect(`${phpSiteUrl}/login?redir=${encodeURIComponent(`/chat/${chatId}`)}`);
  }

  const [detalle, mensajesData] = await Promise.all([getChatDetalle(chatId), getMensajesChatPrevio(chatId)]);
  if (!detalle || !mensajesData) {
    notFound();
  }

  return <ChatWindow detalleInicial={detalle} mensajesIniciales={mensajesData.mensajes} usuarioId={sesion.usuarioId} phpSiteUrl={phpSiteUrl} />;
}
