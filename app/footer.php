  <!-- Aquí puedes agregar scripts globales que uses en todas las páginas -->

  <script>
    // Script del carrusel (puedes moverlo aquí para que sea global)
    document.addEventListener('DOMContentLoaded', () => {
      const slides = document.querySelectorAll('.carousel-slide');
      const nextBtn = document.getElementById('nextSlide');
      const prevBtn = document.getElementById('prevSlide');
      let current = 0;

      function showSlide(index) {
        slides.forEach((slide, i) => {
          slide.classList.toggle('opacity-100', i === index);
          slide.classList.toggle('z-10', i === index);
          slide.classList.toggle('opacity-0', i !== index);
          slide.classList.toggle('z-0', i !== index);
        });
      }

      function nextSlide() {
        current = (current + 1) % slides.length;
        showSlide(current);
      }

      function prevSlide() {
        current = (current - 1 + slides.length) % slides.length;
        showSlide(current);
      }

      if (nextBtn) nextBtn.addEventListener('click', nextSlide);
      if (prevBtn) prevBtn.addEventListener('click', prevSlide);

      showSlide(current);
      setInterval(nextSlide, 8000);
    });
  </script>

</body>

</html>
