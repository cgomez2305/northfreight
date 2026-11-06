# Fuentes del panel (R1)

Locales, sin CDN (`font-src 'self'`), `font-display: swap`. Subset latino variable (eje de peso 100 a 900), obtenidos de los paquetes npm `@fontsource-variable/geist` y `@fontsource-variable/geist-mono` 5.3.0 (instalados fuera del repo; no son dependencia del proyecto).

| Archivo | Familia | Uso | Tamaño |
|---|---|---|---|
| `geist-var.woff2` | Geist | Interfaz, tablas, títulos y números grandes | 29.400 B |
| `geist-mono-var.woff2` | Geist Mono | Solo IDs y códigos (`LD-0142`), metadatos, ejes de gráficos y atajos de teclado | 23.128 B |

Licencia: SIL Open Font License 1.1 (`OFL.txt`), Copyright 2024 The Geist Project Authors (https://github.com/vercel/geist-font).

Si se sustituye un archivo hay que cambiarle el nombre (se sirven con caché larga). Geist no trae las flechas `→` y `↗`: el navegador las toma de la fuente del sistema; los iconos del panel son SVG.
