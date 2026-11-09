# Créditos de imágenes

Toda la fotografía del sitio es de Carlos (generada con IA). Hay tres generaciones (la v3 de la fase T sustituye a la v2 en 24 slots; ver «Set v3» más abajo):

- **Set v3 "Premium Documentary" (fase T, 2026-11-07):** 27 slots con variantes `-a`/`-b` (JPG de 3840 px; FAQ y CTA móviles de 3072×3840), prompts de `ART-DIRECTION-v3.md`. **Los masters están fuera del repositorio.**

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
| `nf-hero-highway-poster-m-{480,640,864}` | primer fotograma de `hero.mp4` (1920×1080, reescalado de 1280×720) | 4:5, 864×1080: x 1056 a 1920 (camión entero) | Hero del home en ≤760 px |
| `nf-hero-highway-poster-sq-{640,1024,1080}` | primer fotograma de `hero.mp4` | 1:1, 1080×1080: x 840 a 1920 | Hero del home de 761 a 1024 px |
| `og-default.jpg` | `og-default` v2 (2400×1256) | 2392×1256 (x 8 a 2400) reducido a 1200×630, con velo, isotipo, "NORTHSTAR" y la frase de siempre | Imagen al compartir |

No se usan: `hero-our-desk` (muestra a dos personas sentadas de espaldas: Carlos decide), `desk-log-backdrop` (casi negra), `cta-night-truck-16x9` y `og-default-1200x630` (apoyo).
`nf-faq-dock-light-wide` es la versión horizontal de la foto del FAQ (apoyo): miniatura de `/blog/` y foto provisional de Box truck (draft).

## Videos (fase K, 2026-11-04)

Videos generados con IA por Carlos a partir de las fotos v2 (image-to-video, originales 2688×1536, 24 fps, 15,08 s). **No son bucles naturales** (en la autopista el camión se aleja hasta casi desaparecer en la niebla; en el muelle la lluvia moja el suelo y cruza un vehículo; en la oficina la luz del techo se apaga), así que un fundido de costura habría dado un salto visible: **se reproducen UNA vez y se quedan en el último fotograma** (sin `loop`). El póster de cada uno es su primer fotograma gradado.

| Archivo | Origen en el Drive de Carlos | Peso | Uso |
|---|---|---|---|
| `assets/video/hero.mp4` | video v3 de Carlos (generado a partir de una imagen de la carpeta v3; **1280×720, 24 fps, 8,0 s, sin audio**; el generador NO conservó `hero-highway-poster-v3-a` como primer fotograma: composición distinta, camión a la derecha y sol bajo a la izquierda) | 2,66 MB | Hero del home (≥1025 px, una pasada sin bucle). Recodificado a 1920×1080 H.264 high crf 27, `+faststart`, sin audio, con unsharp 0,45, balance de sombras +3,5 % azul / medios R −1 % B +0,8 %, saturación ×0,98, contraste ×1,02 y grano temporal σ≈3. La foto `nf-hero-highway-poster` es SU PRIMER FOTOGRAMA exacto (extraído del mp4 ya codificado, sin salto al arrancar). Último fotograma: el camión mucho más lejos por el carril derecho. |
| `assets/video/freight-dock.mp4` | video v3 de Carlos (V02, muelle de distribución en hora dorada, operario con chaleco que camina; **1280×720**, 8 s, sin audio; no conservó `service-dispatch-booking-v3-a` como primer fotograma) | 1,58 MB | Banda del home (≥1025 px, una pasada sin bucle). Recodificado a 1920×1080 H.264 crf 27 con el mismo tratamiento que el hero (ver «Videos v3»). El póster `nf-freight-dock` es su primer fotograma exacto. Sustituye al video v2 de la fase K. |
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
| `nf-hero-highway-poster-{640,1024,1280,1600,1920}` + `.jpg` (1600) | primer fotograma de `hero.mp4` | WebP 83, `method=6`, sin gradación extra (la del video ya está aplicada) | Hero del home (LCP). Máximo 1920 de ancho: no hay master mayor |
| `nf-freight-dock-{640,1024,1280,1600,1920}` | primer fotograma de `freight-dock.mp4` (v3) | WebP 82, `method=6`, sin gradación extra; sin JPG de respaldo (nunca se emitía) | Póster de la banda de video del home |


