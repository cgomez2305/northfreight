# NorthStar Freight Solutions — Website

Cerebro del proyecto. Léelo completo antes de tocar código. Si algo de este archivo contradice una instrucción del usuario en la conversación, manda el usuario; si contradice al código, actualiza este archivo en el mismo cambio.

- **Cliente:** NorthStar Freight Solutions LLC — empresa de truck dispatch remoto (equipo en Venezuela) que trabaja para owner-operators y flotas pequeñas en EE. UU.
- **Responsable del proyecto:** Carlos (ClikRank) — desarrollo web y SEO.
- **Idioma del sitio:** inglés (en-US). Versión en español en fase posterior bajo `/es/`.
- **Idioma de trabajo:** las notas, commits y comentarios internos van en español; todo lo que ve el visitante va en inglés.

---

## 1. Fuentes de verdad

| Recurso | Qué contiene | Enlace |
|---|---|---|
| Lienzo de diseño (Claude Design) | Las 27 páginas en escritorio 1440, tablet 834 y móvil 390, componentes y mega menús. **Es la referencia visual canónica.** | https://claude.ai/artifact/P4WLyiyCPV9wLFSno28gur |
| Prototipo navegable | Exportación HTML del lienzo con menús, calculadoras y filtros funcionando. Sirve para mostrar al cliente y como referencia de comportamiento. | https://claude.ai/artifact/38GbyzWVWaze8JfNA2EqUK |
| Documento de URLs y copy | Estructura de URLs, keywords, titles, metas, H1 y copy de cada página, plan de blog y SEO técnico. **Es la referencia de contenido canónica.** | https://claude.ai/artifact/VtRhBGCJmM5iY1uoFF7kts |
| PDF de concepto del cliente | `NorthStar_Concepto_Web_Referencias.pdf` — idea central, referencias (Ninja, Lean, Logity), bloque de equipo + video, límites de lo que se puede afirmar. | Archivo del cliente |
| Cuestionario al cliente | `NorthStar_Preguntas_Cliente.pdf` — 31 preguntas + 3 decisiones pendientes. | Archivo entregado |

Orden de prioridad cuando hay conflicto: **respuesta del cliente > PDF de concepto > documento de copy > lienzo > este archivo.**

---

## 2. Stack y decisiones técnicas

**Decisión:** PHP 8.2+ sin framework, páginas prácticamente estáticas, desplegado en **Hostinger Business** (hosting compartido, LiteSpeed, SSL incluido). No se usa Next.js, Astro ni WordPress.

Por qué:
- Hostinger Business sirve PHP de forma nativa; no hace falta proceso Node ni build en el servidor.
- PHP solo se usa para **includes, layout, rutas, metadatos y el formulario**. La salida es HTML estático; LiteSpeed lo cachea.
- Cero dependencias en el front salvo GSAP: CSS propio con tokens y JavaScript vanilla.
- Sin base de datos. El contenido vive en archivos PHP de datos (`app/data/*.php`).

Dependencias permitidas:
- **PHPMailer** (vía Composer) para enviar el formulario por SMTP de Hostinger.
- **GSAP 3.12.5 + ScrollTrigger** desde cdnjs (versión fija, con SRI), cargados en todas las rutas (`'motion' => true`).
- Nada más sin acordarlo antes.

Opcional (fase 2): un script `bin/build.php` que pre-renderiza todas las rutas a `dist/*.html` para servirlas como estáticas puras. No es necesario para la primera versión.

---

## 3. Estructura del repositorio

```
/                          ← raíz del repo = public_html en Hostinger
├── .htaccess              # URLs limpias, barra final, HTTPS, caché, compresión, 404
├── index.php              # front controller: resuelve la ruta y renderiza
├── robots.txt
├── composer.json          # solo phpmailer/phpmailer
├── app/
│   ├── config.php         # constantes del sitio (marca, teléfono, correo, dominio, WhatsApp)
│   ├── routes.php         # mapa URL → plantilla + meta + schema + estado (live/draft)
│   ├── helpers.php        # e(), url(), asset(), wa(), partial(), schema_json(), breadcrumbs()
│   ├── data/
│   │   ├── faq.php        # bloques de FAQ reutilizables
│   │   ├── team.php       # personas del equipo (placeholders hasta que el cliente envíe)
│   │   ├── posts.php      # índice del blog (slug, título, categoría, minutos, extracto, fecha)
│   │   ├── glossary.php   # términos del glosario
│   │   ├── equipment.php  # contenido de las páginas por equipo
│   │   └── stages.php     # tabla "A load, stage by stage" (how-it-works)
│   ├── sitemap.php        # genera /sitemap.xml desde routes.php (solo live + sitemap=true) y robots_txt() según ENV
│   ├── llms.php           # genera /llms.txt y /llms-full.txt (formato llmstxt.org) desde routes.php + data; solo rutas live
│   └── forms/
│       ├── lib.php        # csrf_token() sin sesión, saneado, rate limit, log; se incluye siempre
│       ├── contact.php    # POST /contact/: validación, honeypot, límite por IP, PHPMailer
│       └── download.php   # POST de BOL y agreement: valida, avisa por correo y entrega el archivo
├── templates/
│   ├── layout.php         # <html>, <head> (meta, OG, canonical, hreflang, JSON-LD, fuentes), nav, main, cta, footer, mbar
│   ├── partials/
│   │   ├── opsbar.php       # barra superior con relojes ET/CT/MT/PT
│   │   ├── nav.php          # header + enlaces + botón WhatsApp + hamburguesa
│   │   ├── mega-services.php
│   │   ├── mega-resources.php
│   │   ├── mobile-menu.php  # pop-up móvil
│   │   ├── hero.php         # hero interno reutilizable (migas, eyebrow, H1, lead, CTAs, foto o tarjeta a la derecha)
│   │   ├── section-head.php # eyebrow + H2 + lead
│   │   ├── card.php         # tarjeta redondeada (param image para foto arriba; variantes destacada y link)
│   │   ├── faq.php          # <details> + schema FAQPage
│   │   ├── summary.php      # bloque "In short" (param variant 'lead' en el home): respuesta de 2 frases + 3 puntos (extraíble por buscadores e IA)
│   │   ├── breadcrumbs.php  # migas + schema BreadcrumbList
│   │   ├── video.php        # fachada de video con capítulos
│   │   ├── video-band.php   # banda 21:9 con video de fondo, póster y tarjeta de texto
│   │   ├── stage-table.php  # tabla de etapas de una carga
│   │   ├── cta.php          # bloque final "Let's talk about your operation."
│   │   ├── footer.php
│   │   ├── mbar.php         # barra fija móvil (WhatsApp + llamada)
│   │   ├── wa-float.php     # botón flotante de WhatsApp (solo ≥761px)
│   │   └── kit.php          # funciones auxiliares de los partials (iconos SVG, logo, estrella, imágenes)
│   └── pages/               # una plantilla por página (ver §7); subcarpetas con contenido: legal/, posts/, company/
├── assets/
│   ├── css/
│   │   ├── tokens.css     # variables (§5)
│   │   ├── base.css       # reset, tipografía, utilidades
│   │   ├── components.css # nav, botones, tarjetas, FAQ, footer, mbar…
│   │   └── pages/         # un grupo por familia de plantillas: home, services-a, services-b, company, tools, blog (el layout carga solo el de la plantilla)
│   ├── js/
│   │   ├── main.js        # nav, mega menús, pop-up móvil, relojes, formulario
│   │   ├── calc-rpm.js
│   │   ├── calc-ifta.js
│   │   ├── blog-filter.js
│   │   ├── video.js       # fachada + capítulos
│   │   └── motion.js      # GSAP ScrollTrigger: escenas de scroll (todas las rutas)
│   ├── fonts/             # woff2 locales (Barlow, Barlow Condensed, JetBrains Mono)
│   ├── img/               # WebP (640/1024/1280) + fallback JPG, nombres descriptivos en inglés; CREDITS.md con los créditos de Pixabay
│   ├── video/             # hero.mp4 + póster, freight-yard.mp4 + póster (Pixabay, sin audio, 1280x720)
│   └── downloads/         # bol-template.pdf, bol-template.docx, dispatcher-carrier-agreement.docx
└── storage/               # logs del formulario y rate-limit (fuera de git, sin acceso web)
```

