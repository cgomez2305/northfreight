# Guías SEO para Carlos y el cliente (fase AF)

Todo son pasos manuales en cuentas que el cliente o Carlos controlan. No se tocó ninguna cuenta ajena. Dominio: `northstarfreightsolutions.us` (sin www).

## 1. Google Search Console

1. Entrar a search.google.com/search-console con la cuenta que va a administrar el sitio (preferible una cuenta de la agencia más el correo del cliente como propietario).
2. Añadir propiedad de tipo **Dominio** (`northstarfreightsolutions.us`): cubre http/https, www y subdominios (incluido `captacion.`; no hace falta excluirlo, el CRM no se indexa).
3. Verificar con el registro TXT en el DNS (hPanel > Dominios > DNS). No usar etiqueta meta ni archivo: CLAUDE.md prohíbe añadir cosas sin acordarlo, y el TXT no toca el sitio.
4. **No enviar el sitemap mientras `ENV=staging`** (el sitio responde noindex; enviarlo solo genera avisos). Hacerlo el día del lanzamiento: Sitemaps > `https://northstarfreightsolutions.us/sitemap.xml`. El sitemap ya lleva `xhtml:link` hreflang; Search Console lo acepta tal cual.
5. Inspección de URL: comprobar `/`, `/es/`, `/amazon-relay-dispatch/` y `/es/dispatch-amazon-relay/`; pulsar «Solicitar indexación» en esas cuatro y luego en el resto con calma (hay cupo diario).
6. **Segmentar `/es/`**: en Rendimiento > Resultados de búsqueda, añadir filtro «Página» con URL que contenga `/es/` (guardar la comparación EN vs ES). Revisar la pestaña «Páginas» de Indexación: ninguna `/es/` debe aparecer como «Duplicada, el usuario no ha indicado versión canónica».
7. Tras 2 a 4 semanas: revisar «Consultas» en ES y comparar con la tabla de keywords de `seo/INFORME-SEO.md`; los datos reales sustituyen a las estimaciones de Google Ads.
8. Alertas: dejar activado el correo de Search Console para errores de indexación y de experiencia.

## 2. Bing Webmaster Tools

1. bing.com/webmasters > importar el sitio desde Search Console (opción «Import from Google Search Console»): evita un segundo método de verificación.
2. Enviar el mismo sitemap. Bing alimenta a varios asistentes de IA, así que importa para visibilidad GEO.
3. IndexNow: el sitio no lo implementa; no es necesario para la primera versión (decidir después si se quiere).

## 3. Google Business Profile

NorthStar es un servicio remoto sin local de atención al público; Google exige distinguirlo.

1. Crear el perfil como **negocio de área de servicio** (sin dirección visible), ocultando la dirección si se usa una postal.
2. Nombre exacto: el nombre legal/comercial que el cliente confirme (hoy `NorthStar Freight Solutions LLC` en el sitio). No añadir palabras clave al nombre (Google lo sanciona).
3. Categoría principal: la más cercana a servicio de logística/despacho de carga (elegir en el momento; las categorías cambian). Área de servicio: Estados Unidos o los estados que el cliente confirme.
4. Teléfono y web: el mismo teléfono que el sitio (`+1 863-651-3322`) y `https://northstarfreightsolutions.us/`. Coherencia NAP con el JSON-LD y el footer.
5. Verificación: Google puede pedir video o llamada; **requiere dirección real del negocio** (pendiente del cliente: la misma que necesita el CRM para los correos). Sin dirección no se puede verificar.
6. Cuando exista, poner la URL del perfil en `SAME_AS` de `app/config.local.php`. No pedir ni publicar reseñas inventadas; solo reseñas reales de clientes con permiso.

## 4. Analítica sin cookies ni scripts nuevos

CLAUDE.md prohíbe dependencias sin acordar, y el sitio no carga scripts de terceros (solo GSAP). Opciones en orden de menor a mayor impacto:

