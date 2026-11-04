<?php
/** Exportacion a CSV: UTF-8 con BOM (Excel), separador coma y proteccion contra formulas (una celda que empieza por = + - @ tab o CR se prefija con ' ). */

declare(strict_types=1);

namespace Captacion\Services;

final class Csv
{
    public static function cell(mixed $v): string
    {
        $s = is_array($v) ? implode('; ', array_map('strval', $v)) : (string) ($v ?? '');
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $s = "'" . $s;
        }
        if (preg_match('/[",\n]/', $s) === 1) {
            $s = '"' . str_replace('"', '""', $s) . '"';
        }
        return $s;
    }

    public static function line(array $cells): string
    {
        return implode(',', array_map([self::class, 'cell'], $cells)) . "\r\n";
    }

    /** Cuerpo completo del CSV de leads. @param list<array> $rows filas de LeadQuery::exportRows */
    public static function leads(array $rows): string
    {
        $out = "\xEF\xBB\xBF" . self::line([
            'ID', 'Recibido (' . Fmt::zone() . ')', 'Nombre', 'Perfil', 'Teléfono', 'Correo', 'Estado', 'Idioma', 'Equipo', 'Camiones', 'Años de experiencia', 'USDOT',
            'Puntuación', 'Temperatura', 'Etapa', 'Motivo de pérdida', 'Fuente', 'Página de llegada', 'Primer contacto (' . Fmt::zone() . ')',
        ]);
        foreach ($rows as $r) {
            $out .= self::line([
                $r['id'], Fmt::et((string) $r['created_at'])?->format('Y-m-d H:i') ?? '', Fmt::name($r), Catalog::label(Catalog::PROFILES, $r['profile']),
                $r['phone_e164'], $r['email'], $r['state'], Catalog::label(Catalog::LANGS, $r['language']), \Captacion\Db::decode($r['equipment_types']),
                $r['truck_count'], $r['years_experience'], $r['usdot'], $r['score'], Catalog::label(Catalog::TEMPS, $r['temperature']), Catalog::stageAny($r['stage']),
                $r['lost_reason'] !== null ? Catalog::label(Catalog::LOSS_REASONS, $r['lost_reason']) : '', Catalog::source($r['source']), $r['landing_page'],
                $r['first_contact_at'] !== null ? (Fmt::et((string) $r['first_contact_at'])?->format('Y-m-d H:i') ?? '') : '',
            ]);
        }
        return $out;
    }
}
