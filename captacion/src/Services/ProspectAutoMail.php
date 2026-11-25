<?php
/**
 * Correos automáticos a prospectos FMCSA: una cadencia corta de llamada + correo, la práctica habitual de la prospección B2B (quien no contesta un número
 * desconocido sí lee un correo). Tres correos, cada uno APAGADO por defecto y con versión en inglés y en español (Configuración > Prospectos):
 *   intro    fase AW: presentación al entrar en la lista (búsqueda diaria de FMCSA, «Añadir por USDOT» o CSV), si tiene correo. Sale en horario laboral de Nueva York.
 *   missed   fase AU: tras una llamada sin respuesta («Sin respuesta» o «Dejé mensaje»), 10 minutos después (da tiempo a corregir un resultado mal marcado).
 *   breakup  fase AW: último correo cuando se agotan los intentos sin respuesta, 2 días después, en horario laboral.
 * Uno de cada tipo por prospecto, como mucho. El idioma lo decide el prospecto (columna lang, anotada en la llamada; sin dato = inglés).
 *
 * Mismas reglas que los correos en lote (ProspectMail, CAN-SPAM): dirección física y SMTP obligatorios, pie con motivo y baja de un clic (/u/{token} +
 * List-Unsubscribe), nunca a «No llamar más», «No interesado», convertidos, bajas o lista de supresión, el mismo tope por hora (cuenta todos los tipos) y el periodo
 * de espera de las CAMPAÑAS (no se escribe a quien recibió un correo en lote hace menos de N días). Entre dos correos automáticos de la cadencia pasan al menos
 * MIN_GAP (si toca antes, se aplaza).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class ProspectAutoMail
{
    public const DELAY = 600;
    /** Fase AW: separación mínima entre dos correos automáticos al mismo prospecto. */
    public const MIN_GAP = 2 * 86400;
    public const BREAKUP_DELAY = 2 * 86400;
    public const DEFAULT_SUBJECT = 'Sorry I missed you, {empresa}';
    public const DEFAULT_BODY = "Hi,\n\nI just tried to call {empresa} from NorthStar Freight Solutions. We dispatch Amazon Relay and dry van loads for owner-operators and small fleets, and we work with new MC numbers: we book the loads, follow every trip and keep the paperwork organized.\n\nIf you want to talk, reply to this email or message us on WhatsApp at {whatsapp}. You can see how we work here:\n{link_web}\n\nNorthStar Freight Solutions";

    /** tipo => [título, ajuste, cuándo sale]. El orden es el de la cadencia. */
    public const KINDS = [
        'intro' => ['Correo de presentación', 'prospects.automail_intro', 'Al entrar un prospecto nuevo en la lista (búsqueda diaria, USDOT o CSV), si tiene correo. Sale en horario laboral de Nueva York, antes de la primera llamada o el mismo día.'],
        'missed' => ['Correo tras no contestar', 'prospects.automail', 'Al registrar «Sin respuesta» o «Dejé mensaje», 10 minutos después.'],
        'breakup' => ['Último correo al agotar los intentos', 'prospects.automail_breakup', 'Cuando el prospecto pasa a «Sin respuesta (cerrada)», 2 días después y en horario laboral. Deja la puerta abierta sin insistir.'],
    ];

    /** Textos de fábrica. Solo afirman lo que NorthStar hace hoy (CLAUDE.md §8.2): Amazon Relay y dry van, MC nuevos, inglés y español. */
    public const DEFAULTS = [
        'intro' => [
            'en' => ['Dispatch for {empresa}: Amazon Relay and dry van', "Hi,\n\nI'm reaching out from NorthStar Freight Solutions, a remote dispatch desk for owner-operators and small fleets. We work with new MC numbers.\n\nWe book Amazon Relay and dry van loads, follow every trip and keep the paperwork organized (rate confirmations, BOLs and PODs), so you can focus on driving. One of our dispatchers may give you a call in the next few days.\n\nIf you want to talk sooner, reply to this email or message us on WhatsApp at {whatsapp}.\n\nNorthStar Freight Solutions"],
            'es' => ['Dispatch para {empresa}: Amazon Relay y dry van', "Hola:\n\nTe escribo de NorthStar Freight Solutions, un equipo de dispatch remoto para owner-operators y flotas pequeñas. Trabajamos con MC nuevos y atendemos en inglés y en español.\n\nReservamos cargas de Amazon Relay y dry van, seguimos cada viaje y llevamos el papeleo en orden (rate confirmations, BOL y POD), para que tú te concentres en manejar. Es posible que uno de nuestros dispatchers te llame en los próximos días.\n\nSi prefieres hablar antes, responde este correo o escríbenos por WhatsApp al {whatsapp}.\n\nNorthStar Freight Solutions"],
        ],
        'missed' => [
            'en' => [self::DEFAULT_SUBJECT, self::DEFAULT_BODY],
            'es' => ['Intenté llamarte, {empresa}', "Hola:\n\nAcabo de intentar llamar a {empresa} de parte de NorthStar Freight Solutions. Hacemos dispatch de cargas de Amazon Relay y dry van para owner-operators y flotas pequeñas, y trabajamos con MC nuevos: reservamos las cargas, seguimos cada viaje y llevamos el papeleo en orden.\n\nSi quieres hablar, responde este correo o escríbenos por WhatsApp al {whatsapp}. Puedes ver cómo trabajamos aquí:\n{link_web}\n\nNorthStar Freight Solutions"],
        ],
        'breakup' => [
            'en' => ['Should I close your file, {empresa}?', "Hi,\n\nWe tried to reach {empresa} a few times about dispatch support and didn't want to keep calling.\n\nIf the timing isn't right, no problem. If you ever want help booking Amazon Relay or dry van loads, or with the paperwork, just reply to this email or message us on WhatsApp at {whatsapp}.\n\nGood luck on the road,\nNorthStar Freight Solutions"],
            'es' => ['¿Cierro tu expediente, {empresa}?', "Hola:\n\nIntentamos comunicarnos con {empresa} varias veces para hablar de dispatch y no queremos seguir llamando.\n\nSi no es buen momento, no hay problema. Si algún día quieres ayuda para reservar cargas de Amazon Relay o dry van, o con el papeleo, responde este correo o escríbenos por WhatsApp al {whatsapp}.\n\nBuen camino,\nNorthStar Freight Solutions"],
        ],
    ];

    private static function kind(string $kind): string
    {
        return isset(self::KINDS[$kind]) ? $kind : 'missed';
    }

    /** @return array{enabled:bool,subject:string,body:string,subject_es:string,body_es:string} */
    public static function settings(string $kind = 'missed'): array
    {
        $kind = self::kind($kind);
        $s = Settings::get(self::KINDS[$kind][1], []);
        $s = is_array($s) ? $s : [];
        $d = self::DEFAULTS[$kind];
        $pick = static fn(string $k, string $def): string => trim((string) ($s[$k] ?? '')) !== '' ? (string) $s[$k] : $def;
        return ['enabled' => (bool) ($s['enabled'] ?? false), 'subject' => $pick('subject', $d['en'][0]), 'body' => $pick('body', $d['en'][1]),
            'subject_es' => $pick('subject_es', $d['es'][0]), 'body_es' => $pick('body_es', $d['es'][1])];
    }

    /** Guarda un tipo. Sin 'subject_es'/'body_es' en $in se conserva la versión en español guardada. @return array{ok:bool,msg:string} */
    public static function save(array $in, int $userId, string $kind = 'missed'): array
    {
        $kind = self::kind($kind);
        $clean = static fn(mixed $v, int $max): string => mb_substr(trim(str_replace("\r\n", "\n", (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', mb_scrub(is_string($v) ? $v : '')))), 0, $max + 1);
        $cur = self::settings($kind);
        $t = ['subject' => $clean($in['subject'] ?? '', 190), 'body' => $clean($in['body'] ?? '', 8000),
            'subject_es' => array_key_exists('subject_es', $in) ? $clean($in['subject_es'], 190) : $cur['subject_es'], 'body_es' => array_key_exists('body_es', $in) ? $clean($in['body_es'], 8000) : $cur['body_es']];
        $on = ($in['enabled'] ?? '') === '1';
        if (in_array('', $t, true)) {
            return ['ok' => false, 'msg' => 'Escribe el asunto y el texto del correo en los dos idiomas.'];
        }
        if (mb_strlen($t['subject']) > 190 || mb_strlen($t['subject_es']) > 190 || mb_strlen($t['body']) > 8000 || mb_strlen($t['body_es']) > 8000) {
            return ['ok' => false, 'msg' => 'El asunto o el texto son demasiado largos.'];
        }
        if (preg_match('/\[\[.*?\]\]/u', implode("\n", $t)) === 1) {
            return ['ok' => false, 'msg' => 'Quita los marcadores [[…]] antes de guardar.'];
        }
        $bad = array_diff(self::varsIn(implode("\n", $t)), array_keys(ProspectMail::VARS));
        if ($bad) {
            return ['ok' => false, 'msg' => 'Variable desconocida: ' . implode(', ', $bad) . '.'];
        }
        if ($on && ($b = ProspectMail::blockers())) {
            return ['ok' => false, 'msg' => 'No se puede encender todavía: ' . $b[0]];
        }
        $d = self::DEFAULTS[$kind];
        Settings::set(self::KINDS[$kind][1], ['enabled' => $on, 'subject' => $t['subject'] === $d['en'][0] ? '' : $t['subject'], 'body' => $t['body'] === $d['en'][1] ? '' : $t['body'],
            'subject_es' => $t['subject_es'] === $d['es'][0] ? '' : $t['subject_es'], 'body_es' => $t['body_es'] === $d['es'][1] ? '' : $t['body_es']]);
        ActivityLog::record('prospect_automail_save', $userId, 'settings', null, ['kind' => $kind, 'enabled' => $on]);
        $label = self::KINDS[$kind][0];
        if (!$on) {
            return ['ok' => true, 'msg' => $label . ' guardado (apagado).'];
        }
        return ['ok' => true, 'msg' => match ($kind) {
            'intro' => 'Correo de presentación ENCENDIDO: sale a cada prospecto nuevo con correo, en horario laboral, uno por prospecto.',
            'breakup' => 'Último correo ENCENDIDO: sale 2 días después de agotar los intentos sin respuesta, uno por prospecto.',
            default => 'Correo tras no contestar ENCENDIDO: sale 10 minutos después de registrar «Sin respuesta» o «Dejé mensaje», uno por prospecto.',
        }];
    }

    /** @return list<string> */
    private static function varsIn(string $s): array
    {
        preg_match_all('/\{[a-z_]+\}/', $s, $m);
        return array_values(array_unique($m[0]));
    }

    /** Fase AW: primer momento de horario laboral de Nueva York (lunes a viernes, 9:00 a 17:00) a partir de $ts. */
    public static function sendWindow(int $ts): int
    {
        $tz = new \DateTimeZone('America/New_York');
        $d = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        for ($i = 0; $i < 8; $i++) {
            $h = (int) $d->format('G');
            $weekday = (int) $d->format('N') <= 5;
            if ($weekday && $h >= 9 && $h < 17) {
                return $d->getTimestamp();
            }
            $d = $weekday && $h < 9 ? $d->setTime(9, 0) : $d->modify('+1 day')->setTime(9, 0);
        }
        return $ts;
    }

    /** Encola un correo de la cadencia (si ese tipo está encendido y tiene sentido). Nunca lanza. */
    public static function queue(int $prospectId, int $userId, string $kind = 'missed'): void
    {
        try {
            $kind = self::kind($kind);
            if (!self::settings($kind)['enabled']) {
                return;
            }
            $p = Db::one('SELECT id, email, do_not_call, status, converted_lead_id, email_opt_out_at FROM prospects_fmcsa WHERE id = ? AND deleted_at IS NULL', [$prospectId]);
            if ($p === null || $p['email'] === null || $p['email'] === '' || (int) $p['do_not_call'] === 1 || $p['converted_lead_id'] !== null || $p['email_opt_out_at'] !== null) {
                return;
            }
            $st = Db::run('INSERT IGNORE INTO prospect_auto_mails (prospect_id, kind, email, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$prospectId, $kind, $p['email'], 'queued', $userId ?: null, Clock::now()]);
            if ($st->rowCount() === 1) {
                $id = (int) Db::pdo()->lastInsertId();
                $now = Clock::time();
                $at = match ($kind) {
                    'intro' => self::sendWindow($now + self::DELAY),
                    'breakup' => self::sendWindow($now + self::BREAKUP_DELAY),
                    default => $now + self::DELAY,
                };
                Queue::push('prospect_auto_mail', ['id' => $id], Clock::at($at), 'pam:' . $id);
            }
        } catch (\Throwable $t) {
            Storage::error('prospect automail queue failed', get_class($t) . ': ' . $t->getMessage());
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
        $kind = self::kind((string) ($m['kind'] ?? 'missed'));
        $cfg = self::settings($kind);
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
        $lastAuto = $p === null ? null : Db::value("SELECT MAX(sent_at) FROM prospect_auto_mails WHERE prospect_id = ? AND status = 'sent' AND id <> ?", [(int) $p['id'], $id]);
        $lastAutoTs = $lastAuto !== null ? (int) Clock::parse((string) $lastAuto) : 0;
        $lastMailTs = $p !== null && $p['last_emailed_at'] !== null ? (int) Clock::parse((string) $p['last_emailed_at']) : 0;
        $why = match (true) {
            $p === null || $p['deleted_at'] !== null => 'prospecto eliminado',
            (int) $p['do_not_call'] === 1 || in_array($p['status'], ['dnc', 'not_interested'], true) => 'no llamar más o no interesado',
            $p['converted_lead_id'] !== null => 'ya es lead',
            $kind === 'missed' && !in_array((string) $p['last_result'], Prospects::MISSED, true) => 'la última llamada ya no es «sin respuesta»',
            $kind === 'intro' && !in_array((string) $p['status'], ['to_call', 'no_answer'], true) => 'ya se habló con el prospecto',
            $kind === 'breakup' && ((string) $p['status'] !== 'no_answer_closed' || !in_array((string) $p['last_result'], Prospects::MISSED, true)) => 'el prospecto ya no está cerrado sin respuesta',
            $p['email_opt_out_at'] !== null || Suppression::isListed(null, (string) $m['email']) => 'se dio de baja',
            // periodo de espera de las campañas: el último correo no fue de esta cadencia y es reciente
            $lastMailTs > $lastAutoTs + 1 && $lastMailTs > $now - ProspectMail::settings()['cooldown_days'] * 86400 => 'periodo de espera',
            default => null,
        };
        if ($why !== null) {
            self::skip($id, $why);
            return;
        }
        if ($lastAutoTs > $now - self::MIN_GAP) {   // dos correos de la cadencia demasiado juntos: se aplaza
            Queue::push('prospect_auto_mail', ['id' => $id], Clock::at(self::sendWindow($lastAutoTs + self::MIN_GAP)), 'pam:' . $id . ':gap');
            return;
        }
        if (ProspectMail::sentLastHour($now) >= ProspectMail::settings()['cap_hour']) {
            Queue::push('prospect_auto_mail', ['id' => $id], Clock::at($now + 300), 'pam:' . $id . ':' . $now);   // tope de la hora: vuelve a mirar en 5 min
            return;
        }
        $unsub = Unsub::prospectUrl((int) $p['id']);
        $r = self::render($cfg, $p, $unsub, ($p['lang'] ?? '') === 'es' ? 'es' : 'en');
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
        ActivityLog::record('prospect_automail_sent', null, 'prospect', (int) $p['id'], ['kind' => $kind]);
    }

    /** @return array{subject:string,text:string,html:string,unresolved:list<string>} */
    public static function render(array $cfg, array $p, string $unsub, string $lang = 'en'): array
    {
        $lang = $lang === 'es' ? 'es' : 'en';
        $links = ['{link_calculadora}' => Templates::SITE . ProspectMail::CLICK_TARGETS[1], '{link_web}' => Templates::SITE . ProspectMail::CLICK_TARGETS[2]];
        $tpl = $lang === 'es' ? ['subject' => $cfg['subject_es'] ?? $cfg['subject'], 'body' => $cfg['body_es'] ?? $cfg['body']] : ['subject' => $cfg['subject'], 'body' => $cfg['body']];
        return Templates::render($tpl, ['first_name' => '', 'state' => $p['state'] ?? ''], $lang, $unsub, $links + ProspectMail::vars($p, $lang), ['why' => ProspectMail::why($lang)]);
    }

    /** Prueba a la dirección del administrador: llegan las dos versiones (inglés y español) de lo guardado. */
    public static function sendTest(string $to, int $userId, string $kind = 'missed'): array
    {
        $kind = self::kind($kind);
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'msg' => 'Tu usuario no tiene un correo válido para recibir la prueba.'];
        }
        $p = ['id' => 0, 'legal_name' => 'SAMPLE TRUCKING LLC', 'dba' => null, 'state' => 'TX', 'city' => 'DALLAS', 'fleet_size' => 1, 'usdot' => '0000000'];
        foreach (['en', 'es'] as $lang) {
            $r = self::render(self::settings($kind), $p, Url::base() . '/u/PRUEBA', $lang);
            try {
                $status = Mailer::deliver(['to' => $to, 'subject' => '[PRUEBA] ' . $r['subject'], 'text' => $r['text'], 'html' => $r['html'], 'from_name' => Sequences::mailSettings()['from_name']]);
            } catch (\Throwable $t) {
                return ['ok' => false, 'msg' => 'El servidor de correo rechazó la prueba: ' . mb_substr(Storage::redact($t->getMessage()), 0, 160)];
            }
            if ($status !== 'sent') {
                return ['ok' => false, 'msg' => 'Sin SMTP la prueba solo quedó registrada en el servidor: no llegó a tu correo.'];
            }
        }
        ActivityLog::record('prospect_automail_test', $userId, 'settings', null, ['kind' => $kind]);
        return ['ok' => true, 'msg' => 'Pruebas enviadas a ' . $to . ' (inglés y español).'];
    }

    /** Correos automáticos de cada prospecto (para la ficha): [prospect_id => [kind => fila]]. @param list<int> $ids @return array<int,array<string,array>> */
    public static function lastFor(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        try {
            $out = [];
            foreach (Db::all('SELECT prospect_id, kind, status, skip_reason, sent_at FROM prospect_auto_mails WHERE prospect_id IN (' . implode(',', $ids) . ') ORDER BY id') as $r) {
                $out[(int) $r['prospect_id']][(string) $r['kind']] = $r;
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{queued:int,sent:int,skipped:int,failed:int,sent7:int} por tipo, o de todos con $kind = null */
    public static function stats(int $now, ?string $kind = null): array
    {
        $out = ['queued' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'sent7' => 0];
        try {
            $w = $kind !== null ? ' WHERE kind = ' . Db::pdo()->quote(self::kind($kind)) : '';
            foreach (Db::all('SELECT status, COUNT(*) n FROM prospect_auto_mails' . $w . ' GROUP BY status') as $r) {
                $out[(string) $r['status']] = (int) $r['n'];
            }
            $out['sent7'] = (int) Db::value("SELECT COUNT(*) FROM prospect_auto_mails WHERE status = 'sent' AND sent_at > ?" . ($kind !== null ? ' AND kind = ' . Db::pdo()->quote(self::kind($kind)) : ''), [Clock::at($now - 7 * 86400)]);
        } catch (\Throwable) {
        }
        return $out;
    }
}