Reglas:
- Una plantilla por página en `templates/pages/`. Nada de lógica de negocio en las plantillas: los datos vienen de `app/data/` o del array de la ruta.
- Todo texto dinámico se imprime con `e()` (`htmlspecialchars` con `ENT_QUOTES`, UTF-8).
- Los partials reciben sus datos por parámetro; no leen variables globales.
- CSS sin preprocesador. Si el CSS combinado pasa de ~60 KB, revisar duplicados antes de dividir más.

---

## 4. Rutas, routing y .htaccess

`index.php` lee `$_SERVER['REQUEST_URI']`, normaliza la barra final y busca la ruta en `app/routes.php`. Si no existe → 404 con plantilla propia.

Cada ruta define:
```php
'/amazon-relay-dispatch/' => [
  'template'  => 'relay',
  'title'     => 'Amazon Relay Dispatch Service | NorthStar Freight',
  'meta'      => 'Amazon Relay dispatch for carriers: load board monitoring, block booking, trip records, scorecard follow-up and disputes filed with evidence.',
  'h1'        => 'Amazon Relay dispatch, handled end to end',
  'schema'    => ['Service', 'FAQPage', 'BreadcrumbList'],
  'status'    => 'live',          // live | draft (draft = 404 en producción, visible con ?preview=TOKEN)
  'sitemap'   => true,
  'priority'  => 0.9,
],
```

`.htaccess` debe:
1. Forzar HTTPS y dominio sin `www` (o con, según decida el cliente; uno solo).
2. Añadir barra final a las URLs sin extensión (301).
3. Mandar todo lo que no sea archivo real a `index.php`.
4. Bloquear acceso web a `app/`, `templates/`, `storage/`, `vendor/`, `composer.*`, `.git`.
5. Caché larga para `assets/` (fuentes e imágenes 1 año con nombres versionados o `?v=`), corta para HTML.
6. Compresión (LiteSpeed/Brotli/gzip según lo que active Hostinger).

`/sitemap.xml` se genera desde `routes.php` (solo `status=live` y `sitemap=true`).

---

## 5. Sistema de diseño

**Dirección (decisión de Carlos, 2026-11-03):** estilo del sitio https://fourlines.framer.website/ "muy bien aplicado, clean, sin animaciones 2D". Sitio 100 % oscuro, fotografía protagonista, titulares enormes en Barlow Condensed, formas redondeadas y cero decoración técnica. Se conserva la paleta de marca (navy + cian). Sustituye al sistema anterior (claro/oscuro alternado, chaflanes, Saira/DM Sans, blueprint, GSAP).

### 5.1 Color (tokens)

Todos viven en `assets/css/tokens.css` (**ningún hex ni rgba fuera de ese archivo**).

| Token | Hex | Uso |
|---|---|---|
| `--bg` | `#04101F` | Fondo base de todas las páginas |
| `--panel` | `#0A1D33` | Bandas grandes, tarjetas, footer, paneles de hero interno |
| `--panel-2` | `#0F2A47` | Tarjeta destacada, hover, inputs, mega menús |
| `--navy-deep` | `#04132A` | Texto sobre botón cian |
| `--cyan` | `#06A6CC` | Acento: botones primarios, segunda línea del titular, etiquetas mono |
| `--cyan-light` | `#6FD3EC` | Hover del botón primario y detalles |
| `--text` | `#EEF3F8` | Titulares y texto principal |
| `--text-2` | `#B9C8D8` | Párrafos |
| `--text-3` | `#8FA3B8` | Metadatos, migas, notas |

Alfas: `--glass-strong` (fondo de widget y botones sobre foto), `--cta-veil` (degradado del CTA con foto), `--arrow-ne` (flecha ↗ de los botones), `--btn-cell` (celda de la flecha), `--hairline` (borde fino .10), `--hairline-strong` (.18), `--cyan-line`, `--cyan-tint`, `--overlay`, `--glass-bg`, degradados sobre foto (`--hero-fade-*`), `--arrow-mask` (flecha SVG), `--ph-bg` (marca de placeholder). Contraste: `--text`, `--text-2` y `--text-3` pasan AA (≥ 5,6:1) sobre `--bg`, `--panel` y `--panel-2`; el cian pasa ≥ 5:1 sobre oscuro; sobre cian el texto va en `--navy-deep`. Foco visible: `outline: 2px solid var(--cyan)`.

### 5.2 Tipografía

Fuentes locales en `assets/fonts/` (woff2 latin sin hinting, ~60 KB en total), declaradas en `assets/css/fonts.css`:

| Rol | Familia | Configuración |
|---|---|---|
| Titulares | **Barlow Condensed** 700 / 900 | Frase normal (NO mayúsculas), `letter-spacing` negativo, line-height ~.9 |
| Texto | **Barlow** 400 / 500 / 600 | 16-20 px |
| Etiquetas, datos, migas, chips | **JetBrains Mono** | 11-12 px, MAYÚSCULAS, `letter-spacing` ~.1em |

No usar otras fuentes. La flecha "→" se dibuja con una máscara SVG (`.arrow`, `--arrow-mask`) porque Barlow y JetBrains no la traen.

Escala (`assets/css/base.css`, todo con `clamp()`): `.t-display` hasta 136 px (titular visual del home), `.t-h1` hasta 104 px, `.t-h1-long` hasta 76 px (artículos y legales), `.t-cta` hasta 104 px, `.t-h2` hasta 72 px, `.t-h2-s` hasta 48 px, `.t-h3` 28 px, `.t-prose-h2` hasta 44 px, `.t-lead` 18-20 px, `.t-body` 18 px, `.t-card` 16 px, `.t-eyebrow` 12 px, `.t-meta` 11 px. Un solo H1 por página; las palabras clave del titular van en cian dentro del mismo H (span), nunca como imagen.

### 5.3 Espaciado y layout

- Contenedor: `--max` 1280 px con gutter `clamp(20px, 4vw, 48px)` (**mínimo 20 px**).
- Sección: `padding-block: var(--section-y)` = `clamp(72px, 10vw, 140px)`.
- Bandas grandes (`--panel`) con `--inset` (12-16 px) al borde de la ventana y radio 32.
- Encabezado de sección → contenido: 40 px. Tarjetas: padding 28 px, gap 14 px.
- Botones: alto mínimo 56 px (52 en tarjetas, 48 `--sm`, 60 `--lg`); todo control táctil ≥ 44 px. Inputs 52 px.
- Prosa de artículos y legales: `--prose` 720 px, interlineado 1,7.

### 5.4 Grids y breakpoints

`.g2` (2 col), `.g3`/`.g3h` (3), `.g4` (4 → 2 × 2 ≤1024), `.g5` (5 → 3 + 2), `.g6` (6 → 3); a 1 columna ≤640 (`.g3h` a 2). Siempre `repeat(N, minmax(0,1fr))`. **Ninguna tarjeta huérfana.**

- `≥1025px`: navegación completa con mega menús. `≤1024px`: hamburguesa + pop-up, sin mega menús ni correo de la barra superior.
- `≤760px`: barra fija móvil (`mbar`), body con `padding-bottom`. `≤640px`: grids a una columna, botones principales a ancho completo.

### 5.5 Formas

Sin chaflanes ni `clip-path` decorativos en ningún sitio. Radios (tokens): tarjetas `--r-card` 24, tiles `--r-tile` 16, paneles/bandas/hero/footer `--r-panel` 32, botones, chips, nav y marcadores `--r-pill`. Fotos con radio 24-32. Sin sombras pesadas: borde fino `--hairline` + fondo `--panel`.

### 5.6 Logo