## Recortes del FAQ por tema (fase L2)

| Archivo | Master | Recorte | Uso |
|---|---|---|---|
| `nf-check-tracking-mirror-tall-{480,640,1024,1280}` | `check-tracking-mirror` 3840×2880 | 4:5 nativo (2304×2880), gradación de la fase J | FAQ del home, tema Amazon Relay |
| `nf-hero-back-office-tall-{480,640,1024,1280}` | `hero-back-office` 3840×2880 | 4:5 nativo (2304×2880), gradación de la fase J | FAQ del home, tema alcance |
| `nf-check-owner-approve-tall-{480,640,1024,1280}` | `check-owner-approve` 3840×2880 | 4:5 nativo (2304×2880), gradación de la fase J | FAQ del home, tema con quién trabajamos |

## Variante intermedia de 768 px (fase N, 2026-11-04)

Entre 640 y 1024 había un salto grande: para una necesidad de ~700 px el navegador bajaba la de 1024 (p. ej. onboarding 95 KB). Se añadió **`-768.webp`** a todas las fotos de contenido y a sus recortes `-tall` y `-m` (45 archivos, 1,5 MB). Mismo proceso que el resto: el master v2 de `assets/img/ART-DIRECTION.md` (3840 px; los 4:5 del FAQ y los recortes `-tall`/`-m` con las coordenadas de las tablas de arriba), gradación de marca de la fase J, reducción LANCZOS a 768, `unsharp` suave (radio 0,6, 40 %), WebP calidad 83, `method=6`. Solo se escribieron los `-768`; el resto de variantes no se tocó (los recortes y la calidad ≥1600 siguen como estaban).

- Con 768: `nf-{service-dispatch-booking,service-load-tracking,service-back-office,blog-cost,blog-relay-requirements,blog-relay-load-board,blog-lumper-detention,resource-ifta,resource-rate-per-mile,resource-bol-template,resource-agreement,resource-glossary,setup-owner-operator,setup-small-fleet,setup-amazon-relay,check-owner-approve,check-tracking-mirror,check-dry-van-interior,faq-dock-light-wide,faq-dock-light-4x5,why-one-channel,why-record-every-load,onboarding-learn,onboarding-scope,onboarding-run,onboarding-review,cta-night-truck,hero-owner-operators,hero-how-it-works,hero-amazon-relay,hero-dry-van,hero-back-office}-768.webp`.
- Recortes con 768: `nf-{why-one-channel,why-record-every-load,onboarding-learn,onboarding-scope,onboarding-run,onboarding-review,check-tracking-mirror,hero-back-office,check-owner-approve}-tall-768.webp` (4:5, mismas coordenadas que sus `-480/640/1024/1280`) y `nf-cta-night-truck-m-768.webp` (4:5, 2390,0 a 3715,1656).
- Sin master v2 (piezas v1 y póster): `nf-dispatch-room-maps-768` y `nf-dispatch-room-wide-night-768` se reducen desde su variante mayor (1536 y 1448, sin unsharp extra); `nf-freight-dock-768` sale del fotograma gradado de la fase K.
- Sin 768 (a propósito): `nf-hero-highway-poster*` (hero del home), `nf-mega-relay` (solo ≥1025 px), `nf-dispatch-room-maps-tall` (máx. 819), los `-pill-320` y `og-default`.
- 480 para las fotos no recortadas: medido y descartado. A 390 con DPR 2 la variante de 640 ya amplía x1,34 a x1,49 (el recorte cover lo manda el alto del marco), así que una de 480 pasaría del límite de x1,6.
- `sizes`: móvil (≤640 px) `65vw`; escritorio, el ancho útil del marco; `ns_sizes_retina()` (templates/partials/kit.php) añade la rama retina con el 65 %. Ver el comentario de `ns_img()`.

