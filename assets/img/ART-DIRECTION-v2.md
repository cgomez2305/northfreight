# NorthStar Freight: dirección de arte v2 (prompts avanzados)

**Qué es:** la segunda generación de la línea gráfica "Night Freight". Sustituye a los prompts de `ART-DIRECTION.md` (que siguen siendo la referencia de nombres y espacios). Objetivo: fotos que parezcan **fotografía documental real**, no "imagen de IA". Cada prompt va completo y listo para pegar.

---

## 1. Diagnóstico: por qué las imágenes actuales se ven "IA"

Revisando las 36 imágenes entregadas, estos son los síntomas que delatan a un generador:

| Síntoma | Por qué pasa | Cómo se corrige en v2 |
|---|---|---|
| **Todo es neón cian + naranja** (el cliché "teal and orange") | El bloque de estilo pedía "cool cyan highlights" en todo | El cian pasa a **acento pequeño** (cielo, una pantalla, un LED); el color dominante es azul acero y grafito, desaturado |
| **Superficies demasiado limpias y simétricas** (docks, pasillos, filas de camiones perfectas) | Los modelos "ordenan" todo | Se pide **desgaste, irregularidad y suciedad** explícitas (manchas de aceite, rayones, polvo, remolques ligeramente desalineados) |
| **Plástico y brillo** (asfalto mojado de espejo, pieles lisas, metales perfectos) | Palabras como `ultra-detailed`, `photorealistic`, `8k`, `masterpiece` empujan al look "render" | Se **eliminan** esas palabras y se describe la cámara y la película real |
| **Mismo encuadre y la misma hora azul 36 veces** | El bloque de estilo era idéntico | Variedad dentro de la paleta: larga exposición, paneo, teleobjetivo, interior frío, pre-amanecer |
| **Luz imposible** (varias fuentes sin lógica, sombras que no cuadran) | Se pedía luz estética, no luz física | Cada prompt define **de dónde sale cada luz** (LED frío, sodio ámbar, cielo) |
| **Todo nítido y con bokeh perfecto** | Falta de imperfección óptica | Grano de película, halación, viñeteo, aberración cromática leve, desenfoque parcial, desenfoque de movimiento real |
| **Geometría de camión inventada** (ruedas de más, remolques raros) | No se le dice cómo es un camión | Se incluye una **ficha de anatomía** (sección 4) y se prefiere camión pequeño y lejano |
| **Pantallas y papeles con falso texto** | Los generadores dibujan letras | Se pide pantalla como **forma abstracta** y papel "solo líneas" |

---

## 2. Dirección v2: "Freight documentary, shot on film"

**Idea:** fotografía de campo, como si un fotógrafo de reportaje hubiera recorrido autopistas, patios y oficinas con película de 35 mm. Misma marca (navy y cian), pero con **naturalidad**: luz real, desgaste real, escala humana.

- **Película y cámara:** noche y crepúsculo con **Cinestill 800T** (grano visible, halación rojiza en las luces: es la firma de la fotografía nocturna real); interiores y días nublados con **Kodak Portra 400**. Lentes 28 mm a 85 mm para escenas cercanas; 135 mm a 300 mm para carretera con compresión; larga exposición con trípode para luces de tráfico.
- **Paleta:** azul acero y grafito dominantes, sombras en **navy nunca negro puro**, blanco apagado en remolques, **un solo toque cálido** (ámbar sodio) y el **cian solo como acento** (horizonte, un LED, una línea de ruta en una pantalla). Saturación baja.
- **Variedad:** hora azul, noche cerrada, pre-amanecer, lluvia, interior con luz fría de fluorescente, larga exposición. Siempre la misma gradación, no la misma foto.
- **Escala humana sin rostros:** manos, siluetas muy lejanas, espaldas, objetos de uso (llaveros, tazas, cinta). Nada que parezca al equipo real.
- **Mucho aire oscuro** a la izquierda o abajo para el texto de la web.
- **Referencias de ambiente (no pegar nombres en los prompts):** fotografía nocturna de Todd Hido y Stephen Shore, el look de exteriores nocturnos de *Nomadland* y *Sicario*, cartelería de logística de Cinestill.

---

## 3. Reglas de oro para que no parezca IA

1. **Quita** del prompt: `ultra-detailed`, `photorealistic`, `hyperrealistic`, `8k`, `masterpiece`, `cinematic masterpiece`, `HDR`, `neon`, `glowing`, `perfect`. Producen el look de render.
2. **Describe la cámara y la exposición** (lente, apertura, obturador, ISO, trípode o a mano). Los modelos asocian esos datos con fotos reales.
3. **Pide imperfecciones concretas:** manchas de aceite, juntas de asfalto parcheadas, rayones, polvo, óxido, cinta reflectante desgastada.
4. **Una sola fuente de luz clave y una de relleno**, nombradas y con su color.
5. **Pocos sujetos y lejos**: un camión pequeño en el encuadre es más creíble que cuatro enormes.
6. **Prefiere el movimiento real**: paneo (camión nítido, fondo en estela), larga exposición (estelas de luz), lluvia con desenfoque.
7. **Cero texto**: pantallas como formas, papeles como líneas, relojes sin números, puertas sin numeración.
8. **Genera grande:** esta vez entrega **≥ 3840 px de ancho** (16:9 y 21:9) y **≥ 2400 px de ancho** en 3:2 y 4:3. Las imágenes actuales miden 1122 a 1914 px y limitan la nitidez en pantallas retina (ver regla de nitidez del proyecto).

