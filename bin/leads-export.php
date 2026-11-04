<?php
/**
 * Exporta los leads de la solicitud (/careers/apply/) a CSV para Carlos. Sin panel ni base de datos: los leads viven en storage/leads/AAAA-MM.jsonl (una línea JSON por lead,
 * permisos 0600, fuera de web) y este script los aplana.
 *
 * Uso (desde la raíz del repo, en el servidor por SSH o en local con una copia de storage/leads/):
 *   php bin/leads-export.php                          todos los meses, CSV por la salida estándar
 *   php bin/leads-export.php --out=leads.csv          escribe el archivo (se crea con permisos 0600)
 *   php bin/leads-export.php --month=2026-11          solo ese mes
 *   php bin/leads-export.php --profile=owner-operator solo ese perfil (owner-operator | small-fleet | company-driver)
 *   php bin/leads-export.php --qualified=1            solo los que cumplen la regla interna (CDL estándar Sí + inglés Sí/Básico + años mínimos); --qualified=0 los demás
 *   php bin/leads-export.php --since=2026-11-01       desde esa fecha (UTC, por created_at)
 *   php bin/leads-export.php --dir=/ruta/a/leads      otra carpeta con los .jsonl
 *
 * Columnas: id, created_at (UTC), profile, job, qualified, name, phone, email, state, language, contact_pref, best_time, trucks, equipment (separado por "; "), years, authority,
 * cdl_standard, english, consent_given, consent_version, consent_at, consent_ip, consent_text, utm_source, utm_medium, utm_campaign, utm_term, utm_content, referrer, landing, first_seen,
 * ip_hash, user_agent. Las líneas que no se pueden leer se saltan y se avisan por la salida de errores. Las celdas que empiezan por = + - @ llevan un apóstrofo delante para que Excel
 * y Google Sheets no las interpreten como fórmulas (los teléfonos EE. UU. salen como "+1 863 555 0101" para que la hoja los deje como texto). El CSV lleva BOM UTF-8 (Excel abre bien "Español" y los acentos). Contiene datos personales: no lo subas a ningún sitio público.
 *
 * bin/ está bloqueado en la web (.htaccess e index.php); aun así el script solo corre por línea de comandos.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['out::', 'month::', 'profile::', 'qualified::', 'since::', 'dir::', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Uso: php bin/leads-export.php [--out=archivo.csv] [--month=AAAA-MM] [--profile=owner-operator|small-fleet|company-driver] [--qualified=1|0] [--since=AAAA-MM-DD] [--dir=carpeta]\n");
    exit(0);
}
$dir = isset($opts['dir']) && is_string($opts['dir']) && $opts['dir'] !== '' ? rtrim($opts['dir'], '/') : dirname(__DIR__) . '/storage/leads';
$month = isset($opts['month']) && is_string($opts['month']) ? $opts['month'] : '';
if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
    fwrite(STDERR, "--month debe ser AAAA-MM\n");
    exit(2);
}
$since = isset($opts['since']) && is_string($opts['since']) ? $opts['since'] : '';
if ($since !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) !== 1) {
    fwrite(STDERR, "--since debe ser AAAA-MM-DD\n");
    exit(2);
}
$profile = isset($opts['profile']) && is_string($opts['profile']) ? $opts['profile'] : '';
$qualified = isset($opts['qualified']) && is_string($opts['qualified']) && $opts['qualified'] !== '' ? ($opts['qualified'] === '1') : null;

$files = glob($dir . '/' . ($month !== '' ? $month : '*') . '.jsonl') ?: [];
sort($files);
if (!is_dir($dir) || !$files) {
    fwrite(STDERR, "No hay leads en $dir" . ($month !== '' ? " para $month" : '') . ".\n");
    exit(1);
}

$cols = ['id', 'created_at', 'profile', 'job', 'qualified', 'name', 'phone', 'email', 'state', 'language', 'contact_pref', 'best_time', 'trucks', 'equipment', 'years', 'authority',
    'cdl_standard', 'english', 'consent_given', 'consent_version', 'consent_at', 'consent_ip', 'consent_text', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
    'referrer', 'landing', 'first_seen', 'ip_hash', 'user_agent'];

/** Valor de celda: booleanos como yes/no, listas con "; " y protección contra fórmulas de hoja de cálculo. */
$cell = static function (mixed $v): string {
    if (is_bool($v)) {
        return $v ? 'yes' : 'no';
    }
    if (is_array($v)) {
        $v = implode('; ', array_map('strval', $v));
    }
    $s = (string) ($v ?? '');
    if ($s === '') {
        return '';
    }
    // Fórmulas: = y @ siempre; + y - solo si no son un teléfono o un número con ese aspecto (los teléfonos E.164 se imprimen con espacios y quedan como texto).
    if ($s[0] === '=' || $s[0] === '@' || (($s[0] === '+' || $s[0] === '-') && preg_match('/^[+-]?\d[\d\s.()-]*$/', $s) !== 1)) {
        return "'" . $s;
    }
    return $s;
};

$outPath = isset($opts['out']) && is_string($opts['out']) && $opts['out'] !== '' ? $opts['out'] : '';
if ($outPath !== '') {
    $fh = fopen($outPath, 'wb');
    if ($fh === false) {
        fwrite(STDERR, "No se pudo escribir $outPath\n");
        exit(1);
    }
    @chmod($outPath, 0600);
} else {
    $fh = STDOUT;
}
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, $cols, ',', '"', '');

$n = 0;
$skipped = 0;
foreach ($files as $f) {
    $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $i => $line) {
        $l = json_decode($line, true);
        if (!is_array($l) || !isset($l['id'])) {
            $skipped++;
            fwrite(STDERR, 'Línea ilegible: ' . basename($f) . ':' . ($i + 1) . "\n");
            continue;
        }
        if ($profile !== '' && ($l['profile'] ?? '') !== $profile) {
            continue;
        }
        if ($qualified !== null && (bool) ($l['qualified'] ?? false) !== $qualified) {
            continue;
        }
        if ($since !== '' && substr((string) ($l['created_at'] ?? ''), 0, 10) < $since) {
            continue;
        }
        $c = is_array($l['consent'] ?? null) ? $l['consent'] : [];
        $a = is_array($l['attribution'] ?? null) ? $l['attribution'] : [];
        $m = is_array($l['meta'] ?? null) ? $l['meta'] : [];
        $row = $l + [
            'consent_given' => $c['given'] ?? false, 'consent_version' => $c['version'] ?? '', 'consent_at' => $c['at'] ?? '', 'consent_ip' => $c['ip'] ?? '', 'consent_text' => $c['text'] ?? '',
            'ip_hash' => $m['ip_hash'] ?? '', 'user_agent' => $m['user_agent'] ?? '',
        ] + $a;
        if (is_string($row['phone'] ?? null) && preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', $row['phone'], $pm) === 1) {
            $row['phone'] = '+1 ' . $pm[1] . ' ' . $pm[2] . ' ' . $pm[3];   // "+1 863 555 0101": legible y sin que la hoja de cálculo lo convierta en número
        }
        fputcsv($fh, array_map(static fn(string $k): string => $cell($row[$k] ?? ''), $cols), ',', '"', '');
        $n++;
    }
}
if ($outPath !== '') {
    fclose($fh);
}
fwrite(STDERR, "$n leads exportados" . ($skipped ? ", $skipped líneas saltadas" : '') . ".\n");