## Set v3 «Premium Documentary» (fase T, 2026-11-07)

Luminosa y cálida a propósito (cielos despejados, luz dorada, equipo limpio). Los nombres de slot (`nf-<slot>`) NO cambian; se borraron del repo las variantes v2 de los slots reemplazados (el historial de git las conserva). Todo sale de los masters `<slot>-v3-a.jpg` (3840 px; script de gradación y exportación fuera del repo). Variante usada: **`-a` en todos los slots** (la `-b` queda como alternativa por si Carlos prefiere otro encuadre; ninguna página repite foto, así que no hizo falta).

### Gradación de marca SUAVE (sustituye a la de la fase J para la v3)

Mismo orden que la v2 pero más ligera: la foto conserva su luminosidad y sus tonos cálidos.

1. **Anti-banding:** en zonas planas (|Y − Y_desenfocada σ 7| < 0,012) mezcla de hasta el 65 % con la versión desenfocada (la v2 llegaba al 100 % con umbral 0,02: con cielos claros borraba los rayos del sol); los bordes no se tocan.
2. **Sombras hacia navy:** 18 % de `#04101F` en luminancia 0, lineal hasta 0 % en luminancia 0,30 (v2: 30 % hasta 0,35).
3. **Medios tonos:** rojo −2 % y azul +1 %, ponderados por 4·Y·(1−Y) (v2: −3 % y +2 %).
4. **Saturación** ×0,98 (v2: ×0,97).
5. **Curva** con 10 % de `smoothstep` (v2: 22 %).
6. **Grano** gaussiano σ 1,0 % a 3840 px antes de reducir (v2: 1,2 %).

Exportación: WebP en 640, 768, 1024, 1280, 1600, 2048, 2560 (más 3200 en los heros A y el CTA; `-tall` y `-m` en 480, 640, 768, 1024, 1280 y 1325 en `-m` del CTA), LANCZOS, `unsharp` suave (radio 0,6, 40 %) en todas las reducciones, `method=6`; calidad **83 en `nf-hero-*` y CTA, 80 en el resto**; JPG de respaldo a 1600 (calidad 85, progresivo).

### Slots reemplazados

| Slot | Master | Recorte o nota |
|---|---|---|
| `nf-hero-owner-operators` | `hero-owner-operators-v3-a` | `pos` de la página `62% 100%` (lleva el salpicadero oscuro bajo el titular) |
| `nf-hero-amazon-relay` (+ `-pill-320`) | `hero-amazon-relay-v3-a` | pastilla: 2,125:1, 0,882 a 3840,2689; `pos` de la página `70% 30%` |
| `nf-hero-dry-van` (+ `-pill-320`) | `hero-dry-van-v3-a` | pastilla: 2,125:1, 0,536 a 3840,2343 |
| `nf-hero-how-it-works` | `hero-how-it-works-v3-a` | |
| `nf-hero-back-office` (+ `-tall`) | `hero-back-office-v3-a` | `-tall` 4:5, 1152,0 a 3456,2880 |
| `nf-cta-night-truck` | `cta-night-truck-v3-a` (3864×1656) | |
| `nf-cta-night-truck-m` | `cta-night-truck-v3-mobile-a` (3072×3840, 4:5 nativo) | el CTA móvil ya no es un recorte: es la versión móvil de Carlos |
| `nf-service-dispatch-booking` · `-load-tracking` · `-back-office` | `service-*-v3-a` | `service-dispatch-booking-v3` también espera su video v3 (ver abajo); la foto del slot sí se reemplazó |
| `nf-why-one-channel` · `-record-every-load` (+ `-tall`) | `why-*-v3-a` | `-tall` 4:5, 1587,0 a 3635,2560 y 1357,0 a 3405,2560 |
| `nf-onboarding-learn` · `-scope` · `-run` · `-review` (+ `-tall`) | `onboarding-*-v3-a` | `-tall` 4:5: 1472,0 / 1280,0 / 1741,0 / 1587,0 hasta +2048,2560 |
| `nf-faq-dock-light-wide` | `faq-dock-light-v3-a` (3840×2560) | 3:2; miniatura de `/blog/` y foto provisional de Box truck |
| `nf-faq-dock-light-4x5` | `faq-dock-light-v3-mobile-a` (3072×3840, 4:5 nativo) | FAQ del home |
| `nf-setup-owner-operator` · `-small-fleet` · `-amazon-relay` | `setup-*-v3-a` | |
| `nf-check-owner-approve` · `-tracking-mirror` (+ `-tall`) · `-dry-van-interior` | `check-*-v3-a` | `-tall` 4:5, 1536,0 a 3840,2880 (owner y mirror) |
| `nf-mega-relay` | `setup-amazon-relay-v3-b` | 3:2, 0,320 a 3840,2880 (la `-b` evita repetir el encuadre de la foto de Relay) |

