# Créditos de fotos y videos de stock

Todo el material de stock viene de **Pixabay** (Pixabay Content License: uso comercial permitido, sin atribución obligatoria; no se puede revender el archivo suelto). Se descargó vía la API oficial y se recomprimió (WebP + JPG). Este archivo no se sirve por web (`index.php` bloquea `.md`).

## Fotos (`assets/img/`)

| Archivo base | Pixabay ID | Autor | Origen | Descripción |
|---|---|---|---|---|
| `truck-night-rain-headlights` | 6015583 | amrothman | https://pixabay.com/photos/night-truck-road-big-truck-6015583/ | night, truck, road, big truck, delivery truck, cargo truck, traffic, street, car, vehicles, transportation, transport, delivery, logistics, driving |
| `truck-night-mountain-road` | 2591007 | StockSnap | https://pixabay.com/photos/truck-transportation-road-dark-2591007/ | truck, transportation, road, dark, night, blue road, blue dark, blue truck |
| `team-reviewing-documents` | 3196481 | AymaneJed | https://pixabay.com/photos/laptop-office-hand-writing-3196481/ | laptop, office, hand, writing, business, document, contract, paper, education, work, notebook, data, pen, meeting, recruitment, agreement, paperwork |
| `clipboard-signing-report` | 9894137 | constructionestimate | https://pixabay.com/photos/construction-estimate-9894137/ | construction estimate, estimating services, construction |
| `aerial-trucks-parking-lot` | 4405895 | marcinjozwiak | https://pixabay.com/photos/trucks-depot-warehouse-4405895/ | trucks, depot, warehouse, trucking industry, export, import, logistics, aerial view, transport, distribution, storage, storehouse, container, lorry, cargo, transit, poland, europe, trade, loading, aerial photography |
| `dispatch-desk-headset-monitors` | 2821623 | ernestoeslava | https://pixabay.com/photos/woman-call-police-emergency-911-2821623/ | woman, call, police, emergency, 911, phone, people, communication, female, mobile, person, technology, telephone, office, cellphone, talking, support, service, customer, professional, caucasian, attractive, women, adult, working, lifestyle, casual, mexico, baja california, medic |
| `archive-shelves-documents` | 1850170 | Pexels | https://pixabay.com/photos/archive-boxes-shelf-folders-1850170/ | archive, boxes, shelf, folders, documents, data, storage, rack, row, stock, business, warehouse, depth of field |
| `headset-agent-office` | 10378566 | geralt | https://pixabay.com/photos/call-center-headset-smile-10378566/ | call center, headset, smile, office work, communication, customer support, professional, employee, work, woman, modern office, technology, phone assistance, microphone, young woman, multitasking, office environment, support, phone call, happy, workspace |
| `warehouse-sunlight-dock` | 2696005 | justincobb1 | https://pixabay.com/photos/warehouse-bay-storehouse-industry-2696005/ | warehouse, bay, storehouse, industry, shipping, industrial, distribution, commercial, freight, entrance, loading, dock, storage, building, cargo, sunbeam, sunrise, glowing, garage, brown sunrise, brown garage, brown industry |
| `semi-truck-snowy-mountains` | 2677373 | chapay | https://pixabay.com/photos/truck-freight-transportation-2677373/ | truck, freight, transportation, trailer, moving, mountains, road, semi-trailer, semi, cargo, carrier, delivery, dry van, haul, haulage, highway, scenic, canada, british columbia, blue road, blue truck |
| `white-semi-truck-front` | 5248809 | ArtisticOperations | https://pixabay.com/photos/truck-freight-cargo-delivery-5248809/ | truck, freight, cargo, delivery, container, 18 wheeler, rig, heavy, transportation, logistic, tractor, shipping, semi-truck, transport, trucking, trailer, semi, goods, hard work, cross-country, storage, distribution, commercial, working, employed |

## Videos (`assets/video/`)

