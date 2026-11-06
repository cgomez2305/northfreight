# NorthStar Freight: dirección de arte v3 «Premium Documentary»

Prompts de imagen y video para reemplazar la fotografía actual por una línea más profesional, más realista y más creíble para una empresa de transporte de EE. UU. Sustituye a `ART-DIRECTION.md` (v1) y a `ART-DIRECTION-v2.md` en lo visual; conserva su lista de slots y nombres de archivo.

---

## 0. Diagnóstico de lo que hay hoy (por qué no se siente «profesional»)

Mirando las imágenes actuales del sitio (hero, dock, flota, cabina), el problema NO es que parezcan falsas: son bastante creíbles. El problema es lo que comunican:

1. **Cielos cubiertos y exposición baja en todas.** Todo es gris azulado, frío y apagado. Una empresa que quiere transmitir confianza y energía parece triste.
2. **Equipo viejo y sucio.** Remolques con óxido y manchas, tractores gastados, asfalto roto. Comunica abandono, no una operación cuidada.
3. **Siempre vacío.** Patios, docks y carreteras sin gente ni acción. No se ve «trabajo en marcha», que es lo que NorthStar vende.
4. **La misma hora y la misma luz.** Todas son crepúsculo nublado. Se nota que son un set generado de una sola sesión.
5. **Detalles de IA.** Símbolos de pantalla genéricos, fondos que se repiten, geometrías raras en ruedas y remolques.

**Objetivo v3:** fotografía comercial documental, del nivel de las marcas logísticas serias de EE. UU. (piensa en el estilo editorial de Flexport, Uber Freight, Samsara o C.H. Robinson, sin copiar nada suyo): equipo moderno y cuidado, luz bonita y variada (dorada, azul limpia, luz suave de interior), acción visible sin rostros identificables, texturas honestas y composición con espacio limpio para el texto. Sin dramatismo gris, sin neón, sin «look de IA».

## 1. Sobre «reales»: honestidad primero

Ninguna imagen generada con IA es una foto real, y para una página de confianza eso importa. La estrategia v3 es de tres niveles:

- **Nivel A, fotos reales (máxima prioridad de confianza):** el equipo, el espacio de trabajo, el escritorio, el proceso. Ver sección 8 (guía de sesión de fotos con celular). Esto va en `Our desk`, el bloque de equipo y el video. NO se sustituye con IA (regla del proyecto).
- **Nivel B, imágenes generadas fotorrealistas sin rostros:** todo lo demás (carreteras, docks, cabinas, escritorios, patios). Este documento.
- **Nivel C (opcional), stock licenciado con autorización de modelos:** si quieren escenas con personas CON rostro (conductor sonriendo, despachador con headset), lo correcto es licenciar fotos reales (Adobe Stock, Getty, Shutterstock) con licencia comercial y release de modelo, y NUNCA presentarlas como «nuestro equipo». Una cara generada por IA delata el sitio al instante y es el peor riesgo de credibilidad.

## 2. Biblia fotográfica v3

**Tono:** «competencia tranquila». Limpio, moderno, luminoso, con sombras con detalle. Nunca sombrío, nunca épico.

**Luz (usa solo estas 4 recetas; varía entre imágenes):**

| Receta | Cuándo | Descripción para el prompt |
|---|---|---|
| R1 Golden hour | Heros, carretera, patios, exteriores | `low sun at 15 to 25 degrees from the side, long warm raking light, soft long shadows, clear blue sky with a few thin high cirrus clouds` |
| R2 Blue hour limpia | Cierres, docks iluminados, cabina | `clear indigo sky with a warm apricot band at the horizon, practical lights switched on, no heavy clouds, soft cool ambient fill` |
| R3 Luz suave de día | Detalles, documentos, interiores | `bright soft overcast daylight through large windows, gentle shadows, clean whites, natural reflections` |
| R4 Interior cálido | Escritorios y oficina | `warm window light plus one practical desk lamp, balanced exposure, honest color` |

