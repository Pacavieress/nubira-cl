<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8" />
  <title><?= $titulo ?? 'Mi Plataforma' ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  
  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Google Analytics -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-FWHGZQLSDF"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag() { dataLayer.push(arguments); }
    gtag('js', new Date());
    gtag('config', 'G-FWHGZQLSDF');
  </script>
</head>

<body class="bg-gray-100 text-gray-800">
