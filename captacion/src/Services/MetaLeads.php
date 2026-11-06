<?php
/**
 * Meta Lead Ads (doc §4, A-fuente «meta_ads»). Dos pasos:
 *   1) Webhook (WebhookController::meta): Meta avisa «hay un lead nuevo» con un leadgen_id. Solo se verifica la firma, se registra el evento (idempotente) y se encola un job.
 *   2) Job `meta_fetch_lead` (cola): consulta Graph API con META_PAGE_TOKEN, traduce los campos del formulario del anuncio con el MAPEO (Configuracion > Canales externos) al formato
 *      del motor y llama a LeadIngest::handle(…, 'meta_ads'): antispam, dedupe, FMCSA, puntuacion y alertas igual que cualquier otra fuente.
 *
 * FORMATOS (NO PROBADOS contra Meta real; de memoria de la documentacion de «Webhooks for Leads Retrieval»; ajustar aqui si difieren):
 *   webhook  {"object":"page","entry":[{"id":PAGE_ID,"time":N,"changes":[{"field":"leadgen","value":{"leadgen_id":"…","form_id":"…","ad_id":"…","page_id":"…","adgroup_id":"…","created_time":N}}]}]}
 *   Graph    GET /{leadgen_id}?fields=…&access_token=…  ->  {"id":"…","created_time":"2026-…+0000","ad_id","ad_name","adset_name","campaign_name","form_id","platform",
 *            "field_data":[{"name":"full_name","values":["Joe"]},{"name":"email","values":["a@b.c"]}, …]}
 *   Los nombres de las preguntas personalizadas dependen del formulario: por eso el mapeo es configurable. El token viaja en la URL (asi lo documenta Graph) y se redacta de todo log.
 *   META_GRAPH_VERSION (opcional, p. ej. v21.0; vacio = sin version) y META_GRAPH_BASE (solo pruebas) cambian la URL.
 *
 * Consentimiento: SOLO si el formulario lo trae. Hay que pegar en Configuracion el texto exacto del aviso de ese formulario y apuntar que pregunta (o casilla del aviso personalizado)
 * lo confirma; sin ambas cosas el lead entra SIN consentimiento de mensajeria.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;

final class MetaLeads
{
    /** Destinos a los que se puede apuntar una pregunta del formulario. */
    public const TARGETS = [
        'name' => 'Nombre completo', 'first_name' => 'Nombre', 'last_name' => 'Apellido', 'phone' => 'Teléfono', 'email' => 'Correo', 'state' => 'Estado', 'city' => 'Ciudad',
        'equipment' => 'Equipo', 'trucks' => 'Camiones', 'years' => 'Años de experiencia', 'usdot' => 'USDOT', 'language' => 'Idioma', 'cdl_standard' => 'CDL del estado de residencia',
        'english' => 'Inglés', 'profile' => 'Perfil', 'authority' => 'Autoridad', 'best_time' => 'Mejor horario', 'consent' => 'Consentimiento (casilla o pregunta de aceptación)',
    ];
    /** Nombres estandar de Meta que se mapean solos. */
    public const DEFAULT_MAP = [
        'full_name' => 'name', 'first_name' => 'first_name', 'last_name' => 'last_name', 'email' => 'email', 'phone_number' => 'phone', 'phone' => 'phone', 'city' => 'city', 'state' => 'state',
    ];
    public const GRAPH_FIELDS = 'id,created_time,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,form_id,platform,is_organic,field_data,custom_disclaimer_responses';
    public const TEST_PREFIX = 'nstest_';

    /** Cliente HTTP inyectable (pruebas): callable(string $url): array{status:int,body:string,error:string}. */
    public static ?\Closure $client = null;

    // --- Configuracion ---------------------------------------------------------------------------------------------------------------

    public static function config(): array
    {
        $c = Settings::get('meta', []);
        $c = is_array($c) ? $c : [];
        $rows = [];
        foreach ((array) ($c['map'] ?? []) as $r) {
            if (is_array($r) && is_string($r['from'] ?? null) && isset(self::TARGETS[$r['to'] ?? ''])) {
                $from = self::fieldKey($r['from']);
                if ($from !== '') {
                    $rows[] = ['from' => $from, 'to' => (string) $r['to']];
                }
            }
        }
        $ch = array_values(array_intersect(['call', 'sms', 'email', 'whatsapp'], array_map('strval', (array) ($c['consent_channels'] ?? ['call', 'sms', 'email']))));
        return [
            'default_profile' => in_array($c['default_profile'] ?? '', LeadPayload::PROFILES, true) ? (string) $c['default_profile'] : 'owner_operator',
            'map'             => $rows,
            'consent_text'    => is_string($c['consent_text'] ?? null) ? mb_substr(trim($c['consent_text']), 0, 4000) : '',
            'consent_channels' => $ch,
            'disclaimer_key'  => is_string($c['disclaimer_key'] ?? null) ? self::fieldKey($c['disclaimer_key']) : '',
        ];
    }

    /** Clave de pregunta tal como la manda Meta (minusculas, sin espacios; admite signos como «?»). */
    public static function fieldKey(string $s): string
    {
        $s = strtolower(trim((string) preg_replace('/\s+/', '_', $s)));
        return mb_substr((string) preg_replace('/[^\p{L}\p{N}_?.\-]/u', '', $s), 0, 120);
    }

    // --- Webhook ----------------------------------------------------------------------------------------------------------------------

    /**
     * Extrae los leadgen del cuerpo del webhook (ya con firma valida). Tolera estructuras raras: lo que no encaja se ignora.
     * @return list<array{leadgen_id:string,form_id:?string,ad_id:?string,page_id:?string,created_time:?int}>
     */
    public static function parseWebhook(array $body): array
    {
        $out = [];
        foreach ((array) ($body['entry'] ?? []) as $entry) {
            foreach (is_array($entry) ? (array) ($entry['changes'] ?? []) : [] as $ch) {
                $v = is_array($ch) && ($ch['field'] ?? '') === 'leadgen' && is_array($ch['value'] ?? null) ? $ch['value'] : null;
                $id = $v !== null && (is_string($v['leadgen_id'] ?? null) || is_int($v['leadgen_id'] ?? null)) ? (string) $v['leadgen_id'] : '';
                if ($id === '' || preg_match('/^[A-Za-z0-9_-]{3,40}$/', $id) !== 1) {
                    continue;
                }
                $ids = static fn($k) => isset($v[$k]) && (is_string($v[$k]) || is_int($v[$k])) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) $v[$k]) === 1 ? (string) $v[$k] : null;
                $out[] = ['leadgen_id' => $id, 'form_id' => $ids('form_id'), 'ad_id' => $ids('ad_id'), 'page_id' => $ids('page_id') ?? (isset($entry['id']) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) $entry['id']) === 1 ? (string) $entry['id'] : null),
                    'created_time' => isset($v['created_time']) && is_numeric($v['created_time']) ? (int) $v['created_time'] : null];
                if (count($out) >= 50) {
                    break 2;
                }
            }
        }
        return $out;
    }

    // --- Graph API --------------------------------------------------------------------------------------------------------------------

    private static function graphUrl(string $leadgenId): string
    {
        $base = rtrim(Env::get('META_GRAPH_BASE', 'https://graph.facebook.com') ?? 'https://graph.facebook.com', '/');
        $ver = trim((string) Env::get('META_GRAPH_VERSION', ''), '/');
        return $base . ($ver !== '' && preg_match('/^v\d+\.\d+$/', $ver) === 1 ? '/' . $ver : '') . '/' . rawurlencode($leadgenId) . '?fields=' . self::GRAPH_FIELDS . '&access_token=' . rawurlencode((string) Env::get('META_PAGE_TOKEN'));
    }

    /** @return array<string,mixed> la respuesta de Graph decodificada. Lanza RuntimeException (reintentable) o PermanentJobFailure. */
    public static function fetch(string $leadgenId): array
    {
        if ((Env::get('META_PAGE_TOKEN') ?? '') === '') {
            throw new \RuntimeException('META_PAGE_TOKEN sin configurar: no se puede traer el lead de Meta.');
        }
        $url = self::graphUrl($leadgenId);
        $r = self::$client !== null ? (self::$client)($url) : Http::request('GET', $url, null, [], 10, 3);
        $json = json_decode((string) $r['body'], true);
        if ($r['status'] === 0) {
            Integrations::webhook('meta', 'err', 'Graph API no responde');
            throw new \RuntimeException('Graph API no responde: ' . ($r['error'] !== '' ? Storage::redact($r['error']) : 'sin respuesta'));
        }
        if ($r['status'] === 200 && is_array($json) && isset($json['field_data']) && is_array($json['field_data'])) {
            return $json;
        }
        $err = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [];
        $code = (int) ($err['code'] ?? 0);
        $msg = Storage::redact(mb_substr((string) ($err['message'] ?? 'respuesta inesperada'), 0, 160));
        if ($r['status'] === 404 || ($code === 100 && $r['status'] === 400 && stripos($msg, 'does not exist') !== false)) {
            throw new PermanentJobFailure('Meta: el lead ' . $leadgenId . ' no existe o no es accesible con este token (' . $msg . ').');
        }
        Integrations::webhook('meta', 'err', 'Graph API HTTP ' . $r['status'] . ($code > 0 ? ' (código ' . $code . ')' : '') . ': ' . $msg);
        throw new \RuntimeException('Graph API respondió HTTP ' . $r['status'] . ($code > 0 ? ' (código ' . $code . ')' : '') . ': ' . $msg);
    }

    // --- Traduccion de campos -----------------------------------------------------------------------------------------------------------

    private static function first(mixed $values): string
    {
        $vals = is_array($values) ? array_values(array_filter(array_map(static fn($x) => is_scalar($x) ? trim((string) $x) : '', $values), static fn($x) => $x !== '')) : [];
        return $vals ? implode('; ', $vals) : '';
    }

    private static function int(string $v): ?int
    {
        return preg_match('/\d{1,4}/', $v, $m) === 1 ? (int) $m[0] : null;
    }

    private static function has(string $v, array $needles): bool
    {
        $l = mb_strtolower($v);
        foreach ($needles as $n) {
            if (str_contains($l, $n)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> equipos del texto libre o de las opciones del formulario (se normalizan a las claves del sitio). */
    public static function equipment(string $v): array
    {
        $out = [];
        foreach (preg_split('/[;,\/]+|\band\b|\by\b/iu', $v) ?: [] as $part) {
            $p = mb_strtolower(trim($part));
            if ($p === '') {
                continue;
            }
            $k = match (true) {
                str_contains($p, 'dry') => 'dry-van',
                str_contains($p, 'reefer'), str_contains($p, 'refriger') => 'reefer',
                str_contains($p, 'flat') => 'flatbed',
                str_contains($p, 'box') => 'box-truck',
                str_contains($p, 'hot') => 'hotshot',
                str_contains($p, 'power') => 'power-only',
                str_contains($p, 'relay') => 'amazon-relay',
                str_contains($p, 'step') => 'step-deck',
                default => (string) preg_replace('/[^a-z0-9-]/', '', (string) preg_replace('/[\s_]+/', '-', $p)),
            };
            if ($k !== '' && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /**
     * Convierte lo que devolvio Graph al formato de LeadPayload.
     * @return array{data:array,unmapped:list<string>,consent_value:?bool}
     */
    public static function translate(array $graph, array $cfg, string $leadgenId): array
    {
        $map = self::DEFAULT_MAP;
        foreach ($cfg['map'] as $r) {
            $map[$r['from']] = $r['to'];
        }
        $d = ['profile' => $cfg['default_profile']];
        $unmapped = [];
        $consent = null;
        foreach ((array) ($graph['field_data'] ?? []) as $f) {
            if (!is_array($f) || !is_string($f['name'] ?? null)) {
                continue;
            }
            $key = self::fieldKey($f['name']);
            $val = self::first($f['values'] ?? []);
            $to = $map[$key] ?? null;
            if ($to === null) {
                if ($key !== '' && $val !== '' && count($unmapped) < 20) {
                    $unmapped[] = $key;
                }
                continue;
            }
            if ($val === '') {
                continue;
            }
            switch ($to) {
                case 'phone':
                    $d['phone'] = (string) preg_replace('/^p:/i', '', $val);
                    break;
                case 'equipment':
                    $d['equipment'] = array_values(array_unique(array_merge($d['equipment'] ?? [], self::equipment($val))));
                    break;
                case 'trucks':
                    $d['trucks'] = self::int($val);
                    break;
                case 'years':
                    $d['years'] = self::int($val);
                    break;
                case 'language':
                    $d['language'] = self::has($val, ['spanish', 'español', 'espanol']) || str_starts_with(mb_strtolower($val), 'es') ? 'es' : 'en';
                    break;
                case 'cdl_standard':
                    $d['cdl_standard'] = self::has($val, ['not sure', 'unsure', 'no estoy seguro', 'no sé', 'no se']) ? 'unsure' : (preg_match('/^(y|s[ií])/iu', $val) === 1 ? 'yes' : (preg_match('/^n/i', $val) === 1 ? 'no' : 'unsure'));
                    break;
                case 'english':
                    $d['english'] = self::has($val, ['basic', 'básico', 'basico', 'little', 'poco']) ? 'basic' : (preg_match('/^(y|s[ií]|fluent|good|bien)/iu', $val) === 1 ? 'yes' : (preg_match('/^n/i', $val) === 1 ? 'no' : 'basic'));
                    break;
                case 'profile':
                    $d['profile'] = self::has($val, ['company', 'chofer', 'driver']) ? 'company_driver' : (self::has($val, ['fleet', 'carrier', 'flota']) ? 'small_carrier' : (self::has($val, ['owner', 'dueño', 'dueno']) ? 'owner_operator' : $cfg['default_profile']));
                    break;
                case 'authority':
                    $d['authority'] = self::has($val, ['trámite', 'tramite', 'getting', 'applying', 'process']) ? 'getting' : (preg_match('/^(no\b|n$|none|sin\b|ninguna)/iu', $val) === 1 ? 'none' : 'active');
                    break;
                case 'consent':
                    $consent = !in_array(mb_strtolower($val), ['no', 'false', '0', 'n', 'off', 'unchecked'], true);
                    break;
                default:
                    $d[$to] = $val;
            }
        }
        // Casilla de un aviso personalizado (custom_disclaimer_responses): solo si se configuro su clave.
        if ($cfg['disclaimer_key'] !== '') {
            foreach ((array) ($graph['custom_disclaimer_responses'] ?? []) as $c) {
                if (is_array($c) && self::fieldKey((string) ($c['checkbox_key'] ?? '')) === $cfg['disclaimer_key']) {
                    $consent = in_array(strtolower((string) ($c['is_checked'] ?? '')), ['1', 'true', 'yes'], true) || ($c['is_checked'] ?? null) === true;
                }
            }
        }
        $d['site_lead_id'] = 'meta_' . $leadgenId;
        $created = isset($graph['created_time']) ? strtotime((string) $graph['created_time']) : false;
        if ($created !== false) {
            $d['created_at'] = gmdate('Y-m-d H:i:s', (int) $created);
        }
        $name = fn(string $k, string $alt) => (string) ($graph[$k] ?? '') !== '' ? (string) $graph[$k] : (string) ($graph[$alt] ?? '');
        $platform = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($graph['platform'] ?? '')));
        $d['utm'] = array_filter([
            'utm_source' => 'meta', 'utm_medium' => $platform !== '' ? $platform : 'paid_social', 'utm_campaign' => mb_substr($name('campaign_name', 'campaign_id'), 0, 100),
            'utm_content' => mb_substr($name('ad_name', 'ad_id'), 0, 100), 'utm_term' => isset($graph['form_id']) ? 'form:' . mb_substr((string) $graph['form_id'], 0, 40) : '',
        ], static fn($v) => $v !== '');
        $d['landing_page'] = 'meta_lead_ad' . (isset($graph['form_id']) ? ':form_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $graph['form_id']) : '');
        if ($consent === true && $cfg['consent_text'] !== '' && $cfg['consent_channels'] !== []) {
            $d['consent'] = ['given' => true, 'text' => $cfg['consent_text'], 'version' => 'meta-form', 'at' => $d['created_at'] ?? null, 'channels' => $cfg['consent_channels']];
        }
        return ['data' => $d, 'unmapped' => $unmapped, 'consent_value' => $consent];
    }

    // --- Job ----------------------------------------------------------------------------------------------------------------------------

    /** Handler de `meta_fetch_lead`. */
    public static function jobFetch(array $payload): void
    {
        $id = (string) ($payload['leadgen_id'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{3,40}$/', $id) !== 1) {
            throw new PermanentJobFailure('leadgen_id no valido.');
        }
        $evId = (int) Db::value("SELECT id FROM webhook_events WHERE source = 'meta' AND event_key = ?", [Webhook::key($id)]);
        $graph = self::fetch($id);
        $graph['form_id'] = $graph['form_id'] ?? ($payload['form_id'] ?? null);
        $graph['ad_id'] = $graph['ad_id'] ?? ($payload['ad_id'] ?? null);
        $cfg = self::config();
        $t = self::translate($graph, $cfg, $id);
        $r = LeadIngest::handle($t['data'], 'meta_ads', ['fmcsa_sync' => true]);
        if (!$r['ok']) {
            // Datos que no se pueden usar (sin nombre ni contacto, correo desechable…): no se reintenta; queda registrado para revisar.
            if ($evId > 0) {
                Webhook::finish($evId, 'ignored', null, 'lead rechazado: ' . $r['reason'] . ($r['errors'] ? ' (' . implode(',', array_keys($r['errors'])) . ')' : ''));
            }
            Integrations::webhook('meta', 'ok');
            return;
        }
        if ($r['lead_id'] !== null && $t['unmapped'] && $r['status'] === 'created') {
            LeadIngest::event((int) $r['lead_id'], 'meta_fields_unmapped', ['fields' => $t['unmapped']]);
        }
        if ($evId > 0) {
            Webhook::finish($evId, 'processed', $r['lead_id'], $r['status'] . ($t['unmapped'] ? ' · ' . count($t['unmapped']) . ' campos sin mapear' : ''));
        }
        Integrations::webhook('meta', 'ok');
    }
}
