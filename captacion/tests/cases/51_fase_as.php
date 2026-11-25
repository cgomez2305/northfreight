<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Services\ProspectMail;
use Captacion\Services\Prospects;
use Captacion\Services\Sequences;
use Captacion\Services\Templates;
use CaptacionTests\Fx;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Fase AS (2026-11-25): dirección postal por defecto en el CRM y lógica de los filtros de Prospectos («Lista de hoy» con los seguimientos de «Conversó» e
 * «Interesado», sin convertidos; estados con prospectos y su conteo; filtro «Solo con correo» visible con su chip).
 */
return static function (array $ctx): void {
    T::reset(true);

    // ------------------------------------------------------------------ Dirección por defecto
    Fx::setting('mail', []);   // otras pruebas dejan una dirección escrita
    $was = Env::get('MAIL_DEFAULT_ADDRESS');
    T::eq(Templates::address(), '', 'con MAIL_DEFAULT_ADDRESS=- (resto de pruebas) no hay dirección por defecto');
    Env::set('MAIL_DEFAULT_ADDRESS', null);
    T::eq(Templates::address(), "NorthStar Freight Solutions LLC\n30 N Gould St, Ste N\nSheridan, WY 82801", 'sin nada escrito, la dirección es la del sitio (Sheridan, WY)');
    T::eq(Sequences::mailSettings()['address'], Templates::address(), '  Configuración > Correo y las secuencias la usan');
    T::ok(!str_contains(implode(' ', ProspectMail::blockers()), 'dirección') && !str_contains(implode(' ', array_column(Sequences::warnings(), 'text')), 'Falta la dirección'), '  y deja de bloquear secuencias y campañas a prospectos');
    $f = Templates::footer('en', 'https://panel.test/u/x');
    T::ok(str_contains($f['text'], '30 N Gould St, Ste N') && str_contains($f['html'], 'Sheridan, WY 82801'), '  y sale en el pie de cada correo');
    Fx::setting('mail', ['address' => "Otra Calle 1\nMiami, FL 33101"]);
    T::eq(Templates::address(), "Otra Calle 1\nMiami, FL 33101", 'si se escribe otra en Configuración, manda la escrita');
    Fx::setting('mail', ['address' => '']);
    T::ok(str_contains(Templates::address(), 'Sheridan'), '  y si se borra, vuelve la de por defecto (nunca se queda sin dirección)');
    Env::set('MAIL_DEFAULT_ADDRESS', $was);

    // ------------------------------------------------------------------ Lista de hoy
    $now = Clock::time();
    $todayEt = Prospects::etDay($now);
    $n = 0;
    $mk = static function (array $o = []) use (&$n): int {
        $n++;
        return (int) Db::insert('prospects_fmcsa', $o + ['usdot' => (string) (9600000 + $n), 'legal_name' => "HOY $n LLC", 'state' => 'TX', 'phone' => '+1305556' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'fleet_size' => 2, 'authority_granted_at' => '2026-11-10', 'status' => 'to_call', 'listed_on' => '2026-11-20', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
    };
    $todayAt = Prospects::etToUtc($todayEt . 'T09:00');
    $tomorrowAt = Prospects::etToUtc((new \DateTimeImmutable($todayEt . ' 12:00'))->modify('+1 day')->format('Y-m-d') . 'T09:00');
    $new = $mk();
    $talkedToday = $mk(['status' => 'talked', 'next_call_at' => $todayAt, 'state' => 'GA']);
    $intToday = $mk(['status' => 'interested', 'next_call_at' => $todayAt, 'email' => 'int@example.com']);
    $talkedTomorrow = $mk(['status' => 'talked', 'next_call_at' => $tomorrowAt]);
    $talkedNoDate = $mk(['status' => 'talked']);
    $retryToday = $mk(['status' => 'no_answer', 'attempts' => 1, 'next_call_at' => $todayAt]);
    $conv = $mk(['status' => 'interested', 'next_call_at' => $todayAt, 'converted_lead_id' => Fx::lead()]);
    $ids = array_map('intval', array_column(Prospects::page(Prospects::filters([]), $now, 1, 100)['rows'], 'id'));
    sort($ids);
    $want = [$new, $talkedToday, $intToday, $retryToday];
    sort($want);
    T::eq($ids, $want, 'Lista de hoy: por llamar + reintentos + seguimientos de «Conversó» e «Interesado» que tocan hoy (no los de mañana, ni sin fecha, ni ya convertidos)');
    $first = (int) Prospects::page(Prospects::filters([]), $now, 1, 100)['rows'][0]['id'];
    T::ok($first !== $new, '  los seguimientos con fecha van antes que los nunca llamados');
    T::eq(Prospects::counts(Prospects::filters([]), $now)['today'], Prospects::followupsDue($now)['n'] + 1, '  la lista y la campana «Seguimientos de hoy» cuentan lo mismo (más el nuevo por llamar)');

    // ------------------------------------------------------------------ Filtros
    T::eq(Prospects::stateFacets(), ['GA' => 1, 'TX' => 6], 'filtro de estado: solo los estados con prospectos y cuántos hay');
    $admin = Panel::user('admin');
    $s = Panel::start($ctx, 'panel-as51');
    T::ok($s->login($admin['email']), 'login del administrador');
    $p = $s->get('/prospectos')['body'];
    T::ok(str_contains($p, '>TX · Texas (6)</option>') && !str_contains($p, '>AK · Alaska'), '  la pantalla muestra «TX · Texas (6)» y no los 51 estados');
    T::ok(str_contains($p, 'name="ph" value="mail"'), '«Solo con correo» está en el panel de filtros (antes el filtro existía solo por URL y no se veía)');
    $pm = $s->get('/prospectos?ph=mail')['body'];
    T::ok(str_contains($pm, 'Solo con correo<svg') && preg_match('#fpop has-on.*?<b class="badge">1</b>#s', $pm) === 1, '  con su chip y contado en el botón «Filtros»');
    T::ok(stripos($pm, 'Hoy 3 LLC') !== false && stripos($pm, 'Hoy 1 LLC') === false, '  y filtra: solo el que tiene correo');
    T::ok(str_contains($p, 'seguimientos que tocan hoy'), 'la pestaña explica qué entra en la Lista de hoy');
};