Isotipo (`assets/img/isotype-white.svg`, 40 px) + texto "NORTHSTAR" (Barlow Condensed 900, STAR en cian) y "FREIGHT SOLUTIONS" (mono pequeña), alineados al centro vertical. Centrado óptico del isotipo: `translateY(-5%)` en header/footer; en `favicon.svg`/`favicon.png` el isotipo al 92 % desplazado arriba-derecha. Las imágenes para redes (avatar navy, avatar blanco, PNG transparente) se generaron con ese mismo desplazamiento (no están en el repo).

### 5.7 Movimiento

(Actualizado con las fases E1 y E2: el home llega a ~35 ScrollTriggers y el resto a 2-12; el hero lleva video de fondo y widget que siguen a la escena fijada; el mega menú abierto solo por hover se cierra con Esc.)

**Decisión de Carlos (2026-11-03):** fuera las animaciones PLANAS 2D (plano de ruta, ticker, máscara de titulares por líneas, swoosh, estrella que gira, pulso, cotas, marcas de registro, spotlight, botones magnéticos, barra de lectura): **no reintroducirlas**. SÍ quiere el movimiento de https://fourlines.framer.website/: **GSAP + ScrollTrigger con escenas de scroll con profundidad y parallax, en TODA la web** (`assets/js/motion.js` ~5,5 KB gzip + `assets/css/motion.css`; `'motion' => true` en las 22 rutas, el 404 y las 5 páginas de equipo en draft).

Escenas (todas ligadas al scroll con `scrub`, solo `transform` y `opacity`; nunca animar `filter`, `clip-path`, `box-shadow`, tamaño ni `border-radius` en bloques grandes). Comunes a todas las páginas:
1. **Hero interno** (≥1025 px, sin pin): al salir de pantalla el panel escala 1 → .95 y baja 40 px, el texto sube 60 px y baja a opacidad .15, la foto sigue en parallax (en `/our-desk/` el video escala a .92). En la calculadora de rate per mile los inputs viven en el hero: ahí el panel no escala, solo sube y se atenúa el texto.
2. **Bandas y paneles grandes** (`.section--surface`, `.section--dark`, `.co-band`, `.home-relay`, `.cta`, `.footer`): escala .94 → 1 y y 60 → 0 (`scrub .5`, de `top 90%` a `top 45%`). En móvil solo y 40 → 0 y opacidad, sin escala.
3. **Rejillas 3D** (`.g2`..`.g6`, `.blog-grid`, ≥1025 px): contenedor con `perspective: 1200px`; un trigger por fila; cada tarjeta `rotateX` 10° → 0, y 48 → 0, escalonado .12, `scrub: true`. La opacidad inicial es .95 (no .5): con .5 el contraste del cian pequeño sobre los paneles hacía bajar Accessibility de 100 a 96. Se excluyen el carril del home y cualquier rejilla con form, input, select, textarea o calculadora.
4. **Titulares de sección** (≥1025 px): sube la cabecera entera (`.section-head`) y 40 → 0 (no solo el h2, que se pisaría con el lead).
5. **Parallax de fotos** (`.px`, también en móvil): imagen al 120 % del marco con `overflow: hidden`, yPercent ≈ ±8; los marcos `.px` grandes sueltos además escalan .92 → 1. La geometría de `.px` NO depende de `html.js-motion` (con esa condición la foto daba un salto de zoom al activarse el JS).
6. **Cifras** (`.home-steps`, `.home-figures`, `.sb-hw-steps`; escritorio y móvil): entrada una vez, .7 s, `power3.out`, escalonada .08 s.
Solo en el home: **sticky "Why a desk"** (pin de 2,1 alturas, crossfade de fotos, ≥1025 px y ≥700 px de alto), **líneas de progreso del onboarding** (escalonadas, scrub), **parallax de la banda de video** y el **hero fijado** (pin ~100 % del alto, `scrub .6`: la foto se encoge a .86 con parallax, el texto sube y se desvanece, las dos líneas del titular se separan ∓48 px), **carril horizontal de servicios** (≥1025 px y ≥700 px de alto; las 3 tarjetas con foto se desplazan con rotación ±2°, desfase vertical y parallax horizontal; el foco de teclado en una tarjeta hace scroll al punto del pin; la opacidad .6 → 1 va en la foto, no en la tarjeta) y la **banda de Relay**.

Reglas: `motion.js` descarga GSAP y ScrollTrigger (3.12.5, versión fija con SRI en los `data-*` de su `<script>`) tras `load` + idle para no competir con el LCP; `html.js-motion` la añade `motion.js` solo cuando existen `gsap` y `ScrollTrigger` y no hay `prefers-reduced-motion: reduce`; **el CSS nunca oculta contenido sin esa clase** (sin JS, con GSAP bloqueado o con movimiento reducido todo se ve completo y estático). `gsap.matchMedia()` separa escritorio (pines, 3D) de móvil (parallax, cifras, bandas ligeras; sin pines ni 3D); en móvil el `matchMedia` necesita una condición siempre verdadera para que el callback corra. Máx. ~26 ScrollTriggers (home) y 2-13 en el resto; `will-change` solo mientras la escena está activa; `ScrollTrigger.refresh` con debounce si cambia la altura (FAQ, filas del IFTA, filtro del blog); `.js-motion body { overflow-y: clip }` para que el `y` inicial del footer no alargue la página. No se animan inputs, selects, textareas ni los resultados `aria-live`. Hover/focus: transiciones CSS de 150-250 ms. Pendiente: medir fotogramas en un navegador con GPU real (en Chromium con rasterizado por software el home da ~19 % de fotogramas > 33 ms por el hero fijado y el carril; el resto de páginas ≤ 1,5 %).

---

## 6. Componentes