**Prohibido en la luz:** `dark moody storm clouds`, `heavy overcast`, `neon`, `cyberpunk`, `volumetric god rays exagerados`, `HDR`, `teal and orange`. El grado de marca navy/cian lo aplicamos nosotros en postproducción; tú entrega color natural y fiel.

**Cámara (una por tipo de imagen; pégala en el prompt):**

- *Cine (heros, cierres, video):* `photographed on ARRI Alexa 35 with a Cooke S7/i prime lens, Kodak Vision3 250D film emulation (500T for night scenes), natural halation on bright highlights, fine organic grain`.
- *Documental (detalles, interiores, escritorios):* `photographed on a Sony A7R V with a 35mm f/1.4 GM lens, natural light, editorial commercial photography, unretouched`.
- *Aérea:* `aerial photograph from a DJI Inspire 3 with a Zenmuse X9 35mm lens, 45 degree oblique angle`.

**Palabras que debes QUITAR (hacen la imagen plástica):** `8k`, `ultra-detailed`, `hyper-realistic`, `masterpiece`, `trending on artstation`, `HDR`, `epic`, `award-winning`, `perfect`. **Palabras que SÍ ayudan:** `unretouched`, `documentary`, `shot on location`, `natural lens imperfections`, `slight vignette`, `true-to-life color`, `honest textures`, `slight asymmetry`.

**Equipo de EE. UU. creíble (incluye estos rasgos en los prompts de camiones):**

- Tractocamión clase 8 moderno, cabina dormitorio, capó largo aerodinámico, deflector de techo y extensores laterales, pintura blanca limpia o grafito, **sin insignias legibles**.
- **18 ruedas:** 2 de dirección, 8 de tracción (eje tándem) y 8 del remolque (eje tándem), cada rueda con su llanta y su tuerca, geometría coherente.
- Remolque dry van de **53 pies**, lados lisos blancos limpios, faldones aerodinámicos laterales, puertas traseras de bisagra, cinta reflectiva roja y blanca a lo largo y en la parte trasera, luces de gálibo ámbar, guardabarros con faldón, patas de aterrizaje.
- Conexión tractor-remolque con las dos mangueras de aire (azul y roja) y el cable eléctrico entre ambos.
- **Tránsito por la derecha, volante a la izquierda** en la cabina. Carreteras interestatales de 4 carriles con arcén ancho, marcas viales amarillas y blancas, barreras de concreto o guardarraíl, señales verdes SIN texto legible.
- Docks: niveladoras, sellos de puerta, defensas negras de caucho, bolardos amarillos de seguridad, luces de dock, palés con film transparente. Montacargas genéricos SIN marca.
- Personas: chaleco de alta visibilidad ANSI clase 2 (solo áreas pequeñas), casco, botas de trabajo, mezclilla o franela. **Nunca uniformes con logos.**

**Personas y rostros:** espaldas, perfil muy lejano, manos, recortes a la altura del mentón, siluetas. Nada de rostros generados. Escenas con gente solo si no se identifica a nadie.

**Pantallas, texto y logos:** NADA legible, NINGÚN símbolo inventado. Orden de preferencia para una pantalla: (1) apagada o con reflejo, (2) inclinada de modo que no se vea, (3) solo bloques suaves desenfocados. Cualquier interfaz que se necesite se compone después en diseño. Señales, matrículas, números de dock y etiquetas: en blanco o desenfocados.

**Composición:** regla de tercios; **espacio limpio para texto** (tercio izquierdo o franja inferior) logrado con cielo, pared o carretera vacía, no con oscuridad; profundidad con primer plano, plano medio y fondo; alturas de cámara variadas (ojo, baja, aérea); un sujeto claro por imagen.

**Salida:** mínimo 3840 px en el lado largo (genera grande o reescala 2x sin sobreafilar), sRGB, JPG calidad 95 o PNG, sin texto ni marco.

## 3. Bloque común y negativo

**Cola común (pégala al final de TODO prompt):**
`unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark`

