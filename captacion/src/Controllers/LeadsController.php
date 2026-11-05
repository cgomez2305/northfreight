<?php
/** /leads (bandeja con filtros), /leads/{id} (ficha), POST /leads/bulk (cambio masivo) y POST /leads/export (CSV). */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;
use Captacion\Services\ActivityLog;
use Captacion\Services\Catalog;
use Captacion\Services\Csv;
use Captacion\Services\Dashboard;
use Captacion\Services\Fmt;
use Captacion\Services\LeadActions;
use Captacion\Services\LeadQuery;
use Captacion\Services\Timeline;

final class LeadsController extends Responder
{
    public static function index(): never
    {
        $user = self::user();
        $f = LeadQuery::filters($_GET);
        $paging = LeadQuery::paging($_GET);
        $list = LeadQuery::list($f, $paging);
        $counts = LeadQuery::tempCounts($f);
        $total = (int) Db::value('SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL');
        self::page('leads', $user, 'leads', [['Leads', null], ['Bandeja', null]], [
            'title' => 'Leads', 'css' => ['leads'], 'f' => $f, 'paging' => $paging, 'list' => $list, 'counts' => $counts, 'facets' => LeadQuery::facets(), 'grandTotal' => $total,
        ]);
    }

    public static function show(int $id): never
    {
        $user = self::user();
        $lead = Lead::find($id);
        if ($lead === null) {
            self::notFound();
        }
        $events = Db::all('SELECT type, payload, user_id, created_at FROM lead_events WHERE lead_id = ? ORDER BY created_at DESC, id DESC LIMIT 300', [$id]);
        $notes = Db::all('SELECT n.id, n.body, n.created_at, n.updated_at, n.user_id, u.name AS author FROM lead_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.lead_id = ? AND n.deleted_at IS NULL ORDER BY n.created_at DESC, n.id DESC', [$id]);
        $allNotes = Db::all('SELECT id, body FROM lead_notes WHERE lead_id = ?', [$id]);
        $tasks = Db::all('SELECT id, title, due_at, priority, done_at FROM tasks WHERE lead_id = ? AND deleted_at IS NULL ORDER BY (done_at IS NOT NULL), due_at IS NULL, due_at ASC, id DESC LIMIT 50', [$id]);
        $messages = Db::all("SELECT channel, direction, body, created_at FROM messages WHERE lead_id = ? AND channel <> 'call' ORDER BY created_at DESC LIMIT 100", [$id]);
        $calls = Db::all("SELECT status, duration_sec, created_at FROM messages WHERE lead_id = ? AND channel = 'call' ORDER BY created_at DESC LIMIT 5", [$id]);
        $consents = Db::all('SELECT id, channel, consent_text, consent_version, granted_at, revoked_at, revoked_via FROM consents WHERE lead_id = ? ORDER BY granted_at DESC, id DESC', [$id]);
        $appts = Db::all("SELECT starts_at, status FROM appointments WHERE lead_id = ? AND deleted_at IS NULL AND starts_at >= ? ORDER BY starts_at ASC LIMIT 3", [$id, Clock::shift(-3600)]);
        $users = [];
        foreach (Db::all('SELECT id, name FROM users') as $u) {
            $users[(int) $u['id']] = (string) $u['name'];
        }
        $noteText = [];
        foreach ($allNotes as $n) {
            $noteText[(int) $n['id']] = (string) $n['body'];
        }
        $dupes = [];
        if ($lead['phone_e164'] !== null || $lead['email'] !== null) {
            $dupes = Db::all('SELECT id, first_name, last_name, stage, created_at FROM leads WHERE deleted_at IS NULL AND id <> ? AND ((phone_e164 IS NOT NULL AND phone_e164 = ?) OR (email IS NOT NULL AND email = ?)) ORDER BY id DESC LIMIT 5', [$id, $lead['phone_e164'], $lead['email']]);
        }
        $name = Fmt::name($lead);
        $stageOptions = LeadActions::stageOptions($lead);
        $needsOverride = [];
        foreach (array_keys($stageOptions) as $s) {
            $needsOverride[$s] = LeadActions::needsOverride($lead, (string) $s);
        }
        self::page('lead', $user, 'leads', [['Leads', '/leads'], [$name, null]], [
            'title' => $name, 'css' => ['charts', 'lead'], 'hideTabbar' => true, 'lead' => $lead, 'timeline' => Timeline::build($lead, $events, $messages, $noteText, $users), 'notes' => $notes, 'tasks' => $tasks, 'calls' => $calls,
            'consents' => $consents, 'appts' => $appts, 'users' => $users, 'dupes' => $dupes, 'stageOptions' => $stageOptions,
            'needsOverride' => $needsOverride,
        ]);
    }

