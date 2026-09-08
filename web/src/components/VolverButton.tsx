"use client";

// Puerto exacto de navegacionSeguraNubira() (guias.php:243-249, guia_post.php:545-554) —
// history.back() si hay desde dónde volver, si no un fallback contextual fijo (nunca un
// destino genérico como "/").
export function VolverButton({ fallbackHref }: { fallbackHref: string }) {
  function volver() {
    if (window.history.length > 1) {
      window.history.back();
    } else {
      window.location.href = fallbackHref;
    }
  }

  return (
    <button
      type="button"
      onClick={volver}
      aria-label="Volver"
      className="lg:hidden shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-gray-50 hover:bg-gray-100 border border-gray-200/60 shadow-sm active:scale-95 transition-all focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2"
    >
      <svg className="w-[17px] h-[17px] text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
      </svg>
    </button>
  );
}