**Negativo (si la herramienta lo admite):**
`text, letters, numbers, logos, brand names, license plates, watermark, border, frame, cartoon, illustration, 3D render, CGI, plastic skin, airbrushed, over-sharpened, HDR, oversaturated, neon glow, heavy lens flare, fake bokeh blobs, perfect symmetry, duplicated vehicles, extra axles, melted trailer, distorted wheels, deformed hands, six fingers, extra limbs, floating objects, readable signage, rusty dirty trailer, stock photo look`

## 4. Flujo de trabajo

1. Genera **H01 primero** (4 variantes). Elige la mejor y úsala como **referencia de estilo** del resto (`--sref` en Midjourney, imagen de referencia en Flux, Ideogram, Nano Banana o GPT-image).
2. Por cada slot genera **4 variantes**, elige una con el control de calidad (sección 9) y márcala `-a`; guarda una segunda opción como `-b`.
3. Parámetros orientativos: *Midjourney:* `--style raw`, stylize bajo (50 a 150), `--chaos 5 a 10`, `--ar` según el slot, `--sref` con H01. *Flux o similares:* guidance medio (3 a 4), lenguaje natural, sin palabras de «calidad». *Imagen a video (Veo, Kling, Runway, Sora):* usa la foto elegida como primer fotograma para que foto y video sean idénticos.
4. Reescala 2x a 3840 px; si aparece sobreafilado o piel/metal plástico, baja la intensidad.
5. Entrega con el nombre del slot y el sufijo `-v3` (por ejemplo `hero-highway-poster-v3-a.jpg`) para poder compararlas con las actuales antes de reemplazar. Yo aplico la gradación de marca, genero los tamaños y los recortes.

---

## 5. Prompts de imagen: prioridad 1 (home y cierres)

### H01 · `hero-highway-poster` · 16:9
**Uso:** hero del home y primer fotograma del video. **Texto:** tercio izquierdo con cielo y carretera vacíos. **Luz:** R1.
```
Cinematic wide telephoto photograph of a modern white class 8 sleeper-cab semi truck pulling a clean 53-foot white smooth-sided dry van trailer with aerodynamic side skirts, driving away from camera in the right lanes of a four-lane US interstate through open West Texas ranchland at golden hour, low sun from the right throwing long warm raking light across the asphalt and the trailer's side, clear sky graduating from deep blue at the top to a soft apricot glow at the horizon with a few thin high cirrus streaks, dry grass and mesquite on the shoulders, crisp painted lane lines and rumble strips, a second truck small and far ahead for depth, faint heat shimmer, camera low on the shoulder at about 75mm equivalent compressing the road, truck on the right third, left third left as clean open sky and empty road for text, photographed on ARRI Alexa 35 with a Cooke S7/i 75mm lens, Kodak Vision3 250D film emulation, natural halation on sun glints, true-to-life color, subtle film grain, unretouched documentary realism, no text, no logos, no license plates, no watermark, no people visible
```
**Revisa:** 18 ruedas, cintas reflectivas coherentes, camión en carril derecho, sin matrícula legible.

### CTA01 · `cta-night-truck` · 21:9 (y recorte 4:5 móvil)
**Uso:** cierre de todas las páginas. **Texto:** dos tercios izquierdos limpios. **Luz:** R2.
```
Ultra-wide 21:9 cinematic photograph of a single clean white semi truck with a plain white 53-foot dry van trailer cresting a long rural interstate hill at blue hour, headlights and amber marker lights on, clear indigo sky with a warm orange band along the horizon, a small town's soft sodium streetlights far below in the valley, slightly damp asphalt with gentle reflections but no storm, the truck in the right third, the left two thirds quiet sky and empty highway for text, camera low in the grass verge with a slight telephoto compression, photographed on ARRI Alexa 35 with a Cooke S7/i 50mm lens, Kodak Vision3 500T film emulation, natural halation on the headlights, true-to-life color, fine film grain, unretouched documentary realism, no text, no logos, no license plates, no watermark, no people visible
```