| Componente | Comportamiento |
|---|---|
| **Ops bar** | Franja fina en mono sobre `--bg` (sin fondo propio): punto cian + "Dispatch desk · On shift" + relojes ET/CT/MT/PT (Intl, cada 20 s) + correo (oculto ≤1024). **Decisión de Carlos: visible.** ⚠️ "On shift" afirma que el desk trabaja; el cliente debe confirmar horario (§10). Flag `SHOW_ON_SHIFT` en `app/config.php`. |
| **Nav** | Píldora flotante sticky (`top` ~12-16 px, radio total, fondo `--glass-bg`). El `backdrop-filter` va en un `::before` o hijo, **nunca en el propio `.header`** (rompería el menú móvil `position: fixed`). Respaldo sólido sin `backdrop-filter` y con `prefers-reduced-transparency`. Logo · Services ▾ · Resources ▾ · How it works · Our desk · Contact · botón pill "WhatsApp us". Mega menús abren al hover y al clic, cierran al salir y con Esc (`aria-expanded`). `--header-h` (96 px) lo suman las anclas y los bloques sticky. |
| **Mega Services** | Panel redondeado centrado (~940 px, `--panel-2`) con 2 columnas (Dispatch services · Operations & back office; chips "By equipment" solo con equipos confirmados) más tarjeta destacada de Amazon Relay (foto arriba, texto debajo). |
| **Mega Resources** | Free tools (IFTA, Rate per mile) · Templates (BOL, Agreement) · Learn (Glossary, Blog) · tarjeta cian de descarga del BOL. |
| **Pop-up móvil** | `role="dialog" aria-modal="true"`, panel desde la derecha, acordeones Services/Resources (`<details>`), enlaces, bloque inferior con WhatsApp y llamada. Bloquea el scroll del body, cierra con Esc, fondo o enlace, devuelve el foco. Mientras está abierto se oculta la `mbar`. |
| **Barra móvil (mbar)** | Fija abajo, solo ≤760 px: "Talk to a dispatcher" (cian, flex 1) + llamada (círculo 56 px). Respeta `env(safe-area-inset-bottom)`. |
| **Botón flotante de WhatsApp** | Fijo abajo a la derecha **solo ≥761 px**, con el mensaje de la ruta. En ≤760 px no se muestra (la `mbar` ya lo trae). Se oculta con el pop-up abierto. |
| **Hero del home** | Fijado con GSAP (escena 1 de §5.7). Casi a pantalla completa, con la píldora del menú encima. Foto (`truck-oncoming-dusk-mirror`, `<img>` con `srcset`, `width/height` y `fetchpriority="high"`, es el LCP) y, SOLO ≥1025 px sin reduced-motion ni saveData, **video de fondo** (`assets/video/hero.mp4`, autopista aérea de Pixabay, 12 s en bucle con fundido de costura, 1,7 MB): el `<video>` viaja vacío en el HTML (`preload="none"`, sin `src`); `main.js` le pone la fuente tras `load` + idle (helper `ns_hero_video()` en `kit.php`), entra con fundido sobre la foto, se pausa fuera de pantalla y la escena GSAP lo encoge con la foto. Degradado desde la izquierda y abajo (contraste ≥ 5,06:1 medido sobre el video; el cian grande de 120 px es el peor caso). Kicker H1 en mono, titular "Booked. Tracked. **Documented.**", lead, CTAs, chips y **widget "Start in WhatsApp"** (abajo a la derecha en escritorio, en el flujo en móvil). En móvil la foto va arriba y se funde con el fondo. |
| **Widget "Start in WhatsApp"** | Pestañas (`role=tablist`, flechas/Home/End) Owner-operator · Small fleet · Amazon Relay + chips multiselección (Load booking, Load tracking, Back office) + línea mono con la vista previa + botón "Open WhatsApp" cuyo `href` reconstruye `main.js` (`https://wa.me/18636513322?text=` + mensaje, p. ej. "Hi, I run an owner-operator business and I'd like help with load booking and load tracking with NorthStar."). Sin JS los controles están `hidden` y queda el enlace por defecto de la ruta. No guarda ni envía datos. Contraste ≥ 6,48:1 (`--glass-strong`). |
| **Hero interno** | Panel redondeado `--panel` con migas mono, H1 grande de dos tonos, lead, CTAs y, a la derecha (debajo del texto en móvil), foto con radio 24 (`image.ratio`, `image.small` para la de 490 px) o tarjeta de datos / vista previa de documento. Cada página usa una foto distinta; ninguna se repite dentro de la misma página. |
| **Resumen "In short"** | Frase-declaración a ancho completo, sin caja (borde izquierdo fino cian en páginas internas; variante `lead` en el home, condensada 32-58 px), con 3 puntos como chips pill mono. |
| **Tarjeta** | Borde `--hairline`, fondo `--panel`, radio 24; número/etiqueta mono (`.card__meta`), título condensado, texto, enlace con flecha. Variante destacada (`--panel-2` o cian); variante con foto (`image`): foto arriba y texto debajo. |
| **Onboarding (home)** | 4 pasos en columnas con foto 4:5 (`.px`, radio 24: team-reviewing-documents, clipboard-signing-report, truck-night-mountain-road, aerial-trucks-parking-lot), línea fina superior con una línea cian que se rellena con el scroll (escalonada), desfase vertical 0/20/40/60 px desde 1025 px y número enorme condensado; debajo la fila "4 / 1 / 0" (steps, desk, forms) con copy ya afirmado. |
| **Why a desk (home)** | H2 "A team, not a single dispatcher" (eyebrow "03 · Why a desk"), entre Relay y el onboarding. 3 ítems con copy ya existente (One channel for every update · A record of every load · A team behind every load), cada uno con enlace focable (Load tracking, Trucking back office, How it works). ≥1025 px y ≥700 px de alto: pin de 2,1 alturas con numeral grande, foto central con crossfade (opacity/scale) y el ítem activo a opacidad 1 (el resto a .65, ≥ 5,3:1); el foco en un enlace lleva el scroll a su estado. Móvil, sin JS y reduced-motion: 3 tarjetas con foto arriba. |
| **Evidencia (home)** | 6 documentos como chips pill y panel de control "Desk log" (barra de 3 puntos, título "Load · Example", filas mono Load con `ph('ID')` / Handled by / Updates, línea de tiempo de 3 hitos con los textos 06:12, 09:40 y 11:55, píldora "POD filed" y chips BOL / POD / Rate con). Etiqueta "Example" visible; ningún dato nuevo. |
| **Banda de video (home)** | Partial `video-band`: banda 21:9 (radio 32) con `freight-yard.mp4` (patio de carga aéreo de Pixabay, 8 s con fundido de costura, 0,78 MB), tarjeta de texto con copy ya existente (11,2:1) y parallax sobre una capa. El video se monta al acercarse (IntersectionObserver, tras idle) y se pausa al salir; en <761 px la tarjeta va debajo; con móvil, reduced-motion o saveData queda el póster WebP. Es un `<p>`, no un H2. |
| **FAQ** | `<details>/<summary>` con marcador + y filas de borde fino. Parámetro `image`: en ≥1025 px foto sticky a la izquierda (radio 24, `.px`) y filas "Q/01" en mono a la derecha; sin `image` queda el layout simple. El layout genera el JSON-LD FAQPage. En producción se descartan las FAQ con placeholders. En el home usa `warehouse-sunlight-dock`. |
| **Video del equipo** | Fachada (imagen + play centrado); el iframe/video se carga al clic. Pie debajo; capítulos como botones. Sin video real el play queda desactivado. |
| **CTA final** | Panel de radio 32 con foto de fondo (`CTA_IMAGE` en `app/config.php` = `truck-night-rain-headlights`, anulable por ruta con `cta_image`; `''`/`false` = panel liso) y degradado radial `--cta-veil`; titular "Let's talk about **your operation.**", texto "No forms first…", botón WhatsApp con el número visible y botón de correo (con fondo `--glass-strong`). Contraste ≥ 5,42:1 medido. |
| **Footer** | Panel grande redondeado: logo + descripción · Services · Company · Talk to the desk (bloque de contacto con WhatsApp y correo visibles como texto, con iconos); enlace "Back to top" a `#site-header`; fila inferior © año, MC#/USDOT (ph), Privacy, Terms. Sin newsletter (no hay herramienta). |
| **Formularios y calculadoras** | Etiquetas mono, inputs de 52 px, borde fino, foco cian; resultados con `aria-live`. |
| **Botón con celda de flecha** | `a.btn--primary` y `a.btn--navy` llevan una celda circular con ↗ (`::after`, tokens `--arrow-ne`, `--btn-cell`); al hover la celda gira 45° y el botón sube 2 px. Los botones de formulario no la llevan; en el botón navy sobre la banda cian de `/resources/` se conserva la flecha en el texto. La `mbar` no lleva icono de WhatsApp (a 360 px no cabe con la celda). |
| **Chip de sección** | `.t-eyebrow` es un chip global (borde `--hairline-strong`, pill, punto cian, mono 11 px, texto `--text-2`); dentro de `.card` no lleva chip. Los eyebrows del home se numeran 01 a 07. |
| **Pills de foto en titulares** | Token `{pill:base}` de `ns_rich()`: foto decorativa inline en un titular (≈1,6em × .8em, radio pill, `aria-hidden`, `alt=""`; más pequeña en ≤640 px). No cambia el texto accesible del H2 (usado en "Three fronts. One dispatch desk."). |
| **Tabla "A load, stage by stage"** | Partial `stage-table` + datos en `app/data/stages.php`: `<table>` real con caption (Stage · What the desk does · What you get · Kept on file; Booking, Pickup, In transit, Delivery, Settlement); en ≤760 px cada fila es tarjeta. Cada celda reutiliza una afirmación ya presente en el sitio. Está en `/how-it-works/`. |
| **Our desk: reglas y filas** | "Three rules we work by" (3 tarjetas altas) y "What you can count on" (filas W/01 a W/04 con flecha que enlazan a owner, tracking, back office y how-it-works). |

