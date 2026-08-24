// Réplica en React de nb_renderizar_aviso_bbcode() (app/helpers/avisos_bbcode.php) — misma
// mini-sintaxis ([b]...[/b], [icon:nombre]) y misma whitelist de íconos. A diferencia del
// PHP (que arma un string HTML con <strong>/<i> vía preg_replace), acá se parsea a nodos
// React reales: sin dangerouslySetInnerHTML, sin necesidad de re-implementar el escapado.
const ICONOS: Record<string, React.ReactNode> = {
  info: (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="inline w-[1em] h-[1em] align-[-0.15em]">
      <circle cx="12" cy="12" r="9" strokeLinecap="round" strokeLinejoin="round" />
      <path strokeLinecap="round" strokeLinejoin="round" d="M12 11.25v5.25M12 8.25h.008v.008H12V8.25Z" />
    </svg>
  ),
  alerta: (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="inline w-[1em] h-[1em] align-[-0.15em]">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"
      />
    </svg>
  ),
  regalo: (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="inline w-[1em] h-[1em] align-[-0.15em]">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M21 11.25v8.25a1.5 1.5 0 0 1-1.5 1.5H4.5a1.5 1.5 0 0 1-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 1 0 9.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1 1 14.625 7.5H12m0 0V21m-8.25-9.75h16.5a1.125 1.125 0 0 0 1.125-1.125v-1.5a1.125 1.125 0 0 0-1.125-1.125H3.75A1.125 1.125 0 0 0 2.625 8.625v1.5A1.125 1.125 0 0 0 3.75 11.25Z"
      />
    </svg>
  ),
  megafono: (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="inline w-[1em] h-[1em] align-[-0.15em]">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18a23.913 23.913 0 0 1-1.014 5.395m1.014-14.575a23.913 23.913 0 0 0-1.014-5.395M10.34 6.66a23.847 23.847 0 0 0 8.835-2.535m-8.835 11.715a23.847 23.847 0 0 1 8.835 2.535m0-14.25a3 3 0 0 1 0 14.25"
      />
    </svg>
  ),
  calendario: (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="inline w-[1em] h-[1em] align-[-0.15em]">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"
      />
    </svg>
  ),
  estrella: (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="inline w-[1em] h-[1em] align-[-0.15em]">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M11.48 3.5a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0l-4.725 2.885a.562.562 0 0 1-.84-.61l1.285-5.385a.563.563 0 0 0-.182-.557L2.94 10.386a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z"
      />
    </svg>
  ),
  check: (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="inline w-[1em] h-[1em] align-[-0.15em]">
      <path strokeLinecap="round" strokeLinejoin="round" d="m9 12.75 2.25 2.25L15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
    </svg>
  ),
  corazon: (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="inline w-[1em] h-[1em] align-[-0.15em]">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"
      />
    </svg>
  ),
  campana: (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="inline w-[1em] h-[1em] align-[-0.15em]">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"
      />
    </svg>
  ),
  cohete: (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="inline w-[1em] h-[1em] align-[-0.15em]">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M15.59 14.37a6 6 0 0 1-5.84 7.38v-4.8m5.84-2.58a14.98 14.98 0 0 0 6.16-12.12A14.98 14.98 0 0 0 9.631 8.41m5.96 5.96a14.926 14.926 0 0 1-5.841 2.58m-.119-8.54a6 6 0 0 0-7.381 5.84h4.8m2.581-5.84a14.927 14.927 0 0 0-2.58 5.84m-4.8-.001a4.5 4.5 0 0 0-1.757 4.306 4.5 4.5 0 0 0 4.306-1.758M16.5 9a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z"
      />
    </svg>
  ),
};

function parsearBold(texto: string, keyPrefix: string): React.ReactNode[] {
  const partes = texto.split(/(\[b\][\s\S]+?\[\/b\])/g);
  return partes.map((parte, i) => {
    const match = /^\[b\]([\s\S]+?)\[\/b\]$/.exec(parte);
    if (match) return <strong key={`${keyPrefix}-b-${i}`}>{match[1]}</strong>;
    return parte;
  });
}

export function AvisoMensaje({ mensaje, className }: { mensaje: string; className?: string }) {
  const segmentos = mensaje.split(/(\[icon:[a-zA-Z0-9_]+\])/g);

  return (
    <p className={className}>
      {segmentos.map((seg, i) => {
        const match = /^\[icon:([a-zA-Z0-9_]+)\]$/.exec(seg);
        if (match) {
          const icono = ICONOS[match[1].toLowerCase()];
          return icono ? <span key={`icon-${i}`}>{icono}</span> : null;
        }
        return <span key={`txt-${i}`}>{parsearBold(seg, `s${i}`)}</span>;
      })}
    </p>
  );
}
