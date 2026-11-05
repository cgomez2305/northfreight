<?php
/**
 * /referidos (solo administradores): codigos de referido, leads referidos, estado (referido, calificado, firmado) y bonos (pendiente, aprobado, pagado).
 * El monto del bono es PROVISIONAL hasta confirmarlo con NorthStar (ajuste referrals.bonus_confirmed). Cada accion: sesion + rol admin + CSRF + validacion + activity_log.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Services\Referrals;

final class ReferidosController extends Responder
{
    public static function route(?string $a, ?string $b): never
    {
        $user = self::user();
        self::requireAdmin($user);
        if ($a === null) {
            self::index($user);
        }
        $uid = (int) $user['id'];
        if ($a === 'new' && $b === null) {
            self::requirePost('/referidos');
            $r = Referrals::createCode($_POST['owner_name'] ?? '', $_POST['lead_id'] ?? null, $_POST['code'] ?? '', $_POST['bonus_amount'] ?? null, $_POST['note'] ?? '', $uid);
            self::flash($r['ok'], $r['msg']);
            self::redirect('/referidos');
        }
        if ($a === 'confirm' && $b === null) {
            self::requirePost('/referidos');
            $yes = ($_POST['confirmed'] ?? '') === '1';
            Referrals::setBonusConfirmed($yes, $uid);
            self::flash(true, $yes ? 'Marcado: NorthStar confirmó los montos de los bonos.' : 'Los montos vuelven a ser provisionales (pendientes de confirmar con NorthStar).');
            self::redirect('/referidos');
        }
        if (ctype_digit((string) $a) && $b === 'code') {
            self::requirePost('/referidos');
            $op = is_string($_POST['op'] ?? null) ? $_POST['op'] : '';
            $r = match ($op) {
                'enable'  => Referrals::setActive((int) $a, true, $uid),
                'disable' => Referrals::setActive((int) $a, false, $uid),
                'amount'  => Referrals::updateAmount((int) $a, $_POST['bonus_amount'] ?? '', $uid),
                default   => ['ok' => false, 'msg' => 'Acción no válida.'],
            };
            self::flash($r['ok'], $r['msg']);
            self::redirect('/referidos#codigos');
        }
        if (ctype_digit((string) $a) && $b === 'bonus') {
            self::requirePost('/referidos');
            $r = Referrals::bonusAction((int) $a, is_string($_POST['op'] ?? null) ? $_POST['op'] : '', $_POST['bonus_amount'] ?? null, $_POST['note'] ?? '', $uid);
            self::flash($r['ok'], $r['msg']);
            self::redirect('/referidos');
        }
        self::notFound();
    }

    private static function index(array $user): never
    {
        $filter = is_string($_GET['f'] ?? null) ? $_GET['f'] : '';
        $filter = isset(Referrals::STATUSES[$filter]) || (isset(Referrals::BONUS[$filter]) && $filter !== 'none') ? $filter : '';
        $o = Referrals::overview($filter);
        self::page('referidos', $user, 'referidos', [['Referidos', null]], [
            'title' => 'Referidos', 'css' => ['referidos'], 'js' => ['referidos'], 'ov' => $o, 'filter' => $filter, 'confirmed' => Referrals::bonusConfirmed(), 'siteUrl' => Referrals::siteUrl(),
        ]);
    }
}
