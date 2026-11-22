<?php
/**
 * Importacion CSV de leads (fase AM, ruta /importar, solo administradores).
 *
 *  - Subida: hasta 2 MB y 2.000 filas; UTF-8 (con o sin BOM), UTF-16 con BOM o Windows-1252; separador autodetectado (coma, punto y coma, tabulador o barra vertical). El archivo se guarda
 *    como UTF-8 normalizado en storage/imports/<token>.csv (0600, directorio 0750) y se BORRA al terminar la importacion o al descartarla.
 *  - Mapeo de columnas con autodetección por nombre del encabezado (inglés o español), vista previa y SIMULACION que no escribe nada: cuantos se crearian, cuantos son duplicados (y de
 *    que lead, o de que fila del mismo archivo) y cuantos se rechazan y por que (sin nombre, teléfono o correo inválido, sin teléfono ni correo, en la lista de supresión).
 *  - Importacion real por la cola (job `lead_import`, 50 filas por pasada) usando LeadIngest: validacion, Dedupe, FMCSA diferido, puntuacion y reglas, con fuente `csv_import` y la
 *    etiqueta `tag:import-<archivo>` en las banderas del lead. Sin alertas de Telegram ni correos de aviso por cada importado (`quiet`).
 *  - El consentimiento de los importados es SIEMPRE «sin consentimiento»: un CSV no puede probarlo. Cualquier columna de consentimiento se ignora.
 *  - Los telefonos y correos de la lista de supresion NO se importan.
 *  - Duplicados: por defecto se omiten sin tocar el lead existente; con la opcion «completar» pasan por el motor como un reingreso (rellena campos vacios y deja un evento).
 *  - Lo que se vuelva a exportar pasa por Csv::cell (protege contra formulas); aqui se guarda el dato tal cual llega, sin interpretarlo.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class LeadImport
{
    public const MAX_BYTES = 2097152;
    public const MAX_ROWS = 2000;
    public const BATCH = 50;
    public const DELIMITERS = [',' => 'coma', ';' => 'punto y coma', "\t" => 'tabulador', '|' => 'barra vertical'];
    public const PROBLEMS_MAX = 100;

    /** campo del lead => etiqueta del selector de mapeo */
    public const FIELDS = [
        'name' => 'Nombre completo', 'first_name' => 'Nombre', 'last_name' => 'Apellido', 'phone' => 'Teléfono', 'email' => 'Correo', 'state' => 'Estado de EE. UU.', 'city' => 'Ciudad',
        'profile' => 'Perfil (owner-operator, small fleet, company driver)', 'language' => 'Idioma (en o es)', 'equipment' => 'Equipo', 'trucks' => 'Camiones', 'years' => 'Años de experiencia',
        'usdot' => 'USDOT', 'authority' => 'Autoridad (active, getting, none)',
    ];

    private const ALIASES = [
        'name' => ['name', 'fullname', 'nombre', 'nombrecompleto', 'contact', 'contactname', 'driver', 'drivername', 'owner', 'ownername'],
        'first_name' => ['firstname', 'first', 'givenname', 'primernombre', 'nombres'],
        'last_name' => ['lastname', 'last', 'surname', 'apellido', 'apellidos', 'familyname'],
        'phone' => ['phone', 'phonenumber', 'telefono', 'tel', 'mobile', 'cell', 'cellphone', 'celular', 'whatsapp', 'contactphone', 'movil'],
        'email' => ['email', 'correo', 'correoelectronico', 'emailaddress', 'mail', 'eaddress'],
        'state' => ['state', 'estado', 'st', 'region', 'province'],
        'city' => ['city', 'ciudad', 'town'],
        'profile' => ['profile', 'perfil', 'type', 'tipo', 'leadtype'],
        'language' => ['language', 'idioma', 'lang', 'lenguaje'],
        'equipment' => ['equipment', 'equipo', 'trucktype', 'equipmenttype', 'tipodeequipo'],
        'trucks' => ['trucks', 'trucknumber', 'camiones', 'units', 'powerunits', 'fleetsize', 'numberoftrucks'],
        'years' => ['years', 'yearsexperience', 'experience', 'experiencia', 'anos', 'yearsofexperience', 'anosdeexperiencia'],
        'usdot' => ['usdot', 'dot', 'usdotnumber', 'dotnumber'],
        'authority' => ['authority', 'autoridad', 'authoritystatus'],
    ];

    // --- Archivo ---------------------------------------------------------------------------------------------------------------

    public static function dir(): string
    {
        return Storage::dir('imports');
    }

    public static function path(string $token): string
    {
        return self::dir() . '/' . $token . '.csv';
    }

    /** @return array{0:string,1:string} [texto UTF-8 sin BOM, codificacion detectada] */
    public static function decode(string $raw): array
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return [substr($raw, 3), 'UTF-8'];
        }
        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            return [mb_convert_encoding(substr($raw, 2), 'UTF-8', str_starts_with($raw, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE'), 'UTF-16'];
        }
        if (mb_check_encoding($raw, 'UTF-8')) {
            return [$raw, 'UTF-8'];
        }
        return [mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'), 'Windows-1252'];
    }

    /** Separador mas frecuente en la primera linea (fuera de comillas). */
    public static function detectDelimiter(string $text): string
    {
        $first = '';
        $inQ = false;
        $counts = array_fill_keys(array_keys(self::DELIMITERS), 0);
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];
            if ($c === '"') {
                $inQ = !$inQ;
            } elseif (!$inQ && ($c === "\n" || $c === "\r")) {
                break;
            } elseif (!$inQ && isset($counts[$c])) {
                $counts[$c]++;
            }
        }
        arsort($counts);
        $best = (string) array_key_first($counts);
        return $counts[$best] > 0 ? $best : ',';
    }

    /**
     * Lee el archivo. @return array{headers:list<string>,rows:list<array{row:int,cells:list<string>}>,too_many:bool}
     */
    public static function read(string $path, string $delimiter, int $maxRows = self::MAX_ROWS): array
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return ['headers' => [], 'rows' => [], 'too_many' => false];
        }
        $headers = null;
        $rows = [];
        $rec = 0;
        $tooMany = false;
        while (($r = fgetcsv($h, 0, $delimiter, '"', '')) !== false) {
            $rec++;
            if ($r === [null]) {
                continue;   // linea en blanco
            }
            $cells = array_map(static fn($c) => trim((string) $c), $r);
            if ($headers === null) {
                $headers = $cells;
                continue;
            }
            if (implode('', $cells) === '') {
                continue;
            }
            if (count($rows) >= $maxRows) {
                $tooMany = true;
                break;
            }
            $rows[] = ['row' => $rec, 'cells' => $cells];
        }
        fclose($h);
        return ['headers' => $headers ?? [], 'rows' => $rows, 'too_many' => $tooMany];
    }

    /** Sin acentos, minusculas y sin signos: «Teléfono móvil» -> «telefonomovil». */
    public static function headerKey(string $h): string
    {
        $h = strtr(mb_strtolower($h), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return (string) preg_replace('/[^a-z0-9]+/', '', $h);
    }

    /** Autodeteccion: columna => campo (cada campo se usa una sola vez). @param list<string> $headers @return array<int,string> */
    public static function suggest(array $headers): array
    {
        $map = [];
        $used = [];
        foreach ($headers as $i => $h) {
            $k = self::headerKey($h);
            if ($k === '') {
                continue;
            }
            foreach (self::ALIASES as $field => $aliases) {
                if (!isset($used[$field]) && in_array($k, $aliases, true)) {
                    $map[$i] = $field;
                    $used[$field] = true;
                    break;
                }
            }
        }
        return $map;
    }

    /** Etiqueta de origen a partir del nombre del archivo: «Leads Nov 2026.csv» -> «import-leads-nov-2026». */
    public static function tag(string $filename): string
    {
        $base = (string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', basename($filename));
        $s = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base));
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
        return 'import-' . substr($s !== '' ? $s : 'archivo', 0, 30);
    }

    /**
     * Valida y guarda una subida. @param array<string,mixed> $file entrada de $_FILES
     * @return array{ok:bool,msg:string,id?:int}
     */
    public static function store(array $file, int $userId, bool $trustUpload = false): array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return ['ok' => false, 'msg' => 'El archivo pesa más de 2 MB.'];
        }
        if ($err !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || (!$trustUpload && !is_uploaded_file($file['tmp_name']))) {
            return ['ok' => false, 'msg' => 'Elige un archivo CSV para subir.'];
        }
        $name = mb_substr((string) preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename((string) ($file['name'] ?? 'archivo.csv'))), 0, 150);
        if (preg_match('/\.(csv|txt)$/i', $name) !== 1) {
            return ['ok' => false, 'msg' => 'El archivo tiene que ser un .csv (o .txt con valores separados).'];
        }
        $size = (int) @filesize($file['tmp_name']);
        if ($size <= 0) {
            return ['ok' => false, 'msg' => 'El archivo está vacío.'];
        }
        if ($size > self::MAX_BYTES) {
            return ['ok' => false, 'msg' => 'El archivo pesa más de 2 MB.'];
        }
        $raw = (string) file_get_contents($file['tmp_name']);
        if (str_contains($raw, "\0") && !str_starts_with($raw, "\xFF\xFE") && !str_starts_with($raw, "\xFE\xFF")) {
            return ['ok' => false, 'msg' => 'Eso no parece un CSV de texto (tiene datos binarios).'];
        }
        [$text, $enc] = self::decode($raw);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $delim = self::detectDelimiter($text);
        $dir = self::dir();
        if (!Storage::ensure($dir, 0750)) {
            return ['ok' => false, 'msg' => 'El panel no puede guardar archivos en storage/imports.'];
        }
        $token = bin2hex(random_bytes(16));
        $path = self::path($token);
        if (@file_put_contents($path, $text) === false) {
            return ['ok' => false, 'msg' => 'No se pudo guardar el archivo.'];
        }
        @chmod($path, 0600);
        $data = self::read($path, $delim);
        if (!$data['headers'] || count($data['headers']) < 1) {
            @unlink($path);
            return ['ok' => false, 'msg' => 'No se encontró una fila de encabezados en el archivo.'];
        }
        if ($data['too_many']) {
            @unlink($path);
            return ['ok' => false, 'msg' => 'El archivo tiene más de ' . number_format(self::MAX_ROWS, 0, ',', '.') . ' filas. Divídelo en varios archivos.'];
        }
        if (!$data['rows']) {
            @unlink($path);
            return ['ok' => false, 'msg' => 'El archivo solo tiene encabezados: no hay filas para importar.'];
        }
        $id = Db::insert('lead_imports', ['user_id' => $userId, 'token' => $token, 'filename' => $name, 'tag' => self::tag($name), 'status' => 'uploaded', 'delimiter_char' => $delim, 'encoding' => $enc, 'total_rows' => count($data['rows'])]);
        ActivityLog::record('lead_import_upload', $userId, 'lead_import', $id, ['rows' => count($data['rows'])]);
        return ['ok' => true, 'msg' => 'Archivo subido: ' . count($data['rows']) . ' filas.', 'id' => $id];
    }

    public static function find(int $id): ?array
    {
        $r = Db::one('SELECT * FROM lead_imports WHERE id = ?', [$id]);
        return $r;
    }

    /** Borra el archivo del disco (idempotente). */
    public static function dropFile(array $imp): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', (string) $imp['token']) === 1) {
            @unlink(self::path((string) $imp['token']));
        }
    }

    // --- Mapeo y filas -----------------------------------------------------------------------------------------------------------

    /**
     * Mapeo y opciones del formulario. @param array<string,mixed> $post
     * @return array{mapping:array<int,string>,options:array{profile:string,skip_duplicates:bool},error:string}
     */
    public static function parseForm(array $post, int $columns): array
    {
        $mapping = [];
        $seen = [];
        $raw = is_array($post['map'] ?? null) ? $post['map'] : [];
        for ($i = 0; $i < $columns; $i++) {
            $f = is_string($raw[$i] ?? null) ? $raw[$i] : '';
            if ($f === '' || !isset(self::FIELDS[$f])) {
                continue;
            }
            if (isset($seen[$f])) {
                return ['mapping' => [], 'options' => ['profile' => 'owner_operator', 'skip_duplicates' => true], 'error' => 'Dos columnas apuntan al mismo campo («' . self::FIELDS[$f] . '»). Elige una sola.'];
            }
            $seen[$f] = true;
            $mapping[$i] = $f;
        }
        $profile = is_string($post['profile'] ?? null) && in_array($post['profile'], LeadPayload::PROFILES, true) ? $post['profile'] : '';
        $opts = ['profile' => $profile, 'skip_duplicates' => ($post['dup'] ?? 'skip') !== 'fill'];
        $err = '';
        if ($profile === '' && !isset($seen['profile'])) {
            $err = 'Elige el perfil de los leads (todos los del archivo lo tendrán) o asigna una columna de perfil.';
        } elseif (!isset($seen['phone']) && !isset($seen['email'])) {
            $err = 'Asigna al menos una columna de teléfono o de correo.';
        } elseif (!isset($seen['name']) && !isset($seen['first_name'])) {
            $err = 'Asigna una columna de nombre (completo o solo el nombre).';
        }
        return ['mapping' => $mapping, 'options' => $opts + ['profile' => $profile], 'error' => $err];
    }

    /** Entrada del motor a partir de una fila. @param list<string> $cells @param array<int,string> $mapping */
    public static function input(array $cells, array $mapping, array $options): array
    {
        $in = [];
        foreach ($mapping as $i => $field) {
            $v = trim((string) ($cells[$i] ?? ''));
            if ($v !== '') {
                $in[$field] = $v;
            }
        }
        $p = strtolower((string) preg_replace('/[\s-]+/', '_', (string) ($in['profile'] ?? '')));
        $p = ['small_fleet' => 'small_carrier', 'carrier' => 'small_carrier', 'driver' => 'company_driver'][$p] ?? $p;
        $in['profile'] = in_array($p, LeadPayload::PROFILES, true) ? $p : (string) ($options['profile'] ?? '');
        unset($in['consent'], $in['site_lead_id'], $in['created_at'], $in['id']);   // un CSV no puede traer consentimiento ni identificadores del sitio
        $in['consent'] = ['given' => false];
        return $in;
    }

    private const REASONS = [
        'name' => 'Falta el nombre', 'email' => 'Correo inválido', 'invalid_phone' => 'Teléfono inválido', 'missing_contact' => 'Sin teléfono ni correo', 'profile' => 'Perfil inválido',
        'suppressed' => 'En la lista de supresión (STOP o borrado a petición)',
    ];

    /**
     * Decide que pasaria con una fila SIN escribir nada. @param array<string,true|int> $seen claves ya vistas en el archivo (se actualiza)
     * @return array{status:'create'|'duplicate'|'reject',reason:string,cat:string,lead_id:?int,in_file:?int} cat = motivo sin numeros, para agrupar
     */
    public static function evaluate(array $in, array &$seen, int $row): array
    {
        $n = LeadPayload::normalize($in);
        if ($n['errors']) {
            $k = isset($n['errors']['name']) ? 'name' : (isset($n['errors']['email']) ? 'email' : (isset($n['errors']['contact']) ? (string) $n['errors']['contact'] : 'profile'));
            $why = self::REASONS[$k] ?? 'Datos inválidos';
            return ['status' => 'reject', 'reason' => $why, 'cat' => $why, 'lead_id' => null, 'in_file' => null];
        }
        $l = $n['lead'];
        if (Suppression::isListed($l['phone_e164'], $l['email'])) {
            return ['status' => 'reject', 'reason' => self::REASONS['suppressed'], 'cat' => self::REASONS['suppressed'], 'lead_id' => null, 'in_file' => null];
        }
        foreach (array_filter([$l['phone_e164'], $l['email']]) as $key) {
            if (isset($seen[$key])) {
                return ['status' => 'duplicate', 'reason' => 'Repetido en el archivo (fila ' . (int) $seen[$key] . ')', 'cat' => 'Repetido en el archivo', 'lead_id' => null, 'in_file' => (int) $seen[$key]];
            }
        }
        $existing = Dedupe::find($l['phone_e164'], $l['email']);
        foreach (array_filter([$l['phone_e164'], $l['email']]) as $key) {
            $seen[$key] = $row;
        }
        if ($existing !== null) {
            return ['status' => 'duplicate', 'reason' => 'Ya existe el lead ' . Fmt::code((int) $existing['id']), 'cat' => 'Ya existe un lead con ese teléfono o correo', 'lead_id' => (int) $existing['id'], 'in_file' => null];
        }
        return ['status' => 'create', 'reason' => '', 'lead_id' => null, 'in_file' => null];
    }

    /**
     * SIMULACION: no escribe nada. @return array{total:int,create:int,duplicate:int,reject:int,reasons:array<string,int>,problems:list<array{row:int,status:string,reason:string,lead_id:?int}>,sample:list<array{row:int,name:string,contact:string}>}
     */
    public static function simulate(array $imp, array $mapping, array $options): array
    {
        $data = self::read(self::path((string) $imp['token']), (string) $imp['delimiter_char']);
        $out = ['total' => count($data['rows']), 'create' => 0, 'duplicate' => 0, 'reject' => 0, 'reasons' => [], 'problems' => [], 'sample' => []];
        $seen = [];
        foreach ($data['rows'] as $r) {
            $in = self::input($r['cells'], $mapping, $options);
            $e = self::evaluate($in, $seen, (int) $r['row']);
            $out[$e['status']]++;
            if ($e['status'] !== 'create') {
                $out['reasons'][$e['cat']] = ($out['reasons'][$e['cat']] ?? 0) + 1;
                if (count($out['problems']) < self::PROBLEMS_MAX) {
                    $out['problems'][] = ['row' => (int) $r['row'], 'status' => $e['status'], 'reason' => $e['reason'], 'lead_id' => $e['lead_id']];
                }
            } elseif (count($out['sample']) < 5) {
                $out['sample'][] = ['row' => (int) $r['row'], 'name' => trim((string) (($in['name'] ?? '') !== '' ? $in['name'] : (($in['first_name'] ?? '') . ' ' . ($in['last_name'] ?? '')))), 'contact' => (string) ($in['phone'] ?? ($in['email'] ?? ''))];
            }
        }
        arsort($out['reasons']);
        return $out;
    }

    // --- Importacion real (cola) ---------------------------------------------------------------------------------------------------

    /** Guarda mapeo y opciones y encola la primera pasada. */
    public static function start(array $imp, array $mapping, array $options, int $userId): void
    {
        Db::update('lead_imports', (int) $imp['id'], ['status' => 'queued', 'mapping' => $mapping, 'options' => $options, 'started_at' => Clock::now()]);
        Queue::push('lead_import', ['import_id' => (int) $imp['id'], 'offset' => 0], Clock::now(), 'lead_import:' . (int) $imp['id'] . ':0');
        ActivityLog::record('lead_import_start', $userId, 'lead_import', (int) $imp['id'], ['rows' => (int) $imp['total_rows'], 'tag' => $imp['tag'], 'skip_duplicates' => $options['skip_duplicates']]);
    }

    /** Handler del job `lead_import`: una pasada de BATCH filas. */
    public static function jobStep(array $payload, array $job = []): void
    {
        $imp = self::find((int) ($payload['import_id'] ?? 0));
        if ($imp === null || !in_array($imp['status'], ['queued', 'running'], true)) {
            return;
        }
        $offset = max(0, (int) ($payload['offset'] ?? 0));
        $path = self::path((string) $imp['token']);
        if (!is_file($path)) {
            Db::update('lead_imports', (int) $imp['id'], ['status' => 'failed', 'error' => 'El archivo ya no está en el servidor.', 'finished_at' => Clock::now()]);
            throw new PermanentJobFailure('El archivo de la importación #' . (int) $imp['id'] . ' no existe.');
        }
        $mapping = array_map('strval', Db::decode($imp['mapping']));
        $mapping = array_combine(array_map('intval', array_keys($mapping)), array_values($mapping)) ?: [];
        $options = Db::decode($imp['options']) + ['profile' => '', 'skip_duplicates' => true];
        $data = self::read($path, (string) $imp['delimiter_char']);
        $slice = array_slice($data['rows'], $offset, self::BATCH);
        $created = $dups = $rej = 0;
        $problems = Db::decode($imp['problems']);
        $seen = [];
        foreach ($slice as $r) {
            $in = self::input($r['cells'], $mapping, $options);
            $e = self::evaluate($in, $seen, (int) $r['row']);
            $problem = null;
            try {
                if ($e['status'] === 'reject') {
                    $rej++;
                    $problem = ['row' => (int) $r['row'], 'status' => 'reject', 'reason' => $e['reason'], 'lead_id' => null];
                } elseif ($e['status'] === 'duplicate' && !empty($options['skip_duplicates'])) {
                    $dups++;
                    $problem = ['row' => (int) $r['row'], 'status' => 'duplicate', 'reason' => $e['reason'], 'lead_id' => $e['lead_id']];
                } else {
                    $res = LeadIngest::handle($in, 'csv_import', ['skip_antispam' => true, 'quiet' => true, 'fmcsa_sync' => false, 'flags' => ['import:' . substr((string) $imp['tag'], 7)]]);
                    if (!$res['ok']) {
                        $rej++;
                        $problem = ['row' => (int) $r['row'], 'status' => 'reject', 'reason' => 'El motor lo rechazó (' . (string) $res['reason'] . ')', 'lead_id' => null];
                    } elseif ($res['status'] === 'created') {
                        $created++;
                    } else {
                        $dups++;
                        $problem = ['row' => (int) $r['row'], 'status' => 'duplicate', 'reason' => 'Ya existía: se completaron sus campos vacíos', 'lead_id' => (int) $res['lead_id']];
                    }
                }
            } catch (\Throwable $t) {
                $rej++;
                $problem = ['row' => (int) $r['row'], 'status' => 'reject', 'reason' => 'Error interno al guardarlo', 'lead_id' => null];
                Storage::error('lead_import row failed', '#' . (int) $imp['id'] . ' fila ' . (int) $r['row'] . ' ' . get_class($t) . ': ' . $t->getMessage());
            }
            if ($problem !== null && count($problems) < self::PROBLEMS_MAX) {
                $problems[] = $problem;
            }
        }
        $done = $offset + count($slice) >= count($data['rows']);
        $upd = ['status' => $done ? 'done' : 'running', 'processed' => (int) $imp['processed'] + count($slice), 'created_count' => (int) $imp['created_count'] + $created,
            'duplicate_count' => (int) $imp['duplicate_count'] + $dups, 'rejected_count' => (int) $imp['rejected_count'] + $rej, 'problems' => $problems ?: null];
        if ($done) {
            $upd['finished_at'] = Clock::now();
        }
        Db::update('lead_imports', (int) $imp['id'], $upd);
        if ($done) {
            self::dropFile($imp);
            ActivityLog::record('lead_import_done', (int) ($imp['user_id'] ?? 0) ?: null, 'lead_import', (int) $imp['id'], ['created' => $upd['created_count'], 'duplicates' => $upd['duplicate_count'], 'rejected' => $upd['rejected_count']]);
        } else {
            $next = $offset + self::BATCH;
            Queue::push('lead_import', ['import_id' => (int) $imp['id'], 'offset' => $next], Clock::now(), 'lead_import:' . (int) $imp['id'] . ':' . $next);
        }
    }

    /** @return list<array> importaciones recientes */
    public static function recent(int $limit = 10): array
    {
        return Db::all('SELECT id, filename, status, total_rows, processed, created_count, duplicate_count, rejected_count, created_at FROM lead_imports ORDER BY id DESC LIMIT ' . (int) $limit);
    }

    /** Importaciones que se quedaron sin terminar o subidas y abandonadas hace mas de 24 h: borra su archivo. @return int archivos borrados */
    public static function cleanup(): int
    {
        $n = 0;
        foreach (Db::all("SELECT * FROM lead_imports WHERE status IN ('uploaded','failed','cancelled') AND created_at < ?", [Clock::shift(-86400)]) as $imp) {
            if (is_file(self::path((string) $imp['token']))) {
                self::dropFile($imp);
                $n++;
            }
        }
        return $n;
    }
}