### SV01 · `service-dispatch-booking` · 3:2
**Uso:** home, servicio 1 y Relay fila A. **Luz:** R1 (golden hour tarde).
```
Documentary photograph of a clean white 53-foot dry van trailer backed into a dock door of a modern tilt-up distribution center in late afternoon golden light, concrete wall glowing warm, a row of dock doors with two open showing bright white LED light and shrink-wrapped pallets inside, black dock seal and bumpers, yellow safety bollards, a warehouse worker in a hi-vis ANSI vest and hard hat photographed from behind walking along the dock with a clipboard, clear sky with thin cirrus, camera at eye level with a 35mm look, generous clean concrete wall on the left third for text, photographed on a Sony A7R V with a 35mm f/1.4 GM lens, natural light, editorial commercial photography, unretouched documentary photograph, true-to-life color, natural film grain, no text, no door numbers, no logos, no license plates, no watermark, face not visible
```

### SV02 · `service-load-tracking` · 3:2
**Uso:** home, servicio 2. **Luz:** R2 (amanecer).
```
Over-the-shoulder documentary photograph inside the sleeper cab of a modern US semi truck at dawn, left-hand-drive steering wheel at the left, a weathered right hand sliding a smartphone into a dash mount, the phone turned away from camera with a soft glow on the edge and a glare reflection so no screen content is visible, windshield showing a blurred sunrise over a multi-lane highway with a white trailer ahead, clean dashboard, tidy CB radio and a coffee tumbler in the cup holder, warm amber sunrise mixing with cool blue cab shadows, shallow depth of field, clean dark dashboard area on the left third for text, photographed on a Sony A7R V with a 35mm f/1.4 GM lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, face not visible
```

### SV03 · `service-back-office` · 3:2
**Uso:** home, servicio 3 y Relay fila E. **Luz:** R4.
```
Editorial photograph of a tidy operations desk in warm morning window light through venetian blinds: a stack of printed rate confirmation sheets showing only blurred placeholder lines and no legible text, a woman's hand with a simple watch underlining a line with a pen, a ceramic mug, a wireless keyboard, reading glasses, a 27-inch monitor at the edge of frame showing only a blurred grid of pale cells, shallow depth of field, honest paper texture, clean wall space on the left third for text, photographed on a Sony A7R V with a 50mm f/1.2 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no watermark, face not visible
```

### WD01 · `why-one-channel` · 3:2
**Uso:** home, «Why a desk» ítem 1. **Luz:** R1.
```
Documentary photograph of a truck driver's hands typing on a smartphone while leaning against the front fender of a clean white semi tractor at a truck stop at golden hour, the phone angled so the screen is not visible, softly out-of-focus fuel canopy lights and parked trucks behind, warm sun on the driver's forearms and denim jacket sleeve, dark clean fender and tire area on the left third, shallow depth of field, photographed on a Sony A7R V with an 85mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, face not visible
```

### WD02 · `why-record-every-load` · 3:2
**Uso:** home, ítem 2. **Luz:** R1 (mañana).
```
Close documentary photograph of a hand clipping a bundle of delivery paperwork onto a clipboard against the rear swing door of a clean white 53-foot trailer at a warehouse dock in early morning sun, the paper showing only blurred placeholder lines and a blank signature box, a pen tucked under the clip, black dock bumper and yellow bollard softly out of focus, raking warm light revealing honest paper texture, shallow depth of field, photographed on a Sony A7R V with a 50mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no readable text, no logos, no watermark, face not visible
```

### WD03 · `why-desk-team` · 3:2 (**temporal**, se sustituye por foto real)
```
Wide interior photograph of a modern dispatch operations room in the evening, two rows of tidy desks with dual monitors showing only blurred abstract map shapes, headsets resting on desks, a large window with city lights and a distant highway at blue hour, warm practical desk lamps, polished concrete floor, no people, clean wall area on the left third for text, photographed on a Sony A7R V with a 24mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no readable text, no logos, no people, no watermark
```

### ON01 · `onboarding-learn` · 3:2
**Uso:** home paso 1 y How it works. **Luz:** R1 (tarde).
```
Documentary photograph of a diner booth at a US truck stop in warm late afternoon light: two ceramic mugs, an open spiral notebook with hand-drawn lines and no legible writing, a smartphone propped against a napkin holder with its screen angled away, a window behind showing fuel pumps and a row of white trucks softly out of focus, vinyl booth texture, clean tabletop area on the left third for text, photographed on a Sony A7R V with a 35mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no watermark, no people
```

