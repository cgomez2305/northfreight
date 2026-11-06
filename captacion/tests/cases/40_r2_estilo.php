<?php
declare(strict_types=1);

use Captacion\Services\Fmt;
use CaptacionTests\T;

/**
 * Fase R2 (pulido del panel): reglas de estilo que no se pueden olvidar al tocar una hoja y la presentación legible de razones sociales.
 *  - Ninguna hoja del panel (salvo panel.css, donde viven los tokens) trae colores fijos, degradados, filtros de fondo ni sombras.
 *  - Ningún var(--x) apunta a una variable que no existe; sin Barlow, sin mayúsculas forzadas por CSS ni espaciado de etiquetas mono.
 *  - Los selects nativos llevan la flecha propia (token) y los tokens de las dos paletas la definen.
 *  - Fmt::company pasa «NORTH FORK CARRIERS LLC» a «North Fork Carriers LLC» sin tocar nombres que ya traen minúsculas ni las siglas.
 */
return static function (array $ctx): void {
    $dir = $ctx['root'] . '/public/assets/';
    $files = glob($dir . '*.css') ?: [];
    $strip = static fn(string $c): string => (string) preg_replace('#/\*.*?\*/#s', '', $c);
    $defs = [];
    $uses = [];
    foreach ($files as $f) {
        $c = $strip((string) file_get_contents($f));
        $name = basename($f);
        preg_match_all('/(--[a-z0-9-]+)\s*:/', $c, $m);
        foreach ($m[1] as $v) {
            $defs[$v] = true;
        }
        preg_match_all('/var\((--[a-z0-9-]+)/', $c, $m);
        foreach ($m[1] as $v) {
            $uses[$v][$name] = true;
        }
    }
    $undef = [];
    foreach ($uses as $v => $where) {
        if (!isset($defs[$v])) {
            $undef[] = $v . ' (' . implode(', ', array_keys($where)) . ')';
        }
    }
    T::ok($undef === [], 'ninguna variable CSS sin definir' . ($undef ? ' · FALTAN: ' . implode('; ', $undef) : ''));

    $bad = [];
    foreach ($files as $f) {
        $name = basename($f);
        $c = $strip((string) file_get_contents($f));
        if (preg_match('/Barlow|text-transform\s*:\s*uppercase/i', $c)) {
            $bad[] = "$name: Barlow o mayúsculas forzadas";
        }
        if ($name === 'panel.css') {
            continue;
        }
        if (preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $c)) {
            $bad[] = "$name: color fijo fuera de los tokens";
        }
        if (preg_match('/backdrop-filter|-webkit-backdrop-filter|(linear|radial|conic)-gradient/', $c)) {
            $bad[] = "$name: degradado o filtro de fondo";
        }
        // sombras: solo el aro de 1 px del elemento activo o seleccionado, el token de flotantes y los anillos de arrastre/foco
        if (preg_match_all('/box-shadow\s*:\s*([^;}]+)/', $c, $mm)) {
            foreach ($mm[1] as $sh) {
                if (!preg_match('/^\s*(var\(--shadow-pop\)|none|inset 0 0 0 1px [^,]+|0 0 0 1px [^,]+|1px 0 0 var\(--line\))\s*(!important)?\s*$/', $sh)) {
                    $bad[] = "$name: sombra no permitida «" . trim($sh) . '»';
                }
            }
        }
    }
    T::ok($bad === [], 'hojas por pantalla: solo variables, sin degradados, sin sombras pesadas ni mayúsculas forzadas' . ($bad ? ' · ' . implode(' | ', $bad) : ''));

    $panel = $strip((string) file_get_contents($dir . 'panel.css'));
    T::ok(substr_count($panel, '--sel-arrow:') === 2 && preg_match('/\nselect\{[^}]*appearance:none[^}]*background-image:var\(--sel-arrow\)/', $panel) === 1, 'selects nativos: flecha propia por token en claro y oscuro');
    T::ok(!str_contains($panel, 'backdrop-filter') && !preg_match('/gradient/', $panel), 'panel.css sin degradados ni filtros de fondo');

    T::ok(Fmt::company('NORTH FORK CARRIERS LLC') === 'North Fork Carriers LLC', 'razón social: mayúsculas de FMCSA a frase con LLC intacta');
    T::ok(Fmt::company('JKL TRUCKING INC') === 'JKL Trucking Inc' && Fmt::company('DM TRANS CO') === 'DM Trans Co', 'razón social: siglas sin vocales se conservan');
    T::ok(Fmt::company("MARIA'S HAULING & SONS INC") === "Maria's Hauling & Sons Inc" && Fmt::company("O'BRIEN EXPRESS LLC") === "O'Brien Express LLC", 'razón social: apóstrofes');
    T::ok(Fmt::company('Smith Trucking LLC') === 'Smith Trucking LLC' && Fmt::company('') === '' && Fmt::company('K-9 FREIGHT II') === 'K-9 Freight II', 'razón social: lo que ya trae minúsculas, vacío y numerales');
};
