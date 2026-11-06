<?php
/**
 * Consentimientos (TCPA) y revocacion (A15: el lead responde STOP).
 *
 *  - record(): guarda el texto EXACTO aceptado, canal, IP, user agent y fecha (una fila por canal cubierto por el texto: sms, call, email).
 *  - revoke(): marca revoked_at en los consentimientos vigentes de un lead (por id) o de todos los leads con ese telefono, para un canal o para 'all'; anade el evento
 *    'consent_revoked' y la bandera 'stop' (que bloquea SMS y WhatsApp). El webhook de SMS que la llame es de Q4; la logica ya esta lista.
 *  - isGranted(): ultimo consentimiento del lead en ese canal vigente (no revocado) y sin bandera 'stop'.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class Consent
{
    /** Canales que cubre el texto CONSENT_TEXT_V1 del sitio: llamada, SMS y correo. WhatsApp NO esta cubierto por ese texto. */
    public const FORM_CHANNELS = ['call', 'sms', 'email'];

    public static function record(int $leadId, array $consent, array $channels = self::FORM_CHANNELS): int
    {
        if (empty($consent['given'])) {
            return 0;
        }
        $at = isset($consent['at']) && is_string($consent['at']) && Clock::parse($consent['at']) !== null ? Clock::at((int) Clock::parse($consent['at'])) : Clock::now();
        $n = 0;
        foreach ($channels as $ch) {
            Db::insert('consents', [
                'lead_id'         => $leadId,
                'channel'         => $ch,
                'consent_text'    => (string) ($consent['text'] ?? ''),
                'consent_version' => isset($consent['version']) ? substr((string) $consent['version'], 0, 20) : null,
                'ip'              => isset($consent['ip']) && $consent['ip'] !== null ? substr((string) $consent['ip'], 0, 45) : null,
                'user_agent'      => isset($consent['user_agent']) && $consent['user_agent'] !== null ? substr((string) $consent['user_agent'], 0, 255) : null,
                'granted_at'      => $at,
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * @param int|string $leadOrPhone id de lead (int) o telefono en cualquier formato (string)
     * @param string     $channel     'sms' | 'call' | 'email' | 'whatsapp' | 'all'
     * @return int consentimientos revocados
     */
    public static function revoke(int|string $leadOrPhone, string $channel = 'all', string $via = 'stop'): int
    {
        $leadIds = [];
        if (is_int($leadOrPhone)) {
            $leadIds = [$leadOrPhone];
        } else {
            $p = Phone::normalize($leadOrPhone);
            if ($p['ok']) {
                foreach (Db::all('SELECT id FROM leads WHERE phone_e164 = ? AND deleted_at IS NULL', [$p['e164']]) as $r) {
                    $leadIds[] = (int) $r['id'];
                }
            }
        }
        $channel = strtolower($channel);
        $total = 0;
        foreach ($leadIds as $id) {
            Db::transaction(static function () use ($id, $channel, $via, &$total): void {
                $sql = 'UPDATE consents SET revoked_at = ?, revoked_via = ? WHERE lead_id = ? AND revoked_at IS NULL' . ($channel === 'all' ? '' : ' AND channel = ?');
                $params = [Clock::now(), substr($via, 0, 30), $id];
                if ($channel !== 'all') {
                    $params[] = $channel;
                }
                $n = Db::run($sql, $params)->rowCount();
                $total += $n;
                $row = Db::one('SELECT flags FROM leads WHERE id = ? FOR UPDATE', [$id]);
                if ($row === null) {
                    return;
                }
                $flags = Flags::add(Db::decode($row['flags']), $channel === 'all' ? 'stop' : 'stop_' . $channel);
                Db::update('leads', $id, ['flags' => $flags]);
                Db::insert('lead_events', ['lead_id' => $id, 'type' => 'consent_revoked', 'payload' => ['channel' => $channel, 'via' => $via, 'revoked' => $n], 'created_at' => Clock::now()]);
            });
        }
        return $total;
    }

    /**
     * STOP por SMS (A15): revoca varios canales a la vez (una sola transaccion y UN evento por lead). Hoy: sms, whatsapp y whatsapp_inbound; el correo y la llamada manual no se tocan
     * (el correo tiene su propia baja de un clic). Cada canal deja su bandera stop_<canal>, que Consent::isGranted() respeta aunque llegue un consentimiento nuevo.
     * @param list<string> $channels
     * @return array{leads:int,revoked:int} leads afectados y consentimientos revocados
     */
    public static function revokeChannels(int|string $leadOrPhone, array $channels, string $via = 'stop'): array
    {
        $leadIds = [];
        if (is_int($leadOrPhone)) {
            $leadIds = [$leadOrPhone];
        } else {
            $p = Phone::normalize($leadOrPhone);
            if ($p['ok']) {
                foreach (Db::all('SELECT id FROM leads WHERE phone_e164 = ? AND deleted_at IS NULL', [$p['e164']]) as $r) {
                    $leadIds[] = (int) $r['id'];
                }
            }
        }
        $channels = array_values(array_unique(array_map('strtolower', $channels)));
        $total = 0;
        foreach ($leadIds as $id) {
            Db::transaction(static function () use ($id, $channels, $via, &$total): void {
                $row = Db::one('SELECT flags FROM leads WHERE id = ? FOR UPDATE', [$id]);
                if ($row === null) {
                    return;
                }
                $n = 0;
                foreach ($channels as $ch) {
                    $n += Db::run('UPDATE consents SET revoked_at = ?, revoked_via = ? WHERE lead_id = ? AND channel = ? AND revoked_at IS NULL', [Clock::now(), substr($via, 0, 30), $id, $ch])->rowCount();
                }
                $total += $n;
                Db::update('leads', $id, ['flags' => Flags::add(Db::decode($row['flags']), ...array_map(static fn($c) => 'stop_' . $c, $channels))]);
                Db::insert('lead_events', ['lead_id' => $id, 'type' => 'consent_revoked', 'payload' => ['channel' => implode('+', $channels), 'via' => $via, 'revoked' => $n], 'created_at' => Clock::now()]);
            });
        }
        return ['leads' => count($leadIds), 'revoked' => $total];
    }

    /**
     * START/YES/UNSTOP por SMS: restablece SOLO el SMS, y solo si ese lead ya habia dado consentimiento de SMS antes. Deja un consentimiento nuevo (auditoria) y quita stop_sms.
     * No toca WhatsApp ni la bandera general «stop» (un STOP total puesto a mano sigue mandando).
     * @return int leads restablecidos
     */
    public static function restoreSms(string $phone, string $text = 'START'): int
    {
        $p = Phone::normalize($phone);
        if (!$p['ok']) {
            return 0;
        }
        $n = 0;
        foreach (Db::all('SELECT id FROM leads WHERE phone_e164 = ? AND deleted_at IS NULL', [$p['e164']]) as $r) {
            $id = (int) $r['id'];
            $had = (int) Db::value("SELECT COUNT(*) FROM consents WHERE lead_id = ? AND channel = 'sms'", [$id]) > 0;
            if (!$had) {
                continue;
            }
            Db::transaction(static function () use ($id, $text, &$n): void {
                $row = Db::one('SELECT flags FROM leads WHERE id = ? FOR UPDATE', [$id]);
                Db::insert('consents', ['lead_id' => $id, 'channel' => 'sms', 'consent_text' => 'El lead respondio ' . mb_substr($text, 0, 20) . ' por SMS para volver a recibir mensajes (solo SMS).', 'consent_version' => 'sms-start-1', 'granted_at' => Clock::now()]);
                Db::update('leads', $id, ['flags' => Flags::remove(Db::decode($row['flags'] ?? null), 'stop_sms')]);
                Db::insert('lead_events', ['lead_id' => $id, 'type' => 'consent_restored', 'payload' => ['channel' => 'sms', 'via' => 'start'], 'created_at' => Clock::now()]);
                $n++;
            });
        }
        return $n;
    }

    public static function isGranted(int $leadId, string $channel): bool
    {
        $row = Db::one('SELECT flags FROM leads WHERE id = ?', [$leadId]);
        if ($row === null) {
            return false;
        }
        $flags = Db::decode($row['flags']);
        if (in_array('stop', $flags, true) || in_array('stop_' . $channel, $flags, true)) {
            return false;
        }
        $c = Db::one('SELECT revoked_at FROM consents WHERE lead_id = ? AND channel = ? ORDER BY granted_at DESC, id DESC LIMIT 1', [$leadId, $channel]);
        return $c !== null && $c['revoked_at'] === null;
    }
}
