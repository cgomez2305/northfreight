<?php
/**
 * /llms.txt y /llms-full.txt (formato llmstxt.org) generados SIEMPRE desde routes.php + app/data/*:
 * ningún texto se duplica aquí, así que nunca se desincroniza del sitio.
 *
 * Reglas (CLAUDE.md §8.2 / §8.4):
 *  - Solo rutas status=live y sitemap=true; las draft (páginas de equipo) no aparecen.
 *  - Nada de placeholders: cualquier texto con {{ph:…}}, "[ … ]" o la palabra "confirm" se omite (la línea entera),
 *    también en staging. Las FAQ con placeholders se descartan igual que en producción.
 *  - Nada de afirmaciones nuevas (cobertura, horarios, tarifas, cifras): solo metas, leads, FAQ, glosario y extractos que ya están en el sitio.
 *
 * Requiere app/helpers.php cargado. Las cabeceras las pone index.php.
 */

/** Texto plano seguro para llms: sin marcas, sin HTML, espacios colapsados. '' si contiene un placeholder (hay que omitir la línea). */
function llms_clean(?string $s): string
{
    if ($s === null) {
        return '';
    }
    $s = str_replace(['{hl}', '{/hl}'], '', $s);
    $s = str_replace('{br}', ' ', $s);
    if (has_ph($s)) {
        return '';
    }
    $s = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if ($s === '' || preg_match('/\[ [^\]]+ \]/u', $s) === 1 || preg_match('/\bconfirm\b/i', $s) === 1) {
        return '';
    }
    return $s;
}

/** Rutas live del sitemap agrupadas por sección de llms.txt: ['services'=>[path=>route], 'resources'=>…, 'blog'=>…, 'company'=>…, 'optional'=>…]. */
function llms_sections(): array
{
    $groups = ['home' => [], 'services' => [], 'resources' => [], 'blog' => [], 'company' => [], 'optional' => []];
    $serviceTemplates = ['owner', 'relay', 'dryvan', 'tracking', 'backoffice', 'equipment'];
    foreach (routes() as $key => $r) {
        if ($key === '404' || ($r['status'] ?? '') !== 'live' || empty($r['sitemap'])) {
            continue;
        }
        $tpl = (string) ($r['template'] ?? '');
        $g = match (true) {
            $key === '/'                              => 'home',
            in_array($tpl, $serviceTemplates, true)   => 'services',
            str_starts_with($key, '/resources/')      => 'resources',
            str_starts_with($key, '/blog/')           => 'blog',
            $tpl === 'legal'                          => 'optional',
            default                                   => 'company',
        };
        $groups[$g][$key] = $r;
    }
    return $groups;
}

/** Título de una ruta para llms: el H1 de la ruta ("Home" para el inicio). */
function llms_title(string $path, array $route): string
{
    return $path === '/' ? 'Home' : llms_clean((string) ($route['h1'] ?? '')) ;
}

/**
 * Resumen de 2 frases del inicio, hecho solo con texto ya publicado: la descripción de empresa del footer y la lista de servicios
 * de la meta del home (lo que va entre ": " y el primer punto).
 */
function llms_summary(): string
{
    $first = BRAND . ' is a ' . lcfirst(rtrim(org_description(), '.')) . '.';
    $meta = llms_clean((string) (routes()['/']['meta'] ?? ''));
    $second = '';
    if (($i = strpos($meta, ': ')) !== false) {
        $services = rtrim(explode('. ', substr($meta, $i + 2))[0], '.');
        $second = $services !== '' ? ' Services cover ' . $services . '.' : '';
    }
    return $first . ($second !== '' ? $second : ($meta !== '' ? ' ' . $meta : ''));
}

/** Líneas de contacto tal como aparecen en el sitio (WhatsApp, correo y, si está en config, dirección). */
function llms_contact_lines(): array
{
    $lines = [
        'Contact: WhatsApp ' . PHONE . ' (' . WA_BASE . ') · Email ' . EMAIL . ' · Site language: English (US).',
    ];
    $a = org_address();
    if ($a) {
        $city = trim(($a['city'] ?? '') . (isset($a['city'], $a['region']) ? ', ' : '') . ($a['region'] ?? '') . (isset($a['postal']) ? ' ' . $a['postal'] : ''));
        $lines[] = 'Address: ' . implode(', ', array_filter([$a['street'] ?? '', $city, $a['country'] ?? ''], static fn(string $x): bool => $x !== ''));
    }
    return $lines;
}

/** Cabecera común: "# Marca", blockquote y contacto. */
function llms_head(): array
{
    $out = ['# ' . BRAND, ''];
    $sum = llms_summary();
    if ($sum !== '') {
        $out[] = '> ' . $sum;
        $out[] = '';
    }
    foreach (llms_contact_lines() as $l) {
        $out[] = $l;
        $out[] = '';
    }
    return $out;
}

