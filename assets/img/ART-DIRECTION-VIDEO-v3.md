# NorthStar Freight: prompts de video v3 (imagen a video, nivel cinematográfico)

Basado en las 54 imágenes nuevas de la carpeta de Drive (`*-v3-a`, `*-v3-b` y las versiones móviles). Complementa `ART-DIRECTION-v3.md` (imágenes) y sustituye a la sección de video de v1/v2.

---

## 0. Cómo está pensado

- **Todo es imagen a video:** el **primer fotograma es la imagen aprobada** (así foto y video son idénticos y no hay saltos al arrancar). Los 23 primeros fotogramas en 16:9 (2560×1440, ya recortados con el encuadre correcto) van en el paquete `primeros-fotogramas-16x9.zip`. Las imágenes 4:3 y 3:2 NO se suben tal cual a un generador: usa estos recortes.
- **Una sola pasada, sin bucle:** igual que los videos actuales del sitio. Se reproducen una vez y **el último fotograma queda como imagen de reposo** detrás del texto. Por eso cada prompt define el **fotograma final** y deja el espacio de texto limpio.
- **Cámara real:** movimientos pequeños y creíbles (trípode, dolly lento, dron suave). Nada de zooms rápidos, barridos ni cámara nerviosa.
- **Movimiento sutil y verosímil:** el espectáculo está en la luz, en el camión avanzando y en los detalles ambientales (polvo, hierba, vapor), no en efectos.

## 1. Revisión de la carpeta: qué se anima y qué no

Las 54 imágenes son un salto enorme: tractocamiones modernos y limpios, luz dorada y azul, geometría coherente (18 ruedas, remolque de 53 pies con cinta reflectiva, faldones, tránsito por la derecha, volante a la izquierda), personas sin rostro y composición con espacio para texto. Detalles a vigilar al animar: las ruedas de los camiones (el generador puede deformarlas), las manos con el teléfono y los reflejos del espejo.

| Imagen | Decisión |
|---|---|
| `hero-highway-poster-v3-a` (b es más cerrada) | **Video V01** (hero del home) |
| `service-dispatch-booking-v3-a` | **Video V02** (banda de video del home) |
| `hero-owner-operators-v3-a` | **Video V03** (cabina al amanecer) |
| `cta-night-truck-v3-a` | Video V04 (cierre) |
| `hero-how-it-works-v3-a` | Video V05 (aéreo, cruce de autopistas) |
| `hero-amazon-relay-v3-a` | Video V06 (aéreo, centro de distribución) |
| `hero-dry-van-v3-a` | Video V07 (puertas traseras abiertas) |
| `check-dry-van-interior-v3-a` | Video V08 (interior del remolque) |
| `onboarding-run-v3-a` | Video V09 (salida por la caseta) |
| `setup-owner-operator-v3-a` | Video V10 (área de descanso al amanecer) |
| `setup-small-fleet-v3-a` | Video V11 (patio de flota) |
| `setup-amazon-relay-v3-a` | Video V12 (patio del centro de distribución) |
| `service-load-tracking-v3-a` | Video V13 (cabina con el teléfono en el soporte) |
| `check-tracking-mirror-v3-a` | Video V14 (espejo al atardecer) |
| `onboarding-learn`, `onboarding-scope`, `service-back-office`, `hero-back-office`, `why-record-every-load-b`, `why-one-channel`, `check-owner-approve`, `faq-dock-light-b`, `onboarding-review` | **Micro-animaciones C01 a C09** (4 a 6 s, casi fotos vivas) |
| `hero-our-desk-v3-a/b`, `why-desk-team-v3-a/b` | **NO animar ni usar como equipo:** son salas vacías generadas por IA y podrían leerse como la oficina real de NorthStar. Se sustituyen por fotos reales (sección 10 de `ART-DIRECTION-v3.md`) |
| Versiones `-mobile` de CTA y FAQ | No hacen falta para video (el video solo se reproduce en pantallas de 1025 px o más) |

