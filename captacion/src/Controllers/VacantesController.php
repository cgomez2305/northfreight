<?php
/**
 * /vacantes (solo administradores): alta, edicion, publicacion, pausa y cierre de las vacantes de company drivers que se publican en el SITIO (storage/jobs.json).
 * Cada accion: sesion + rol admin + CSRF + validacion estricta (Services\JobPostings) + activity_log. Publicar/pausar/cerrar reescribe el archivo del sitio de forma atomica.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Services\JobPostings;
use Captacion\Services\JobsPublisher;
use Captacion\Services\Settings;
use Captacion\Services\UsStates;

final class VacantesController extends Responder
{
    public static function route(?string $a, ?string $b): never
    {
        $user = self::user();
        self::requireAdmin($user);
        $uid = (int) $user['id'];
        if ($a === null) {
            self::index($user);
        }
        $post = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
        if ($a === 'new' && $b === null) {
            if (!$post) {
                self::form($user, null, self::blank(), []);
            }
            self::requirePost('/vacantes/new');
            $r = JobPostings::create($_POST, $uid);
            if (!$r['ok']) {
                self::flash(false, $r['msg']);
                self::form($user, null, $_POST, $r['errors'] ?? [], 422);
            }
            self::flash(true, $r['msg']);
            self::redirect('/vacantes/' . $r['id']);
        }
        if (ctype_digit((string) $a)) {
            $id = (int) $a;
            $job = JobPostings::find($id);
            if ($job === null) {
                self::notFound();
            }
            if ($b === null && !$post) {
                self::form($user, $job, JobPostings::toForm($job), []);
            }
            if ($b === 'save') {
                self::requirePost('/vacantes/' . $id);
                $r = JobPostings::save($id, $_POST, $uid);
                if (!$r['ok']) {
                    self::flash(false, $r['msg']);
                    self::form($user, $job, $_POST, $r['errors'] ?? [], 422);
                }
                self::flash(true, $r['msg']);
                self::redirect('/vacantes/' . $id);
            }
            if ($b === 'status') {
                self::requirePost('/vacantes/' . $id);
                $op = is_string($_POST['op'] ?? null) ? $_POST['op'] : '';
                $r = JobPostings::setStatus($id, $op, $uid);
                self::flash($r['ok'], $r['msg']);
                self::redirect($op === 'delete' && $r['ok'] ? '/vacantes' : (($_POST['from'] ?? '') === 'list' ? '/vacantes' : '/vacantes/' . $id));
            }
        }
        self::notFound();
    }

    private static function blank(): array
    {
        $today = JobPostings::today();
        return ['title' => '', 'slug' => '', 'posted_on' => $today, 'valid_through' => (new \DateTimeImmutable($today))->modify('+60 days')->format('Y-m-d'), 'employment_type' => 'FULL_TIME', 'city' => '', 'region' => '', 'postal' => '',
            'summary' => '', 'equipment' => '', 'responsibilities' => [], 'requirements' => [], 'benefits' => [], 'pay_on' => '', 'pay_min' => '', 'pay_max' => '', 'pay_unit' => 'WEEK'];
    }

    private static function index(array $user): never
    {
        $jobs = JobPostings::all();
        $pub = Settings::get('jobs.published');
        $domain = parse_url(\Captacion\Services\Referrals::siteUrl(), PHP_URL_HOST) ?: 'northstarfreightsolutions.us';
        self::page('vacantes', $user, 'vacantes', [['Vacantes', null]], [
            'title' => 'Vacantes', 'css' => ['vacantes'], 'js' => [], 'jobs' => $jobs, 'pub' => is_array($pub) ? $pub : null, 'siteUrl' => \Captacion\Services\Referrals::siteUrl(),
            'today' => JobPostings::today(), 'siteFile' => 'storage/jobs.json', 'domain' => $domain,
        ]);
    }

    private static function form(array $user, ?array $job, array $values, array $errors, int $status = 200): never
    {
        foreach (array_keys(JobPostings::LIST_FIELDS) as $k) {
            if (is_array($values[$k] ?? null)) {
                $values[$k] = implode("\n", array_map('strval', $values[$k]));
            }
        }
        self::page('vacante', $user, 'vacantes', [['Vacantes', '/vacantes'], [$job !== null ? (string) $job['title'] : 'Nueva vacante', null]], [
            'title' => $job !== null ? 'Editar vacante' : 'Nueva vacante', 'css' => ['vacantes'], 'js' => [], 'job' => $job, 'v' => $values, 'errors' => $errors, 'siteUrl' => \Captacion\Services\Referrals::siteUrl(),
            'states' => UsStates::NAMES, 'today' => JobPostings::today(),
        ], $status);
    }
}