---

## 4. Ficha de anatomía del camión (pégala en tu herramienta o úsala para el control de calidad)

- **Tractor** clase 8 de capó largo, blanco liso, parachoques gris oscuro, escapes verticales, espejos grandes. **10 ruedas** (2 directrices + 8 motrices en dos ejes tándem).
- **Remolque dry van de 53 pies**, blanco, paneles laterales lisos con línea de remaches, **eje tándem trasero con 8 ruedas** (2 ejes x 4), faldones laterales, **4 guardabarros** traseros, parachoques ICC, puertas traseras con **dos barras verticales de cierre**, patas de aterrizaje delante.
- **Cinta reflectante** (rojo y blanco) en el riel inferior y atrás. Luces de gálibo ámbar arriba y laterales, rojas atrás.
- **Entre tractor y remolque:** quinta rueda, mangueras de aire (rojo y azul) en espiral.
- **Rechaza** cualquier imagen con ejes de más, ruedas fusionadas, remolque más corto que el tractor, puertas traseras sin barras, luces en sitios imposibles o cabina que no encaja con el chasis.

---

## 5. Bloques que ya van dentro de cada prompt

**Bloque noche y crepúsculo (N):**
```
Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```

**Bloque día nublado e interior (D):**
```
Shot on Kodak Portra 400 film, slightly underexposed, fine organic grain, gentle halation on highlights, natural lens vignetting, unretouched candid documentary photograph, overcast cold light or practical interior light only, cool steel-blue shadows, muted desaturated palette of steel blue and graphite with cyan only as a small accent and a single warm amber practical light, real-world imperfections and wear, no text, no logos, no license plates, no watermark, no faces
```

**Negative prompt** (solo si tu herramienta lo admite; en Flux, Imagen y GPT-image no existe: ya está dicho en positivo en cada prompt):
```
text, letters, numbers, logos, brand names, license plates, watermark, border, frame, faces, cartoon, illustration, 3d render, CGI, plastic, oversaturated, neon, HDR, over-sharpened, symmetrical perfect, extra wheels, merged wheels, deformed hands, extra fingers, distorted trailer, garbled text
```

### Parámetros por herramienta (orientativos: ajusta a la versión que uses)
- **Midjourney v7:** `--ar 16:9` (o el que toque) `--style raw --stylize 100 --chaos 8 --q 1` y usa `--sref` con tu mejor foto (H01 aprobada) para el resto.
- **Flux 1.1 Pro / Flux Kontext:** prompt tal cual; `raw mode` activado; guidance 3 a 3,5; sin negative.
- **Imagen 4 / Nano Banana (Gemini):** pega el prompt completo; para corregir una rueda o una mano, **edita por chat** ("fix only the rear wheels, keep everything else").
- **GPT-image:** prompt completo; pide "do not stylize, keep photographic imperfections".
- **Ideogram 3.0:** estilo "Realistic"; desactiva "Magic prompt".

### Postproducción (30 minutos por imagen, marca toda la diferencia)
1. **Upscale** a ≥ 3840 px con Magnific (creativity bajo, resemblance alto) o Topaz Gigapixel.
2. **Corregir defectos** con relleno generativo / inpainting (ruedas, manos, letras en pantallas).
3. **Camera Raw / Lightroom:** bajar saturación 10 a 15 %, bajar el cian 10 %, curva de tonos con negros levantados (navy, no negro), grano 25 a 35 (tamaño 25), halación ligera, viñeteo suave, nitidez moderada (no la máxima).
4. **Exportar:** sRGB, JPG calidad 95 sin texto ni marco, con el nombre indicado.

### Control de calidad (rechaza si falla alguno)
Ruedas y ejes creíbles (ficha de anatomía) · sin letras en ninguna parte · luz físicamente coherente · sin simetría de render · manos con 5 dedos · desenfoque y grano naturales · se ve bien recortada · zona oscura para texto · nitidez a 100 %.

---

## 6. Orden de regeneración (mayor impacto primero)

1. **Ola 1 (lo que ve cualquiera al entrar):** H01, CTA01, SV01 a SV03, FAQ01, OG01.
2. **Ola 2 (páginas de servicio):** HR01 a HR06, ON01 a ON04, WD01 a WD03.
3. **Ola 3 (resto):** TB01 a TB03, CK01 a CK03, BL01 a BL04, RW01 a RW05, MM01, DK01.
4. **Videos:** V01 con el H01 nuevo; V02 con SV01; V03 opcional.

La columna "Sustituye a" indica qué archivo `nf-*` reemplaza cada imagen; si una imagen v2 no es mejor que la actual, se queda la actual.

---

## 7. Prompts de imagen


### P1 · imprescindibles

