import { notFound, redirect } from "next/navigation";
import { getAulaDetalle, getMensajesAula, getArchivosContrato } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { AulaShell } from "@/components/AulaShell";

interface AulaPageProps {
  params: Promise<{ id: string }>;
}

// Puerto de app/mini_aula.php — Grupo Mini Aula, Pieza 2 (27/08/2026) + Fase 3/4 (ver
// server/src/modules/aula/aula.types.ts para el alcance completo por fase).
export default async function AulaPage({ params }: AulaPageProps) {
  const { id } = await params;
  const contratoId = Number(id);
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";

  if (!Number.isInteger(contratoId) || contratoId <= 0) {
    notFound();
  }

  const sesion = await getSesion();
  if (!sesion) {
    redirect(`${phpSiteUrl}/login?redir=${encodeURIComponent(`/aula/${contratoId}`)}`);
  }

  const detalle = await getAulaDetalle(contratoId);
  if (!detalle) {
    notFound();
  }

  const [mensajesData, archivos] = await Promise.all([getMensajesAula(contratoId), getArchivosContrato(contratoId)]);

  return (
    <AulaShell detalleInicial={detalle} mensajesIniciales={mensajesData?.mensajes ?? []} archivosIniciales={archivos} usuarioId={sesion.usuarioId} />
  );
}
