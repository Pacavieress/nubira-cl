"use client";

// Extraído de Header.tsx porque Header es un Server Component (necesita async/await
// para getSesion()) y un Server Component no puede llevar manejadores de evento como
// onSubmit en elementos nativos — solo un Client Component puede.
//
// onSubmit: puerto exacto de header.php:175 (deshabilita el input si va vacío antes del
// submit, para que buscar sin escribir nada no navegue a /busqueda?q=).
export function HeaderSearchForm({ q }: { q?: string }) {
  return (
    <form
      action="/busqueda"
      method="GET"
      role="search"
      onSubmit={(e) => {
        const input = e.currentTarget.elements.namedItem("q") as HTMLInputElement | null;
        if (input && input.value === "") input.disabled = true;
      }}
      className="w-full flex items-center bg-gray-50 border border-gray-100 rounded-full focus-within:border-[#54A6D8] focus-within:bg-white transition-colors duration-200 overflow-hidden relative z-10 outline-none"
    >
      <div className="pl-3 text-gray-400 shrink-0 pointer-events-none">
        <svg className="w-3.5 h-3.5 md:w-4 md:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"
          />
        </svg>
      </div>
      <input
        type="search"
        name="q"
        className="w-full py-1.5 md:py-2 pl-2 pr-4 bg-transparent border-none focus:ring-0 text-gray-900 placeholder-gray-400 text-base md:text-sm cursor-pointer focus:cursor-text outline-none"
        placeholder="¿Qué buscas?"
        autoComplete="off"
        enterKeyHint="search"
        defaultValue={q ?? ""}
      />
      <button type="submit" className="sr-only" />
    </form>
  );
}
