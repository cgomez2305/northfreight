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
- Cero dependencias en el front: CSS propio con tokens y JavaScript vanilla. GSAP solo donde haya animación de scroll.
- Sin base de datos. El contenido vive en archivos PHP de datos (`app/data/*.php`).

Dependencias permitidas:
- **PHPMailer** (vía Composer) para enviar el formulario por SMTP de Hostinger.
- **GSAP 3 + ScrollTrigger** desde cdnjs, versión fija, cargado solo en las páginas que lo usan.
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
│   │   └── equipment.php  # contenido de las páginas por equipo
│   ├── sitemap.php        # genera /sitemap.xml desde routes.php (solo live + sitemap=true)
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
│   │   ├── hero.php         # hero interno reutilizable (migas, eyebrow, H1, swoosh, lead, CTAs, columna derecha opcional)
│   │   ├── section-head.php # eyebrow + H2 + lead
│   │   ├── card.php         # tarjeta con chaflán (variantes: light, dark, link)
│   │   ├── faq.php          # <details> + schema FAQPage
│   │   ├── breadcrumbs.php  # migas + schema BreadcrumbList
│   │   ├── video.php        # fachada de video con capítulos
│   │   ├── cta.php          # bloque final "Let's talk about your operation."
│   │   ├── footer.php
│   │   ├── mbar.php         # barra fija móvil (WhatsApp + llamada)
│   │   └── kit.php          # funciones auxiliares de los partials (iconos SVG, logo, estrella, swoosh, imágenes)
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
│   │   └── motion.js      # GSAP ScrollTrigger (solo donde se carga)
│   ├── fonts/             # woff2 locales (Saira variable, DM Sans variable, JetBrains Mono)
│   ├── img/               # WebP + fallback JPG, nombres descriptivos en inglés
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

### 5.1 Color (tokens)

| Token | Hex | Uso |
|---|---|---|
| `--navy` | `#071C35` | Superficie oscura principal (heros, bandas), texto de títulos sobre claro |
| `--navy-deep` | `#04132A` | Footer, CTA final, pop-up móvil, paneles más oscuros |
| `--cyan` | `#06A6CC` | Acento de marca: botones primarios, palabras resaltadas, estrella |
| `--cyan-ink` | `#04799A` | Cian para texto y enlaces sobre fondo claro (pasa AA) |
| `--cyan-light` | `#6FD3EC` | Eyebrows y detalles sobre fondo oscuro |
| `--ink` | `#0B1B2E` | Texto principal sobre claro |
| `--slate` | `#4A5B6E` | Texto secundario sobre claro |
| `--on-dark` | `#C9D6E3` | Párrafos sobre navy |
| `--on-dark-muted` | `#8FA3B8` | Metadatos y migas sobre oscuro |
| `--on-dark-soft` | `#B9C8D8` | Enlaces del footer y etiquetas sobre oscuro |
| `--on-dark-strong` | `#E6EEF6` | Enlaces de la navegación sobre oscuro (en la maqueta) |
| `--surface` | `#F3F6F9` | Fondos de sección claros y tarjetas |
| `--surface-hover` | `#EAF1F7` | Hover de tarjetas sobre `--surface` |
| `--ink-2` | `#24364A` | Texto de apoyo sobre claro (en la maqueta) |
| `--line` | `#DCE4EC` | Divisores y bordes |
| `--line-soft` | `#E3EAF1` | Divisores suaves |
| `--field` | `#C9D4DF` | Borde de inputs |
| `--white` | `#FFFFFF` | Fondo base |

También hay tokens alfa en `tokens.css` (`--line-on-dark`, `--tint-on-dark`, `--cyan-tint*`, overlays y sombras), `--ph-bg` (marca de placeholder), `--wrap` y las formas `--cut-*`. **Ningún hex ni rgba fuera de `tokens.css`.** Foco visible: `--cyan-ink` sobre fondo claro (el cian puro da 2,86:1 sobre blanco).

Sobre cian, el texto va en `--navy-deep` (`#04132A`), nunca en blanco.

### 5.2 Tipografía

| Rol | Familia | Configuración |
|---|---|---|
| Display / títulos | **Saira** variable | `font-stretch:125%`, `font-variation-settings:'wdth' 125`, MAYÚSCULAS, peso 800–900, `letter-spacing:-.02em` en tamaños grandes |
| Eyebrows / etiquetas | **Saira** | `wdth 112`, peso 600, MAYÚSCULAS, `letter-spacing:.34em` (secciones) o `.24em` (meta) |
| Texto | **DM Sans** variable | 400–700. Es la fuente de lectura del usuario; no cambiarla. |
| Datos / horas / códigos | **JetBrains Mono** | 400–600, `tabular-nums` |