**Orden de generación recomendado:** V01, V02 y V03 primero (son los que reemplazan a los videos actuales del sitio); después V04 a V09; el resto según presupuesto.

## 2. Reglas de producción (valen para todos)

- **Formato:** 16:9, 1080p o más (genera en 1080p y yo reescalo si hace falta), 24 fps, **sin audio** (pide mute o descártalo después).
- **Duración:** 8 a 12 s por toma (V01 se arma con dos tomas, ver abajo). No más: la calidad cae.
- **Fidelidad al primer fotograma:** usa la imagen como *start frame*, no como referencia de estilo. Intensidad de movimiento baja o media (en Kling y Runway: *motion* 3 a 5 de 10; en Veo y Sora el prompt manda).
- **Geometría estable:** pide explícitamente que ruedas, remolque, cabina y arquitectura no cambien de forma. Una rueda que ondula o un remolque que se derrite es motivo de descarte.
- **Ruedas:** movimiento consistente con unos 100 km/h sin efecto estroboscópico.
- **Luz:** la hora no cambia de golpe; solo evoluciones lentas (el sol sube o baja unos grados, una nube pasa).
- **Sin:** cortes, zooms rápidos, barridos, cámara nerviosa, texto, logos, matrículas, subtítulos, rostros nítidos, marcas de agua.
- **Genera 3 intentos** por prompt y elige el más estable. Para elegir: mira la primera y la última rueda del camión, los bordes del remolque y las manos.

**Cola de cámara común (pégala al final del prompt que lo pida):**
`filmed on ARRI Alexa 35 with a Cooke S7/i prime lens, 24 fps, 180 degree shutter with natural motion blur, subtle organic film grain, true-to-life color, unretouched documentary realism, no audio`

**Negativo de video (si la herramienta lo admite):**
`morphing, warping, melting trailer, wobbling wheels, extra wheels, extra axles, flickering, jitter, strobing, ghosting, handheld shake, fast zoom, whip pan, cut, scene change, sudden lighting change, lens flare overload, text, subtitles, logos, license plates, watermark, sharp faces, deformed hands, extra fingers, duplicated vehicles, cartoon, oversaturated`

**Estructura de cada prompt:** (1) cómo es el primer fotograma, para anclar; (2) la acción; (3) la cámara; (4) luz y ambiente; (5) lo que NO debe cambiar; (6) cómo termina.

---

## 3. Prioridad 1: reemplazan los videos del home

### V01 · `hero-highway` · hero del home · 2 tomas (total 15 s)
**Primer fotograma:** `V01-hero-highway.jpg` (= `hero-highway-poster-v3-a`).
**Toma A (10 s):**
```
Starting from the provided frame: a clean white class 8 sleeper-cab semi truck pulling a white 53-foot dry van trailer, seen from behind and slightly to the left, driving away in the right lane of a four-lane US interstate through dry West Texas ranchland at golden hour. The truck pulls away from the camera at a steady highway speed and gradually becomes smaller, shrinking to about one third of its current size over ten seconds and drifting toward the vanishing point. Camera: completely locked-off tripod at 85mm equivalent on the road shoulder, no zoom, no pan, no shake. The tandem axles of the tractor and the trailer rotate smoothly at highway speed without strobing, the red and white conspicuity tape and the red tail lights stay crisp and stable. A warm sun glint slides slowly along the trailer's side panels as the truck moves, faint heat shimmer rises from the asphalt, dry grass and mesquite on the shoulder sway gently in the wind, a second truck far ahead stays small near the horizon, thin cirrus clouds drift very slowly. The trailer shape, wheels, road markings and rumble strips never change form. Ends with the truck small near the horizon on the right third, the left two thirds clean open sky and empty road. Filmed on ARRI Alexa 35 with a Cooke S7/i prime lens, 24 fps, 180 degree shutter with natural motion blur, subtle organic film grain, true-to-life color, unretouched documentary realism, no audio, no text, no logos, no license plates, no people visible
```
**Toma B (5 s, continuación):** usa el **último fotograma de la toma A** como primer fotograma.
```
Starting from the provided frame: the white semi truck is already small, far down the empty interstate at golden hour. Continue the same locked-off tripod shot: the truck keeps receding until it is a small bright shape near the horizon, the sky turns a slightly deeper apricot as the sun sinks a few degrees, the long shadows of the grass stretch a little, a slow soft drift of cirrus. The final second is almost still: calm empty road, open sky, one tiny truck. No camera movement. Same lens, light and grain as the previous shot, no audio, no text, no logos, no license plates, no people.
```
**Fotograma final (queda como imagen de reposo del hero):** camión pequeño en el tercio derecho, carretera vacía, cielo limpio a la izquierda para el texto.
**Revisa:** ruedas sin ondular, remolque sin deformarse, el camión nunca sale de cuadro bruscamente, sin segundo camión que aparezca de la nada.

