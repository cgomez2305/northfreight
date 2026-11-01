<?php
/**
 * Generación de /sitemap.xml y /robots.txt a partir de las rutas (§4, §7.6).
 * Requiere app/helpers.php cargado.
 */

/** XML del sitemap: solo rutas status=live y sitemap=true. lastmod = mtime real de la plantilla. */
function sitemap_xml(): string
{
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
         . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (routes() as $key => $r) {
        if ($key === '404' || ($r['status'] ?? '') !== 'live' || empty($r['sitemap'])) {
            continue;
        }
        $file = NS_ROOT . '/templates/pages/' . $r['template'] . '.php';
        $mtime = is_file($file) ? filemtime($file) : filemtime(NS_ROOT . '/app/routes.php');
        $out .= "  <url>\n"
              . '    <loc>' . htmlspecialchars(abs_url($key), ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n"
              . '    <lastmod>' . date('Y-m-d', $mtime) . "</lastmod>\n"
              . '    <priority>' . number_format((float) ($r['priority'] ?? 0.5), 1, '.', '') . "</priority>\n"
              . "  </url>\n";
    }
    return $out . "</urlset>\n";
}

/**
 * robots.txt dinámico: staging => Disallow: / (sin sitemap); producción => permitir todo
 * salvo rutas internas, con la línea Sitemap. No existe robots.txt estático en el repo.
 */
function robots_txt(): string
{
    if (!is_production()) {
        return "User-agent: *\nDisallow: /\n";
    }
    return "User-agent: *\nAllow: /\n"
         . "Disallow: /app/\nDisallow: /templates/\nDisallow: /storage/\nDisallow: /vendor/\n\n"
         . 'Sitemap: ' . abs_url('/sitemap.xml') . "\n";
}