No usar Bricolage, Inter, Space Grotesk ni ninguna otra fuente. No usar texto solo en contorno (outline).

Escala (máximo en escritorio; todos con `clamp()` y bajan proporcionalmente):

| Clase | Valor | Uso |
|---|---|---|
| `.t-display` | `clamp(42px,5vw,76px)` / lh .92 | Titular visual del home ("Booked. Tracked. Documented.") |
| `.t-h1` | `clamp(38px,5.2vw,76px)` / lh .92 | H1 de páginas de servicio, empresa y recursos |
| `.t-h1-long` | `clamp(34px,4.4vw,60px)` | H1 de artículos y páginas legales |
| `.t-cta` | `clamp(36px,5.8vw,84px)` / lh .9 | Solo el titular del bloque final de contacto |
| `.t-h2` | `clamp(30px,4vw,56px)` / lh .96 | H2 de sección (todas) |
| `.t-h2-s` | `clamp(26px,3.2vw,40px)` | Títulos de bandas destacadas |
| `.t-h3` | `20px` / peso 800 / lh 1.05 | Título de tarjeta |
| `.t-prose-h2` | `clamp(24px,2.6vw,34px)` | H2 dentro de artículos |
| `.t-lead` | `clamp(16px,1.4vw,18px)` / lh 1.65 | Entrada del hero |
| `.t-body` | `17px` / lh 1.65–1.75 | Párrafos de sección, FAQ, glosario |
| `.t-card` | `15px` / lh 1.6 | Texto en tarjetas |
| `.t-small` | `13–14px` | Notas, ayudas de formulario |
| `.t-eyebrow` | `11px` | Etiqueta sobre H2 |
| `.t-meta` | `10px` | Migas, etiquetas en tarjetas |

Reglas: un solo H1 por página. `h1,h2,h3 { text-wrap: balance }`. Las palabras clave del título van en `--cyan` dentro del mismo H (span), nunca como imagen.

### 5.3 Espaciado y layout

- Contenedor: `max-width:1280px; margin:0 auto; padding-inline: clamp(20px,4vw,48px)`. **El gutter mínimo es 20px**: ningún texto ni botón toca el borde.
- Sección: `padding-block: clamp(64px,9vw,120px)`.
- Sección inmediatamente antes del CTA final: `padding-bottom: clamp(110px,12vw,160px)` (el CTA sube 64px con su corte diagonal).
- Hero interno: `padding-top: clamp(36px,6vw,80px); padding-bottom: clamp(56px,8vw,104px)`.
- Encabezado de sección → contenido: `gap: 40px`.
- Tarjetas: padding `26px` (tiles pequeños `22px`); gap entre tarjetas `14px` (tiles `12px`).
- Botones: alto mínimo `56px` (secundarios dentro de tarjetas `52px`); todo control táctil ≥ `44px`.
- Espaciado entre hermanos con `flex`/`grid` + `gap`; no márgenes sueltos.

### 5.4 Grids y breakpoints

| Clase | Escritorio | ≤1024px | ≤640px |
|---|---|---|---|
| `.g2` | 2 col | 2 | 1 |
| `.g3` | 3 col | 3 | 1 |
| `.g3h` | 3 col | 3 | 2 |
| `.g4` | 4 col | 2 × 2 | 1 |
| `.g5` | 5 col | 3 + 2 (grid de 6, spans 2/3) | 1 |
| `.g6` | 6 col | 3 | 1 |

Siempre `repeat(N, minmax(0,1fr))`. **Ninguna tarjeta queda huérfana en una fila.**

Breakpoints:
- `≥1025px`: navegación completa con mega menús.
- `≤1024px`: hamburguesa + pop-up; se ocultan los mega menús y el correo de la barra superior.
- `≤760px`: aparece la barra fija móvil (`mbar`); se ocultan elementos `.hide-mobile`; el body gana `padding-bottom` para no quedar tapado.
- `≤640px`: grids a una columna; botones principales a ancho completo.

### 5.5 Formas de marca

