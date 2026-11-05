<?php
/**
 * Filtros de los prospectos FMCSA (Configuracion > Prospectos). Un solo ajuste JSON `prospects.config`; sin valor se usan DEFAULTS.
 *   enabled          la corrida diaria busca prospectos nuevos (apagarla no borra nada)
 *   max_age_months   antiguedad maxima de la autoridad (1 a 24)
 *   states           [] = todo EE. UU.; si no, solo esos estados
 *   max_fleet        unidades motrices maximas (1 a 100); un dato de flota ausente NO descarta
 *   require_phone    solo carriers con telefono valido
 *   cargo            [] = cualquiera; si no, categorias de CARGO (basta con una); si el filtro esta activo y FMCSA no trae banderas de carga, se descarta
 *   daily_cap        tope de prospectos NUEVOS por dia (hora de Nueva York), 1 a 500
 * FMCSA publica la carga que declara el carrier (banderas crgo_*), no el tipo de equipo: dry van, reefer o flatbed no existen como dato.
 */

declare(strict_types=1);

namespace Captacion\Services;

final class ProspectConfig
{
    public const DEFAULTS = ['enabled' => true, 'max_age_months' => 12, 'states' => [], 'max_fleet' => 10, 'require_phone' => true, 'cargo' => [], 'daily_cap' => 40];

    /** categoria => [etiqueta, banderas crgo_* del censo (sin el prefijo)] */
    public const CARGO = [
        'general'  => ['Carga general', ['genfreight']],
        'cold'     => ['Refrigerada y alimentos', ['produce', 'coldfood', 'meat', 'beverages']],
        'heavy'    => ['Construcción y carga pesada', ['bldgmat', 'metalsheet', 'logpole', 'machlrg', 'construct']],
        'vehicles' => ['Vehículos y casas móviles', ['motoveh', 'drivetow', 'mobilehome']],
        'bulk'     => ['Graneles y líquidos', ['liqgas', 'chem', 'drybulk', 'grainfeed', 'coalcoke', 'oilfield']],
        'other'    => ['Otra carga', ['household', 'intermodal', 'paperprod', 'garbage', 'usmail', 'farmsupp', 'utility', 'waterwell', 'livestock', 'cargoothr']],
    ];

    /** @return array{enabled:bool,max_age_months:int,states:list<string>,max_fleet:int,require_phone:bool,cargo:list<string>,daily_cap:int} */
    public static function get(): array
    {
        $v = Settings::get('prospects.config', []);
        $v = is_array($v) ? $v : [];
        $out = self::DEFAULTS;
        foreach (self::normalize($v)['clean'] as $k => $x) {
            $out[$k] = $x;
        }
        return $out;
    }

    /**
     * Valida un formulario o un JSON guardado. 'clean' solo trae las claves validas; 'errors' = [campo => mensaje].
     * @return array{clean:array,errors:array<string,string>}
     */
    public static function normalize(array $in): array
    {
        $clean = [];
        $err = [];
        if (array_key_exists('enabled', $in)) {
            $clean['enabled'] = filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('require_phone', $in)) {
            $clean['require_phone'] = filter_var($in['require_phone'], FILTER_VALIDATE_BOOLEAN);
        }
        $int = static function (string $k, int $min, int $max, string $label) use ($in, &$clean, &$err): void {
            if (!array_key_exists($k, $in)) {
                return;
            }
            $raw = is_string($in[$k]) ? trim($in[$k]) : $in[$k];
            if (is_int($raw) || (is_string($raw) && preg_match('/^\d{1,4}$/', $raw) === 1)) {
                $n = (int) $raw;
                if ($n >= $min && $n <= $max) {
                    $clean[$k] = $n;
                    return;
                }
            }
            $err[$k] = $label . ' debe ser un número entre ' . $min . ' y ' . $max . '.';
        };
        $int('max_age_months', 1, 24, 'La antigüedad máxima (meses)');
        $int('max_fleet', 1, 100, 'La flota máxima');
        $int('daily_cap', 1, 500, 'El tope diario');
        if (array_key_exists('states', $in)) {
            $list = is_array($in['states']) ? $in['states'] : (is_string($in['states']) ? preg_split('/[\s,;]+/', $in['states']) : []);
            $states = [];
            foreach ((array) $list as $s) {
                $s = strtoupper(trim((string) $s));
                if ($s === '') {
                    continue;
                }
                if (!isset(UsStates::NAMES[$s])) {
                    $err['states'] = 'Estado no válido: ' . mb_substr($s, 0, 12) . '.';
                    continue;
                }
                $states[$s] = true;
            }
            $clean['states'] = array_keys($states);
        }
        if (array_key_exists('cargo', $in)) {
            $cargo = [];
            foreach ((array) $in['cargo'] as $c) {
                if (is_string($c) && isset(self::CARGO[$c])) {
                    $cargo[$c] = true;
                } else {
                    $err['cargo'] = 'Categoría de carga no válida.';
                }
            }
            $clean['cargo'] = array_keys($cargo);
        }
        return ['clean' => $clean, 'errors' => $err];
    }

    /** Categorias de carga (claves de CARGO) que cubren las banderas dadas. @param list<string> $flags sin prefijo crgo_ */
    public static function categoriesOf(array $flags): array
    {
        $out = [];
        foreach (self::CARGO as $cat => [, $keys]) {
            if (array_intersect($keys, $flags)) {
                $out[] = $cat;
            }
        }
        return $out;
    }
}
