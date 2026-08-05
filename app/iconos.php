<?php
// app/iconos.php

function icon($name, $classes = "w-6 h-6") {
    // Estilos base
    $sizeAttrs = nb_icon_intrinsic_size($classes);
    $outline = "fill=\"none\" viewBox=\"0 0 24 24\" stroke-width=\"1.5\" stroke=\"currentColor\" $sizeAttrs";
    $solid   = "viewBox=\"0 0 24 24\" fill=\"currentColor\" $sizeAttrs";

    switch ($name) {
        // --- GENERALES (Outline) ---
        case 'home': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25' /></svg>";
        case 'search': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z' /></svg>";
        case 'user': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z' /></svg>";
        case 'chat-outline': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z' /></svg>";
        case 'plus-outline': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M12 4.5v15m7.5-7.5h-15' /></svg>";
        case 'arrow-right': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3' /></svg>";
        case 'pencil': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125' /></svg>";
        case 'building': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0012 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75z' /></svg>";

        // --- PREMIUM (Sólidos para acciones) ---
        case 'publish-doc': return "<svg $solid class='$classes'><path fill-rule='evenodd' d='M5.625 1.5c-1.036 0-1.875.84-1.875 1.875v17.25c0 1.035.84 1.875 1.875 1.875h12.75c1.035 0 1.875-.84 1.875-1.875V12.75A3.75 3.75 0 0016.5 9h-1.875a1.875 1.875 0 01-1.875-1.875V5.25A3.75 3.75 0 009 1.5H5.625zM7.5 15a.75.75 0 01.75-.75h7.5a.75.75 0 010 1.5h-7.5A.75.75 0 017.5 15zm.75 2.25a.75.75 0 000 1.5H12a.75.75 0 000-1.5H8.25z' clip-rule='evenodd' /><path d='M12.971 1.816A5.23 5.23 0 0114.25 5.25v1.875c0 .207.168.375.375.375H16.5a5.23 5.23 0 013.434 1.279 9.768 9.768 0 00-6.963-6.963z' /></svg>";
        case 'publish-class': return "<svg $solid class='$classes'><path d='M11.7 2.805a.75.75 0 01.6 0A60.65 60.65 0 0122.83 8.72a.75.75 0 01-.231 1.337 49.949 49.949 0 00-9.902 3.912l-.003.002-.34.18a.75.75 0 01-.707 0A50.009 50.009 0 001.402 10.06a.75.75 0 01-.231-1.337A60.653 60.653 0 0111.7 2.805z' /><path d='M13.06 15.473a48.45 48.45 0 017.666-3.282c.134 1.414.22 2.843.255 4.285a.75.75 0 01-.46.71 47.878 47.878 0 00-8.105 4.342.75.75 0 01-.832 0 47.877 47.877 0 00-8.104-4.342.75.75 0 01-.461-.71c.035-1.442.121-2.87.255-4.286A48.4 48.4 0 0110.94 15.473c.191.1.404.154.618.154.214 0 .427-.054.618-.154z' /></svg>";
        case 'hire-solid': return "<svg $solid class='$classes'><path fill-rule='evenodd' d='M7.5 3.75A1.5 1.5 0 006 5.25v13.5a1.5 1.5 0 001.5 1.5h9a1.5 1.5 0 001.5-1.5V5.25a1.5 1.5 0 00-1.5-1.5h-9zM15.75 9a.75.75 0 00-1.5 0v2.25H12a.75.75 0 000 1.5h2.25V15a.75.75 0 001.5 0v-2.25H18a.75.75 0 000-1.5h-2.25V9zM6.75 18v-1.5h1.5V18h-1.5zm0-3.75v-1.5h1.5v1.5h-1.5zm0-3.75v-1.5h1.5v1.5h-1.5z' clip-rule='evenodd' /></svg>";
        case 'chat-solid': return "<svg $solid class='$classes'><path fill-rule='evenodd' d='M4.804 21.644A6.707 6.707 0 006 21.75a6.721 6.721 0 003.583-1.029c.774.182 1.584.279 2.417.279 5.322 0 9.75-3.97 9.75-9 0-5.03-4.428-9-9.75-9s-9.75 3.97-9.75 9c0 2.409 1.025 4.587 2.674 6.192.232.226.277.428.254.543a3.73 3.73 0 01-.814 1.686.75.75 0 00.44 1.223zM8.25 10.875a1.125 1.125 0 100 2.25 1.125 1.125 0 000-2.25zM10.875 12a1.125 1.125 0 112.25 0 1.125 1.125 0 01-2.25 0zm4.875-1.125a1.125 1.125 0 100 2.25 1.125 1.125 0 000-2.25z' clip-rule='evenodd' /></svg>";
        
        // OTROS
        case 'star-solid': return "<svg $solid class='$classes'><path fill-rule='evenodd' d='M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.007 5.404.433c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.433 2.082-5.006z' clip-rule='evenodd' /></svg>";
        case 'check-circle': return "<svg $solid class='$classes'><path fill-rule='evenodd' d='M8.603 3.799A4.49 4.49 0 0112 2.25c1.357 0 2.573.6 3.397 1.549a4.49 4.49 0 013.498 1.307 4.491 4.491 0 011.307 3.497A4.49 4.49 0 0121.75 12a4.49 4.49 0 01-1.549 3.397 4.491 4.491 0 01-1.307 3.497 4.491 4.491 0 01-3.497 1.307A4.49 4.49 0 0112 21.75a4.49 4.49 0 01-3.397-1.549 4.49 4.49 0 01-3.498-1.306 4.491 4.491 0 01-1.307-3.498A4.49 4.49 0 012.25 12c0-1.357.6-2.573 1.549-3.397a4.49 4.49 0 011.307-3.497 4.491 4.491 0 013.497-1.307zm7.007 6.387a.75.75 0 10-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.14-.094l3.75-5.25z' clip-rule='evenodd' /></svg>";
        case 'lock': return "<svg $solid class='$classes'><path fill-rule='evenodd' d='M12 1.5a5.25 5.25 0 00-5.25 5.25v3a3 3 0 00-3 3v6.75a3 3 0 003 3h10.5a3 3 0 003-3v-6.75a3 3 0 00-3-3v-3c0-2.9-2.35-5.25-5.25-5.25zm3.75 8.25v-3a3.75 3.75 0 10-7.5 0v3h7.5z' clip-rule='evenodd' /></svg>";
        case 'eye': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z' /><path stroke-linecap='round' stroke-linejoin='round' d='M15 12a3 3 0 11-6 0 3 3 0 016 0z' /></svg>";
        case 'x-circle': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z' /></svg>";
        case 'alert-triangle': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z' /></svg>";
        case 'info-circle': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z' /></svg>";
        case 'academic-cap': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M4.26 10.147a60.438 60.438 0 00-.491 6.347A48.62 48.62 0 0112 20.904a48.62 48.62 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.636 50.636 0 00-2.658-.813A59.906 59.906 0 0112 3.493a59.903 59.903 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5' /></svg>";
        case 'chat-bubble': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.74 1.04.586 1.641a4.483 4.483 0 01-.923 1.785A5.969 5.969 0 006 21c1.282 0 2.47-.402 3.445-1.087.81.22 1.668.337 2.555.337z' /></svg>";
        case 'chevron-right': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M8.25 4.5l7.5 7.5-7.5 7.5' /></svg>";
        case 'chevron-down': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M19.5 8.25l-7.5 7.5-7.5-7.5' /></svg>";
        case 'shield-check': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M9 12.75L11.25 15 15 9.75M21 12c0 5.591-3.824 10.29-9 11.622C6.824 22.29 3 17.591 3 12V5.487c0-.694.376-1.33.985-1.664A47.962 47.962 0 0112 2.25c2.717 0 5.358.32 7.515.857.609.336.985.972.985 1.664V12z' /></svg>";
        case 'arrow-left': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18' /></svg>";
        case 'x-mark': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M6 18L18 6M6 6l12 12' /></svg>";
        case 'exclamation-triangle': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z' /></svg>";
        case 'paperclip': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13' /></svg>";
        case 'paper-airplane': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5' /></svg>";
        case 'share-outline': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M7.5 8.25H6a2.25 2.25 0 00-2.25 2.25v9A2.25 2.25 0 006 21.75h12A2.25 2.25 0 0020.25 19.5v-9A2.25 2.25 0 0018 8.25h-1.5M12 3v12m0-12L8.25 6.75M12 3l3.75 3.75' /></svg>";
        case 'copy': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M16.5 8.25V6a2.25 2.25 0 00-2.25-2.25H6A2.25 2.25 0 003.75 6v8.25A2.25 2.25 0 006 16.5h2.25m8.25-8.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-7.5A2.25 2.25 0 018.25 18v-1.5m8.25-8.25h-6a2.25 2.25 0 00-2.25 2.25v6' /></svg>";

        // --- PERFIL / NUEVOS ---
        case 'sparkles': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z' /></svg>";
        case 'camera': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z' /><path stroke-linecap='round' stroke-linejoin='round' d='M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z' /></svg>";
        case 'trash': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0' /></svg>";
        case 'fire': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M15.362 5.214A8.252 8.252 0 0112 21 8.25 8.25 0 016.038 7.048 8.287 8.287 0 009 9.6a8.983 8.983 0 013.361-6.867 8.21 8.21 0 003 2.48z' /><path stroke-linecap='round' stroke-linejoin='round' d='M12 18a3.75 3.75 0 00.495-7.467 5.99 5.99 0 00-1.925 3.546 5.974 5.974 0 01-2.133-1A3.75 3.75 0 0012 18z' /></svg>";
        case 'clock': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z' /></svg>";
        case 'chevron-left': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M15.75 19.5L8.25 12l7.5-7.5' /></svg>";
        case 'circle': return "<svg $outline class='$classes'><circle cx='12' cy='12' r='9' /></svg>";
        case 'wifi': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 011.06 0z' /></svg>";
        case 'users': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z' /></svg>";
        case 'laptop': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0H3' /></svg>";
        case 'check': return "<svg $outline class='$classes'><path stroke-linecap='round' stroke-linejoin='round' d='M4.5 12.75l6 6 9-13.5' /></svg>";

        default: return "";
    }
}

// [Fix zoom íconos] Tamaño intrínseco (width/height reales en el SVG) calculado
// desde la clase Tailwind que ya se pasa a icon() — evita que el ícono se vea
// gigante (tamaño por defecto del navegador para SVG sin dimensión) antes de que
// Tailwind CDN inyecte la clase w-N/h-N real. La CSS de Tailwind, una vez cargada,
// siempre pisa visualmente este atributo — es solo el estado antes de esa carga.
function nb_icon_intrinsic_size($classes) {
    static $escala = [
        '0' => 0, '0.5' => 2, '1' => 4, '1.5' => 6, '2' => 8, '2.5' => 10,
        '3' => 12, '3.5' => 14, '4' => 16, '5' => 20, '6' => 24, '7' => 28,
        '8' => 32, '9' => 36, '10' => 40, '11' => 44, '12' => 48,
        '14' => 56, '16' => 64, '20' => 80, '24' => 96,
    ];
    if (preg_match('/(?:^|\s)w-([0-9.]+)(?:\s|$)/', $classes, $m) && isset($escala[$m[1]])) {
        $px = $escala[$m[1]];
        return "width=\"$px\" height=\"$px\"";
    }
    return 'width="24" height="24"';
}
?>