### ON02 · `onboarding-scope` · 3:2
**Uso:** home paso 2. **Luz:** R3.
```
Overhead three-quarter documentary photograph of a worn paper road atlas open on a wooden table with a hand holding a pen and tracing a line between two cities, a tablet beside it showing only a blurred abstract map without labels, a small notebook and a coffee cup, bright soft daylight from a window, honest paper and wood texture, clean space on the left third for text, photographed on a Sony A7R V with a 35mm f/1.8 lens, unretouched documentary photograph, true-to-life color, natural film grain, no readable text, no logos, no watermark, face not visible
```

### ON03 · `onboarding-run` · 3:2
**Uso:** home paso 3. **Luz:** R1 (amanecer).
```
Documentary photograph of a clean white semi truck with a plain white 53-foot dry van trailer pulling out through the secured gate of a distribution center at sunrise, a guard shack with a raised yellow gate arm and no signage, long warm shadows across the asphalt, light mist rising from the ground, clear pale sky, chain-link fence and tall light poles, the truck in the right third and open pavement on the left third for text, photographed on an ARRI Alexa 35 with a Cooke S7/i 40mm lens, Kodak Vision3 250D film emulation, true-to-life color, fine film grain, unretouched documentary realism, no text, no logos, no license plates, no watermark, no faces
```

### ON04 · `onboarding-review` · 3:2
**Uso:** home paso 4. **Luz:** R4.
```
Documentary photograph of a weekly review at a desk in warm morning light: a laptop showing only a soft blurred bar chart with no numbers or labels, a printed settlement statement with blurred placeholder lines, a hand holding a pen over the paper, a coffee mug and a set of truck keys, shallow depth of field, honest textures, clean wall space on the left third for text, photographed on a Sony A7R V with a 50mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no readable text, no logos, no watermark, face not visible
```

### FAQ01 · `faq-dock-light` · 3:2 y 4:5
**Uso:** home, foto del FAQ. **Luz:** R3.
```
Documentary photograph of an empty loading dock bay in bright soft daylight: a clean white trailer partially backed in against a black dock seal, a leveled dock plate, black rubber bumpers, yellow bollards, a painted concrete apron with crisp yellow lines, tidy and well maintained, clean pale wall area for text, composition works at 3:2 and at 4:5 vertical, photographed on a Sony A7R V with a 35mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no door numbers, no logos, no license plates, no watermark, no people
```

### TB01 · `setup-owner-operator` · 4:3
**Uso:** pestaña «Owner-operator» (home y owner). **Luz:** R1 (amanecer).
```
Documentary photograph of a meticulously maintained long-hood sleeper tractor in pearl white with polished aluminum fuel tanks and a single trailer, parked alone at a quiet interstate rest area at sunrise, dew on the grass, golden light on the polished metal, a tiny distant silhouette of the owner stretching beside the cab steps, a few trees and a picnic shelter in soft focus, clear sky, open sky on the left third for text, photographed on a Sony A7R V with a 50mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, face not visible
```

### TB02 · `setup-small-fleet` · 4:3
**Uso:** pestaña «Small fleet». **Luz:** R1.
```
Documentary photograph of four matching white sleeper tractors with matching clean white 53-foot trailers parked in a neat row in a modern secured truck yard at golden hour, new asphalt with crisp painted lines, chain-link fence, tall light poles, a white pickup truck in the foreground, a tiny distant silhouette of a person walking between the trucks, clear blue sky, open space on the left third for text, photographed on a Sony A7R V with a 35mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, face not visible
```

### TB03 · `setup-amazon-relay` · 4:3 (**sin ninguna marca de Amazon**)
**Uso:** pestaña «Amazon Relay carrier» y foto de Relay. **Luz:** R2.
```
Documentary photograph at dusk of a large modern fulfillment center with a long row of lit dock doors and many clean white unmarked 53-foot trailers backed in, a white tractor pulling a trailer out of the yard in the foreground, blank gray signage panels on the building with no text, bright white LED dock lights, clear indigo sky with a warm apricot horizon, tidy asphalt with crisp lines, a few small distant figures in hi-vis vests, open sky on the left third for text, photographed on an ARRI Alexa 35 with a Cooke S7/i 32mm lens, Kodak Vision3 500T film emulation, true-to-life color, fine film grain, unretouched documentary realism, no text, no logos, no brand colors, no license plates, no watermark, no faces
```

