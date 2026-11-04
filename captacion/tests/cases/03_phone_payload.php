<?php
declare(strict_types=1);

use Captacion\Services\Disposable;
use Captacion\Services\LeadPayload;
use Captacion\Services\Phone;
use CaptacionTests\T;

return static function (array $ctx): void {
    // Telefonos validos de EE. UU.
    $valid = [
        '(863) 555-0101' => '+18635550101', '863-555-0101' => '+18635550101', '863.555.0101' => '+18635550101', '8635550101' => '+18635550101',
        '+1 863 555 0101' => '+18635550101', '+18635550101' => '+18635550101', '1-863-555-0101' => '+18635550101', '18635550101' => '+18635550101',
        '  (305) 234-5678  ' => '+13052345678', '863 555 0101 ext 12' => '+18635550101', '8635550101 x5' => '+18635550101', '(212) 555-0199' => '+12125550199',
    ];
    foreach ($valid as $in => $out) {
        $r = Phone::normalize((string) $in);
        T::ok($r['ok'] && $r['e164'] === $out && !$r['intl'], "telefono valido '$in' -> $out");
    }
    // Invalidos
    $invalid = [
        '' => 'empty', '   ' => 'empty', '555-0101' => 'length', '123456789' => 'length', '863555010' => 'length', '86355501011' => 'length', '+1 863 555 010' => 'length',
        '123-456-7890' => 'nanp_leading_digit', '863-155-0101' => 'nanp_leading_digit', '911-555-0101' => 'nanp_area_code', '863-911-0101' => 'nanp_exchange',
        '290-555-0101' => 'nanp_area_code', '000-000-0000' => 'nanp_leading_digit', '222-222-2222' => 'repeated_digits', 'abc' => 'chars', '863-555-01O1' => 'chars',
        '863+555+0101' => 'chars', '+0 863 555 0101' => 'length', '+44' => 'length', '(863) 555-0101; DROP TABLE' => 'chars',
    ];
    foreach ($invalid as $in => $reason) {
        $r = Phone::normalize((string) $in);
        T::ok(!$r['ok'] && $r['e164'] === null && $r['reason'] === $reason, "telefono invalido '$in' ($reason)" . (!$r['ok'] ? '' : ' -> acepto ' . $r['e164']) . ($r['reason'] !== $reason ? ' [razon: ' . $r['reason'] . ']' : ''));
    }
    T::ok(!Phone::normalize(null)['ok'] && !Phone::normalize(8635550101)['ok'], 'telefono no-string -> invalido');
    $intl = Phone::normalize('+52 55 1234 5678');
    T::ok($intl['ok'] && $intl['intl'] && $intl['e164'] === '+525512345678', 'numero internacional (+52) aceptado con intl=true');
    $intl2 = Phone::normalize('011 52 55 1234 5678');
    T::ok($intl2['ok'] && $intl2['intl'] && $intl2['e164'] === '+525512345678', 'prefijo 011 -> +52...');

    // Disposable
    Disposable::reload();
    $lines = array_filter(array_map('trim', file($ctx['root'] . '/config/disposable-domains.txt') ?: []), static fn($l) => $l !== '' && $l[0] !== '#');
    T::ok(count($lines) >= 100, 'lista de dominios desechables: ' . count($lines) . ' (>= 100)');
    T::ok(Disposable::isDisposable('x@mailinator.com') && Disposable::isDisposable('x@Guerrillamail.com') && Disposable::isDisposable('x@sub.yopmail.com'), 'dominios desechables (incl. mayusculas y subdominios) detectados');
    T::ok(!Disposable::isDisposable('x@gmail.com') && !Disposable::isDisposable('x@northstarfreightsolutions.us') && !Disposable::isDisposable('x@notmailinator.org'), 'dominios normales no se bloquean');

    // Normalizacion del payload
    $n = LeadPayload::normalize([
        'profile' => 'owner-operator', 'name' => "  Maria   Perez\r\nBcc: x@y.com ", 'phone' => '863 555 0101', 'email' => ' MARIA@Example.COM ', 'state' => 'florida',
        'language' => 'ES', 'cdl_standard' => 'yes', 'english' => 'basic', 'years' => '4', 'trucks' => '2', 'equipment' => ['Dry Van', 'amazon_relay', 'dry-van', '<script>'],
        'authority' => 'active', 'usdot' => 'USDOT 1234567', 'job' => 'My Job!', 'attribution' => ['utm_source' => 'google', 'landing' => '/careers/', 'referrer' => 'https://g.co/'],
        'consent' => ['given' => true, 'text' => "texto\x00 exacto", 'version' => 'v1'],
    ]);
    $l = $n['lead'];
    T::eq($n['errors'], [], 'payload valido: sin errores');
    T::eq($l['profile'], 'owner_operator', 'perfil owner-operator -> owner_operator');
    T::eq([$l['first_name'], $l['last_name']], ['Maria', 'Perez Bcc: x@y.com'], 'nombre: espacios y saltos de linea saneados, separado en nombre/apellido');
    T::eq($l['email'], 'maria@example.com', 'correo en minusculas y recortado');
    T::eq($l['phone_e164'], '+18635550101', 'telefono a E.164');
    T::eq($l['state'], 'FL', 'estado "florida" -> FL');
    T::eq([$l['language'], $l['cdl_type'], $l['english_level'], $l['years_experience'], $l['truck_count']], ['es', 'standard', 'basic', 4, 2], 'idioma, CDL (yes->standard), ingles, anos, camiones');
    T::eq($l['equipment_types'], ['dry-van', 'amazon-relay', 'script'], 'equipo: slugs normalizados, sin duplicados ni caracteres raros');
    T::eq([$l['usdot'], $l['authority_status'], $l['job_slug']], ['1234567', 'active', 'my-job'], 'USDOT solo digitos, autoridad, vacante');
    T::eq([$l['utm']['utm_source'] ?? null, $l['landing_page'], $l['referrer']], ['google', '/careers/', 'https://g.co/'], 'atribucion');
    T::eq([$l['consent']['given'], $l['consent']['text']], [true, 'texto exacto'], 'consentimiento: texto sin caracteres de control');

    $map = ['yes' => 'standard', 'no' => 'non_domiciled', 'unsure' => 'unsure'];
    foreach ($map as $in => $out) {
        T::eq(LeadPayload::normalize(['profile' => 'small-fleet', 'name' => 'A B', 'phone' => '8635550101', 'cdl_standard' => $in])['lead']['cdl_type'], $out, "cdl_standard '$in' -> cdl_type '$out'");
    }
    T::eq(LeadPayload::normalize(['profile' => 'small-fleet', 'name' => 'A B', 'phone' => '8635550101'])['lead']['profile'], 'small_carrier', 'small-fleet -> small_carrier');
    T::eq(LeadPayload::normalize(['profile' => 'company-driver', 'name' => 'A B', 'phone' => '8635550101'])['lead']['profile'], 'company_driver', 'company-driver -> company_driver');

    // Errores
    T::ok(isset(LeadPayload::normalize(['name' => 'A B', 'phone' => '8635550101'])['errors']['profile']), 'sin perfil -> error profile');
    T::ok(isset(LeadPayload::normalize(['profile' => 'bogus', 'name' => 'A', 'phone' => '8635550101'])['errors']['profile']), 'perfil desconocido -> error');
    T::ok(isset(LeadPayload::normalize(['profile' => 'owner_operator', 'phone' => '8635550101'])['errors']['name']), 'sin nombre -> error name');
    T::ok(isset(LeadPayload::normalize(['profile' => 'owner_operator', 'name' => 'A'])['errors']['contact']), 'sin telefono ni correo -> error contact');
    T::ok(isset(LeadPayload::normalize(['profile' => 'owner_operator', 'name' => 'A', 'phone' => '123'])['errors']['contact']), 'telefono invalido y sin correo -> error contact (no hay como contactarlo)');
    $keep = LeadPayload::normalize(['profile' => 'owner_operator', 'name' => 'A', 'phone' => '123-456-7890', 'email' => 'a@b.com']);
    T::ok($keep['errors'] === [] && $keep['lead']['phone_e164'] === null && in_array('phone_invalid', $keep['flags'], true) && $keep['lead']['phone_raw'] === '123-456-7890', 'telefono invalido pero con correo: el lead se conserva (phone_e164 NULL, bandera phone_invalid)');
    T::ok(isset(LeadPayload::normalize(['profile' => 'owner_operator', 'name' => 'A', 'phone' => '8635550101', 'email' => 'no-es-correo'])['errors']['email']), 'correo con formato invalido -> error email');
    T::ok(isset(LeadPayload::normalize(['profile' => 'owner_operator', 'name' => 'A', 'phone' => '8635550101', 'email' => "a@b.com\nBcc: x@y.com"])['errors']['email']), 'correo con salto de linea -> error');
    $odd = LeadPayload::normalize(['profile' => 'owner_operator', 'name' => 'A', 'phone' => '8635550101', 'years' => '-3', 'trucks' => '99999', 'state' => 'Atlantis', 'usdot' => '123456789012']);
    T::ok($odd['lead']['years_experience'] === null && $odd['lead']['truck_count'] === null && $odd['lead']['state'] === null && $odd['lead']['usdot'] === null && in_array('state_invalid', $odd['flags'], true) && in_array('usdot_invalid', $odd['flags'], true), 'valores fuera de rango: se ignoran con bandera, el lead no se pierde');
    $big = LeadPayload::normalize(['profile' => 'owner_operator', 'name' => str_repeat('x', 500), 'phone' => '8635550101', 'landing_page' => str_repeat('/a', 400)]);
    T::ok(mb_strlen($big['lead']['first_name']) <= 100 && mb_strlen((string) $big['lead']['landing_page']) <= 300, 'longitudes limitadas');
};
