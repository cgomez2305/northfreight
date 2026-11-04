# Créditos de imágenes

Toda la fotografía del sitio es de Carlos (generada con IA). Hay dos generaciones:

- **Set v2 "fotografía documental" (fase J, 2026-11-04):** 33 masters JPG de 3840 px de ancho (el CTA, de 3864 px; el OG, de 2400 px; FAQ 4:5 de 3072×3840) generados con los prompts de `ART-DIRECTION-v2.md`. **Los masters están fuera del repositorio.** Nombre de archivo: `nf-<nombre-v2>` (p. ej. `nf-service-dispatch-booking`).
- **Set v1 "Night Freight" (fases E a G):** solo quedan 2 piezas en uso: `nf-dispatch-room-maps` (+ `-tall`) y `nf-dispatch-room-wide-night`. El hero del home y la banda de video pasaron a la v2 en la fase K (fotos `nf-hero-highway-poster` y `nf-freight-dock`, primeros fotogramas de los videos nuevos); `nf-highway-dusk-white-trucks` y `nf-freight-dock-loop` se borraron.

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
- `nf-<nombre>-{640,1024,1280,1600,2048,2560}.webp` y `-3200.webp` solo en los heros fotográficos (patrón A) y el CTA: calidad 83, `method=6`, `unsharp` suave (radio 0,6, 40 %) en todas las variantes (todas son reducciones; no se exporta el ancho nativo). **Fase M (2026-11-04):** las variantes de 1600 px o más de las fotos que NO son heros (`nf-hero-*` queda en 83) se recomprimieron a calidad 80, `method=6` (83 de 85 archivos; 12,1 MB a 9,5 MB, −22 %; PSNR 43 a 46 dB frente al archivo anterior; diferencia imperceptible a 100 % en comparación lado a lado). Solo se recomprimió si el ahorro era ≥ 15 % (`nf-freight-dock-1600` y `nf-onboarding-learn-1600` se quedaron como estaban). Al regenerar una variante ≥ 1600 de esas fotos, usar calidad 80. El mega menú solo lleva 640 y 1024. `ns_img()` los detecta por nombre.
- Fuera del set: `favicon.png|svg`, `isotype*.svg` y `og-default.jpg` (`star-outline.svg` se borró en la fase M: nada lo usaba).

## Recortes reales (desde el master gradado a resolución nativa, no con `cover`)

| Archivo | Master | Recorte (x0, y0, x1, y1) | Uso |
|---|---|---|---|
| `nf-cta-night-truck-m-{480,640,1024,1325}` | 3864×1656 | 4:5, 2390,0 a 3715,1656 | CTA final en móvil (≤760 px) |
| `nf-{why-one-channel,why-record-every-load}-tall-{480,640,1024,1280}` | 3840×2560 | 4:5, 2048×2560: 973,0 a 3021,2560 y 1088,0 a 3136,2560 | Sticky "Why a desk" (≥1025 px) |
| `nf-onboarding-{learn,scope,run,review}-tall-{480,640,1024,1280}` | 3840×2560 | 4:5, 2048×2560: x0 896, 1088, 1664 y 1357 | Onboarding del home (≥1025 px) |
| `nf-hero-amazon-relay-pill-320`, `nf-hero-dry-van-pill-320` | 3840×2880 | 2,125:1 a todo el ancho: 0,681 a 3840,2488 y 0,536 a 3840,2343, reducido a 320 px | Pastillas del H2 "Three fronts" |
| `nf-hero-highway-poster-m-{480,640,1024,1280,1600}` | `hero-highway-poster` 3840×2160 | 4:5, 1728×2160: 1901,0 a 3629,2160 (camión entero, x 71 a 85 % de la foto) | Hero del home en ≤760 px |
| `nf-hero-highway-poster-sq-{640,1024,1280,1600,2048}` | `hero-highway-poster` 3840×2160 | 1:1, 2160×2160: 1454,0 a 3614,2160 | Hero del home de 761 a 1024 px |
| `og-default.jpg` | `og-default` v2 (2400×1256) | 2392×1256 (x 8 a 2400) reducido a 1200×630, con velo, isotipo, "NORTHSTAR" y la frase de siempre | Imagen al compartir |

No se usan: `hero-our-desk` (muestra a dos personas sentadas de espaldas: Carlos decide), `desk-log-backdrop` (casi negra), `cta-night-truck-16x9` y `og-default-1200x630` (apoyo).
`nf-faq-dock-light-wide` es la versión horizontal de la foto del FAQ (apoyo): miniatura de `/blog/` y foto provisional de Box truck (draft).

## Videos (fase K, 2026-11-04)

