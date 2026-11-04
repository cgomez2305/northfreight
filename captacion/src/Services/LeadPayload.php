<?php
/**
 * Paso 1 del motor (doc §5.1): validacion y normalizacion del lead entrante, venga de donde venga (sitio, API, importacion).
 *
 * Entrada (claves aceptadas; los alias del formulario del sitio funcionan igual):
 *   profile            owner_operator | small_carrier | company_driver   (alias: owner-operator, small-fleet, company-driver)
 *   name | first_name + last_name
 *   phone, email, state (codigo o nombre), city, language (en|es)
 *   cdl_type           standard | non_domiciled | unsure      (alias: cdl_standard = yes|no|unsure -> standard|non_domiciled|unsure)
 *   cdl_class (A|B|C), english_level (yes|basic|no)           (alias: english), years_experience (alias: years)
 *   equipment_types[]  (alias: equipment), truck_count (alias: trucks), usdot, authority_status (alias: authority: active|getting|none), endorsements[]
 *   contact_pref, best_time, job (vacante)
 *   utm_source/medium/campaign/term/content, first_seen, referrer, landing_page (alias: landing), referral_code | utm{} | attribution{}
 *   consent {given, text, version, at, ip, user_agent}
 *   site_lead_id (alias: id si empieza por ld_), created_at (solo importaciones)
 *
 * Errores que rechazan el lead: profile, name, contact (ni telefono ni correo), email (formato). Un telefono invalido NO pierde el lead si hay correo valido
 * (phone_e164 queda NULL y se marca la bandera phone_invalid). Todo lo demas invalido se ignora con bandera.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;

final class LeadPayload
{
    public const PROFILES = ['owner_operator', 'small_carrier', 'company_driver'];

    /** @return array{lead:array,errors:array<string,string>,flags:list<string>} */
    public static function normalize(array $in): array
    {
        $errors = [];
        $flags = [];
        $lead = [];

        // Perfil
        $p = strtolower(str_replace('-', '_', self::str($in['profile'] ?? '', 30)));
        $p = ['small_fleet' => 'small_carrier', 'carrier' => 'small_carrier', 'driver' => 'company_driver'][$p] ?? $p;
        if (!in_array($p, self::PROFILES, true)) {
            $errors['profile'] = 'invalid_profile';
        }
        $lead['profile'] = $p;

        // Nombre
        $first = self::str($in['first_name'] ?? '', 100);
        $last = self::str($in['last_name'] ?? '', 100);
        if ($first === '' && isset($in['name'])) {
            $parts = preg_split('/\s+/', self::str($in['name'], 200), 2) ?: [];
            $first = mb_substr($parts[0] ?? '', 0, 100);
            $last = $parts[1] ?? '';
        }
        if ($first === '') {
            $errors['name'] = 'required';
        }
        $lead['first_name'] = $first;
        $lead['last_name'] = mb_substr($last, 0, 100);

        // Correo
        $email = strtolower(self::str($in['email'] ?? '', 190));
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n,;<>\s]/', $email) === 1)) {
            $errors['email'] = 'invalid_email';
            $email = '';
        }
        $lead['email'] = $email !== '' ? $email : null;

        // Telefono
        $phoneRaw = self::str($in['phone'] ?? '', 40);
        $lead['phone_raw'] = $phoneRaw !== '' ? $phoneRaw : null;
        $lead['phone_e164'] = null;
        if ($phoneRaw !== '') {
            $ph = Phone::normalize($phoneRaw);
            if ($ph['ok']) {
                $lead['phone_e164'] = $ph['e164'];
                if ($ph['intl']) {
                    $flags[] = 'intl_phone';
                }
            } else {
                $flags[] = 'phone_invalid';
            }
        }
        if ($lead['phone_e164'] === null && $lead['email'] === null && !isset($errors['email'])) {
            $errors['contact'] = $phoneRaw !== '' ? 'invalid_phone' : 'missing_contact';
        }

        // Ubicacion e idioma
        $stateRaw = self::str($in['state'] ?? '', 40);
        $lead['state'] = UsStates::code($stateRaw);
        if ($stateRaw !== '' && $lead['state'] === null) {
            $flags[] = 'state_invalid';
        }
        $lead['city'] = ($c = self::str($in['city'] ?? '', 100)) !== '' ? $c : null;
        $lang = strtolower(substr(self::str($in['language'] ?? '', 5), 0, 2));
        $lead['language'] = $lang === 'es' ? 'es' : 'en';

        // Elegibilidad. cdl_standard del formulario del sitio: yes -> standard, no -> non_domiciled, unsure -> unsure.
        $cdl = strtolower(self::str($in['cdl_type'] ?? ($in['cdl_standard'] ?? ''), 20));
        $cdl = ['yes' => 'standard', 'no' => 'non_domiciled', 'not_sure' => 'unsure', 'not-sure' => 'unsure'][$cdl] ?? $cdl;
        $lead['cdl_type'] = in_array($cdl, ['standard', 'non_domiciled', 'unsure'], true) ? $cdl : null;
        $class = strtoupper(self::str($in['cdl_class'] ?? '', 3));
        $lead['cdl_class'] = in_array($class, ['A', 'B', 'C'], true) ? $class : null;
        $eng = strtolower(self::str($in['english_level'] ?? ($in['english'] ?? ''), 10));
        $lead['english_level'] = in_array($eng, ['yes', 'basic', 'no'], true) ? $eng : null;
        $lead['years_experience'] = self::int($in['years_experience'] ?? ($in['years'] ?? null), 0, 60);

        // Operacion
        $eq = $in['equipment_types'] ?? ($in['equipment'] ?? []);
        $lead['equipment_types'] = self::slugs($eq, 12, 30);
        $lead['truck_count'] = self::int($in['truck_count'] ?? ($in['trucks'] ?? null), 1, 999);
        $usdotRaw = preg_replace('/\D+/', '', self::str($in['usdot'] ?? '', 20));
        $lead['usdot'] = is_string($usdotRaw) && preg_match('/^\d{1,8}$/', $usdotRaw) === 1 ? ltrim($usdotRaw, '0') : null;
        if ($lead['usdot'] === '') {
            $lead['usdot'] = null;
        }
        if ($usdotRaw !== '' && $lead['usdot'] === null) {
            $flags[] = 'usdot_invalid';
        }
        $auth = strtolower(self::str($in['authority_status'] ?? ($in['authority'] ?? ''), 20));
        $lead['authority_status'] = in_array($auth, ['active', 'getting', 'none'], true) ? $auth : null;
        $lead['endorsements'] = self::slugs($in['endorsements'] ?? [], 8, 20);
        $lead['authority_age_months'] = null;

        // Preferencias
        $lead['contact_pref'] = self::slug($in['contact_pref'] ?? '', 20) ?: null;
        $lead['best_time'] = self::slug($in['best_time'] ?? '', 20) ?: null;
        $lead['job_slug'] = self::slug($in['job'] ?? ($in['job_slug'] ?? ''), 80) ?: null;

        // Atribucion
        $att = is_array($in['attribution'] ?? null) ? $in['attribution'] : (is_array($in['utm'] ?? null) ? $in['utm'] : []);
        $utm = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'first_seen'] as $k) {
            $v = self::str($att[$k] ?? ($in[$k] ?? ''), 100);
            if ($v !== '') {
                $utm[$k] = $v;
            }
        }
        $lead['utm'] = $utm ?: null;
        $lead['landing_page'] = ($v = self::str($in['landing_page'] ?? ($in['landing'] ?? ($att['landing'] ?? '')), 300)) !== '' ? $v : null;
        $lead['referrer'] = ($v = self::str($in['referrer'] ?? ($att['referrer'] ?? ''), 300)) !== '' ? $v : null;
        $ref = preg_replace('/[^A-Za-z0-9_-]/', '', self::str($in['referral_code'] ?? ($in['ref'] ?? ''), 60));
        $lead['referral_code'] = is_string($ref) && $ref !== '' ? substr($ref, 0, 40) : null;

        // Consentimiento
        $cs = is_array($in['consent'] ?? null) ? $in['consent'] : [];
        $lead['consent'] = [
            'given'      => !empty($cs['given']),
            'text'       => self::str($cs['text'] ?? '', 4000, true),
            'version'    => self::str($cs['version'] ?? '', 20),
            'at'         => isset($cs['at']) && is_string($cs['at']) ? $cs['at'] : null,
            'ip'         => isset($cs['ip']) && is_string($cs['ip']) ? substr($cs['ip'], 0, 45) : null,
            'user_agent' => isset($cs['user_agent']) && is_string($cs['user_agent']) ? substr($cs['user_agent'], 0, 255) : null,
        ];

        // Identificadores
        $sid = $in['site_lead_id'] ?? (isset($in['id']) && is_string($in['id']) && str_starts_with($in['id'], 'ld_') ? $in['id'] : '');
        $sid = preg_replace('/[^A-Za-z0-9_-]/', '', self::str($sid, 40));
        $lead['site_lead_id'] = is_string($sid) && $sid !== '' ? substr($sid, 0, 40) : null;
        $created = isset($in['created_at']) && is_string($in['created_at']) ? strtotime($in['created_at']) : false;
        $lead['created_at'] = ($created !== false && $created <= Clock::time() + 86400 && $created > 946684800) ? Clock::at($created) : Clock::now();

        return ['lead' => $lead, 'errors' => $errors, 'flags' => $flags];
    }

    private static function str(mixed $v, int $max, bool $multiline = false): string
    {
        if (!is_string($v) && !is_int($v) && !is_float($v)) {
            return '';
        }
        $v = mb_scrub((string) $v);
        $v = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $v);
        $v = (string) preg_replace($multiline ? '/(?![\n\t])\p{Cc}/u' : '/\p{Cc}+/u', $multiline ? '' : ' ', $v);
        if (!$multiline) {
            $v = (string) preg_replace('/ {2,}/', ' ', $v);
        }
        return mb_substr(trim($v), 0, $max);
    }

    private static function int(mixed $v, int $min, int $max): ?int
    {
        if (is_int($v)) {
            $n = $v;
        } elseif (is_string($v) && preg_match('/^\d{1,4}$/', trim($v)) === 1) {
            $n = (int) trim($v);
        } else {
            return null;
        }
        return $n >= $min && $n <= $max ? $n : null;
    }

    private static function slug(mixed $v, int $max): string
    {
        $s = strtolower(self::str($v, $max));
        $s = (string) preg_replace('/[\s_]+/', '-', $s);
        return (string) preg_replace('/[^a-z0-9-]/', '', $s);
    }

    /** @return list<string> */
    private static function slugs(mixed $v, int $maxItems, int $maxLen): array
    {
        if (is_string($v)) {
            $v = preg_split('/[,;]+/', $v) ?: [];
        }
        $out = [];
        foreach (is_array($v) ? $v : [] as $x) {
            $s = self::slug($x, $maxLen);
            if ($s !== '' && !in_array($s, $out, true)) {
                $out[] = $s;
            }
            if (count($out) >= $maxItems) {
                break;
            }
        }
        return $out;
    }
}
