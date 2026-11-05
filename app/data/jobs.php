<?php
/**
 * Vacantes REALES de company drivers (CDL-A). FUENTE: el panel de captacion (captacion/) escribe storage/jobs.json al publicar, pausar o cerrar una vacante (Vacantes > Publicar);
 * este archivo lo lee, valida y sanea cada campo (ns_jobs_from_file) y IGNORA lo invalido. Si el JSON falta, esta vacio o corrupto, el resultado es el arreglo vacio: el sitio nunca falla por esto.
 * Tambien puedes declarar vacantes a mano en $static (misma estructura), por si el panel no se usa.
 * Vacío = no hay vacantes: /careers/cdl-a-drivers/ queda en 404 (draft), el hub /careers/ lo dice con honestidad
 * ("No openings listed right now") y el formulario no ofrece la opción "company driver". NO inventes vacantes: cada entrada publica una página con JSON-LD JobPosting.
 *
 * Cada clave es el slug de la URL: /careers/cdl-a-drivers/{slug}/. Una vacante solo es "activa" (en el sitemap, indexable y con JobPosting) si
 *   status === 'active'  Y  posted <= hoy  Y  valid_through >= hoy.
 * Al pasar valid_through, la página deja de existir sola (404) y sale del sitemap. Los campos opcionales que falten simplemente no se imprimen
 * (ni en la página ni en el JSON-LD); `pay` NO se inventa: sin el dato no hay baseSalary.
 *
 * Estructura (ejemplo comentado; copia, rellena con datos confirmados por el cliente y quita los comentarios):
 *
 * 'regional-cdl-a-driver' => [
 *     'status'          => 'active',                 // active | paused
 *     'title'           => 'Regional CDL-A Company Driver',
 *     'posted'          => '2026-11-10',             // YYYY-MM-DD, fecha real de publicación (datePosted)
 *     'valid_through'   => '2026-12-31',             // YYYY-MM-DD, obligatorio para activarla (validThrough)
 *     'employment_type' => 'FULL_TIME',              // FULL_TIME | PART_TIME | CONTRACTOR | TEMPORARY
 *     'location'        => ['city' => 'Lakeland', 'region' => 'FL', 'postal' => '33801', 'country' => 'US'],   // lugar de trabajo o base
 *     'summary'         => 'Una o dos frases: qué es el puesto.',
 *     'equipment'       => 'Dry van',                // opcional
 *     'responsibilities' => ['…', '…'],               // opcional (lista)
 *     'requirements'    => ['Standard CDL-A', '…'],  // opcional (lista)
 *     'benefits'        => ['…'],                    // opcional (lista; solo beneficios confirmados)
 *     'pay'             => ['min' => 0, 'max' => 0, 'unit' => 'WEEK', 'currency' => 'USD'],   // opcional; unit: HOUR | WEEK | MONTH | YEAR (sin cifras inventadas)
 * ],
 */

