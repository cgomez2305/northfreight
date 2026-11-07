# NorthStar Freight: prompts de imagen que faltan (v3 «Premium Documentary»)

Complementa a `ART-DIRECTION-v3.md`: misma biblia, mismo bloque común, mismo flujo. Cubre solo lo que todavía se ve con el estilo anterior (12 imágenes en 11 espacios) porque la carpeta v3 no traía su versión. Con estas 11 generaciones, el sitio queda 100 % en v3 (más la imagen para redes, que la compongo yo sin IA).

## 0. Qué falta y por qué

| # | Slot | Dónde se ve | Tamaño real que tiene hoy | Proporción a generar |
|---|---|---|---|---|
| BL01 | `blog-cost` | Artículo de costos y todas sus tarjetas | 768 px (blando) | 3:2 |
| BL02 | `blog-relay-requirements` | Artículo de requisitos de Relay | 2560 px | 3:2 |
| BL03 | `blog-relay-load-board` | Artículo del load board y tarjetas | 768 px (blando) | 3:2 |
| BL04 | `blog-lumper-detention` | Artículo de lumper y detention | 2560 px | 3:2 |
| RW01 | `resource-ifta` | Calculadora IFTA | 768 px (blando) | 3:2 |
| RW02 | `resource-rate-per-mile` | Calculadora de rate per mile | 768 px (blando) | 3:2 |
| RW03 | `resource-bol-template` | Plantilla de BOL | 2560 px | 3:2 |
| RW04 | `resource-agreement` | Acuerdo dispatcher-carrier | 768 px (blando) | 3:2 |
| RW05 | `resource-glossary` | Glosario | 768 px (blando) | 3:2 |
| DR01 | `dispatch-room-wide` | «Meet the desk» del home (fachada del video) | 768 px (blando) | 16:9 |
| DR02 | `dispatch-room-maps` | Our desk y «Why a desk» | 1536 px | 3:2 |

Siete de ellas hoy son de 768 px: se amplían y se ven blandas, así que conviene generarlas a 3840 px aunque el estilo ya fuera correcto. Las 4 imágenes para redes de los artículos (`og/post-*.jpg`) las compongo yo con estas portadas; la de `og-default` la recorto del hero nuevo de la home. No hay que generarlas.

## 1. Reglas (resumen; el detalle está en `ART-DIRECTION-v3.md` §2 a §4)

- **Referencia de estilo:** usa como `--sref` / imagen de referencia una imagen v3 ya aprobada, por ejemplo `hero-owner-operators-v3-a`, para que todo conserve el mismo color y la misma luz.
- **Luz:** solo las recetas R1 a R4. **Prohibido:** tormenta, neón, HDR, teal and orange.
- **Palabras a quitar:** `8k`, `ultra-detailed`, `hyper-realistic`, `masterpiece`, `epic`, `perfect`. **Sí ayudan:** `unretouched`, `documentary`, `natural lens imperfections`, `honest textures`.
- **Texto, pantallas y logos:** nada legible. Pantalla apagada, inclinada o con bloques suaves. Papeles con líneas borrosas, nunca palabras. Nada de monedas con números ni sellos.
- **Cola común (al final de TODO prompt):** `unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark`
- **Negativo (si la herramienta lo admite):** `text, letters, numbers, logos, brand names, license plates, watermark, border, frame, cartoon, illustration, 3D render, CGI, plastic skin, airbrushed, over-sharpened, HDR, oversaturated, neon glow, heavy lens flare, fake bokeh blobs, perfect symmetry, readable signage, stock photo look`
- **Variantes:** 4 por prompt, elige una y márcala `-a`; guarda otra como `-b`. Nombre: `<slot>-v3-a.jpg` (por ejemplo `blog-cost-v3-a.jpg`).
- **Salida:** 3840 px en el lado largo, sRGB, JPG calidad 95 o PNG, sin texto ni marco.
- **Espacio para texto:** en estas imágenes el texto va casi siempre debajo (tarjeta) y no encima, pero deja el tercio izquierdo o la franja superior con superficie lisa para poder recortar con libertad.

---

## 2. Portadas de blog (3:2)

### BL01 · `blog-cost` · 3:2
**Uso:** artículo «How much does truck dispatch service cost?». **Luz:** R3. **Nota:** la calculadora no debe tener dígitos legibles; si salen símbolos raros, pídela con la pantalla apagada.
```
Documentary photograph of a worn oak kitchen table in bright soft overcast window light: a basic desk calculator with its display switched off, a loose stack of curled fuel receipts showing only soft blurred printed lines and no legible text, a ceramic mug with a faint stain, a plain ballpoint pen, a smartphone lying face down at an angle, a cotton shirt cuff entering the frame at the edge, shallow depth of field with a softly blurred window in the background, honest paper and wood textures, clean smooth table surface on the left third, photographed on a Sony A7R V with a 50mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, face not visible
```