### Lo que NO se tocó

- Con la fase W los tres videos del home y de Owner son v3; solo `service-dispatch-booking-v3` (la imagen) sigue sin uso como póster porque ningún video parte de ella. Los masters descargados están fuera del repo.
- `hero-our-desk-v3-*` y `why-desk-team-v3-*`: salas vacías generadas que podrían leerse como la oficina real de NorthStar. Our desk sigue con `nf-dispatch-room-maps` (v1) y el ítem 3 de Why a desk también.
- `nf-resource-*`, `nf-blog-*`, `nf-dispatch-room-*`, `og-default.jpg` y los `OG` de artículos: no tienen v3.

### Contraste con las fotos luminosas (medido, 1440 px salvo indicación)

Método: captura con el texto transparente y, por cada texto, contraste del color real del texto contra el píxel de fondo MÁS cercano en luminancia (peor caso; entre paréntesis el percentil 99). Mínimo por ubicación sobre todas las líneas de texto. Antes = sitio con la v2; ahora = v3 con los degradados reforzados.

| Ubicación | Antes | Con v3 sin ajustar | Ahora |
|---|---|---|---|
| Hero Owner (titular cian) | 5,48 | 3,73 | **5,27** (p99 6,11); 1280: 5,64; 1025: 5,99 |
| Hero Relay | 5,68 | 4,81 | **5,38**; 1280: 5,63; 1025: 5,60 |
| Hero Dry van | 5,89 | 5,14 | **5,67**; 1280: 5,67; 1025: 5,68 |
| Hero How it works | 6,07 | 5,87 | **6,17**; 1025: 6,27 |
| Hero Back office | 5,88 | 5,62 | **5,94**; 1025: 5,99 |
| Hero Box truck (draft, foto de FAQ muy clara) | 5,66 | 4,69 | **5,17** |
| CTA final (1440 / 1920 / 1025) | 6,47 / 6,47 / 6,48 | 5,78 / 5,16 / 5,84 | **5,98 / 5,90 / 6,04** |
| CTA final a 834 px (párrafo) | 6,46 | 3,59 | **5,40** |
| Filas C Relay (5) y How (4) | 11,0 a 11,1 | 10,9 a 11,1 | 10,9 a 11,1 |
| Banda D Back office (foto sin cambios) | 6,48 | 6,48 | 6,48 |
| Tarjetas B (owner, relay, dry van) | 9,96 | 9,96 | 9,96 |
| Tarjeta B Load tracking (píldora) | 4,81 | 8,24 | 8,48 |
| Pestañas del home | 6,48 | 6,48 | 6,48 |
| Carril de servicios del home (1440 / 1280) | 7,9 a 9,3 / 6,9 a 10 | 7,96 a 8,74 / 5,11 | 9,5 a 9,9 / 6,56 a 10 |
| Hero Our desk (video, panel de vidrio) | 4,48 | 4,48 | 5,04 |