Reglas de UI no negociables:
1. **Texto sobre foto o video solo con degradado que garantice contraste AA medido (≥ 4,5:1)**: hero del home (también sobre el video), widget del hero (≥ 6,48:1), CTA final con foto (≥ 5,42:1) y tarjeta de la banda de video (≥ 11,2:1). En tarjetas y heros internos el texto va debajo o al lado de la foto. Excepción: botón play del video.
2. Ningún texto a menos de 20 px del borde de la pantalla.
3. Sin scroll horizontal entre 360 y 1440 px. **Se comprueba con `overflow-x: clip` DESACTIVADO** (inyectar `html,body,main{overflow:visible!important}` y medir `scrollWidth`): `clip` lo oculta en navegadores modernos pero Safari < 16 y algunos navegadores integrados no lo soportan. `base.css` lleva `@supports not (overflow-x: clip) { body { overflow-x: hidden } }` y `overflow-x: hidden` + `overscroll-behavior-x: none` en `<html>`. Probar con emulación móvil táctil y `scrollTo(400, 0)`, y medir los elementos `position: fixed` (mbar, botón flotante) contra el viewport.
4. Sin tarjetas huérfanas en ningún breakpoint.
5. Sin marcas de agua detrás de títulos ni botones.
6. Contraste AA mínimo (etiquetas mono pequeñas incluidas). Foco visible en todo control.

---

## 7. Mapa del sitio, URLs y SEO

Una URL por intención de búsqueda, rutas cortas a un nivel, sin `/services/`. Datos de DataForSEO (Google US, inglés, oct 2026). Vol. = búsquedas/mes, KD = dificultad 0–100.

### 7.1 Páginas principales (fase 1)

| URL | Plantilla | Keyword principal | Vol. | KD | Estado |
|---|---|---|---|---|---|
| `/` | home | truck dispatch services (+ trucking dispatch services 720, truck dispatch company 390) | 590 | 20 | Diseñada |
| `/dispatch-for-owner-operators/` | owner | dispatch services for owner operators | 260 | 11 | Diseñada |
| `/amazon-relay-dispatch/` | relay | amazon relay dispatch (+ amazon relay carrier 590) — **diferencial** | 40 | 0 | Diseñada |
| `/dry-van-dispatch/` | dryvan | dry van dispatch | 10 | 0 | Diseñada |
| `/load-tracking/` | tracking | truck load tracking service | — | — | Diseñada |
| `/trucking-back-office/` | backoffice | trucking back office services | 10 | 4 | Diseñada |
| `/how-it-works/` | how | how does truck dispatching work | — | — | Diseñada |
| `/our-desk/` | desk | marca (E-E-A-T, video) | — | — | Diseñada |
| `/contact/` | contact | truck dispatch company near me | 50 | 20 | Diseñada |
| `/privacy-policy/` · `/terms/` | legal | — | — | — | Diseñadas (texto pendiente de abogado) |

### 7.2 Recursos (fase 1 — decisión de Carlos: se construye la maqueta completa, Resources y Blog activos)

| URL | Plantilla | Keyword | Vol. |
|---|---|---|---|
| `/resources/` | resources | — (hub del mega menú) | — |
| `/resources/bol-template/` | doc-bol | bol template · bill of lading template | 3.600 + 1.900 |
| `/resources/ifta-calculator/` | tool-ifta | ifta calculator | 1.000 |
| `/resources/rate-per-mile-calculator/` | tool-rpm | rate per mile calculator | 170 |
| `/resources/dispatcher-carrier-agreement/` | doc-agreement | dispatcher carrier agreement | 110 |
| `/resources/glossary/` | glossary | what is a dry van (1.900) y demás términos | — |

Fase 2 del glosario: una URL por término (`/resources/glossary/dry-van/`), como hace ntslogistics.

### 7.3 Blog

**Decisión de Carlos: blog activo (`status=live`, en el sitemap) y con `index`.** Cuatro artículos de la maqueta; `how-to-dispatch-a-truck` no existe en la maqueta y no se registra hasta escribirlo.

| URL | Keyword | Vol. | Enlaza a |
|---|---|---|---|
| `/blog/` | — | — | — |
| `/blog/how-much-does-truck-dispatch-cost/` | how much does dispatch cost (pico oct–nov) | 1.300 | `/`, `/dispatch-for-owner-operators/` |
| `/blog/amazon-relay-requirements/` | amazon relay requirements | 1.300 | `/amazon-relay-dispatch/` |
| `/blog/amazon-relay-load-board/` | amazon relay load board | 9.900 | `/amazon-relay-dispatch/` |
| `/blog/lumper-fees-detention-pay/` | lumper fee (1.900) · detention pay trucking (140) | — | `/load-tracking/`, `/trucking-back-office/` |
| `/blog/how-to-dispatch-a-truck/` | how to dispatch a truck | 320 | `/how-it-works/` — **pendiente de escribir** |

Siguientes temas: freight factoring (880, CPC alto), amazon relay review (140), freight broker vs dispatcher (110).

### 7.4 Páginas por equipo (solo si el cliente confirma que las despacha)

| URL | Keyword | Vol. |
|---|---|---|
| `/box-truck-dispatch/` | box truck dispatch service (+ box truck dispatch 480) — mejor ratio volumen/KD del estudio | 320 |
| `/hotshot-dispatch/` | hotshot dispatch services | 140 |
| `/flatbed-dispatch/` | flatbed dispatch services | 50 |
| `/power-only-dispatch/` | power only dispatch | 30 |
| `/reefer-dispatch/` | reefer dispatch | 30 |

Están diseñadas, pero se dejan con `status => 'draft'` hasta tener confirmación. Una página de un servicio que no se presta genera leads que no se pueden atender y va contra el PDF de concepto.

### 7.5 Evitar

`truck dispatch jobs` (6.600), `truck dispatch training/course` (4.400) y el término `truck dispatch` solo (5.400, intención mezclada). Traen gente buscando empleo o curso, no clientes.

### 7.6 SEO técnico

- **Title** ≤ 60 caracteres, **meta description** ≤ 155. Los textos exactos están en el documento de copy.
- **Canonical** en todas las páginas; URLs siempre con barra final.
- **JSON-LD:**
  - Home: `Organization` + `ProfessionalService` (nombre, logo, teléfono, correo, `areaServed: US`; dirección solo si el cliente la confirma).
  - Servicios: `Service` con `provider` = NorthStar.
  - Todo bloque de preguntas: `FAQPage`.
  - Servicios, recursos y blog: `BreadcrumbList`.
  - Artículos: `Article` (autor "NorthStar dispatch desk", fecha real).
  - `/our-desk/`: `VideoObject` con capítulos (`Clip`) cuando exista el video.