    /** POST /leads/bulk: cambio masivo de etapa (contacted | qualified | lost) o exportacion de la seleccion. */
    public static function bulk(): never
    {
        $user = self::user();
        $back = self::backUrl();
        self::requirePost($back);
        $ids = is_array($_POST['ids'] ?? null) ? array_map('intval', $_POST['ids']) : [];
        $act = is_string($_POST['do'] ?? null) ? $_POST['do'] : '';
        if ($act === 'export') {
            self::exportResponse($user, null, $ids, 'seleccion');
        }
        $r = LeadActions::bulkStage($ids, (string) ($_POST['stage'] ?? ''), ['reason' => (string) ($_POST['reason'] ?? ''), 'note' => (string) ($_POST['note'] ?? '')], (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect($back);
    }

    /** POST /leads/export: CSV de los leads que cumplen los filtros (qs) o de una seleccion (ids). */
    public static function export(): never
    {
        $user = self::user();
        self::requirePost('/leads');
        $ids = is_array($_POST['ids'] ?? null) ? array_map('intval', $_POST['ids']) : null;
        $scope = is_string($_POST['scope'] ?? null) ? $_POST['scope'] : 'filters';
        if ($scope === 'period') {
            $period = is_string($_POST['period'] ?? null) && isset(Dashboard::PERIODS[$_POST['period']]) ? $_POST['period'] : 'today';
            [$from, $to] = Dashboard::window($period);
            $rows = Db::all(
                "SELECT l.id, l.created_at, l.first_name, l.last_name, l.profile, l.phone_e164, l.email, l.state, l.language, l.equipment_types, l.truck_count, l.years_experience, l.usdot,
                        l.score, l.temperature, l.stage, l.lost_reason, l.source, l.landing_page, l.first_contact_at
                   FROM leads l WHERE l.deleted_at IS NULL AND l.created_at >= ? AND l.created_at <= ? ORDER BY l.created_at DESC, l.id DESC LIMIT 10000",
                [Fmt::utc($from), Fmt::utc($to)]
            );
            self::sendCsv($user, $rows, 'periodo-' . $period, ['scope' => 'period', 'period' => $period]);
        }
        parse_str(is_string($_POST['qs'] ?? null) ? (string) $_POST['qs'] : '', $q);
        self::exportResponse($user, LeadQuery::filters($q), $scope === 'selection' ? $ids : null, $scope === 'selection' ? 'seleccion' : 'filtro');
    }

    private static function exportResponse(array $user, ?array $f, ?array $ids, string $tag): never
    {
        $rows = LeadQuery::exportRows($f, $ids);
        self::sendCsv($user, $rows, $tag, ['scope' => $ids !== null ? 'selection' : 'filters', 'filters' => $f]);
    }

    private static function sendCsv(array $user, array $rows, string $tag, array $meta): never
    {
        ActivityLog::record('leads_export', (int) $user['id'], 'lead', null, ['rows' => count($rows)] + $meta);
        self::download('leads-' . $tag . '-' . gmdate('Ymd-His') . '.csv', 'text/csv; charset=utf-8', Csv::leads($rows));
    }

    /** Vuelta a la bandeja con los mismos filtros (parametro `back`, solo rutas internas /leads...). */
    private static function backUrl(): string
    {
        $b = is_string($_POST['back'] ?? null) ? $_POST['back'] : '/leads';
        return preg_match('#^/leads(\?[A-Za-z0-9_%=&.\-+]{0,600})?$#', $b) === 1 ? $b : '/leads';
    }
}