#### H01 · `hero-highway-poster` · 16:9 (genera en 2:1 y recorta)
*Uso:* Home: hero (foto y primer fotograma del video V01) · *Sustituye a:* `nf-highway-dusk-white-trucks`
```
Long-exposure photograph taken from a pedestrian overpass looking down the length of a multi-lane US interstate at blue hour, 135mm lens, 1.5 second exposure on a tripod, f/5.6, ISO 400. Several tractor-trailers in the right lanes, rendered with slight motion blur, with long continuous red tail-light trails and amber marker lights, while the guardrail, overhead sign structure without any lettering and the tree line stay sharp. Thin cyan glow at the horizon fading into a deep navy sky, low mist sitting in the valley behind the trees, wet patched asphalt with lane reflectors, slight haze from truck spray. Road leads from the lower right toward the upper left, the left third of the frame stays calm and dark for headline text. Trucks are small and far enough that they read as real vehicles, not toys. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Zona oscura y limpia en el tercio izquierdo. Camiones pequeños y lejanos: así la geometría de ruedas no puede fallar. Entregar ≥ 3840 px de ancho.

#### CTA01 · `cta-night-truck` · 21:9 (y recorte 16:9)
*Uso:* Cierre de TODAS las páginas · *Sustituye a:* `nf-lone-truck-rain-night`
```
Low camera at the edge of a wet two-lane highway shoulder at night, 85mm lens, f/2, 1/60s, ISO 3200. a Class 8 long-hood conventional tractor in plain white with a dark grey bumper, pulling a 53-foot white dry van trailer with tandem rear axles, smooth side panels with visible rivet lines and faint road grime on the lower third, red-and-white reflective conspicuity tape along the lower rail, mud flaps and side skirts approaching at a three-quarter angle on the right third of the frame, headlights blooming softly, rain streaks lit from behind, a thin veil of tire spray, patched tar seams and cat-eye reflectors in the asphalt, a single distant sodium highway lamp far behind. The left two thirds of the frame is almost black asphalt and rain, ready for text, with only a faint cyan sky glow along the top edge. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Texto va a la izquierda: dos tercios oscuros. Camión en el tercio derecho, no centrado. Ruedas: tractor 10 (2 directrices + 8 motrices), remolque 8.

#### SV01 · `service-dispatch-booking` · 3:2
*Uso:* Home: tarjeta de servicio 1 (Dispatch & load booking) · *Sustituye a:* `nf-trailer-dock-dusk`
```
Documentary exterior of a distribution-center loading dock at dusk, 35mm lens, f/4, 1/30s on a tripod, ISO 1600. A row of six numberless dock doors, a white dry van trailer backed into the third door with the rubber dock seal compressed against it, yellow safety bollards, wheel chocks under the trailer tires, painted floor lines worn by tire marks, an oil stain on the concrete. Cold white LED wall-pack lights on the building, warm amber light spilling from inside the open door, deep blue sky above. The yard is empty; one tiny far-away figure in a hi-vis vest at the end of the dock for scale. Dark negative space on the left. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Sin números en las puertas ni texto en la pared. Sombras del dock coherentes con las luces (la luz ámbar sale de la puerta abierta).

#### SV02 · `service-load-tracking` · 3:2
*Uso:* Home: tarjeta de servicio 2 (Load tracking & check calls) · *Sustituye a:* `nf-phone-route-rain-highway`
```
Over-the-shoulder view from the passenger seat of a truck cab at dusk, 50mm lens, f/1.8, 1/60s, ISO 3200: a single adult hand with natural proportions and five relaxed fingers holding a smartphone, the screen mostly dark with one thin cyan route polyline on a blank dark map and no labels or letters, reflecting faintly on the windshield. Beyond the glass, an interstate with truck marker lights dissolving into soft bokeh, rain beads on the windshield, the dashboard edge and a coffee cup out of focus. Dark negative space on the left. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Revisar la mano (5 dedos, muñeca normal) y que la pantalla no tenga letras. Si falla, regenerar solo la mano (inpainting).

#### SV03 · `service-back-office` · 3:2
*Uso:* Home: tarjeta de servicio 3 (Trucking back office) · *Sustituye a:* `nf-logistics-desk-cyan`
```
Documentary still life of a dispatcher's desk in a dark office at night, 50mm lens, f/2.8, 1/50s, ISO 1600. A slightly messy stack of blank carbon-copy shipping forms with faint ruled lines, binder clips, a yellow highlighter, a pen left across the forms, a desktop calculator, a closed rugged laptop, a mug with no print. One warm amber desk lamp from the right, a cold cyan-white monitor glow from the left edge, a rain-streaked window behind with out-of-focus highway lights. Slight dust on the desk, paper edges not perfectly aligned. Shot on Kodak Portra 400 film, slightly underexposed, fine organic grain, gentle halation on highlights, natural lens vignetting, unretouched candid documentary photograph, overcast cold light or practical interior light only, cool steel-blue shadows, muted desaturated palette of steel blue and graphite with cyan only as a small accent and a single warm amber practical light, real-world imperfections and wear, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Ninguna hoja con texto legible (solo líneas de formulario). Que parezca un escritorio usado, no un render.

#### WD01 · `why-one-channel` · 3:2
*Uso:* Home: 'Why a desk' ítem 1 (One channel for every update) · *Sustituye a:* `nf-phone-chat-dashboard`
```
A smartphone seated in a dash cradle of a parked truck at night, 35mm lens, f/2, 1/60s, ISO 3200. The screen shows only abstract soft cyan chat bubbles as plain rounded shapes without letters, throwing a faint blue light on cracked vinyl dashboard texture. The steering wheel rim and an instrument cluster with amber needle lighting are out of focus; through the windshield, a dark truck-stop lot with distant marker lights. No hands in frame. Dark negative space on the left. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Burbujas de chat como formas, sin letras. Textura de vinilo del tablero con desgaste.

