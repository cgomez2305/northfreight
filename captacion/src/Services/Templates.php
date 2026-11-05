<?php
/**
 * Plantillas de correo (Comunicaciones): CRUD, variables, marcadores y pie obligatorio (CAN-SPAM).
 *
 *  - Variables: {nombre} {equipo} {estado} {link_baja} {link_calculadora} {whatsapp}. Una variable desconocida que quede sin reemplazar cuenta como «sin resolver».
 *  - Marcadores [[CONFIRMAR: …]]: texto pendiente de confirmar. Una plantilla con un marcador (o variable) sin resolver NO se envia.
 *  - Pie (siempre, no editable): quien escribe, direccion fisica (Configuracion > Correo; sin ella no se envia ninguna secuencia) y enlace de baja de un clic.
 *  - Solo texto plano: se escapa todo; el HTML del correo lo arma render().
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Db;

final class Templates
{
    public const LANGS = ['en' => 'Inglés', 'es' => 'Español'];
    public const SITE = 'https://northstarfreightsolutions.us';
    public const WHATSAPP = '+1 863-651-3322';   // el del sitio (CLAUDE.md §8.3)
    private static bool $ensured = false;

    /** Siembra las plantillas de fabrica que falten (INSERT IGNORE por clave+idioma). No pisa lo que Carlos editó. */
    public static function ensure(): void
    {
        if (self::$ensured) {
            return;
        }
        foreach (TemplateDefaults::all() as $t) {
            Db::run('INSERT IGNORE INTO email_templates (tkey, lang, name, subject, body, kind, active) VALUES (?, ?, ?, ?, ?, ?, 1)', [$t['tkey'], $t['lang'], $t['name'], $t['subject'], $t['body'], 'system']);
        }
        self::$ensured = true;
    }

    /** Solo pruebas: vuelve a sembrar en la proxima llamada. */
    public static function resetEnsure(): void
    {
        self::$ensured = false;
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM email_templates WHERE id = ?', [$id]);
    }

    public static function byKey(string $tkey, string $lang): ?array
    {
        self::ensure();
        return Db::one('SELECT * FROM email_templates WHERE tkey = ? AND lang = ?', [$tkey, $lang]) ?? ($lang !== 'en' ? Db::one('SELECT * FROM email_templates WHERE tkey = ? AND lang = ?', [$tkey, 'en']) : null);
    }

    /** @return list<array> */
    public static function all(): array
    {
        self::ensure();
        return Db::all('SELECT * FROM email_templates ORDER BY (kind = \'system\') DESC, tkey, lang');
    }

    // --- Variables y marcadores -----------------------------------------------------------------------------------------

    public static function vars(array $lead, string $lang, string $unsubUrl): array
    {
        $eq = array_map(static fn($t) => Catalog::label(Catalog::EQUIPMENT, (string) $t), (array) ($lead['equipment_types'] ?? []));
        $first = trim((string) ($lead['first_name'] ?? ''));
        return [
            '{nombre}' => $first !== '' ? $first : ($lang === 'es' ? 'amigo' : 'there'),
            '{equipo}' => $eq ? implode(', ', $eq) : ($lang === 'es' ? 'tu equipo' : 'your equipment'),
            '{estado}' => (string) ($lead['state'] ?? ''),
            '{link_baja}' => $unsubUrl,
            '{link_calculadora}' => self::SITE . '/resources/rate-per-mile-calculator/',
            '{whatsapp}' => self::WHATSAPP,
        ];
    }

    /** Marcadores [[…]] y variables {xxx} que siguen sin resolver en un texto ya interpolado. @return list<string> */
    public static function unresolved(string $s): array
    {
        preg_match_all('/\[\[[^\]]{1,200}\]\]|\{[a-z_]{2,30}\}/', $s, $m);
        return array_values(array_unique($m[0]));
    }

    public static function interpolate(string $s, array $vars): string
    {
        return strtr($s, $vars);
    }

    // --- Pie obligatorio ----------------------------------------------------------------------------------------------------

    public static function address(): string
    {
        $a = Settings::get('mail', []);
        $a = is_array($a) ? $a : [];
        return trim(is_string($a['address'] ?? null) ? $a['address'] : '');
    }

    /** @return array{text:string,html:string} */
    public static function footer(string $lang, string $unsubUrl): array
    {
        $addr = self::address();
        $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($lang === 'es') {
            $why = 'Recibes este correo porque pediste información sobre dispatch con NorthStar.';
            $un = 'Darte de baja con un clic';
        } else {
            $why = 'You are receiving this because you asked about dispatch with NorthStar.';
            $un = 'Unsubscribe in one click';
        }
        $addrTxt = $addr !== '' ? $addr : ($lang === 'es' ? '[dirección física sin configurar]' : '[physical address not configured]');
        return [
            'text' => "\n--\nNorthStar Freight Solutions\n" . $addrTxt . "\n" . $why . "\n" . $un . ': ' . $unsubUrl . "\n",
            'html' => '<hr style="border:0;border-top:1px solid #E1E7EF;margin:24px 0 12px"><p style="margin:0;font-size:12px;line-height:1.5;color:#586A80">NorthStar Freight Solutions<br>' . nl2br($e($addrTxt)) . '<br>' . $e($why)
                . ' <a href="' . $e($unsubUrl) . '" style="color:#036E8A">' . $e($un) . '</a></p>',
        ];
    }

    /**
     * Genera asunto, texto y HTML de una plantilla para un lead (o un lead de muestra).
     * @return array{subject:string,text:string,html:string,unresolved:list<string>}
     */
    public static function render(array $tpl, array $lead, string $lang, string $unsubUrl): array
    {
        $vars = self::vars($lead, $lang, $unsubUrl);
        $subject = (string) preg_replace('/\s+/', ' ', self::interpolate((string) $tpl['subject'], $vars));
        $body = self::interpolate((string) $tpl['body'], $vars);
        $unres = self::unresolved($subject . "\n" . $body);
        $f = self::footer($lang, $unsubUrl);
        $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $paras = array_map(static fn($p) => '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#0A1B2E">' . nl2br($e(trim($p))) . '</p>', array_filter(preg_split('/\n{2,}/', trim($body)) ?: [], static fn($p) => trim($p) !== ''));
        $html = '<!doctype html><html lang="' . $e($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($subject) . '</title></head>'
            . '<body style="margin:0;background:#F1F4F8;font-family:Arial,Helvetica,sans-serif"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#FFFFFF;border:1px solid #E1E7EF;border-radius:14px"><tr><td style="padding:28px">'
            . implode('', $paras) . $f['html'] . '</td></tr></table></td></tr></table></body></html>';
        return ['subject' => $subject, 'text' => trim($body) . "\n" . $f['text'], 'html' => $html, 'unresolved' => $unres];
    }

    // --- CRUD ---------------------------------------------------------------------------------------------------------------

    /** @return array{ok:bool,errors:array<string,string>,values:array} */
    public static function validate(array $in, bool $creating): array
    {
        $err = [];
        $name = self::line($in['name'] ?? '', 120);
        if (mb_strlen($name) < 3) {
            $err['name'] = 'Escribe un nombre de al menos 3 caracteres.';
        }
        $lang = is_string($in['lang'] ?? null) && isset(self::LANGS[$in['lang']]) ? $in['lang'] : '';
        if ($lang === '') {
            $err['lang'] = 'Elige el idioma.';
        }
        $subject = self::line($in['subject'] ?? '', 190);
        if (mb_strlen($subject) < 3) {
            $err['subject'] = 'Escribe un asunto de al menos 3 caracteres.';
        }
        $body = is_string($in['body'] ?? null) ? str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], trim($in['body'])) : '';
        $body = (string) preg_replace('/[^\P{C}\n\t]/u', '', $body);
        if (mb_strlen($body) < 20 || mb_strlen($body) > 8000) {
            $err['body'] = 'El mensaje debe tener entre 20 y 8.000 caracteres.';
        }
        if (preg_match('/<\s*\/?\s*[a-z][^>]*>/i', $subject . $body) === 1) {
            $err['body'] = 'Solo texto: sin etiquetas HTML.';
        }
        $tkey = '';
        if ($creating) {
            $tkey = self::slug((string) ($in['tkey'] ?? '') !== '' ? (string) $in['tkey'] : $name);
            if ($tkey === '') {
                $err['name'] = $err['name'] ?? 'Ese nombre no sirve para crear la clave de la plantilla.';
            } elseif (Db::value('SELECT COUNT(*) FROM email_templates WHERE tkey = ? AND lang = ?', [$tkey, $lang]) > 0) {
                $err['name'] = 'Ya existe una plantilla con esa clave en ese idioma.';
            }
        }
        return ['ok' => !$err, 'errors' => $err, 'values' => ['tkey' => $tkey, 'lang' => $lang, 'name' => $name, 'subject' => $subject, 'body' => $body, 'active' => ($in['active'] ?? '1') === '1']];
    }

    private static function line(mixed $v, int $max): string
    {
        $s = is_string($v) ? trim((string) preg_replace('/[\r\n\t]+/', ' ', $v)) : '';
        return mb_substr((string) preg_replace('/[^\P{C}]/u', '', $s), 0, $max);
    }

    public static function slug(string $s): string
    {
        $s = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s));
        return trim(substr((string) preg_replace('/[^a-z0-9]+/', '_', $s), 0, 40), '_');
    }

    public static function create(array $v, int $userId): int
    {
        $id = Db::insert('email_templates', ['tkey' => $v['tkey'], 'lang' => $v['lang'], 'name' => $v['name'], 'subject' => $v['subject'], 'body' => $v['body'], 'kind' => 'custom', 'active' => $v['active'] ? 1 : 0, 'updated_by' => $userId]);
        ActivityLog::record('template_create', $userId, 'template', $id, ['tkey' => $v['tkey'], 'lang' => $v['lang']]);
        return $id;
    }

    public static function update(int $id, array $v, int $userId): void
    {
        $before = self::find($id);
        Db::update('email_templates', $id, ['name' => $v['name'], 'subject' => $v['subject'], 'body' => $v['body'], 'active' => $v['active'] ? 1 : 0, 'updated_by' => $userId]);
        ActivityLog::record('template_update', $userId, 'template', $id, ['tkey' => $before['tkey'] ?? null, 'lang' => $before['lang'] ?? null, 'subject_changed' => ($before['subject'] ?? '') !== $v['subject'], 'body_changed' => ($before['body'] ?? '') !== $v['body']]);
    }

    public static function delete(int $id, int $userId): array
    {
        $t = self::find($id);
        if ($t === null) {
            return ['ok' => false, 'msg' => 'Esa plantilla no existe.'];
        }
        if ($t['kind'] === 'system') {
            return ['ok' => false, 'msg' => 'Las plantillas de fábrica no se borran: desactívala o restáurala.'];
        }
        Db::run('DELETE FROM email_templates WHERE id = ?', [$id]);
        ActivityLog::record('template_delete', $userId, 'template', $id, ['tkey' => $t['tkey'], 'lang' => $t['lang']]);
        return ['ok' => true, 'msg' => 'Plantilla eliminada.'];
    }

    /** Vuelve una plantilla de fabrica a su texto original. */
    public static function restore(int $id, int $userId): array
    {
        $t = self::find($id);
        if ($t === null || $t['kind'] !== 'system') {
            return ['ok' => false, 'msg' => 'Solo se restauran las plantillas de fábrica.'];
        }
        foreach (TemplateDefaults::all() as $d) {
            if ($d['tkey'] === $t['tkey'] && $d['lang'] === $t['lang']) {
                Db::update('email_templates', $id, ['name' => $d['name'], 'subject' => $d['subject'], 'body' => $d['body'], 'updated_by' => $userId]);
                ActivityLog::record('template_restore', $userId, 'template', $id, ['tkey' => $t['tkey'], 'lang' => $t['lang']]);
                return ['ok' => true, 'msg' => 'Plantilla restaurada al texto original.'];
            }
        }
        return ['ok' => false, 'msg' => 'No hay texto original para esa plantilla.'];
    }

    /** Lead de muestra para vista previa y pruebas. */
    public static function sampleLead(string $lang): array
    {
        return ['first_name' => $lang === 'es' ? 'Luis' : 'Mike', 'state' => 'TX', 'equipment_types' => ['dry-van', 'reefer']];
    }
}