### V02 · `dock-golden-hour` · banda de video del home · 10 s
**Primer fotograma:** `V02-dock-golden-hour.jpg` (= `service-dispatch-booking-v3-a`).
```
Starting from the provided frame: the warm concrete wall of a modern distribution center glowing in late-afternoon golden light, three dock doors with the two on the left open and showing bright white LED-lit interiors with shrink-wrapped pallets, a long white 53-foot trailer backed against the dock extending to the right toward its parked tractor, yellow safety bollards, and a warehouse worker in a hi-vis yellow vest and white hard hat seen from behind, walking steadily along the dock holding a clipboard. Action: the worker keeps walking away from the camera at a calm pace toward the open dock doors; inside the first open door a forklift silhouette crosses slowly far in the background. Camera: slow smooth lateral dolly from left to right at about walking speed with a very slight parallax, 35mm look, no zoom, no shake. The sun slowly moves a few degrees, long soft shadows drift across the concrete, a faint warm glow spills from the dock doors, tiny dust motes float in the beams of light. The trailer, wheels, dock seals, bollards and the building geometry never change shape. Ends with the worker small, reaching the second dock door, the sunlit wall filling the left third. Filmed on ARRI Alexa 35 with a Cooke S7/i prime lens, 24 fps, 180 degree shutter with natural motion blur, subtle organic film grain, true-to-life color, unretouched documentary realism, no audio, no text, no door numbers, no logos, no license plates, no visible face
```

### V03 · `cab-sunrise` · hero de Owner-operators · 10 s
**Primer fotograma:** `V03-cab-sunrise.jpg` (= `hero-owner-operators-v3-a`).
```
Starting from the provided frame: the interior of a clean modern US sleeper cab seen from the passenger seat at sunrise, left-hand-drive steering wheel, a tablet in a dash mount with its screen dark, the sleeper curtain open on the left, a long empty interstate vanishing ahead under a golden sun just above the horizon. Action: the truck drives steadily forward; the lane markings stream smoothly toward the cab, the horizon sun glints and a soft natural flare slowly drifts across the windshield, dust motes float in the warm light, the steering wheel makes tiny, natural corrections with no hands visible, the sleeper curtain sways very slightly with the road. Camera: locked-off, mounted at the passenger seat at a 25mm equivalent, with only the faint natural micro-vibration of a moving cab, no zoom, no pan. The dashboard, tablet, mirror and window frames never change shape and no text or symbol appears on any screen. Ends with the cab steady, the sun slightly higher, the road ahead bright and empty. Filmed on ARRI Alexa 35 with a Cooke S7/i 25mm lens, 24 fps, 180 degree shutter with natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no people
```

---

## 4. Prioridad 2: páginas y cierres