#### WD02 · `why-record-every-load` · 3:2
*Uso:* Home: 'Why a desk' ítem 2 (A record of every load) · *Sustituye a:* `nf-archive-aisle-cyan`
```
A paper records room in a logistics office, one-point perspective down an aisle of grey steel shelving, 28mm lens, f/5.6, 1/15s on a tripod, ISO 800. Banker's boxes and accordion folders in mixed worn kraft and manila tones, a few not perfectly aligned, a rolling library ladder, a coil of cable on the floor. Overhead fluorescent tubes with one tube dead, the rest giving a cold green-white light graded toward steel blue, a faint cyan glow from a monitor at the end of the aisle and one amber exit lamp. Dust in the air, scuffed linoleum. No labels or lettering on the boxes. Shot on Kodak Portra 400 film, slightly underexposed, fine organic grain, gentle halation on highlights, natural lens vignetting, unretouched candid documentary photograph, overcast cold light or practical interior light only, cool steel-blue shadows, muted desaturated palette of steel blue and graphite with cyan only as a small accent and a single warm amber practical light, real-world imperfections and wear, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Evitar simetría perfecta de render: cajas desiguales, un tubo apagado, polvo. Sin etiquetas legibles.

#### WD03 · `why-desk-team` · 3:2
*Uso:* Home: 'Why a desk' ítem 3 (A team behind every load) · *Sustituye a:* `nf-dispatch-room-maps`
```
A quiet dispatch operations room at night, wide interior shot from the back, 24mm lens, f/2.8, 1/30s, ISO 3200. Eight workstations with dual monitors showing only dark maps with thin cyan route lines and no readable interface text, headsets hanging from monitor stands, chairs slightly askew, a jacket over one chair, a half-finished coffee cup, a taped paper map on a side wall. Lit only by the screens and one warm amber desk lamp in the distance, a rainy window showing blurred highway lights. No people in the frame. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Sin personas (es una sala vacía: no insinuar al equipo real). Monitores sin texto.

#### ON01 · `onboarding-learn` · 3:2 (y recorte 4:5 desde el master)
*Uso:* Home: onboarding paso 1 (We learn your operation) · *Sustituye a:* `nf-hands-map-keys`
```
Overhead three-quarter view of two pairs of adult hands over a printed paper road map on a worn steel truck-stop table, 50mm lens, f/2.8, 1/60s, ISO 1600: one hand pointing with a pen along a highlighted route, the other holding a truck key fob, natural skin texture, correct finger count, a wristwatch on one wrist, a paper cup. Warm amber lamp from the upper right, cold window light from the left, map with abstract road lines and no readable place names. Faces out of frame. Shot on Kodak Portra 400 film, slightly underexposed, fine organic grain, gentle halation on highlights, natural lens vignetting, unretouched candid documentary photograph, overcast cold light or practical interior light only, cool steel-blue shadows, muted desaturated palette of steel blue and graphite with cyan only as a small accent and a single warm amber practical light, real-world imperfections and wear, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Manos con anatomía normal. Mapa sin nombres legibles (solo líneas).

#### ON02 · `onboarding-scope` · 3:2 (y recorte 4:5)
*Uso:* Home: onboarding paso 2 (We define scope & contacts) · *Sustituye a:* `nf-signing-clipboard-night`
```
Close view of a hand signing a form on a clipboard resting on the hood of a white truck at night, 50mm lens, f/2, 1/80s, ISO 3200. The signature is an unreadable scribble, the form has only faint printed lines and no words, a ballpoint pen, knuckles with light scuffs, a work-jacket sleeve. A tractor headlamp provides the warm key light from the left, windshield and hood show fine rain beads and dust, background yard lights as soft bokeh with a cold cyan cast. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Firma ilegible, formulario sin palabras. Revisar dedos.

#### ON03 · `onboarding-run` · 3:2 (y recorte 4:5)
*Uso:* Home: onboarding paso 3 (We run the day-to-day) · *Sustituye a:* `nf-white-truck-rainy-highway`
```
Panning shot of a Class 8 long-hood conventional tractor in plain white with a dark grey bumper, pulling a 53-foot white dry van trailer with tandem rear axles, smooth side panels with visible rivet lines and faint road grime on the lower third, red-and-white reflective conspicuity tape along the lower rail, mud flaps and side skirts moving left to right on a wet interstate at dusk, 200mm lens, shutter 1/30s following the truck so the tractor is sharp and the road, rain and background are streaked with horizontal motion blur, f/4, ISO 800. Headlights on, tire spray from the trailer wheels, overcast sky with a thin cyan band near the horizon, grey-blue tones, wet road reflecting the headlights. Dark, calm negative space above the truck. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* El paneo (camión nítido, fondo en movimiento) es lo que más lo aleja del look IA. Contar ruedas.

#### ON04 · `onboarding-review` · 3:2 (y recorte 4:5)
*Uso:* Home: onboarding paso 4 (We review & improve) · *Sustituye a:* `nf-trailer-rows-aerial`
```
High oblique aerial photograph from a helicopter at 70 meters over a truck trailer yard at night, 50mm lens, f/2.8, 1/100s, ISO 6400. About forty plain white trailers parked in slightly irregular rows with small differences in spacing and angle, sodium yard lights casting amber pools on wet gravel, a service road with one tractor moving, cold blue ambient light from the sky, distant highway lights at the top edge. Soft haze, no cartoonish repetition. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Anti-patrón: filas idénticas repetidas. Pedir irregularidad y sombras de cada luz.

