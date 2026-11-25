<?php
/**
 * Fase AQ (2026-11-25): correos en lote a prospectos FMCSA por segmentos. El cliente lo pidió en la reunión del 2026-11-24 («enviar ciertos tipos de correo
 * a los que tengan una flota más grande… por estas parametrizaciones», «un rastreador que confirme si el cliente abrió el correo»).
 *
 * Una campaña = segmento (estado, flota, antigüedad de la autoridad, estado del prospecto) + asunto y cuerpo propios. Reglas (CAN-SPAM y prudencia):
 *  - Para lanzar hacen falta: dirección física del remitente (Configuración > Correo), SMTP real, texto sin marcadores [[…]] ni variables sin resolver,
 *    y una prueba enviada DESPUÉS de la última edición. Cada correo lleva pie con dirección, motivo y baja de un clic (/u/{token} + List-Unsubscribe).
 *  - Nunca se escribe a: «No llamar más», «No interesado», convertidos en lead, quien se dio de baja (email_opt_out_at o lista de supresión),
 *    correos inválidos, ni a quien recibió un correo de campaña en los últimos N días (periodo de espera). Un correo por dirección y campaña.
 *  - El envío va por la cola (job prospect_mail_tick) en lotes, con tope por hora propio (no comparte el de las secuencias). 3 rechazos seguidos del
 *    servidor de correo pausan la campaña. Las aperturas se cuentan con un píxel 1×1 (/o/{token}): orientativas (Apple Mail las precarga y otros
 *    clientes bloquean imágenes).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class ProspectMail
{
    public const STATUS = ['draft' => 'Borrador', 'sending' => 'Enviando', 'paused' => 'En pausa', 'done' => 'Terminada', 'cancelled' => 'Cancelada'];
    public const R_STATUS = ['queued' => 'En cola', 'sent' => 'Enviado', 'failed' => 'Falló', 'skipped' => 'Omitido'];
    /** Estados del prospecto que se pueden elegir como segmento (No interesado y No llamar más nunca reciben correos). */
    public const SEG_TABS = ['' => 'Cualquier estado', 'to_call' => 'Por llamar', 'no_answer' => 'Sin respuesta', 'talked' => 'Conversó', 'interested' => 'Interesado'];
    public const DEFAULT_CAP_HOUR = 30;
    public const DEFAULT_COOLDOWN_DAYS = 30;
    public const MAX_RECIPIENTS = 2000;
    public const BATCH = 10;
    public const VARS = [
        '{empresa}' => 'Razón social del transportista', '{ciudad}' => 'Ciudad', '{estado}' => 'Estado (EE. UU.)', '{flota}' => 'Flota («6 trucks», «1 truck»)',
        '{whatsapp}' => 'WhatsApp de NorthStar', '{link_calculadora}' => 'Calculadora de rate per mile del sitio (cuenta clics)', '{link_web}' => 'Página principal del sitio (cuenta clics)',
        '{link_baja}' => 'Enlace de baja (el pie ya lo lleva)',
    ];
    /** Fase AT: segundo correo a los N días a quien no abrió, no hizo clic ni respondió (0 = sin segundo correo). */
    public const FOLLOWUP_DAYS = [0 => 'Sin segundo correo', 3 => 'A los 3 días', 5 => 'A los 5 días', 7 => 'A los 7 días', 10 => 'A los 10 días'];
    /** Fase AW: segmento por idioma preferido (lo anota quien llama; sin dato cuenta como «no prefiere español»). */
    public const SEG_LANGS = ['' => 'Cualquier idioma', 'es' => 'Prefieren español', 'en' => 'No prefieren español'];
    /** Fase AW: idioma del correo (pie, baja y variables). */
    public const LANGS = ['en' => 'Inglés', 'es' => 'Español'];
    public const FOLLOWUP_SUBJECT = ['en' => 'Following up: dispatch for {empresa}', 'es' => 'Seguimiento: dispatch para {empresa}'];
    public const FOLLOWUP_BODY_ES = "Hola:\n\nTe escribo de nuevo por mi correo de la semana pasada. Si el dispatch de {empresa} está entre tus pendientes, en NorthStar reservamos cargas de Amazon Relay y dry van, seguimos cada viaje y llevamos el papeleo en orden.\n\nBasta con responder este correo o escribirnos por WhatsApp al {whatsapp} para empezar.\n\nNorthStar Freight Solutions";
    public const FOLLOWUP_BODY = "Hi,\n\nJust following up on my note from last week. If dispatch support for {empresa} is on your list, NorthStar can book Amazon Relay and dry van loads, follow every trip and keep the paperwork organized.\n\nA quick reply or a WhatsApp message at {whatsapp} is enough to start.\n\nNorthStar Freight Solutions";
    /** Destinos de los enlaces con conteo de clics (/r/{token}): solo estos, nunca una URL que llegue de fuera. */
    public const CLICK_TARGETS = [1 => '/resources/rate-per-mile-calculator/', 2 => '/'];
    public const WHY_ES = 'Recibes este correo porque tu empresa figura como transportista en el registro público de FMCSA. Si prefieres no volver a saber de NorthStar, usa el enlace de abajo.';
    public const WHY = 'You are receiving this email because your company is listed as a motor carrier in the public FMCSA registry. If you would rather not hear from NorthStar again, use the link below.';
    /** Textos de partida (inglés: los transportistas están en EE. UU.). Solo afirman lo que NorthStar hace hoy (CLAUDE.md §8.2): Amazon Relay y dry van, MC nuevos, 24/7. */
    public const STARTERS = [
        'owner' => [
            'label' => 'Owner-operators (1 camión)', 'segment' => ['fl' => '1'], 'lang' => 'en',
            'subject' => 'Dispatch for {empresa}: Amazon Relay and dry van',
            'body' => "Hi,\n\nI'm reaching out from NorthStar Freight Solutions, a remote dispatch desk for owner-operators and small fleets. We work with new MC numbers.\n\nWe book Amazon Relay and dry van loads, follow every trip and keep the paperwork organized (rate confirmations, BOLs and PODs), so you can focus on driving.\n\nIf you want to talk about your truck in {estado}, just reply to this email or message us on WhatsApp at {whatsapp}.\n\nNorthStar Freight Solutions",
        ],
        'fleet' => [
            'label' => 'Flotas (6 camiones o más)', 'segment' => ['fl' => '6+'], 'lang' => 'en',
            'subject' => 'A dispatch desk for {empresa}',
            'body' => "Hi,\n\nI'm reaching out from NorthStar Freight Solutions. We run a remote dispatch desk for carriers: Amazon Relay and dry van loads booked, every trip followed and the paperwork kept on file, with dispatch coverage 24/7.\n\nWith {flota} on the road, one desk that handles booking, tracking and back office can take a lot off your team.\n\nIf it makes sense, reply to this email or message us on WhatsApp at {whatsapp} and we can talk about your lanes.\n\nNorthStar Freight Solutions",
        ],
        // fase AW: MC recién emitidos (la FAQ de Relay del sitio ya afirma que se trabaja con autoridades nuevas; no se afirma qué exige Amazon)
        'new_mc' => [
            'label' => 'MC nuevos (3 meses o menos)', 'segment' => ['ag' => 3], 'lang' => 'en',
            'subject' => 'New MC? Let\'s get {empresa} moving',
            'body' => "Hi,\n\nCongratulations on your new authority. Many brokers ask for some months of history before they give loads to a new MC, so the first weeks can be slow.\n\nNorthStar Freight Solutions works with new MC numbers. We dispatch Amazon Relay and dry van loads, follow every trip and keep the paperwork organized, so you can focus on driving.\n\nIf you want to talk about getting your truck moving in {estado}, reply to this email or message us on WhatsApp at {whatsapp}. You can check your numbers with our free rate per mile calculator:\n{link_calculadora}\n\nNorthStar Freight Solutions",
        ],
        'owner_es' => [
            'label' => 'En español: owner-operators', 'segment' => ['fl' => '1', 'lg' => 'es'], 'lang' => 'es',
            'subject' => 'Dispatch para {empresa}: Amazon Relay y dry van',
            'body' => "Hola:\n\nTe escribo de NorthStar Freight Solutions, un equipo de dispatch remoto para owner-operators y flotas pequeñas. Trabajamos con MC nuevos y atendemos en inglés y en español.\n\nReservamos cargas de Amazon Relay y dry van, seguimos cada viaje y llevamos el papeleo en orden (rate confirmations, BOL y POD), para que tú te concentres en manejar.\n\nSi quieres hablar de tu camión en {estado}, responde este correo o escríbenos por WhatsApp al {whatsapp}.\n\nNorthStar Freight Solutions",
        ],
        'fleet_es' => [
            'label' => 'En español: flotas', 'segment' => ['fl' => '2-5', 'lg' => 'es'], 'lang' => 'es',
            'subject' => 'Un equipo de dispatch para {empresa}',
            'body' => "Hola:\n\nTe escribo de NorthStar Freight Solutions. Llevamos el dispatch de transportistas desde un equipo remoto: reservamos cargas de Amazon Relay y dry van, seguimos cada viaje y guardamos el papeleo, con cobertura de dispatch 24/7, en inglés y en español.\n\nCon {flota} en la carretera, un solo equipo que se encargue de reservar, dar seguimiento y del back office te quita mucho trabajo.\n\nSi te parece, responde este correo o escríbenos por WhatsApp al {whatsapp} y hablamos de tus rutas.\n\nNorthStar Freight Solutions",
        ],
    ];

    private static function ok(string $msg, array $extra = []): array
    {
        return ['ok' => true, 'msg' => $msg] + $extra;
    }

    private static function fail(string $msg, array $extra = []): array
    {
        return ['ok' => false, 'msg' => $msg] + $extra;
    }

    // --- Ajustes --------------------------------------------------------------------------------------------------------------------

    /** @return array{cap_hour:int,cooldown_days:int,read_replies:bool} */
    public static function settings(): array
    {
        $s = Settings::get('prospect_mail', []);
        $s = is_array($s) ? $s : [];
        $cap = (int) ($s['cap_hour'] ?? self::DEFAULT_CAP_HOUR);
        $cool = (int) ($s['cooldown_days'] ?? self::DEFAULT_COOLDOWN_DAYS);
        return ['cap_hour' => $cap >= 1 && $cap <= 200 ? $cap : self::DEFAULT_CAP_HOUR, 'cooldown_days' => $cool >= 0 && $cool <= 365 ? $cool : self::DEFAULT_COOLDOWN_DAYS,
            'read_replies' => (bool) ($s['read_replies'] ?? false)];
    }

    public static function saveSettings(array $in, int $userId): array
    {
        $cap = (int) ($in['cap_hour'] ?? 0);
        $cool = (int) ($in['cooldown_days'] ?? -1);
        if ($cap < 1 || $cap > 200) {
            return self::fail('El tope por hora debe estar entre 1 y 200 correos.');
        }
        if ($cool < 0 || $cool > 365) {
            return self::fail('El periodo de espera debe estar entre 0 y 365 días.');
        }
        $read = ($in['read_replies'] ?? '') === '1';
        Settings::set('prospect_mail', ['cap_hour' => $cap, 'cooldown_days' => $cool, 'read_replies' => $read]);
        ActivityLog::record('prospect_mail_settings', $userId, 'settings', null, ['cap_hour' => $cap, 'cooldown_days' => $cool, 'read_replies' => $read]);
        if ($read) {
            ProspectReplies::ensureScheduled();
        }
        return self::ok('Ajustes de envío guardados.');
    }

    /** Lo que falta para poder lanzar cualquier campaña (vacío = nada). @return list<string> */
    public static function blockers(): array
    {
        $b = [];
        if (Templates::address() === '') {
            $b[] = 'Falta la dirección física del remitente (obligatoria en EE. UU., CAN-SPAM). Escríbela en Configuración > Correo.';
        }
        if (!Mailer::canDeliver()) {
            $b[] = 'No hay servidor de correo (SMTP) configurado: los correos no saldrían.';
        }
        return $b;
    }

    // --- Segmento y destinatarios ----------------------------------------------------------------------------------------------------

    /** Segmento validado con lista blanca. @return array{tab:string,us:string,fl:string,ag:int} */
    public static function segment(array $in): array
    {
        $f = Prospects::filters($in);
        $tab = is_string($in['tab'] ?? null) && isset(self::SEG_TABS[$in['tab']]) ? $in['tab'] : '';
        $lg = is_string($in['lg'] ?? null) && isset(self::SEG_LANGS[$in['lg']]) ? $in['lg'] : '';
        return ['tab' => $tab, 'us' => $f['us'], 'fl' => $f['fl'], 'ag' => $f['ag'], 'lg' => $lg];
    }

    public static function segmentLabel(array $seg): string
    {
        $p = [];
        $p[] = $seg['fl'] !== '' ? 'Flota: ' . (Prospects::FLEETS[$seg['fl']] ?? $seg['fl']) : 'Cualquier flota';
        $p[] = $seg['us'] !== '' ? 'Estado: ' . $seg['us'] : 'Todos los estados';
        if ((int) $seg['ag'] > 0) {
            $p[] = 'Autoridad: ' . (Prospects::AGES[(int) $seg['ag']] ?? '');
        }
        if ($seg['tab'] !== '') {
            $p[] = self::SEG_TABS[$seg['tab']];
        }
        if (($seg['lg'] ?? '') !== '') {
            $p[] = self::SEG_LANGS[$seg['lg']];
        }
        return implode(' · ', $p);
    }

    /** Hashes de la lista de supresión para correos (se comparan en PHP: la lista guarda hashes, no direcciones). @return array<string,true> */
    private static function suppressedEmails(): array
    {
        $out = [];
        foreach (Db::all("SELECT value_hash FROM suppression WHERE kind = 'email'") as $r) {
            $out[(string) $r['value_hash']] = true;
        }
        return $out;
    }

    /**
     * Destinatarios de un segmento AHORA, con las exclusiones aplicadas y un correo por dirección.
     * @return array{rows:list<array>,n:int,in_segment:int,with_email:int,excluded:array<string,int>,capped:bool}
     */
    public static function audience(array $seg, int $now, int $limit = self::MAX_RECIPIENTS): array
    {
        $f = ['tab' => $seg['tab'] !== '' ? $seg['tab'] : 'today', 'q' => '', 'us' => $seg['us'], 'fl' => $seg['fl'], 'ag' => (int) $seg['ag'], 'ph' => '', 'now' => ''];
        [$w, $p] = Prospects::whereFor($f, $now, $seg['tab'] !== '');
        if (($seg['lg'] ?? '') === 'es') {   // fase AW
            $w .= " AND p.lang = 'es'";
        } elseif (($seg['lg'] ?? '') === 'en') {
            $w .= " AND (p.lang IS NULL OR p.lang <> 'es')";
        }
        $inSegment = (int) Db::value('SELECT COUNT(*) FROM prospects_fmcsa p WHERE ' . $w, $p);
        $cool = self::settings()['cooldown_days'];
        $rows = Db::all(
            "SELECT p.id, p.legal_name, p.dba, p.email, p.state, p.city, p.fleet_size, p.usdot, p.status, p.do_not_call, p.converted_lead_id, p.email_opt_out_at, p.last_emailed_at
               FROM prospects_fmcsa p WHERE $w AND p.email IS NOT NULL AND p.email <> '' ORDER BY p.authority_granted_at DESC, p.id DESC LIMIT " . (int) (self::MAX_RECIPIENTS * 3),
            $p
        );
        $sup = self::suppressedEmails();
        $ex = ['blocked' => 0, 'unsubscribed' => 0, 'recent' => 0, 'invalid' => 0, 'duplicate' => 0];
        $cut = $cool > 0 ? $now - $cool * 86400 : null;
        // el periodo de espera va también por DIRECCIÓN: dos prospectos con el mismo correo no lo reciben dos veces en campañas distintas
        $recentMails = [];
        if ($cut !== null) {
            foreach (Db::all("SELECT DISTINCT email FROM prospect_campaign_recipients WHERE status = 'sent' AND sent_at > ?", [Clock::at($cut)]) as $x) {
                $recentMails[strtolower((string) $x['email'])] = true;
            }
        }
        $seen = [];
        $ok = [];
        foreach ($rows as $r) {
            $mail = Suppression::normalize('email', (string) $r['email']);
            if ($mail === null) {
                $ex['invalid']++;
                continue;
            }
            if ((int) $r['do_not_call'] === 1 || in_array($r['status'], ['dnc', 'not_interested'], true) || $r['converted_lead_id'] !== null) {
                $ex['blocked']++;
                continue;
            }
            if ($r['email_opt_out_at'] !== null || isset($sup[Suppression::hash('email', $mail)])) {
                $ex['unsubscribed']++;
                continue;
            }
            if ($cut !== null && (isset($recentMails[$mail]) || ($r['last_emailed_at'] !== null && (int) Clock::parse((string) $r['last_emailed_at']) > $cut))) {
                $ex['recent']++;
                continue;
            }
            if (isset($seen[$mail])) {
                $ex['duplicate']++;
                continue;
            }
            $seen[$mail] = true;
            $r['email'] = $mail;
            $ok[] = $r;
        }
        return ['rows' => array_slice($ok, 0, $limit), 'n' => min(count($ok), $limit), 'in_segment' => $inSegment, 'with_email' => count($rows), 'excluded' => $ex, 'capped' => count($ok) > $limit];
    }

    // --- Campañas ------------------------------------------------------------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $c = Db::one('SELECT * FROM prospect_campaigns WHERE id = ? AND deleted_at IS NULL', [$id]);
        if ($c !== null) {
            $c['segment'] = self::segment(Db::decode($c['segment']));
        }
        return $c;
    }

    /** @return list<array> campañas con sus conteos */
    public static function list(): array
    {
        $rows = Db::all('SELECT * FROM prospect_campaigns WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 200');
        $counts = [];
        if ($rows) {
            $ids = array_map(static fn($r) => (int) $r['id'], $rows);
            foreach (Db::all('SELECT campaign_id, status, COUNT(*) n, SUM(opened_at IS NOT NULL) o, SUM(clicked_at IS NOT NULL) k, SUM(replied_at IS NOT NULL) rp FROM prospect_campaign_recipients WHERE campaign_id IN (' . implode(',', $ids) . ') GROUP BY campaign_id, status') as $c) {
                $cid = (int) $c['campaign_id'];
                $counts[$cid][(string) $c['status']] = (int) $c['n'];
                $counts[$cid]['opened'] = ($counts[$cid]['opened'] ?? 0) + (int) $c['o'];
                $counts[$cid]['clicked'] = ($counts[$cid]['clicked'] ?? 0) + (int) $c['k'];
                $counts[$cid]['replied'] = ($counts[$cid]['replied'] ?? 0) + (int) $c['rp'];
            }
        }
        foreach ($rows as &$r) {
            $r['segment'] = self::segment(Db::decode($r['segment']));
            $r['counts'] = ($counts[(int) $r['id']] ?? []) + ['queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'opened' => 0, 'clicked' => 0, 'replied' => 0];
        }
        return $rows;
    }

    /** @return array{queued:int,sent:int,failed:int,skipped:int,opened:int,clicked:int,replied:int,fu_sent:int,fu_queued:int,fu_wait:int} */
    public static function stats(int $id): array
    {
        $s = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'opened' => 0, 'clicked' => 0, 'replied' => 0, 'fu_sent' => 0, 'fu_queued' => 0, 'fu_wait' => 0];
        foreach (Db::all("SELECT status, COUNT(*) n, SUM(opened_at IS NOT NULL) o, SUM(clicked_at IS NOT NULL) k, SUM(replied_at IS NOT NULL) rp, SUM(fu_status = 'sent') fs, SUM(fu_status = 'queued') fq,
                                 SUM(status = 'sent' AND fu_status IS NULL) fw FROM prospect_campaign_recipients WHERE campaign_id = ? GROUP BY status", [$id]) as $r) {
            $s[(string) $r['status']] = (int) $r['n'];
            $s['opened'] += (int) $r['o'];
            $s['clicked'] += (int) $r['k'];
            $s['replied'] += (int) $r['rp'];
            $s['fu_sent'] += (int) $r['fs'];
            $s['fu_queued'] += (int) $r['fq'];
            $s['fu_wait'] += (int) $r['fw'];
        }
        return $s;
    }

    /** @return array{rows:list<array>,total:int,page:int,pages:int} */
    public static function recipients(int $id, string $status, int $page, int $per = 50): array
    {
        $w = 'r.campaign_id = ?';
        $p = [$id];
        if (isset(self::R_STATUS[$status])) {
            $w .= ' AND r.status = ?';
            $p[] = $status;
        } elseif ($status === 'opened') {
            $w .= ' AND r.opened_at IS NOT NULL';
        } elseif ($status === 'clicked') {
            $w .= ' AND r.clicked_at IS NOT NULL';
        } elseif ($status === 'replied') {
            $w .= ' AND r.replied_at IS NOT NULL';
        }
        $total = (int) Db::value("SELECT COUNT(*) FROM prospect_campaign_recipients r WHERE $w", $p);
        $pages = max(1, (int) ceil($total / $per));
        $page = min(max(1, $page), $pages);
        $rows = Db::all(
            "SELECT r.*, pr.legal_name, pr.dba, pr.state, pr.city, pr.fleet_size, pr.usdot FROM prospect_campaign_recipients r JOIN prospects_fmcsa pr ON pr.id = r.prospect_id
              WHERE $w ORDER BY r.id LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per),
            $p
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return array{ok:bool,errors:array<string,string>,values:array} */
    public static function validate(array $in): array
    {
        $v = [
            'name' => trim(is_string($in['name'] ?? null) ? mb_substr($in['name'], 0, 200) : ''),
            'subject' => trim((string) preg_replace('/\s+/', ' ', is_string($in['subject'] ?? null) ? mb_substr($in['subject'], 0, 300) : '')),
            'body' => str_replace("\r\n", "\n", trim(is_string($in['body'] ?? null) ? mb_substr($in['body'], 0, 21000) : '')),
            'segment' => self::segment($in),
            'lang' => ($in['lang'] ?? 'en') === 'es' ? 'es' : 'en',
            'followup_days' => isset(self::FOLLOWUP_DAYS[(int) ($in['followup_days'] ?? 0)]) ? (int) ($in['followup_days'] ?? 0) : 0,
            'followup_subject' => trim((string) preg_replace('/\s+/', ' ', is_string($in['followup_subject'] ?? null) ? mb_substr($in['followup_subject'], 0, 300) : '')),
            'followup_body' => str_replace("\r\n", "\n", trim(is_string($in['followup_body'] ?? null) ? mb_substr($in['followup_body'], 0, 21000) : '')),
        ];
        $err = [];
        if ($v['name'] === '' || mb_strlen($v['name']) > 120) {
            $err['name'] = 'Ponle un nombre de 1 a 120 caracteres (solo lo ves tú).';
        }
        if ($v['subject'] === '' || mb_strlen($v['subject']) > 190) {
            $err['subject'] = 'El asunto debe tener de 1 a 190 caracteres.';
        }
        if (mb_strlen($v['body']) < 20 || mb_strlen($v['body']) > 20000) {
            $err['body'] = 'El mensaje debe tener de 20 a 20.000 caracteres.';
        }
        preg_match_all('/\{[a-z_]{2,30}\}/', $v['subject'] . "\n" . $v['body'], $m);
        $unknown = array_values(array_diff(array_unique($m[0]), array_keys(self::VARS)));
        if ($unknown) {
            $err['body'] = 'Variable desconocida: ' . implode(', ', $unknown) . '. Usa solo las de la lista.';
        }
        if ($v['followup_days'] > 0) {
            if ($v['followup_subject'] === '' || mb_strlen($v['followup_subject']) > 190) {
                $err['followup_subject'] = 'El asunto del segundo correo debe tener de 1 a 190 caracteres.';
            }
            if (mb_strlen($v['followup_body']) < 20 || mb_strlen($v['followup_body']) > 20000) {
                $err['followup_body'] = 'El segundo correo debe tener de 20 a 20.000 caracteres.';
            }
            preg_match_all('/\{[a-z_]{2,30}\}/', $v['followup_subject'] . "\n" . $v['followup_body'], $m2);
            $unk2 = array_values(array_diff(array_unique($m2[0]), array_keys(self::VARS)));
            if ($unk2) {
                $err['followup_body'] = 'Variable desconocida: ' . implode(', ', $unk2) . '. Usa solo las de la lista.';
            }
        }
        return ['ok' => $err === [], 'errors' => $err, 'values' => $v];
    }

    public static function create(array $v, int $userId): int
    {
        $now = Clock::now();
        $id = (int) Db::insert('prospect_campaigns', ['name' => $v['name'], 'segment' => $v['segment'], 'lang' => $v['lang'] ?? 'en', 'subject' => $v['subject'], 'body' => $v['body'], 'status' => 'draft',
            'followup_days' => $v['followup_days'] ?? 0, 'followup_subject' => ($v['followup_subject'] ?? '') !== '' ? $v['followup_subject'] : null, 'followup_body' => ($v['followup_body'] ?? '') !== '' ? $v['followup_body'] : null,
            'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);
        ActivityLog::record('prospect_campaign_create', $userId, 'prospect_campaign', $id, ['segment' => $v['segment']]);
        return $id;
    }

    public static function update(int $id, array $v, int $userId): array
    {
        $c = self::find($id);
        if ($c === null || $c['status'] !== 'draft') {
            return self::fail('Solo se puede editar una campaña en borrador.');
        }
        Db::update('prospect_campaigns', $id, ['name' => $v['name'], 'segment' => $v['segment'], 'lang' => $v['lang'] ?? 'en', 'subject' => $v['subject'], 'body' => $v['body'], 'updated_at' => Clock::now(),
            'followup_days' => $v['followup_days'] ?? 0, 'followup_subject' => ($v['followup_subject'] ?? '') !== '' ? $v['followup_subject'] : null, 'followup_body' => ($v['followup_body'] ?? '') !== '' ? $v['followup_body'] : null]);
        ActivityLog::record('prospect_campaign_update', $userId, 'prospect_campaign', $id, ['segment' => $v['segment']]);
        return self::ok('Borrador guardado.');
    }

    public static function delete(int $id, int $userId): array
    {
        $c = self::find($id);
        if ($c === null || $c['status'] !== 'draft') {
            return self::fail('Solo se borra una campaña en borrador; una ya lanzada se cancela.');
        }
        Db::update('prospect_campaigns', $id, ['deleted_at' => Clock::now()]);
        ActivityLog::record('prospect_campaign_delete', $userId, 'prospect_campaign', $id);
        return self::ok('Borrador eliminado.');
    }

    // --- Render --------------------------------------------------------------------------------------------------------------------

    /** @return array<string,string> variables de un prospecto */
    public static function vars(array $p, string $lang = 'en'): array
    {
        $name = trim((string) ($p['legal_name'] ?? '')) !== '' ? (string) $p['legal_name'] : (string) ($p['dba'] ?? '');
        $n = ($p['fleet_size'] ?? null) !== null && $p['fleet_size'] !== '' ? (int) $p['fleet_size'] : 0;
        $es = $lang === 'es';
        return [
            '{empresa}' => $name !== '' ? Fmt::company($name) : ($es ? 'tu empresa' : 'your company'),
            '{ciudad}' => trim((string) ($p['city'] ?? '')) !== '' ? ucwords(strtolower((string) $p['city'])) : ($es ? 'tu zona' : 'your area'),
            '{estado}' => trim((string) ($p['state'] ?? '')) !== '' ? (string) $p['state'] : ($es ? 'tu estado' : 'your state'),
            '{flota}' => $n > 0 ? $n . ($es ? ($n === 1 ? ' camión' : ' camiones') : ($n === 1 ? ' truck' : ' trucks')) : ($es ? 'tus camiones' : 'your trucks'),
        ];
    }

    /** Motivo del pie según el idioma. */
    public static function why(string $lang): string
    {
        return $lang === 'es' ? self::WHY_ES : self::WHY;
    }

    /**
     * @param int $step 1 = correo de la campaña, 2 = segundo correo (fase AT)
     * @param ?int $rid destinatario: con él, {link_calculadora} y {link_web} pasan por /r/{token} y cuentan el clic (sin él, la vista previa y la prueba llevan el enlace directo)
     * @return array{subject:string,text:string,html:string,unresolved:list<string>}
     */
    public static function render(array $c, array $p, string $unsubUrl, ?string $pixel = null, int $step = 1, ?int $rid = null): array
    {
        $links = [];
        foreach ([1 => '{link_calculadora}', 2 => '{link_web}'] as $t => $var) {
            $links[$var] = $rid !== null ? Unsub::clickUrl($rid, $t) : Templates::SITE . self::CLICK_TARGETS[$t];
        }
        $tpl = $step === 2 ? ['subject' => (string) ($c['followup_subject'] ?? ''), 'body' => (string) ($c['followup_body'] ?? '')] : ['subject' => (string) $c['subject'], 'body' => (string) $c['body']];
        $lang = ($c['lang'] ?? 'en') === 'es' ? 'es' : 'en';
        return Templates::render($tpl, ['first_name' => '', 'state' => $p['state'] ?? ''], $lang, $unsubUrl, $links + self::vars($p, $lang), ['why' => self::why($lang), 'pixel' => $pixel]);
    }

    /** Prospecto de muestra para la vista previa: el primero del segmento o uno ficticio. */
    public static function sample(array $seg, int $now): array
    {
        $a = self::audience($seg, $now, 1);
        return $a['rows'][0] ?? ['id' => 0, 'legal_name' => 'SAMPLE TRUCKING LLC', 'dba' => null, 'email' => 'carrier@example.com', 'state' => $seg['us'] !== '' ? $seg['us'] : 'TX', 'city' => 'DALLAS',
            'fleet_size' => $seg['fl'] === '6+' ? 8 : ($seg['fl'] === '2-5' ? 3 : 1), 'usdot' => '0000000'];
    }

    // --- Prueba y lanzamiento --------------------------------------------------------------------------------------------------------

    /** Envía la campaña renderizada con el primer prospecto del segmento a la dirección del administrador, con «[PRUEBA]» en el asunto. */
    public static function sendTest(int $id, string $to, int $userId): array
    {
        $c = self::find($id);
        if ($c === null) {
            return self::fail('La campaña no existe.');
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return self::fail('Tu usuario no tiene un correo válido para recibir la prueba.');
        }
        $sample = self::sample($c['segment'], Clock::time());
        $steps = (int) ($c['followup_days'] ?? 0) > 0 ? [1, 2] : [1];
        try {
            foreach ($steps as $step) {   // con segundo correo, la prueba manda los dos
                $r = self::render($c, $sample, Url::base() . '/u/PRUEBA', null, $step);
                $status = Mailer::deliver(['to' => $to, 'subject' => '[PRUEBA' . ($step === 2 ? ' · 2.º correo' : '') . '] ' . $r['subject'], 'text' => $r['text'], 'html' => $r['html'], 'from_name' => Sequences::mailSettings()['from_name']]);
                if ($status !== 'sent') {
                    break;
                }
            }
        } catch (\Throwable $t) {
            return self::fail('El servidor de correo rechazó la prueba: ' . mb_substr(Storage::redact($t->getMessage()), 0, 160));
        }
        if ($status !== 'sent') {
            return self::fail('Sin SMTP la prueba solo quedó registrada en el servidor: no llegó a tu correo.');
        }
        Db::update('prospect_campaigns', $id, ['test_sent_at' => Clock::now()]);
        ActivityLog::record('prospect_campaign_test', $userId, 'prospect_campaign', $id);
        return self::ok((count($steps) === 2 ? 'Los dos correos de prueba se enviaron a ' : 'Prueba enviada a ') . $to . '. Revísala y, si está bien, lanza la campaña.');
    }

    /** Lo que impide lanzar ESTA campaña (vacío = se puede). @return list<string> */
    public static function launchBlockers(array $c, ?array $aud = null): array
    {
        $b = self::blockers();
        if ($c['status'] !== 'draft') {
            $b[] = 'La campaña ya se lanzó.';
        }
        $sample = self::sample($c['segment'], Clock::time());
        foreach ((int) ($c['followup_days'] ?? 0) > 0 ? [1, 2] : [1] as $step) {
            $r = self::render($c, $sample, Url::base() . '/u/EJEMPLO', null, $step);
            if ($r['unresolved']) {
                $b[] = ($step === 2 ? 'El segundo correo' : 'El texto') . ' tiene marcadores o variables sin resolver: ' . implode(' ', array_map(static fn($m) => mb_substr($m, 0, 40), array_slice($r['unresolved'], 0, 3)));
            }
        }
        if ($c['test_sent_at'] === null || (int) Clock::parse((string) $c['test_sent_at']) < (int) Clock::parse((string) $c['updated_at'])) {
            $b[] = 'Envíate una prueba después del último cambio.';
        }
        if ($aud !== null && $aud['n'] === 0) {
            $b[] = 'El segmento no tiene ningún destinatario.';
        }
        return $b;
    }

    public static function launch(int $id, int $userId): array
    {
        $c = self::find($id);
        if ($c === null) {
            return self::fail('La campaña no existe.');
        }
        $now = Clock::time();
        $aud = self::audience($c['segment'], $now);
        $b = self::launchBlockers($c, $aud);
        if ($b) {
            return self::fail($b[0]);
        }
        Db::pdo()->beginTransaction();
        try {
            $at = Clock::at($now);
            foreach (array_chunk($aud['rows'], 200) as $chunk) {
                $sql = 'INSERT IGNORE INTO prospect_campaign_recipients (campaign_id, prospect_id, email, status, created_at) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, \'queued\', ?)'));
                $params = [];
                foreach ($chunk as $r) {
                    array_push($params, $id, (int) $r['id'], (string) $r['email'], $at);
                }
                Db::run($sql, $params);
            }
            Db::update('prospect_campaigns', $id, ['status' => 'sending', 'total' => $aud['n'], 'launched_by' => $userId, 'launched_at' => $at, 'paused_reason' => null]);
            Db::pdo()->commit();
        } catch (\Throwable $t) {
            Db::pdo()->rollBack();
            throw $t;
        }
        self::schedule($id, $now);
        if (self::settings()['read_replies']) {
            ProspectReplies::ensureScheduled();
        }
        ActivityLog::record('prospect_campaign_launch', $userId, 'prospect_campaign', $id, ['recipients' => $aud['n'], 'segment' => $c['segment']]);
        $cap = self::settings()['cap_hour'];
        return self::ok('Campaña lanzada: ' . Fmt::num($aud['n']) . ' correos en cola, a un máximo de ' . $cap . ' por hora.');
    }

    private static function schedule(int $id, int $at): void
    {
        Queue::push('prospect_mail_tick', ['campaign_id' => $id], Clock::at($at), 'pmail:' . $id . ':' . $at);
    }

    public static function pause(int $id, int $userId): array
    {
        $c = self::find($id);
        if ($c === null || $c['status'] !== 'sending') {
            return self::fail('Solo se pausa una campaña que se está enviando.');
        }
        Db::update('prospect_campaigns', $id, ['status' => 'paused', 'paused_reason' => 'Pausada a mano']);
        ActivityLog::record('prospect_campaign_pause', $userId, 'prospect_campaign', $id);
        return self::ok('Campaña en pausa. Los correos que faltan esperan a que la reanudes.');
    }

    public static function resume(int $id, int $userId): array
    {
        $c = self::find($id);
        if ($c === null || $c['status'] !== 'paused') {
            return self::fail('Solo se reanuda una campaña en pausa.');
        }
        if ($b = self::blockers()) {
            return self::fail($b[0]);
        }
        Db::update('prospect_campaigns', $id, ['status' => 'sending', 'paused_reason' => null]);
        self::schedule($id, Clock::time());
        ActivityLog::record('prospect_campaign_resume', $userId, 'prospect_campaign', $id);
        return self::ok('Campaña reanudada.');
    }

    public static function cancel(int $id, int $userId): array
    {
        $c = self::find($id);
        if ($c === null || !in_array($c['status'], ['sending', 'paused'], true)) {
            return self::fail('Solo se cancela una campaña que se está enviando o en pausa.');
        }
        Db::run("UPDATE prospect_campaign_recipients SET status = 'skipped', skip_reason = 'campaña cancelada' WHERE campaign_id = ? AND status = 'queued'", [$id]);
        Db::run("UPDATE prospect_campaign_recipients SET fu_status = 'skipped', fu_skip_reason = 'campaña cancelada' WHERE campaign_id = ? AND status = 'sent' AND (fu_status IS NULL OR fu_status = 'queued')", [$id]);
        Db::update('prospect_campaigns', $id, ['status' => 'cancelled', 'finished_at' => Clock::now()]);
        ActivityLog::record('prospect_campaign_cancel', $userId, 'prospect_campaign', $id);
        return self::ok('Campaña cancelada. Los correos que no habían salido ya no se envían.');
    }

    // --- Envío (job prospect_mail_tick) --------------------------------------------------------------------------------------------

    /** Correos a prospectos enviados en la última hora: campañas (1.º y 2.º) y, desde la fase AU, los automáticos tras no contestar (comparten el tope). */
    public static function sentLastHour(int $now): int
    {
        $auto = 0;
        try {
            $auto = (int) Db::value("SELECT COUNT(*) FROM prospect_auto_mails WHERE status = 'sent' AND sent_at > ?", [Clock::at($now - 3600)]);
        } catch (\Throwable) {   // entre el despliegue y la migración 020
        }
        return (int) Db::value("SELECT COUNT(*) FROM prospect_campaign_recipients WHERE status = 'sent' AND sent_at > ?", [Clock::at($now - 3600)])
            + (int) Db::value("SELECT COUNT(*) FROM prospect_campaign_recipients WHERE fu_status = 'sent' AND fu_sent_at > ?", [Clock::at($now - 3600)]) + $auto;
    }

    private static function pauseFor(int $id, string $why): void
    {
        Db::update('prospect_campaigns', $id, ['status' => 'paused', 'paused_reason' => mb_substr($why, 0, 190)]);
        ActivityLog::record('prospect_campaign_autopause', null, 'prospect_campaign', $id, ['why' => mb_substr($why, 0, 190)]);
    }

    /** Por qué ya no se debe escribir a este prospecto (se revisa justo antes de cada envío). */
    private static function skipReason(array $r, array $sup): ?string
    {
        if ($r['p_deleted'] !== null) {
            return 'prospecto eliminado';
        }
        if ((int) $r['do_not_call'] === 1 || in_array($r['p_status'], ['dnc', 'not_interested'], true)) {
            return 'no llamar más o no interesado';
        }
        if ($r['converted_lead_id'] !== null) {
            return 'ya es lead';
        }
        if ($r['email_opt_out_at'] !== null || isset($sup[Suppression::hash('email', (string) $r['email'])])) {
            return 'se dio de baja';
        }
        return null;
    }

    public static function tick(array $payload, array $job = []): void
    {
        $id = (int) ($payload['campaign_id'] ?? 0);
        $c = self::find($id);
        if ($c === null || $c['status'] !== 'sending') {
            return;
        }
        $now = Clock::time();
        if ($b = self::blockers()) {
            self::pauseFor($id, $b[0]);
            return;
        }
        $budget = min(self::BATCH, self::settings()['cap_hour'] - self::sentLastHour($now));
        if ($budget <= 0) {
            self::schedule($id, $now + 300);   // tope de la hora alcanzado: vuelve a mirar en 5 min
            return;
        }
        $step1 = (int) Db::value("SELECT COUNT(*) FROM prospect_campaign_recipients WHERE campaign_id = ? AND status = 'queued'", [$id]) > 0;
        if (!$step1 && (int) ($c['followup_days'] ?? 0) > 0) {
            self::promoteFollowups($c, $now);
        }
        $col = $step1 ? 'status' : 'fu_status';
        $rows = Db::all(
            "SELECT r.id rid, r.email, r.opened_at, r.clicked_at, r.replied_at, p.id, p.legal_name, p.dba, p.state, p.city, p.fleet_size, p.usdot, p.status p_status, p.do_not_call, p.converted_lead_id, p.email_opt_out_at,
                    p.email_replied_at, p.deleted_at p_deleted
               FROM prospect_campaign_recipients r JOIN prospects_fmcsa p ON p.id = r.prospect_id WHERE r.campaign_id = ? AND r.$col = 'queued' ORDER BY r.id LIMIT " . (int) ($budget * 5),
            [$id]
        );
        $sup = self::suppressedEmails();
        $from = Sequences::mailSettings();
        $fails = 0;
        $sentNow = 0;
        foreach ($rows as $r) {
            if ($sentNow >= $budget) {   // los omitidos no gastan el tope de la hora: se leen de más y se corta al llegar al tope de enviados
                break;
            }
            $why = self::skipReason($r, $sup);
            if ($why === null && !$step1 && ($r['opened_at'] !== null || $r['clicked_at'] !== null || $r['replied_at'] !== null || $r['email_replied_at'] !== null)) {
                $why = $r['replied_at'] !== null || $r['email_replied_at'] !== null ? 'respondió' : 'ya abrió el primero';
            }
            if ($why !== null) {
                Db::run($step1 ? "UPDATE prospect_campaign_recipients SET status = 'skipped', skip_reason = ? WHERE id = ?" : "UPDATE prospect_campaign_recipients SET fu_status = 'skipped', fu_skip_reason = ? WHERE id = ?", [$why, (int) $r['rid']]);
                continue;
            }
            $unsub = Unsub::prospectUrl((int) $r['id']);
            $m = self::render($c, $r, $unsub, Unsub::openUrl((int) $r['rid']), $step1 ? 1 : 2, (int) $r['rid']);
            try {
                $status = Mailer::deliver(['to' => (string) $r['email'], 'subject' => $m['subject'], 'text' => $m['text'], 'html' => $m['html'], 'from_name' => $from['from_name'],
                    'reply_to' => $from['reply_to'] !== '' ? $from['reply_to'] : Mailer::to(),
                    'headers' => ['List-Unsubscribe' => '<' . $unsub . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']]);
            } catch (\Throwable $t) {
                $fails++;
                $err = mb_substr(Storage::redact($t->getMessage()), 0, 250);
                Db::run($step1 ? "UPDATE prospect_campaign_recipients SET status = 'failed', error = ? WHERE id = ?" : "UPDATE prospect_campaign_recipients SET fu_status = 'failed', error = ? WHERE id = ?", [$err, (int) $r['rid']]);
                if ($fails >= 3) {
                    self::pauseFor($id, 'El servidor de correo rechazó 3 envíos seguidos. Revisa el SMTP y reanuda.');
                    return;
                }
                continue;
            }
            if ($status !== 'sent') {   // sin SMTP a mitad de envío: no se da por enviado
                self::pauseFor($id, 'No hay servidor de correo (SMTP): nada se envió.');
                return;
            }
            $fails = 0;
            $sentNow++;
            $at = Clock::now();
            Db::run($step1 ? "UPDATE prospect_campaign_recipients SET status = 'sent', sent_at = ?, error = NULL WHERE id = ?" : "UPDATE prospect_campaign_recipients SET fu_status = 'sent', fu_sent_at = ? WHERE id = ?", [$at, (int) $r['rid']]);
            Db::run('UPDATE prospects_fmcsa SET last_emailed_at = ? WHERE id = ?', [$at, (int) $r['id']]);
        }
        $left = (int) Db::value("SELECT COUNT(*) FROM prospect_campaign_recipients WHERE campaign_id = ? AND (status = 'queued' OR fu_status = 'queued')", [$id]);
        if ($left > 0) {
            self::schedule($id, $now + 60);
            return;
        }
        if ((int) ($c['followup_days'] ?? 0) > 0) {   // faltan segundos correos que aún no tocan: vuelve cuando toque el primero
            $next = Db::value("SELECT MIN(sent_at) FROM prospect_campaign_recipients WHERE campaign_id = ? AND status = 'sent' AND fu_status IS NULL", [$id]);
            if ($next !== null) {
                self::schedule($id, max($now + 60, (int) Clock::parse((string) $next) + (int) $c['followup_days'] * 86400));
                return;
            }
        }
        Db::update('prospect_campaigns', $id, ['status' => 'done', 'finished_at' => Clock::now()]);
        ActivityLog::record('prospect_campaign_done', null, 'prospect_campaign', $id, self::stats($id));
    }

    /** Pasa a la cola del segundo correo a quien recibió el primero hace N días o más; quien ya abrió, hizo clic o respondió no lo recibe. */
    private static function promoteFollowups(array $c, int $now): void
    {
        $cut = Clock::at($now - (int) $c['followup_days'] * 86400);
        Db::run("UPDATE prospect_campaign_recipients SET fu_status = 'skipped', fu_skip_reason = IF(replied_at IS NOT NULL, 'respondió', IF(clicked_at IS NOT NULL, 'hizo clic', 'ya abrió el primero'))
                  WHERE campaign_id = ? AND status = 'sent' AND fu_status IS NULL AND sent_at <= ? AND (opened_at IS NOT NULL OR clicked_at IS NOT NULL OR replied_at IS NOT NULL)", [(int) $c['id'], $cut]);
        Db::run("UPDATE prospect_campaign_recipients SET fu_status = 'queued' WHERE campaign_id = ? AND status = 'sent' AND fu_status IS NULL AND sent_at <= ?", [(int) $c['id'], $cut]);
    }

    // --- Baja y aperturas (páginas públicas) ------------------------------------------------------------------------------------------

    /** Baja de un prospecto: deja de recibir correos de campaña para siempre (bandera + lista de supresión) y se le quita de lo que estaba en cola. */
    public static function unsubscribe(int $prospectId, string $via): array
    {
        $p = Db::one('SELECT id, email, email_opt_out_at FROM prospects_fmcsa WHERE id = ?', [$prospectId]);
        if ($p === null) {
            return ['ok' => false, 'already' => false];
        }
        $already = $p['email_opt_out_at'] !== null;
        if (!$already) {
            Db::run('UPDATE prospects_fmcsa SET email_opt_out_at = ? WHERE id = ?', [Clock::now(), $prospectId]);
        }
        Suppression::add('email', (string) $p['email'], Suppression::EMAIL_UNSUB);
        Db::run("UPDATE prospect_campaign_recipients SET status = 'skipped', skip_reason = 'se dio de baja' WHERE prospect_id = ? AND status = 'queued'", [$prospectId]);
        if (!$already) {
            ActivityLog::record('prospect_unsubscribe', null, 'prospect', $prospectId, ['via' => $via]);
        }
        return ['ok' => true, 'already' => $already];
    }

    /** Píxel de apertura: primera apertura y conteo (con tope). */
    public static function recordOpen(int $recipientId): void
    {
        Db::run('UPDATE prospect_campaign_recipients SET opened_at = COALESCE(opened_at, ?), open_count = LEAST(open_count + 1, 60000) WHERE id = ? AND status = \'sent\'', [Clock::now(), $recipientId]);
    }

    /** Clic en un enlace del correo (fase AT): cuenta también como apertura (hay clientes que bloquean el píxel). Devuelve la URL de destino. */
    public static function recordClick(int $recipientId, int $target): string
    {
        $now = Clock::now();
        Db::run('UPDATE prospect_campaign_recipients SET clicked_at = COALESCE(clicked_at, ?), click_count = LEAST(click_count + 1, 60000), opened_at = COALESCE(opened_at, ?) WHERE id = ? AND status = \'sent\'', [$now, $now, $recipientId]);
        return Templates::SITE . (self::CLICK_TARGETS[$target] ?? '/');
    }

    /** Último correo de campaña de cada prospecto (para la lista de Prospectos). @param list<int> $ids @return array<int,array{name:string,sent_at:string,opened:bool}> */
    public static function lastFor(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $out = [];
        try {   // la lista de Prospectos nunca debe romperse por esto (p. ej. en el minuto entre el despliegue y la migración 018)
            foreach (Db::all("SELECT r.prospect_id, r.sent_at, r.opened_at, r.clicked_at, r.replied_at, c.name FROM prospect_campaign_recipients r JOIN prospect_campaigns c ON c.id = r.campaign_id
                               WHERE r.status = 'sent' AND r.prospect_id IN (" . implode(',', $ids) . ') ORDER BY r.sent_at') as $r) {
                $out[(int) $r['prospect_id']] = ['name' => (string) $r['name'], 'sent_at' => (string) $r['sent_at'], 'opened' => $r['opened_at'] !== null, 'clicked' => $r['clicked_at'] !== null, 'replied' => $r['replied_at'] !== null];
            }
        } catch (\Throwable) {
            return [];
        }
        return $out;
    }
}
