<?php
/**
 * Respuestas de prospectos a las campañas (fase AT): lee el buzón del remitente (contact@) por IMAP en SOLO LECTURA (EXAMINE y BODY.PEEK de unas pocas cabeceras:
 * nunca marca nada como leído, no mueve ni borra) y, si el remitente de un correo coincide con un destinatario de campaña al que ya se le escribió:
 *  - marca la respuesta en el destinatario (replied_at) y en el prospecto (email_replied_at), que entra en la «Lista de hoy» hasta que se le llame;
 *  - deja de enviarle el segundo correo y lo que tuviera en cola en otras campañas;
 *  - lo anota en el registro de actividad y en la campana.
 * Ignora respuestas automáticas (fuera de la oficina, rebotes). Se enciende en Prospectos > Correos en lote > Ajustes de envío (apagado por defecto).
 * Credenciales: IMAP_HOST/IMAP_PORT/IMAP_USER/IMAP_PASS/IMAP_SECURE en .env o mail.env; sin ellas usa las del SMTP (mismo buzón; en Hostinger imap.hostinger.com:993).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;

final class ProspectReplies
{
    public const EVERY = 600;          // cada 10 minutos mientras esté encendido
    public const FIRST_DAYS = 14;      // la primera lectura mira las dos últimas semanas
    public const MAX_PER_SCAN = 200;
    private const STATE = 'prospect_mail.inbox';

    /** @return array{host:string,port:int,user:string,pass:string,secure:string} */
    public static function config(): array
    {
        $smtpHost = (string) Env::get('SMTP_HOST', '');
        $host = (string) Env::get('IMAP_HOST', '');
        if ($host === '' && $smtpHost !== '') {
            $host = str_contains($smtpHost, 'hostinger') ? 'imap.hostinger.com' : (string) preg_replace('/^smtp\./', 'imap.', $smtpHost);
        }
        $secure = strtolower((string) Env::get('IMAP_SECURE', 'ssl'));
        return ['host' => $host, 'port' => Env::int('IMAP_PORT', 993), 'user' => (string) Env::get('IMAP_USER', (string) Env::get('SMTP_USER', '')),
            'pass' => (string) Env::get('IMAP_PASS', (string) Env::get('SMTP_PASS', '')), 'secure' => in_array($secure, ['ssl', 'none'], true) ? $secure : 'ssl'];
    }

    public static function configured(): bool
    {
        $c = self::config();
        return $c['host'] !== '' && $c['user'] !== '' && $c['pass'] !== '';
    }

    /** @return array{at:?string,error:?string,last_uid:int,validity:int,found:int} */
    public static function state(): array
    {
        $s = Settings::get(self::STATE, []);
        $s = is_array($s) ? $s : [];
        return ['at' => $s['at'] ?? null, 'error' => $s['error'] ?? null, 'last_uid' => (int) ($s['last_uid'] ?? 0), 'validity' => (int) ($s['validity'] ?? 0), 'found' => (int) ($s['found'] ?? 0)];
    }

    /** Deja programada la próxima lectura (una por franja de 10 minutos; la clave evita duplicados). */
    public static function ensureScheduled(?int $at = null): void
    {
        $at ??= Clock::time();
        Queue::push('prospect_reply_scan', [], Clock::at($at), 'preply:' . intdiv($at, self::EVERY));
    }

    /** Job: lee y vuelve a programarse mientras el ajuste siga encendido. */
    public static function job(array $payload = [], array $job = []): void
    {
        if (!ProspectMail::settings()['read_replies']) {
            return;
        }
        try {
            self::scan();
        } finally {
            self::ensureScheduled(Clock::time() + self::EVERY);
        }
    }

    /** @return array{ok:bool,found:int,read:int,error:?string} */
    public static function scan(): array
    {
        $st = self::state();
        $save = static function (array $over) use ($st): void {
            Settings::set(self::STATE, array_merge(['at' => $st['at'], 'error' => $st['error'], 'last_uid' => $st['last_uid'], 'validity' => $st['validity'], 'found' => $st['found']], $over));
        };
        if (!self::configured()) {
            $save(['at' => Clock::now(), 'error' => 'Faltan las credenciales del buzón (IMAP o SMTP).']);
            return ['ok' => false, 'found' => 0, 'read' => 0, 'error' => 'sin credenciales'];
        }
        if ((int) Db::value("SELECT COUNT(*) FROM prospect_campaign_recipients WHERE status = 'sent'") === 0) {   // aún no se ha escrito a nadie: no hace falta abrir el buzón
            $save(['at' => Clock::now(), 'error' => null]);
            return ['ok' => true, 'found' => 0, 'read' => 0, 'error' => null];
        }
        $cfg = self::config();
        $imap = new Imap();
        try {
            $imap->connect($cfg['host'], $cfg['port'], $cfg['secure']);
            $imap->login($cfg['user'], $cfg['pass']);
            $validity = $imap->examine('INBOX');
            $last = $validity === $st['validity'] ? $st['last_uid'] : 0;
            $uids = $last > 0 ? $imap->uidSearch('UID ' . ($last + 1) . ':*') : $imap->uidSearch('SINCE ' . gmdate('j-M-Y', Clock::time() - self::FIRST_DAYS * 86400));
            $uids = array_values(array_filter($uids, static fn(int $u) => $u > $last));
            sort($uids);
            $uids = array_slice($uids, 0, self::MAX_PER_SCAN);
            $found = 0;
            $max = $last;
            if ($uids) {
                foreach ($imap->fetchHeaders($uids, ['FROM', 'DATE', 'SUBJECT', 'AUTO-SUBMITTED', 'X-AUTOREPLY', 'X-AUTORESPOND', 'PRECEDENCE']) as $uid => $raw) {
                    $max = max($max, $uid);
                    if (self::handle(self::parseHeaders($raw))) {
                        $found++;
                    }
                }
                $max = max($max, (int) end($uids));
            }
            $imap->logout();
            $save(['at' => Clock::now(), 'error' => null, 'last_uid' => $max, 'validity' => $validity, 'found' => $st['found'] + $found]);
            return ['ok' => true, 'found' => $found, 'read' => count($uids), 'error' => null];
        } catch (\Throwable $t) {
            $imap->close();
            $msg = mb_substr(Storage::redact($t->getMessage()), 0, 160);
            $save(['at' => Clock::now(), 'error' => $msg]);
            Storage::error('prospect replies', $msg);
            return ['ok' => false, 'found' => 0, 'read' => 0, 'error' => $msg];
        }
    }

    /** @return array<string,string> cabeceras en minúsculas (desplegadas) */
    public static function parseHeaders(string $raw): array
    {
        $raw = (string) preg_replace('/\r?\n[ \t]+/', ' ', $raw);
        $h = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m) === 1) {
                $h[strtolower($m[1])] = trim($m[2]);
            }
        }
        return $h;
    }

    public static function isAutomatic(array $h): bool
    {
        $auto = strtolower($h['auto-submitted'] ?? 'no');
        $prec = strtolower($h['precedence'] ?? '');
        $from = strtolower($h['from'] ?? '');
        return ($auto !== '' && $auto !== 'no') || isset($h['x-autoreply']) || isset($h['x-autorespond']) || in_array($prec, ['bulk', 'junk', 'list', 'auto_reply'], true)
            || preg_match('/mailer-daemon|postmaster|no-?reply/', $from) === 1;
    }

    /** Aplica una respuesta si el remitente es un destinatario de campaña. @return bool si se registró una respuesta nueva */
    public static function handle(array $h): bool
    {
        if (self::isAutomatic($h)) {
            return false;
        }
        $from = $h['from'] ?? '';
        $email = preg_match('/<([^>\s]+@[^>\s]+)>/', $from, $m) === 1 ? $m[1] : (preg_match('/([^\s<>"]+@[^\s<>"]+)/', $from, $m2) === 1 ? $m2[1] : '');
        $email = strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $date = isset($h['date']) && ($ts = strtotime($h['date'])) !== false ? $ts : Clock::time();
        $at = Clock::at(min($date, Clock::time()));
        $r = Db::one("SELECT id, prospect_id, replied_at FROM prospect_campaign_recipients WHERE email = ? AND status = 'sent' AND sent_at <= ? ORDER BY sent_at DESC LIMIT 1", [$email, $at]);
        if ($r === null || $r['replied_at'] !== null) {
            return false;
        }
        $pid = (int) $r['prospect_id'];
        Db::run('UPDATE prospect_campaign_recipients SET replied_at = ?, opened_at = COALESCE(opened_at, ?) WHERE id = ?', [$at, $at, (int) $r['id']]);
        Db::run('UPDATE prospects_fmcsa SET email_replied_at = ? WHERE id = ?', [$at, $pid]);
        Db::run("UPDATE prospect_campaign_recipients SET fu_status = 'skipped', fu_skip_reason = 'respondió' WHERE prospect_id = ? AND status = 'sent' AND (fu_status IS NULL OR fu_status = 'queued')", [$pid]);
        Db::run("UPDATE prospect_campaign_recipients SET status = 'skipped', skip_reason = 'respondió' WHERE prospect_id = ? AND status = 'queued'", [$pid]);
        ActivityLog::record('prospect_reply', null, 'prospect', $pid, ['subject' => mb_substr((string) ($h['subject'] ?? ''), 0, 120)]);
        return true;
    }

    /** Prospectos que respondieron y aún no se han llamado después (campana y «Lista de hoy»). @return array{n:int,max_id:int} */
    public static function pending(): array
    {
        try {
            $r = Db::one("SELECT COUNT(*) n, COALESCE(MAX(id), 0) m FROM prospects_fmcsa WHERE deleted_at IS NULL AND email_replied_at IS NOT NULL AND (last_call_at IS NULL OR last_call_at < email_replied_at)
                AND status NOT IN ('dnc', 'not_interested') AND converted_lead_id IS NULL");
        } catch (\Throwable) {
            return ['n' => 0, 'max_id' => 0];
        }
        return ['n' => (int) ($r['n'] ?? 0), 'max_id' => (int) ($r['m'] ?? 0)];
    }
}