- **Chaflanes** (no se usan bordes redondeados en ningún sitio):
  - `.cut` (tarjetas): `clip-path: polygon(0 0,calc(100% - 22px) 0,100% 22px,100% 100%,22px 100%,0 calc(100% - 22px))`
  - `.cut-s` (tiles): mismo patrón con `12px`.
  - `.btn` (botones): `polygon(14px 0,100% 0,100% calc(100% - 14px),calc(100% - 14px) 100%,0 100%,0 14px)` — chaflán arriba-izquierda y abajo-derecha. **Todos los botones, incluida la barra móvil, usan este mismo corte de 14px.**
  - Imágenes de hero: chaflán grande `polygon(18% 0,100% 0,100% 100%,0 100%,0 18%)` o `56px` en esquinas opuestas.
- **Estrella NorthStar** (SVG, `viewBox="0 0 249 249"`): `M127 0L98 100L0 123L99 149L122 249L153 148L249 125L150 98Z`. Se usa como viñeta, marcador y acento (con animación `twinkle` lenta).
- **Swoosh** bajo los H1 (SVG `viewBox="0 0 220 14"`, 200×12): `polygon points="0,14 220,0 210,6 60,14"` en cian.
- **Ticker** inclinado `-1.6deg` en cian bajo el hero del home.
- **Corte diagonal** superior del CTA final: `clip-path: polygon(0 64px,100% 0,100% 100%,0 100%)` con `margin-top:-64px`.

### 5.6 Logo (lockup del header y footer)

- Isotipo blanco/cian `46×46px` + bloque de texto a la derecha con `margin-left: 2px`, alineado a la **base de la N** (`align-items: flex-end`).
- "NORTH**STAR**": Saira wdth 125, peso 900, `22px`, `line-height: 15.84px`, blanco con "STAR" en `--cyan`.
- "FREIGHT SOLUTIONS": Saira wdth 112, peso 600, `8px`, `line-height: 5.76px`, `letter-spacing: .49em`, color `#B9C8D8`.
- Separación vertical entre las dos líneas: `4px`. Los dos renglones miden lo mismo de ancho.
- Proporciones verificadas contra el logo original; no cambiarlas a ojo.
- Isotipo en SVG (`assets/img/isotype-white.svg` y `isotype.svg`), sin recortes.

### 5.7 Movimiento

- Existentes: `twinkle` de la estrella (4.8s), punto "live" con pulso, ticker en marquesina, aparición `rise` de la tarjeta del hero, pop-up móvil (fondo `fade` .25s + panel `slide` desde la derecha .35s), hover de tarjetas (`translateY(-3px)`) y botones (`-2px`).
- GSAP ScrollTrigger: entradas de sección y escalonado de tarjetas, sutil (≤ 24px de desplazamiento, ≤ .6s). Una sola animación orquestada por sección, no efectos en cada elemento.
- Todo el contenido es visible sin JavaScript y sin scroll (nada queda en `opacity:0` esperando un observer).
- `prefers-reduced-motion: reduce` desactiva todas las animaciones y transformaciones de hover.

---

## 6. Componentes

