# NorthStar Freight: dirección de arte y prompts de imagen y video

Objetivo: que toda la fotografía del sitio tenga UNA sola línea gráfica (la del sitio oscuro navy/cian) y deje de verse genérica. Hoy usamos stock de Pixabay de 1280 px y fotos heterogéneas; con estas imágenes se reemplaza todo y se acaba la pixelación.

Cómo usar este documento: genera cada imagen con tu herramienta favorita (Midjourney v7, Flux, Imagen, Nano Banana, Ideogram, GPT-image...) pegando el PROMPT completo (ya lleva el bloque de estilo incluido). Los videos van con Veo, Kling o Runway. Entrégame los archivos con el NOMBRE indicado y yo los optimizo (WebP/AVIF, tamaños, póster) y los coloco.

---

## 1. Biblia de estilo ("Night Freight")

**Idea:** control room meets open road. Serio, tranquilo, preciso, cinematográfico. La carretera de noche vista con calma y la mesa de operaciones iluminada en cian: lo que hace NorthStar es seguir cada carga con evidencia.

| Elemento | Regla |
|---|---|
| Luz | Hora azul y noche. Sombras en navy profundo (#04101F), luces frías cian (#06A6CC) como acento principal, UNA luz cálida ámbar (faros, luces de gálibo, lámpara de dock) como único contraste cálido. |
| Color | Navy, cian, gris acero, blanco apagado de los remolques. Nada de naranjas, verdes ni rojos saturados, nada de HDR exagerado. Contraste suave, negros con detalle. |
| Cámara | Lente 35 mm o 50 mm look, f/2.8, cámara baja, horizonte bajo, compresión de teleobjetivo en carretera, grano de película mínimo, mucho detalle. |
| Composición | Mucho aire oscuro en el tercio izquierdo o en la parte baja (ahí va el texto). Líneas de fuga. Un solo sujeto claro por imagen. |
| Sujetos | Tractocamiones de EE. UU. con remolques dry van BLANCOS SIN MARCA, interestatales, docks y patios de carga, documentos en blanco, pantallas con mapas abstractos. |
| Personas | Sin rostros: espaldas, siluetas lejanas, manos. (Las personas reales del equipo se fotografían de verdad, ver sección 6.) |
| Prohibido | Logos y marcas reales (ni Amazon, ni Penske, ni Freightliner legible), texto legible, matrículas legibles, marcas de agua, bordes, estilo cartoon/3D/ilustración, manos deformes, remolques con geometría imposible. |
| Salida | Mínimo 3840 px en el lado largo (genera grande o reescala 2x con Magnific/Topaz/upscaler del generador), sRGB, JPG calidad 95 o PNG, sin texto ni marco. |

**Bloque de estilo** (ya está pegado dentro de cada prompt; úsalo tal cual si inventas más):
`cinematic photograph, blue hour, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 35mm lens, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, calm and precise mood, generous dark negative space, no text, no logos, no license plates, no watermark, no faces`

**Negative prompt** (si la herramienta lo permite): `text, letters, logos, brand names, license plates, watermark, border, frame, faces, cartoon, illustration, 3d render, oversaturated, HDR, neon, orange, distorted trailer, extra wheels, deformed hands, blurry, low resolution`

**Truco de consistencia:** genera primero H01 (hero) y elígela como referencia de estilo (`--sref` en Midjourney, "style reference" en Flux/Ideogram) para el resto. Mantén la misma semilla de color y las mismas palabras de luz.

**Control de calidad antes de entregar:** (1) sin texto ni logos en ningún punto, ni en remolques, ni en pantallas; (2) ruedas, ejes y remolques con geometría creíble (los generadores fallan aquí: descarta y regenera); (3) se ve bien recortada a 3:2 y a 16:9; (4) zona oscura libre para texto; (5) nitidez a 100 %.

---

## 2. Lista de imágenes por prioridad

**P1, imprescindibles ahora (18):** H01, CTA01, SV01 a SV03, WD01 a WD03, ON01 a ON04, FAQ01, TB01 a TB03 · más los 2 videos V01 y V02.
**P2, siguiente oleada (22):** CK01 a CK03, HR01 a HR06, BL01 a BL04, RW01 a RW05, MM01, OG01, DK01 · más V03 opcional.

| Código | Archivo | Dónde se usa | Proporción |
|---|---|---|---|
| H01 | `hero-highway-poster` | Home: hero (foto y primer fotograma del video) | 16:9 |
| CTA01 | `cta-night-truck` | Cierre de TODAS las páginas | 21:9 (y recorte 16:9) |
| SV01 | `service-dispatch-booking` | Home: tarjeta de servicio 1 | 3:2 |
| SV02 | `service-load-tracking` | Home: tarjeta de servicio 2 | 3:2 |
| SV03 | `service-back-office` | Home: tarjeta de servicio 3 | 3:2 |
| WD01 | `why-one-channel` | Home: "A team behind every load" ítem 1 | 3:2 |
| WD02 | `why-record-every-load` | Home: ítem 2 | 3:2 |
| WD03 | `why-desk-team` | Home: ítem 3 | 3:2 |
| ON01 | `onboarding-learn` | Home: paso 1 | 3:2 |
| ON02 | `onboarding-scope` | Home: paso 2 | 3:2 |
| ON03 | `onboarding-run` | Home: paso 3 | 3:2 |
| ON04 | `onboarding-review` | Home: paso 4 | 3:2 |
| FAQ01 | `faq-dock-light` | Home: FAQ con foto | 3:2 (genera también 4:5) |
| TB01 | `setup-owner-operator` | Pestaña "Owner-operator" (home y owner) | 4:3 |
| TB02 | `setup-small-fleet` | Pestaña "Small fleet" | 4:3 |
| TB03 | `setup-amazon-relay` | Pestaña "Amazon Relay carrier" | 4:3 |
| CK01 | `check-owner-approve` | Owner: panel checklist | 4:3 |
| CK02 | `check-tracking-mirror` | Load tracking: panel checklist | 4:3 |
| CK03 | `check-dry-van-interior` | Dry van: panel checklist | 4:3 |
| HR01 | `hero-owner-operators` | Hero de /dispatch-for-owner-operators/ | 4:3 |
| HR02 | `hero-amazon-relay` | Hero de /amazon-relay-dispatch/ | 4:3 |
| HR03 | `hero-dry-van` | Hero de /dry-van-dispatch/ | 4:3 |
| HR04 | `hero-how-it-works` | Hero de /how-it-works/ | 4:3 |
| HR05 | `hero-back-office` | Hero de /trucking-back-office/ | 4:3 |
| HR06 | `hero-our-desk` | Hero de /our-desk/ | 4:3 |
| BL01 a BL04 | `blog-*` | Portadas de los 4 artículos | 3:2 |
| RW01 a RW05 | `resource-*` | Miniaturas de herramientas y plantillas | 3:2 |
| MM01 | `mega-relay` | Tarjeta del menú de Services | 3:2 |
| OG01 | `og-default` | Imagen al compartir en redes | 1.91:1 (1200×630 final) |
| DK01 | `desk-log-backdrop` | Fondo del panel Desk log (opcional) | 16:9 |
| V01 | `hero-highway-loop` | Video del hero | 16:9 |
| V02 | `freight-yard-loop` | Banda de video del home | 21:9 o 16:9 |
| V03 | `desk-room-loop` | Banda opcional en Our desk | 16:9 |

---

## 3. Prompts de imagen, P1

Todos terminan con el mismo bloque de estilo para que los pegues completos.

### H01 · `hero-highway-poster` · 16:9
```
Aerial drone view gliding low over a multi-lane US interstate at blue hour, a few white semi-trucks with plain unmarked white dry van trailers in the right lanes, long red tail-light trails and amber marker lights, deep navy sky fading to a thin cyan glow at the horizon, light mist over dark tree lines, road geometry leading from bottom-right to the upper-left, large calm dark area on the left third, cinematic photograph, blue hour, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 35mm lens, photorealistic, ultra-detailed, subtle film grain, calm and precise mood, no text, no logos, no license plates, no watermark, no faces
```

### CTA01 · `cta-night-truck` · 21:9
```
Wide cinematic shot of a single semi-truck with a plain white unmarked dry van trailer driving toward camera on a wet empty highway at night, rain-slick asphalt reflecting amber headlights and cool cyan sky glow, low camera angle, slight telephoto compression, the truck on the right third, very dark left two thirds for text, cinematic photograph, blue hour, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 50mm lens, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, no text, no logos, no license plates, no watermark, no faces
```

### SV01 · `service-dispatch-booking` · 3:2
```
White unmarked dry van semi-trailer backed into a lit warehouse loading dock at dusk, dock door open with warm amber interior light, cool cyan twilight on the trailer side, painted floor lines, empty yard, a distant silhouette of a person far away only as tiny shape, cinematic photograph, blue hour, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 35mm lens, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, generous dark negative space on the left, no text, no logos, no license plates, no watermark, no faces
```

### SV02 · `service-load-tracking` · 3:2
```
Close-up of a hand holding a smartphone in front of a rainy night highway, the phone screen showing only an abstract glowing cyan route line on a dark map with no readable text, a semi-truck blurred in soft bokeh behind with amber marker lights, shallow depth of field, cinematic photograph, blue hour, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 50mm lens, photorealistic, ultra-detailed, subtle film grain, dark negative space on the left, no readable text, no logos, no license plates, no watermark, no face
```

### SV03 · `service-back-office` · 3:2
```
Dark desk seen from a low three-quarter angle: a neat stack of blank paper shipping forms with a pen, a closed laptop and a tablet showing an abstract spreadsheet of blurred cyan lines with no readable text, soft cyan rim light from the left and a single warm amber desk lamp, deep navy background, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 50mm macro feel, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, no text, no logos, no watermark, no faces
```

### WD01 · `why-one-channel` · 3:2
```
Smartphone resting on a dark truck dashboard at night, the screen showing only abstract soft cyan chat bubbles with no readable text, windshield behind it with blurred highway lights and amber marker light streaks, steering wheel edge in soft focus, cinematic photograph, blue hour, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 35mm lens, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, dark negative space on the left, no readable text, no logos, no watermark, no faces
```

### WD02 · `why-record-every-load` · 3:2
```
Orderly archive aisle of tall dark shelves with identical blank document boxes and folders, edge-lit by a cool cyan strip light running down the aisle, a single amber exit lamp far away, perfect one-point perspective, clean and silent, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 35mm lens, photorealistic, ultra-detailed, subtle film grain, dark negative space on the left, no text, no labels, no logos, no watermark, no faces
```

### WD03 · `why-desk-team` · 3:2
```
Quiet dispatch operations room at night seen from the back, rows of empty ergonomic chairs and wide monitors showing only abstract glowing cyan route maps and soft lines with no readable text, headsets resting on desks, dark navy room lit by the screens, one warm amber desk lamp in the distance, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 35mm lens, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, no people visible, no text, no logos, no watermark, no faces
```

### ON01 · `onboarding-learn` · 3:2
```
Top-down view of two pairs of hands over a large printed road map and a set of truck keys on a dark wooden table, pen and a notebook with no readable writing, cool cyan side light and one warm amber lamp glow, deep navy surroundings, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 50mm lens, photorealistic, ultra-detailed, subtle film grain, dark negative space on the left, no readable text, no logos, no watermark, no faces
```

### ON02 · `onboarding-scope` · 3:2
```
Close-up of a hand with a pen about to sign a blank agreement on a black clipboard, paper edge catching cool cyan rim light, shallow depth of field, blurred truck parked far behind in the dusk, one warm amber point of light, deep navy tones, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 85mm lens, photorealistic, ultra-detailed, subtle film grain, no readable text, no logos, no watermark, no face
```

### ON03 · `onboarding-run` · 3:2
```
Semi-truck with a plain white unmarked dry van trailer driving on a rainy night highway seen from a low angle, headlights creating amber bokeh, wet asphalt reflections, cool cyan sky glow, speed and calm at once, truck on the right, dark left third, cinematic photograph, blue hour, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 50mm lens, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, no text, no logos, no license plates, no watermark, no faces
```

### ON04 · `onboarding-review` · 3:2
```
Aerial top-down view of a large truck yard at dusk with long parallel rows of plain white trailers casting soft shadows on dark asphalt, painted bay lines, cool cyan tint over the whole scene with a few amber site lights, graphic geometry, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, drone shot, photorealistic, ultra-detailed, subtle film grain, no text, no logos, no license plates, no watermark, no faces
```

### FAQ01 · `faq-dock-light` · 3:2 (genera también 4:5)
```
Empty warehouse loading dock at blue hour, a single dock door open with soft cool cyan light spilling onto dark concrete, yellow floor lines fading into shadow, one warm amber wall lamp, clean and quiet, symmetrical composition, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 35mm lens, photorealistic, ultra-detailed, subtle film grain, no text, no signs, no logos, no watermark, no faces
```

### TB01 · `setup-owner-operator` · 4:3
```
Single semi-truck tractor with sleeper cab and a plain white unmarked dry van parked alone at a quiet truck stop at dusk, cab interior light glowing warm amber, cool cyan sky, wet asphalt, one lone truck on the right, dark left third, cinematic photograph, blue hour, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 35mm lens, photorealistic, ultra-detailed, subtle film grain, no text, no logos, no license plates, no watermark, no faces
```

### TB02 · `setup-small-fleet` · 4:3
```
Five identical white semi-trucks with plain unmarked trailers parked in a tidy row in a yard at blue hour, long converging perspective, cool cyan sky reflected on cab windows, a few amber marker lights, calm and organized, cinematic photograph, blue hour, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 50mm telephoto compression, photorealistic, ultra-detailed, subtle film grain, no text, no logos, no license plates, no watermark, no faces
```

### TB03 · `setup-amazon-relay` · 4:3 (sin ninguna marca de Amazon)
```
Plain white box trailer docked at a large modern distribution center at night, gantry lights and a row of numbered-free dock doors glowing cool cyan and amber, wet concrete apron, a yard truck far in the background, quiet industrial scale, cinematic photograph, blue hour, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 35mm lens, photorealistic, ultra-detailed, subtle film grain, no text, no signage, no logos, no license plates, no watermark, no faces
```

---

## 4. Prompts de imagen, P2

### CK01 · `check-owner-approve` · 4:3
```
Driver's hand holding a smartphone inside a truck cab at dusk, the screen showing only an abstract glowing cyan check mark on a dark interface with no text, steering wheel and amber dashboard glow softly blurred, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 50mm lens, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, no readable text, no logos, no watermark, no face
```

### CK02 · `check-tracking-mirror` · 4:3
```
View into a large truck side mirror at night, the reflection showing a long highway with trailing red and amber lights and a cool cyan horizon, door frame dark in the foreground, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 50mm lens, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, no text, no logos, no license plates, no watermark, no faces
```

### CK03 · `check-dry-van-interior` · 4:3
```
Interior of an empty clean white dry van trailer seen from the rear doors, ribbed walls and wooden floor, the open doors framing a blue hour yard with cool cyan light shafts and a hint of amber, perfect one-point perspective, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 24mm lens, photorealistic, ultra-detailed, subtle film grain, no text, no logos, no watermark, no faces
```

### HR01 · `hero-owner-operators` · 4:3
```
Truck cab interior at night from the passenger seat: dashboard with softly glowing amber gauges, steering wheel, windshield showing a dark highway and distant cyan horizon, a phone mounted on the dash with an abstract cyan route glow, no driver visible, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 35mm lens, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, dark left third, no readable text, no logos, no watermark, no faces
```

### HR02 · `hero-amazon-relay` · 4:3
```
Plain white box trailer being hooked up at a distribution center yard at night, tractor lights cutting through light rain, wet concrete reflecting cool cyan and amber light, large building with unlit blank facade, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 35mm lens, photorealistic, ultra-detailed, subtle film grain, no text, no signage, no logos, no license plates, no watermark, no faces
```

### HR03 · `hero-dry-van` · 4:3
```
Three-quarter rear view of a clean plain white dry van trailer pulled by a semi-truck on an empty highway at blue hour, red tail lights glowing, wet road reflections, cool cyan sky, low camera, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 50mm lens, photorealistic, ultra-detailed, subtle film grain, no text, no logos, no license plates, no watermark, no faces
```

### HR04 · `hero-how-it-works` · 4:3
```
Aerial night view of a highway interchange with glowing light trails forming clean curved lines, tiny trucks as points of amber light, subtle cool cyan haze, graphic and calm like a network map, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, drone shot, photorealistic, ultra-detailed, subtle film grain, no text, no logos, no watermark, no faces
```

### HR05 · `hero-back-office` · 4:3
```
Neat dark desk in a quiet office at night: stack of blank paper forms, a laptop showing abstract blurred cyan lines, a stamp and pen, a warm amber desk lamp, rain on a window in the background reflecting distant highway lights, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 35mm lens, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, no readable text, no logos, no watermark, no faces
```

### HR06 · `hero-our-desk` · 4:3
```
Wide shot of a dispatch operations room at night from the back, a row of monitors with abstract glowing cyan route maps, headsets on desks, large window overlooking a distant highway with light trails, two blurred silhouettes seen only from behind far away, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 24mm lens, photorealistic, ultra-detailed, subtle film grain, no readable text, no logos, no watermark, no faces
```

### BL01 · `blog-cost` · 3:2 (cuánto cuesta un servicio de dispatch)
```
Macro of a vintage-style desk calculator with blank display, a few coins and a fuel receipt with no readable text on a dark surface, cool cyan rim light, one amber lamp glow, blurred truck on a highway in the far background, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 85mm macro, photorealistic, ultra-detailed, subtle film grain, no readable text, no logos, no watermark, no faces
```

### BL02 · `blog-relay-requirements` · 3:2
```
Black clipboard with a blank checklist sheet and a pen on the hood of a plain white box truck at dusk, cool cyan twilight, amber dock light in the background, shallow depth of field, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 50mm lens, photorealistic, ultra-detailed, subtle film grain, no readable text, no logos, no watermark, no faces
```

### BL03 · `blog-relay-load-board` · 3:2
```
Tablet mounted in a truck cab at night showing only an abstract grid of soft glowing cyan cards with no readable text, windshield with blurred distribution center lights beyond, amber dashboard glow, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 35mm lens, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, no readable text, no logos, no watermark, no faces
```

### BL04 · `blog-lumper-detention` · 3:2
```
Loading dock at night with an analog wall clock reading no specific time hanging beside an open dock door, a plain white trailer waiting, cool cyan light inside the warehouse and amber dock lamp, sense of waiting, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 35mm lens, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, no text, no signs, no logos, no watermark, no faces
```

### RW01 · `resource-ifta` · 3:2
```
Fuel pump nozzle resting in its holster at a truck stop at night, wet concrete, cool cyan sky glow and warm amber canopy lights, nozzle and hose in sharp focus, blurred semi-truck behind, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 50mm lens, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, no text, no logos, no price numbers, no watermark, no faces
```

### RW02 · `resource-rate-per-mile` · 3:2
```
Small calculator with blank display mounted on a truck dashboard at dusk, paper map folded beside it, windshield showing a blurred road with cool cyan horizon and amber taillights, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 50mm lens, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, no readable text, no logos, no watermark, no faces
```

### RW03 · `resource-bol-template` · 3:2
```
Macro of a blank multi-copy shipping form on a dark clipboard with empty boxes and ruled lines (no readable text), carbon-copy layers visible at the edge, cool cyan rim light, one amber lamp glow, extremely shallow depth of field, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 100mm macro, photorealistic, ultra-detailed, subtle film grain, no readable text, no logos, no watermark, no faces
```

### RW04 · `resource-agreement` · 3:2
```
Two blank bound contract pages and a fountain pen on a dark desk, a small amber lamp casting a warm pool of light, cool cyan reflection on the pen, handshake-free, calm and serious, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 85mm lens, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, no readable text, no logos, no watermark, no faces
```

### RW05 · `resource-glossary` · 3:2
```
Neat fan of blank index cards and a closed notebook on a dark desk beside a truck key fob, cool cyan edge light and a warm amber lamp glow, top-down slight angle, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 50mm lens, shallow depth of field, photorealistic, ultra-detailed, subtle film grain, no readable text, no logos, no watermark, no faces
```

### MM01 · `mega-relay` · 3:2
```
Plain white box trailer docked at a large distribution center on a rainy night, cool cyan glow from the dock doors, amber yard lights, wet concrete reflections, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 35mm lens, photorealistic, ultra-detailed, subtle film grain, no text, no signage, no logos, no license plates, no watermark, no faces
```

### OG01 · `og-default` · 1.91:1 (entregar 2400×1256 para reducir a 1200×630)
```
Wide cinematic shot of a plain white semi-truck on a dark highway at blue hour with a calm cyan horizon, truck in the right third, very dark left half reserved for a title, light mist, cinematic photograph, deep navy shadows with cool cyan highlights, one restrained warm amber accent light, 35mm lens, photorealistic, ultra-detailed, subtle film grain, no text, no logos, no license plates, no watermark, no faces
```

### DK01 · `desk-log-backdrop` · 16:9 (opcional)
```
Abstract dark control-room background: out-of-focus glowing cyan map lines and dots on deep navy, faint amber points like distant trucks, soft vignette, no text, no UI elements, very low contrast so white text can sit on top, cinematic, photorealistic, ultra-detailed, subtle grain, no text, no logos, no watermark, no faces
```

---

## 5. Prompts de video (Veo, Kling, Runway, Sora...)

Reglas para los 3: 16:9 (V02 puede ser 21:9), 1080p o más, 8 a 12 s, SIN audio, cámara lenta y estable, movimiento constante sin cortes para que haga bucle; pide "seamless loop" y, si la herramienta no lo hace, yo hago el fundido de costura. Sin texto, logos ni matrículas; remolques blancos sin marca. Si puedes, genera con el primer fotograma = H01 (image-to-video) para que la foto y el video sean idénticos.

### V01 · `hero-highway-loop`
```
Slow steady forward drone glide low above a multi-lane US interstate at blue hour, white semi-trucks with plain unmarked dry van trailers moving in the right lanes, red tail-light trails and amber marker lights, deep navy sky with a thin cyan glow on the horizon, light mist drifting over dark tree lines, constant speed, no cuts, seamless loop, cinematic, photorealistic, no text, no logos, no license plates, no people
```

### V02 · `freight-yard-loop`
```
Slow lateral dolly along a row of plain white trailers backed into lit dock doors at a distribution yard at night, light rain, wet concrete reflecting cool cyan and amber lights, a yard truck passing far in the background, steam rising softly, constant slow movement, no cuts, seamless loop, cinematic, photorealistic, no text, no signage, no logos, no license plates, no people
```

### V03 · `desk-room-loop` (opcional)
```
Slow push-in on a quiet dispatch operations room at night: wide monitors showing only abstract glowing cyan route lines that subtly pulse and travel, headsets on desks, a large window with distant highway light trails, no people, soft atmospheric haze, constant slow movement, no cuts, seamless loop, cinematic, photorealistic, no readable text, no logos
```

---

## 6. Lo que NO se genera con IA (lo tiene que aportar el cliente)

- **Retratos del equipo y fotos reales de la oficina/desk.** Es la página de confianza (E-E-A-T). Presentar personas o instalaciones inventadas como "nuestro equipo" sería engañar al visitante y va contra la regla de contenido del proyecto. Las imágenes de este documento son ambientales y no muestran rostros ni afirman nada sobre el equipo real; cuando el cliente envíe fotos propias, sustituyen a HR06, WD03 y DK01.
- **El video del equipo** (la fachada con capítulos de Our desk).
- **Capturas reales de la operación** (por ejemplo un registro de check call real) si el cliente las autoriza.

## 7. Entrega

Nombra los archivos exactamente como en la tabla (por ejemplo `hero-highway-poster.jpg`), un archivo por imagen, mínimo 3840 px en el lado largo, sin texto. Si una imagen sale bien pero con un defecto pequeño (una rueda rara, una mancha), mándala igual y yo la limpio o la recorto. Si quieres una segunda opción de alguna, genera 2 o 3 variantes y márcalas `-a`, `-b`, `-c`.


## 8. Entregadas (set Night Freight integrado)

Las 36 piezas están en `assets/img/nf-*` (WebP + JPG; masters fuera del repo). Mapa archivo → slot (ninguna foto se repite dentro de una misma página; ver `CREDITS.md` para los recortes):

| Archivo | Slot |
|---|---|
| `nf-highway-dusk-white-trucks` | Home: hero (LCP), con recortes `-m` y `-sq` |
| `nf-lone-truck-rain-night` | CTA final de todas las páginas (`CTA_IMAGE`), recorte `-m` en móvil |
| `nf-trailer-dock-dusk` · `nf-phone-route-rain-highway` · `nf-logistics-desk-cyan` | Home: carril de servicios (dispatch, tracking, back office) |
| `nf-truck-lights-rain-front` · `nf-white-truck-cyan-horizon` | Home: pastillas del H2 "Three fronts" (`-pill-320`) |
| `nf-phone-chat-dashboard` · `nf-archive-aisle-cyan` · `nf-dispatch-room-maps` | Home: "Why a desk" (3:2 y `-tall` 4:5 en ≥1025 px) |
| `nf-hands-map-keys` · `nf-signing-clipboard-night` · `nf-white-truck-rainy-highway` · `nf-trailer-rows-aerial` | Home: onboarding (3:2 y `-tall`) |
| `nf-dispatch-room-wide-night` | Home: fachada del video "Meet the desk" |
| `nf-trailer-dock-night-mist` | Home: banda de imagen (slot de video) |
| `nf-dock-door-amber-night` | Home: foto del FAQ (4:5 nativo) |
| `nf-cab-interior-cyan-night` | Hero de Owner-operators |
| `nf-hitching-trailer-rain` · `nf-tablet-cab-night-yard` | Amazon Relay: hero y bloque "Why Relay needs a dedicated desk" |
| `nf-white-truck-cyan-horizon` (`-r43`) | Hero de Dry van; además OG por defecto y pastilla del home |
| `nf-route-map-night-trucks` (`-r43`) | Hero de How it works y tarjeta relacionada |
| `nf-desk-rain-window-night` | Hero de Trucking back office |
| `nf-dispatch-room-maps` | Our desk: fachada del video del hero |
| `nf-highway-side-mirror-night` | Load tracking: bloque "Proof, not promises" y tarjeta relacionada |
| `nf-trailer-dock-night-wide` | Mega menú de Services: tarjeta de Amazon Relay |
| `nf-vintage-calculator-night-road` · `nf-clipboard-on-truck-hood` · `nf-tablet-cab-night-yard` · `nf-dock-waiting-clock` | Portadas del blog: costes, requisitos de Relay, load board, lumper y detention |
| `nf-calculator-map-dashboard` · `nf-fuel-nozzle-truck-stop` · `nf-shipping-form-clipboard` · `nf-contracts-amber-cyan` · `nf-cards-fan-key-fob` | Tarjetas de recursos: rate per mile, IFTA, BOL, agreement, glosario |
| `nf-loading-dock-blue-hour` | Tarjeta "All articles" de Resources y foto de Box truck (draft) |
| `nf-lone-truck-blue-twilight` · `nf-white-fleet-blue-hour` · `nf-truck-lights-rain-front` · `nf-white-truck-rainy-highway` | Fotos provisionales de Hotshot, Flatbed, Power only y Reefer (páginas en draft) |
| `nf-route-network-glow-blue` | Sin usar (fondo opcional del Desk log: se omite para no restar contraste ni peso) |
