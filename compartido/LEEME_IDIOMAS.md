# Idiomas de Tukán (`tukan_idiomas.php`): cómo lo conecta cada programa

Una sola pieza, **un solo archivo PHP sin composer** (`compartido/tukan_idiomas.php`), para que **todos los programas Tukán**
tengan el botón 🌐 con **español, inglés, francés, portugués e italiano**. Es la misma idea que ya usan TuVocalía y TuAcademia
(v28), separada para que sirva en cualquier programa. Cada programa guarda una **copia sin cambios** (como `tukan_ia.php`).

## Cómo funciona

- El programa se escribe **solo en español**, como siempre.
- Si la persona elige otro idioma con el botón 🌐, la página se arma en español y **al final** se traduce cada texto visible
  con el diccionario del programa: `idiomas/en.php`, `fr.php`, `pt.php`, `it.php` (cada uno devuelve
  `['texto en español' => 'traducción']`).
- **Lo que escriben los usuarios no se traduce** (nombres de clientes, mensajes, productos): no está en el diccionario.
  Tampoco se toca nada dentro de `<script>`, `<style>`, `<textarea>`, `<svg>`, `<code>`, `<pre>` ni entre `<!--notr-->` y `<!--/notr-->`.
- Acepta números (`"Quedan {n} días"`) y partes variables (`"Datos para entrar de {s}"`), íconos adelante, `:` al final,
  partes separadas con ` · ` y `Etiqueta: valor`.
- **Lo que no tiene traducción se queda en español**: nunca se rompe nada.
- El idioma se recuerda en una cookie (1 año) y, si el programa lo pide, en la cuenta del usuario.
- Las respuestas JSON, descargas y archivos no se traducen (solo `text/html`).

## Conectarlo a un programa (5 pasos)

1. Copiar `compartido/tukan_idiomas.php` al programa (por ejemplo `src/tukan_idiomas.php`), **sin cambios**.
2. Al arrancar (antes de imprimir nada, después de abrir la sesión):

```php
require_once __DIR__ . '/../src/tukan_idiomas.php';
tukan_idioma_config([
    'dir'       => __DIR__ . '/../idiomas',
    'usuario'   => fn () => usuario()['idioma'] ?? null,                 // opcional
    'guardar'   => fn (string $l) => guardar_idioma_del_usuario($l),     // opcional
    'defecto'   => fn () => getenv('IDIOMA_PRUEBA') ?: null,             // opcional (pruebas o idioma del negocio)
    'faltantes' => getenv('IDIOMA_FALTANTES') ?: null,                   // SOLO en pruebas
]);
tukan_idioma_iniciar();
```

3. En la barra de arriba: `<?= tukan_idioma_selector() ?>` (trae su propio estilo). En la pantalla de entrar y en las páginas públicas también.
4. Textos que escribe el JavaScript: en el `<head>` poner `<?= tukan_idioma_js([...lista de frases...]) ?>` y en el JS usar
   `TR('Texto en español')`. Si hay números o nombres: `TR('Guardando {n} números…').replace('{n}', n)`.
5. Diccionarios: correr las pruebas de pantallas con `faltantes` encendido y otro idioma forzado. El archivo queda con todos
   los textos que se vieron sin traducción → se traducen y se guardan en `idiomas/xx.php`. Luego, prueba que revise que cada
   diccionario carga y que `{n}` / `{s}` coinciden (ver `tuwhatsapp/tests/prueba_idiomas.php`).

> Ojo con los datos de prueba: nombres de clientes o mensajes de las pruebas **no** deben quedar en el diccionario
> (si un cliente real escribe lo mismo, se traduciría). Solo textos que están en el código del programa.

## Funciones

| Función | Para qué |
|---|---|
| `tukan_idiomas()` | lista: `es`, `en`, `fr`, `pt`, `it` con nombre y bandera |
| `tukan_idioma_config([...])` | carpeta de diccionarios y opciones |
| `tukan_idioma_actual()` | idioma de esta página (`?lang=` › cookie › usuario › defecto › español) |
| `tukan_tr('texto')` | traduce un texto suelto (por ejemplo, un error en JSON o un correo) |
| `tukan_traducir_html($html, 'en')` | traduce una página completa |
| `tukan_idioma_iniciar()` | empieza a capturar la página para traducirla al final |
| `tukan_idioma_selector()` | botón 🌐 con los 5 idiomas |
| `tukan_idioma_js([...])` | pone `window.TR()` con las frases del JavaScript |
| `tukan_idioma_opciones('es')` | `<option>` de los idiomas para un `<select>` |

## Estado en los programas (22 oct 2026)

| Programa | Idiomas |
|---|---|
| TuVocalía, TuAcademia | ✅ (sistema propio v28, mismo funcionamiento; ~9.000 frases por idioma) |
| WhatsApp Tukán | ✅ primero con la pieza compartida |
| TuSalón, TuHospedaje, TuEvento, TuPlanner, TuGuardería Canina, TuSpa, TuGroomer, TuPass, ERP para productoras, TuRemolque, Panel Tukán Central | ⬜ en orden, uno por uno |