---

## 6. Prompts de imagen: prioridad 2 (heros de páginas, paneles y blog)

### CK01 · `check-owner-approve` · 4:3
**Nota:** el icono de aprobado (check) lo compongo yo después sobre la pantalla; no se pide a la IA.
```
Documentary photograph of a truck driver's hands holding a smartphone at a truck stop in the evening, the phone angled so the screen content is not visible and only a soft cool glow reflects on the thumb, softly out-of-focus fuel canopy lights and a white tractor behind, weathered hands and a denim cuff, shallow depth of field, clean dark area on the left third for text, photographed on a Sony A7R V with an 85mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no watermark, face not visible
```

### CK02 · `check-tracking-mirror` · 4:3
```
Close documentary photograph of a large exterior truck mirror reflecting a clean white trailer receding down a highway at sunset with a warm apricot sky, the mirror frame in matte black with a few honest water spots, the cab door edge blurred in the foreground, clean sky area for text, photographed on a Sony A7R V with a 50mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, no people
```

### CK03 · `check-dry-van-interior` · 4:3
```
Interior photograph looking down the inside of an empty 53-foot dry van trailer toward the open rear doors, clean light wood floor planks, steel E-track rails along both walls, bright daylight pouring in from the open doors across the floor, fine dust motes in the light, a loading dock and blue sky visible through the opening, symmetrical vanishing point, clean dark side wall on the left for text, photographed on a Sony A7R V with a 24mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no watermark, no people
```

### HR01 · `hero-owner-operators` · 4:3
```
Documentary photograph from the passenger seat of a clean modern US sleeper cab at sunrise, left-hand-drive steering wheel, dashboard with a tablet mount holding a tablet turned away from camera, sleeper curtain open behind, windshield showing a long empty interstate in warm golden light, tidy cab interior, dark dashboard area on the left third for text, photographed on an ARRI Alexa 35 with a Cooke S7/i 25mm lens, Kodak Vision3 250D film emulation, true-to-life color, fine film grain, unretouched documentary realism, no text, no logos, no license plates, no watermark, no people
```

### HR02 · `hero-amazon-relay` · 4:3
```
Aerial oblique photograph at dusk of a large modern fulfillment center yard with dozens of clean white unmarked trailers neatly parked at lit dock doors, a single white tractor trailer entering the yard on the access road, tidy parking lines, blank building signage, clear indigo sky with an apricot horizon, open sky area on the left third for text, photographed from a DJI Inspire 3 with a Zenmuse X9 35mm lens at a 45 degree oblique angle, natural lens imperfections, true-to-life color, fine grain, unretouched documentary realism, no text, no logos, no brand colors, no license plates, no watermark, no faces
```

### HR03 · `hero-dry-van` · 4:3
```
Documentary photograph from the rear of a 53-foot dry van trailer with both swing doors open at a loading dock in warm late afternoon sun, neatly stacked shrink-wrapped pallets of boxes inside, a manual pallet jack, a warehouse worker in a hi-vis vest photographed from behind at the far end, clean dock plate and yellow bollards, clear sky, open space on the left third for text, photographed on a Sony A7R V with a 24mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, face not visible
```

### HR04 · `hero-how-it-works` · 4:3
```
Aerial photograph of a US interstate cloverleaf interchange at blue hour with five clean white semi trucks fanning out along different ramps and lanes in different directions, taillights and amber marker lights on, clear indigo sky with an apricot horizon, tidy green fields in the infield, crisp lane lines, open sky on the left third for text, photographed from a DJI Inspire 3 with a Zenmuse X9 35mm lens, 60 degree oblique angle, true-to-life color, fine film grain, unretouched documentary realism, no text, no signage text, no logos, no license plates, no watermark, no faces
```