#### FAQ01 · `faq-dock-light` · 4:5 (vertical, 2400×3000 mínimo)
*Uso:* Home: foto del FAQ · *Sustituye a:* `nf-dock-door-amber-night`
```
Vertical composition of a single open loading dock door at night, 35mm lens, f/2.8, 1/40s, ISO 3200. Warm amber light pours from the empty bay into thin fog, the black rubber dock seal and yellow steel bollard framing it, wet concrete apron with a puddle reflecting the light, a faint cyan LED wall-pack above. Concrete wall with water streaks and peeling paint. Calm, quiet, nobody in the frame. Darkness on the left and bottom. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Es la única imagen vertical nativa: pídela en 4:5 desde el principio.

#### TB01 · `setup-owner-operator` · 4:3 (y 16:9)
*Uso:* Pestaña 'Owner-operator' (home y owner) · *Sustituye a:* `nf-lone-truck-blue-twilight`
```
A single plain white tractor with a sleeper cab parked alone at the edge of an empty rest area at blue hour, 50mm lens, f/2.8, 1/50s, ISO 1600, slight three-quarter front view. The cab dome light glows warm amber, parking-lot marker lights on, a thin cyan twilight sky, wet asphalt with faint reflections, a trailer-less tractor with fifth wheel visible, a wooden pallet and a single vending-machine glow far in the background. Quiet, lonely, independent. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Tractor sin remolque (una sola unidad). Distinta de CTA01 (aquí parado, cabina iluminada).

#### TB02 · `setup-small-fleet` · 4:3 (y 16:9)
*Uso:* Pestaña 'Small fleet' · *Sustituye a:* `nf-white-fleet-blue-hour`
```
Four plain white tractors, each with a plain white dry van trailer, parked in a slightly staggered row in a depot lot at blue hour, 35mm lens, f/4, 1/30s on a tripod, ISO 1600. Each vehicle has small differences: one cab light on, one with a mirror folded, different trailer positions. Cold blue sky with a cyan band at the horizon, sodium lamp pools on wet asphalt, chain-link fence in soft focus at the right. Human scale from a single tiny hi-vis figure far away. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Pedir irregularidad entre unidades (no clones). Máx. 4 camiones: más aumenta los errores.

#### TB03 · `setup-amazon-relay` · 4:3 (y 16:9)
*Uso:* Pestaña 'Amazon Relay carrier' · *Sustituye a:* `nf-trailer-dock-night-wide`
```
A large fulfillment-center trailer staging yard at night with rows of plain white 53-foot trailers backed against numberless dock doors, 28mm lens, f/4, 1/30s, ISO 3200. Cold white LED floodlights, a few doors glowing amber, wet concrete with painted stripes, security fence and a guard-booth glow far away. Absolutely no brand marks, arrows, smiles or lettering anywhere on the building or trailers. Cyan only in the sky edge. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Cero marca de Amazon (ni flecha, ni sonrisa, ni color naranja de marca). Ambiente de centro logístico genérico.


### P2 · siguiente oleada

#### CK01 · `check-owner-approve` · 4:3
*Uso:* Owner: panel 'You stay in control' · *Sustituye a:* `nf-cab-interior-cyan-night`
```
Interior of a truck cab seen from the passenger seat at night, 28mm lens, f/2, 1/40s, ISO 3200. A phone in a dash cradle glows with an abstract cyan card with no letters, the steering wheel with worn leather wrap, a paper logbook and a pen on the dash, an amber dome-light glow, a windshield with fine rain and a distant yard. Faintly dusty dashboard, small scuffs on plastics. No driver in frame. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Sin conductor visible. La pantalla es una forma, sin texto.

#### CK02 · `check-tracking-mirror` · 4:3
*Uso:* Load tracking: bloque 'Proof, not promises' · *Sustituye a:* `nf-highway-side-mirror-night`
```
Close view of a truck's large side mirror at night, 85mm lens, f/2, 1/80s, ISO 3200. Rain droplets on the mirror glass, the reflection showing the long white trailer side with red and amber marker lights and the blurred interstate behind it, a chrome mirror arm with water beads, a hint of the door frame. Cold blue ambient light, amber marker glow, no faces visible in the reflection. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Reflejo coherente con la escena (marcar el remolque y las luces). Gotas físicamente creíbles.

