# NorthStar Freight: videos que faltan (prompts v3, segunda guía)

Complementa a `ART-DIRECTION-VIDEO-v3.md`. Ya están en el sitio los primeros 12 videos; aquí van los **14 que quedan** (5 videos grandes y 9 micro-animaciones) con prompts reescritos con lo que aprendimos al integrar los anteriores.

## 0. Estado

| Hecho (12) | Dónde está en el sitio |
|---|---|
| V01 hero del home · V02 muelle · V03 cabina · V04 CTA · V05 intercambiador · V06 patio de Relay · V07 puertas traseras · V08 interior del remolque · V09 salida por la caseta | Hero y banda del home, CTA del home, hero de Owner, How, Relay, Dry van (hero y panel) y paso 03 del onboarding |

| Pendiente | Primer fotograma (archivo del paquete `primeros-fotogramas-16x9.zip`) | Dónde caería en el sitio | Qué hace falta de mi lado |
|---|---|---|---|
| **V13** cabina con el teléfono | `V13-cab-phone-dash.jpg` | Hero de `/load-tracking/` | HECHO (fase AC): el hero no tenía foto; ahora lleva foto + video |
| **V14** espejo al atardecer | `V14-truck-mirror-sunset.jpg` | Panel «Proof, not promises» de `/load-tracking/` | Nada: el panel ya admite video |
| **V12** patio de Relay al anochecer | `V12-relay-yard-dusk.jpg` | Pestaña «Amazon Relay» de «Choose your setup» (home y Owner) | Montar video dentro de las pestañas |
| **V10** área de descanso | `V10-owner-operator-rest-area.jpg` | Pestaña «Owner-operator» | Igual que V12 |
| **V11** patio de flota | `V11-small-fleet-yard.jpg` | Pestaña «Small fleet» | Igual que V12 |
| **C04** carpeta junto a la ventana | `C04-desk-folder-window.jpg` | Hero de `/trucking-back-office/` | Nada: el hero ya admite video |
| **C01, C02, C09** | `C01-diner-booth.jpg`, `C02-road-atlas.jpg`, `C09-desk-review.jpg` | Pasos 01, 02 y 04 del onboarding del home (el 03 ya tiene el suyo) | Nada: ya admiten video |
| **C03** escritorio, mano que escribe | `C03-desk-hand-writing.jpg` | Tarjeta «Trucking back office» del home | Montar video en las tarjetas |
| **C05, C06, C07, C08** | ver §4 | Tarjetas de «Why a desk», FAQ y checks | Montar video en cada contenedor (pequeño) |

**Mi recomendación de prioridad:** V13 y V14 (cierran la página de Load tracking sin tocar código), después V12, V10 y V11 (las pestañas son una de las secciones que más se miran), después C04 y C01, C02, C09. **C03, C05, C06, C07 y C08 aportan poco** (son fotos vivas de 5 s en tarjetas pequeñas): hazlas solo si sobra presupuesto.

Lo que **no se genera**: la foto y el video del equipo (Jonathan, Elias y Mileva) y la oficina real de NorthStar. Siguen siendo material real.

## 1. Lo que aprendimos con los 12 videos (aplícalo a este lote)

1. **El generador cambia el encuadre.** En los 12 casos el primer fotograma del video NO fue la imagen aprobada: reencuadró, se alejó o movió la cámara. Lo resolví usando el primer fotograma del video como póster, pero si el encuadre cambia mucho, se pierde parte de la composición pensada para el texto. Por eso aquí cada prompt empieza con «Preserve…» y pide cámara fija.
2. **Salieron a 720p** (1280×720) y los reescalé a 1080p. En escritorio se ven algo blandos. Si tu herramienta lo permite, **exporta a 1080p o más** (o genera y sube con «upscale»): mejora todo el sitio.
3. **Duran 8 s.** No pidas 10 o 12: la herramienta entrega 8. Escribe la acción para que **termine antes del segundo 7** y el último fotograma quede limpio, porque ese fotograma es la imagen de reposo.
4. **Un solo color de camión.** En V05 apareció una cabina roja. Añade siempre `every tractor and trailer is plain white or graphite, no red or colored vehicles`.
5. **Cámara fija gana.** Los movimientos de dolly o push-in cambiaron la geometría; los que funcionaron mejor fueron los de cámara fija con el movimiento dentro de la escena (camión, luz, personas lejanas). En este lote casi todos son fijos.
6. **Manos:** sale bien si el gesto es uno solo y simple. Los que traen manos (V13 y las C) son el riesgo principal: genera 3 intentos y mira los dedos al inicio, a la mitad y al final.
7. **Sin texto:** ninguna pantalla, papel, reloj o señal puede mostrar texto. Si aparece, descarta.

