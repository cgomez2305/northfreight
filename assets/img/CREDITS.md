# Créditos de imágenes

Toda la fotografía del sitio es de Carlos (generada con IA). Hay dos generaciones:

- **Set v2 "fotografía documental" (fase J, 2026-11-04):** 33 masters JPG de 3840 px de ancho (el CTA, de 3864 px; el OG, de 2400 px; FAQ 4:5 de 3072×3840) generados con los prompts de `ART-DIRECTION-v2.md`. **Los masters están fuera del repositorio.** Nombre de archivo: `nf-<nombre-v2>` (p. ej. `nf-service-dispatch-booking`).
- **Set v1 "Night Freight" (fases E a G):** solo quedan 4 piezas en uso: `nf-highway-dusk-white-trucks` (+ `-m`, `-sq`; es el primer fotograma de `assets/video/hero.mp4`), `nf-freight-dock-loop` (primer fotograma de `assets/video/freight-dock.mp4`), `nf-dispatch-room-maps` (+ `-tall`) y `nf-dispatch-room-wide-night`. Se conservan hasta que Carlos regenere los videos o decida sustituirlas (ver "Lo discutible" en el reporte de la fase J).

No hay ninguna foto de stock. Este archivo no se sirve por web (`index.php` bloquea `.md`).

## Gradación de marca (fase J, script fuera del repo)

Las v2 salen más grises y claras que el sitio (navy `#04101F` y cian). Antes de exportar, TODAS reciben la misma gradación por lote (PIL + numpy, en float):

1. **Anti-banding:** en las zonas planas (diferencia de luminancia con una versión desenfocada σ 7 menor de 0,02) se mezcla con esa versión desenfocada; los bordes reales no se tocan. Rompe los plateaus de posterizado de cielos, papeles y zonas oscuras.
2. **Sombras hacia navy:** mezcla con `#04101F` del 30 % en luminancia 0, decreciendo lineal hasta 0 % en luminancia 0,35.
3. **Medios tonos más fríos:** rojo −3 % y azul +2 %, ponderados por 4·Y·(1−Y).
4. **Saturación** ×0,97 (no sube); el cian no se refuerza.
5. **Curva** con contraste suave: 22 % de mezcla con `smoothstep`.
6. **Grano luminoso** gaussiano σ 1,2 % (mismo valor en R, G y B) aplicado a 3840 px antes de reducir.

Hoja antes y después de las 4 pruebas (exterior nocturno, cielo nublado claro, interior con lámpara, detalle de papeles) en el reporte de la fase J.

## Archivos

- `nf-<nombre>.jpg`: respaldo a 1600 px de ancho (calidad 85, progresivo).
- `nf-<nombre>-{640,1024,1280,1600,2048,2560}.webp` y `-3200.webp` solo en los heros fotográficos (patrón A) y el CTA: calidad 83, `method=6`, `unsharp` suave (radio 0,6, 40 %) en todas las variantes (todas son reducciones; no se exporta el ancho nativo). El mega menú solo lleva 640 y 1024. `ns_img()` los detecta por nombre.
- Fuera del set: `favicon.png|svg`, `isotype*.svg`, `star-outline.svg` y `og-default.jpg`.

## Recortes reales (desde el master gradado a resolución nativa, no con `cover`)

| Archivo | Master | Recorte (x0, y0, x1, y1) | Uso |
|---|---|---|---|
| `nf-cta-night-truck-m-{480,640,1024,1325}` | 3864×1656 | 4:5, 2390,0 a 3715,1656 | CTA final en móvil (≤760 px) |
| `nf-{why-one-channel,why-record-every-load}-tall-{480,640,1024,1280}` | 3840×2560 | 4:5, 2048×2560: 973,0 a 3021,2560 y 1088,0 a 3136,2560 | Sticky "Why a desk" (≥1025 px) |
| `nf-onboarding-{learn,scope,run,review}-tall-{480,640,1024,1280}` | 3840×2560 | 4:5, 2048×2560: x0 896, 1088, 1664 y 1357 | Onboarding del home (≥1025 px) |
| `nf-hero-amazon-relay-pill-320`, `nf-hero-dry-van-pill-320` | 3840×2880 | 2,125:1 a todo el ancho: 0,681 a 3840,2488 y 0,536 a 3840,2343, reducido a 320 px | Pastillas del H2 "Three fronts" |
| `nf-highway-dusk-white-trucks-{m,sq}-*` | v1 (1672×941) | sin cambios | Hero del home en ≤760 px y 761 a 1024 px |
| `og-default.jpg` | `og-default` v2 (2400×1256) | 2392×1256 (x 8 a 2400) reducido a 1200×630, con velo, isotipo, "NORTHSTAR" y la frase de siempre | Imagen al compartir |

No se usan: `hero-highway-poster` (el hero del home sigue con la foto v1 porque es el primer fotograma del video), `hero-our-desk` (muestra a dos personas sentadas de espaldas: Carlos decide), `desk-log-backdrop` (casi negra), `cta-night-truck-16x9` y `og-default-1200x630` (apoyo).
`nf-faq-dock-light-wide` es la versión horizontal de la foto del FAQ (apoyo): miniatura de `/blog/` y foto provisional de Box truck (draft).

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
