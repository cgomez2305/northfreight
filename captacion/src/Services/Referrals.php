<?php
/**
 * Referidos (doc §4, §9.7, A12). Un CODIGO (referral_codes) pertenece a un referidor (puede ser un lead firmado); un REFERIDO (referrals) es un lead que llego con ese codigo:
 *   estado:  referred (llego con el codigo) -> qualified (el lead avanzo) -> signed (firmo)
 *   bono:    none -> pending (firmo: «pendiente de aprobar», avisa a Carlos) -> approved -> paid     (o cancelled)
 * El monto del bono es PROVISIONAL hasta que NorthStar lo confirme (ajuste referrals.bonus_confirmed); sin monto no se aprueba. El enlace compartible es
 * SITE_URL/careers/?ref=CODIGO (el sitio guarda ?ref= en localStorage 60 dias y lo manda en el formulario). Un codigo inexistente se ignora sin error.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Models\Lead;

final class Referrals
{
    public const STATUSES = ['referred' => 'Referido', 'qualified' => 'Calificado', 'signed' => 'Firmado'];
    public const BONUS = ['none' => 'Sin bono aún', 'pending' => 'Pendiente de aprobar', 'approved' => 'Aprobado', 'paid' => 'Pagado', 'cancelled' => 'Cancelado'];
    private const QUALIFYING = ['qualified', 'prequalified', 'call_booked', 'presented'];
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'msg' => $msg];
    }

    private static function ok(string $msg, array $extra = []): array
    {
        return ['ok' => true, 'msg' => $msg] + $extra;
    }

    public static function siteUrl(): string
    {
        return rtrim(Env::get('SITE_URL', 'https://northstarfreightsolutions.us') ?? 'https://northstarfreightsolutions.us', '/');
    }

    public static function link(string $code): string
    {
        return self::siteUrl() . '/careers/?ref=' . rawurlencode($code);
    }

    public static function bonusConfirmed(): bool
    {
        return Settings::get('referrals.bonus_confirmed', false) === true;
    }

    public static function setBonusConfirmed(bool $yes, int $userId): void
    {
        $before = self::bonusConfirmed();
        Settings::set('referrals.bonus_confirmed', $yes);
        ActivityLog::settingsChange($userId, 'referrals', ['bonus_confirmed' => $before], ['bonus_confirmed' => $yes]);
    }

    /** Codigo valido: 3 a 40 caracteres A-Z, 0-9, guion y guion bajo (siempre en mayusculas). null si no lo es. */
    public static function normalize(mixed $raw): ?string
    {
        $c = strtoupper(trim(is_string($raw) ? $raw : ''));
        return preg_match('/^[A-Z0-9_-]{3,40}$/', $c) === 1 ? $c : null;
    }

    public static function generate(string $ownerName): string
    {
        $ascii = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $ownerName);
        $first = strtoupper(substr((string) preg_replace('/[^A-Za-z]/', '', (string) strtok(trim($ascii), ' ')), 0, 6));
        $prefix = strlen($first) >= 2 ? $first : 'REF';
        for ($i = 0; $i < 30; $i++) {
            $s = '';
            for ($j = 0; $j < 4; $j++) {
                $s .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $code = $prefix . '-' . $s;
            if (self::byCode($code) === null) {
                return $code;
            }
        }
        throw new \RuntimeException('No se pudo generar un codigo unico.');
    }

    public static function byCode(string $code): ?array
    {
        return Db::one('SELECT * FROM referral_codes WHERE code = ?', [$code]);
    }

    /** Codigo ACTIVO para el texto que llego del sitio (se normaliza). */
    public static function find(mixed $raw): ?array
    {
        $c = self::normalize($raw);
        if ($c === null) {
            return null;
        }
        $r = self::byCode($c);
        return $r !== null && (int) $r['active'] === 1 ? $r : null;
    }

    private static function amount(mixed $v): array
    {
        if ($v === null || $v === '') {
            return [true, null];
        }
        $s = str_replace(',', '.', trim((string) $v));
        if (preg_match('/^\d{1,6}(\.\d{1,2})?$/', $s) !== 1) {
            return [false, null];
        }
        return [true, (float) $s];
    }

    /** Alta de un codigo. @return array{ok:bool,msg:string,id?:int,code?:string} */
    public static function createCode(mixed $ownerName, mixed $leadId, mixed $code, mixed $amount, mixed $note, int $userId): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', mb_scrub(is_string($ownerName) ? $ownerName : '')));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            return self::fail('Escribe el nombre del referidor (2 a 120 caracteres).');
        }
        $lead = null;
        if ($leadId !== null && trim((string) $leadId) !== '') {
            $lead = ctype_digit(ltrim((string) $leadId, '#')) ? Lead::find((int) ltrim((string) $leadId, '#')) : null;
            if ($lead === null) {
                return self::fail('El lead asociado no existe (escribe su número, sin #).');
            }
        }
        [$okAmt, $amt] = self::amount($amount);
        if (!$okAmt) {
            return self::fail('El monto del bono debe ser un número (por ejemplo 250 o 250.50).');
        }
        $c = null;
        if (is_string($code) && trim($code) !== '') {
            $c = self::normalize($code);
            if ($c === null) {
                return self::fail('El código admite letras, números, guion y guion bajo (3 a 40 caracteres).');
            }
            if (self::byCode($c) !== null) {
                return self::fail('Ese código ya existe. Elige otro.');
            }
        }
        $c ??= self::generate($name);
        $note = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', mb_scrub(is_string($note) ? $note : ''))), 0, 255);
        try {
            $id = Db::insert('referral_codes', ['owner_name' => $name, 'owner_lead_id' => $lead !== null ? (int) $lead['id'] : null, 'code' => $c, 'bonus_amount' => $amt, 'active' => 1,
                'note' => $note !== '' ? $note : null, 'created_by' => $userId, 'created_at' => Clock::now()]);
        } catch (\PDOException $e) {
            return self::fail('Ese código ya existe. Elige otro.');
        }
        ActivityLog::record('referral_code_create', $userId, 'referral_code', $id, ['code' => $c, 'lead_id' => $lead['id'] ?? null, 'bonus_amount' => $amt]);
        return self::ok('Código creado: ' . $c, ['id' => $id, 'code' => $c]);
    }

    public static function setActive(int $codeId, bool $active, int $userId): array
    {
        $r = Db::one('SELECT id, code FROM referral_codes WHERE id = ?', [$codeId]);
        if ($r === null) {
            return self::fail('El código no existe.');
        }
        Db::update('referral_codes', $codeId, ['active' => $active ? 1 : 0]);
        ActivityLog::record($active ? 'referral_code_enable' : 'referral_code_disable', $userId, 'referral_code', $codeId, ['code' => $r['code']]);
        return self::ok($active ? 'Código activado.' : 'Código desactivado: los nuevos leads con él se ignoran.');
    }

    public static function updateAmount(int $codeId, mixed $amount, int $userId): array
    {
        $r = Db::one('SELECT id, code, bonus_amount FROM referral_codes WHERE id = ?', [$codeId]);
        if ($r === null) {
            return self::fail('El código no existe.');
        }
        [$ok, $amt] = self::amount($amount);
        if (!$ok) {
            return self::fail('El monto del bono debe ser un número (por ejemplo 250 o 250.50).');
        }
        Db::update('referral_codes', $codeId, ['bonus_amount' => $amt]);
        ActivityLog::record('referral_code_amount', $userId, 'referral_code', $codeId, ['code' => $r['code'], 'from' => $r['bonus_amount'], 'to' => $amt]);
        return self::ok('Monto del bono actualizado.');
    }

    /** Codigo del lead (para A12): lo crea si no tiene. Devuelve el enlace compartible. */
    public static function linkForLead(array $lead): string
    {
        $id = (int) ($lead['id'] ?? 0);
        if ($id <= 0) {
            return self::link('EJEMPLO');
        }
        $row = Db::one('SELECT code FROM referral_codes WHERE owner_lead_id = ? AND active = 1 ORDER BY id LIMIT 1', [$id]);
        if ($row === null) {
            $name = Fmt::name($lead);
            $r = self::createCode($name !== '' ? $name : 'Referidor', $id, null, null, 'Creado al enviar A12', 0);
            return $r['ok'] ? self::link((string) $r['code']) : self::siteUrl() . '/careers/';
        }
        return self::link((string) $row['code']);
    }

    // --- Captura (motor) ----------------------------------------------------------------------------------------------

    /** Enlaza un lead nuevo (o que acaba de completar su codigo) con el referidor. Idempotente (un lead, un referido). @return ?array fila del codigo */
    public static function attach(int $leadId, mixed $rawCode): ?array
    {
        $code = self::find($rawCode);
        if ($code === null || ((int) ($code['owner_lead_id'] ?? 0)) === $leadId) {
            return null;
        }
        $st = Db::run('INSERT IGNORE INTO referrals (referral_code_id, lead_id, status, bonus_status, created_at) VALUES (?, ?, ?, ?, ?)', [(int) $code['id'], $leadId, 'referred', 'none', Clock::now()]);
        if ($st->rowCount() === 1) {
            LeadIngest::event($leadId, 'referral_linked', ['code' => $code['code'], 'by' => $code['owner_name']]);
        }
        return $code;
    }

    /** El lead avanzo de etapa: «Referido» pasa a «Calificado». */
    public static function onStage(int $leadId, string $stage): void
    {
        if (!in_array($stage, self::QUALIFYING, true)) {
            return;
        }
        Db::run("UPDATE referrals SET status = 'qualified', qualified_at = ? WHERE lead_id = ? AND status = 'referred'", [Clock::now(), $leadId]);
    }

    /** El lead firmo: el referido pasa a «Firmado», el bono a «pendiente de aprobar» y se avisa a Carlos. Idempotente. */
    public static function onSigned(array $lead, ?int $userId): void
    {
        $leadId = (int) $lead['id'];
        $ref = Db::one('SELECT r.*, c.code, c.owner_name, c.owner_lead_id, c.bonus_amount AS code_amount FROM referrals r JOIN referral_codes c ON c.id = r.referral_code_id WHERE r.lead_id = ? FOR UPDATE', [$leadId]);
        if ($ref === null || $ref['status'] === 'signed') {
            return;
        }
        $now = Clock::now();
        Db::update('referrals', (int) $ref['id'], ['status' => 'signed', 'signed_at' => $now, 'qualified_at' => $ref['qualified_at'] ?? $now,
            'bonus_status' => $ref['bonus_status'] === 'none' ? 'pending' : $ref['bonus_status'], 'bonus_amount' => $ref['bonus_amount'] ?? $ref['code_amount']]);
        LeadIngest::event($leadId, 'referral_signed', ['code' => $ref['code'], 'by' => $ref['owner_name']]);
        if ($ref['owner_lead_id'] !== null) {
            LeadIngest::event((int) $ref['owner_lead_id'], 'referral_signed', ['code' => $ref['code'], 'referral_id' => (int) $ref['id']]);
        }
        ActivityLog::record('referral_signed', $userId, 'referral', (int) $ref['id'], ['code' => $ref['code'], 'lead_id' => $leadId]);
        if (Telegram::configured()) {
            Queue::push('referral_notify', ['referral_id' => (int) $ref['id']], $now, 'referral_notify:' . (int) $ref['id']);
        }
    }

    /** Job referral_notify: aviso a Carlos de que hay un bono por aprobar. */
    public static function notify(array $payload): void
    {
        $r = Db::one('SELECT r.id, r.lead_id, r.bonus_amount, c.code, c.owner_name FROM referrals r JOIN referral_codes c ON c.id = r.referral_code_id WHERE r.id = ?', [(int) ($payload['referral_id'] ?? 0)]);
        if ($r === null) {
            throw new PermanentJobFailure('El referido ya no existe.');
        }
        $lead = Lead::find((int) $r['lead_id']);
        $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $amt = $r['bonus_amount'] !== null ? '$' . number_format((float) $r['bonus_amount'], 2) . (self::bonusConfirmed() ? '' : ' (provisional)') : 'monto sin definir';
        Telegram::send("<b>Referido FIRMADO: bono por aprobar</b>\nCódigo " . $e($r['code']) . ' de ' . $e($r['owner_name']) . "\nFirmó: " . $e($lead !== null ? Fmt::name($lead) : 'lead #' . $r['lead_id']) . "\nBono: " . $e($amt) . "\nPanel: " . $e(Url::base() . '/referidos'));
    }

    // --- Bonos ---------------------------------------------------------------------------------------------------------

    public static function pendingApproval(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM referrals WHERE bonus_status = 'pending'");
    }

    /**
     * Cambia el bono de un referido. approve (pending -> approved, exige monto > 0), pay (approved -> paid), cancel (pending|approved -> cancelled), reopen (cancelled -> pending).
     */
    public static function bonusAction(int $referralId, string $action, mixed $amount, mixed $note, int $userId): array
    {
        return Db::transaction(static function () use ($referralId, $action, $amount, $note, $userId): array {
            $r = Db::one('SELECT r.*, c.code FROM referrals r JOIN referral_codes c ON c.id = r.referral_code_id WHERE r.id = ? FOR UPDATE', [$referralId]);
            if ($r === null) {
                return self::fail('El referido no existe.');
            }
            $now = Clock::now();
            $note = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', mb_scrub(is_string($note) ? $note : ''))), 0, 255);
            $u = [];
            switch ($action) {
                case 'approve':
                    if ($r['bonus_status'] !== 'pending') {
                        return self::fail('Solo se aprueba un bono «pendiente de aprobar».');
                    }
                    [$ok, $amt] = self::amount($amount === null || $amount === '' ? $r['bonus_amount'] : $amount);
                    if (!$ok || $amt === null || $amt <= 0) {
                        return self::fail('Escribe el monto del bono (mayor que 0) para aprobarlo.');
                    }
                    $u = ['bonus_status' => 'approved', 'bonus_amount' => $amt, 'bonus_approved_at' => $now];
                    break;
                case 'pay':
                    if ($r['bonus_status'] !== 'approved') {
                        return self::fail('Solo se marca como pagado un bono aprobado.');
                    }
                    $u = ['bonus_status' => 'paid', 'bonus_paid_at' => $now];
                    break;
                case 'cancel':
                    if (!in_array($r['bonus_status'], ['pending', 'approved'], true)) {
                        return self::fail('Solo se cancela un bono pendiente o aprobado.');
                    }
                    $u = ['bonus_status' => 'cancelled'];
                    break;
                case 'reopen':
                    if ($r['bonus_status'] !== 'cancelled' || $r['status'] !== 'signed') {
                        return self::fail('Solo se reabre un bono cancelado de un referido firmado.');
                    }
                    $u = ['bonus_status' => 'pending', 'bonus_approved_at' => null];
                    break;
                default:
                    return self::fail('Acción no válida.');
            }
            if ($note !== '') {
                $u['bonus_note'] = $note;
            }
            Db::update('referrals', $referralId, $u);
            ActivityLog::record('referral_bonus_' . $action, $userId, 'referral', $referralId, ['code' => $r['code'], 'from' => $r['bonus_status'], 'to' => $u['bonus_status'], 'amount' => $u['bonus_amount'] ?? $r['bonus_amount']]);
            return self::ok(['approve' => 'Bono aprobado.', 'pay' => 'Bono marcado como pagado.', 'cancel' => 'Bono cancelado.', 'reopen' => 'Bono reabierto: vuelve a «pendiente de aprobar».'][$action]);
        });
    }

    // --- Lectura ---------------------------------------------------------------------------------------------------------

    /** @return array{codes:list<array>,referrals:list<array>,totals:array} */
    public static function overview(string $filter = ''): array
    {
        $codes = Db::all("SELECT c.*, (SELECT COUNT(*) FROM referrals r WHERE r.referral_code_id = c.id) AS n_ref, (SELECT COUNT(*) FROM referrals r WHERE r.referral_code_id = c.id AND r.status = 'signed') AS n_signed,
            l.first_name AS owner_first, l.last_name AS owner_last FROM referral_codes c LEFT JOIN leads l ON l.id = c.owner_lead_id ORDER BY c.active DESC, c.id DESC LIMIT 300");
        $w = '';
        $p = [];
        if (isset(self::BONUS[$filter]) && $filter !== 'none') {
            $w = 'WHERE r.bonus_status = ?';
            $p[] = $filter;
        } elseif (isset(self::STATUSES[$filter])) {
            $w = 'WHERE r.status = ?';
            $p[] = $filter;
        }
        $refs = Db::all("SELECT r.*, c.code, c.owner_name, l.first_name, l.last_name, l.stage, l.state AS lead_state, l.profile FROM referrals r JOIN referral_codes c ON c.id = r.referral_code_id
            JOIN leads l ON l.id = r.lead_id $w ORDER BY (r.bonus_status = 'pending') DESC, r.id DESC LIMIT 300", $p);
        $t = Db::one("SELECT COUNT(*) AS n, SUM(status = 'signed') AS signed, SUM(bonus_status = 'pending') AS pending, SUM(bonus_status = 'approved') AS approved, SUM(bonus_status = 'paid') AS paid FROM referrals") ?? [];
        return ['codes' => $codes, 'referrals' => $refs, 'totals' => ['n' => (int) ($t['n'] ?? 0), 'signed' => (int) ($t['signed'] ?? 0), 'pending' => (int) ($t['pending'] ?? 0), 'approved' => (int) ($t['approved'] ?? 0), 'paid' => (int) ($t['paid'] ?? 0)]];
    }
}
