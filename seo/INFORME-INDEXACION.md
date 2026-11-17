# Indexación y reparto de enlaces internos (fase AF, 2026-11-17)

Método: rastreo completo desde `/` con `ENV=production` simulado (48 URLs alcanzadas, 44 en sitemap, 2.730 enlaces internos), separando enlaces de contenido (`<main>`), menú (`<header>`) y pie (`<footer>`); comprobaciones puntuales en vivo (solo lectura). El sitio real sigue en `ENV=staging` con `noindex`, así que hoy no hay nada indexado por diseño.

## 1. Indexación

| Comprobación | Resultado |
|---|---|
| Sitemap vs. rastreo | Las 44 URLs del sitemap se alcanzan por enlaces; ninguna huérfana. Las 4 rastreadas fuera del sitemap son `/careers/apply/` y `/es/trabaja-con-nosotros/aplicar/` (con y sin `?type=`), todas `noindex, follow` y con canonical a la URL limpia. |
| Directivas en producción | 44/44 con `index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1`; canonical autorreferente sin excepciones. |
| Códigos | 0 enlaces internos que devuelvan algo distinto de 200. 404 en inglés y español con `noindex, nofollow`. |
| URLs basura | `/blog/page/2/`, `/feed/`, `/wp-admin/`, `/box-truck-dispatch/` (draft) y `?preview=` falso dan 404 con noindex. |
| Variantes | `/Contact/`, `/contact`, `/es`, `/index.php` → 301 a la URL canónica. Parámetros (`?utm_source`, `?cat=`) con canonical a la limpia. |
| `/contact/?sent=1` | Cabecera `X-Robots-Tag: noindex` correcta; la meta robots de la página dice `index` (la cabecera manda). Cosmético. |
| `/careers/thank-you/` | 303 a apply con noindex. |
| CRM | `captacion.` responde con `X-Robots-Tag: noindex, nofollow, noarchive` y `robots.txt` con `Disallow: /`. |
| Descargas | `/assets/downloads/` 403; BOL y agreement fuera del sitemap en producción mientras `DOWNLOADS_APPROVED=false`. |
| hreflang | Recíproco en las 44 (en-us / es-us / x-default). |

Conclusión: sin bloqueos, duplicados ni páginas sin enlazar. Lo único que impide indexar hoy es el `noindex` de staging.

## 2. Reparto de enlaces (link juice)

- **Menú y pie:** cada página lleva 34 enlaces de header (17 URLs únicas, repetidos entre el menú de escritorio y el móvil) y 16 de footer. Eso da a las 17 páginas del menú entre 23 y 25 enlaces entrantes en todo el sitio. Es normal; Google cuenta una vez cada URL.
- **Contenido (enlaces contextuales, el que más pesa):**
  - Mejor servidas: home (24), `/es/` (22), `/blog/how-much-does-truck-dispatch-cost/` (9), `/how-it-works/` (9), `/blog/` (9).
  - Más flacas: `/careers/owner-operators/` (3 a 4), `/resources/ifta-calculator/` (3), artículos de Relay (5 cada uno), `/power-only-dispatch/` (**1**, solo el glosario).
- **Páginas que perdían jugo:** `/how-it-works/` tenía solo 2 enlaces contextuales de salida (home y careers). Es la página de proceso y no empujaba a ningún servicio.
- **Enlaces hacia `noindex`:** `/careers/apply/` recibe 18 enlaces (8 menú, 6 contenido, 4 pie). Es un destino de conversión, lo normal; `noindex, follow` deja pasar el jugo mientras Google lo mantenga así. No se tocó.
- **Páginas de privacidad y términos:** 47 entrantes por el pie (rutina). Sin versión en español.
- **Anclas:** sin «click here», «read more» ni «learn more» en el contenido. Las tarjetas enteras envueltas en `<a>` llevan anclas largas (título + descripción); no dañan, pero dispersan la palabra clave. Las anclas con keyword de §7.6 existen: costes → home («truck dispatch services») y owner («dispatch services for owner-operators»); lumper → back office («trucking back office»); Relay → `/amazon-relay-dispatch/`.
- **Español:** las páginas `/es/` solo enlazan al inglés con el selector de idioma y con los legales; sin fuga entre idiomas. Profundidad máxima 3 clics (artículos `/es/blog/…`).

## 3. Cambios hechos

Sin cambiar title, meta, H1 ni H2:

- **`/how-it-works/` (y `/es/como-funciona/`):** la tríada «We book the freight / We follow every trip / We keep the paperwork» pasa a ser enlaces a `/dispatch-for-owner-operators/`, `/load-tracking/` y `/trucking-back-office/` (usa el parámetro `href`/`cta` que ya tenía el partial `trio`; sin CSS nuevo). Enlaces contextuales de salida: 2 → 5.
- **`/power-only-dispatch/` (y su versión en español):** pasa de 1 a 4 enlaces contextuales, desde las FAQ de owner, de «Do you work with owner-operators?» (home) y de careers-owner.
- **FAQ de dry van** («How do I get updates?») enlaza a `/load-tracking/`.
- Resultado medido: `/load-tracking/` 4 → 6 contextuales, owner, back office y sus pares en español +1 cada uno. 0 errores, 0 enlaces rotos.

## 4. Recomendaciones (no aplicadas)

1. **Artículos con más volumen** (`amazon-relay-load-board`, 9.900) reciben solo 5 enlaces contextuales: cuando exista `/blog/how-to-dispatch-a-truck/`, enlazarlos desde ahí y desde el home.
2. **`/careers/owner-operators/`** (3 a 4 enlaces): un enlace desde el hero o el «In short» de owner-operators lo reforzaría; toca copy, queda para Carlos.
3. **Tarjetas completas como enlace:** si se quiere reforzar la keyword, añadir un enlace de texto breve dentro de la tarjeta (con la keyword como ancla) o acortar el `aria-label`.
4. **`/contact/?sent=1`:** emitir también `noindex` en la meta (hoy solo cabecera). No afecta a Google.
5. Cuando exista contenido para `/box-truck-dispatch/`, enlazarla desde owner, home y la tabla de equipos antes de pasarla a `live`.
6. Tras el lanzamiento: Search Console > Páginas (estado de indexación por idioma) y «Enlaces» > «Enlaces internos más enlazados» para contrastar este mapa con datos reales.
