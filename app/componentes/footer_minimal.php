<?php
// app/componentes/footer_minimal.php
// UX NUBIRA 2.0: Footer estilo App (no intrusivo, legalmente funcional)
?>
<footer class="mt-12 border-t border-gray-100 pt-6 pb-28 md:pb-8 flex flex-col md:flex-row justify-between items-center gap-4 px-2 w-full">
    <div class="text-[12px] text-gray-400 font-medium text-center md:text-left">
        &copy; 2025 - <?= date('Y') ?> Nubira.cl. Todos los derechos reservados.
    </div>
    
    <div class="flex flex-wrap justify-center gap-4 md:gap-6 text-[12px] font-bold text-gray-500">
        <a href="/sobre-nosotros" class="hover:text-[#54A6D8] transition-colors">Sobre Nosotros</a>
        <a href="/terminos" class="hover:text-[#54A6D8] transition-colors">Términos</a>
        <a href="/privacidad" class="hover:text-[#54A6D8] transition-colors">Privacidad</a>
        <a href="mailto:contacto@nubira.cl" class="hover:text-[#54A6D8] transition-colors flex items-center gap-1">
            <i class="fa-solid fa-envelope"></i> Soporte
        </a>
        <a href="https://instagram.com/nubira.cl" target="_blank" class="hover:text-[#54A6D8] transition-colors flex items-center gap-1">
            <i class="fa-brands fa-instagram"></i> Instagram
        </a>
        <a href="https://facebook.com/nubira.cl" target="_blank" class="hover:text-[#54A6D8] transition-colors flex items-center gap-1">
            <i class="fa-brands fa-facebook"></i> Facebook
        </a>
    </div>
</footer>