// Puerto de app/mini_aula.php (view-pizarra) — Grupo Mini Aula, Fase 4. Solo visible para
// el vendedor real (ver aula.repository.ts: pizarraUrl es null para cualquier otro rol,
// incluido el admin en bypass — restricción de producto del PHP real, no algo a corregir
// acá). Sin API key ni creación de sala: la URL de Excalidraw (room+key en el fragmento #)
// es autocontenida — el room se crea implícitamente cuando alguien la abre.
export function AulaPizarra({ pizarraUrl }: { pizarraUrl: string }) {
  return (
    <div className="w-full h-full bg-white rounded-3xl border-2 border-gray-200 shadow-md overflow-hidden relative">
      <iframe src={pizarraUrl} className="w-full h-full border-0" allow="clipboard-read; clipboard-write" title="Pizarra colaborativa" />
    </div>
  );
}