### V04 · `cta-night-truck` · cierre de las páginas · 8 s
**Primer fotograma:** `V04-cta-night-truck.jpg` (= `cta-night-truck-v3-a`, recorte 16:9 con el camión en el tercio derecho).
```
Starting from the provided frame: a clean white semi truck with a plain white 53-foot dry van trailer cresting a long rural interstate hill at blue hour, headlights and amber roof marker lights on, clear indigo sky with a warm orange band along the horizon, the soft lights of a small town far below in the valley, slightly damp asphalt with gentle reflections. Action: the truck approaches the camera slowly under a telephoto lens, growing only about twenty percent in frame, its headlights blooming subtly on the wet road, the trailer's amber and red marker lights steady; the town lights twinkle faintly, the grass in the foreground sways gently, a few thin clouds barely move. Camera: locked-off tripod at 70mm, with at most a barely perceptible push-in of three percent, no pan, no shake. The tractor, grille, wheels and trailer never change form. The left two thirds of the frame stay as quiet sky and empty road for text. Ends with the truck still on the right third, closer but fully inside the frame. Filmed on ARRI Alexa 35 with a Cooke S7/i 50mm lens, Kodak Vision3 500T look, 24 fps, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible driver
```

### V05 · `aerial-interchange` · hero de How it works · 12 s
**Primer fotograma:** `V05-aerial-interchange.jpg` (= `hero-how-it-works-v3-a`).
```
Starting from the provided frame: an aerial view of a US interstate cloverleaf interchange at blue hour with several clean white semi trucks on different ramps, green fields in the infield, a clear indigo sky with an apricot horizon. Action: the drone slowly gains about fifteen meters of altitude while gliding forward, revealing more of the interchange with gentle parallax; the white trucks travel along their ramps in different directions at realistic speed, one merging onto the highway, one looping around the cloverleaf, one entering the underpass; headlights and amber marker lights glow, the guardrails and lane lines stay crisp. Camera: smooth stabilized aerial move from a DJI Inspire 3 with a 35mm look, a single continuous glide with no sudden changes of speed. Fields, trees, overpass and road geometry never change shape, and the trucks keep a consistent size and form. Ends wide, with the trucks spread across the interchange and the clean sky filling the top third for text. Filmed with natural motion blur at 24 fps, subtle organic film grain, true-to-life color, no audio, no text, no road sign text, no logos, no license plates, no people
```

### V06 · `aerial-fulfillment` · hero de Amazon Relay · 10 s
**Primer fotograma:** `V06-aerial-fulfillment.jpg` (= `hero-amazon-relay-v3-a`). Sin ninguna marca.
```
Starting from the provided frame: an aerial oblique view at dusk of a large modern fulfillment center with a long row of lit dock doors and many clean white unmarked trailers backed in, a white tractor with a trailer entering the yard on a curving access road, tidy landscaping, a clear sky with an apricot horizon. Action: the drone glides slowly forward and slightly left, following the truck as it rolls smoothly through the curve toward the docks; the LED dock lights glint softly, tiny distant figures in hi-vis vests move between trailers, the grass on the hillside sways gently. Camera: smooth stabilized aerial glide at a 45 degree oblique angle, constant slow speed, no zoom. The building, trailers, dock doors and road geometry never change shape; the building's signage panels stay blank. Ends with the truck near the dock row and the open sky on the left for text. Filmed with natural motion blur at 24 fps, subtle organic film grain, true-to-life color, no audio, no text, no logos, no brand colors, no license plates, no visible faces
```

### V07 · `dry-van-rear-doors` · hero de Dry van · 9 s
**Primer fotograma:** `V07-dry-van-rear-doors.jpg` (= `hero-dry-van-v3-a`).
```
Starting from the provided frame: the rear of a 53-foot dry van trailer with both swing doors open at a loading dock in warm late-afternoon sun, a steel dock plate framed by yellow bollards, neatly stacked shrink-wrapped pallets of boxes inside on the right, a manual pallet jack, and a warehouse worker in a hi-vis vest walking away deep inside the trailer. Action: the worker keeps walking calmly deeper into the trailer toward the far end; warm light beams cross the interior and fine dust motes float through them; the long shadow of the bollards creeps slowly across the concrete. Camera: very slow smooth dolly-in toward the trailer opening, advancing about one and a half meters at a 24mm look, perfectly level, no zoom, no shake. The trailer, doors, pallets, boxes, pallet jack and bollards never change form or position. Ends closer to the doorway with the worker small at the far end of the warm interior. Filmed on ARRI Alexa 35 with a Cooke S7/i 24mm lens, 24 fps, 180 degree shutter with natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible face
```