### HR05 · `hero-back-office` · 4:3
```
Editorial photograph of a premium operations desk in warm morning light, a neat folder of signed delivery receipts showing only blurred placeholder lines, a closed laptop, a ceramic mug, a pen and reading glasses, a window with venetian blinds throwing soft stripes of light, a hand resting on the papers, shallow depth of field, clean wall space on the left third for text, photographed on a Sony A7R V with a 35mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no readable text, no logos, no watermark, face not visible
```

### HR06 · `hero-our-desk` · 4:3 (**temporal**, se sustituye por foto real del equipo)
```
Wide interior photograph of a modern dispatch operations room at blue hour, two rows of tidy desks with dual monitors showing only blurred abstract map shapes, headsets resting on desks, a large window with city lights and a distant highway, warm practical desk lamps, polished concrete floor, no people, clean wall area on the left third for text, photographed on a Sony A7R V with a 24mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no readable text, no logos, no people, no watermark
```

---

## 7. Recetas cortas (blog, recursos, menú y social)

Usa la cola común y la cámara documental. Todas 3:2, con espacio limpio para texto.

- **BL01 `blog-cost`:** calculadora básica sin dígitos legibles, recibos de combustible con líneas borrosas, teléfono apagado y una taza sobre una mesa de cocina en luz de ventana R3.
- **BL02 `blog-relay-requirements`:** carpeta de documentos con hojas en blanco, un casco blanco y un chaleco de alta visibilidad sobre una mesa en un dock, luz R3.
- **BL03 `blog-relay-load-board`:** laptop abierta con solo bloques suaves desenfocados (sin texto) sobre el capó de un tractor blanco en un patio, golden hour R1.
- **BL04 `blog-lumper-detention`:** palés con film transparente en un dock, un reloj de pared de dock con las manecillas pero sin números legibles, trabajador de espaldas, luz R1.
- **RW01 a RW05 `resource-*` (IFTA, rate per mile, BOL, agreement, glosario):** primer plano de objetos reales (mapa de carreteras, calculadora, recibos de combustible, carpeta, bolígrafo, taza) con fondo desenfocado y luz R3; sin texto legible.
- **MM01 `mega-relay`:** recorte limpio de TB03.
- **OG01 `og-default`:** recorte de H01 en 1.91:1 con el espacio para el texto a la izquierda; el título lo compongo yo.
- **DK01 `desk-log-backdrop`:** fondo oscuro suave, sin detalle, para que el texto se lea (puede quedar como degradado diseñado; no necesita IA).

---

## 8. Prompts de video (cinematográficos)

Reglas: 16:9 (V02 puede ser 21:9), 1080p o más, 8 a 12 s, SIN audio, movimiento estable y lento, sin cortes, una sola pasada. Genera con **imagen a video usando la foto elegida como primer fotograma**. Cámara fija o con movimiento mínimo y creíble (nada de zooms imposibles). Sin texto, logos ni matrículas.

### V01 · `hero-highway-loop`
Primer fotograma: H01.
```
Slow steady telephoto tracking shot following a clean white class 8 semi truck with a white 53-foot dry van trailer along a four-lane US interstate at golden hour, the truck gradually pulling away from camera, long warm shadows sliding across the asphalt, a second truck far ahead, gentle heat shimmer, grass on the shoulders swaying slightly in the wind, constant speed, no cuts, no camera shake, natural motion blur, cinematic, photorealistic, shot on ARRI Alexa 35, no text, no logos, no license plates, no people
```

### V02 · `freight-yard-loop`
Primer fotograma: SV01 o TB03.
```
Slow lateral dolly along a row of clean white trailers backed into lit dock doors at a modern distribution center at blue hour, a yard truck moving slowly far in the background, workers in hi-vis vests as tiny distant figures, steam from a vent drifting softly, warm interior light spilling from the open dock doors onto the concrete, constant slow movement, no cuts, natural motion blur, cinematic, photorealistic, no text, no signage, no logos, no license plates, no close-up faces
```

