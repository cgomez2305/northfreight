<?php
/**
 * Fase AM · /importar (solo administradores): importacion CSV de leads.
 *   GET  /importar                  subir un archivo + importaciones recientes
 *   POST /importar                  sube el CSV (≤ 2 MB, ≤ 2.000 filas)
 *   GET  /importar/{id}             mapeo de columnas y vista previa (o avance y resultado si ya se importo)
 *   POST /importar/{id}/simular     simulacion: NO escribe nada, cuenta lo que pasaria
 *   POST /importar/{id}/start       importa por la cola
 *   POST /importar/{id}/discard     descarta la subida y borra el archivo
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Services\ActivityLog;
use Captacion\Services\LeadImport;
use Captacion\Services\RateLimiter;
use Captacion\Db;

final class ImportController extends Responder
{
    public static function route(?int $id, ?string $action): never
    {
        $user = self::user();
        self::requireAdmin($user);
        if ($id === null) {
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' ? self::upload($user) : self::index($user);
        }
        $imp = LeadImport::find($id);
        if ($imp === null) {
            self::notFound();
        }
        match ($action) {
            null => self::show($user, $imp),
            'simular' => self::simulate($user, $imp),
            'start' => self::start($user, $imp),
            'discard' => self::discard($user, $imp),
            default => self::notFound(),
        };
    }

    private static function index(array $user): never
    {
        LeadImport::cleanup();
        self::page('importar', $user, 'leads', [['Leads', '/leads'], ['Importar CSV', null]], ['title' => 'Importar leads', 'css' => ['datos'], 'recent' => LeadImport::recent(), 'imp' => null]);
    }

    private static function upload(array $user): never
    {
        self::requirePost('/importar');
        $key = 'u' . (int) $user['id'];
        if (RateLimiter::tooMany('lead_import_upload', $key, 10, 3600)) {
            self::flash(false, 'Subiste muchos archivos seguidos. Espera un rato.');
            self::redirect('/importar');
        }
        RateLimiter::hit('lead_import_upload', $key, 3600);
        $f = $_FILES['csv'] ?? null;
        $r = LeadImport::store(is_array($f) ? $f : [], (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect($r['ok'] ? '/importar/' . (int) $r['id'] : '/importar');
    }

    /** Datos comunes de la pantalla de mapeo. */
    private static function mapping(array $user, array $imp, ?array $sim, ?array $form, string $error = ''): never
    {
        $path = LeadImport::path((string) $imp['token']);
        $data = LeadImport::read($path, (string) $imp['delimiter_char'], 6);
        $suggest = LeadImport::suggest($data['headers']);
        self::page('importar', $user, 'leads', [['Leads', '/leads'], ['Importar CSV', '/importar'], [(string) $imp['filename'], null]], [
            'title' => 'Importar leads', 'css' => ['datos'], 'recent' => [], 'imp' => $imp, 'headers' => $data['headers'], 'preview' => array_slice($data['rows'], 0, 5),
            'mapping' => $form['mapping'] ?? $suggest, 'options' => $form['options'] ?? ['profile' => '', 'skip_duplicates' => true], 'sim' => $sim, 'formError' => $error, 'progress' => null,
        ]);
    }

    private static function show(array $user, array $imp): never
    {
        if ($imp['status'] === 'uploaded') {
            if (!is_file(LeadImport::path((string) $imp['token']))) {
                self::flash(false, 'El archivo de esta importación ya no está en el servidor. Súbelo otra vez.');
                self::redirect('/importar');
            }
            self::mapping($user, $imp, null, null);
        }
        self::page('importar', $user, 'leads', [['Leads', '/leads'], ['Importar CSV', '/importar'], [(string) $imp['filename'], null]], [
            'title' => 'Importar leads', 'css' => ['datos'], 'recent' => [], 'imp' => $imp, 'headers' => [], 'preview' => [], 'mapping' => [], 'options' => [], 'sim' => null, 'formError' => '', 'progress' => $imp,
        ]);
    }

    private static function simulate(array $user, array $imp): never
    {
        $back = '/importar/' . (int) $imp['id'];
        self::requirePost($back);
        if ($imp['status'] !== 'uploaded' || !is_file(LeadImport::path((string) $imp['token']))) {
            self::redirect($back);
        }
        $data = LeadImport::read(LeadImport::path((string) $imp['token']), (string) $imp['delimiter_char'], 1);
        $form = LeadImport::parseForm($_POST, count($data['headers']));
        $sim = $form['error'] === '' ? LeadImport::simulate($imp, $form['mapping'], $form['options']) : null;
        self::mapping($user, $imp, $sim, $form, $form['error']);
    }

    private static function start(array $user, array $imp): never
    {
        $back = '/importar/' . (int) $imp['id'];
        self::requirePost($back);
        if ($imp['status'] !== 'uploaded' || !is_file(LeadImport::path((string) $imp['token']))) {
            self::flash(false, 'Esta importación ya se inició o el archivo ya no existe.');
            self::redirect($back);
        }
        $data = LeadImport::read(LeadImport::path((string) $imp['token']), (string) $imp['delimiter_char'], 1);
        $form = LeadImport::parseForm($_POST, count($data['headers']));
        if ($form['error'] !== '') {
            self::mapping($user, $imp, null, $form, $form['error']);
        }
        if (($_POST['confirm'] ?? '') !== '1') {
            self::mapping($user, $imp, null, $form, 'Marca la casilla para confirmar: los leads importados no tienen consentimiento y se crean de verdad.');
        }
        // doble clic: solo el primero pasa de «uploaded» a «queued»
        if (Db::run("UPDATE lead_imports SET status = 'queued' WHERE id = ? AND status = 'uploaded'", [(int) $imp['id']])->rowCount() !== 1) {
            self::redirect($back);
        }
        LeadImport::start($imp, $form['mapping'], $form['options'], (int) $user['id']);
        self::flash(true, 'Importación en cola: se procesa en lotes de ' . LeadImport::BATCH . ' filas cada minuto. Esta página muestra el avance.');
        self::redirect($back);
    }

    private static function discard(array $user, array $imp): never
    {
        self::requirePost('/importar');
        if ($imp['status'] === 'uploaded') {
            LeadImport::dropFile($imp);
            Db::update('lead_imports', (int) $imp['id'], ['status' => 'cancelled', 'finished_at' => \Captacion\Clock::now()]);
            ActivityLog::record('lead_import_discard', (int) $user['id'], 'lead_import', (int) $imp['id']);
            self::flash(true, 'Subida descartada y archivo borrado.');
        }
        self::redirect('/importar');
    }
}