- **Open Graph + Twitter card** con imagen 1200×630 por plantilla.
- **Enlazado interno:** el home enlaza a las 5 páginas de servicio; cada servicio enlaza al home, a `/how-it-works/` y a un artículo; cada artículo enlaza a su servicio con la keyword como anchor.
- **hreflang** `en-us` / `es-us` cuando exista `/es/` (fase 2, keywords en español aún no investigadas).
- `sitemap.xml` generado y `robots.txt` con la ruta al sitemap.
- **GEO / visibilidad en IA (preparado, 2026-11-02):**
  - `/llms.txt` y `/llms-full.txt` se generan desde `routes.php` + `app/data/` (solo `status=live`, sin placeholders). En staging llevan `X-Robots-Tag: noindex`.
  - `robots.txt` en producción: grupo `*` + bots de **búsqueda/respuesta de IA** (OAI-SearchBot, ChatGPT-User, PerplexityBot, Claude-SearchBot, Claude-User) permitidos + bots de **entrenamiento** (GPTBot, ClaudeBot, Google-Extended, Applebot-Extended, CCBot) según `AI_TRAINING_BOTS` (`'allow'` por defecto | `'block'`). En staging: `Disallow: /` para todos.
  - Bloque **"In short"** (`partial('summary')`) en el home, owner, relay, dryvan, tracking, backoffice y how-it-works: definición directa + 3 puntos, solo con lo que la página ya afirma (§8.2). Cada resumen abre con la keyword principal de la página en su forma exacta (p. ej. "Dispatch services for owner-operators…", "Truck load tracking means…", "Here is how truck dispatching works…") y el home añade las secundarias "truck dispatch company" y "trucking dispatch services". Los textos están en cada plantilla; Carlos debe validarlos.
  - **Regla al tocar copy:** title, meta, H1 y H2 no se reescriben por estilo. Antes de cambiar cualquier texto, y después, comparar title/meta/H1/H2 y el conteo de keywords de las 22 rutas (el cambio de guiones del 2026-11-02 dio cero diferencias). Title ≤ 60, meta ≤ 155, un solo H1, sin title/meta ni H2 repetidos dentro de una misma página.
  - **Decisiones SEO de 2026-11-02 (Carlos: "decide lo mejor para el SEO"; difieren del documento de copy, que queda desactualizado en esto):**
    - Home: title "Truck Dispatch Services | Remote Dispatch Desk | NorthStar" y meta con "dispatch company". Ya no repite "Owner-Operators" en el title, para no competir con `/dispatch-for-owner-operators/`. El H1 (el kicker pequeño) no cambia. El H2 de Relay del home pasó a "Amazon Relay dispatch for carriers who move fast." para no duplicar el H1 de `/amazon-relay-dispatch/`.
    - Owner: meta con la keyword exacta. Load tracking: meta con "Truck load tracking". Our desk: meta sin "Watch how we work" (no hay video).
    - How it works: H1 = "How truck dispatching works with NorthStar"; el H1 anterior ("How we plug into your operation") pasó a eyebrow del hero y sigue como H2 del home.
    - H2 con keyword: preguntas frecuentes ("Dry van dispatch questions", "Amazon Relay dispatch questions"…), "What we manage in your Amazon Relay account", "Five back office jobs off your desk", y H2 propios para IFTA, rate per mile, BOL y agreement (antes idénticos entre páginas).
    - El rótulo "In short" es un `<p>`, no un H2, para no ensuciar el índice de encabezados.
    - Anclas con keyword: el artículo de costes enlaza al home ("truck dispatch services") y a owner ("dispatch services for owner-operators"); el de lumper enlaza a "trucking back office".
  - **H2 añadidos en las fases E1 y E2 (únicos en su página):** home "A team, not a single dispatcher" (el ítem 03 de esa sección se titula "A team behind every load"); `/how-it-works/` "A load, stage by stage"; `/our-desk/` "Three rules we work by" y "What you can count on". Title, meta, H1 y JSON-LD de las 22 rutas no cambiaron.
  - JSON-LD: `Organization` + `ProfessionalService` + `WebSite` (con `contactPoint`, `knowsAbout`, `hasOfferCatalog`) en el home; `Service`/`FAQPage`/`BreadcrumbList` en servicios; `Article` con `image` y `dateModified` en artículos; `DefinedTermSet` en el glosario. `legalName`, `address`, `sameAs` y `foundingDate` solo se emiten si existen `LEGAL_NAME`, `ADDRESS`, `SAME_AS`, `FOUNDING_DATE` en `app/config.local.php` (ver `config.local.example.php`); `ADDRESS` también se imprime (NAP) en el footer. `datePublished` solo sale cuando `app/data/posts.php` tiene `published` (YYYY-MM-DD real): no se inventan fechas.
- Breadcrumbs visibles en todas las páginas internas.

---

## 8. Contenido

### 8.1 Idea central (del PDF del cliente)

> Las incidencias se gestionan con evidencia. Y las operaciones, en equipo.

Dispatch, seguimiento y administración se presentan como **un solo equipo**. La confianza se construye mostrando personas reales y evidencia del trabajo, y termina en una conversación por WhatsApp.

Referencias y qué tomar de cada una:
- **Ninja Dispatch** → precisión: explicar una necesidad concreta, responsables y procedimientos; bloque de equipo + video con capítulos. No copiar su cobertura, acreditaciones, tarifas ni promesas de dedicación.
- **Lean Solutions** → estructura: agrupar dispatch, seguimiento y back office como oferta ordenada. No presentar plataformas o IA que NorthStar no tenga.
- **Logity Dispatch** → claridad: páginas de servicio claras, acceso directo a WhatsApp, incorporación en pocos pasos. No copiar métricas, garantías de ingresos ni porcentajes.

### 8.2 Reglas de contenido

1. **Solo se afirma lo que NorthStar hace hoy.** Lo no confirmado va como `[PLACEHOLDER]` y no se publica.
2. Sin métricas, testimonios, garantías ni porcentajes inventados o copiados de la competencia. Las cifras necesitan respaldo del cliente.
3. Sin promesas de cobertura (24/7, nocturna, tiempo de respuesta) hasta que el cliente confirme horario.
4. El contacto principal es WhatsApp **sin formulario previo**. El formulario es secundario, en `/contact/`.
5. Voz: directa, de operador a operador, en segunda persona, frases cortas. Términos del oficio (rate con, POD, detention, deadhead, scorecard) usados con naturalidad.
6. Las herramientas y plantillas llevan aviso: los resultados son estimaciones, y el agreement no es asesoría legal.
7. **Sin guiones largos (— ni –) en el texto visible** (decisión de Carlos: el copy debe sonar natural). Se usan punto, coma, dos puntos, paréntesis o "to" en rangos ("3 to 4 min"). Los eyebrows numerados llevan "·" ("01 · What we manage"). Única excepción: el "—" de las calculadoras y del desk log como valor vacío. Comprobar con `grep -rnE '—|–' templates app --include=*.php` (ignorar comentarios).

### 8.3 Contacto

- WhatsApp: **+1 863-651-3322** → `https://wa.me/18636513322?text=` + mensaje codificado.
- Mensaje por defecto: `Hi, I'd like to talk about dispatch for my fleet with NorthStar.` Cada página de servicio tiene su variante (owner-operator, Relay, dry van, back office, tracking, equipo).
- Correo: `nfreightsolutionsllc@gmail.com` (pendiente: posible correo con dominio propio).
- El número y el correo siempre visibles como texto, no solo como enlace.

### 8.4 Placeholders vigentes en el diseño

Medidos con la auditoría sobre las 22 rutas live (staging). Se pintan con `ph()`, que en `ENV=production` devuelve cadena vacía y descarta las FAQ que los llevan, para que nada con corchetes llegue a producción:

| Placeholder | Dónde |
|---|---|
| `MC# pending · USDOT pending` | footer de las 22 páginas |
| `0:00` (×3) | home y Our desk (tiempos de capítulos del video) |
| `Team photo`, `Name`, `Role`, `One line on experience` (×4 cada uno) | Our desk |
| `YOUR FEE — % of gross or flat weekly` | home, Owner (FAQ de coste) |
| `confirm equipment types` | home, Owner |
| `confirm terms: weekly, monthly, cancel anytime` | home (FAQ del contrato) |
| `confirm how account access is handled`, `confirm` | Relay |
| `confirm load boards`, `confirm` | Dry van |
| `ID` | home (tarjeta "Desk log · Example") y Load tracking (desk log de ejemplo) |
| `hours · time zone`, `other confirmed types` | Contact |
| `date`, `Business address`, `Cookie and analytics tools…`, `Limitation of liability clause…` | Privacy y Terms (texto del abogado) |
| `Publish date` | los 4 artículos del blog |
| `NorthStar pricing model…` | artículo de costes |
| `Current minimum limits — verify on relay.amazon.com…` | artículo de requisitos de Relay |

Antes de publicar en producción: auditar las rutas con `ENV=production` y comprobar que no quedan huecos visibles; el sitio NO debe salir a producción mientras las páginas legales y el artículo de costes tengan huecos.

---

## 9. Funcionalidad

