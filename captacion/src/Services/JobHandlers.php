<?php
/**
 * Handlers de la cola (fase Q1): telegram_alert (A2), telegram_alert_followup (A3), fmcsa_enrich, email_notify; Q3: handoff_deliver, handoff_notify, sequence_step.
 * Cada handler recibe (array $payload, array $job) y lanza una excepcion para fallar (la cola reintenta a 1, 5 y 15 min).
 * sms_backup_alert NO tiene handler: se registra 'parked' (TODO Q4: SMS de respaldo a Carlos por Twilio, tras el registro A2P 10DLC).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;

final class JobHandlers
{
    /** Cliente FMCSA inyectable (pruebas). */
    public static ?Fmcsa $fmcsa = null;

    /** @return array<string,callable> */
    public static function all(): array
    {
        return [
            'telegram_alert'          => [self::class, 'telegramAlert'],
            'telegram_alert_followup' => [self::class, 'telegramFollowup'],
            'fmcsa_enrich'            => [self::class, 'fmcsaEnrich'],
            'email_notify'            => [self::class, 'emailNotify'],
            'rescore_batch'           => [ScoringConfig::class, 'runBatch'],
            'backup_db'               => [Backup::class, 'handler'],
            'handoff_deliver'         => [Handoff::class, 'deliver'],
            'handoff_notify'          => [Handoff::class, 'notify'],
            'referral_notify'         => [Referrals::class, 'notify'],
            'sequence_step'           => [Sequences::class, 'runStep'],
        ];
    }

    private static function lead(array $payload): array
    {
        $id = (int) ($payload['lead_id'] ?? 0);
        $lead = $id > 0 ? Lead::find($id) : null;
        if ($lead === null) {
            throw new PermanentJobFailure('El lead #' . $id . ' no existe o fue borrado.');
        }
        return $lead;
    }

    public static function telegramAlert(array $payload): void
    {
        $lead = self::lead($payload);
        $kind = in_array($payload['kind'] ?? '', ['hot', 'warm', 'signed_reentry'], true) ? (string) $payload['kind'] : 'hot';
        $cfg = Alerts::get();
        if ($kind === 'hot' && !$cfg['hot_telegram']) {
            return;   // apagado en Configuracion despues de encolarse
        }
        if ($kind === 'warm' && (!Alerts::warmTelegram($cfg) || $lead['temperature'] !== 'warm' || $lead['first_contact_at'] !== null || $lead['stage'] !== 'new')) {
            return;   // ya no aplica: se apago, subio a caliente o ya lo atendieron
        }
        Telegram::send(Telegram::leadText($lead, $kind));
        LeadIngest::event((int) $lead['id'], 'alert_sent', ['channel' => 'telegram', 'kind' => $kind]);
    }

    /** A3: 15 min despues, si nadie ha contactado al lead, segunda alerta + SMS de respaldo (registrado, no ejecutado). */
    public static function telegramFollowup(array $payload): void
    {
        $lead = self::lead($payload);
        if ($lead['first_contact_at'] !== null || $lead['temperature'] !== 'hot' || $lead['stage'] !== 'new') {
            return;   // ya lo atendieron (o dejo de ser caliente): nada que avisar
        }
        if (!Alerts::get()['followup_telegram']) {
            return;
        }
        Telegram::send(Telegram::leadText($lead, 'followup', Alerts::followupMinutes()));
        LeadIngest::event((int) $lead['id'], 'alert_sent', ['channel' => 'telegram', 'kind' => 'followup']);
        // TODO Q4: sms_backup_alert (SMS de respaldo a Carlos con Twilio, requiere A2P 10DLC). Hoy solo queda registrado como 'parked'.
        Queue::push('sms_backup_alert', ['lead_id' => (int) $lead['id']], Clock::now(), 'sms_backup:' . $lead['id'] . ':' . $lead['attempt'], 'parked', 'TODO Q4: SMS de respaldo a Carlos (no ejecutado en Q1)');
    }

    public static function fmcsaEnrich(array $payload): void
    {
        $lead = self::lead($payload);
        if ($lead['usdot'] === null) {
            throw new PermanentJobFailure('El lead no tiene USDOT.');
        }
        $fm = (self::$fmcsa ?? new Fmcsa())->lookup((string) $lead['usdot']);   // FmcsaUnavailable (reintentable) sube a la cola
        LeadIngest::applyFmcsa((int) $lead['id'], $fm);
    }

    public static function emailNotify(array $payload): void
    {
        $lead = self::lead($payload);
        if (!Alerts::get()['email']) {
            return;   // correo apagado en Configuracion
        }
        $kind = (string) ($payload['kind'] ?? 'new_lead');
        $temps = ['hot' => 'CALIENTE', 'warm' => 'tibio', 'cold' => 'frio', 'disqualified' => 'descartado'];
        $profiles = ['owner_operator' => 'Owner-operator', 'small_carrier' => 'Carrier pequeno', 'company_driver' => 'Company driver'];
        $name = trim($lead['first_name'] . ' ' . $lead['last_name']);
        $head = $kind === 'reentry' ? 'Un lead volvio a escribir' : 'Nuevo lead';
        $subject = '[Captacion] ' . $head . ': ' . $name . ' (' . ($temps[$lead['temperature']] ?? $lead['temperature']) . ', ' . $lead['score'] . '/100)';
        $body = $head . "\n\n"
            . 'Nombre: ' . $name . "\n"
            . 'Perfil: ' . ($profiles[$lead['profile']] ?? $lead['profile']) . "\n"
            . 'Telefono: ' . ($lead['phone_e164'] ?? '-') . "\n"
            . 'Correo: ' . ($lead['email'] ?? '-') . "\n"
            . 'Estado: ' . ($lead['state'] ?? '-') . "\n"
            . 'Equipo: ' . ($lead['equipment_types'] ? implode(', ', $lead['equipment_types']) : '-') . "\n"
            . 'Puntuacion: ' . $lead['score'] . '/100 (' . ($temps[$lead['temperature']] ?? $lead['temperature']) . ')' . "\n"
            . 'Fuente: ' . $lead['source'] . ' | Llego por: ' . ($lead['landing_page'] ?? '-') . "\n"
            . ($lead['flags'] ? 'Banderas: ' . implode(', ', $lead['flags']) . "\n" : '')
            . 'Panel: ' . Url::lead((int) $lead['id']) . "\n";
        Mailer::send($subject, $body);
    }
}