### V08 · `dry-van-interior` · panel de Dry van · 8 s
**Primer fotograma:** `V08-dry-van-interior.jpg` (= `check-dry-van-interior-v3-a`).
```
Starting from the provided frame: the inside of an empty 53-foot dry van trailer looking toward the open rear doors, light wood floor planks, steel E-track rails running along both walls, bright daylight pouring in across the floor, a loading dock and blue sky visible through the opening. Action: a very slow forward push toward the opening; fine dust motes drift in the beam of light; a soft cloud shadow passes and the daylight on the floor gently brightens and dims once; the distant dock doors outside stay sharp. Camera: smooth slow dolly-in about two meters, perfectly centered and level, 24mm look, no zoom, no shake. The walls, rails, ceiling ribs and floor planks never change shape or pattern. Ends nearer the doorway with the bright exit filling a larger part of the frame. Filmed on ARRI Alexa 35 with a Cooke S7/i 24mm lens, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no people
```

### V09 · `gate-exit-sunrise` · paso 3 del onboarding · 10 s
**Primer fotograma:** `V09-gate-exit-sunrise.jpg` (= `onboarding-run-v3-a`).
```
Starting from the provided frame: a clean white tractor with a plain white 53-foot trailer at the secured gate of a distribution center at sunrise, a guard shack with a raised yellow gate arm, tall light poles, long warm shadows on the asphalt, low sun and soft ground mist behind the trees on the left. Action: the truck rolls forward slowly and smoothly toward the right side of the frame, passing the guard shack under the raised gate arm and leaving the frame in the last two seconds; the mist drifts gently, the sun glints softly through the trees, the gate arm stays raised and still. Camera: locked-off tripod at 35mm with only a three percent slow push-in, no pan, no shake. Tractor, trailer, wheels, guard shack and fence never change shape; the wheels rotate smoothly without strobing. Ends with the empty gate, the sunrise and the long shadows as the resting frame. Filmed on ARRI Alexa 35 with a Cooke S7/i 40mm lens, Kodak Vision3 250D look, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible driver
```

### V10 · `owner-operator-rest-area` · pestaña Owner-operator · 10 s
**Primer fotograma:** `V10-owner-operator-rest-area.jpg` (= `setup-owner-operator-v3-a`).
```
Starting from the provided frame: a meticulously maintained pearl-white long-hood sleeper tractor with polished aluminum fuel tanks, chrome stacks and a single white trailer, parked alone at a quiet interstate rest area at sunrise, dew on the grass, a driver seen from behind standing at the front of the trailer with both arms raised in a stretch, a large tree on the right, a picnic shelter far away. Action: the sun rises slowly and the golden light climbs along the chrome and the trailer side; low dew mist drifts across the grass; the tree leaves rustle gently; the driver slowly lowers his arms and turns to walk toward the cab steps (back to camera, no face visible). Camera: slow smooth push-in at a 70mm look, about five percent, perfectly stable. The tractor, stacks, tanks, wheels and trailer never change shape. Ends with the driver near the cab steps, the sun higher, warm light on the chrome. Filmed on ARRI Alexa 35 with a Cooke S7/i 50mm lens, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible face
```

### V11 · `small-fleet-yard` · pestaña Small fleet · 10 s
**Primer fotograma:** `V11-small-fleet-yard.jpg` (= `setup-small-fleet-v3-a`).
```
Starting from the provided frame: four matching white sleeper tractors with clean white 53-foot trailers parked in a neat row in a modern secured truck yard at golden hour, a white pickup truck in the foreground, tall light poles, crisp painted parking lines, a clear sky, a tiny distant figure walking on the far left. Action: the distant figure walks slowly along the row; as the dusk deepens a few degrees the amber marker lights of the tractors and the sodium glow of the light poles come on softly one by one; a faint breeze moves the dry grass at the edge of the asphalt. Camera: very slow smooth lateral dolly from right to left at a 35mm look, perfectly level, no zoom, no shake. The trucks, trailers, pickup and light poles never change shape or spacing. Ends with the full row lit and calm, open sky above. Filmed on ARRI Alexa 35 with a Cooke S7/i 35mm lens, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible face
```