| Función | Implementación |
|---|---|
| **Rate per mile** (`calc-rpm.js`) | Entradas: pago de la carga, millas cargadas, deadhead, precio del combustible, MPG, extras. Salidas: RPM cargado, RPM total, costo de combustible, neto tras combustible y extras, neto por milla total. Valores de ejemplo marcados como "example values". Recalcula en `input`. |
| **IFTA** (`calc-ifta.js`) | Filas por estado (estado, millas, galones comprados, tasa opcional) con agregar/quitar. MPG de flota = millas totales ÷ galones totales; galones gravables = millas del estado ÷ MPG; neto = gravables − comprados; impuesto = neto × tasa si hay tasa. Resumen: millas, galones, MPG, impuesto neto. Tabla con scroll horizontal propio en móvil. Las tasas no se precargan (cambian cada trimestre). |
| **Filtro del blog** | Chips All / Dispatch / Amazon Relay / Paperwork & pay con `aria-pressed`. Sin JS se ven todas las tarjetas. |
| **Capítulos del video** | Botones con estado activo; con el video real, saltan al minuto. |
| **Formulario de contacto** | `POST /contact/` → `app/forms/contact.php` (la ruta declara `'form' => 'contact'`). Token CSRF **sin sesión** (HMAC con `APP_SECRET`, válido 2 h; así no hay cookies que rompan la caché de LiteSpeed), honeypot `website`, tiempo mínimo 3 s, límite por IP (5/hora, hash en `storage/ratelimit/`), validación servidor, PHPMailer por SMTP con credenciales de `app/config.local.php`. Sin credenciales guarda el mensaje en `storage/mail.log` y responde éxito. Éxito → 303 a `/contact/?sent=1`; errores → 422 en la misma página. Campos: name, company, MC#, trucks, equipment, message. **Sin campo de email/teléfono de respuesta (así es la maqueta): pendiente de decidir con Carlos.** |
| **Descargas (BOL, agreement)** | `'form' => 'download'` → `app/forms/download.php`: nombre + correo + formato → entrega `assets/downloads/<archivo>` con `Content-Disposition` y avisa por correo; si el archivo no existe devuelve 422 con mensaje claro (no 500) y registra la solicitud en `storage/downloads.log`. |
| **Newsletter del blog** | Solo maquetado. Conectar cuando el cliente elija herramienta. |

JavaScript: GSAP en todas las rutas (descarga diferida), módulos pequeños, `defer`, cada uno se carga solo en la página que lo usa (`main.js` en todas). El sitio funciona y se lee sin JS.

---

## 10. Pendientes y decisiones abiertas

Del cuestionario al cliente (las respuestas desbloquean la publicación):
- Equipos que despachan → define qué páginas de equipo pasan a `live` y los chips del mega menú.
- Alcance de la gestión de Amazon Relay y del back office.
- Horario y cobertura → decide si se mantiene "On shift" y los relojes en vivo.
- Modelo de cobro y si se publica.
- MC#/USDOT (o quitarlos del footer), nombre legal, dirección.
- Equipo (nombres, cargos, fotos), portavoz y grabación del video.
- Evidencia autorizada, fotos propias (reemplazar stock), testimonios con permiso.
- Dominio y correo definitivos; destino del formulario; analítica.

Decisiones de diseño/estrategia por cerrar:
1. **Mensaje del hero:** A) "Incidents are handled with evidence. And operations, as a team." (PDF) · B) "Booked. Tracked. Documented." (actual).
2. ~~**Estructura de la v1**~~ **Resuelto por Carlos: completa, tal cual la maqueta (con Resources y Blog).**
3. **Selector ES / EN:** el PDF lo incluye; se quitó del diseño porque no había destino. Volver a ponerlo cuando exista `/es/`.
4. **Porcentajes:** el artículo de costos menciona un rango de mercado (5–10%); el PDF pide no usar porcentajes ajenos. Decidir si se quita.
5. **Hero del home bajo el PDF:** cambiar los chips por "Coordinated loads · Organized information · Constant follow-up" si se elige la opción A.

Pendientes de SEO/GEO que dependen del cliente (la parte técnica ya está lista, ver §7.6): fechas reales de publicación de los 4 artículos (`published` en `posts.php`), `LEGAL_NAME`/`ADDRESS`/`FOUNDING_DATE`/`SAME_AS` (perfiles oficiales), ficha de Google Business Profile, decidir `AI_TRAINING_BOTS`, validar los textos "In short", enviar el sitemap a Search Console y pasar `ENV` a `production`.

Pendientes técnicos conocidos:
- ~~Rehacer la tarjeta destacada del mega menú de Services sin texto sobre la foto.~~ Hecho (foto arriba, texto debajo).
- Escribir `/blog/how-to-dispatch-a-truck/` (no está en la maqueta; no se registra hasta tenerlo).
- Archivos reales del BOL (PDF y DOCX) y del agreement (DOCX revisado por abogado) en `assets/downloads/`. El agreement ofrece también "PDF — read only": decidir si se prepara o se quita la opción.
- `title`/`meta`/`h1` de Resources, Blog, herramientas y páginas de equipo no están en el doc de copy: los redactó el andamiaje con la keyword (`// TODO verificar con doc de copy` en `app/routes.php`). Carlos debe validarlos.
- Fotos y videos: son de stock (Pixabay vía API, licencia comercial sin atribución, créditos en `assets/img/CREDITS.md`) y hay que sustituirlos por material propio del cliente (el techo de la API gratuita es 1280 px de ancho). `white-semi-truck-front` muestra texto legible en el trailer. Pendiente histórico (fotos del andamiaje inicial): reemplazar todas las fotos (stock genérico): la del equipo con auriculares figura como "NorthStar team" en Our desk y es la primera. Dos fotos son de solo 490 px y se amplían en el hero. Algunas muestran marcas de terceros legibles (Trans Chicago, Penske, Wabash, Kreilkamp).
- El video del equipo no existe: el play queda desactivado ("Video coming soon" solo para lector de pantalla). Cuando haya URL, el partial `video` recibe `data-video-src`.
- Páginas de equipo (5) en `draft`. Sus migas llevan "Services", que apunta a `/dispatch-for-owner-operators/` porque no existe un hub de servicios.
- Error visual en formularios: no hay token `--danger` (no se añadió color sin permiso); los errores usan navy con borde grueso.
- Frase "Estimate only…" en las calculadoras (§8.2.6): no está en la maqueta; confirmar el texto.
- Newsletter del blog: solo maquetado, `method="get"` y el input sin `name`.
- `.htaccess` sin probar (el servidor de desarrollo `php -S` no lo lee): probar en staging de Hostinger (HTTPS, barra final, bloqueo de `app/`, caché, compresión).
- Plantilla de correo y credenciales SMTP reales: crear `app/config.local.php` en el servidor desde `app/config.local.example.php` (`APP_SECRET`, `SMTP_*`, `MAIL_TO`, `PREVIEW_TOKEN`, `ENV`, `DOMAIN`).


---

## 11. Rendimiento y accesibilidad

- Fuentes en local (`woff2`, subset latino sin hinting, ~60 KB: Barlow 400/500/600, Barlow Condensed 700/900, JetBrains Mono variable), `font-display: swap`, `preload` de Barlow Condensed 900 y Barlow 400. Para otro peso o glifo, regenerar con `fontTools.subset` desde Google Fonts (ver comentario en `assets/css/fonts.css`).
- **Presupuesto de CSS:** ≤ 18 KB gzip por página (fonts + tokens + base + components + grupo de página). Tras el rediseño: 12–13 KB.
- Imágenes WebP con `srcset`/`sizes`, `width`/`height` explícitos, `loading="lazy"` salvo la imagen del hero (`fetchpriority="high"`).
- Video con fachada: nada de iframe hasta el clic. Los videos de fondo (hero, banda) se montan tras `load` + idle (o al acercarse), solo en escritorio y sin reduced-motion ni saveData; el hero es la foto hasta entonces. `.htaccess` sirve mp4/webm con caché de 1 año (comprobar `Accept-Ranges` en el servidor real: `php -S` no lo soporta).
- Objetivo Lighthouse móvil: Performance ≥ 90, Accessibility ≥ 95, Best Practices ≥ 95, SEO 100. LCP < 2.5 s, CLS < 0.1. **Medido en el sitio publicado con las escenas GSAP activas (2026-11-03):** con el movimiento en toda la web: home Performance 100 (LCP 1,7 s, TBT 10 ms), artículo de costes 99 (LCP 1,4 s), Amazon Relay 95-97 (LCP 2,2-2,4 s, el más justo: su LCP es la foto del hero de 490 px; idéntico en local antes y después de las escenas, así que no lo causa GSAP); CLS 0; Accessibility 100; Best Practices 100. **SEO sale 69 solo porque el sitio está en staging con `noindex`** (en producción es 100). **GSAP y ScrollTrigger se descargan desde `motion.js` tras `load` + idle, no con `<script defer>` en el `<head>`:** cargados con `defer` competían con la foto del hero y el LCP del home subía de 1,6 a 3,1 s. Repetir la medición tras cada cambio visual, sobre el sitio publicado y con la página completa (§13).
- HTML semántico: `header`, `nav`, `main`, `section` con encabezado, `footer`; `button` para acciones, `a` para navegación.
- `aria-label` en botones de solo icono; `aria-expanded`/`aria-controls` en menús; `aria-live` en resultados de calculadoras.
- Contraste AA en todo el texto; foco visible en cian.