## 2. Cómo usarlos

- **Primer fotograma:** el archivo indicado, del paquete `primeros-fotogramas-16x9.zip` (ya recortados a 16:9 con el encuadre correcto).
- **Formato del prompt:** el mismo que ya te dio buen resultado: empieza con `Use the provided image as the starting frame. Preserve …`, una sola toma continua, acción pequeña y creíble, cámara fija, cierre definido y la cola de calidad.
- **Cola común (ya incluida en cada prompt):** `1080p, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible face`.
- **Negativo si la herramienta lo admite:** `morphing, warping, melting trailer, wobbling wheels, extra wheels, flickering, jitter, strobing, ghosting, handheld shake, fast zoom, whip pan, cut, scene change, sudden lighting change, text, subtitles, logos, license plates, watermark, sharp faces, deformed hands, extra fingers, duplicated vehicles, red vehicles, cartoon, oversaturated`
- **Genera 3 intentos** de cada uno y elige el más estable (mira ruedas, bordes del remolque y manos). Nombre de entrega: `V13-cab-phone-dash-a.mp4` (y `-b` si hay otra opción).

---

## 3. Videos grandes (8 s)

### V13 · `cab-phone-dash` · hero de Load tracking
**Primer fotograma:** `V13-cab-phone-dash.jpg`. **Riesgo:** manos. Si no queda limpio, usa C07 como alternativa.
```
Use the provided image as the starting frame. Preserve the over-the-shoulder view inside the US sleeper cab at sunset, the left-hand steering wheel, the coiled CB radio cable, the steel tumbler in the cup holder, the multi-lane highway ahead with the white trailer and the low sun, exactly as in the image. One continuous real-time shot from a rigidly mounted camera at a 35mm look with only the faint natural vibration of a moving cab, no zoom and no reframing. The weathered right hand finishes seating the smartphone in the dash mount with one simple gesture and then slowly withdraws to rest near the steering wheel, fingers mostly hidden behind the phone; the phone stays turned away and shows no screen content. The truck keeps rolling, the trailer ahead recedes slowly, the road markings stream by, a soft sun flare drifts across the windshield and the coiled cable sways slightly. Dashboard and steering wheel never change shape; every vehicle is plain white or graphite. Ends with the hand at rest and the sun glowing ahead. 1080p, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible face
```

### V14 · `truck-mirror-sunset` · panel «Proof, not promises»
**Primer fotograma:** `V14-truck-mirror-sunset.jpg`. **Riesgo:** el reflejo (lo más difícil para un generador). Es la pieza menos importante: si se deforma, descártala.
```
Use the provided image as the starting frame. Preserve the large exterior truck mirror on the white cab door, the reflected white trailer receding down the highway under the apricot sunset, and the flat open landscape behind, exactly as in the image. One continuous real-time shot from a camera rigidly mounted to the cab at a 50mm look with only a barely perceptible road vibration, no zoom and no reframing. The truck drives forward: in the mirror the lane markings stream calmly away and the trailer holds a steady position in the reflection while the sunset glow shifts a very small amount; beyond the mirror the distant scrub and hills pass slowly in soft focus. The mirror frame, the cab door and the trailer in the reflection never change shape; keep the motion gentle so the reflection stays stable. Ends with the same composition and the sky slightly deeper in color. 1080p, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no people
```

### V12 · `relay-yard-dusk` · pestaña «Amazon Relay»
**Primer fotograma:** `V12-relay-yard-dusk.jpg`. **Sin ninguna marca de Amazon** (rótulos del edificio lisos y grises).
```
Use the provided image as the starting frame. Preserve the large modern fulfillment center at dusk, the long row of lit dock doors with clean white unmarked trailers backed in, the blank gray signage panels, the curving yard road with the white tractor and trailer in the foreground, and the clear indigo sky with the warm apricot horizon, exactly as in the image. One continuous real-time shot from a locked-off tripod at a 32mm look, no zoom, no pan, no reframing. The truck rolls forward slowly and smoothly along the curve toward the dock row and comes to a calm stop near the right third of the frame; the dock lights glint softly, two tiny figures in hi-vis vests cross far in the background and the young trees at the left move gently in the breeze. Tractor, trailers, dock doors, building and road never change shape and the signage stays blank; every tractor and trailer is plain white or graphite, no red or colored vehicles. Ends with the truck stopped near the right third and the long lit dock row behind it. 1080p, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no brand colors, no license plates, no visible faces
```

