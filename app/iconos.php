<?php
declare(strict_types=1);

/** Iconos SVG (trazo de 2 px, 24x24) y logo de Tukán, dentro de la página (sin archivos ni librerías). */
function icono(string $nombre): string
{
    static $trazos = [
        'inicio'    => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h5v-6h4v6h5V9.5"/>',
        'programas' => '<rect x="3" y="3" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="2"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="3.75"/>',
        'actividad' => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1"/><circle cx="3.5" cy="12" r="1"/><circle cx="3.5" cy="18" r="1"/>',
        'ayuda'     => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.2a2.6 2.6 0 0 1 5 .8c0 1.8-2.5 2.2-2.5 3.8"/><path d="M12 17.2h.01"/>',
        'cuenta'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.2-4 4.3-6 8-6s6.8 2 8 6"/>',
        'usuarios'  => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.9-3.4 3.4-5.2 6.5-5.2s5.6 1.8 6.5 5.2"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.9c2 .7 3.2 2.4 3.6 5.1"/>',
        'ajustes'   => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
        'salir'     => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 16l-4-4 4-4M6 12h10"/>',
        'externo'   => '<path d="M14 4h6v6"/><path d="M20 4 11 13"/><path d="M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4"/>',
        'refrescar' => '<path d="M20 11a8 8 0 0 0-14.6-4.5L4 8"/><path d="M4 3v5h5"/><path d="M4 13a8 8 0 0 0 14.6 4.5L20 16"/><path d="M20 21v-5h-5"/>',
        'mas'       => '<path d="M12 5v14M5 12h14"/>',
        'ok'        => '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.7 2.7L16 9.8"/>',
        'error'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/>',
        'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5h.01"/>',
        'copiar'    => '<rect x="8" y="8" width="12" height="12" rx="2.5"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
        'escudo'    => '<path d="M12 3 4.5 6v5.5c0 4.6 3.1 8.2 7.5 9.5 4.4-1.3 7.5-4.9 7.5-9.5V6z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
        'reloj'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'rayo'      => '<path d="M13 3 5 13.5h6L10 21l8-10.5h-6z"/>',
        'llave'     => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l2 2M14.5 8.5l2 2"/>',
        'editar'    => '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
        'basura'    => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/>',
        'enchufe'   => '<path d="M9 3v5M15 3v5"/><path d="M6 8h12v3a6 6 0 0 1-12 0z"/><path d="M12 17v4"/>',
        'flecha'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'volver'    => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
        'pausa'     => '<rect x="6" y="4.5" width="4" height="15" rx="1.2"/><rect x="14" y="4.5" width="4" height="15" rx="1.2"/>',
        'restaurar' => '<path d="M4 12a8 8 0 1 0 2.6-5.9L4 8.5"/><path d="M4 3.5v5h5"/>',
        'candado'   => '<rect x="5" y="10.5" width="14" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>',
        'celular'   => '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M11 18.5h2"/>',
        'terminal'  => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="m7 9 3 3-3 3M12.5 15H17"/>',
        'imprimir'  => '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
        'bandeja'   => '<path d="M3 13h5l1.5 3h5L16 13h5"/><path d="M5.5 5h13L21 13v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5z"/>',
        'clientes'  => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.9-3.4 3.4-5.2 6.5-5.2s5.6 1.8 6.5 5.2"/><circle cx="17.5" cy="9.5" r="2.5"/><path d="M17 14.6c2.4.2 4 1.9 4.5 4.9"/>',
        'demo'      => '<path d="M12 3l2.2 5.3L20 9l-4.4 3.8L17 19l-5-3.1L7 19l1.4-6.2L4 9l5.8-.7z"/>',
        'finanzas'  => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'mas_menu'  => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
        'calendario' => '<rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'embudo'    => '<path d="M3 4h18l-7 8.5V19l-4 2v-8.5z"/>',
        'whatsapp'  => '<path d="M4 20l1.3-3.9A8 8 0 1 1 8 18.8z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1-1.5-2-1-1 1c-1-.5-1.8-1.3-2.3-2.3l1-1-1-2z"/>',
        'telefono'  => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
        'correo'    => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m3.5 6.5 8.5 6 8.5-6"/>',
        'dinero'    => '<rect x="2.5" y="6" width="19" height="12" rx="2.5"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9v.01M18 15v.01"/>',
        'adjunto'   => '<path d="M20 11.5 12.2 19.3a5 5 0 0 1-7.1-7.1l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7l-8.5 8.5a1.7 1.7 0 0 1-2.4-2.4l7.8-7.8"/>',
        'descargar' => '<path d="M12 4v11M7 10l5 5 5-5"/><path d="M4 19h16"/>',
        'filtro'    => '<path d="M4 6h16M7 12h10M10 18h4"/>',
        'buscar'    => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
        'campana'   => '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        'chevron'   => '<path d="m9 6 6 6-6 6"/>',
        'atras'     => '<path d="m15 6-6 6 6 6"/>',
        'abajo'     => '<path d="m6 9 6 6 6-6"/>',
        'check'     => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'cruz'      => '<path d="M6 6l12 12M18 6 6 18"/>',
        'nube'      => '<path d="M7 18.5h10.5a4 4 0 0 0 .6-7.95A6 6 0 0 0 6.6 9.1 4.7 4.7 0 0 0 7 18.5z"/>',
        'nota'      =>'<path d="M5 3h10l4 4v14H5z"/><path d="M14 3v5h5M8 12h8M8 16h6"/>',
    ];
    $d = $trazos[$nombre] ?? $trazos['info'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
}

/** Logo de Tukán (los bloques del tucán + la palabra). $blanco = versión para fondo oscuro. */
function logo_tukan(bool $blanco = true, string $clase = ''): string
{
    $c = $blanco ? '#FFFFFF' : '#14213D';
    $ojo = $blanco ? '#14213D' : '#FFFFFF';
    $palabra = 'M43.68 0.0Q35.448 0.0 30.324 -5.124Q25.2 -10.248 25.2 -18.816V-65.856H4.368V-83.328H25.2V-109.2H46.368V-83.328H69.216V-65.856H46.368V-22.512Q46.368 -17.472 51.072 -17.472H67.2V0.0ZM114.24 1.344Q104.496 1.344 97.188 -3.108Q89.88 -7.56 85.848 -15.456Q81.816 -23.352 81.816 -33.6V-83.328H102.984V-35.28Q102.984 -25.872 107.604 -21.168Q112.224 -16.464 120.792 -16.464Q130.536 -16.464 135.912 -22.932Q141.288 -29.4 141.288 -40.992V-83.328H162.456V0.0H141.624V-10.92H138.6Q136.584 -6.72 131.04 -2.688Q125.496 1.344 114.24 1.344ZM180.096 0.0V-117.6H201.264V-52.584H204.288L231.84 -83.328H259.392L220.92 -43.008L260.736 0.0H233.52L204.288 -33.096H201.264V0.0ZM294.84 2.352Q285.936 2.352 278.88 -0.756Q271.824 -3.864 267.708 -9.828Q263.592 -15.792 263.592 -24.36Q263.592 -32.928 267.708 -38.724Q271.824 -44.52 279.132 -47.46Q286.44 -50.4 295.848 -50.4H318.696V-55.104Q318.696 -60.984 315.0 -64.764Q311.304 -68.544 303.24 -68.544Q295.344 -68.544 291.48 -64.932Q287.616 -61.32 286.44 -55.608L266.952 -62.16Q268.968 -68.544 273.42 -73.836Q277.872 -79.128 285.348 -82.404Q292.824 -85.68 303.576 -85.68Q320.04 -85.68 329.616 -77.448Q339.192 -69.216 339.192 -53.592V-22.512Q339.192 -17.472 343.896 -17.472H350.616V0.0H336.504Q330.288 0.0 326.256 -3.024Q322.224 -6.048 322.224 -11.088V-11.256H319.032Q318.36 -9.24 316.008 -5.964Q313.656 -2.688 308.616 -0.168Q303.576 2.352 294.84 2.352ZM298.536 -14.784Q307.44 -14.784 313.068 -19.74Q318.696 -24.696 318.696 -32.928V-34.608H297.36Q291.48 -34.608 288.12 -32.088Q284.76 -29.568 284.76 -25.032Q284.76 -20.496 288.288 -17.64Q291.816 -14.784 298.536 -14.784ZM291.648 -92.736 304.08 -117.6H327.936L310.128 -92.736ZM360.192 0.0V-83.328H381.024V-72.408H384.048Q386.064 -76.776 391.608 -80.724Q397.152 -84.672 408.408 -84.672Q418.152 -84.672 425.46 -80.22Q432.768 -75.768 436.8 -67.956Q440.832 -60.144 440.832 -49.728V0.0H419.664V-48.048Q419.664 -57.456 415.044 -62.16Q410.424 -66.864 401.856 -66.864Q392.112 -66.864 386.736 -60.396Q381.36 -53.928 381.36 -42.336V0.0Z';
    return '<svg class="' . e($clase) . '" role="img" aria-label="Tukán" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 680 178">'
        . '<g><rect x="0" y="0" width="40" height="40" rx="12" fill="' . $c . '"/><rect x="46" y="0" width="40" height="40" rx="12" fill="' . $c . '"/>'
        . '<rect x="0" y="46" width="40" height="40" rx="12" fill="' . $c . '"/>'
        . '<rect x="46" y="46" width="40" height="40" rx="12" fill="' . $ojo . '" stroke="' . $c . '" stroke-width="5"/><circle cx="66" cy="66" r="9" fill="' . $c . '"/>'
        . '<rect x="92" y="46" width="40" height="40" rx="12" fill="#FFC93C"/><rect x="138" y="46" width="40" height="40" rx="20" fill="#FFC93C"/>'
        . '<rect x="92" y="92" width="40" height="40" rx="12" fill="#FF8A1F"/><rect x="0" y="92" width="40" height="40" rx="12" fill="' . $c . '"/>'
        . '<rect x="46" y="92" width="40" height="40" rx="12" fill="' . $c . '"/><rect x="0" y="138" width="40" height="40" rx="12" fill="' . $c . '"/>'
        . '<rect x="46" y="138" width="40" height="40" rx="12" fill="' . $c . '"/></g>'
        . '<path transform="translate(222 146)" d="' . $palabra . '" fill="' . $c . '"/></svg>';
}