/** /llms.txt: índice en markdown con enlaces absolutos y la meta de cada ruta. */
function llms_txt(): string
{
    $out = llms_head();
    $titles = ['services' => 'Services', 'resources' => 'Resources', 'blog' => 'Blog', 'company' => 'Company', 'optional' => 'Optional'];
    $groups = llms_sections();
    // Home: se lista dentro de Company.
    $groups['company'] = $groups['home'] + $groups['company'];
    foreach ($titles as $g => $heading) {
        $lines = [];
        foreach ($groups[$g] as $path => $r) {
            $title = llms_title($path, $r);
            if ($title === '') {
                continue;
            }
            $desc = llms_clean((string) ($r['meta'] ?? ''));
            $lines[] = '- [' . $title . '](' . abs_url($path) . ')' . ($desc !== '' ? ': ' . $desc : '');
        }
        if ($lines) {
            $out[] = '## ' . $heading;
            $out[] = '';
            array_push($out, ...$lines);
            $out[] = '';
        }
    }
    return rtrim(implode("\n", $out)) . "\n";
}

/** /llms-full.txt: markdown con una sección por ruta live (título, URL, descripción, FAQ), definiciones del glosario y extractos del blog. */
function llms_full_txt(): string
{
    $out = llms_head();
    $groups = llms_sections();
    $order = ['home', 'services', 'company', 'resources', 'blog', 'optional'];
    foreach ($order as $g) {
        foreach ($groups[$g] as $path => $r) {
            $title = llms_title($path, $r);
            if ($title === '') {
                continue;
            }
            $out[] = '## ' . $title;
            $out[] = '';
            $out[] = 'URL: ' . abs_url($path);
            $out[] = '';
            $desc = llms_clean((string) ($r['meta'] ?? ''));
            if ($desc !== '') {
                $out[] = $desc;
                $out[] = '';
            }

            // Bloque "In short" de la página (misma fuente que la plantilla: app/data/summaries.php): la frase que un motor generativo extrae.
            $sum = summary_for($r + ['path' => $path]);
            $sumText = llms_clean((string) ($sum['text'] ?? ''));
            if ($sumText !== '') {
                $out[] = '**In short:** ' . $sumText;
                $out[] = '';
                foreach (array_slice((array) ($sum['points'] ?? []), 0, 3) as $pt) {
                    $pt = llms_clean((string) $pt);
                    if ($pt !== '') {
                        $out[] = '- ' . $pt;
                    }
                }
                $out[] = '';
            }

            // Artículos: categoría, fechas reales (si existen), entrada y extracto de posts.php.
            if (($r['template'] ?? '') === 'post') {
                $post = post_by_slug((string) ($r['slug'] ?? ''));
                if ($post) {
                    $d = post_dates($post);
                    $meta = array_filter([
                        'Category: ' . llms_clean((string) ($post['category'] ?? '')),
                        !empty($post['minutes']) ? (int) $post['minutes'] . ' min read' : '',
                        $d['published'] !== '' ? 'Published: ' . $d['published'] : '',
                        $d['modified'] !== '' ? 'Last updated: ' . $d['modified'] : '',
                    ], static fn(string $x): bool => $x !== '' && $x !== 'Category: ');
                    if ($meta) {
                        $out[] = implode(' · ', $meta);
                        $out[] = '';
                    }
                    foreach (['lead', 'excerpt'] as $k) {
                        $t = llms_clean((string) ($post[$k] ?? ''));
                        if ($t !== '' && $t !== $desc) {
                            $out[] = $t;
                            $out[] = '';
                        }
                    }
                }
            }

            // Glosario: definiciones de app/data/glossary.php.
            if (($r['template'] ?? '') === 'glossary') {
                $defs = [];
                foreach (data('glossary') as $t) {
                    $term = llms_clean((string) ($t['term'] ?? ''));
                    $def = llms_clean((string) ($t['def'] ?? ''));
                    if ($term !== '' && $def !== '') {
                        $defs[] = '- **' . $term . '**: ' . $def;
                    }
                }
                if ($defs) {
                    $out[] = '### Definitions';
                    $out[] = '';
                    array_push($out, ...$defs);
                    $out[] = '';
                }
            }

            // FAQ: mismos ítems que el sitio, SIEMPRE sin placeholders (como en producción).
            $faq = [];
            foreach (route_faq_items($r) as $it) {
                $q = llms_clean((string) ($it['q'] ?? ''));
                $a = llms_clean((string) ($it['a'] ?? ''));
                if ($q === '' || $a === '') {
                    continue;
                }
                $block = ['#### ' . $q, '', $a];
                $lp = (string) ($it['link']['path'] ?? '');
                $ll = llms_clean((string) ($it['link']['label'] ?? ''));
                if ($lp !== '' && $ll !== '' && route_is_live($lp)) {
                    $block[] = '';
                    $block[] = 'Related: [' . $ll . '](' . abs_url($lp) . ')';
                }
                $block[] = '';
                $faq[] = $block;
            }
            if ($faq) {
                $out[] = '### Frequently asked questions';
                $out[] = '';
                foreach ($faq as $b) {
                    array_push($out, ...$b);
                }
            }
        }
    }
    return rtrim(implode("\n", $out)) . "\n";
}