---

## 12. Despliegue (Hostinger Business)

1. PHP 8.2+ desde hPanel; extensiones `mbstring`, `openssl`, `intl`.
2. Repositorio en GitHub → **Git deploy** de hPanel a `public_html` (rama `main`). `vendor/` se instala con Composer en el servidor por SSH o se sube en el despliegue.
3. SSL activo y redirección HTTPS.
4. Cuenta de correo SMTP en Hostinger para el formulario; credenciales en `app/config.local.php` (fuera de git), nunca en el código.
5. LiteSpeed Cache: caché de página para HTML, excluyendo `/contact/` en POST.
6. Google Search Console + sitemap enviado; analítica según respuesta del cliente.
7. Entorno de staging en un subdominio con `noindex` antes del dominio final.

### Estado del despliegue (2026-11-02)

- **Sitio:** `https://northstarfreightsolutions.us` (dominio `.us` comprado el 2026-10-29), en el plan Business de la cuenta, usuario de hosting `u480515229`, raíz `public_html`.
- **Primer despliegue:** paquete subido por archivo (archivos commiteados + `vendor/` de PHPMailer sin `.git`, sin `CLAUDE.md` ni `.claude/`).
- **Git:** despliegue automático conectado a `cgomez2305/northfreight`, rama **`main`** (creada desde `claude/tender-brown-canrk9`). Cada push a `main` actualiza el sitio. Se trabaja en una rama de desarrollo y se fusiona a `main` para publicar. `vendor/` y `app/config.local.php` NO están en Git y viven solo en el servidor: tras un cambio de dependencias hay que resubir `vendor/` (o ejecutar `composer install` por SSH).
- **`app/config.local.php` del servidor:** `ENV=staging` (noindex + `robots.txt` Disallow), `DOMAIN`, `PREVIEW_TOKEN` (para ver las rutas draft con `?preview=`) y `APP_SECRET`. **Sin SMTP:** el formulario guarda los mensajes en `storage/mail.log` hasta que se añadan `SMTP_*` y `MAIL_TO`.
- **Pasar a producción:** cambiar `ENV` a `production` (quita noindex y abre robots), con los placeholders resueltos (§8.4) y los archivos de `assets/downloads/` subidos.
- **Verificado en vivo:** 22 rutas live en 200, 404 propio, HTTPS y barra final con 301, `app/`, `vendor/`, `storage/`, `templates/` y `.git` con 403, draft solo con token, `.htaccess` funcionando.
- **Sitio sobrante:** existe un sitio vacío en `wheat-lyrebird-274919.hostingersite.com`, creado por error antes de conocer el dominio. No tiene contenido y puede borrarse desde hPanel.

---

## 13. Checklist antes de dar una página por terminada

- [ ] Coincide con el artboard del lienzo en 1440, 834 y 390 (y se ve bien en 360).
- [ ] Un solo H1; títulos con la escala de §5.2; nada fuera de escala.
- [ ] Gutter ≥ 20px, sin scroll horizontal, sin tarjetas huérfanas.
- [ ] Ningún texto sobre imágenes.
- [ ] Title, meta, canonical, OG y JSON-LD correctos; migas visibles.
- [ ] Enlaces internos según §7.6; ningún `href="#"` vacío.
- [ ] Sin placeholders si la página es `live`.
- [ ] WhatsApp con el mensaje correcto para esa página.
- [ ] Funciona sin JS; teclado y lector de pantalla probados en menús y pop-up.
- [ ] `prefers-reduced-motion` respetado.
- [ ] **Página completa antes de medir o publicar:** el HTML termina en `</html>`, no contiene `Fatal error`, `Warning`, `Stack trace`, `/home/` ni `public_html`, e incluye `js/main.js`. Lighthouse NO detecta JS roto (una página sin JS puntúa más alto). Comprobar con las 22 rutas del sitemap.
- [ ] Lo que depende de JS funciona de verdad, con emulación móvil táctil: menú móvil (abrir, Esc, cerrar), mega menús, calculadoras, filtro del blog, reloj, `html.js-motion`.
- [ ] Lighthouse móvil dentro de los objetivos de §11.

---

## 14. Cómo trabajar en este repo (para Claude)

- Antes de maquetar una página, revisar su artboard en el lienzo y su sección en el documento de copy.
- Reutilizar partials y clases existentes antes de crear otros nuevos; si hace falta uno nuevo, documentarlo en §6.
- No inventar contenido ni datos del cliente. Si falta algo, usar `[PLACEHOLDER]` y anotarlo en §8.4.
- **Nada de código de pruebas en el repo.** Los arneses de medición (parámetros `?x=`, variables `$X`, marcas `XHARNESS`/`XH`, rutas del scratchpad) no se versionan nunca: dos de ellos llegaron a `main` dentro de un commit WIP y uno dejó el sitio publicado con un error fatal visible y sin JS. Antes de commitear: `grep -rnE '\$X\b|XH|XHARNESS|\$_GET\[.x.\]|claude-0|scratchpad' templates app assets index.php` debe salir vacío. Los commits WIP van solo a la rama de desarrollo y se revisan (`git diff`) antes de fusionar a `main`.
- **Referencias de Carlos:** cuando pasa un sitio de referencia (Fourlines, Cargohype…), se toman COMPONENTES y se adaptan al sistema y al contenido real de NorthStar; no se copia su tema, copy, cifras, logos, testimonios ni imágenes (§8.2). Lo que necesite datos que no existen (clientes, métricas, ofertas de trabajo) se deja fuera o con `ph()`. Capturar referencias Webflow/Framer con Playwright usando el user agent por defecto (con uno personalizado se rompen sus animaciones) y desplazando con `mouse.wheel`.
- **Media de stock:** Pixabay por su API oficial con la clave que da Carlos (variable de entorno o mensaje; NUNCA en archivos del repo, commits ni logs). Las páginas web de Pexels y Pixabay tienen verificación anti-bots de Cloudflare: no se evade (el sandbox lo bloquea); sin clave, pedirla o que Carlos suba los archivos. Cada pieza entra en `assets/img/CREDITS.md`. Las fotos se recomprimen a WebP 640/1024/1280 + JPG; los videos a 1280x720, sin audio, con fundido de costura y ≤ 2 MB.
- Los errores de PHP nunca se muestran al visitante (`index.php` fija `display_errors=0` y escribe en `storage/error.log`, también en staging).
- No añadir dependencias, fuentes ni colores fuera de este archivo sin preguntar.
- Cambios de diseño que afecten a varias páginas: aplicarlos en tokens o componentes, no página por página.
- Al cerrar una tarea: pasar el checklist de §13 y actualizar §10 si se resolvió un pendiente.

### Flujo con subagentes (`.claude/agents/`)

- **planner** (opus): decisiones de alto impacto y reparto de tareas. No escribe código.
- **coder** (sonnet): implementa lo planificado, corre el build y reporta en pocas líneas.
- **explorer** (haiku): búsquedas, lecturas, renombrados, formato. Solo lectura.
- La sesión principal corre en Sonnet (`/model sonnet`). Orden: planner → coder (explorer apoya a ambos).
- Referencia visual del prototipo: https://claude.ai/artifact/38GbyzWVWaze8JfNA2EqUK (HTML exportado; páginas `index.html`, `Owner.html`, `Relay.html`, etc.).