A 834 y 390 px los heros y las filas llevan el texto bajo la foto sobre `--bg` (≥6,2 en el hero; el CTA a 390 queda en 5,08, igual que antes). El mínimo de `hero-owner-390` (2,56) y el de `rail-1280-0` (1,66) son artefactos de la captura (botón bajo la `mbar` y un píxel suelto durante el pin) y valen igual antes y después.

**Qué se ajustó**, siempre en CSS compartido (tokens de `tokens.css`) y sin tocar el brillo de la foto: `--photo-fade-left` (heros A y bandas D) con el tramo oscuro más largo (stops .93 al 40 %, .85 al 53 %, .56 al 65 %, .25 al 76 %, .07 al 86 %, 0 al 94 %; antes .92/.78/.42/.12/0 hasta el 88 %); `--cta-veil` igual de largo (.92 al 42 %, .80 al 54 %, .46 al 66 %, .16 al 76 %, 0 al 86 %); `--card-fade` (carril del home) .93 al 40 % y .6 al 62 % (antes .92 al 36 % y .5 al 60 %); `--glass-strong` de .88 a .92 (tarjetas de vidrio sobre foto). En recortes: `pos` `62% 100%` en el hero de Owner (el titular cae sobre el salpicadero y no sobre la ventanilla clara), `70% 30%` en el de Relay (el titular queda bajo el cielo azul y no sobre el resplandor), y la tarjeta «Why Relay needs a dedicated desk» pierde `flip` para no tapar el camión.



## Hero del home v3 (video de Carlos, 2026-11-07)

- Origen: `hero-v3-raw.mp4`, 1280×720. **Limitación de nitidez (§14):** a 1440 px de ancho el hero se muestra con `cover` por alto (~872 px): ×1,21 respecto de los píxeles reales a 1x y ×2,4 en retina. Incumple la regla del sitio; es provisional hasta que Carlos reexporte el video a 1080p o más (con el generador, "upscale" o resolución alta) y se repita este paso.
- Contraste del texto del hero sobre el video (peor de tres fotogramas: inicio, 4 s y final; texto grande = «Documented.» cian): 1440 5,04 grande / 9,54 pequeño; 1280 5,10 / 9,02; 1025 5,12 / 6,92; 1024 5,99 / 10,1; 834 5,93 / 9,98; 768 5,93 / 9,67; 390 6,67 / 8,29. Botones: navy sobre cian 6,48. De 761 a 1024 px se añadió el token `--hero-fade-tablet` (sin él el párrafo medía 3,35:1 sobre el cielo y el camión claros). El texto de escritorio queda sobre el degradado de siempre (`--hero-fade-left`): el sol bajo queda atenuado por el degradado a la izquierda.
- El `object-position` (90 % 50 %) deja el camión (x 68 a 95 % del cuadro) a la derecha, bajo el widget y fuera del titular.


## Videos v3 (fase W, 2026-11-07)

Tres videos de Carlos, todos 1280×720, 24 fps, 8 s, sin audio, recodificados a 1920×1080 con el mismo tratamiento que el hero (Lanczos, unsharp 0,45, balance sombras +3,5 % azul / medios R −1 % B +0,8 %, saturación ×0,98, contraste ×1,02, grano temporal σ≈3, H.264 high crf 27, `+faststart`, sin audio). Ninguno conservó el fotograma inicial de su imagen v3, así que el póster de escritorio es SU primer fotograma exacto (extraído del mp4 ya codificado):

| Video | Pesa | Dónde | Póster de escritorio | Foto en <1025 px |
|---|---|---|---|---|
| `owner-cab.mp4` (V03, cabina al atardecer, brazo en el volante) | 1,75 MB | hero de `/dispatch-for-owner-operators/` (`video` del partial `hero`) | `nf-hero-owner-operators-vid-{640,1024,1280,1600,1920}` | `nf-hero-owner-operators` v3-a (3840 px) |
| `freight-dock.mp4` (V02, muelle) | 1,58 MB | banda de video del home | `nf-freight-dock-*` (reemplaza el slot) | el mismo póster |
| `cta-night-truck.mp4` (V04, tráiler al anochecer que se acerca) | 1,38 MB | CTA final SOLO en el home (`'cta_video' => 'cta-night-truck'` en la ruta; las demás páginas siguen con la foto) | `nf-cta-night-truck-vid-{640,1024,1280,1600,1920}` | `nf-cta-night-truck` v3-a y `-m` |