### V03 · `cab-dawn-loop` (nuevo)
Primer fotograma: HR01.
```
Locked-off interior shot from the passenger seat of a clean US sleeper cab at sunrise, the steering wheel at left, the highway ahead glowing gold with trucks passing slowly in the distance, a soft sun flare slowly drifting across the windshield, dust motes floating in the light, a tablet in the mount with its screen turned away, constant subtle motion only, no cuts, cinematic, photorealistic, no text, no logos, no license plates, no people
```

---

## 9. Control de calidad: descarta si falla cualquiera

1. **Texto o símbolos:** ni una letra, número, logo ni símbolo inventado en remolques, camiones, pantallas, señales o papeles (amplía al 200 %).
2. **Camión creíble:** 18 ruedas con geometría coherente, conexión de mangueras, remolque sin cortes raros, luces ámbar y cintas reflectivas lógicas, tránsito por la derecha, volante a la izquierda.
3. **Manos y personas:** cinco dedos, proporciones normales, ningún rostro generado.
4. **Aspecto de IA:** piel/metal plástico, simetría perfecta, bokeh en manchas falsas, reflejos que no cuadran, patrones repetidos. Si lo ves, regenera.
5. **Estado del equipo:** limpio, moderno y cuidado (nada de óxido ni suciedad extrema).
6. **Luz:** una de las 4 recetas, sin cielos tormentosos ni neón.
7. **Recortes:** funciona a 3:2, 4:3 y 16:9 y deja espacio limpio para el texto sin depender de la oscuridad.
8. **Nitidez a 100 %** y sin artefactos tras reescalar.

## 10. Sesión de fotos REAL (Nivel A): lo que NO se genera

Con un celular reciente (modo foto normal, SIN filtros ni retoque, HDR apagado) y luz natural. Esto es lo que más sube la confianza.

1. **Retratos del equipo** (Jonathan, Elias, Mileva y quien confirme): vertical 4:5, hombros hacia arriba, mirando a cámara, **mismo fondo liso claro o azul oscuro para todos**, junto a una ventana (luz suave lateral, nunca el sol directo), ropa lisa sin logos, 5 fotos por persona y se elige la mejor. Enviar el original sin editar.
2. **El espacio de trabajo real:** el escritorio y las pantallas, de día, ordenado. **Pantallas con datos de ejemplo o desenfocadas: nunca nombres de clientes, MC#, placas ni teléfonos reales.**
3. **El trabajo en acción:** 3 tomas por encima del hombro de una persona usando WhatsApp o el sistema (pantalla desenfocada), una persona con headset en una llamada, dos personas revisando un documento.
4. **Detalles:** manos escribiendo, taza y cuaderno, un reloj con la hora de EE. UU. visible si es posible, el mapa de rutas en pantalla desenfocado.
5. **Plano general** horizontal del espacio de trabajo con el equipo trabajando (si todos consienten salir).
6. **Video de 3 a 4 minutos** (guion de tres temas: cómo coordinamos cargas y conductores, cómo documentamos incidencias con evidencia, cómo trabajamos como equipo). Vertical y horizontal, buen audio (un micrófono de solapa de bajo costo marca la diferencia), luz de ventana.
7. **Autorización:** cada persona que aparezca firma por escrito el permiso de uso de imagen para la web.

**Cada foto real sustituye a su espacio provisional** (HR06, WD03, DK01) y alimenta la sección de equipo y Our desk.

## 11. Entrega y reemplazo

- Un archivo por imagen, nombre del slot + `-v3` + `-a`/`-b` (por ejemplo `service-dispatch-booking-v3-a.jpg`), mínimo 3840 px de lado largo, sin texto ni marco.
- Primero H01, CTA01 y SV01 a SV03: con esas cinco ya cambia la percepción de todo el sitio. Después ON, TB, WD y los heros.
- Yo aplico la gradación de marca (se mantiene el navy del sitio), genero los WebP en todos los anchos, los recortes `-m`, `-sq` y `-tall`, actualizo `CREDITS.md`, comparo antes y después en capturas y publico por lotes.
- Los videos nuevos solo se activan si el primer fotograma coincide con la foto del slot.
