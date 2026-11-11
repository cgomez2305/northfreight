<?php
/**
 * Generación de /sitemap.xml y /robots.txt a partir de las rutas (§4, §7.6).
 * Requiere app/helpers.php cargado.
 */

/**
 * XML del sitemap: solo rutas status=live y sitemap=true.
 * Sin <lastmod> por defecto: tras un despliegue por Git todos los archivos tienen la fecha del despliegue y el valor sería falso (y Google lo ignora si no es fiable).
 * Una ruta lo emite solo si declara una fecha real en su entrada de routes.php: 'lastmod' => 'YYYY-MM-DD'.
 */
function sitemap_xml(): string
{
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
         . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
    foreach (routes() as $key => $r) {
        if (in_array($key, ['404', '404es'], true) || ($r['status'] ?? '') !== 'live' || empty($r['sitemap'])) {
            continue;
        }
        $lastmod = trim((string) ($r['lastmod'] ?? ''));
        // Fase AE: cada URL con versión en el otro idioma declara sus alternates (en-us, es-us y x-default), también la propia (requisito de Google).
        $alts = '';
        foreach (ns_hreflang_links($r) as $alt) {
            $alts .= '    <xhtml:link rel="alternate" hreflang="' . $alt['hreflang'] . '" href="' . htmlspecialchars($alt['href'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "\"/>\n";
        }
        $out .= "  <url>\n"
              . '    <loc>' . htmlspecialchars(abs_url($key), ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n"
              . $alts
              . (iso_date_ok($lastmod) ? '    <lastmod>' . $lastmod . "</lastmod>\n" : '')
              . '    <priority>' . number_format((float) ($r['priority'] ?? 0.5), 1, '.', '') . "</priority>\n"
              . "  </url>\n";
    }
    return $out . "</urlset>\n";
}

/**
 * robots.txt dinámico: staging => Disallow: / para todos (sin sitemap); producción => permitir todo salvo rutas internas,
 * con postura EXPLÍCITA sobre IA y la línea Sitemap. No existe robots.txt estático en el repo.
 *
 * Producción:
 *  - `User-agent: *` Allow / + Disallows internos.
 *  - Bots de búsqueda/respuesta de IA (OAI-SearchBot, ChatGPT-User, PerplexityBot, Claude-SearchBot, Claude-User): siempre Allow.
 *  - Bots de entrenamiento (GPTBot, ClaudeBot, Google-Extended, Applebot-Extended, CCBot): según AI_TRAINING_BOTS ('allow' defecto | 'block').
 *  Un grupo específico anula al de `*`, así que cada grupo repite los Disallows internos.
 */
function robots_txt(): string
{
    if (!is_production()) {
        return "User-agent: *\nDisallow: /\n";
    }
    $internal = "Disallow: /app/\nDisallow: /templates/\nDisallow: /storage/\nDisallow: /vendor/\n";
    $group = static fn(array $agents, string $rules): string => implode('', array_map(static fn(string $a): string => 'User-agent: ' . $a . "\n", $agents)) . $rules;

    $searchBots = ['OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Claude-SearchBot', 'Claude-User'];
    $trainingBots = ['GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot'];
    $blockTraining = defined('AI_TRAINING_BOTS') && strtolower((string) AI_TRAINING_BOTS) === 'block';

    return '# ' . BRAND . " robots.txt\n"
         . '# Machine-readable summary of this site for AI assistants: ' . abs_url('/llms.txt') . "\n\n"
         . $group(['*'], "Allow: /\n" . $internal) . "\n"
         . "# AI search and answer engines: allowed (they cite and link to the site)\n"
         . $group($searchBots, "Allow: /\n" . $internal) . "\n"
         . '# AI training crawlers: ' . ($blockTraining ? 'blocked' : 'allowed') . " (AI_TRAINING_BOTS in app/config.php)\n"
         . $group($trainingBots, $blockTraining ? "Disallow: /\n" : "Allow: /\n" . $internal) . "\n"
         . 'Sitemap: ' . abs_url('/sitemap.xml') . "\n";
}
