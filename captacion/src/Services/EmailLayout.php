<?php
/**
 * Diseño HTML de los correos a leads y prospectos (fase AT): secuencias, envíos manuales desde la ficha y campañas en lote. Lo usa Templates::render.
 *
 * Pensado para que se lea bien en cualquier cliente de correo (Gmail, Outlook, Apple Mail, el móvil):
 *  - una sola columna de 600 px, tablas y estilos en línea (los clientes de correo ignoran casi todo el CSS), sin imágenes (muchos las bloquean);
 *  - la marca en texto encima de la tarjeta, cuerpo a 16 px con interlineado amplio y párrafos separados;
 *  - una línea que solo lleva un enlace de la plantilla ({link_calculadora}, {link_web}, {link_referido}, {cita_enlace}) se convierte en un BOTÓN con un
 *    texto que dice qué hace, en lugar de una URL larga; la fecha de una cita ({cita_fecha} sola en su párrafo) va destacada en un recuadro;
 *  - las URL sueltas y el número de WhatsApp son enlaces; «- » al empezar una línea hace una lista y **texto** pone negrita;
 *  - texto de vista previa oculto (lo que el cliente de correo muestra junto al asunto) y el pie legal fuera de la tarjeta, en gris y pequeño.
 * El texto plano del correo no cambia: lo sigue armando Templates::render.
 */

declare(strict_types=1);

namespace Captacion\Services;

final class EmailLayout
{
    private const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    private const INK = '#1F2933';
    private const MUTED = '#6B7280';
    private const LINK = '#0A6F8C';
    private const NAVY = '#0A1D33';
    private const LINE = '#E5E7EB';
    private const BG = '#F3F4F6';