### V12 · `relay-yard-dusk` · pestaña Amazon Relay · 10 s
**Primer fotograma:** `V12-relay-yard-dusk.jpg` (= `setup-amazon-relay-v3-a`). Sin ninguna marca.
```
Starting from the provided frame: a large modern fulfillment center at dusk with a long row of lit dock doors and many clean white unmarked trailers backed in, a white tractor with a trailer rolling along the yard road in the foreground, blank gray signage panels on the building, a clear indigo sky with a warm apricot horizon. Action: the truck drives smoothly along the curve at a calm speed from left to right; the bright LED dock lights glint softly; two tiny figures in hi-vis vests cross far in the background; a gentle breeze moves the young trees at the left. Camera: low smooth slider move following the truck at a 32mm look, constant slow speed, no zoom, no shake. Tractor, trailers, dock doors, building and road geometry never change shape; the signage stays blank. Ends with the truck near the right third and the long lit dock row behind it. Filmed on ARRI Alexa 35 with a Cooke S7/i 32mm lens, Kodak Vision3 500T look, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no brand colors, no license plates, no visible faces
```

### V13 · `cab-phone-dash` · servicio Load tracking · 8 s
**Primer fotograma:** `V13-cab-phone-dash.jpg` (= `service-load-tracking-v3-a`).
```
Starting from the provided frame: over-the-shoulder view inside a US sleeper cab at sunset, the left-hand-drive steering wheel at the left, a weathered right hand seating a smartphone into a dash mount with the phone turned away so no screen content is visible, a coiled CB radio cable, a steel tumbler in the cup holder, and through the windshield a multi-lane highway with a white trailer ahead and the sun low on the horizon. Action: the hand finishes seating the phone with one simple gesture and slowly withdraws to rest near the wheel, the fingers staying mostly hidden behind the phone; the truck keeps rolling, the trailer ahead recedes slowly, the road markings stream by, a soft sun flare drifts across the windshield, the coiled CB cable sways slightly. Camera: locked-off over-the-shoulder at a 35mm look with the faint natural vibration of a moving cab, no zoom. The phone shows no text, no symbol and no light on its screen; dashboard and wheel never change shape. Ends with the hand at rest and the sun glowing ahead. Filmed on ARRI Alexa 35 with a Cooke S7/i 35mm lens, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible face
```
**Revisa:** manos (cinco dedos, sin deformarse); si falla, usa C07 como alternativa más simple.

### V14 · `truck-mirror-sunset` · panel de Tracking · 6 s
**Primer fotograma:** `V14-truck-mirror-sunset.jpg` (= `check-tracking-mirror-v3-a`).
```
Starting from the provided frame: a large exterior truck mirror on a white cab door reflecting a white trailer receding down a highway under a warm apricot sunset sky, a flat open landscape behind. Action: the truck drives forward; in the mirror the lane markings stream calmly away and the trailer holds a steady position in the reflection, the sunset glow shifts a very small amount; beyond the mirror the distant scrub and hills pass slowly in soft focus. Camera: locked-off, rigidly mounted to the cab at a 50mm look, with only a barely perceptible road vibration, no zoom. The mirror frame, the cab door and the trailer in the reflection never change shape; keep the motion gentle so the reflection stays stable. Ends with the same composition, the sky slightly deeper in color. Filmed on ARRI Alexa 35 with a Cooke S7/i 50mm lens, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no people
```
**Revisa:** el reflejo es lo más difícil para un generador. Si se deforma, sube la duración mínima o descártalo: es la menos importante.

---

## 5. Micro-animaciones (C01 a C09): casi fotos vivas, 4 a 6 s