- Convención: el póster de un video es `<base de la imagen>-vid`; el partial lo usa solo en ≥1025 px (`art`), donde el video se monta (`[data-bgvideo]` en `main.js`: baja al acercarse, reproduce al verse ≥30 %, una pasada, se queda en el último fotograma). Con movimiento reducido, saveData o sin JS se ve el póster. Para activar un CTA con video en otra ruta: `cta_video` y los `-vid`.
- Origen 720p: a 1440 px se amplía ×1,1 a ×1,2 y en retina ×2,2 o más (fuera de la regla de nitidez, provisional hasta que Carlos reexporte a 1080p o más). Hero Owner: `pos_d` `0% 100%` para que el cielo brillante no toque el titular.
- Contraste medido sobre inicio, 4 s y final (1440, 1280, 1025): hero Owner texto grande cian 4,51 / 5,29 / 5,96 y pequeño ≥ 16,3; CTA del home grande 6,04 / 6,10 / 6,11 y pequeño ≥ 7,25; tarjeta de la banda 13,4 en todos. Peso total de los videos del home: 5,6 MB (hero 2,66 + banda 1,58 + CTA 1,38) frente a 6,6 MB antes, todos solo ≥1025 px y diferidos.

### Videos v3, segunda tanda (fase X, 2026-11-07)

Mismo tratamiento y mecánica que la fase W (1280×720 a 1920×1080, crf 28, póster `-vid` = primer fotograma exacto, solo ≥1025 px; <1025 px sigue la foto v3):

| Video | Pesa | Dónde | Póster de escritorio |
|---|---|---|---|
| `how-interchange.mp4` (V05, intercambiador aéreo al anochecer con camiones por las rampas; el origen pesaba 6,6 MB; **aparece una cabina roja** en el puente: no afecta a la marca porque no hay logos, pero se puede pedir a Carlos que la evite si regenera) | 2,22 MB | hero de `/how-it-works/` | `nf-hero-how-it-works-vid-{640,1024,1280,1600,1920}` |
| `relay-dock.mp4` (V06, patio de muelles aéreo al anochecer, tractor con tráiler que entra por la curva; sin marcas) | 1,79 MB | hero de `/amazon-relay-dispatch/` | `nf-hero-amazon-relay-vid-{640,1024,1280,1600,1920}` |

- Contraste del texto del hero sobre inicio, 4 s y final (1440, 1280, 1025): How it works texto grande 5,46 / 5,59 / 6,35 y pequeño ≥ 10,8; Relay texto grande 5,28 / 5,99 / 5,47 y pequeño ≥ 14,8.
- Mismo aviso de nitidez (origen 720p). Con esto los videos de Carlos ya integrados son: hero, banda, Owner, CTA del home, How it works y Relay; faltan V07 a V14 y C01 a C09 de `ART-DIRECTION-VIDEO-v3.md`.

### Videos v3, tercera tanda (fase Y, 2026-11-07)

Mismo tratamiento y mecánica que las fases W y X (1280×720 a 1920×1080, crf 28, póster `-vid` = primer fotograma exacto, solo ≥1025 px, foto v3 en <1025 px):

| Video | Pesa | Dónde | Póster de escritorio |
|---|---|---|---|
| `dryvan-rear-doors.mp4` (V07, avance hacia el interior de un tráiler en un muelle, operario con chaleco que camina hacia el fondo; el origen pesaba 5,3 MB) | 2,21 MB | hero de `/dry-van-dispatch/` | `nf-hero-dry-van-vid-{640,1024,1280,1600,1920}` |
| `dryvan-interior.mp4` (V08, interior vacío del tráiler con la cámara avanzando hacia las puertas abiertas; el origen pesaba 6,1 MB) | 2,39 MB | panel «One desk behind every dry van load» de `/dry-van-dispatch/` (partial `photo-card`, parámetro nuevo `bgvideo`) | `nf-check-dry-van-interior-vid-{640,1024,1280,1600,1920}` |

