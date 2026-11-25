<?php
/**
 * Prospectos FMCSA (outbound, doc §7 y §9.3): lista de llamadas del dia, resultado con un clic, historial, conversion a lead y «no llamar mas» permanente.
 * Las llamadas son MANUALES (nada de marcadores, SMS ni correo en frio desde esta fase). Estados: to_call · no_answer (hasta 3 intentos seguidos) · no_answer_closed
 * (el 3.º sin respuesta; se reabre a mano) · talked · interested · not_interested · dnc (permanente: guarda tambien el telefono en dnc_numbers).
 * Cada accion deja activity_log; nada de datos personales en las URLs (solo ids).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class Prospects
{
    public const STATUSES = [
        'to_call' => 'Por llamar', 'no_answer' => 'Sin respuesta', 'no_answer_closed' => 'Sin respuesta (cerrada)', 'talked' => 'Conversó', 'interested' => 'Interesado',
        'not_interested' => 'No interesado', 'dnc' => 'No llamar más',
    ];
    /** Resultados que se registran con un clic. Fase AU: «Dejé mensaje» (buzón de voz: cuenta como intento, igual que «Sin respuesta») y «Número equivocado» (cierra). */
    public const RESULTS = ['no_answer' => 'Sin respuesta', 'voicemail' => 'Dejé mensaje', 'talked' => 'Conversó', 'interested' => 'Interesado', 'not_interested' => 'No interesado', 'wrong_number' => 'Número equivocado', 'dnc' => 'No llamar más'];
    /** Resultados sin conversación que cuentan como intento (y pueden disparar el correo automático). */
    public const MISSED = ['no_answer', 'voicemail'];
    /** Resultados con conversación (meta diaria). */
    public const SPOKE = ['talked', 'interested', 'not_interested', 'dnc'];
    /** Calificación rápida en la llamada (columna qual). */
    public const QUAL_EQUIPMENT = ['dry-van' => 'Dry van', 'reefer' => 'Reefer', 'flatbed' => 'Flatbed', 'box-truck' => 'Box truck', 'hotshot' => 'Hotshot', 'power-only' => 'Power only', 'step-deck' => 'Step deck', 'other' => 'Otro'];
    public const YESNO = ['yes' => 'Sí', 'no' => 'No'];
    public const BEST = ['morning' => 'Mañana', 'afternoon' => 'Tarde', 'evening' => 'Noche'];
    public const QUAL_LABELS = ['contact_name' => 'Contacto', 'email' => 'Correo dado por teléfono', 'equipment' => 'Equipo', 'relay' => 'Cuenta de Amazon Relay', 'dispatcher' => 'Ya tiene dispatcher', 'best_time' => 'Mejor horario'];
    /** Pestañas (contadores). 'no_answer' agrupa abiertas y cerradas. */
    public const TABS = ['today' => 'Lista de hoy', 'to_call' => 'Por llamar', 'no_answer' => 'Sin respuesta', 'talked' => 'Conversó', 'interested' => 'Interesado', 'not_interested' => 'No interesado', 'dnc' => 'No llamar más'];
    public const RESULT_ICONS = ['no_answer' => 'phone', 'voicemail' => 'inbox', 'talked' => 'chat', 'interested' => 'star', 'not_interested' => 'x', 'wrong_number' => 'alert', 'dnc' => 'shield'];
    /** Origen del prospecto (columna source). */
    public const SOURCES = ['socrata' => 'FMCSA · autoridades nuevas', 'manual' => 'Añadido a mano', 'csv' => 'Importado de CSV', 'demo' => 'Demostración'];
    /** Intentos sin respuesta por defecto antes de cerrar (fase AW: configurable de 3 a 6 en Configuración > Prospectos, `maxAttempts()`). */
    public const MAX_ATTEMPTS = 3;
    /** Fase AW: idioma que prefiere el transportista (columna lang; NULL = sin saber). */
    public const LANGS = ['en' => 'Inglés', 'es' => 'Español'];
    /** Fase AW: espaciado de los intentos sin respuesta, en días hábiles tras el intento n (el último valor se repite). */
    public const SPACING = [
        'next_day' => ['Al siguiente día hábil', [1]],
        'spread' => ['A los 2, 3 y 5 días hábiles (recomendado)', [2, 3, 5]],
        'wide' => ['A los 3, 5 y 7 días hábiles', [3, 5, 7]],
    ];
    public const PER = [25, 50, 100];
    public const FLEETS = ['1' => '1 unidad', '2-5' => '2 a 5', '6+' => '6 o más'];
    public const AGES = [3 => '3 meses o menos', 6 => '6 meses o menos', 12 => '12 meses o menos'];

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'msg' => $msg];
    }

    private static function ok(string $msg, array $extra = []): array
    {
        return ['ok' => true, 'msg' => $msg] + $extra;
    }

    public static function find(int $id): ?array
    {
        $r = Db::one('SELECT * FROM prospects_fmcsa WHERE id = ? AND deleted_at IS NULL', [$id]);
        if ($r === null) {
            return null;
        }
        $r['cargo'] = Db::decode($r['cargo']);
        $r['fmcsa_data'] = Db::decode($r['fmcsa_data']);
        $r['qual'] = Db::decode($r['qual'] ?? null);
        return $r;
    }

    // --- Consultas ------------------------------------------------------------------------------------------------------

    /** Filtros de la URL, validados con lista blanca. @return array{tab:string,q:string,us:string,fl:string,ag:int,ph:string} */
    public static function filters(array $in): array
    {
        $s = static fn(string $k, int $max = 80): string => is_string($in[$k] ?? null) ? mb_substr(trim($in[$k]), 0, $max) : '';
        $tab = $s('tab', 20);
        $us = strtoupper($s('us', 2));
        $ag = (int) $s('ag', 3);
        return [
            'tab' => isset(self::TABS[$tab]) ? $tab : 'today', 'q' => $s('q', 100), 'us' => isset(UsStates::NAMES[$us]) ? $us : '', 'fl' => isset(self::FLEETS[$s('fl', 3)]) ? $s('fl', 3) : '',
            'ag' => isset(self::AGES[$ag]) ? $ag : 0, 'ph' => $s('ph', 4) === 'mail' ? 'mail' : '', 'now' => $s('now', 1) === '1' ? '1' : '',
        ];
    }

    /**
     * Condicion de la «Lista de hoy»: por llamar + seguimientos que tocan HOY (día ET, el mismo corte que la campana «Seguimientos de hoy»): reintentos sin respuesta y,
     * desde la fase AS, también «Conversó» e «Interesado» con fecha de seguimiento (antes solo los avisaba la campana y nunca aparecían en la lista), y desde la AT
     * quien respondió a un correo de campaña y aún no se ha llamado (van los primeros).
     * Nunca prospectos de «no llamar» ni los ya convertidos en lead.
     */
    private static function todayClause(int $now): array
    {
        $end = self::etToUtc(self::etDay($now), true) ?? Clock::at($now);
        return ["(p.status = 'to_call' OR (p.status = 'no_answer' AND (p.next_call_at IS NULL OR p.next_call_at <= ?)) OR (p.status IN ('talked', 'interested') AND p.next_call_at IS NOT NULL AND p.next_call_at <= ?)
            OR (p.email_replied_at IS NOT NULL AND (p.last_call_at IS NULL OR p.last_call_at < p.email_replied_at) AND p.status NOT IN ('dnc', 'not_interested'))) AND p.converted_lead_id IS NULL", [$end, $end]];
    }

    /** Estados (EE. UU.) con prospectos y cuántos hay en cada uno, para el filtro (antes la lista traía los 51 aunque no hubiera nadie). @return array<string,int> */
    public static function stateFacets(): array
    {
        $out = [];
        foreach (Db::all("SELECT state, COUNT(*) AS n FROM prospects_fmcsa WHERE deleted_at IS NULL AND state IS NOT NULL AND state <> '' GROUP BY state ORDER BY state") as $r) {
            $out[(string) $r['state']] = (int) $r['n'];
        }
        return $out;
    }

    /** WHERE de la lista (alias p) para otros servicios: los correos en lote a prospectos usan los mismos filtros. @return array{0:string,1:list<mixed>} */
    public static function whereFor(array $f, int $now, bool $withTab = true): array
    {
        return self::where($f, $now, $withTab);
    }

    /** @return array{0:string,1:list<mixed>} */
    private static function where(array $f, int $now, bool $withTab = true): array
    {
        $w = ['p.deleted_at IS NULL'];
        $params = [];
        if ($withTab) {
            switch ($f['tab']) {
                case 'today':
                    [$sql, $p] = self::todayClause($now);
                    $w[] = $sql;
                    $params = array_merge($params, $p);
                    $w[] = 'p.do_not_call = 0';
                    break;
                case 'no_answer':
                    $w[] = "p.status IN ('no_answer', 'no_answer_closed')";
                    break;
                default:
                    $w[] = 'p.status = ?';
                    $params[] = $f['tab'];
            }
        }
        if ($f['us'] !== '') {
            $w[] = 'p.state = ?';
            $params[] = $f['us'];
        }
        if ($f['fl'] === '1') {
            $w[] = 'p.fleet_size = 1';
        } elseif ($f['fl'] === '2-5') {
            $w[] = 'p.fleet_size BETWEEN 2 AND 5';
        } elseif ($f['fl'] === '6+') {
            $w[] = 'p.fleet_size >= 6';
        }
        if ($f['ag'] > 0) {
            $w[] = 'p.authority_granted_at >= ?';
            $params[] = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('America/New_York'))->modify('-' . $f['ag'] . ' months')->format('Y-m-d');
        }
        if ($f['ph'] === 'mail') {
            $w[] = 'p.email IS NOT NULL';
        }
        if (($f['now'] ?? '') === '1') {   // solo los que se pueden llamar AHORA: estado en horario (8 a. m. a 9 p. m. local en todas sus zonas) y con teléfono
            $ok = self::callableStates($now);
            $w[] = $ok ? 'p.phone IS NOT NULL AND p.state IN (' . implode(',', array_fill(0, count($ok), '?')) . ')' : '1 = 0';
            $params = array_merge($params, $ok);
        }
        if ($f['q'] !== '') {
            $q = $f['q'];
            $digits = preg_replace('/\D/', '', $q) ?? '';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $parts = ['p.legal_name LIKE ?', 'p.dba LIKE ?', 'p.city LIKE ?', 'p.email LIKE ?'];
            $params = array_merge($params, [$like, $like, $like, $like]);
            if (strlen($digits) >= 3) {
                $parts[] = 'p.usdot LIKE ?';
                $parts[] = 'p.mc_number LIKE ?';
                $parts[] = 'p.phone LIKE ?';
                $d = '%' . $digits . '%';
                $params = array_merge($params, [$d, $d, $d]);
            }
            $w[] = '(' . implode(' OR ', $parts) . ')';
        }
        return [implode(' AND ', $w), $params];
    }

    /** Conteos por pestaña respetando el resto de filtros. @return array<string,int> */
    public static function counts(array $f, int $now): array
    {
        $out = [];
        foreach (array_keys(self::TABS) as $tab) {
            [$sql, $p] = self::where(['tab' => $tab] + $f, $now);
            $out[$tab] = (int) Db::value("SELECT COUNT(*) FROM prospects_fmcsa p WHERE $sql", $p);
        }
        return $out;
    }

    /**
     * Pagina de prospectos. Orden: autoridad mas reciente primero y, a igual fecha, dato mas completo primero (en la «Lista de hoy» los reintentos vencidos van al principio).
     * @return array{rows:list<array>,total:int,pages:int,page:int}
     */
    public static function page(array $f, int $now, int $page, int $per): array
    {
        [$sql, $p] = self::where($f, $now);
        $total = (int) Db::value("SELECT COUNT(*) FROM prospects_fmcsa p WHERE $sql", $p);
        $pages = max(1, (int) ceil($total / $per));
        $page = max(1, min($page, $pages));
        [$sel, $sp, $order, $op] = self::ordering($f['tab'], $now);
        $rows = Db::all("SELECT p.*, $sel FROM prospects_fmcsa p WHERE $sql ORDER BY $order LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per), array_merge($sp, $p, $op));
        foreach ($rows as &$r) {
            $r['cargo'] = Db::decode($r['cargo']);
            $r['qual'] = Db::decode($r['qual'] ?? null);
        }
        unset($r);
        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * Fase AU: columnas de prioridad y orden de la lista. En las pestañas por llamar: primero los que se pueden llamar YA (hora local permitida y con teléfono), luego
     * por PRIORIDAD (ver prioritySql) y, a igual prioridad, autoridad más reciente y dato más completo. «Conversó» e «Interesado»: lo último llamado primero.
     * @return array{0:string,1:list<mixed>,2:string,3:list<mixed>} [select, params del select, order by, params del order]
     */
    private static function ordering(string $tab, int $now): array
    {
        [$prio, $pp] = self::prioritySql($now);
        $sel = "$prio AS prio, " . self::REPLIED_NEW . ' AS replied_new, ' . self::CLICKED_NEW . ' AS clicked_new';
        $order = 'p.authority_granted_at DESC, p.completeness DESC, p.id ASC';
        $op = [];
        if (in_array($tab, ['talked', 'interested'], true)) {
            $order = 'p.last_call_at DESC, p.id DESC';
        } elseif (in_array($tab, ['today', 'to_call', 'no_answer'], true)) {
            $order = 'prio DESC, ' . $order;
            if (($ok = self::callableStates($now)) !== []) {
                $order = '(p.phone IS NOT NULL AND p.state IN (' . implode(',', array_fill(0, count($ok), '?')) . ')) DESC, ' . $order;
                $op = $ok;
            }
        }
        return [$sel, $pp, $order, $op];
    }

    private const REPLIED_NEW = '(p.email_replied_at IS NOT NULL AND (p.last_call_at IS NULL OR p.last_call_at < p.email_replied_at))';
    private const CLICKED_NEW = 'EXISTS(SELECT 1 FROM prospect_campaign_recipients pcr WHERE pcr.prospect_id = p.id AND pcr.clicked_at IS NOT NULL AND (p.last_call_at IS NULL OR pcr.clicked_at > p.last_call_at))';
    /** Umbrales de la etiqueta de prioridad. */
    public const PRIO_HIGH = 45;
    public const PRIO_MID = 25;

    /**
     * Prioridad de llamada (fase AU), de mayor a menor peso: respondió a un correo y aún no se le llamó (+50), hizo clic en un correo después de la última llamada (+30),
     * pidió que lo llamaran a una hora que ya llegó (+25), seguimiento de «Conversó» o «Interesado» que toca (+20), flota de 1 a 5 (+12) o de 6 a 10 (+8), autoridad de
     * 30 días o menos (+15), 90 (+10) o 180 (+5), carga general declarada (encaja con dry van y Relay, +10), tiene correo (+3) y −4 por cada intento sin respuesta.
     * Es un orden de trabajo, no una predicción: no usa nada que FMCSA no publique o que el equipo no haya registrado.
     * @return array{0:string,1:list<mixed>}
     */
    public static function prioritySql(int $now): array
    {
        $day = static fn(int $d): string => (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('America/New_York'))->modify('-' . $d . ' days')->format('Y-m-d');
        $at = Clock::at($now);
        $sql = '(50 * ' . self::REPLIED_NEW . ' + 30 * ' . self::CLICKED_NEW . "
            + 25 * (p.callback_exact = 1 AND p.next_call_at IS NOT NULL AND p.next_call_at <= ?)
            + 20 * (p.status IN ('talked', 'interested') AND p.next_call_at IS NOT NULL AND p.next_call_at <= ?)
            + CASE WHEN p.fleet_size BETWEEN 1 AND 5 THEN 12 WHEN p.fleet_size BETWEEN 6 AND 10 THEN 8 ELSE 0 END
            + CASE WHEN p.authority_granted_at >= ? THEN 15 WHEN p.authority_granted_at >= ? THEN 10 WHEN p.authority_granted_at >= ? THEN 5 ELSE 0 END
            + 10 * COALESCE(JSON_CONTAINS(p.cargo, '\"genfreight\"'), 0) + 3 * (p.email IS NOT NULL) - 4 * p.attempts)";
        return [$sql, [$at, $at, $day(30), $day(90), $day(180)]];
    }

    /** Etiqueta y motivos de la prioridad de una fila (la misma regla que prioritySql, para mostrarla). @return array{level:string,label:string,why:list<string>} */
    public static function priorityInfo(array $r, int $now): array
    {
        $why = [];
        if (!empty($r['replied_new'])) {
            $why[] = 'Respondió a un correo';
        }
        if (!empty($r['clicked_new'])) {
            $why[] = 'Abrió un enlace del correo';
        }
        $due = $r['next_call_at'] !== null && (int) Clock::parse((string) $r['next_call_at']) <= $now;
        if ((int) ($r['callback_exact'] ?? 0) === 1 && $due) {
            $why[] = 'Pidió que lo llamaran a esta hora';
        } elseif ($due && in_array($r['status'], ['talked', 'interested'], true)) {
            $why[] = 'Seguimiento que toca hoy';
        }
        $fl = $r['fleet_size'] !== null ? (int) $r['fleet_size'] : 0;
        if ($fl >= 1 && $fl <= 10) {
            $why[] = $fl <= 5 ? 'Flota de 1 a 5' : 'Flota de 6 a 10';
        }
        if ($r['authority_granted_at'] !== null) {
            $days = (int) floor(($now - (int) strtotime($r['authority_granted_at'] . ' 00:00:00 UTC')) / 86400);
            if ($days <= 180) {
                $why[] = 'Autoridad de ' . max(0, $days) . ($days === 1 ? ' día' : ' días');
            }
        }
        if (in_array('genfreight', (array) ($r['cargo'] ?? []), true)) {
            $why[] = 'Carga general (encaja con dry van)';
        }
        if ((int) $r['attempts'] > 0) {
            $why[] = (int) $r['attempts'] . ((int) $r['attempts'] === 1 ? ' intento' : ' intentos') . ' sin respuesta';
        }
        $p = (int) ($r['prio'] ?? 0);
        $level = $p >= self::PRIO_HIGH ? 'high' : ($p >= self::PRIO_MID ? 'mid' : 'low');
        return ['level' => $level, 'label' => ['high' => 'Prioridad alta', 'mid' => 'Prioridad media', 'low' => 'Prioridad baja'][$level], 'why' => $why];
    }

    /**
     * Modo llamada: el siguiente prospecto de la «Lista de hoy» que se puede llamar AHORA, por prioridad, sin los que se saltaron en esta sesión.
     * @param list<int> $skip @return array{row:?array,left:int}
     */
    public static function nextForCall(int $now, array $skip): array
    {
        $f = self::filters(['tab' => 'today', 'now' => '1']);
        [$sql, $p] = self::where($f, $now);
        $skip = array_values(array_unique(array_filter(array_map('intval', $skip))));
        if ($skip) {
            $sql .= ' AND p.id NOT IN (' . implode(',', $skip) . ')';
        }
        [$sel, $sp, $order, $op] = self::ordering('today', $now);
        $row = Db::one("SELECT p.*, $sel FROM prospects_fmcsa p WHERE $sql ORDER BY $order LIMIT 1", array_merge($sp, $p, $op));
        $left = (int) Db::value("SELECT COUNT(*) FROM prospects_fmcsa p WHERE $sql", $p);
        if ($row !== null) {
            $row['cargo'] = Db::decode($row['cargo']);
            $row['qual'] = Db::decode($row['qual'] ?? null);
        }
        return ['row' => $row, 'left' => $left];
    }

    /** Llamadas de hoy (día de Nueva York), de todo el equipo y del usuario. @return array{calls:int,spoke:int,interested:int,converted:int,mine:int,goal:int} */
    public static function todayStats(int $now, int $userId): array
    {
        $start = self::etToUtc(self::etDay($now)) ?? Clock::at($now - 86400);
        $out = ['calls' => 0, 'spoke' => 0, 'interested' => 0, 'converted' => 0, 'mine' => 0, 'goal' => (int) ProspectConfig::get()['daily_goal']];
        foreach (Db::all('SELECT result, user_id, COUNT(*) AS n FROM prospect_calls WHERE created_at >= ? GROUP BY result, user_id', [$start]) as $r) {
            $n = (int) $r['n'];
            if (isset(self::RESULTS[$r['result']])) {
                $out['calls'] += $n;
                if ((int) $r['user_id'] === $userId) {
                    $out['mine'] += $n;
                }
            }
            if (in_array($r['result'], self::SPOKE, true)) {
                $out['spoke'] += $n;
            }
            if ($r['result'] === 'interested') {
                $out['interested'] += $n;
            }
            if ($r['result'] === 'converted') {
                $out['converted'] += $n;
            }
        }
        return $out;
    }

    /** Cuántos de la pestaña actual (con sus filtros) se pueden llamar ahora mismo. */
    public static function callableCount(array $f, int $now): int
    {
        [$sql, $p] = self::where(['now' => '1'] + $f, $now);
        return (int) Db::value("SELECT COUNT(*) FROM prospects_fmcsa p WHERE $sql", $p);
    }

    /** Estados (EE. UU.) donde AHORA es hora permitida para llamar en todas sus zonas. @return list<string> */
    public static function callableStates(int $now): array
    {
        $out = [];
        foreach (array_keys(UsTimeZones::ZONES) as $st) {
            if (UsTimeZones::check($st, $now)['allowed']) {
                $out[] = $st;
            }
        }
        return $out;
    }

    /** Historial de llamadas de varios prospectos: id => lista (mas reciente primero). */
    public static function historyFor(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Db::all("SELECT c.*, u.name AS user_name FROM prospect_calls c LEFT JOIN users u ON u.id = c.user_id WHERE c.prospect_id IN ($in) ORDER BY c.id DESC", $ids) as $r) {
            $out[(int) $r['prospect_id']][] = $r;
        }
        return $out;
    }

    /** Resumen para el pie de la pantalla y el panel de estado. */
    public static function summary(int $now): array
    {
        $listed = self::etDay($now);
        return [
            'total' => (int) Db::value('SELECT COUNT(*) FROM prospects_fmcsa WHERE deleted_at IS NULL'),
            'new_today' => (int) Db::value('SELECT COUNT(*) FROM prospects_fmcsa WHERE listed_on = ?', [$listed]),
            'converted' => (int) Db::value('SELECT COUNT(*) FROM prospects_fmcsa WHERE converted_lead_id IS NOT NULL'),
            'dnc' => (int) Db::value('SELECT COUNT(*) FROM dnc_numbers'),
        ];
    }

    /**
     * Fase AM: 'Y-m-d' o 'Y-m-d\TH:i' escritos en ET (America/New_York, SIEMPRE: las reglas de llamada son por ET) a 'Y-m-d H:i:s' UTC.
     * Antes se usaba Fmt::fromEtInput, que interpreta en la zona de visualizacion de Configuracion: si no era ET, el dia y las 9:00 se corrian.
     */
    public static function etToUtc(string $v, bool $endOfDay = false): ?string
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?$/', $v, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $time = isset($m[4]) ? $m[4] . ':' . $m[5] . ':00' : ($endOfDay ? '23:59:59' : '00:00:00');
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $time, new \DateTimeZone('America/New_York'));
        return $d === false ? null : gmdate('Y-m-d H:i:s', $d->getTimestamp());
    }

    public static function etDay(int $ts): string
    {
        return ProspectImport::etDate($ts);
    }

    /** Seguimientos de hoy o vencidos (campana). @return array{n:int,max_id:int} */
    public static function followupsDue(int $now): array
    {
        $end = self::etToUtc(self::etDay($now), true) ?? Clock::at($now);
        $r = Db::one("SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS m FROM prospects_fmcsa WHERE deleted_at IS NULL AND do_not_call = 0 AND converted_lead_id IS NULL
            AND status IN ('no_answer', 'talked', 'interested') AND next_call_at IS NOT NULL AND next_call_at <= ?", [$end]);
        return ['n' => (int) ($r['n'] ?? 0), 'max_id' => (int) ($r['m'] ?? 0)];
    }

    // --- Acciones -------------------------------------------------------------------------------------------------------

    /** Siguiente dia habil a las 9:00 a. m. ET (UTC). */
    /** Fase AW: intentos sin respuesta antes de cerrar (Configuración > Prospectos > Cadencia de llamadas). */
    public static function maxAttempts(): int
    {
        return ProspectConfig::get()['max_attempts'];
    }

    /** Fase AW: cuándo vuelve a la lista tras el intento n sin respuesta (9:00 a. m. ET del día hábil que toque según el espaciado configurado). */
    public static function retryAt(int $now, int $attempt): string
    {
        $days = self::SPACING[ProspectConfig::get()['retry_spacing']][1] ?? [1];
        $n = (int) ($days[max(0, min($attempt, count($days)) - 1)] ?? 1);
        $d = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('America/New_York'));
        for ($i = 0; $i < $n; $i++) {
            $d = $d->modify('+1 day');
            while (in_array((int) $d->format('N'), [6, 7], true)) {
                $d = $d->modify('+1 day');
            }
        }
        return self::etToUtc($d->format('Y-m-d') . 'T09:00') ?? Clock::at($now + 86400 * $n);
    }

    /** «Vuelve a la lista el viernes 27.» (fecha ET del siguiente intento, sea la del espaciado o la elegida a mano). */
    private static function retryLabel(string $when): string
    {
        $d = $when !== '' ? Fmt::et($when) : null;
        return $d === null ? 'Vuelve a la lista más adelante.' : 'Vuelve a la lista el ' . mb_strtolower(Fmt::weekdayLong((int) $d->format('N'))) . ' ' . $d->format('j') . '.';
    }

    public static function nextBusinessDay(int $now): string
    {
        $d = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('America/New_York'))->modify('+1 day');
        while (in_array((int) $d->format('N'), [6, 7], true)) {
            $d = $d->modify('+1 day');
        }
        return self::etToUtc($d->format('Y-m-d') . 'T09:00') ?? Clock::at($now + 86400);
    }

    /**
     * Registra el resultado de una llamada. $nextDate = 'AAAA-MM-DD' opcional (ET, 9:00 a. m.). Transaccion + historial + activity_log.
     * @return array{ok:bool,msg:string,status?:string}
     */
    public static function record(int $id, string $result, mixed $note, mixed $nextDate, int $userId, mixed $nextTime = '', ?array $qualIn = null): array
    {
        if (!isset(self::RESULTS[$result])) {
            return self::fail('Elige un resultado de la lista.');
        }
        $nextTime = is_string($nextTime) ? trim($nextTime) : '';
        if ($nextTime !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $nextTime) !== 1) {
            return self::fail('La hora del siguiente intento no es válida.');
        }
        $qual = null;
        $lang = false;   // false = el formulario no trae el campo (no se toca)
        if ($qualIn !== null && array_key_exists('lang', $qualIn)) {
            $lang = is_string($qualIn['lang']) && isset(self::LANGS[$qualIn['lang']]) ? $qualIn['lang'] : '';
            unset($qualIn['lang']);
        }
        if ($qualIn !== null) {
            $q = self::normalizeQual($qualIn);
            if ($q['error'] !== null) {
                return self::fail($q['error']);
            }
            $qual = $q['clean'];
        }
        $note = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', mb_scrub(is_string($note) ? $note : '')));
        $note = mb_substr($note, 0, 500);
        $next = null;
        if (is_string($nextDate) && trim($nextDate) !== '') {
            $next = self::etToUtc(trim($nextDate) . 'T09:00');
            if ($next === null) {
                return self::fail('La fecha del siguiente intento no es válida.');
            }
            if ((int) Clock::parse($next) < Clock::time() - 86400) {
                return self::fail('La fecha del siguiente intento ya pasó.');
            }
        }
        try {
            $r = Db::transaction(static function () use ($id, $result, $note, $next, $userId, $nextTime, $nextDate, $qual, $lang): array {
                $p = Db::one('SELECT * FROM prospects_fmcsa WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
                if ($p === null) {
                    return self::fail('El prospecto no existe.');
                }
                $exact = false;
                if ($nextTime !== '' && in_array($result, ['no_answer', 'voicemail', 'talked', 'interested'], true)) {   // hora pedida por el prospecto, en SU hora local
                    $at = self::localCallback((string) ($p['state'] ?? ''), is_string($nextDate) ? trim($nextDate) : '', $nextTime);
                    if (!$at['ok']) {
                        return self::fail($at['msg']);
                    }
                    $next = $at['utc'];
                    $exact = true;
                }
                if ((int) $p['do_not_call'] === 1) {
                    return self::fail('Este prospecto está en «No llamar más»: no se puede registrar otra llamada.');
                }
                if ($p['converted_lead_id'] !== null) {
                    return self::fail('Este prospecto ya es un lead: sigue su seguimiento desde la ficha.');
                }
                $now = Clock::now();
                $attempts = (int) $p['attempts'];
                $u = ['last_call_at' => $now, 'last_result' => $result, 'callback_exact' => $exact ? 1 : 0];
                if ($qual !== null) {
                    $u['qual'] = $qual ?: null;
                }
                if ($lang !== false) {   // fase AW: idioma anotado en la llamada ('' = sin saber)
                    $u['lang'] = $lang !== '' ? $lang : null;
                }
                $status = $result;
                if ($result === 'wrong_number') {   // número equivocado: se cierra (sin «no llamar»: el número no es de este carrier); se reabre a mano si aparece otro
                    $status = 'no_answer_closed';
                    $u += ['closed_at' => $now, 'next_call_at' => null, 'callback_exact' => 0];
                } elseif (in_array($result, self::MISSED, true)) {
                    $status = 'no_answer';
                    $attempts++;
                    $u['attempts'] = $attempts;
                    if ($attempts >= self::maxAttempts()) {
                        $status = 'no_answer_closed';
                        $u = ['closed_at' => $now, 'next_call_at' => null, 'callback_exact' => 0] + $u;
                        $next = null;
                    } else {
                        $next ??= self::retryAt(Clock::time(), $attempts);
                        $u['next_call_at'] = $next;
                    }
                } else {
                    $u['attempts'] = 0;
                    $u['next_call_at'] = in_array($result, ['talked', 'interested'], true) ? $next : null;
                    if (in_array($result, ['not_interested', 'dnc'], true)) {
                        $u['closed_at'] = $now;
                    }
                    if ($result === 'dnc') {
                        $u += ['do_not_call' => 1, 'dnc_at' => $now, 'next_call_at' => null];
                    }
                }
                $u['status'] = $status;
                Db::update('prospects_fmcsa', $id, $u);
                Db::insert('prospect_calls', ['prospect_id' => $id, 'user_id' => $userId, 'result' => $result, 'note' => $note !== '' ? $note : null,
                    'attempt_no' => in_array($result, self::MISSED, true) ? $attempts : 0, 'next_call_at' => $u['next_call_at'] ?? null, 'created_at' => $now]);
                if ($result === 'dnc' && $p['phone'] !== null) {
                    Db::run('INSERT IGNORE INTO dnc_numbers (phone_e164, usdot, prospect_id, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$p['phone'], $p['usdot'], $id, 'prospect', $userId, $now]);
                }
                ActivityLog::record('prospect_call', $userId, 'prospect', $id, ['result' => $result, 'status' => $status, 'attempts' => $attempts, 'has_note' => $note !== '', 'next' => $u['next_call_at'] ?? null]);
                $when = $u['next_call_at'] ?? null;
                $msg = match (true) {
                    $result === 'wrong_number' => 'Número equivocado: el prospecto se cerró. Si consigues otro teléfono, reábrelo.',
                    $status === 'no_answer_closed' => 'Intento ' . $attempts . ' de ' . self::maxAttempts() . ' sin respuesta: el prospecto pasó a «Sin respuesta (cerrada)». Puedes reabrirlo a mano.',
                    $status === 'no_answer' => ($result === 'voicemail' ? 'Mensaje de voz registrado' : 'Sin respuesta registrada') . ' (intento ' . $attempts . ' de ' . self::maxAttempts() . '). ' . ($exact ? 'Vuelve a la lista a la hora pedida.' : self::retryLabel((string) $when)),
                    default => match ($status) {
                    'dnc' => 'Marcado «No llamar más». No volverá a listarse.',
                    'interested' => 'Marcado como interesado. Conviértelo en lead para seguirlo.',
                    default => 'Resultado registrado: ' . self::RESULTS[$status] . '.',
                    } . ($exact && $when !== null ? ' Siguiente llamada: ' . self::localLabel((string) ($p['state'] ?? ''), (string) $when) . '.' : ''),
                };
                return self::ok($msg, ['status' => $status]);
            });
        } catch (\Throwable $t) {
            Storage::error('prospect record failed', get_class($t) . ': ' . $t->getMessage());
            return self::fail('No se pudo registrar el resultado. Inténtalo de nuevo.');
        }
        if (!$r['ok']) {
            return $r;
        }
        // Fase AU: automatizaciones tras la llamada (nunca rompen el registro del resultado)
        try {
            if (in_array($result, self::MISSED, true)) {
                ProspectAutoMail::queue($id, $userId);
            }
            if (($r['status'] ?? '') === 'no_answer_closed' && $result !== 'wrong_number') {   // fase AW: se agotaron los intentos: último correo de la cadencia
                ProspectAutoMail::queue($id, $userId, 'breakup');
            }
            if ($result === 'interested' && ProspectConfig::get()['auto_convert']) {
                $c = self::convert($id, $userId);
                $r['msg'] = $c['ok'] ? 'Interesado: ' . lcfirst($c['msg']) : 'Marcado como interesado, pero no se pudo convertir en lead: ' . $c['msg'];
                if (isset($c['lead_id'])) {
                    $r['lead_id'] = $c['lead_id'];
                }
            }
        } catch (\Throwable $t) {
            Storage::error('prospect after-call failed', get_class($t) . ': ' . $t->getMessage());
        }
        return $r;
    }

    /** Valida la calificación rápida de la llamada. @return array{clean:array<string,mixed>,error:?string} */
    public static function normalizeQual(array $in): array
    {
        $s = static fn(string $k, int $max): string => is_string($in[$k] ?? null) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', mb_scrub($in[$k]))), 0, $max) : '';
        $out = [];
        if (($n = $s('contact_name', 80)) !== '') {
            $out['contact_name'] = $n;
        }
        if (($m = strtolower($s('email', 190))) !== '') {
            if (filter_var($m, FILTER_VALIDATE_EMAIL) === false) {
                return ['clean' => [], 'error' => 'El correo que anotaste no es válido.'];
            }
            $out['email'] = $m;
        }
        $eq = array_values(array_filter(array_map('strval', is_array($in['equipment'] ?? null) ? array_slice($in['equipment'], 0, 10) : []), static fn($k) => isset(self::QUAL_EQUIPMENT[$k])));
        if ($eq) {
            $out['equipment'] = array_values(array_unique($eq));
        }
        foreach (['relay' => self::YESNO, 'dispatcher' => self::YESNO, 'best_time' => self::BEST] as $k => $opts) {
            $v = $s($k, 12);
            if ($v !== '' && isset($opts[$v])) {
                $out[$k] = $v;
            }
        }
        return ['clean' => $out, 'error' => null];
    }

    /** Calificación legible: [etiqueta, valor] por dato. @return list<array{0:string,1:string}> */
    public static function qualRows(?array $q): array
    {
        $q = is_array($q) ? $q : [];
        $rows = [];
        foreach (self::QUAL_LABELS as $k => $label) {
            if (!isset($q[$k]) || $q[$k] === '' || $q[$k] === []) {
                continue;
            }
            $v = match ($k) {
                'equipment' => implode(', ', array_map(static fn($x) => self::QUAL_EQUIPMENT[$x] ?? $x, (array) $q[$k])),
                'relay', 'dispatcher' => self::YESNO[$q[$k]] ?? (string) $q[$k],
                'best_time' => self::BEST[$q[$k]] ?? (string) $q[$k],
                default => (string) $q[$k],
            };
            $rows[] = [$label, $v];
        }
        return $rows;
    }

    /**
     * Hora de rellamada pedida por el prospecto en SU hora local (zona principal del estado). Sin fecha = hoy (allá). Debe ser futura y caer en el horario permitido.
     * @return array{ok:bool,msg:string,utc?:string}
     */
    public static function localCallback(string $state, string $date, string $time): array
    {
        $zones = UsTimeZones::zones($state);
        if (!$zones) {
            return ['ok' => false, 'msg' => 'Sin estado no se sabe la hora local: elige solo la fecha.'];
        }
        $tz = new \DateTimeZone($zones[0]);
        if ($date === '') {
            $date = (new \DateTimeImmutable('@' . Clock::time()))->setTimezone($tz)->format('Y-m-d');
        }
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? \DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $time, $tz) : false;
        if ($d === false) {
            return ['ok' => false, 'msg' => 'La fecha del siguiente intento no es válida.'];
        }
        $ts = $d->getTimestamp();
        if ($ts <= Clock::time()) {
            return ['ok' => false, 'msg' => 'Esa hora ya pasó allá (' . $d->format('g:i A') . ' ' . $d->format('T') . ').'];
        }
        if (!UsTimeZones::check($state, $ts)['allowed']) {
            return ['ok' => false, 'msg' => 'Esa hora queda fuera del horario permitido (8 a. m. a 9 p. m. hora local' . (UsTimeZones::isMultiZone($state) ? ' en todas las zonas del estado' : '') . ').'];
        }
        return ['ok' => true, 'msg' => '', 'utc' => gmdate('Y-m-d H:i:s', $ts)];
    }

    /** «jue 27, 3:00 p. m. CST» en la hora local del prospecto. */
    public static function localLabel(string $state, string $utc): string
    {
        $zones = UsTimeZones::zones($state);
        $d = (new \DateTimeImmutable($utc . ' UTC'))->setTimezone(new \DateTimeZone($zones[0] ?? 'America/New_York'));
        return Fmt::weekdayShort((int) $d->format('N')) . ' ' . $d->format('j') . ', ' . $d->format('g:i') . ' ' . ($d->format('A') === 'AM' ? 'a. m.' : 'p. m.') . ' ' . $d->format('T') . ($zones ? ' (hora local)' : '');
    }

    /**
     * Fase AT: «No interesado» de hace más de N meses (Configuración > Prospectos, apagado por defecto) vuelve a «Por llamar» con 0 intentos.
     * Nunca «No llamar más», ni con el teléfono en la lista de no llamar, ni ya convertidos. Lo llama el cron horario de FMCSA. @return int cuántos volvieron
     */
    public static function relist(int $now): int
    {
        $months = (int) ProspectConfig::get()['relist_months'];
        if ($months <= 0) {
            return 0;
        }
        $cut = (new \DateTimeImmutable('@' . $now))->modify('-' . $months . ' months')->format('Y-m-d H:i:s');
        $n = Db::run("UPDATE prospects_fmcsa p SET p.status = 'to_call', p.attempts = 0, p.closed_at = NULL, p.next_call_at = NULL, p.updated_at = ?
                       WHERE p.status = 'not_interested' AND p.deleted_at IS NULL AND p.do_not_call = 0 AND p.converted_lead_id IS NULL AND p.closed_at IS NOT NULL AND p.closed_at <= ?
                         AND (p.phone IS NULL OR NOT EXISTS (SELECT 1 FROM dnc_numbers d WHERE d.phone_e164 = p.phone))", [Clock::at($now), $cut])->rowCount();
        if ($n > 0) {
            ActivityLog::record('prospects_relist', null, 'prospect', null, ['count' => $n, 'months' => $months]);
        }
        return $n;
    }

    /** Reabre un prospecto cerrado por falta de respuesta (o «no interesado»): vuelve a «Por llamar» con 0 intentos. «No llamar más» jamas se reabre. */
    public static function reopen(int $id, int $userId): array
    {
        return Db::transaction(static function () use ($id, $userId): array {
            $p = Db::one('SELECT * FROM prospects_fmcsa WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
            if ($p === null) {
                return self::fail('El prospecto no existe.');
            }
            if ((int) $p['do_not_call'] === 1 || $p['status'] === 'dnc') {
                return self::fail('«No llamar más» es permanente: no se puede reabrir.');
            }
            if (!in_array($p['status'], ['no_answer_closed', 'not_interested'], true)) {
                return self::fail('Solo se reabren los prospectos cerrados («Sin respuesta (cerrada)» o «No interesado»).');
            }
            Db::update('prospects_fmcsa', $id, ['status' => 'to_call', 'attempts' => 0, 'next_call_at' => null, 'closed_at' => null]);
            Db::insert('prospect_calls', ['prospect_id' => $id, 'user_id' => $userId, 'result' => 'reopened', 'note' => null, 'attempt_no' => 0, 'created_at' => Clock::now()]);
            ActivityLog::record('prospect_reopen', $userId, 'prospect', $id, ['from' => $p['status']]);
            return self::ok('Prospecto reabierto: vuelve a «Por llamar».');
        });
    }

    /**
     * Convierte un prospecto en lead: fuente fmcsa_outbound, perfil owner-operator (1 unidad o sin dato) o carrier pequeño (2 o mas), datos de FMCSA ya cargados,
     * etapa «Contactado» con first_contact_at ahora, SIN consentimiento de mensajeria y SIN alertas de Telegram ni correos (quiet). Si ya existe un lead con el mismo
     * telefono o correo se enlaza a ese en lugar de crear otro.
     * @return array{ok:bool,msg:string,lead_id?:int}
     */
    public static function convert(int $id, int $userId): array
    {
        $p = self::find($id);
        if ($p === null) {
            return self::fail('El prospecto no existe.');
        }
        if ((int) $p['do_not_call'] === 1) {
            return self::fail('Este prospecto está en «No llamar más»: no se convierte en lead.');
        }
        if ($p['converted_lead_id'] !== null) {
            return self::ok('Ya era un lead.', ['lead_id' => (int) $p['converted_lead_id']]);
        }
        $units = $p['fleet_size'] !== null ? (int) $p['fleet_size'] : null;
        $name = (string) ($p['legal_name'] ?: ($p['dba'] ?: 'USDOT ' . $p['usdot']));
        $grantedAt = $p['authority_granted_at'];
        $age = null;
        if ($grantedAt !== null) {
            $g = new \DateTimeImmutable($grantedAt . ' 00:00:00 UTC');
            $n = new \DateTimeImmutable('@' . Clock::time());
            $age = $g > $n ? 0 : ((int) $n->format('Y') - (int) $g->format('Y')) * 12 + ((int) $n->format('n') - (int) $g->format('n')) - ((int) $n->format('j') < (int) $g->format('j') ? 1 : 0);
        }
        $fm = [
            'found' => true, 'usdot' => $p['usdot'], 'source' => 'socrata', 'legal_name' => $p['legal_name'], 'dba' => $p['dba'], 'state' => $p['state'], 'allowed_to_operate' => true, 'authority_status' => 'active',
            'authority_granted_at' => $grantedAt, 'authority_age_months' => $age, 'insured' => null, 'bipd_on_file' => null, 'power_units' => $units, 'drivers' => $p['drivers'] !== null ? (int) $p['drivers'] : null,
            'driver_oos_rate' => null, 'vehicle_oos_rate' => null, 'safety_rating' => null, 'red_flags' => [], 'checked_at' => Clock::now(),
        ];
        $q = is_array($p['qual']) ? $p['qual'] : [];   // fase AU: lo que se anotó en la llamada pasa al lead
        $payload = [
            'profile' => $units !== null && $units >= 2 ? 'small_carrier' : 'owner_operator', 'first_name' => mb_substr((string) ($q['contact_name'] ?? $name), 0, 100), 'phone' => (string) $p['phone'],
            'email' => (string) ($q['email'] ?? $p['email']), 'equipment_types' => array_values(array_merge((array) ($q['equipment'] ?? []), ($q['relay'] ?? '') === 'yes' ? ['amazon-relay'] : [])),
            'best_time' => (string) ($q['best_time'] ?? ''),
            'state' => (string) $p['state'], 'city' => (string) $p['city'], 'usdot' => (string) $p['usdot'], 'truck_count' => $units !== null && $units >= 1 ? $units : null, 'authority_status' => 'active',
            'language' => ($p['lang'] ?? '') === 'es' ? 'es' : 'en', 'consent' => ['given' => false],
        ];
        try {
            $r = LeadIngest::handle($payload, 'fmcsa_outbound', ['quiet' => true, 'skip_antispam' => true, 'fmcsa_data' => $fm]);
        } catch (\Throwable $t) {
            Storage::error('prospect convert failed', get_class($t) . ': ' . $t->getMessage());
            return self::fail('No se pudo crear el lead. Inténtalo de nuevo.');
        }
        if (!$r['ok'] || $r['lead_id'] === null) {
            return self::fail('No se pudo crear el lead (' . ($r['reason'] ?? 'datos no válidos') . ').');
        }
        $leadId = (int) $r['lead_id'];
        $existing = $r['status'] !== 'created';
        if (!$existing) {
            LeadActions::setStage($leadId, 'contacted', ['note' => 'Convertido desde un prospecto FMCSA (llamada manual)'], $userId);
        }
        $summary = [];
        if (($q['contact_name'] ?? '') !== '') {
            $summary[] = 'Empresa: ' . $name;
        }
        foreach (self::qualRows($q) as [$k, $v]) {
            $summary[] = $k . ': ' . $v;
        }
        $lastNote = Db::value("SELECT note FROM prospect_calls WHERE prospect_id = ? AND note IS NOT NULL ORDER BY id DESC LIMIT 1", [$id]);
        if (is_string($lastNote) && $lastNote !== '') {
            $summary[] = 'Última nota de la llamada: ' . $lastNote;
        }
        if ($summary && $userId > 0) {
            LeadActions::addNote($leadId, "Desde la llamada en frío (prospecto FMCSA, USDOT " . $p['usdot'] . "):\n" . implode("\n", $summary), $userId);
        }
        LeadIngest::event($leadId, 'prospect_converted', ['prospect_id' => $id, 'usdot' => $p['usdot'], 'existing_lead' => $existing], null);
        Db::update('prospects_fmcsa', $id, ['converted_lead_id' => $leadId, 'converted_at' => Clock::now(), 'status' => in_array($p['status'], ['to_call', 'no_answer', 'no_answer_closed', 'talked', 'not_interested'], true) ? 'interested' : $p['status'], 'next_call_at' => null]);
        Db::insert('prospect_calls', ['prospect_id' => $id, 'user_id' => $userId, 'result' => 'converted', 'note' => null, 'attempt_no' => 0, 'created_at' => Clock::now()]);
        ActivityLog::record('prospect_convert', $userId, 'prospect', $id, ['lead_id' => $leadId, 'existing_lead' => $existing]);
        return self::ok($existing ? 'Ya existía un lead con ese teléfono o correo: se enlazó a él (#' . $leadId . ').' : 'Lead creado en «Contactado» (#' . $leadId . '). No se mandó ningún mensaje ni alerta.', ['lead_id' => $leadId]);
    }

    /** Filas para el CSV (mismos filtros que la pantalla, sin paginar, maximo 5000). */
    public static function exportRows(array $f, int $now): array
    {
        [$sql, $p] = self::where($f, $now);
        return Db::all("SELECT p.* FROM prospects_fmcsa p WHERE $sql ORDER BY p.authority_granted_at DESC, p.completeness DESC, p.id ASC LIMIT 5000", $p);
    }
}