### BL02 · `blog-relay-requirements` · 3:2
**Uso:** artículo «Amazon Relay requirements». **Luz:** R3. **Sin ninguna marca de Amazon.**
```
Documentary photograph of a steel work table at a loading dock in bright soft daylight coming through the open dock door: a manila folder open to blank pages with only faint blurred ruled lines, a white hard hat and a folded high-visibility vest resting on it, a pen across the papers, a clipboard edge, out-of-focus yellow safety bollards and a dock leveler plate in the background, shallow depth of field, honest scratched metal texture, clean light wall surface on the left third, photographed on a Sony A7R V with a 35mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, no people
```

### BL03 · `blog-relay-load-board` · 3:2
**Uso:** artículo «Amazon Relay load board» y 5 tarjetas. **Luz:** R1. **Pantalla:** solo bloques suaves, sin ninguna interfaz reconocible.
```
Documentary photograph of an open laptop resting on the hood of a clean white sleeper-cab tractor in a freight yard at golden hour, the low side sun raking across the hood, the laptop screen showing only a soft out-of-focus grid of pale rounded blocks with no text and no recognizable interface, a coiled blue air line hanging in the foreground, a long row of white 53-foot trailers softly blurred in the background, warm long shadows on the asphalt, shallow depth of field, honest paint reflections, clear sky area above for cropping, photographed on a Sony A7R V with a 35mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, no people
```

### BL04 · `blog-lumper-detention` · 3:2
**Uso:** artículo «Lumper fees and detention pay». **Luz:** R1. **Reloj:** esfera lisa con marcas, sin números (la IA inventa glifos).
```
Documentary photograph inside a warehouse loading bay in late-afternoon sun entering through the open dock doors: several shrink-wrapped pallets of cardboard boxes waiting in a staging lane, a minimalist industrial wall clock with plain tick marks and no numerals mounted above the dock door, one warehouse worker in a high-visibility vest seen from behind and far away, yellow bollards, a clean concrete floor with long warm shadows, a white trailer backed against the dock, shallow depth of field on the pallets, clean wall space on the left third, photographed on a Sony A7R V with a 35mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, face not visible
```

---

## 3. Portadas de recursos (3:2)

Primeros planos de objetos reales, cada uno con su propio protagonista para que, vistos juntos en `/resources/`, no se confundan.

### RW01 · `resource-ifta` · 3:2
**Uso:** calculadora IFTA y tarjetas de recursos. **Luz:** R3. **Mapa:** formas y líneas de carreteras, sin nombres.
```
Documentary photograph from a 45 degree angle of an open road atlas on a clean desk in bright soft window light, the pages showing a colorful map of interstate highways drawn only as soft lines and shapes with no legible place names or numbers, a fuel receipt with blurred printed lines lying across the atlas, a plain pen, the corner of a small basic calculator at the edge of the frame, shallow depth of field, honest paper texture, clean pale desk surface on the left third, photographed on a Sony A7R V with a 50mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, no people
```

### RW02 · `resource-rate-per-mile` · 3:2
**Uso:** calculadora de rate per mile. **Luz:** R1. **Calculadora:** pantalla apagada o con reflejo, teclas lisas sin símbolos legibles.
```
Documentary photograph taken from the passenger seat of a clean modern sleeper cab at golden hour: a basic calculator with its display switched off and plain keys resting on the dashboard beside a folded paper road map with only soft colored lines, an empty four-lane interstate stretching ahead through the windshield, warm side light on the dash, softly blurred sun-lit fields, a sleeve of a flannel shirt at the edge of the frame, shallow depth of field on the calculator, honest dashboard textures, clean sky area in the upper left for cropping, photographed on a Sony A7R V with a 35mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, face not visible
```

### RW03 · `resource-bol-template` · 3:2
**Uso:** plantilla de BOL. **Luz:** R3 con un toque de R1. **Formulario:** cajas y líneas borrosas, nada legible.
```
Documentary photograph of a clipboard resting on a steel dock plate holding a blank multi-part carbonless shipping form, the colored copies in white, yellow and pink visible along the stacked edges, the printed boxes and ruled lines showing only as soft blurred shapes with no legible words or numbers, a plain pen clipped to the board, a shrink-wrapped pallet softly out of focus behind it, warm low side light from the open dock door, shallow depth of field, honest paper and metal textures, clean concrete floor on the left third, photographed on a Sony A7R V with a 50mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, no people
```