### V10 · `owner-operator-rest-area` · pestaña «Owner-operator»
**Primer fotograma:** `V10-owner-operator-rest-area.jpg`. **Riesgo:** brazos del conductor (que quede de espaldas y sin rostro).
```
Use the provided image as the starting frame. Preserve the pearl-white long-hood sleeper tractor with polished aluminum fuel tanks and chrome stacks, the single white trailer parked alone at the quiet rest area, the driver seen from behind at the front of the trailer, the large tree on the right, the distant picnic shelter and the sunrise light, exactly as in the image. One continuous real-time shot from a completely locked-off tripod at a 50mm look, no zoom, no pan, no reframing. The driver slowly lowers both arms from his stretch and takes two slow steps toward the cab steps with his back to the camera and no face visible; the sun rises a few degrees and the golden light climbs along the chrome and the trailer side, low dew mist drifts across the grass and the leaves of the tree rustle gently. The tractor, stacks, tanks, wheels and trailer never change shape. Ends with the driver near the cab steps and warm light on the chrome. 1080p, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible face
```

### V11 · `small-fleet-yard` · pestaña «Small fleet»
**Primer fotograma:** `V11-small-fleet-yard.jpg`. **Cámara:** fija (el dolly lateral del primer plan movía la hilera; solo úsalo si el generador lo mantiene estable).
```
Use the provided image as the starting frame. Preserve the four matching white sleeper tractors with clean white 53-foot trailers parked in a neat row, the white pickup truck in the foreground, the tall light poles, the crisp painted parking lines, the tiny distant figure on the far left and the clear golden-hour sky, exactly as in the image. One continuous real-time shot from a completely locked-off tripod at a 35mm look, perfectly level, no zoom, no pan, no reframing. The distant figure walks slowly along the row; as the dusk deepens a few degrees the amber marker lights of the tractors and the soft sodium glow of the light poles come on one by one, and a faint breeze moves the dry grass at the edge of the asphalt. The trucks, trailers, pickup and light poles never change shape or spacing; every vehicle is plain white or graphite, no red or colored vehicles. Ends with the full row lit and calm and open sky above. 1080p, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible face
```

---

## 4. Micro-animaciones (C01 a C09)

Casi fotos vivas: **cámara completamente fija**, un solo movimiento pequeño, la luz casi no cambia, manos con un solo gesto. Las herramientas entregan 8 s: **yo uso solo los primeros 5 o 6 s**, así que escribe el movimiento para que ocurra al principio. Cola común incluida en cada prompt.

### C01 · `diner-booth` · paso 01 del onboarding
```
Use the provided image as the starting frame. Preserve the diner booth table by the window, the two coffee mugs, the closed phone and the spiral notebook with the pen, and the truck stop outside at sunrise, exactly as in the image. One continuous real-time shot from a completely locked-off camera, no zoom, no pan, no reframing. Thin steam rises softly from both mugs; through the window a white tractor rolls very slowly between the fuel pumps; the golden light changes only slightly. The phone, notebook and pen stay perfectly still. 1080p, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible face
```

### C02 · `road-atlas` · paso 02 del onboarding
```
Use the provided image as the starting frame. Preserve the open road atlas with the blue route line, the hand holding the pen, the tablet showing a map, the coffee mug and the notebook on the wooden table, exactly as in the image. One continuous real-time shot from a completely locked-off camera, no zoom, no pan, no reframing. The hand slowly continues tracing the blue line a few centimeters further across the atlas with a single steady gesture and the fingers keep their natural shape; a thin thread of steam rises from the mug. The tablet and notebook do not change; no text appears on the map or the tablet. Window light stays steady. 1080p, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible face
```

### C09 · `desk-review` · paso 04 del onboarding
```
Use the provided image as the starting frame. Preserve the desk in warm window light with the printed report, the hand holding the pen, the laptop showing a bar chart, the steaming mug, the truck keys and the plant, exactly as in the image. One continuous real-time shot from a completely locked-off camera, no zoom, no pan, no reframing. The hand moves the pen slowly along a line of the report with one simple gesture; steam rises from the mug; the stripes of light from the blinds drift slowly across the desk. The laptop glow barely changes and no text or numbers appear on the screen or the report; the chart stays as soft blurred bars. 1080p, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible face
```

### C04 · `desk-folder-window` · hero de Back office
```
Use the provided image as the starting frame. Preserve the wooden desk in warm window light with the open binder of papers, the reading glasses, the pen, the mug, the closed laptop, the plant and the blinds, and the hand resting on the page, exactly as in the image. One continuous real-time shot from a completely locked-off camera, no zoom, no pan, no reframing. The hand slowly turns one page of the binder with a single simple gesture; the stripes of light from the blinds drift slowly across the wall and the desk; the leaves of the plant sway barely. The laptop, glasses, pen and mug stay perfectly still and no text appears on the pages. 1080p, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible face
```