#### CK03 · `check-dry-van-interior` · 4:3
*Uso:* Dry van: bloque de página
```
Interior of an empty 53-foot dry van looking toward the open rear doors at dusk, 24mm lens, f/5.6, 1/15s on a tripod, ISO 800. Plywood-lined side walls with horizontal E-track cargo rails, a scuffed hardwood floor with forklift tire marks, a single shrink-wrapped pallet near the doors, cold blue twilight light falling through the doors with a warm amber glow from a dock light outside, dust floating in the beam, receding perspective toward the light. Shot on Kodak Portra 400 film, slightly underexposed, fine organic grain, gentle halation on highlights, natural lens vignetting, unretouched candid documentary photograph, overcast cold light or practical interior light only, cool steel-blue shadows, muted desaturated palette of steel blue and graphite with cyan only as a small accent and a single warm amber practical light, real-world imperfections and wear, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Detalles reales: E-track, suelo rayado, madera. Sin texto en paredes.

#### HR01 · `hero-owner-operators` · 4:3 (y 16:9)
*Uso:* Hero de /dispatch-for-owner-operators/ · *Sustituye a:* `nf-cab-interior-cyan-night`
```
A lone plain white tractor with a plain white dry van trailer cresting a long hill on a two-lane highway at pre-dawn, 300mm telephoto compression, f/5.6, 1/125s, ISO 1600. Fog filling the valleys, deep blue sky with the first thin cyan light on the horizon, truck headlights and marker lights glowing, a ribbon of wet road, utility poles lining the road, soft layered ridgelines. The truck is small in the frame on the right, large calm negative space on the left. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Camión pequeño y lejano: calma, independencia, sin errores de ruedas.

#### HR02 · `hero-amazon-relay` · 4:3 (y 16:9)
*Uso:* Hero de /amazon-relay-dispatch/ · *Sustituye a:* `nf-hitching-trailer-rain`
```
A tractor backing under a white dry van trailer in a yard at night, 35mm lens, f/2.8, 1/60s, ISO 3200: the fifth-wheel coupling just before connection, coiled air lines with red and blue glad hands hanging from the trailer nose, rain falling through a yard floodlight, wet concrete with reflections, an amber work light on the tractor frame, the trailer landing gear legs down. Cold blue ambient light, no brand marks, no readable numbers. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Detalle de enganche creíble (patas de aterrizaje, mangueras rojas y azules).

#### HR03 · `hero-dry-van` · 4:3 (y 16:9)
*Uso:* Hero de /dry-van-dispatch/ · *Sustituye a:* `nf-white-truck-cyan-horizon`
```
Close detail of the rear of a 53-foot dry van trailer at a dock at dusk, 50mm lens, f/4, 1/50s, ISO 1600: two rear doors with vertical locking bars and hinges, a bolt seal hanging on the latch with no readable digits, red-and-white reflective conspicuity tape, a tail-light cluster glowing red, ICC bumper, rubber mud flaps, wet concrete reflecting the lights, scuffs, grime and a small dent in the door skin. Cold blue ambient with one amber dock light. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* El precinto (bolt seal) sin números legibles. Detalle de desgaste.

#### HR04 · `hero-how-it-works` · 4:3 (y 16:9)
*Uso:* Hero de /how-it-works/ · *Sustituye a:* `nf-route-map-night-trucks`
```
A highway interchange photographed from a hilltop at night, 70mm lens, 8 second exposure on a tripod, f/8, ISO 200: white and red light trails of traffic curving along stacked ramps and merging into a network, a few static overpass lamps sharp, deep navy sky, cool cyan haze on the horizon, dark fields at the foreground. The light trails read like a route map without any text or markings. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Larga exposición real, no neón. Sin textos de carteles.

#### HR05 · `hero-back-office` · 4:3 (y 16:9)
*Uso:* Hero de /trucking-back-office/ · *Sustituye a:* `nf-desk-rain-window-night`
```
A dispatcher's desk at night, 50mm lens, f/2.2, 1/50s, ISO 1600: printed settlement statements and fuel receipts spread across a worn desk with blank lines only, a calculator, a binder clip, a highlighter, an open laptop showing a dark screen with a thin cyan line chart without labels, a warm amber lamp from the right, a rain-streaked window behind with blurred highway lights. Natural desk wear, a coffee ring on one page. Shot on Kodak Portra 400 film, slightly underexposed, fine organic grain, gentle halation on highlights, natural lens vignetting, unretouched candid documentary photograph, overcast cold light or practical interior light only, cool steel-blue shadows, muted desaturated palette of steel blue and graphite with cyan only as a small accent and a single warm amber practical light, real-world imperfections and wear, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Documentos sin texto legible. Aire oscuro a la izquierda.

#### HR06 · `hero-our-desk` · 4:3 (y 16:9)
*Uso:* Hero de /our-desk/ y fachada del video · *Sustituye a:* `nf-dispatch-room-maps`
```
Wide interior of a dispatch operations room at night, 24mm lens, f/2.8, 1/30s, ISO 3200: a long wall of screens showing dark maps with thin cyan route lines and no readable text, desks with headsets and notebooks, chairs askew, a rain-streaked floor-to-ceiling window with blurred highway lights, cold overhead LED strips and a single warm amber desk lamp. No people in frame. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Sin personas: la sala vacía evita insinuar al equipo real.

#### BL01 · `blog-cost` · 3:2
*Uso:* Blog: how much does truck dispatch cost · *Sustituye a:* `nf-vintage-calculator-night-road`
```
A vintage desktop calculator and a crumpled fuel receipt on a truck dashboard at night, 50mm lens, f/2, 1/60s, ISO 3200, the calculator display blank with no digits, a highway at night in soft bokeh behind the windshield with amber marker lights, a pen, scratched vinyl dash. Cold blue ambient light, a single warm amber light from the right. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Pantalla de la calculadora sin dígitos.