| Componente | Comportamiento |
|---|---|
| **Ops bar** | Franja superior oscura: punto cian con pulso + "Dispatch desk · On shift" + relojes ET/CT/MT/PT (Intl, se actualizan cada 20 s) + correo (oculto ≤1024). **Decisión de Carlos: se replica tal cual la maqueta (visible).** ⚠️ "On shift" afirma que el desk está trabajando; el cliente aún debe confirmar horario (ver §10). Flag `SHOW_ON_SHIFT` en `app/config.php`. |
| **Nav** | Logo · Services ▾ · Resources ▾ · How it works · Our desk · Contact · botón "WhatsApp us". Mega menú abre al hover y al clic, cierra al salir y con Esc; `aria-expanded` en los disparadores. |
| **Mega Services** | Dispatch services (Owner-operators, Amazon Relay, Dry van) · Operations & back office (Load tracking, Back office) · chips "By equipment" (solo equipos confirmados) · tarjeta destacada de Amazon Relay. ⚠️ La tarjeta destacada tiene texto encima de la foto: rehacerla con el texto debajo o al lado. |
| **Mega Resources** | Free tools (IFTA, Rate per mile) · Templates (BOL, Agreement) · Learn (Glossary, Blog) · tarjeta cian de descarga del BOL. |
| **Pop-up móvil** | `role="dialog" aria-modal="true"`, fondo navy translúcido con desenfoque, panel desde la derecha (ancho completo en móvil, 440px en tablet), cabecera con logo + botón cerrar, acordeones Services/Resources, enlaces principales, bloque fijo inferior con WhatsApp y llamada. Bloquea el scroll del body, cierra con Esc, clic en el fondo o en un enlace, y devuelve el foco al botón que lo abrió. **Mientras está abierto, la barra móvil se oculta.** |
| **Barra móvil (mbar)** | Fija abajo, de borde a borde, fondo `--navy-deep` 96% con desenfoque y línea superior. Botones alineados al gutter de 20px: "Talk to a dispatcher" (cian, flex 1) + llamada (cuadrado 56px). Respeta `env(safe-area-inset-bottom)`. Solo ≤760px. |
| **Hero interno** | Migas → eyebrow opcional → H1 → swoosh → tagline opcional → lead → CTAs → columna derecha opcional (imagen, tarjeta de datos o vista previa de documento). |
| **Tarjeta** | Chaflán `.cut`, fondo `--surface` (variante oscura en navy para la última o la destacada), número/etiqueta mono, H3, texto, enlace con flecha "→" en DM Sans. |
| **FAQ** | `<details>/<summary>` con la estrella NorthStar que gira 45° al abrir (así es en la maqueta). El layout genera el JSON-LD FAQPage. En producción se descartan las FAQ con placeholders. |
| **Video del equipo** | Fachada (imagen + botón play centrado); el iframe/video se carga al hacer clic. Pie **debajo** del video ("Meet the desk · 3 chapters", "Video · 3–4 min"). Capítulos como botones que marcan el activo y saltan al minuto. |
| **CTA final** | Isotipo 56px en flujo encima del titular "Let's talk about **your operation.**", texto "No forms first…", botón grande de WhatsApp con el número visible + botón de correo. |
| **Footer** | Logo + descripción · Services · Company (How it works, Our desk, Resources, Blog, Contact) · Talk to the desk. Fila inferior: © año, MC#/USDOT (o eliminar si no aplica), Privacy, Terms. |

Reglas de UI que salieron de las auditorías y no se negocian:
1. **Ningún texto sobre imágenes.** Pies, etiquetas y tarjetas van debajo o al lado de la foto. (Excepción única: el botón de play del video.)
2. Ningún texto a menos de 20px del borde de la pantalla.
3. Sin scroll horizontal entre 360 y 1440px.
4. Sin tarjetas huérfanas en ningún breakpoint.
5. Sin marcas de agua detrás de títulos ni botones.
6. La flecha "→" siempre en DM Sans (Saira no tiene el glifo).
7. Contraste AA mínimo (las etiquetas grises pequeñas incluidas). Foco visible en todo control.

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

### 8.3 Contacto

- WhatsApp: **+1 863-651-3322** → `https://wa.me/18636513322?text=` + mensaje codificado.
- Mensaje por defecto: `Hi, I'd like to talk about dispatch for my fleet with NorthStar.` Cada página de servicio tiene su variante (owner-operator, Relay, dry van, back office, tracking, equipo).
- Correo: `nfreightsolutionsllc@gmail.com` (pendiente: posible correo con dominio propio).
- El número y el correo siempre visibles como texto, no solo como enlace.

### 8.4 Placeholders vigentes en el diseño

Medidos con la auditoría sobre las 22 rutas live (staging). Se pintan con `ph()`, que en `ENV=production` devuelve cadena vacía y descarta las FAQ que los llevan, para que nada con corchetes llegue a producción:

| Placeholder | Dónde |
|---|---|
| `MC# — · USDOT —` | footer de las 22 páginas |
| `0:00` (×3) | home y Our desk (tiempos de capítulos del video) |
| `Team photo`, `Name`, `Role`, `One line on experience` (×4 cada uno) | Our desk |
| `YOUR FEE — % of gross or flat weekly` | home, Owner (FAQ de coste) |
| `confirm equipment types` | home, Owner |
| `confirm terms: weekly, monthly, cancel anytime` | home (FAQ del contrato) |
| `confirm how account access is handled`, `confirm` | Relay |
| `confirm load boards`, `confirm` | Dry van |
| `ID` | home y Load tracking (desk log de ejemplo) |
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

JavaScript: sin dependencias salvo GSAP, módulos pequeños, `defer`, cada uno se carga solo en la página que lo usa. El sitio funciona y se lee sin JS.

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