Videos generados con IA por Carlos a partir de las fotos v2 (image-to-video, originales 2688×1536, 24 fps, 15,08 s). **No son bucles naturales** (en la autopista el camión se aleja hasta casi desaparecer en la niebla; en el muelle la lluvia moja el suelo y cruza un vehículo; en la oficina la luz del techo se apaga), así que un fundido de costura habría dado un salto visible: **se reproducen UNA vez y se quedan en el último fotograma** (sin `loop`). El póster de cada uno es su primer fotograma gradado.

| Archivo | Origen en el Drive de Carlos | Peso | Uso |
|---|---|---|---|
| `assets/video/hero.mp4` | `vkSk4c32_2x_upscaled.mp4` (de `hero-highway-poster`, autopista al amanecer) | 3,4 MB | Hero del home (≥1025 px). La foto `nf-hero-highway-poster` es el póster y el LCP. Último fotograma: autopista vacía con un camión lejano en la niebla. |
| `assets/video/freight-dock.mp4` | `jzLjaq0L_2x_upscaled.mp4` (de `setup-amazon-relay`, patio de noche con camión en el muelle) | 3,2 MB | Banda del home (≥1025 px). Póster: `nf-freight-dock` (primer fotograma, 2688×1536). |
| (no está en el repo) | `8CAdhlyz_2x_upscaled.mp4` (de `hero-our-desk`, oficina del desk) | 7,2 MB | No usado: Our desk sigue con `nf-dispatch-room-maps`. Queda codificado fuera del repo por si se usa después. |

Procesado de los videos (script fuera del repo, fotograma a fotograma): reescalado a 1920×1080 (lanczos), la MISMA gradación de marca de la fase J (parámetros de arriba) con los radios del anti-banding a la mitad (el video va a 1920, no a 3840) y grano gaussiano más suave (σ 0,8 % en lugar de 1,2 %, semilla distinta en cada fotograma); H.264 high, 24 fps, `preset slow`, crf 23, `yuv420p`, sin audio (los originales de autopista y oficina traían pista AAC: se descartó), `+faststart`. Sin fundido de costura. Los 364 fotogramas se codifican desde un intermedio sin pérdida.

**Hero, color y encuadre:** el primer fotograma del video coincide con el master (camión y ladera en el mismo sitio, desplazamiento < 0,3 %), así que se usa el master a 3840 px como foto del hero. El video llega ~6 niveles más oscuro y más azul que el master, por eso el video pasa por una curva por canal (LUT de 48 tramos, monótona) ajustada al master antes de la gradación; tras la gradación la diferencia media con la foto es de 2,2 niveles (p95 6,0; sesgo −0,5). El video es más blando que la foto (el modelo no repite el detalle del pavimento), de modo que el paso foto a video se nota solo como un ligero suavizado, sin salto de color ni de encuadre.

El código solo pide el video en ≥1025 px, sin `prefers-reduced-motion` ni `saveData`, tras `load` + idle: en móvil, tablet y con movimiento reducido solo se descarga la foto.

- **Hero del home:** `ns_hero_video('hero')` detecta `assets/video/hero.mp4` (opcional `hero.webm`); `main.js` lo monta con fundido sobre la foto, lo pausa al salir de pantalla y lo reanuda donde iba; al terminar se queda en el último fotograma (no se reinicia).
- **Banda del home:** `partial('video-band', [... 'video' => 'freight-dock'])` en `templates/pages/home.php`; sin el mp4 no se emite `<video>`. Empieza a bajar al acercarse y se reproduce una vez al verse (≥30 % de la banda).
- **Video del equipo (Our desk y home):** sigue sin URL real; el play queda desactivado (`data-video-src`).

## Hero y banda de video v2 (fase K)

| Archivo | Master | Procesado | Uso |
|---|---|---|---|
| `nf-hero-highway-poster-{640,1024,1280,1600,2048,2560,3200}` + `.jpg` (1600) | `hero-highway-poster` 3840×2160 | gradación de la fase J (grano 1,2 %), WebP 83, `method=6`, unsharp suave | Hero del home (LCP) |
| `nf-freight-dock-{640,1024,1280,1600,2048,2560}` + `.jpg` (1600) | primer fotograma de `dock.mp4` (2688×1536), sin LUT | misma gradación (anti-banding con radios al 70 %) | Póster de la banda de video |


## Recortes del FAQ por tema (fase L2)

| Archivo | Master | Recorte | Uso |
|---|---|---|---|
| `nf-check-tracking-mirror-tall-{480,640,1024,1280}` | `check-tracking-mirror` 3840×2880 | 4:5 nativo (2304×2880), gradación de la fase J | FAQ del home, tema Amazon Relay |
| `nf-hero-back-office-tall-{480,640,1024,1280}` | `hero-back-office` 3840×2880 | 4:5 nativo (2304×2880), gradación de la fase J | FAQ del home, tema alcance |
| `nf-check-owner-approve-tall-{480,640,1024,1280}` | `check-owner-approve` 3840×2880 | 4:5 nativo (2304×2880), gradación de la fase J | FAQ del home, tema con quién trabajamos |