if (!function_exists('ns_jobs_from_file')) {
    /** Texto de una linea, sin caracteres de control ni < >, con largo maximo. */
    function ns_jobs_text(mixed $v, int $max): string
    {
        if (!is_string($v)) {
            return '';
        }
        $v = strip_tags($v);
        $v = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
        $v = str_replace(['<', '>'], '', $v);
        return trim((string) mb_substr(trim((string) preg_replace('/\s{2,}/u', ' ', $v)), 0, $max, 'UTF-8'));
    }

    function ns_jobs_date(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
    }

    /** @return list<string> */
    function ns_jobs_list(mixed $v): array
    {
        $out = [];
        foreach (is_array($v) ? array_slice($v, 0, 15) : [] as $x) {
            $t = ns_jobs_text($x, 240);
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** Una vacante del JSON ya saneada, o null si le falta algo obligatorio (titulo, fechas, estado). */
    function ns_jobs_clean(mixed $slug, mixed $j): ?array
    {
        if (!is_string($slug) || preg_match('/^[a-z0-9][a-z0-9-]{1,80}$/', $slug) !== 1 || !is_array($j)) {
            return null;
        }
        $title = ns_jobs_text($j['title'] ?? '', 90);
        $posted = ns_jobs_date($j['posted'] ?? null);
        $until = ns_jobs_date($j['valid_through'] ?? null);
        $status = $j['status'] ?? '';
        if ($title === '' || $posted === null || $until === null || !in_array($status, ['active', 'paused'], true)) {
            return null;
        }
        $type = $j['employment_type'] ?? 'FULL_TIME';
        $out = ['status' => $status, 'title' => $title, 'posted' => $posted, 'valid_through' => $until, 'employment_type' => in_array($type, ['FULL_TIME', 'PART_TIME', 'CONTRACTOR', 'TEMPORARY'], true) ? $type : 'FULL_TIME'];
        $loc = is_array($j['location'] ?? null) ? $j['location'] : [];
        $region = strtoupper(ns_jobs_text($loc['region'] ?? '', 3));
        $postal = ns_jobs_text($loc['postal'] ?? '', 10);
        $location = array_filter([
            'city' => ns_jobs_text($loc['city'] ?? '', 80), 'region' => preg_match('/^[A-Z]{2}$/', $region) === 1 ? $region : '', 'postal' => preg_match('/^\d{5}(-\d{4})?$/', $postal) === 1 ? $postal : '', 'country' => 'US',
        ], static fn($x) => $x !== '');
        $out['location'] = $location;
        foreach (['summary' => 500, 'equipment' => 80] as $k => $max) {
            $t = ns_jobs_text($j[$k] ?? '', $max);
            if ($t !== '') {
                $out[$k] = $t;
            }
        }
        foreach (['responsibilities', 'requirements', 'benefits'] as $k) {
            $l = ns_jobs_list($j[$k] ?? null);
            if ($l) {
                $out[$k] = $l;
            }
        }
        $pay = $j['pay'] ?? null;
        if (is_array($pay) && in_array($pay['unit'] ?? '', ['HOUR', 'WEEK', 'MONTH', 'YEAR'], true)) {
            $min = isset($pay['min']) && (is_int($pay['min']) || is_float($pay['min'])) && $pay['min'] > 0 && $pay['min'] < 10000000 ? round((float) $pay['min'], 2) : null;
            $max = isset($pay['max']) && (is_int($pay['max']) || is_float($pay['max'])) && $pay['max'] > 0 && $pay['max'] < 10000000 ? round((float) $pay['max'], 2) : null;
            if (($min !== null || $max !== null) && ($min === null || $max === null || $min <= $max)) {
                $out['pay'] = array_filter(['min' => $min, 'max' => $max, 'unit' => $pay['unit'], 'currency' => 'USD'], static fn($x) => $x !== null);
            }
        }
        return $out;
    }

    /** Lee storage/jobs.json (esquema 1). Cualquier problema (falta, vacio, enorme, corrupto, esquema distinto) devuelve []. Nunca lanza. */
    function ns_jobs_from_file(string $file): array
    {
        try {
            clearstatcache(true, $file);
            if (!is_file($file) || !is_readable($file)) {
                return [];
            }
            $size = @filesize($file);
            if ($size === false || $size < 20 || $size > 262144) {
                return [];
            }
            $raw = @file_get_contents($file);
            $data = is_string($raw) ? json_decode($raw, true, 6) : null;
            if (!is_array($data) || ($data['schema'] ?? null) !== 1 || !is_array($data['jobs'] ?? null)) {
                return [];
            }
            $out = [];
            foreach (array_slice($data['jobs'], 0, 50, true) as $slug => $j) {
                $c = ns_jobs_clean($slug, $j);
                if ($c !== null) {
                    $out[$slug] = $c;
                }
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }
}

$static = [];   // vacantes escritas a mano (vacio)

return $static + ns_jobs_from_file(NS_ROOT . '/storage/jobs.json');
