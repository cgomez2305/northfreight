# Créditos de imágenes

Toda la fotografía del sitio es el set **Night Freight**: 36 imágenes generadas con IA por Carlos siguiendo `ART-DIRECTION.md` (hora azul y noche, navy y cian con un acento ámbar, sin rostros, sin logotipos ni texto legible). Los **masters originales (PNG de 1122 a 1914 px de ancho) están fuera del repositorio**. Este archivo no se sirve por web (`index.php` bloquea `.md`).

No queda ninguna foto de stock (Pixabay ni del andamiaje inicial) ni ningún video: `assets/video/` ya no existe. Los videos de stock se retiraron porque no combinan con la línea gráfica; el slot de video sigue en el código (ver "Slot de video" abajo).

## Archivos

- `nf-<nombre>.jpg` (respaldo, ancho nativo) y `nf-<nombre>-{640,1024,1280,…,ancho nativo}.webp` (calidad 84, `method=6`, `unsharp` suave de radio 0,6 y 40 % solo en las variantes reducidas; la variante nativa no se retoca). Ninguna variante se amplía por encima del master.
- Fuera del set: `favicon.png|svg`, `isotype*.svg`, `star-outline.svg` y `og-default.jpg`.

## Recortes reales (hechos desde el master a resolución nativa, no con `cover`)

| Archivo | Master | Recorte | Uso |
|---|---|---|---|
| `nf-highway-dusk-white-trucks-m-{480,640,753}` | 1672×941 | 4:5, 753×941, x 811 a 1564 | Hero del home ≤760 px |
| `nf-highway-dusk-white-trucks-sq-{640,941}` | 1672×941 | 1:1, 941×941, x 666 a 1607 | Hero del home 761 a 1024 px |
| `nf-{phone-chat-dashboard,archive-aisle-cyan,dispatch-room-maps,hands-map-keys,signing-clipboard-night,white-truck-rainy-highway,trailer-rows-aerial}-tall-{480,640,819}` | 1536×1024 | 4:5, 819×1024, a alto completo | Sticky "Why a desk" (3) y onboarding (4) en ≥1025 px |
| `nf-truck-lights-rain-front-pill-320`, `nf-white-truck-cyan-horizon-pill-320` | 1672×941 y 1734×907 | 2,125:1 (640×301 y 760×358) reducido a 320 px | Pastillas del H2 "Three fronts" |
| `nf-white-truck-cyan-horizon-r43-{640,1024,1209}` | 1734×907 | 4:3, 1209×907, x 525 a 1734 | Hero de Dry van |
| `nf-route-map-night-trucks-r43-{640,1024,1255}` | 1672×941 | 4:3, 1255×941, x 417 a 1672 | Hero de How it works |
| `nf-lone-truck-rain-night-m-{480,658}` | 1914×822 | 4:5, 658×822, x 1202 a 1860 | CTA final en móvil |
| `og-default.jpg` | `nf-white-truck-cyan-horizon` | 1200×630 (1,905:1) con velo, isotipo, "NORTHSTAR" y la frase de siempre | Imagen al compartir |

## Videos (Night Freight)

Loops generados con IA por Carlos en HeyGen a partir de las imágenes de marca (originales 2688×1536, 15 s). Recodificados a 1920×1080, 24 fps, H.264 high, crf 26, `preset slow`, sin audio, `+faststart`, 13,6 s con fundido de costura de 1,4 s (el final enlaza con el principio). El póster de cada uno es su primer fotograma.

| Archivo | Origen en el Drive de Carlos | Peso | Uso |
|---|---|---|---|
| `assets/video/hero.mp4` | `IPrBXKo6_2x_upscaled.mp4` (V01, autopista al anochecer) | 2,0 MB | Hero del home (≥1025 px). La foto `nf-highway-dusk-white-trucks` es el póster y el LCP. |
| `assets/video/freight-dock.mp4` | `irYhmStC_2x_upscaled.mp4` (V02, muelle nocturno) | 3,7 MB | Banda del home (≥1025 px). Póster: `nf-freight-dock-loop` (primer fotograma, 1920×1080). |
| (no está en el repo) | `bTXhzswz_2x_upscaled.mp4` (V03, sala del desk) | 3,4 MB | Reserva: versión codificada lista, fuera del repo (no se publica sin uso). |

El código solo pide el video en ≥1025 px, sin `prefers-reduced-motion` ni `saveData`, tras `load` + idle: en móvil, tablet y con movimiento reducido solo se descarga la foto.

- **Hero del home:** `ns_hero_video('hero')` detecta `assets/video/hero.mp4` (opcional `hero.webm`); `main.js` lo monta con fundido sobre la foto.
- **Banda del home:** `partial('video-band', [... 'video' => 'freight-dock'])` en `templates/pages/home.php`; sin el mp4 no se emite `<video>`.
- **Video del equipo (Our desk y home):** sigue sin URL real; el play queda desactivado (`data-video-src`).
