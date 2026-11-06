<?php
/**
 * Pegamento entre el motor puro (RuleEngine) y el resto del CRM (Q5b): arma los datos de un lead (facts), aplica los conjuntos PUBLICADOS despues de la puntuacion base y devuelve el
 * mismo resultado que Scoring::score() mas lo que las reglas anadieron.
 *
 * EQUIVALENCIA: con el interruptor general apagado, sin ningun conjunto publicado o sin ninguna regla que coincida, score() devuelve EXACTAMENTE lo que devuelve Scoring::score()
 * (mismo arreglo, sin claves nuevas). La puntuacion del documento (§6) sigue siendo la base; las reglas la ajustan, nunca la sustituyen.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class RuleRunner
{
    /** Interruptor general (ajuste rules.enabled; encendido por defecto): apagado, el motor ignora todas las reglas. */
    public static function enabled(): bool
    {
        try {
            return Settings::get('rules.enabled', true) !== false;
        } catch (\Throwable) {
            return false;   // si la base de reglas no se puede leer, el motor sigue con la puntuacion del documento
        }
    }

    /** @return list<array> conjuntos publicados en orden de aplicacion ([] si estan apagadas o no hay) */
    public static function sets(): array
    {
        if (!self::enabled()) {
            return [];
        }
        try {
            return RuleSets::engineSets();
        } catch (\Throwable $t) {
            Storage::error('rules unavailable', get_class($t) . ': ' . $t->getMessage());
            return [];
        }
    }

    /** @return array<int,list<string>> canales con consentimiento vigente por lead (consulta unica; respeta las banderas stop) */
    public static function consentMap(array $leads): array
    {
        $ids = array_values(array_filter(array_map(static fn($l) => (int) ($l['id'] ?? 0), $leads)));
        $out = [];
        if (!$ids) {
            return $out;
        }
        $last = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = Db::all('SELECT lead_id, channel, revoked_at FROM consents WHERE lead_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY granted_at, id', $chunk);
            foreach ($rows as $r) {
                $last[(int) $r['lead_id']][(string) $r['channel']] = $r['revoked_at'] === null;
            }
        }
        foreach ($leads as $l) {
            $id = (int) ($l['id'] ?? 0);
            $flags = is_array($l['flags'] ?? null) ? $l['flags'] : [];
            $ch = [];
            foreach (($last[$id] ?? []) as $c => $ok) {
                if ($ok && !in_array('stop', $flags, true) && !in_array('stop_' . $c, $flags, true)) {
                    $ch[] = $c;
                }
            }
            $out[$id] = $ch;
        }
        return $out;
    }

    /** Canales de un lead que todavia no existe (alta): los que cubre el consentimiento del formulario. */
    private static function formConsent(array $lead): array
    {
        $c = is_array($lead['consent'] ?? null) ? $lead['consent'] : [];
        return !empty($c['given']) ? array_values((array) ($c['channels'] ?? Consent::FORM_CHANNELS)) : [];
    }

    /** Datos del lead que las condiciones pueden mirar (ver Rules::FIELDS). Puro: la hora y el consentimiento llegan de fuera. @param list<string>|null $consent */
    public static function facts(array $lead, int $now, ?array $consent = null): array
    {
        $flags = is_array($lead['flags'] ?? null) ? $lead['flags'] : [];
        $fm = [];
        foreach (['red:authority_inactive' => 'authority_inactive', 'red:no_insurance' => 'no_insurance', 'usdot_not_found' => 'usdot_not_found', 'fmcsa_pending' => 'fmcsa_pending'] as $flag => $k) {
            if (in_array($flag, $flags, true)) {
                $fm[] = $k;
            }
        }
        $created = Clock::parse(is_string($lead['created_at'] ?? null) ? $lead['created_at'] : null);
        $n = static fn($v): ?int => isset($v) && $v !== '' ? (int) $v : null;
        $low = static fn($v): ?string => isset($v) && $v !== '' ? mb_strtolower((string) $v) : null;
        $utm = is_array($lead['utm'] ?? null) ? $lead['utm'] : [];
        return [
            'profile'       => (string) ($lead['profile'] ?? ''),
            'state'         => isset($lead['state']) && $lead['state'] !== '' ? strtoupper((string) $lead['state']) : null,
            'equipment'     => array_values(array_map('strtolower', array_map('strval', (array) ($lead['equipment_types'] ?? [])))),
            'years'         => $n($lead['years_experience'] ?? null),
            'trucks'        => $n($lead['truck_count'] ?? null),
            'source'        => (string) ($lead['source'] ?? ''),
            'campaign'      => $low($utm['utm_campaign'] ?? null),
            'language'      => (string) ($lead['language'] ?? ''),
            'has_usdot'     => isset($lead['usdot']) && $lead['usdot'] !== '',
            'fmcsa_flag'    => $fm,
            'authority_age' => $n($lead['authority_age_months'] ?? null),
            'consent'       => $consent ?? [],
            'hours_since'   => $created === null ? 0.0 : round(max(0, $now - $created) / 3600, 2),
            'stage'         => (string) ($lead['stage'] ?? 'new'),
            'landing'       => $low($lead['landing_page'] ?? null),
        ];
    }

    /** Variables de los textos de alertas y tareas (lista blanca Rules::VARS). Sin telefono ni correo. */
    public static function vars(array $lead, int $score, string $temperature): array
    {
        $eq = array_map(static fn($e) => Catalog::label(Catalog::EQUIPMENT, (string) $e), (array) ($lead['equipment_types'] ?? []));
        return [
            'nombre' => trim((string) ($lead['first_name'] ?? '') . ' ' . (string) ($lead['last_name'] ?? '')), 'perfil' => Catalog::label(Catalog::PROFILES, (string) ($lead['profile'] ?? '')),
            'estado' => (string) ($lead['state'] ?? ''), 'equipo' => implode(', ', $eq), 'camiones' => isset($lead['truck_count']) ? (string) $lead['truck_count'] : '', 'anos' => isset($lead['years_experience']) ? (string) $lead['years_experience'] : '',
            'puntuacion' => (string) $score, 'temperatura' => Catalog::label(Catalog::TEMPS, $temperature), 'fuente' => Catalog::source((string) ($lead['source'] ?? '')),
            'etapa' => Catalog::stageAny((string) ($lead['stage'] ?? 'new')),
        ];
    }

    /**
     * Igual que Scoring::score() (misma firma) y, si hay reglas publicadas que actuan, con el ajuste aplicado, el desglose ampliado (breakdown.rules, rules_adjust, score_base)
     * y la clave 'effects' (alertas, tareas, etiquetas, cola de revision) para que el motor las ejecute. $sets permite probar conjuntos distintos sin tocar la base.
     * @param list<string>|null $consent canales con consentimiento (si no se da y alguna regla lo usa, se lee de la base o del formulario)
     */
    public static function score(array $lead, ?array $settings = null, ?int $now = null, ?array $sets = null, ?array $consent = null): array
    {
        $base = Scoring::score($lead, $settings);
        $sets ??= self::sets();
        if (!$sets) {
            return $base;
        }
        $now ??= Clock::time();
        try {
            if ($consent === null && RuleEngine::usesField($sets, 'consent')) {
                $consent = isset($lead['id']) ? (self::consentMap([$lead])[(int) $lead['id']] ?? []) : self::formConsent($lead);
            }
            return self::combine($lead, $base, $sets, self::facts($lead, $now, $consent), $settings ?? Scoring::settings());
        } catch (\Throwable $t) {
            Storage::error('rules score failed', get_class($t) . ': ' . $t->getMessage());   // un fallo de las reglas NUNCA bloquea la captacion: el lead se puntua con el documento
            return $base;
        }
    }

    /** Aplica $sets sobre un resultado base ya calculado (lo usan la vista previa y la simulacion, que calculan la base una sola vez). */
    public static function combine(array $lead, array $base, array $sets, array $facts, array $settings): array
    {
        $r = RuleEngine::run($sets, $facts, ['score' => $base['score'], 'temperature' => $base['temperature']], $settings['scoring.temperature']);
        if (!$r['applied']) {
            return $base;
        }
        $bd = $base['breakdown'];
        $bd['score_base'] = $base['score'];
        $bd['rules_adjust'] = $r['adjust'];
        $bd['rules'] = array_map(static fn($a) => [
            'set_id' => $a['set_id'], 'set' => $a['set'], 'rule_id' => $a['rule_id'], 'name' => $a['name'], 'kind' => $a['kind'],
            'effects' => array_map(static fn($e) => array_diff_key($e, ['text' => 1, 'title' => 1]) + ($e['type'] === 'create_task' ? ['title' => mb_substr($e['title'], 0, 60)] : []), $a['effects']),
        ], $r['applied']);
        $bd['score'] = $r['score'];
        $bd['temperature'] = $r['temperature'];
        $out = $base;
        $out['score'] = $r['score'];
        $out['temperature'] = $r['temperature'];
        $out['breakdown'] = $bd;
        $out['effects'] = ['applied' => $r['applied'], 'alerts' => $r['alerts'], 'tasks' => $r['tasks'], 'tags' => $r['tags'], 'review' => $r['review'], 'adjust' => $r['adjust']];
        return $out;
    }
}
