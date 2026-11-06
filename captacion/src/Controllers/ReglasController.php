<?php
/**
 * Editor de reglas (Q5b), pestaña «Reglas» de /config. SOLO administradores (ConfigController::route ya lo exige y los endpoints JSON lo comprueban de nuevo: el lector recibe 403).
 *
 *  - Formularios (POST /config/rules/*: newset, starter, save, publish, revert, unpublish, discard, delete, switch, reset, apply, simcsv): sesion + CSRF; publicar, revertir, retirar, borrar,
 *    el interruptor general, restablecer y «aplicar a existentes» exigen ademas confirmacion reciente de contrasena + 2FA (Services\Reauth). Guardar borrador, la vista previa y la simulacion no.
 *  - JSON (POST /ajax/rules/{panel,row,preview,simulate,simstatus}): sesion + CSRF en X-CSRF-Token + rol admin + limites de uso. Nada de datos personales en la URL.
 *  Sin JavaScript el editor funciona como formulario clasico (Services\RuleForm aplica las operaciones estructurales en el servidor).
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Db;
use Captacion\Services\ActivityLog;
use Captacion\Services\RuleForm;
use Captacion\Services\RulePhrase;
use Captacion\Services\RuleRunner;
use Captacion\Services\Rules;
use Captacion\Services\RuleSets;
use Captacion\Services\RuleSim;
use Captacion\Services\RuleTemplates;
use Captacion\Services\RateLimiter;
use Captacion\Services\Reauth;
use Captacion\Services\ScoringConfig;
use Captacion\Services\Session;
use Captacion\Services\Settings;
use Captacion\View;

final class ReglasController extends Responder
{
    public const PREVIEW_LIMIT = 40;   // vistas previas por minuto y usuario
    public const SIM_LIMIT = 8;        // simulaciones por hora y usuario
    public const ROW_LIMIT = 300;      // filas y paneles por minuto y usuario
    public const JSON_MAX = 200000;
    private const STRUCT_OPS = ['add_rule', 'del_rule', 'dup_rule', 'up', 'down', 'add_cond', 'add_group', 'del_item', 'add_except', 'del_except', 'add_act', 'del_act', 'refresh'];
    private const IMPACT_SCOPES = ['last:100', 'last:500', 'last:1000', 'days:7', 'days:30', 'days:90'];
    private const SIM_SCOPES = ['last:500', 'last:1000', 'last:5000', 'last:20000', 'days:30', 'days:90', 'days:365', 'range'];

    private static function limit(string $bucket, array $user, int $max, int $window = 60, bool $json = true): void
    {
        $key = 'u' . (int) $user['id'];
        if (RateLimiter::tooMany($bucket, $key, $max, $window)) {
            if ($json) {
                self::json(['ok' => false, 'error' => 'rate_limited', 'msg' => 'Demasiadas peticiones seguidas. Espera un momento.'], 429, ['Retry-After: 30']);
            }
            self::flash(false, 'Demasiadas peticiones seguidas. Espera un momento.');
            self::redirect('/config?tab=reglas');
        }
        RateLimiter::hit($bucket, $key, $window);
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // Datos de la pestaña y del editor
    // ---------------------------------------------------------------------------------------------------------------------------

    public static function tabVars(): array
    {
        $sets = RuleSets::all();
        $editor = null;
        $sid = $_GET['set'] ?? null;
        $focus = isset($_GET['nueva']) ? 'new' : (isset($_GET['simular']) ? 'sim' : '');
        if (is_string($sid) && ctype_digit($sid) && strlen($sid) < 12) {
            $editor = self::editorVars((int) $sid);
        } elseif ($focus !== '' && $sets) {
            // Desde la paleta: «Nueva regla» y «Simular» abren el conjunto con borrador mas reciente (o el primero)
            $pick = $sets[0];
            foreach ($sets as $x) {
                if ($x['draft'] !== null && ($pick['draft'] === null || $x['draft']['at'] > $pick['draft']['at'])) {
                    $pick = $x;
                }
            }
            $editor = self::editorVars((int) $pick['id']);
            if ($editor !== null && $focus === 'new') {
                $editor['rules'] = RuleForm::apply($editor['rules'], $editor['kind'], 'add_rule');
            }
        }
        return ['focus' => $editor !== null ? $focus : ($focus === 'new' ? 'newset' : ''), 
            'sets' => $sets, 'editor' => $editor, 'rulesOn' => RuleRunner::enabled(), 'pubCount' => count(array_filter($sets, static fn($s) => $s['published'] !== null)),
            'cssV' => self::cssVersion(), 'recalc' => ScoringConfig::state(), 'recalcRunning' => ScoringConfig::running(),
        ];
    }

    private static function cssVersion(): string
    {
        return (string) (@filemtime(CAPTACION_ROOT . '/public/assets/rules.css') ?: 1);
    }

    /** @return array<string,array{when:string,then:string,except:string}> frases de las reglas completas (las que no validan no tienen frase) */
    private static function phrases(array $raw, string $kind): array
    {
        $out = [];
        foreach ($raw as $k => $r) {
            $one = Rules::normalize([$k => $r], $kind);
            if ($one['ok'] && $one['rules']) {
                $x = $one['rules'][0];
                $out[(string) $k] = ['when' => RulePhrase::when($x), 'then' => RulePhrase::then($x), 'except' => RulePhrase::except($x)];
            }
        }
        return $out;
    }

    /**
     * Variables del editor de un conjunto. $over: rules (forma de formulario), meta, errors, impact, impactErr, impactScope, simScope.
     * @return array|null null si el conjunto no existe
     */
    public static function editorVars(int $setId, array $over = []): ?array
    {
        $set = RuleSets::find($setId);
        if ($set === null) {
            return null;
        }
        $kind = (string) $set['kind'];
        $draft = RuleSets::draft($setId);
        $pub = RuleSets::published($setId);
        $rules = $over['rules'] ?? RuleForm::fromRules(($draft ?? $pub)['rules'] ?? []);
        // Historial con la diferencia de cada version frente a la anterior (las 12 mas nuevas)
        $history = RuleSets::history($setId);
        $rulesBy = [];
        foreach (Db::all('SELECT version, rules FROM rule_versions WHERE set_id = ? ORDER BY version', [$setId]) as $r) {
            $rulesBy[(int) $r['version']] = Db::decode($r['rules']);
        }
        $vers = array_keys($rulesBy);
        foreach ($history as $i => $h) {
            $pos = array_search($h['version'], $vers, true);
            $prev = $pos > 0 ? $rulesBy[$vers[$pos - 1]] : [];
            $history[$i]['diff'] = $i < 12 ? RuleSets::diff($prev, $rulesBy[$h['version']] ?? []) : [];
        }
        return $over + [
            'set' => ['id' => (int) $set['id'], 'name' => (string) $set['name'], 'description' => (string) $set['description']], 'kind' => $kind, 'rules' => $rules, 'errors' => [],
            'phrases' => self::phrases($rules, $kind), 'draft' => $draft, 'pub' => $pub, 'history' => $history, 'diffDraft' => $draft !== null ? RuleSets::diff($pub['rules'] ?? [], $draft['rules']) : [],
            'sim' => RuleSim::latest($setId), 'locked' => !Reauth::fresh(), 'csrf' => Session::csrf(), 'reauthTtl' => Reauth::ttl(),
            'meta' => ['name' => (string) $set['name'], 'description' => (string) $set['description'], 'note' => (string) ($draft['note'] ?? '')],
            'impact' => null, 'impactErr' => '', 'impactScope' => 'last:500', 'simScope' => 'last:1000',
        ];
    }

    private static function str(mixed $v, int $max): string
    {
        return is_string($v) ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? ''), 0, $max) : '';
    }

    /** @return array{0:array,1:array,2:array,3:array} [reglas del formulario, meta, normalizadas, errores] */
    private static function parse(array $src, string $kind): array
    {
        $raw = RuleForm::raw($src['rules'] ?? []);
        $meta = ['name' => self::str($src['name'] ?? '', 80), 'description' => self::str($src['description'] ?? '', 300), 'note' => self::str($src['note'] ?? '', 200)];
        $v = Rules::normalize($raw, $kind);
        $errors = $v['errors'];
        if ($meta['name'] === '') {
            $errors['meta.name'] = 'Ponle un nombre al conjunto.';
        } elseif (preg_match('/[<>]/', $meta['name'] . $meta['description'] . $meta['note']) === 1) {
            $errors['meta.name'] = 'El nombre, la descripción y la nota no admiten los signos < y >.';
        }
        return [$raw, $meta, $v, $errors];
    }

    /** @return array<string,mixed> alcance desde «last:100», «days:30» o «range» con fechas */
    private static function scopeIn(mixed $s, mixed $from, mixed $to, bool $sim): array
    {
        $s = is_string($s) ? $s : '';
        $ok = $sim ? self::SIM_SCOPES : self::IMPACT_SCOPES;
        $s = in_array($s, $ok, true) ? $s : ($sim ? 'last:1000' : 'last:500');
        if ($s === 'range') {
            return RuleSim::scope(['mode' => 'range', 'from' => is_string($from) ? $from : '', 'to' => is_string($to) ? $to : ''], true);
        }
        [$mode, $n] = explode(':', $s);
        return RuleSim::scope($mode === 'days' ? ['mode' => 'days', 'days' => (int) $n] : ['mode' => 'last', 'n' => (int) $n], $sim);
    }

    private static function target(array $set, array $rules): array
    {
        return ['set_id' => (int) $set['id'], 'name' => (string) $set['name'], 'kind' => (string) $set['kind'], 'rules' => $rules];
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // Formularios
    // ---------------------------------------------------------------------------------------------------------------------------

    private static function listUrl(int $setId = 0): string
    {
        return '/config?tab=reglas' . ($setId > 0 ? '&set=' . $setId : '');
    }

    private static function guard(bool $sensitive, int $setId = 0): void
    {
        $back = self::listUrl($setId);
        self::requirePost($back);
        if ($sensitive && !Reauth::fresh()) {
            self::flash(false, 'Confirma tu identidad (contraseña y código) para hacer este cambio.');
            self::redirect($back);
        }
    }

    private static function setId(): int
    {
        return isset($_POST['set']) && is_string($_POST['set']) && ctype_digit($_POST['set']) && strlen($_POST['set']) < 12 ? (int) $_POST['set'] : 0;
    }

    public static function route(?string $b, array $user): never
    {
        match ($b) {
            'newset'    => self::newSet($user),
            'starter'   => self::starter($user),
            'save'      => self::save($user, false),
            'publish'   => self::save($user, true),
            'revert'    => self::revert($user),
            'unpublish' => self::simple($user, 'unpublish'),
            'discard'   => self::simple($user, 'discard'),
            'delete'    => self::simple($user, 'delete'),
            'switch'    => self::switchAll($user),
            'reset'     => self::resetAll($user),
            'apply'     => self::apply($user),
            'simcsv'    => self::simCsv($user),
            default     => self::notFound(),
        };
    }

    private static function newSet(array $user): never
    {
        self::guard(false);
        $kind = is_string($_POST['kind'] ?? null) ? $_POST['kind'] : '';
        $r = RuleSets::create($kind, $_POST['name'] ?? '', $_POST['description'] ?? '', (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect(self::listUrl($r['ok'] ? (int) $r['id'] : 0));
    }

    private static function starter(array $user): never
    {
        self::guard(false);
        $r = RuleTemplates::install((int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect(self::listUrl());
    }

    private static function page(array $user, int $setId, array $over, int $status = 200): never
    {
        ConfigController::index($user, ['editor' => self::editorVars($setId, $over)], $status, 'reglas');
    }

    /** Guardar borrador / publicar / operaciones estructurales sin JS / vista previa y simulacion sin JS. */
    private static function save(array $user, bool $publish): never
    {
        $sid = self::setId();
        self::guard($publish, $sid);
        $set = $sid > 0 ? RuleSets::find($sid) : null;
        if ($set === null) {
            self::flash(false, 'El conjunto no existe.');
            self::redirect(self::listUrl());
        }
        $kind = (string) $set['kind'];
        $uid = (int) $user['id'];
        $op = is_string($_POST['op'] ?? null) ? $_POST['op'] : 'save';
        [$raw, $meta, $v, $errors] = self::parse($_POST, $kind);
        $keep = ['meta' => $meta, 'impactScope' => (string) ($_POST['impact_scope'] ?? 'last:500'), 'simScope' => (string) ($_POST['sim_scope'] ?? 'last:1000')];
        $name = explode(':', $op, 2)[0];

        if (!$publish && in_array($name, self::STRUCT_OPS, true)) {
            self::page($user, $sid, ['rules' => RuleForm::apply($raw, $kind, $op)] + $keep);
        }
        if (!$publish && $op === 'preview') {
            if ($errors) {
                self::page($user, $sid, ['rules' => $raw, 'errors' => $errors, 'impactErr' => 'Corrige los campos marcados para calcular el impacto.'] + $keep, 422);
            }
            $sc = self::scopeIn($keep['impactScope'], null, null, false);
            self::page($user, $sid, ['rules' => $raw, 'impact' => RuleSim::preview(self::target($set, $v['rules']), $sc['scope'])] + $keep);
        }
        if ($errors) {
            self::flash(false, 'Hay ' . count($errors) . ' campo' . (count($errors) === 1 ? '' : 's') . ' por corregir. No se guardó nada.');
            self::page($user, $sid, ['rules' => $raw, 'errors' => $errors] + $keep, 422);
        }
        if ($meta['name'] !== (string) $set['name'] || $meta['description'] !== (string) $set['description']) {
            RuleSets::updateMeta($sid, $meta['name'], $meta['description'], $uid);
        }
        $r = RuleSets::saveDraft($sid, $v['rules'], $meta['note'] !== '' ? $meta['note'] : null, $uid);
        if (!$r['ok']) {
            self::flash(false, $r['msg']);
            self::redirect(self::listUrl($sid));
        }
        if (!$publish && $op === 'simulate') {
            $sc = self::scopeIn($keep['simScope'], $_POST['sim_from'] ?? '', $_POST['sim_to'] ?? '', true);
            if (!$sc['ok']) {
                self::page($user, $sid, ['rules' => $raw, 'impactErr' => $sc['error']] + $keep, 422);
            }
            self::limit('rules_sim', $user, self::SIM_LIMIT, 3600, false);
            $s = RuleSim::start($sid, (int) $r['version_id'], $v['rules'], $sc['scope'], $uid);
            self::flash($s['ok'], $s['msg']);
            self::redirect(self::listUrl($sid));
        }
        if ($publish) {
            $p = RuleSets::publish($sid, $uid, $meta['note'] !== '' ? $meta['note'] : null);
            self::flash($p['ok'], $p['msg'] . ($p['ok'] ? ' Para actualizar los leads que ya existen usa «Aplicar a leads existentes».' : ''));
            self::redirect(self::listUrl($sid));
        }
        self::flash(true, $r['msg']);
        self::redirect(self::listUrl($sid));
    }

    private static function revert(array $user): never
    {
        $sid = self::setId();
        self::guard(true, $sid);
        $vid = isset($_POST['version']) && is_string($_POST['version']) && ctype_digit($_POST['version']) ? (int) $_POST['version'] : 0;
        $r = RuleSets::revert($sid, $vid, (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect(self::listUrl($sid));
    }

    private static function simple(array $user, string $what): never
    {
        $sid = self::setId();
        self::guard($what !== 'discard', $sid);
        $uid = (int) $user['id'];
        $r = match ($what) {
            'unpublish' => RuleSets::unpublish($sid, $uid),
            'discard'   => RuleSets::discardDraft($sid, $uid),
            default     => RuleSets::deleteSet($sid, $uid),
        };
        self::flash($r['ok'], $r['msg']);
        self::redirect(self::listUrl($what === 'delete' || !$r['ok'] ? 0 : $sid));
    }

    private static function switchAll(array $user): never
    {
        self::guard(true);
        $on = ($_POST['on'] ?? '') === '1';
        $before = RuleRunner::enabled();
        Settings::set('rules.enabled', $on);
        RuleSets::clearCache();
        ActivityLog::settingsChange((int) $user['id'], 'rules.enabled', ['encendidas' => $before], ['encendidas' => $on]);
        self::flash(true, $on ? 'Reglas encendidas: lo publicado vuelve a aplicarse.' : 'Reglas apagadas: la puntuación y las alertas son las del documento.');
        self::redirect(self::listUrl());
    }

    private static function resetAll(array $user): never
    {
        self::guard(true);
        $r = RuleSets::retireAll((int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect(self::listUrl());
    }

    private static function apply(array $user): never
    {
        self::guard(true);
        if (($_POST['confirm'] ?? '') !== '1') {
            self::flash(false, 'Marca la casilla de confirmación para recalcular.');
            self::redirect(self::listUrl());
        }
        $r = ScoringConfig::startRecalc((int) $user['id']);
        self::flash($r['ok'], $r['msg'] . ($r['ok'] ? ' Los leads existentes reciben puntos, temperatura, etiquetas y cola de revisión; no se crean alertas ni tareas en masa.' : ''));
        self::redirect(self::listUrl());
    }

    private static function simCsv(array $user): never
    {
        self::guard(false);
        $id = isset($_POST['sim']) && is_string($_POST['sim']) && ctype_digit($_POST['sim']) ? (int) $_POST['sim'] : 0;
        $csv = $id > 0 ? RuleSim::csv($id) : null;
        if ($csv === null) {
            self::flash(false, 'Esa simulación no existe o todavía no termina.');
            self::redirect(self::listUrl(self::setId()));
        }
        ActivityLog::record('rules_sim_export', (int) $user['id'], 'rule_sim', $id, ['filas' => substr_count($csv, "\r\n") - 1]);
        self::download('simulacion-reglas-' . $id . '.csv', 'text/csv; charset=utf-8', $csv);
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // JSON
    // ---------------------------------------------------------------------------------------------------------------------------

    public static function ajax(string $name): never
    {
        [$user, $b] = self::jsonPost(self::JSON_MAX);
        if (($user['role'] ?? '') !== 'admin') {
            self::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        match ($name) {
            'panel'     => self::ajaxPanel($user, $b),
            'row'       => self::ajaxRow($user, $b),
            'preview'   => self::ajaxPreview($user, $b),
            'simulate'  => self::ajaxSimulate($user, $b),
            'simstatus' => self::ajaxSimStatus($user, $b),
            default     => self::json(['ok' => false, 'error' => 'not_found'], 404),
        };
    }

    private static function setOf(mixed $id): ?array
    {
        return is_int($id) || (is_string($id) && ctype_digit($id)) ? RuleSets::find((int) $id) : null;
    }

    private static function ajaxPanel(array $user, array $b): never
    {
        self::limit('rules_row', $user, self::ROW_LIMIT);
        $set = self::setOf($b['id'] ?? null);
        if ($set === null) {
            self::json(['ok' => false, 'error' => 'not_found', 'msg' => 'El conjunto ya no existe.'], 404);
        }
        $vars = self::editorVars((int) $set['id']);
        self::json(['ok' => true, 'id' => (int) $set['id'], 'name' => (string) $set['name'], 'html' => View::render('config/_reglas_editor', $vars + ['inline' => false], false)]);
    }

    /** Una pieza del editor (condicion, grupo, accion, regla o excepcion) con los nombres de campo que pide el JS. */
    private static function ajaxRow(array $user, array $b): never
    {
        self::limit('rules_row', $user, self::ROW_LIMIT);
        $np = is_string($b['np'] ?? null) ? $b['np'] : '';
        $set = self::setOf($b['set'] ?? null);
        if (!RuleForm::validPrefix($np) || $set === null) {
            self::json(['ok' => false, 'error' => 'invalid'], 422);
        }
        $kind = (string) $set['kind'];
        $dp = RuleForm::dotted($np);
        $type = is_string($b['kind'] ?? null) ? $b['kind'] : '';
        switch ($type) {
            case 'cond':
                $field = is_string($b['field'] ?? null) && isset(Rules::FIELDS[$b['field']]) ? $b['field'] : 'profile';
                $ftype = Rules::fieldType($field) ?? 'enum';
                $op = is_string($b['op'] ?? null) && in_array($b['op'], Rules::OPS_BY_TYPE[$ftype], true) ? $b['op'] : Rules::OPS_BY_TYPE[$ftype][0];
                $c = ['field' => $field, 'op' => $op, 'value' => $b['value'] ?? ''];
                $html = View::render('config/_rule_cond', ['np' => $np, 'dp' => $dp, 'c' => $c, 'errors' => [], 'delOp' => 'del_item:' . $dp], false);
                break;
            case 'group':
                $html = View::render('config/_rule_group', ['np' => $np, 'dp' => $dp, 'g' => ['mode' => 'any', 'items' => ['c1' => RuleForm::blankCond()]], 'errors' => []], false);
                break;
            case 'act':
                $a = RuleForm::blankAction($kind, is_string($b['type'] ?? null) ? $b['type'] : '');
                $key = basename(str_replace(['[', ']'], ['/', ''], $np));
                $rk = explode('.', $dp)[1] ?? 'x';
                $html = View::render('config/_rule_act', ['np' => $np, 'dp' => $dp, 'a' => $a, 'kind' => $kind, 'errors' => [], 'delOp' => 'del_act:' . $rk . '.' . $key], false);
                break;
            case 'except':
                $html = View::render('config/_rule_block', ['np' => $np, 'dp' => $dp, 'block' => ['mode' => 'all', 'items' => ['c1' => RuleForm::blankCond()]], 'errors' => [], 'which' => 'except', 'key' => explode('.', $dp)[1] ?? 'x'], false);
                break;
            case 'rule':
                $key = explode('.', $dp)[1] ?? 'x';
                $html = View::render('config/_rule', ['k' => $key, 'r' => RuleForm::blankRule($kind), 'errors' => [], 'kind' => $kind, 'phr' => null], false);
                break;
            default:
                self::json(['ok' => false, 'error' => 'invalid'], 422);
        }
        self::json(['ok' => true, 'html' => $html]);
    }

    private static function ajaxPreview(array $user, array $b): never
    {
        self::limit('rules_prev', $user, self::PREVIEW_LIMIT);
        $set = self::setOf($b['set'] ?? null);
        if ($set === null) {
            self::json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $kind = (string) $set['kind'];
        [$raw, $meta, $v, $errors] = self::parse(RuleForm::fromQuery($b['form'] ?? ''), $kind);
        $out = ['ok' => true, 'valid' => !$errors, 'errors' => $errors, 'phrases' => self::phrases($raw, $kind)];
        if (!empty($b['impact'])) {
            $sc = self::scopeIn($b['scope'] ?? '', null, null, false);
            $impact = null;
            $err = '';
            if ($errors) {
                $err = 'Corrige los campos marcados para ver el impacto.';
            } else {
                $impact = RuleSim::preview(self::target($set, $v['rules']), $sc['scope']);
            }
            $out['html'] = View::render('config/_rule_impact', ['impact' => $impact, 'impactErr' => $err], false);
        }
        self::json($out);
    }

    private static function ajaxSimulate(array $user, array $b): never
    {
        $set = self::setOf($b['set'] ?? null);
        if ($set === null) {
            self::json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $kind = (string) $set['kind'];
        $uid = (int) $user['id'];
        [$raw, $meta, $v, $errors] = self::parse(RuleForm::fromQuery($b['form'] ?? ''), $kind);
        if ($errors) {
            self::json(['ok' => false, 'error' => 'invalid', 'errors' => $errors, 'msg' => 'Corrige los campos marcados antes de simular.'], 422);
        }
        $sc = self::scopeIn($b['scope'] ?? '', $b['from'] ?? '', $b['to'] ?? '', true);
        if (!$sc['ok']) {
            self::json(['ok' => false, 'error' => 'invalid', 'msg' => $sc['error']], 422);
        }
        self::limit('rules_sim', $user, self::SIM_LIMIT, 3600);
        if ($meta['name'] !== (string) $set['name'] || $meta['description'] !== (string) $set['description']) {
            RuleSets::updateMeta((int) $set['id'], $meta['name'], $meta['description'], $uid);
        }
        $d = RuleSets::saveDraft((int) $set['id'], $v['rules'], $meta['note'] !== '' ? $meta['note'] : null, $uid);   // la simulacion queda asociada al borrador
        if (!$d['ok']) {
            self::json(['ok' => false, 'error' => 'save', 'msg' => $d['msg']], 422);
        }
        $s = RuleSim::start((int) $set['id'], (int) $d['version_id'], $v['rules'], $sc['scope'], $uid);
        if (!$s['ok']) {
            self::json(['ok' => false, 'error' => 'sim', 'msg' => $s['msg']], 422);
        }
        $sim = RuleSim::status((int) $s['id']);
        self::json(['ok' => true, 'id' => $s['id'], 'status' => $sim['status'], 'version' => (int) $d['version'], 'msg' => $s['msg'], 'html' => View::render('config/_rule_sim', ['sim' => $sim, 'csrf' => Session::csrf(), 'setId' => (int) $set['id']], false)]);
    }

    private static function ajaxSimStatus(array $user, array $b): never
    {
        self::limit('rules_row', $user, self::ROW_LIMIT);
        $id = is_int($b['id'] ?? null) || (is_string($b['id'] ?? null) && ctype_digit($b['id'])) ? (int) $b['id'] : 0;
        $sim = $id > 0 ? RuleSim::status($id) : null;
        if ($sim === null) {
            self::json(['ok' => false, 'error' => 'not_found'], 404);
        }
        self::json(['ok' => true, 'status' => $sim['status'], 'percent' => $sim['percent'], 'html' => View::render('config/_rule_sim', ['sim' => $sim, 'csrf' => Session::csrf(), 'setId' => $sim['set_id']], false)]);
    }
}
