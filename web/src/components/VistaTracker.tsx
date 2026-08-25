"use client";

import { useEffect } from "react";

// Puerto exacto de detalle_servicio.php:1616-1685 (tracker de engagement) — incluido a
// pedido explícito del usuario (2026-08-25): fire-and-forget, solo analítica, sin mutar
// datos del usuario. Mismo localStorage key ("nubira_sid"), mismos umbrales de scroll/tiempo
// para "leyó completo" (scroll>=90% y tiempo>=30s), mismo intervalo de ping (5s) y el mismo
// sendBeacon al ocultarse la pestaña. Solo se monta para viewers que NO son el dueño
// (ver detalle_servicio.php:1616, misma condición).
export function VistaTracker({ publicacionId }: { publicacionId: number }) {
  useEffect(() => {
    if (!publicacionId) return;

    const SK = "nubira_sid";
    let sid = localStorage.getItem(SK);
    if (!sid || sid.length < 10) {
      sid = Date.now() + "-" + Math.random().toString(36).slice(2, 10);
      localStorage.setItem(SK, sid);
    }

    function getDispositivo(): "movil" | "tablet" | "desktop" {
      const w = window.innerWidth;
      if (w < 768) return "movil";
      if (w <= 1024) return "tablet";
      return "desktop";
    }

    const origen = (document.referrer || "").slice(0, 120);
    let tiempo = 0;
    let scrollPct = 0;
    let leyoCompleto = false;

    function calcScroll(): number {
      const h = document.body.scrollHeight - window.innerHeight;
      if (h <= 0) return 100;
      return Math.round(((window.scrollY + window.innerHeight) / document.body.scrollHeight) * 100);
    }

    function onScroll() {
      const p = calcScroll();
      if (p > scrollPct) scrollPct = p;
      if (!leyoCompleto && scrollPct >= 90 && tiempo >= 30) leyoCompleto = true;
    }
    document.addEventListener("scroll", onScroll, { passive: true });

    const tiempoInterval = setInterval(() => {
      if (document.visibilityState === "visible") {
        tiempo++;
        if (!leyoCompleto && scrollPct >= 90 && tiempo >= 30) leyoCompleto = true;
      }
    }, 1000);

    function payload(): string {
      return JSON.stringify({
        tipo: "servicio",
        publicacion_id: publicacionId,
        session_id: sid,
        tiempo_segundos: tiempo,
        scroll_max_pct: scrollPct,
        leyo_completo: leyoCompleto,
        dispositivo: getDispositivo(),
        origen,
      });
    }

    const pingInterval = setInterval(() => {
      fetch("/api/vistas", { method: "POST", headers: { "Content-Type": "application/json" }, body: payload() }).catch(() => {});
    }, 5000);

    function onVisibilityChange() {
      if (document.visibilityState === "hidden") {
        navigator.sendBeacon("/api/vistas", payload());
      }
    }
    document.addEventListener("visibilitychange", onVisibilityChange);

    return () => {
      document.removeEventListener("scroll", onScroll);
      document.removeEventListener("visibilitychange", onVisibilityChange);
      clearInterval(tiempoInterval);
      clearInterval(pingInterval);
    };
  }, [publicacionId]);

  return null;
}