Pendientes técnicos conocidos:
- ~~Rehacer la tarjeta destacada del mega menú de Services sin texto sobre la foto.~~ Hecho (foto arriba, texto debajo).
- Escribir `/blog/how-to-dispatch-a-truck/` (no está en la maqueta; no se registra hasta tenerlo).
- Archivos reales del BOL (PDF y DOCX) y del agreement (DOCX revisado por abogado) en `assets/downloads/`. El agreement ofrece también "PDF — read only": decidir si se prepara o se quita la opción.
- `title`/`meta`/`h1` de Resources, Blog, herramientas y páginas de equipo no están en el doc de copy: los redactó el andamiaje con la keyword (`// TODO verificar con doc de copy` en `app/routes.php`). Carlos debe validarlos.
- Reemplazar todas las fotos (stock genérico): la del equipo con auriculares figura como "NorthStar team" en Our desk y es la primera. Dos fotos son de solo 490 px y se amplían en el hero. Algunas muestran marcas de terceros legibles (Trans Chicago, Penske, Wabash, Kreilkamp).
- El video del equipo no existe: el play queda desactivado ("Video coming soon" solo para lector de pantalla). Cuando haya URL, el partial `video` recibe `data-video-src`.
- Páginas de equipo (5) en `draft`. Sus migas llevan "Services", que apunta a `/dispatch-for-owner-operators/` porque no existe un hub de servicios.
- Error visual en formularios: no hay token `--danger` (no se añadió color sin permiso); los errores usan navy con borde grueso.
- Frase "Estimate only…" en las calculadoras (§8.2.6): no está en la maqueta; confirmar el texto.
- Newsletter del blog: solo maquetado, `method="get"` y el input sin `name`.
- `.htaccess` sin probar (el servidor de desarrollo `php -S` no lo lee): probar en staging de Hostinger (HTTPS, barra final, bloqueo de `app/`, caché, compresión).
- Plantilla de correo y credenciales SMTP reales: crear `app/config.local.php` en el servidor desde `app/config.local.example.php` (`APP_SECRET`, `SMTP_*`, `MAIL_TO`, `PREVIEW_TOKEN`, `ENV`, `DOMAIN`).

Estado de verificación (staging, `ENV=production` en Lighthouse móvil, 4 rutas): Performance 94–95, Accessibility 100, Best Practices 100, SEO 100, CLS 0. **LCP 2,8–3,0 s (objetivo < 2,5 s)** medido con `php -S` sin compresión ni caché; revisarlo en Hostinger con LiteSpeed y, si no baja, precargar la imagen del hero y sustituir las fotos de 490 px.

---

## 11. Rendimiento y accesibilidad

- Fuentes en local (`woff2`, subset latino), `font-display: swap`, `preload` de Saira 900 y DM Sans 400.
- Imágenes WebP con `srcset`/`sizes`, `width`/`height` explícitos, `loading="lazy"` salvo la imagen del hero (`fetchpriority="high"`).
- Video con fachada: nada de iframe hasta el clic.
- Objetivo Lighthouse móvil: Performance ≥ 90, Accessibility ≥ 95, Best Practices ≥ 95, SEO 100. LCP < 2.5 s, CLS < 0.1.
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
- [ ] Lighthouse móvil dentro de los objetivos de §11.

---

## 14. Cómo trabajar en este repo (para Claude)

- Antes de maquetar una página, revisar su artboard en el lienzo y su sección en el documento de copy.
- Reutilizar partials y clases existentes antes de crear otros nuevos; si hace falta uno nuevo, documentarlo en §6.
- No inventar contenido ni datos del cliente. Si falta algo, usar `[PLACEHOLDER]` y anotarlo en §8.4.
- No añadir dependencias, fuentes ni colores fuera de este archivo sin preguntar.
- Cambios de diseño que afecten a varias páginas: aplicarlos en tokens o componentes, no página por página.
- Al cerrar una tarea: pasar el checklist de §13 y actualizar §10 si se resolvió un pendiente.

### Flujo con subagentes (`.claude/agents/`)

- **planner** (opus): decisiones de alto impacto y reparto de tareas. No escribe código.
- **coder** (sonnet): implementa lo planificado, corre el build y reporta en pocas líneas.
- **explorer** (haiku): búsquedas, lecturas, renombrados, formato. Solo lectura.
- La sesión principal corre en Sonnet (`/model sonnet`). Orden: planner → coder (explorer apoya a ambos).
- Referencia visual del prototipo: https://claude.ai/artifact/38GbyzWVWaze8JfNA2EqUK (HTML exportado; páginas `index.html`, `Owner.html`, `Relay.html`, etc.).