#### BL02 · `blog-relay-requirements` · 3:2
*Uso:* Blog: Amazon Relay requirements · *Sustituye a:* `nf-clipboard-on-truck-hood`
```
A clipboard with a blank checklist form resting on the hood of a white truck at night, 50mm lens, f/2, 1/60s, ISO 3200, empty checkbox squares in a column and no words, a pen on the clipboard, a parked trailer and yard lights out of focus, rain beads on the hood paint, amber light from a work lamp, cold blue ambient. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Casillas sin texto. Cuidado con el brillo del capó.

#### BL03 · `blog-relay-load-board` · 3:2
*Uso:* Blog: Amazon Relay load board · *Sustituye a:* `nf-tablet-cab-night-yard`
```
A rugged tablet in a dash mount inside a truck cab in a night yard, 35mm lens, f/2, 1/40s, ISO 3200: the screen showing an abstract list of horizontal cyan bars with no text, the dashboard edge, a rain-covered windshield with sodium-lit trailers out of focus beyond. Warm amber glow from the instrument cluster, cold blue ambient. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Tablet con barras abstractas, sin interfaz legible.

#### BL04 · `blog-lumper-detention` · 3:2
*Uso:* Blog: lumper fees and detention pay · *Sustituye a:* `nf-dock-waiting-clock`
```
A loaded trailer waiting at a dock at night, 35mm lens, f/2.8, 1/30s, ISO 3200, a plain wall clock with simple tick marks and no numerals above the dock door, the driver's hand resting on a steering wheel visible through the cab window as a silhouette (no face), amber dock-door light and cold blue yard light, wet concrete. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Reloj sin números. Conductor solo silueta de mano.

#### RW01 · `resource-ifta` · 3:2
*Uso:* Recursos: IFTA calculator · *Sustituye a:* `nf-fuel-nozzle-truck-stop`
```
A diesel fuel nozzle resting in the pump holster at a truck stop at night, 50mm lens, f/2, 1/60s, ISO 3200, wet concrete, a white tractor out of focus at the next lane, amber canopy lights, cold blue ambient light, the pump display dark and blank, no brand marks on the pump. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Surtidor sin marcas ni números.

#### RW02 · `resource-rate-per-mile` · 3:2
*Uso:* Recursos: Rate per mile calculator · *Sustituye a:* `nf-calculator-map-dashboard`
```
A handheld calculator on a truck dashboard beside a folded paper road map at night, 50mm lens, f/2, 1/60s, ISO 3200, the calculator display blank, the map showing only abstract road lines with no names, highway lights in bokeh through the windshield, warm amber instrument glow, cold blue ambient. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Calculadora sin dígitos, mapa sin nombres.

#### RW03 · `resource-bol-template` · 3:2
*Uso:* Recursos: BOL template · *Sustituye a:* `nf-shipping-form-clipboard`
```
A blank multi-part shipping form on a clipboard on the tailgate of a loading dock at dusk, 50mm lens, f/2.8, 1/60s, ISO 1600, only printed empty boxes and thin ruled lines with no words, a ballpoint pen, a rubber dock bumper and trailer door softly blurred behind, cold blue twilight with an amber dock light. Shot on Kodak Portra 400 film, slightly underexposed, fine organic grain, gentle halation on highlights, natural lens vignetting, unretouched candid documentary photograph, overcast cold light or practical interior light only, cool steel-blue shadows, muted desaturated palette of steel blue and graphite with cyan only as a small accent and a single warm amber practical light, real-world imperfections and wear, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Formulario con cajas vacías, sin texto.

#### RW04 · `resource-agreement` · 3:2
*Uso:* Recursos: Dispatcher-carrier agreement · *Sustituye a:* `nf-contracts-amber-cyan`
```
Two stapled contract pages with blank text blocks drawn as faint grey lines and a pen resting on top, on a dark wooden table, 50mm lens, f/2.2, 1/60s, ISO 1600, a warm amber lamp from the right, a cold cyan monitor reflection from the left, a closed folder and a mug out of focus, slightly worn paper edges. Shot on Kodak Portra 400 film, slightly underexposed, fine organic grain, gentle halation on highlights, natural lens vignetting, unretouched candid documentary photograph, overcast cold light or practical interior light only, cool steel-blue shadows, muted desaturated palette of steel blue and graphite with cyan only as a small accent and a single warm amber practical light, real-world imperfections and wear, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Sin palabras: solo bloques grises de texto simulado.

#### RW05 · `resource-glossary` · 3:2
*Uso:* Recursos: Glossary · *Sustituye a:* `nf-cards-fan-key-fob`
```
A loose fan of blank index cards with soft handwritten-looking scribbles that read as lines only, a trucker road atlas and a key fob on a dark desk, 50mm lens, f/2.2, 1/60s, ISO 1600, a warm amber desk lamp from the right, cold blue ambient from a window, shallow depth of field. Shot on Kodak Portra 400 film, slightly underexposed, fine organic grain, gentle halation on highlights, natural lens vignetting, unretouched candid documentary photograph, overcast cold light or practical interior light only, cool steel-blue shadows, muted desaturated palette of steel blue and graphite with cyan only as a small accent and a single warm amber practical light, real-world imperfections and wear, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Garabatos ilegibles, no letras.

