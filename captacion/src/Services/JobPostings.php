<?php
/**
 * Vacantes de company drivers (doc §9.8): alta, edicion, publicacion, pausa y cierre. Cada cambio que afecta al sitio reescribe storage/jobs.json (JobsPublisher) DENTRO de la
 * misma transaccion: si el archivo no se puede escribir, el cambio se revierte y el panel lo dice. status: draft | active | paused | closed.
 * Validacion estricta: sin HTML, fechas reales, valid_through futura para publicar, pago solo si se marca «publicar el pago» y con cifras y unidad.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class JobPostings
{
    public const STATUSES = ['draft' => 'Borrador', 'active' => 'Publicada', 'paused' => 'En pausa', 'closed' => 'Cerrada'];
    public const TYPES = ['FULL_TIME' => 'Tiempo completo', 'PART_TIME' => 'Medio tiempo', 'CONTRACTOR' => 'Contratista', 'TEMPORARY' => 'Temporal'];
    public const UNITS = ['HOUR' => 'por hora', 'WEEK' => 'por semana', 'MONTH' => 'por mes', 'YEAR' => 'por año'];
    public const MAX_JOBS = 50;
    public const LIST_FIELDS = ['responsibilities' => 'Qué hará', 'requirements' => 'Qué necesita', 'benefits' => 'Qué ofrecemos'];

    public static function today(): string
    {
        return ProspectImport::etDate(Clock::time());
    }

    public static function find(int $id): ?array
    {
        $r = Db::one('SELECT * FROM job_postings WHERE id = ? AND deleted_at IS NULL', [$id]);
        return $r === null ? null : self::hydrate($r);
    }

    private static function hydrate(array $r): array
    {
        foreach (array_keys(self::LIST_FIELDS) as $k) {
            $r[$k] = array_values(array_filter(array_map('strval', Db::decode($r[$k] ?? null))));
        }
        return $r;
    }

    /** @return list<array> */
    public static function all(): array
    {
        return array_map([self::class, 'hydrate'], Db::all("SELECT * FROM job_postings WHERE deleted_at IS NULL ORDER BY FIELD(status, 'active', 'paused', 'draft', 'closed'), id DESC LIMIT 200"));
    }

    /** ¿Esta vencida (publicada pero con valid_through anterior a hoy)? */
    public static function expired(array $j): bool
    {
        return $j['status'] === 'active' && $j['valid_through'] !== null && (string) $j['valid_through'] < self::today();
    }

    public static function slugify(string $title): string
    {
        $s = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $s = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $s));
        return substr(trim($s, '-'), 0, 80);
    }

    private static function text(mixed $v, int $max): string
    {
        $s = is_string($v) ? mb_scrub($v) : '';
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        return mb_substr(trim((string) preg_replace('/[ \t]+/u', ' ', $s)), 0, $max);
    }

    private static function hasHtml(string $s): bool
    {
        return preg_match('/[<>]/', $s) === 1 || preg_match('/&(?:lt|gt|#0*60|#0*62|#x0*3c|#x0*3e);/i', $s) === 1;
    }

    /** @return list<string> lineas no vacias de un textarea (maximo 12, cada una de hasta 200 caracteres) */
    private static function lines(mixed $v): array
    {
        $raw = is_array($v) ? implode("\n", array_map('strval', $v)) : (is_string($v) ? $v : '');
        $out = [];
        foreach (preg_split('/\R/u', mb_scrub($raw)) ?: [] as $l) {
            $l = self::text(ltrim($l, "-*• \t"), 400);
            if ($l !== '') {
                $out[] = $l;
            }
        }
        return $out;
    }

    private static function money(mixed $v): array
    {
        $s = str_replace(',', '', trim((string) $v));
        if ($s === '') {
            return [true, null];
        }
        if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $s) !== 1 || (float) $s <= 0) {
            return [false, null];
        }
        return [true, (float) $s];
    }

    private static function date(mixed $v): ?string
    {
        $s = is_string($v) ? trim($v) : '';
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        return $d !== false && $d->format('Y-m-d') === $s && $s >= '2000-01-01' && $s <= '2100-12-31' ? $s : null;
    }

    /**
     * Valida el formulario. @return array{clean:array,errors:array<string,string>}
     * @param bool $forPublish exige lo necesario para que la pagina del sitio salga completa
     */
    public static function validate(array $in, bool $forPublish = false): array
    {
        $err = [];
        $c = [];
        $c['title'] = self::text($in['title'] ?? '', 90);
        if (mb_strlen($c['title']) < 5) {
            $err['title'] = 'El título necesita al menos 5 caracteres.';
        }
        $slug = self::text($in['slug'] ?? '', 80);
        $slug = $slug !== '' ? strtolower($slug) : self::slugify($c['title']);
        if (preg_match('/^[a-z0-9][a-z0-9-]{2,79}$/', $slug) !== 1 || str_contains($slug, '--')) {
            $err['slug'] = 'La dirección (slug) lleva solo letras minúsculas, números y guiones (3 a 80 caracteres).';
        }
        $c['slug'] = $slug;
        $c['posted_on'] = self::date($in['posted_on'] ?? '');
        if ($c['posted_on'] === null) {
            $err['posted_on'] = 'La fecha de publicación no es válida (usa el calendario).';
        }
        $c['valid_through'] = self::date($in['valid_through'] ?? '');
        if ($c['valid_through'] === null) {
            $err['valid_through'] = 'La fecha de vencimiento no es válida (usa el calendario).';
        } elseif ($c['posted_on'] !== null) {
            if ($c['valid_through'] < $c['posted_on']) {
                $err['valid_through'] = 'La vacante no puede vencer antes de su fecha de publicación.';
            } elseif ($c['valid_through'] > (new \DateTimeImmutable($c['posted_on']))->modify('+1 year')->format('Y-m-d')) {
                $err['valid_through'] = 'Una vacante no puede durar más de un año.';
            }
        }
        $type = (string) ($in['employment_type'] ?? 'FULL_TIME');
        $c['employment_type'] = isset(self::TYPES[$type]) ? $type : 'FULL_TIME';
        $c['city'] = self::text($in['city'] ?? '', 80);
        $region = strtoupper(self::text($in['region'] ?? '', 2));
        $c['region'] = $region !== '' && isset(UsStates::NAMES[$region]) ? $region : '';
        if ($region !== '' && $c['region'] === '') {
            $err['region'] = 'Escribe el estado con dos letras (por ejemplo FL).';
        }
        $postal = self::text($in['postal'] ?? '', 10);
        $c['postal'] = preg_match('/^\d{5}(-\d{4})?$/', $postal) === 1 ? $postal : '';
        if ($postal !== '' && $c['postal'] === '') {
            $err['postal'] = 'El código postal debe tener 5 dígitos.';
        }
        $c['summary'] = self::text($in['summary'] ?? '', 500);
        $c['equipment'] = self::text($in['equipment'] ?? '', 80);
        foreach (array_keys(self::LIST_FIELDS) as $k) {
            $c[$k] = self::lines($in[$k] ?? '');
            if (count($c[$k]) > 12) {
                $err[$k] = 'Máximo 12 líneas.';
            }
            foreach ($c[$k] as $l) {
                if (mb_strlen($l) > 200) {
                    $err[$k] = 'Cada línea admite hasta 200 caracteres.';
                    break;
                }
            }
        }
        // Sin HTML en ningun texto
        foreach (['title' => 'título', 'slug' => 'slug', 'city' => 'ciudad', 'summary' => 'resumen', 'equipment' => 'equipo'] as $k => $label) {
            if (self::hasHtml((string) $c[$k])) {
                $err[$k] = 'El ' . $label . ' no puede llevar HTML (< ni >). Escribe texto simple.';
            }
        }
        foreach (array_keys(self::LIST_FIELDS) as $k) {
            foreach ($c[$k] as $l) {
                if (self::hasHtml($l)) {
                    $err[$k] = 'Las líneas no pueden llevar HTML (< ni >). Escribe texto simple.';
                    break;
                }
            }
        }
        // Pago: solo si se marca y con cifras
        $payOn = !empty($in['pay_on']);
        [$okMin, $min] = self::money($in['pay_min'] ?? '');
        [$okMax, $max] = self::money($in['pay_max'] ?? '');
        $unit = (string) ($in['pay_unit'] ?? '');
        $c['pay'] = null;
        if (!$payOn) {
            if ($min !== null || $max !== null || trim((string) ($in['pay_min'] ?? '') . (string) ($in['pay_max'] ?? '')) !== '') {
                $err['pay'] = 'Hay cifras de pago escritas. Marca «Publicar el pago» o bórralas: no se publica pago sin confirmarlo.';
            }
        } else {
            if (!$okMin || !$okMax) {
                $err['pay'] = 'Las cifras de pago deben ser números mayores que 0 (hasta dos decimales).';
            } elseif ($min === null && $max === null) {
                $err['pay'] = 'Escribe el pago mínimo o el máximo, o desmarca «Publicar el pago».';
            } elseif ($min !== null && $max !== null && $min > $max) {
                $err['pay'] = 'El pago mínimo no puede ser mayor que el máximo.';
            } elseif (!isset(self::UNITS[$unit])) {
                $err['pay'] = 'Elige la unidad del pago (por hora, semana, mes o año).';
            } else {
                $c['pay'] = ['min' => $min, 'max' => $max, 'unit' => $unit, 'currency' => 'USD'];
            }
        }
        if ($forPublish) {
            if (mb_strlen($c['summary']) < 20) {
                $err['summary'] = 'Para publicar escribe un resumen de al menos 20 caracteres.';
            }
            if ($c['city'] === '' || $c['region'] === '') {
                $err['city'] = 'Para publicar indica la ciudad y el estado del puesto.';
            }
            if (!$c['responsibilities'] && !$c['requirements']) {
                $err['requirements'] = 'Para publicar agrega al menos una responsabilidad o un requisito.';
            }
            if ($c['valid_through'] !== null && $c['valid_through'] <= self::today()) {
                $err['valid_through'] = 'Para publicar, la fecha de vencimiento debe ser futura (después de hoy).';
            }
        }
        return ['clean' => $c, 'errors' => $err];
    }

    private static function row(array $c, ?int $userId, bool $new): array
    {
        $loc = trim($c['city'] . ($c['city'] !== '' && $c['region'] !== '' ? ', ' : '') . $c['region']);
        $row = [
            'title' => $c['title'], 'slug' => $c['slug'], 'posted_on' => $c['posted_on'], 'valid_through' => $c['valid_through'], 'employment_type' => $c['employment_type'],
            'location' => $loc !== '' ? $loc : null, 'city' => $c['city'] !== '' ? $c['city'] : null, 'region' => $c['region'] !== '' ? $c['region'] : null, 'postal' => $c['postal'] !== '' ? $c['postal'] : null,
            'summary' => $c['summary'] !== '' ? $c['summary'] : null, 'equipment' => $c['equipment'] !== '' ? $c['equipment'] : null,
            'responsibilities' => $c['responsibilities'] ?: null, 'requirements' => $c['requirements'] ?: null, 'benefits' => $c['benefits'] ?: null,
            'pay_min' => $c['pay']['min'] ?? null, 'pay_max' => $c['pay']['max'] ?? null, 'pay_unit' => $c['pay']['unit'] ?? null, 'pay_currency' => $c['pay'] !== null ? 'USD' : null,
            'updated_at' => Clock::now(),
        ];
        if ($new) {
            $row += ['status' => 'draft', 'is_active' => 0, 'created_by' => $userId, 'created_at' => Clock::now()];
        }
        return $row;
    }

    /** Alta como borrador (no toca el sitio). @return array{ok:bool,msg:string,id?:int,errors?:array} */
    public static function create(array $in, int $userId): array
    {
        $v = self::validate($in);
        if ($v['errors']) {
            return ['ok' => false, 'msg' => 'Revisa los campos marcados.', 'errors' => $v['errors']];
        }
        if (count(self::all()) >= 200) {
            return ['ok' => false, 'msg' => 'Hay demasiadas vacantes guardadas. Borra las cerradas que no necesites.'];
        }
        if (Db::value('SELECT COUNT(*) FROM job_postings WHERE slug = ?', [$v['clean']['slug']]) > 0) {
            return ['ok' => false, 'msg' => 'Revisa los campos marcados.', 'errors' => ['slug' => 'Ya existe una vacante con esa dirección (slug). Cambia el slug.']];
        }
        $id = Db::insert('job_postings', self::row($v['clean'], $userId, true));
        ActivityLog::record('job_create', $userId, 'job', $id, ['slug' => $v['clean']['slug'], 'title' => $v['clean']['title']]);
        return ['ok' => true, 'msg' => 'Vacante guardada como borrador. Publícala cuando esté lista.', 'id' => $id];
    }

    /** Guarda cambios. Si la vacante esta publicada o en pausa, revalida como para publicar y actualiza el sitio. */
    public static function save(int $id, array $in, int $userId): array
    {
        $j = self::find($id);
        if ($j === null) {
            return ['ok' => false, 'msg' => 'La vacante no existe.'];
        }
        if ($j['status'] === 'closed') {
            return ['ok' => false, 'msg' => 'Una vacante cerrada no se edita. Reábrela primero.'];
        }
        $live = $j['status'] === 'active';
        $in['slug'] = $live || $j['status'] === 'paused' ? $j['slug'] : ($in['slug'] ?? $j['slug']);   // la URL publicada no cambia
        $v = self::validate($in, $live);
        if ($v['errors']) {
            return ['ok' => false, 'msg' => 'Revisa los campos marcados.', 'errors' => $v['errors']];
        }
        if ((int) Db::value('SELECT COUNT(*) FROM job_postings WHERE slug = ? AND id <> ?', [$v['clean']['slug'], $id]) > 0) {
            return ['ok' => false, 'msg' => 'Revisa los campos marcados.', 'errors' => ['slug' => 'Ya existe una vacante con esa dirección (slug). Cambia el slug.']];
        }
        try {
            return Db::transaction(static function () use ($id, $v, $userId, $j): array {
                Db::update('job_postings', $id, self::row($v['clean'], $userId, false));
                $pub = in_array($j['status'], ['active', 'paused'], true) ? JobsPublisher::publish() : null;
                ActivityLog::record('job_update', $userId, 'job', $id, ['slug' => $v['clean']['slug'], 'status' => $j['status']]);
                return ['ok' => true, 'msg' => $pub !== null ? 'Cambios guardados y enviados al sitio. La página puede tardar hasta 5 minutos en verse (caché).' : 'Cambios guardados.', 'id' => $id];
            });
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    /** publish | pause | close | reopen | delete. @return array{ok:bool,msg:string,errors?:array} */
    public static function setStatus(int $id, string $op, int $userId): array
    {
        $j = self::find($id);
        if ($j === null) {
            return ['ok' => false, 'msg' => 'La vacante no existe.'];
        }
        $st = (string) $j['status'];
        $to = match ($op) {
            'publish' => in_array($st, ['draft', 'paused'], true) ? 'active' : null,
            'pause'   => $st === 'active' ? 'paused' : null,
            'close'   => $st !== 'closed' ? 'closed' : null,
            'reopen'  => $st === 'closed' ? 'draft' : null,
            default   => null,
        };
        if ($op === 'delete') {
            if ($st !== 'draft' || $j['published_at'] !== null) {
                return ['ok' => false, 'msg' => 'Solo se borra un borrador que nunca se publicó. Cierra las demás.'];
            }
            Db::run('DELETE FROM job_postings WHERE id = ?', [$id]);
            ActivityLog::record('job_delete', $userId, 'job', $id, ['slug' => $j['slug']]);
            return ['ok' => true, 'msg' => 'Borrador borrado.'];
        }
        if ($to === null) {
            return ['ok' => false, 'msg' => 'Esa acción no aplica al estado actual («' . (self::STATUSES[$st] ?? $st) . '»).'];
        }
        if ($to === 'active') {
            $v = self::validate(self::toForm($j), true);
            if ($v['errors']) {
                return ['ok' => false, 'msg' => 'Para publicar falta corregir: ' . implode(' ', array_values($v['errors'])), 'errors' => $v['errors']];
            }
            if (count(array_filter(self::all(), static fn($x) => $x['status'] === 'active' && $x['id'] !== $j['id'])) + 1 > self::MAX_JOBS) {
                return ['ok' => false, 'msg' => 'No puede haber más de ' . self::MAX_JOBS . ' vacantes publicadas.'];
            }
        }
        try {
            return Db::transaction(static function () use ($id, $to, $op, $userId, $j): array {
                $u = ['status' => $to, 'is_active' => $to === 'active' ? 1 : 0, 'updated_at' => Clock::now()];
                if ($to === 'active' && $j['published_at'] === null) {
                    $u['published_at'] = Clock::now();
                }
                Db::update('job_postings', $id, $u);
                JobsPublisher::publish();
                ActivityLog::record('job_' . $op, $userId, 'job', $id, ['slug' => $j['slug'], 'from' => $j['status'], 'to' => $to]);
                $msg = ['publish' => 'Vacante publicada: sale en /careers/ y en el sitemap. Por la caché del sitio puede tardar hasta 5 minutos en verse.',
                    'pause' => 'Vacante en pausa: la página deja de existir (404) y sale del sitemap. Puede tardar hasta 5 minutos por la caché.',
                    'close' => 'Vacante cerrada: la página deja de existir (404) y sale del sitemap.', 'reopen' => 'Vacante reabierta como borrador. Revísala y vuelve a publicarla.'][$op];
                return ['ok' => true, 'msg' => $msg];
            });
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    /** Datos de una vacante en el formato del formulario (para revalidar al publicar). */
    public static function toForm(array $j): array
    {
        return ['title' => $j['title'], 'slug' => $j['slug'], 'posted_on' => $j['posted_on'], 'valid_through' => $j['valid_through'], 'employment_type' => $j['employment_type'], 'city' => (string) $j['city'], 'region' => (string) $j['region'],
            'postal' => (string) $j['postal'], 'summary' => (string) $j['summary'], 'equipment' => (string) $j['equipment'], 'responsibilities' => $j['responsibilities'], 'requirements' => $j['requirements'], 'benefits' => $j['benefits'],
            'pay_on' => $j['pay_unit'] !== null ? '1' : '', 'pay_min' => $j['pay_min'] !== null ? (string) $j['pay_min'] : '', 'pay_max' => $j['pay_max'] !== null ? (string) $j['pay_max'] : '', 'pay_unit' => (string) $j['pay_unit']];
    }
}