- Contraste del hero de Dry van sobre inicio, 4 s y final (1440, 1280, 1025): texto grande 5,62 / 5,74 / 5,99 y pequeño ≥ 15,3. El panel de texto del segundo video es una tarjeta `--panel` sólida fuera de la foto, sin texto sobre el video.
- Con esto la página de Dry van tiene DOS videos (4,6 MB, ambos solo en escritorio y diferidos). Mismo aviso de nitidez (origen 720p). Videos integrados: hero y banda del home, CTA del home, Owner, How, Relay y los dos de Dry van; faltan V09 a V14 y C01 a C09 de `ART-DIRECTION-VIDEO-v3.md`.

### Video v3 del onboarding (fase Z, 2026-11-07)

- `onboarding-gate.mp4` (V09: un camión blanco sale por la puerta de un centro de distribución al amanecer y abandona el cuadro por la derecha, cámara fija; 1280×720, 8 s; recodificado a 1920×1080 crf 28, 1,30 MB). Dónde: paso 03 «We run the day-to-day» del onboarding del home (columna 4:5), solo ≥1025 px y sin texto sobre el video.
- Póster de escritorio `nf-onboarding-run-tall-vid-{480,720,864}`: primer fotograma exacto, recorte 4:5 de 864×1080 desde x 866 (cabina entera); el `<video>` usa `object-position: 82% 50%` (el mismo recorte, `--vpos` fijo en `home.css`). En <1025 px sigue la foto v3. Origen 720p: en una columna de 360 px el recorte 4:5 parte de 576 px reales, así que aquí NO se amplía.
- Videos integrados: hero, banda, CTA y onboarding del home, Owner, How, Relay y los dos de Dry van; faltan V10 a V14 y C01 a C09 de `ART-DIRECTION-VIDEO-v3.md`.

## Set v3, segunda tanda: blog, recursos y escritorio del desk (fase AB, 2026-11-07)

Con esta tanda ya no queda ninguna imagen v1 ni v2 en uso. Masters `<slot>-v3-a.jpg` de 3840 px (a 3840×2560 y la ancha a 3840×2160; prompts de `ART-DIRECTION-v3-faltantes.md`), variante **`-a` en los 11 espacios** (la `-b` queda como alternativa). Misma gradación SUAVE de la fase T (anti-banding hasta 65 %, sombras 18 % hacia navy, R −2 % / B +1 %, sat ×0,98, curva 10 %, grano 1,0 %), WebP calidad 80 en 640, 768, 1024, 1280, 1600, 2048 y 2560, JPG de respaldo a 1600, `unsharp` 0,6 / 40 %. Se borraron las variantes antiguas (el historial de git las conserva).

| Slot | Master | Nota |
|---|---|---|
| `nf-blog-cost` · `nf-blog-relay-requirements` · `nf-blog-relay-load-board` · `nf-blog-lumper-detention` | `blog-*-v3-a` | portadas de los 4 artículos; `relay-load-board` también es la cortina del home y una fila de «What we manage» de Relay (contraste medido ≈ 11:1 en las 5 filas), `lumper-detention` y `relay-requirements` son filas de Relay |
| `nf-resource-ifta` · `-rate-per-mile` · `-bol-template` · `-agreement` · `-glossary` | `resource-*-v3-a` | miniaturas de `/resources/`, tarjetas relacionadas y el panel de Back office (`agreement`) |
| `nf-dispatch-room-wide` (antes `nf-dispatch-room-wide-night`) | `dispatch-room-wide-v3-a` (16:9) | fachada del video de «Meet the desk» del home; sin gente; tarjeta de vidrio medida a 5,4 a 5,6:1 |
| `nf-dispatch-room-maps` (+ `-tall-{480,640,768,1024}`) | `dispatch-room-maps-v3-a` | Our desk y tarjeta 03 de «Why a desk»; `-tall` 4:5 de 2048×2560 desde x 704 |

