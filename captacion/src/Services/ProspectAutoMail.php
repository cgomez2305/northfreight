<?php
/**
 * Fase AU: correo automático tras una llamada sin respuesta («Sin respuesta» o «Dejé mensaje»). Uno por prospecto, como mucho, y APAGADO por defecto
 * (Configuración > Prospectos). Es la práctica habitual de «llamada + correo» de la prospección B2B: quien no contesta un número desconocido sí lee un correo.
 *
 * Mismas reglas que los correos en lote (ProspectMail, CAN-SPAM): dirección física y SMTP obligatorios, pie con motivo y baja de un clic (/u/{token} +
 * List-Unsubscribe), nunca a «No llamar más», «No interesado», convertidos, bajas o lista de supresión, periodo de espera de los correos en lote (no se escribe a quien
 * recibió un correo de NorthStar hace menos de N días) y el mismo tope por hora (cuenta los dos tipos). Se envía 10 minutos después de registrar la llamada
 * (da tiempo a corregir un resultado mal marcado: si ya no aplica, se omite).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class ProspectAutoMail
{
    public const DELAY = 600;
    public const DEFAULT_SUBJECT = 'Sorry I missed you, {empresa}';
    public const DEFAULT_BODY = "Hi,\n\nI just tried to call {empresa} from NorthStar Freight Solutions. We dispatch Amazon Relay and dry van loads for owner-operators and small fleets, and we work with new MC numbers: we book the loads, follow every trip and keep the paperwork organized.\n\nIf you want to talk, reply to this email or message us on WhatsApp at {whatsapp}. You can see how we work here:\n{link_web}\n\nNorthStar Freight Solutions";

    /** @return array{enabled:bool,subject:string,body:string} */
    public static function settings(): array
    {
        $s = Settings::get('prospects.automail', []);
        $s = is_array($s) ? $s : [];
        return ['enabled' => (bool) ($s['enabled'] ?? false), 'subject' => trim((string) ($s['subject'] ?? '')) !== '' ? (string) $s['subject'] : self::DEFAULT_SUBJECT,
            'body' => trim((string) ($s['body'] ?? '')) !== '' ? (string) $s['body'] : self::DEFAULT_BODY];
    }

    /** @return array{ok:bool,msg:string} */
    public static function save(array $in, int $userId): array
    {
        $clean = static fn(mixed $v, int $max): string => mb_substr(trim(str_replace("\r\n", "\n", (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', mb_scrub(is_string($v) ? $v : '')))), 0, $max + 1);
        $subject = $clean($in['subject'] ?? '', 190);
        $body = $clean($in['body'] ?? '', 8000);
        $on = ($in['enabled'] ?? '') === '1';
        if ($subject === '' || $body === '') {
            return ['ok' => false, 'msg' => 'Escribe el asunto y el texto del correo.'];
        }
        if (mb_strlen($subject) > 190 || mb_strlen($body) > 8000) {
            return ['ok' => false, 'msg' => 'El asunto o el texto son demasiado largos.'];
        }
        if (preg_match('/\[\[.*?\]\]/u', $subject . $body) === 1) {
            return ['ok' => false, 'msg' => 'Quita los marcadores [[…]] antes de guardar.'];
        }
        $bad = array_diff(self::varsIn($subject . "\n" . $body), array_merge(array_keys(ProspectMail::VARS)));
        if ($bad) {
            return ['ok' => false, 'msg' => 'Variable desconocida: ' . implode(', ', $bad) . '.'];
        }
        if ($on && ($b = ProspectMail::blockers())) {
            return ['ok' => false, 'msg' => 'No se puede encender todavía: ' . $b[0]];
        }
        Settings::set('prospects.automail', ['enabled' => $on, 'subject' => $subject === self::DEFAULT_SUBJECT ? '' : $subject, 'body' => $body === self::DEFAULT_BODY ? '' : $body]);
        ActivityLog::record('prospect_automail_save', $userId, 'settings', null, ['enabled' => $on]);
        return ['ok' => true, 'msg' => $on ? 'Correo tras no contestar ENCENDIDO: sale 10 minutos después de registrar «Sin respuesta» o «Dejé mensaje», uno por prospecto.' : 'Correo tras no contestar guardado (apagado).'];
    }

    /** @return list<string> */
    private static function varsIn(string $s): array
    {
        preg_match_all('/\{[a-z_]+\}/', $s, $m);
        return array_values(array_unique($m[0]));
    }

    /** Encola el correo de un prospecto tras una llamada perdida (si está encendido y tiene sentido). Nunca lanza. */
    public static function queue(int $prospectId, int $userId): void
    {
        if (!self::settings()['enabled']) {
            return;
        }
        $p = Db::one('SELECT id, email, do_not_call, status, converted_lead_id, email_opt_out_at FROM prospects_fmcsa WHERE id = ? AND deleted_at IS NULL', [$prospectId]);
        if ($p === null || $p['email'] === null || (int) $p['do_not_call'] === 1 || $p['converted_lead_id'] !== null || $p['email_opt_out_at'] !== null) {
            return;
        }
        $st = Db::run('INSERT IGNORE INTO prospect_auto_mails (prospect_id, email, status, created_by, created_at) VALUES (?, ?, ?, ?, ?)', [$prospectId, $p['email'], 'queued', $userId ?: null, Clock::now()]);
        if ($st->rowCount() === 1) {
            $id = (int) Db::pdo()->lastInsertId();
            Queue::push('prospect_auto_mail', ['id' => $id], Clock::at(Clock::time() + self::DELAY), 'pam:' . $id);
        }
    }

    private static function skip(int $id, string $why): void
    {
        Db::run("UPDATE prospect_auto_mails SET status = 'skipped', skip_reason = ? WHERE id = ?", [mb_substr($why, 0, 60), $id]);
    }

    /** Job prospect_auto_mail. */
    public static function job(array $payload, array $job = []): void
    {
        $id = (int) ($payload['id'] ?? 0);
        $m = Db::one('SELECT * FROM prospect_auto_mails WHERE id = ?', [$id]);
        if ($m === null || $m['status'] !== 'queued') {
            return;
        }
        $cfg = self::settings();
        if (!$cfg['enabled']) {
            self::skip($id, 'el correo automático se apagó');
            return;
        }
        if (ProspectMail::blockers()) {
            self::skip($id, 'sin SMTP o sin dirección física');
            return;
        }
        $p = Db::one('SELECT * FROM prospects_fmcsa WHERE id = ?', [(int) $m['prospect_id']]);
        $now = Clock::time();
        $why = match (true) {
            $p === null || $p['deleted_at'] !== null => 'prospecto eliminado',
            (int) $p['do_not_call'] === 1 || in_array($p['status'], ['dnc', 'not_interested'], true) => 'no llamar más o no interesado',
            $p['converted_lead_id'] !== null => 'ya es lead',
            !in_array((string) $p['last_result'], Prospects::MISSED, true) => 'la última llamada ya no es «sin respuesta»',
            $p['email_opt_out_at'] !== null || Suppression::isListed(null, (string) $m['email']) => 'se dio de baja',
            $p['last_emailed_at'] !== null && (int) Clock::parse((string) $p['last_emailed_at']) > $now - ProspectMail::settings()['cooldown_days'] * 86400 => 'periodo de espera',
            default => null,
        };
        if ($why !== null) {
            self::skip($id, $why);
            return;
        }
        if (ProspectMail::sentLastHour($now) >= ProspectMail::settings()['cap_hour']) {
            Queue::push('prospect_auto_mail', ['id' => $id], Clock::at($now + 300), 'pam:' . $id . ':' . $now);   // tope de la hora: vuelve a mirar en 5 min
            return;
        }
        $unsub = Unsub::prospectUrl((int) $p['id']);
        $r = self::render($cfg, $p, $unsub);
        $from = Sequences::mailSettings();
        try {
            $status = Mailer::deliver(['to' => (string) $m['email'], 'subject' => $r['subject'], 'text' => $r['text'], 'html' => $r['html'], 'from_name' => $from['from_name'],
                'reply_to' => $from['reply_to'] !== '' ? $from['reply_to'] : Mailer::to(), 'headers' => ['List-Unsubscribe' => '<' . $unsub . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']]);
        } catch (\Throwable $t) {
            Db::run("UPDATE prospect_auto_mails SET status = 'failed', error = ? WHERE id = ?", [mb_substr(Storage::redact($t->getMessage()), 0, 250), $id]);
            return;
        }
        if ($status !== 'sent') {
            self::skip($id, 'sin SMTP');
            return;
        }
        $at = Clock::now();
        Db::run("UPDATE prospect_auto_mails SET status = 'sent', sent_at = ?, error = NULL WHERE id = ?", [$at, $id]);
        Db::run('UPDATE prospects_fmcsa SET last_emailed_at = ? WHERE id = ?', [$at, (int) $p['id']]);
        ActivityLog::record('prospect_automail_sent', null, 'prospect', (int) $p['id'], []);
    }

    /** @return array{subject:string,text:string,html:string,unresolved:list<string>} */
    public static function render(array $cfg, array $p, string $unsub): array
    {
        $links = ['{link_calculadora}' => Templates::SITE . ProspectMail::CLICK_TARGETS[1], '{link_web}' => Templates::SITE . ProspectMail::CLICK_TARGETS[2]];
        return Templates::render(['subject' => $cfg['subject'], 'body' => $cfg['body']], ['first_name' => '', 'state' => $p['state'] ?? ''], 'en', $unsub, $links + ProspectMail::vars($p), ['why' => ProspectMail::WHY]);
    }

    /** Prueba a la dirección del administrador. */
    public static function sendTest(string $to, int $userId): array
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'msg' => 'Tu usuario no tiene un correo válido para recibir la prueba.'];
        }
        $p = ['id' => 0, 'legal_name' => 'SAMPLE TRUCKING LLC', 'dba' => null, 'state' => 'TX', 'city' => 'DALLAS', 'fleet_size' => 1, 'usdot' => '0000000'];
        $r = self::render(self::settings(), $p, Url::base() . '/u/PRUEBA');
        try {
            $status = Mailer::deliver(['to' => $to, 'subject' => '[PRUEBA] ' . $r['subject'], 'text' => $r['text'], 'html' => $r['html'], 'from_name' => Sequences::mailSettings()['from_name']]);
        } catch (\Throwable $t) {
            return ['ok' => false, 'msg' => 'El servidor de correo rechazó la prueba: ' . mb_substr(Storage::redact($t->getMessage()), 0, 160)];
        }
        if ($status !== 'sent') {
            return ['ok' => false, 'msg' => 'Sin SMTP la prueba solo quedó registrada en el servidor: no llegó a tu correo.'];
        }
        ActivityLog::record('prospect_automail_test', $userId, 'settings', null, []);
        return ['ok' => true, 'msg' => 'Prueba enviada a ' . $to . '.'];
    }

    /** Correo automático de cada prospecto (para la lista). @param list<int> $ids @return array<int,array> */
    public static function lastFor(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        try {
            $out = [];
            foreach (Db::all('SELECT prospect_id, status, skip_reason, sent_at FROM prospect_auto_mails WHERE prospect_id IN (' . implode(',', $ids) . ')') as $r) {
                $out[(int) $r['prospect_id']] = $r;
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{queued:int,sent:int,skipped:int,failed:int,sent7:int} */
    public static function stats(int $now): array
    {
        $out = ['queued' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'sent7' => 0];
        try {
            foreach (Db::all('SELECT status, COUNT(*) n FROM prospect_auto_mails GROUP BY status') as $r) {
                $out[(string) $r['status']] = (int) $r['n'];
            }
            $out['sent7'] = (int) Db::value("SELECT COUNT(*) FROM prospect_auto_mails WHERE status = 'sent' AND sent_at > ?", [Clock::at($now - 7 * 86400)]);
        } catch (\Throwable) {
        }
        return $out;
    }
}