#### MM01 · `mega-relay` · 3:2
*Uso:* Mega menú de Services (tarjeta Amazon Relay) · *Sustituye a:* `nf-trailer-dock-night-wide`
```
A wide night view of a distribution-center dock wall with white trailers backed in and dock doors glowing amber, 35mm lens, f/4, 1/30s, ISO 3200, wet concrete apron, cold LED wall packs, a thin cyan sky edge, no marks, no numbers, no text. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Se verá pequeña: composición simple y contrastada.

#### OG01 · `og-default` · 1.91:1 (1200×630 final; genera 2:1 y recorta)
*Uso:* Imagen al compartir en redes · *Sustituye a:* `nf-white-truck-cyan-horizon`
```
A single plain white tractor-trailer on an empty interstate under a deep navy sky with a thin cyan horizon, three-quarter front view, 85mm lens, f/2.8, 1/100s, ISO 800, headlights on, the truck centered on the lower third so a crop keeps it whole, tall calm sky above, wet asphalt with faint reflections. Safe margin of 8% around the truck. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Camión entero y centrado con margen: los redes recortan.

#### DK01 · `desk-log-backdrop` · 16:9 (opcional)
*Uso:* Fondo del panel Desk log · *Sustituye a:* `nf-route-network-glow-blue`
```
Abstract long-exposure photograph of highway light trails over dark fields, 70mm lens, 15 second exposure, f/8, ISO 100: thin crossing red and white lines forming a loose network, the rest almost black navy, a hint of cyan haze at the lowest edge. Very low-key and calm, designed to sit behind text at 20% opacity. Shot on 35mm Cinestill 800T film, pushed one stop, visible organic grain, soft red-orange halation around bright light sources, natural lens vignetting, faint chromatic aberration at the frame edges, unretouched candid documentary photograph, available light only, shadows in deep navy blue and never pure black, muted desaturated palette of steel blue and graphite with cyan only as a small accent and sodium amber as the single warm note, slightly soft focus away from the subject, real-world imperfections, no text, no logos, no license plates, no watermark, no faces
```
*Nota:* Muy oscura: es un fondo.


---

## 8. Prompts de video (image-to-video)

Usa la imagen v2 aprobada como **primer fotograma**. Duración 12 a 15 s, 24 fps, exporta lo más grande posible (2688×1536 o más). Yo hago el bucle sin salto con un fundido de costura y lo codifico a 1080p.

#### V01 · `hero-highway-loop` · 16:9, 12 a 15 s
*Uso:* Video del hero (primer fotograma = H01)
```
Slow aerial dolly forward along the interstate, the camera gliding about 2 percent forward and 1 percent to the right over 12 seconds, nothing else changes. Tail-light trails flow gently, trucks drift forward at normal highway speed in the right lanes, mist moves slowly through the tree line, faint sway of treetops, the cyan horizon stays constant. No cuts, no zoom, no camera shake, no new objects entering, the last frame returns to the same composition as the first so it loops. Photographic film look, grain stays stable, no flicker. Shot on film look, subtle organic grain, muted steel-blue palette with cyan accent and sodium amber, no text, no logos, no faces.
```
*Nota:* Image-to-video (HeyGen / Veo / Kling / Runway) con H01 como primer fotograma. Duración 12 a 15 s, 24 fps, 1080p o superior. Aplica el fundido de costura al final.

#### V02 · `freight-yard-loop` · 16:9 (recorta a 21:9)
*Uso:* Banda de video del home (primer fotograma = SV01 o una variante 16:9)
```
Locked-off camera on a tripod, 35mm view of a loading dock at night: only the environment moves. Fine rain falling through the yard lights, steam drifting from a distant vent, a distant tractor with headlights slowly crossing the far background from left to right, the amber glow in the open dock door flickering very slightly, puddle reflections rippling. Nothing in the foreground moves, no cuts, no camera movement, seamless loop. Shot on film look, subtle organic grain, muted steel-blue palette with cyan accent and sodium amber, no text, no logos, no faces.
```
*Nota:* Cámara FIJA: el movimiento solo del ambiente. Evita el camión grande moviéndose en primer plano (fallan las ruedas).

#### V03 · `desk-room-loop` · 16:9
*Uso:* Banda opcional en Our desk
```
Locked-off interior shot of an empty dispatch operations room at night: monitor screens slowly updating thin cyan route lines, a tiny cursor-free pulse of light moving along them, light trails of distant highway traffic moving behind the rain-streaked window, one desk lamp steady. No people, no camera movement, no cuts, seamless loop. Shot on film look, subtle organic grain, muted steel-blue palette with cyan accent and sodium amber, no text, no logos, no faces.
```
*Nota:* Sin personas. Movimiento mínimo: pantallas y ventana.


---

## 9. Lo ideal: fotos reales

Ninguna imagen generada sustituye a **fotos reales** del equipo, de sus oficinas y de camiones de clientes con permiso. Cuando las tengas, tienen prioridad sobre cualquier prompt: el sitio ya está preparado (marcos grandes, recortes, ver `CREDITS.md`). Una sola sesión de 2 horas con un fotógrafo en una ciudad con tráfico pesado cubriría H01, CTA01 y ON03 con calidad que ningún generador logra.