    /** Texto de cada botón según la variable de la plantilla. */
    private const BUTTONS = [
        'link_calculadora' => ['en' => 'Open the rate per mile calculator', 'es' => 'Abrir la calculadora de rate per mile'],
        'link_web' => ['en' => 'Visit NorthStar Freight Solutions', 'es' => 'Visitar NorthStar Freight Solutions'],
        'link_referido' => ['en' => 'Open your referral link', 'es' => 'Abrir tu enlace de referido'],
        'cita_enlace' => ['en' => 'Join the call', 'es' => 'Entrar a la llamada'],
    ];

    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param string $rawBody cuerpo de la plantilla SIN interpolar (para reconocer las líneas que son solo un enlace)
     * @param array<string,string> $vars variables ya resueltas
     */
    public static function html(string $lang, string $subject, string $rawBody, array $vars, string $footerHtml, ?string $pixel = null): string
    {
        $blocks = [];
        $preheader = '';
        foreach (preg_split('/\n{2,}/', trim(str_replace("\r\n", "\n", $rawBody))) ?: [] as $para) {
            $text = [];
            $flush = static function () use (&$text, &$blocks, &$preheader, $vars): void {
                if ($text) {
                    $plain = trim(Templates::interpolate(implode("\n", $text), $vars));
                    if ($plain !== '' && preg_match('/^NorthStar Freight Solutions\.?$/u', $plain) === 1) {   // la firma
                        $blocks[] = '<p style="margin:0;font:600 16px/1.5 ' . self::FONT . ';color:' . self::INK . '">' . self::e($plain) . '</p>';
                    } elseif ($plain !== '') {
                        $blocks[] = self::paragraph($plain);
                        if ($preheader === '' && !preg_match('/^(hi|hello|hola|buenas)\b[^.\n]{0,40},?$/iu', $plain)) {   // el saludo no sirve de vista previa
                            $preheader = $plain;
                        }
                    }
                    $text = [];
                }
            };
            foreach (explode("\n", $para) as $line) {
                // «{link_x}» solo, «Link: {link_x}» o «…tu enlace personal: {link_x}» al final de la línea: el texto queda y el enlace pasa a botón
                if (preg_match('/^(.*?)\s*\{(link_calculadora|link_web|link_referido|cita_enlace)\}\s*$/u', $line, $m) === 1 && ($m[1] === '' || preg_match('/^(?:link|enlace)\s*:$/iu', trim($m[1])) === 1 || str_ends_with(trim($m[1]), ':'))) {
                    $m = [$m[0], $m[2], trim($m[1])];
                    $url = trim($vars['{' . $m[1] . '}'] ?? '');
                    if (preg_match('#^https?://\S+$#', $url) === 1) {
                        if ($m[2] !== '' && preg_match('/^(?:link|enlace)\s*:$/iu', $m[2]) !== 1) {
                            $text[] = $m[2];
                        }
                        $flush();
                        $blocks[] = self::button(self::BUTTONS[$m[1]][$lang === 'es' ? 'es' : 'en'], $url, $m[1] === 'link_referido');
                        continue;
                    }
                }
                if (preg_match('/^\s*\{cita_fecha\}\s*$/', $line) === 1 && isset($vars['{cita_fecha}'])) {
                    $flush();
                    $blocks[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px"><tr><td style="padding:14px 18px;background:' . self::BG . ';border-radius:8px;font:600 17px/1.4 ' . self::FONT . ';color:' . self::INK . '">'
                        . self::e((string) $vars['{cita_fecha}']) . '</td></tr></table>';
                    continue;
                }
                $text[] = $line;
            }
            $flush();
        }
        $pre = mb_substr((string) preg_replace('/\s+/', ' ', $preheader), 0, 110);
        $px = $pixel !== null && $pixel !== '' ? '<img src="' . self::e($pixel) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0">' : '';
        return '<!doctype html><html lang="' . self::e($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
            . '<title>' . self::e($subject) . '</title><style>@media (max-width:620px){.ns-card{padding:28px 22px!important}.ns-pad{padding-left:22px!important;padding-right:22px!important}.ns-btn{display:block!important;text-align:center!important}}</style></head>'
            . '<body style="margin:0;padding:0;background:' . self::BG . ';-webkit-text-size-adjust:100%">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:' . self::BG . ';mso-hide:all">' . self::e($pre) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . self::BG . '"><tr><td align="center" style="padding:32px 12px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px">'
            . self::logoRow(40)
            . '<tr><td class="ns-card" style="padding:34px 40px 30px;background:#FFFFFF;border:1px solid ' . self::LINE . ';border-top:0;border-radius:0 0 12px 12px">' . implode('', $blocks) . '</td></tr>'
            . '<tr><td class="ns-pad" style="padding:20px 40px 0;font:400 12px/1.6 ' . self::FONT . ';color:' . self::MUTED . '">' . $footerHtml . '</td></tr>'
            . '</table>' . $px . '</td></tr></table></body></html>';
    }

    /**
     * Logo REAL de la web (isotipo + NORTHSTAR + FREIGHT SOLUTIONS, capturado del encabezado del sitio a 3×) en una franja navy, como en el sitio.
     * PNG (Gmail y Outlook no muestran SVG) servido desde el dominio: assets/img/email/logo-white.png. Con las imágenes bloqueadas se lee el texto alternativo en blanco.
     */
    public static function logoRow(int $padX, string $sub = ''): string
    {
        return '<tr><td class="ns-pad" style="padding:22px ' . $padX . 'px;background:' . self::NAVY . ';border-radius:12px 12px 0 0">'
            . '<img src="' . Templates::SITE . '/assets/img/email/logo-white.png" width="210" height="60" alt="NorthStar Freight Solutions" style="display:block;width:210px;height:60px;border:0;outline:none;text-decoration:none;color:#FFFFFF;font:700 18px ' . self::FONT . '">'
            . ($sub !== '' ? '<p style="margin:10px 0 0;font:500 12px ' . self::FONT . ';letter-spacing:.06em;color:#B9C8D8">' . self::e($sub) . '</p>' : '')
            . '</td></tr>';
    }

    /** Párrafo con listas («- »), negritas (**texto**), URL y WhatsApp como enlaces. */
    private static function paragraph(string $plain): string
    {
        $lines = explode("\n", $plain);
        $out = '';
        $buf = [];
        $list = [];
        $pStyle = 'margin:0 0 18px;font:400 16px/1.65 ' . self::FONT . ';color:' . self::INK;
        $endP = static function () use (&$buf, &$out, $pStyle): void {
            if ($buf) {
                $out .= '<p style="' . $pStyle . '">' . implode('<br>', $buf) . '</p>';
                $buf = [];
            }
        };
        $endL = static function () use (&$list, &$out): void {
            if ($list) {
                $out .= '<ul style="margin:0 0 18px;padding:0 0 0 20px;font:400 16px/1.65 ' . self::FONT . ';color:' . self::INK . '">' . implode('', array_map(static fn($li) => '<li style="margin:0 0 6px">' . $li . '</li>', $list)) . '</ul>';
                $list = [];
            }
        };
        foreach ($lines as $l) {
            if (preg_match('/^\s*[-•]\s+(.+)$/u', $l, $m) === 1) {
                $endP();
                $list[] = self::inline($m[1]);
            } else {
                $endL();
                $buf[] = self::inline(trim($l));
            }
        }
        $endP();
        $endL();
        return $out;
    }

    private static function inline(string $s): string
    {
        $h = self::e($s);
        $h = (string) preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $h);
        $h = (string) preg_replace_callback('#https?://[^\s<>"]+#', static function (array $m): string {
            $url = rtrim($m[0], '.,);:');
            $tail = substr($m[0], strlen($url));
            return '<a href="' . $url . '" style="color:' . self::LINK . ';text-decoration:underline;word-break:break-all">' . $url . '</a>' . $tail;
        }, $h);
        $wa = self::e(Templates::WHATSAPP);
        if (str_contains($h, $wa)) {
            $h = str_replace($wa, '<a href="https://wa.me/' . preg_replace('/\D/', '', Templates::WHATSAPP) . '" style="color:' . self::LINK . ';text-decoration:underline;white-space:nowrap">' . $wa . '</a>', $h);
        }
        return $h;
    }

    private static function button(string $label, string $url, bool $showUrl): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:4px 0 ' . ($showUrl ? '8' : '22') . 'px"><tr><td style="border-radius:8px;background:' . self::NAVY . '">'
            . '<a class="ns-btn" href="' . self::e($url) . '" style="display:inline-block;padding:13px 22px;font:600 15px/1.2 ' . self::FONT . ';color:#FFFFFF;text-decoration:none;border-radius:8px">' . self::e($label) . '</a></td></tr></table>'
            . ($showUrl ? '<p style="margin:0 0 22px;font:400 13px/1.5 ' . self::FONT . ';color:' . self::MUTED . ';word-break:break-all">' . self::e($url) . '</p>' : '');
    }
}