1. **Search Console** (ya cubre impresiones, clics, consultas y páginas por idioma). Suficiente para SEO.
2. **Registros del servidor** en hPanel (Estadísticas / logs de acceso): visitas por URL sin instalar nada. Sirven para ver tráfico a `/es/`.
3. **Conversiones:** el contacto principal es WhatsApp; los clics a `wa.me` no se miden hoy. Para medirlos hace falta una herramienta. Opciones a decidir con el cliente: Plausible o similar sin cookies (un script de terceros, requiere aprobar la dependencia y actualizar la política de privacidad), o `GA_ID`/`META_PIXEL_ID` ya reservados en la config (cargarían scripts y exigen aviso de cookies). **No se implementó ninguno.**
4. UTM: los leads de `/careers/apply/` ya guardan `utm_*` y `referrer`; usar UTM en cualquier enlace que se publique (perfiles, anuncios).

## 5. Pasar `ENV` a `production`

Qué debe estar resuelto antes (CLAUDE.md §8.4, §10, §12):

- [ ] Textos legales (privacidad y términos: fecha, dirección, cookies, responsabilidad) revisados por abogado. En producción las secciones sin texto se omiten, pero las páginas no deben salir a medias.
- [ ] Descargables: BOL revisado por el cliente y agreement por abogado; si no, se queda `DOWNLOADS_APPROVED=false` (BOL y agreement dan 404 y `/resources/` omite plantillas; ya lo cubre el código).
- [ ] Artículo de costes sin huecos (las FAQ con placeholder se descartan solas en producción).
- [ ] `app/config.local.php`: `ENV='production'`, `DOMAIN`, `APP_SECRET`, `PREVIEW_TOKEN`, `MAIL_TO` y SMTP real.
- [ ] Equipo y video reales o retirados (en producción se omiten sin datos).
- [ ] Decidir `AI_TRAINING_BOTS` (hoy `allow`).
- [ ] Aprobación de Carlos sobre las FAQs ocultas y el modelo de cobro.

### Lista de comprobaciones SEO del día del lanzamiento

Hacer en este orden, con `curl` (solo lectura) justo después del despliegue:

1. `curl -sI https://northstarfreightsolutions.us/` : **sin** `X-Robots-Tag`; HTML sin `<meta name="robots" content="noindex…">`.
2. `curl -s https://northstarfreightsolutions.us/robots.txt` : `Allow: /`, línea `Sitemap:` correcta, sin `Disallow: /`.
3. `/sitemap.xml` : 44 URLs aproximadamente (o las que resulten tras los placeholders), todas 200, con `xhtml:link` en las parejas EN/ES.
4. Canonical autorreferente y hreflang `en-us`/`es-us`/`x-default` en `/` y `/es/` (el auditor de esta fase está en el informe; repetirlo contra producción).
5. Redirecciones: `http://northstarfreightsolutions.us/` y `http://www.…` → `https://northstarfreightsolutions.us/` (301); `https://www.northstarfreightsolutions.us/` → apex (301, regla añadida en la fase AF); `//es/` → `/es/`; sin barra final → con barra.
6. HSTS ya activo (`max-age=31536000; includeSubDomains`, sin `preload`).
7. 404 en inglés y en español (`/nada/` y `/es/nada/`): estado 404, `noindex`.
8. `llms.txt` y `llms-full.txt` sin noindex y con la sección «Español».
9. `/assets/downloads/` da 403; `/app/`, `/storage/`, `/captacion/` dan 403.
10. JSON-LD sin placeholders ni corchetes: `curl -s URL | grep -c "\["` en las páginas clave; Rich Results Test de Google para `/`, un servicio y un artículo.
11. Search Console: enviar sitemap, inspeccionar y solicitar indexación de las URLs principales (guía §1).
12. Una semana después: revisar el log de CSP en modo solo informe (`storage/csp.log`) antes de pasarla a bloqueo (CLAUDE.md fase O).