### C03 · `desk-hand-writing` · tarjeta «Trucking back office»
```
Use the provided image as the starting frame. Preserve the desk by the window with the stack of printed forms, the hand holding the pen, the other hand with a wristwatch, the keyboard, the monitor with pale blurred cells, the mug and the plant, exactly as in the image. One continuous real-time shot from a completely locked-off camera, no zoom, no pan, no reframing. The hand underlines one line of the form with the pen in a single slow gesture while the other hand rests still; the stripes of light from the blinds shift slowly across the desk. The monitor shows no text or numbers and the plant barely moves. 1080p, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible face
```

### C05 · `clipboard-trailer-door` · tarjeta 02 de «Why a desk»
**Nota:** el set de la guía usa la variante `-b`; el sitio usa la `-a`. Si animas la `-b`, cambio también la foto.
```
Use the provided image as the starting frame. Preserve the white trailer rear door with its hinges and reflective tape, the clipboard with the blurred form, the worker's hands and hi-vis vest, and the dock doors out of focus behind, exactly as in the image. One continuous real-time shot from a completely locked-off camera, no zoom, no pan, no reframing. One hand smooths the paper and presses the clip once with a single simple gesture, the fingers keeping their natural shape; a soft glint of sun slides across the hinge and the reflective tape. The dock in the background stays still and no text appears on the form. 1080p, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible face
```

### C06 · `driver-phone-fender` · tarjeta 01 de «Why a desk»
```
Use the provided image as the starting frame. Preserve the driver in a denim jacket leaning on the white fender, his hands holding the smartphone, the fuel canopy and the trucks softly out of focus behind, and the sunset sky, exactly as in the image. One continuous real-time shot from a completely locked-off camera, no zoom, no pan, no reframing. The thumbs type with a small natural movement; the breeze moves the denim jacket slightly; in the blurred background a white truck leaves the fuel station slowly. The phone screen stays turned away and shows no text. 1080p, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible face
```

### C07 · `hands-phone-truckstop` · tema «con quién trabajamos» (FAQ) y alternativa de V13
```
Use the provided image as the starting frame. Preserve the hands holding the smartphone in a denim cuff at the truck stop at dusk, the lit fuel canopy and the white truck in the soft background, exactly as in the image. One continuous real-time shot from a completely locked-off camera, no zoom, no pan, no reframing. One thumb makes a single slow swipe gesture; the canopy lights come on gradually; the truck in the background rolls forward a little. The phone screen stays turned away and shows no text, and the fingers keep their natural shape. 1080p, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no visible face
```

### C08 · `dock-tree-shadows` · foto del FAQ
**Nota:** usa la variante `-b` (con árbol); el sitio hoy usa la `-a` (muro vacío), así que si la animas cambio la foto.
```
Use the provided image as the starting frame. Preserve the white warehouse wall, the dock door with the white trailer backed in, the yellow bollards, the painted parking lines and the tree at the left with its leaf shadows on the wall, exactly as in the image. One continuous real-time shot from a completely locked-off camera, no zoom, no pan, no reframing. The shadows of the leaves move softly across the wall in the breeze and the sunlight shifts very slightly; no vehicle, person or object moves. 1080p, 24 fps, natural motion blur, subtle organic film grain, true-to-life color, no audio, no text, no logos, no license plates, no people
```

---

## 5. Control de calidad: descarta si falla cualquiera

1. **Ruedas:** giran suave, sin estroboscopio, sin ondular ni cambiar de número.
2. **Camión y remolque:** conservan forma, proporciones y cinta reflectiva; sin vehículos rojos ni de color.
3. **Manos y personas:** cinco dedos estables, nadie con rostro nítido, nadie que aparezca o desaparezca.
4. **Luz:** sin saltos de exposición; el cambio es lento.
5. **Cámara:** sin sacudidas ni zooms; si pedimos cámara fija, tiene que estar fija.
6. **Texto y símbolos:** ninguno en pantallas, papeles, mapas, relojes ni señales.
7. **Final:** el último fotograma queda limpio y sirve como imagen de reposo.
8. **Primer fotograma:** lo más parecido posible a la imagen aprobada (si cambia demasiado el encuadre, descártalo y genera otro intento).

## 6. Qué hago yo al recibirlos

Igual que con los 12 anteriores: recodifico a 1920×1080 (H.264, sin audio, `+faststart`, ≈ 1,3 a 2,4 MB cada uno), uso su primer fotograma exacto como póster de escritorio, mantengo la foto v3 nítida en tablet y móvil, mido el contraste del texto cuando lo hay encima, reviso desbordes y publico. Para V10, V11 y V12 añado primero el montaje de video dentro de las pestañas y para C03, C05, C06, C07 y C08 el montaje en sus contenedores.
