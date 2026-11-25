<?php
/**
 * Ficha de entrega a NorthStar (doc §11): lo que recibe el equipo de NorthStar cuando un lead pasa a «Presentado a NorthStar».
 * Lleva: contacto, perfil, equipo, estado, puntuacion y su desglose, datos de FMCSA con banderas rojas, notas y resultado de llamadas, preferencias declaradas,
 * consentimientos (canal y fecha) y atribucion. NUNCA lleva documentos de conductores (el CRM no los guarda). Tres salidas: texto, HTML de correo (estilos en linea: es un correo,
 * no una pagina del panel) y el JSON del webhook (payload()).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;

final class HandoffCard
{
    /** Ficha estructurada de un lead (fila de leads ya hidratada). */
    public static function build(array $lead): array
    {
        $id = (int) $lead['id'];
        $fm = is_array($lead['fmcsa_data'] ?? null) ? $lead['fmcsa_data'] : [];
        $bd = is_array($lead['score_breakdown'] ?? null) ? $lead['score_breakdown'] : [];
        $flags = array_values(array_map('strval', (array) ($lead['flags'] ?? [])));
        $notes = $id > 0 ? Db::all('SELECT n.body, n.created_at, u.name AS author FROM lead_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.lead_id = ? AND n.deleted_at IS NULL ORDER BY n.created_at DESC, n.id DESC LIMIT 8', [$id]) : [];
        $calls = $id > 0 ? Db::all("SELECT status, duration_sec, body, created_at FROM messages WHERE lead_id = ? AND channel = 'call' ORDER BY created_at DESC, id DESC LIMIT 8", [$id]) : [];
        $consents = $id > 0 ? Db::all('SELECT channel, granted_at, revoked_at, consent_version FROM consents WHERE lead_id = ? ORDER BY granted_at DESC, id DESC LIMIT 12', [$id]) : [];
        $equipment = array_map(static fn($t) => Catalog::label(Catalog::EQUIPMENT, (string) $t), (array) ($lead['equipment_types'] ?? []));
        return [
            'id'            => $id,
            'name'          => Fmt::name($lead),
            'first_name'    => (string) ($lead['first_name'] ?? ''),
            'last_name'     => (string) ($lead['last_name'] ?? ''),
            'profile'       => (string) $lead['profile'],
            'profile_label' => Catalog::label(Catalog::PROFILES, $lead['profile']),
            'phone'         => $lead['phone_e164'] ?? null,
            'phone_label'   => Fmt::phone($lead['phone_e164'] ?? null),
            'email'         => $lead['email'] ?? null,
            'state'         => $lead['state'] ?? null,
            'city'          => $lead['city'] ?? null,
            'language'      => (string) ($lead['language'] ?? 'en'),
            'contact_pref'  => $lead['contact_pref'] ?? null,
            'best_time'     => $lead['best_time'] ?? null,
            'equipment'     => $equipment,
            'truck_count'   => $lead['truck_count'] ?? null,
            'years'         => $lead['years_experience'] ?? null,
            'cdl'           => Catalog::label(Catalog::CDL, $lead['cdl_type'] ?? null),
            'english'       => Catalog::label(Catalog::ENGLISH, $lead['english_level'] ?? null),
            'authority'     => Catalog::label(Catalog::AUTHORITY, $lead['authority_status'] ?? null),
            'usdot'         => $lead['usdot'] ?? null,
            'authority_age_months' => $lead['authority_age_months'] ?? null,
            'stage'         => Catalog::stageLabel((string) ($lead['pipeline'] ?? 'A'), $lead['stage'] ?? null),
            'temperature'   => Catalog::label(Catalog::TEMPS, $lead['temperature'] ?? null),
            'score'         => (int) ($lead['score'] ?? 0),
            'breakdown'     => array_map(static fn($i) => ['label' => Catalog::SCORE_LABELS[(string) ($i['key'] ?? '')] ?? (string) ($i['label'] ?? ''),   // fase AT: con tildes (las de Scoring van sin)
                 'points' => (int) ($i['points'] ?? 0), 'note' => Catalog::scoreNote((string) ($i['note'] ?? ''))], (array) ($bd['items'] ?? [])),
            'fmcsa'         => $fm ? [
                'found' => (bool) ($fm['found'] ?? false), 'authority' => Catalog::label(Catalog::AUTHORITY, $fm['authority_status'] ?? null), 'insured' => $fm['insured'] ?? null,
                'power_units' => $fm['power_units'] ?? null, 'drivers' => $fm['drivers'] ?? null, 'legal_name' => $fm['legal_name'] ?? null,
                'red_flags' => array_values(array_map(static fn($f) => Catalog::label(Catalog::FLAGS, 'red:' . $f), (array) ($fm['red_flags'] ?? []))),
            ] : null,
            'red_flags'     => array_values(array_map(static fn($f) => Catalog::label(Catalog::FLAGS, (string) $f), array_filter($flags, static fn($f) => str_starts_with($f, 'red:')))),
            'flags'         => array_values(array_map(static fn($f) => Catalog::label(Catalog::FLAGS, (string) $f), array_filter($flags, static fn($f) => !str_starts_with($f, 'red:')))),
            'notes'         => array_map(static fn($n) => ['at' => (string) $n['created_at'], 'by' => $n['author'], 'body' => mb_substr((string) $n['body'], 0, 600)], $notes),
            'calls'         => array_map(static fn($c) => ['at' => (string) $c['created_at'], 'outcome' => Catalog::label(Catalog::CALL_OUTCOMES, (string) $c['status']), 'minutes' => $c['duration_sec'] !== null ? intdiv((int) $c['duration_sec'], 60) : null, 'note' => $c['body'] !== null ? mb_substr((string) $c['body'], 0, 300) : null], $calls),
            'consents'      => array_map(static fn($c) => ['channel' => (string) $c['channel'], 'granted_at' => (string) $c['granted_at'], 'revoked_at' => $c['revoked_at'], 'version' => $c['consent_version']], $consents),
            'attribution'   => [
                'source' => Catalog::source($lead['source'] ?? null), 'landing_page' => $lead['landing_page'] ?? null, 'referrer' => $lead['referrer'] ?? null,
                'utm' => is_array($lead['utm'] ?? null) ? $lead['utm'] : [], 'referral_code' => $lead['referral_code'] ?? null,
            ],
            'received_at'   => (string) ($lead['created_at'] ?? Clock::now()),
        ];
    }

    /** Lead ficticio para «Enviar prueba» (no existe en la base). */
    public static function sample(): array
    {
        $now = Clock::now();
        return self::build([
            'id' => 0, 'first_name' => 'Prueba', 'last_name' => 'Entrega', 'profile' => 'owner_operator', 'phone_e164' => '+13055550100', 'email' => 'prueba-entrega@example.com', 'state' => 'FL', 'city' => 'Miami',
            'language' => 'en', 'contact_pref' => 'whatsapp', 'best_time' => 'morning', 'equipment_types' => ['dry-van', 'reefer'], 'truck_count' => 2, 'years_experience' => 5, 'cdl_type' => 'standard',
            'english_level' => 'yes', 'authority_status' => 'active', 'usdot' => '1234567', 'authority_age_months' => 14, 'pipeline' => 'A', 'stage' => 'presented', 'temperature' => 'hot', 'score' => 82,
            'score_breakdown' => ['items' => [['label' => 'CDL estandar', 'points' => 20, 'note' => ''], ['label' => 'Ingles', 'points' => 15, 'note' => 'yes'], ['label' => 'Autoridad activa verificada (FMCSA)', 'points' => 15, 'note' => '']]],
            'fmcsa_data' => ['found' => true, 'authority_status' => 'active', 'insured' => true, 'power_units' => 2, 'drivers' => 2, 'red_flags' => []], 'flags' => [],
            'source' => 'web_careers', 'landing_page' => '/careers/owner-operators/', 'utm' => ['utm_source' => 'google'], 'created_at' => $now,
        ]);
    }

    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function yn(mixed $v): string
    {
        return $v === null ? 'sin dato' : ($v ? 'sí' : 'no');
    }

    /** Filas [titulo => [[etiqueta, valor], ...]] comunes al texto y al HTML. */
    private static function sections(array $c): array
    {
        $dash = static fn($v) => ($v === null || $v === '' || $v === []) ? '—' : (is_array($v) ? implode(', ', $v) : (string) $v);
        $loc = trim(($c['city'] ? $c['city'] . ', ' : '') . ($c['state'] ?? ''), ', ');
        $sec = [];
        $sec['Contacto'] = [['Nombre', $c['name']], ['Perfil', $c['profile_label']], ['Teléfono', $c['phone'] ?? '—'], ['Correo', $dash($c['email'])], ['Ubicación', $dash($loc)], ['Idioma', Catalog::label(Catalog::LANGS, $c['language'])]];
        $sec['Equipo y elegibilidad'] = [['Equipo', $dash($c['equipment'])], ['Camiones', $dash($c['truck_count'])], ['Años de experiencia', $dash($c['years'])], ['CDL', $c['cdl']], ['Inglés', $c['english']], ['Autoridad (declarada)', $c['authority']], ['USDOT', $dash($c['usdot'])], ['Antigüedad de la autoridad', $c['authority_age_months'] !== null ? $c['authority_age_months'] . ' meses' : '—']];
        $sec['Estado y puntuación'] = [['Etapa', $c['stage']], ['Temperatura', $c['temperature']], ['Puntuación', $c['score'] . '/100']];
        foreach ($c['breakdown'] as $b) {
            $sec['Estado y puntuación'][] = ['  · ' . $b['label'], ($b['points'] > 0 ? '+' : '') . $b['points'] . ($b['note'] !== '' ? ' (' . $b['note'] . ')' : '')];
        }
        if ($c['fmcsa'] !== null) {
            $f = $c['fmcsa'];
            $sec['FMCSA'] = [['Encontrado', self::yn($f['found'])], ['Autoridad', $f['authority']], ['Seguro registrado', self::yn($f['insured'])], ['Unidades / conductores', $dash($f['power_units']) . ' / ' . $dash($f['drivers'])], ['Banderas rojas', $c['red_flags'] ? implode(', ', $c['red_flags']) : 'ninguna']];
        } else {
            $sec['FMCSA'] = [['Estado', 'sin consulta a FMCSA' . ($c['red_flags'] ? ' · banderas: ' . implode(', ', $c['red_flags']) : '')]];
        }
        $sec['Preferencias declaradas'] = [['Medio de contacto', $dash(['call' => 'Llamada', 'whatsapp' => 'WhatsApp', 'text' => 'Texto', 'email' => 'Correo'][(string) $c['contact_pref']] ?? $c['contact_pref'])], ['Mejor horario', $dash(['morning' => 'Mañana', 'afternoon' => 'Tarde', 'evening' => 'Noche', 'any' => 'Cualquier hora'][(string) $c['best_time']] ?? $c['best_time'])], ['Avisos del sistema', $dash($c['flags'])]];
        $calls = [];
        foreach ($c['calls'] as $x) {
            $calls[] = [Fmt::dateTime($x['at']), $x['outcome'] . ($x['minutes'] !== null ? ' · ' . $x['minutes'] . ' min' : '') . ($x['note'] ? ' · ' . $x['note'] : '')];
        }
        $sec['Llamadas'] = $calls ?: [['—', 'Sin llamadas registradas']];
        $notes = [];
        foreach ($c['notes'] as $x) {
            $notes[] = [Fmt::dateTime($x['at']) . ($x['by'] ? ' · ' . $x['by'] : ''), $x['body']];
        }
        $sec['Notas'] = $notes ?: [['—', 'Sin notas']];
        $cons = [];
        $chan = ['call' => 'Llamada', 'sms' => 'SMS', 'email' => 'Correo', 'whatsapp' => 'WhatsApp'];
        foreach ($c['consents'] as $x) {
            $cons[] = [$chan[$x['channel']] ?? $x['channel'], 'dado ' . Fmt::dateTime($x['granted_at']) . ($x['revoked_at'] !== null ? ' · REVOCADO ' . Fmt::dateTime((string) $x['revoked_at']) : '') . ($x['version'] ? ' (' . $x['version'] . ')' : '')];
        }
        $sec['Consentimientos'] = $cons ?: [['—', 'Sin consentimientos registrados']];
        $a = $c['attribution'];
        $utm = [];
        foreach ($a['utm'] as $k => $v) {
            $utm[] = $k . '=' . $v;
        }
        $sec['Atribución'] = [['Fuente', $a['source']], ['Página de llegada', $dash($a['landing_page'])], ['Referido por', $dash($a['referral_code'])], ['UTM', $dash($utm)], ['Recibido', Fmt::dateTime($c['received_at'])]];
        return $sec;
    }

    public static function text(array $c, array $handoff): string
    {
        $test = ($handoff['reason'] ?? '') === 'test';
        $out = ($test ? "*** PRUEBA DE ENTREGA: lead ficticio, no hay nada que confirmar ***\n\n" : '') . 'LEAD PRESENTADO A NORTHSTAR' . (($handoff['version'] ?? 1) > 1 ? ' (reenvío ' . (int) $handoff['version'] . ')' : '') . "\n" . str_repeat('=', 40) . "\n";
        foreach (self::sections($c) as $title => $rows) {
            $out .= "\n" . mb_strtoupper($title) . "\n";
            foreach ($rows as [$k, $v]) {
                $out .= $k . ': ' . $v . "\n";
            }
        }
        $u = $handoff['urls'] ?? [];
        if (!empty($u['signed'])) {
            $out .= "\nCONFIRMAR (un clic, sin iniciar sesión; cada enlace se usa una sola vez)\nFirmó: " . $u['signed'] . "\nLo rechazamos: " . $u['rejected'] . "\n";
        } else {
            $out .= "\n(Los enlaces de confirmación no se generan en las pruebas.)\n";
        }
        return $out . "\nLos documentos de los conductores NO pasan por este sistema: se piden y se guardan en el flujo de NorthStar.\n";
    }

    /**
     * Ficha HTML del correo de entrega a NorthStar. Fase AT: mismo lenguaje visual que los correos a leads (EmailLayout): marca en texto sobre la tarjeta,
     * fuente del sistema, títulos en frase normal (sin mayúsculas forzadas), datos en dos columnas y los botones de confirmación arriba.
     */
    public static function html(array $c, array $handoff): string
    {
        $f = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $test = ($handoff['reason'] ?? '') === 'test';
        $u = $handoff['urls'] ?? [];
        $h = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>Lead presentado a NorthStar</title>'
            . '<style>@media (max-width:660px){.ns-card{padding-left:22px!important;padding-right:22px!important}.ns-k{display:block!important;width:auto!important;padding-bottom:0!important}.ns-v{display:block!important}}</style></head>'
            . '<body style="margin:0;background:#F3F4F6;color:#1F2933">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F4F6"><tr><td align="center" style="padding:32px 12px">'
            . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px">'
            . EmailLayout::logoRow(32, 'CAPTACIÓN')
            . '<tr><td class="ns-card" style="padding:28px 32px 8px;background:#FFFFFF;border:1px solid #E5E7EB;border-top:0;border-bottom:0">'
            . ($test ? '<p style="margin:0 0 16px;padding:10px 14px;background:#FEF3C7;color:#8A4B0B;border-radius:8px;font:400 14px/1.5 ' . $f . '"><b>Prueba de entrega</b>: lead ficticio, no hay nada que confirmar.</p>' : '')
            . '<p style="margin:0;font:500 13px ' . $f . ';color:#6B7280">Lead presentado a NorthStar' . (($handoff['version'] ?? 1) > 1 ? ' · reenvío ' . (int) $handoff['version'] : '') . '</p>'
            . '<h1 style="margin:6px 0 8px;font:700 26px/1.25 ' . $f . ';color:#1F2933">' . self::e($c['name']) . '</h1>'
            . '<p style="margin:0;font:400 15px/1.6 ' . $f . ';color:#374151">' . self::e($c['profile_label']) . ' · Puntuación <b>' . (int) $c['score'] . '/100</b> (' . self::e($c['temperature']) . ')'
            . ($c['red_flags'] ? '<br><b style="color:#B42318">Banderas rojas: ' . self::e(implode(', ', $c['red_flags'])) . '</b>' : '') . '</p>';
        if (!empty($u['signed'])) {
            $h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px 0 6px"><tr>'
                . '<td style="padding-right:10px;border-radius:8px;background:#0A1D33"><a href="' . self::e($u['signed']) . '" style="display:inline-block;padding:13px 22px;font:600 15px ' . $f . ';color:#FFFFFF;text-decoration:none;border-radius:8px">Firmó</a></td>'
                . '<td style="padding-left:10px"><a href="' . self::e($u['rejected']) . '" style="display:inline-block;padding:12px 20px;font:600 15px ' . $f . ';color:#0A1D33;text-decoration:none;border:1px solid #0A1D33;border-radius:8px">Lo rechazamos</a></td>'
                . '</tr></table><p style="margin:0;font:400 13px/1.5 ' . $f . ';color:#6B7280">Un clic, sin iniciar sesión. Cada enlace se usa una sola vez y caduca a los 30 días.</p>';
        }
        $h .= '</td></tr>';
        foreach (self::sections($c) as $title => $rows) {
            $h .= '<tr><td class="ns-card" style="padding:22px 32px 0;background:#FFFFFF;border-left:1px solid #E5E7EB;border-right:1px solid #E5E7EB">'
                . '<p style="margin:0 0 8px;padding-bottom:8px;border-bottom:1px solid #E5E7EB;font:600 14px ' . $f . ';color:#0A6F8C">' . self::e($title) . '</p>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
            foreach ($rows as [$k, $v]) {
                $h .= '<tr><td class="ns-k" valign="top" style="width:38%;padding:4px 12px 4px 0;font:400 13px/1.5 ' . $f . ';color:#6B7280">' . self::e(ltrim((string) $k, ' ·')) . '</td>'
                    . '<td class="ns-v" valign="top" style="padding:4px 0 8px;font:400 15px/1.5 ' . $f . ';color:#1F2933">' . nl2br(self::e($v)) . '</td></tr>';
            }
            $h .= '</table></td></tr>';
        }
        return $h . '<tr><td class="ns-card" style="padding:22px 32px 28px;background:#FFFFFF;border:1px solid #E5E7EB;border-top:0;border-radius:0 0 12px 12px"><p style="margin:0;font:400 13px/1.5 ' . $f . ';color:#6B7280">Los documentos de los conductores no pasan por este sistema: se piden y se guardan en el flujo de NorthStar.</p></td></tr></table></td></tr></table></body></html>';
    }

    /** Ficha para el webhook (claves estables en ingles; sin textos de pantalla salvo etiquetas). */
    public static function payload(array $c): array
    {
        return [
            'id' => $c['id'], 'name' => $c['name'], 'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'profile' => $c['profile'], 'phone' => $c['phone'], 'email' => $c['email'],
            'state' => $c['state'], 'city' => $c['city'], 'language' => $c['language'], 'contact_pref' => $c['contact_pref'], 'best_time' => $c['best_time'],
            'equipment' => $c['equipment'], 'truck_count' => $c['truck_count'], 'years_experience' => $c['years'], 'usdot' => $c['usdot'], 'authority_age_months' => $c['authority_age_months'],
            'stage' => $c['stage'], 'temperature' => $c['temperature'], 'score' => $c['score'], 'score_breakdown' => $c['breakdown'], 'fmcsa' => $c['fmcsa'], 'red_flags' => $c['red_flags'],
            'notes' => $c['notes'], 'calls' => $c['calls'], 'consents' => $c['consents'], 'attribution' => $c['attribution'], 'received_at' => $c['received_at'],
        ];
    }
}