**Imágenes para redes (compuestas, no generadas):** `og/post-*.jpg` (4 artículos) y `og-default.jpg` se recompusieron a 1200×630 con las portadas nuevas (y, la de `og-default`, el primer fotograma del hero), con el mismo diseño de antes: degradado navy a la izquierda, isotipo, wordmark, título y barra cian. Aviso de honestidad: las dos imágenes del desk son AMBIENTALES (detalles de escritorio sin gente), no la oficina real; se sustituyen por fotos reales del equipo cuando existan. Los textos alternativos de las 11 piezas se reescribieron para describir lo que se ve.

## Fase AC · V13 (hero de /load-tracking/)

- `tracking-cab.mp4` (V13: cabina vista desde el asiento al atardecer, mano sobre el teléfono del soporte, autopista y tráiler delante; origen 1280×720, 8 s recortado a 7,6 s porque los últimos fotogramas muestran la mano a medio gesto; recodificado a 1920×1080 crf 27, 1,21 MB). Dónde: hero de `/load-tracking/` (antes sin foto), solo ≥1025 px.
- Póster `nf-service-load-tracking-vid-{640,1024,1280,1600,1920}.webp` = primer fotograma exacto; en <1025 px el hero usa la foto v3 `nf-service-load-tracking` (pos `55% 60%`).
- Contraste medido (p99 sobre el texto, fotogramas inicio/mitad/final): «for your trucks» 6,46 / 6,52 / 6,46 (1440 / 1280 / 1025), lead ≥ 16,1, tarjeta «Desk log» 5,42 a 5,62 con `--glass-strong` (sin fondo medía 1,4).
- `tracking-mirror.mp4` (V14: espejo exterior de la cabina blanca al atardecer con el tráiler y la autopista reflejados, cámara fija; 8 s completos, sin manos; 1920×1080 crf 27, 1,24 MB). Dónde: panel «Proof, not promises» de `/load-tracking/` (`bgvideo` de `photo-card`, solo ≥1025 px); póster `nf-check-tracking-mirror-vid-{640,1024,1280,1600,1920}.webp` = primer fotograma exacto. El texto va en la tarjeta `--panel` que solapa la foto, no sobre el video (9,96:1); la píldora del enlace es de vidrio oscuro.
- `relay-yard-dusk.mp4` (V12: patio de muelles de un centro de distribución al anochecer, cámara fija, un tráiler blanco sale por la derecha; rótulos lisos, sin marcas; 1920×1080 crf 28, 0,88 MB). Dónde: pestaña «Amazon Relay carrier» de «Choose your setup» (home y Owner) vía el parámetro nuevo `video` por ítem del partial `tabs` (mismo `[data-bgvideo]` y `.pcard__video`; solo ≥1025 px; arranca cuando la pestaña se hace visible porque el panel inactivo es `display:none`). Póster `nf-setup-amazon-relay-vid-{640..1920}.webp` = primer fotograma exacto. Sin texto sobre el video (la tarjeta `--panel` solapa la foto).
- `owner-rest-area.mp4` (V10: área de descanso al amanecer, cámara fija; un conductor de espaldas se estira, se agacha, camina hacia el tractor blanco de capó largo y sube a la cabina; sin rostro visible; en 2,5 a 3,2 s baja la cabeza al agacharse y a partir de 3,5 s aparece con gorra, detalle menor a este tamaño; 1920×1080 crf 28, 1,25 MB). Dónde: pestaña «Owner-operator» de «Choose your setup» del home (la primera, la visible al cargar) vía `video` del ítem en `tabs`; póster `nf-setup-owner-operator-vid-{640..1920}.webp` = primer fotograma exacto. Owner no tiene esa pestaña (solo Small fleet y Amazon Relay).