### RW04 · `resource-agreement` · 3:2
**Uso:** acuerdo dispatcher-carrier. **Luz:** R4. **Sin firma legible ni texto.**
```
Documentary photograph of a tidy wooden desk in warm window light with a single practical lamp: a two-page stapled document with only blurred placeholder lines and no legible text, a blank signature line, a metal pen resting across it, a ceramic mug, a hand resting flat on the desk beside the papers with a plain wristwatch and a shirt cuff, shallow depth of field, honest paper grain, clean wall space on the left third, photographed on a Sony A7R V with a 50mm f/1.2 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, face not visible
```

### RW05 · `resource-glossary` · 3:2
**Uso:** glosario. **Luz:** R3. **Tarjetas en blanco** (nada de escritura).
```
Documentary photograph of a fan of blank cream index cards lying on a worn wooden desk in bright soft window light, a weathered field notebook with a leather cover closed beside them, a generic truck key fob on a plain steel ring, a pair of reading glasses, a ceramic mug at the soft edge of the frame, shallow depth of field with a softly blurred window behind, honest paper and leather textures, clean smooth wood on the left third, photographed on a Sony A7R V with a 50mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, no people
```

---

## 4. Escritorio del desk (sin gente y sin oficina «real»)

**Aviso de honestidad:** las dos imágenes de hoy (`dispatch-room-*`) muestran una sala de monitores de noche que puede leerse como la oficina real de NorthStar y tiene siluetas de personas. Estas dos versiones son detalles de un escritorio, sin gente y sin pretender ser un espacio concreto. Siguen siendo ambientales: cuando haya fotos reales del equipo, se sustituyen.

### DR01 · `dispatch-room-wide` · 16:9
**Uso:** fachada del video de «Meet the desk» en el home. **Luz:** R2 (azul limpio con luces encendidas). **Cámara:** documental.
```
Documentary photograph of a quiet modern dispatch desk by a large window at blue hour: a long clean desk with two monitors showing only soft out-of-focus pale blocks and no text, a wireless headset resting on a keyboard, an open spiral notebook with blurred ruled lines, a ceramic mug, a desk lamp switched on casting warm light, through the window a freight yard with softly blurred white trailers and lit dock lights under a clear indigo sky with a warm apricot horizon, an empty ergonomic chair pushed slightly back, no people, shallow depth of field, honest textures, clean dark-glass wall area on the left third for cropping, photographed on a Sony A7R V with a 35mm f/1.4 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, no people
```

### DR02 · `dispatch-room-maps` · 3:2 (con recorte vertical 4:5 centrado)
**Uso:** índice de «What you can count on» en Our desk y la tarjeta 03 de «Why a desk». **Luz:** R4. **Compón con el sujeto en el centro**, porque lo recorto a 4:5.
```
Documentary photograph from a 45 degree angle of a desk surface in warm morning window light: an open road atlas with a hand-drawn blue route line over soft colored shapes and no legible names, a rugged tablet leaning upright showing only a soft blurred grid of pale cells, a wireless headset coiled beside it, a ceramic mug, a pen and a closed notebook, shallow depth of field, honest paper and plastic textures, the main subject centered with clear surface on both sides, photographed on a Sony A7R V with a 50mm f/1.2 lens, unretouched documentary photograph, true-to-life color, natural film grain, no text, no logos, no license plates, no watermark, no people
```

---

## 5. Control de calidad: descarta si falla cualquiera

1. Cualquier texto, número, palabra o símbolo legible (en papeles, calculadoras, mapas, relojes, pantallas).
2. Manos con dedos de más o deformes; rostros visibles (aquí no debe aparecer ninguno).
3. Camiones con ruedas de más, remolques deformes, logos o insignias inventadas; cualquier marca de Amazon.
4. Luz dramática, neón, tormenta o sobresaturación.
5. Objetos flotando, vidrio o metal «de plástico», simetría perfecta.
6. Que se parezca demasiado a otra pieza de la misma página: las portadas de `/resources/` y de `/blog/` se ven juntas, así que cada una necesita un protagonista y un color dominante distintos.

## 6. Entrega y qué hago yo después

Sube las imágenes con el nombre del slot y el sufijo `-v3-a` (y `-b` para la alternativa). Yo hago: gradación suave de marca (la misma de la fase T), tamaños de 640 a 2560 más la de 768, recortes, alt text, retiro de las variantes antiguas, componer las 4 imágenes para redes de los artículos y la de `og-default`, y barrido de rutas y capturas a 1440 y 390 px.