Reglas: cámara **completamente fija** (sin dolly ni zoom), un solo movimiento principal pequeño y creíble, la luz casi no cambia, manos solo con un gesto simple. Úsalas como fondos sutiles (por ejemplo bandas o tarjetas) o descártalas si no aportan. Todas con: `Starting from the provided frame`, `locked-off camera, no zoom, no pan`, `24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no visible face`.

- **C01 `diner-booth`** (onboarding-learn): vapor sube suave de las dos tazas; por la ventana, un tractor blanco avanza muy despacio entre los surtidores; la luz dorada cambia apenas; el teléfono y el cuaderno no se mueven.
- **C02 `road-atlas`** (onboarding-scope): la mano sigue trazando la línea azul con el bolígrafo unos centímetros más sobre el mapa; sube un hilo de vapor de la taza; la tableta con el mapa no cambia; luz de ventana estable.
- **C03 `desk-hand-writing`** (service-back-office): la mano subraya una línea con el bolígrafo; las franjas de luz de la persiana se desplazan despacio sobre el escritorio; la otra mano con reloj descansa quieta.
- **C04 `desk-folder-window`** (hero-back-office): la mano pasa lentamente una página de la carpeta; las franjas de luz de la persiana derivan; las hojas de la planta se mecen apenas.
- **C05 `clipboard-trailer-door`** (why-record-every-load-b): la mano alisa el papel y presiona el clip una vez; un destello de sol recorre la bisagra y la cinta reflectiva; el muelle del fondo queda quieto. Un solo gesto, sin cambiar la forma de los dedos.
- **C06 `driver-phone-fender`** (why-one-channel-a): los pulgares teclean con un movimiento pequeño; el viento mueve levemente la chaqueta de mezclilla; en el fondo desenfocado un camión sale despacio de la estación de servicio.
- **C07 `hands-phone-truckstop`** (check-owner-approve-a): un pulgar hace un gesto de deslizar; las luces del techo de la estación se encienden poco a poco; el camión del fondo avanza un poco.
- **C08 `dock-tree-shadows`** (faq-dock-light-b): las sombras de las hojas se mueven suavemente sobre la pared por el viento y la luz del sol se mueve un poco; ningún vehículo se mueve.
- **C09 `desk-review`** (onboarding-review-a): la mano mueve el bolígrafo sobre el informe; sube vapor de la taza; el brillo de la pantalla del portátil casi no cambia y no aparece texto; las franjas de luz derivan.

---

## 6. Qué hago yo después de recibirlos

- Aplico la gradación de marca (navy y cian, la misma de las fotos), recorto, corrijo exposición y codifico a 1920×1080, H.264, 24 fps, sin audio, `+faststart`, objetivo ≤ 3,5 MB por video grande.
- Compruebo que el **primer fotograma coincide con la foto del slot** y que el **último fotograma sirve como imagen de reposo** (espacio de texto limpio, contraste AA medido).
- Los videos se montan al acercarse (carga diferida), solo en escritorio y sin movimiento reducido, se reproducen una vez y quedan en el último fotograma, como los actuales.
- Nombres de entrega: `V01-hero-highway-a.mp4`, `V02-dock-golden-hour-a.mp4`… y `-b` si hay segunda opción.

## 7. Control de calidad del video: descarta si falla cualquiera

1. **Ruedas:** giran suaves, sin estroboscopio, sin ondular ni cambiar de número. Cuenta las ruedas de eje al inicio y al final.
2. **Camión y remolque:** conservan forma, proporciones y cinta reflectiva todo el tiempo; no se estiran ni se funden.
3. **Manos y personas:** cinco dedos estables, nadie con rostro nítido, nadie que aparezca o desaparezca.
4. **Luz:** sin saltos de exposición; el cambio es lento y natural.
5. **Cámara:** sin sacudidas ni zooms; el movimiento es el pedido y de velocidad constante.
6. **Texto y símbolos:** ninguno aparece en pantallas, remolques, señales o papeles.
7. **Final:** el último fotograma tiene el espacio de texto limpio y se ve bien como imagen fija.
8. **Primer fotograma:** idéntico a la imagen aprobada (compara a pantalla completa).