| Archivo | Pixabay ID | Autor | Origen | Notas |
|---|---|---|---|---|
| `hero.mp4`, `hero-poster-{1280,1920}.webp`, `hero-poster-m-{440,640,948}.webp`, `hero-poster-sq-{640,1080}.webp` | 209542 | phlyingdoctor | https://pixabay.com/videos/id-209542/ | Autopista vista desde un dron. Fuente: versión `small` de 1920×1080 (60 fps, 39,7 s). Recorte: de 3 s a 16 s, 24 fps, fundido de costura de 1 s (bucle de 12 s), sin audio, H.264 high crf 29 `preset slow`, `+faststart`, 1920×1080, 4,0 MB. El póster es el fotograma del segundo 4,0 de la fuente (primer fotograma del bucle): es el `<img>` del hero (LCP) los recortes `m` (1,17:1, para el marco móvil de ≤760 px; la variante de 440 px va a q66 sin unsharp para no subir el LCP móvil) y `sq` (cuadrado, x 300 a 1380, para 761 a 1024 px) son las fotos de las pantallas pequeñas. |
| `freight-yard.mp4`, `freight-yard-poster-{640,1280,1920}.webp` | 143479 | u_t230tq6rtc | https://pixabay.com/videos/id-143479/ | Patio de carga desde un dron. Fuente: versión de 1920×1080 (24 fps, 9,1 s). Recortado a 8 s con fundido de costura de 1 s para que el bucle no salte, 24 fps, sin audio, H.264 high crf 26 `preset slow`, `+faststart`, 1920×1080, 2,2 MB. El póster es el primer fotograma del bucle (segundo 1,0 de la fuente). |

Las fotos anteriores (`white-semi-*`, `red-semi-*`, `two-semis-*`, `dry-van-*`, `dispatch-team-*`, `truck-oncoming-*`) son stock genérico del andamiaje inicial; su origen no está documentado y se sustituirán por fotos propias del cliente (CLAUDE.md §10).

## Procesado de imágenes (fase F: sin pixelación)

- **Techo de resolución.** La API gratuita de Pixabay solo entrega `largeImageURL` de 1280 px de ancho (los originales de 5000 a 8000 px requieren acceso completo). Las fotos del andamiaje (`red-semi-*`, `two-semis-*`, `dry-van-trailers-*`, `dispatch-team-*`, `truck-oncoming-*`) son de 1024 px. Los marcos se diseñaron con ese techo: ninguna foto se amplía más de x1,10 en pantalla 1x ni x1,6 en retina (DPR 2) en 1440×900 y 390×844.
- **Variantes.** WebP calidad 84, `method=6`, `unsharp` suave (radio 0,6, 40 %) solo en las variantes redimensionadas, anchos 640 / 1024 / 1280 (solo hasta el ancho de la foto de origen: **no se amplía ninguna variante**; los `-1600.webp` eran 1024 ampliado y se borraron).
- **Recortes reales** (`{base}-tall-{ancho}.webp`, proporción 4:5, a alto completo de la foto) para los marcos verticales: onboarding del home (4 fotos), sticky "Why a desk" (3 fotos) y foto del FAQ (`warehouse-sunlight-dock-tall`). Las usa `ns_img` con `art` (solo ≥1025 px) o `base` directo (FAQ).
- **Póster del hero.** `assets/video/hero-poster-*.webp` ya no es la foto `truck-oncoming-dusk-mirror` (1024 px, ampliada x1,4): es un fotograma 1920×1080 del propio video. `truck-oncoming-dusk-mirror` sigue en la página de Owner-operators.
- **Pastillas de foto del titular** (`{pill:...}`): variante `{base}-pill-320.webp` (miden ≤122 px; con 640 pesaban 37 a 52 KB cada una).
- **Móvil.** `sizes` se declara a propósito algo por debajo del ancho útil (`calc(100vw - 72px)`) para que a DPR 2 se elija la variante de 640: ampliación ≈ x1,26 (límite x1,6) y el LCP móvil no sube. En escritorio `sizes` sí es el ancho útil real (cover, alto del marco y `.px` incluidos).
- **CTA final.** La foto ocupa como mucho 1000 px (centrada, fundida con el panel por los lados, sin parallax) para que la foto de 1280 px se amplíe x0,79 (x1,58 en retina) en 1440×900.
- **Fotos que solo se arreglan con material propio del cliente** (≥1800 px de ancho): un CTA final a todo el ancho del panel (~1410 px; con la foto de 1280 px serían x1,10 en 1x y x2,2 en retina), el fondo del hero del home a más de 1440×900 (1080p da x1,6 en retina hasta 1440×900; en 1920×1080 retina sale x1,98) y cualquier foto de 1024 px (`red-semi-*`, `two-semis-*`, `dry-van-trailers-*`, `dispatch-team-*`, `truck-oncoming-*`) si se muestra a más de ~745 px de ancho en retina (tablet).
