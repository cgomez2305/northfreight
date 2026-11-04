<?php
/**
 * Correo de avisos a Carlos (email_notify). Usa PHPMailer del sitio (vendor/ de la raiz del repo) SOLO si existe y hay SMTP_* en el .env;
 * si no, deja el mensaje completo en storage/mail.log (el job cuenta como hecho: el aviso queda registrado). El CRM no depende de PHPMailer.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Env;

final class Mailer
{
    public static function smtpConfigured(): bool
    {
        $pass = Env::get('SMTP_PASS') ?? '';
        return (Env::get('SMTP_HOST') ?? '') !== '' && (Env::get('SMTP_USER') ?? '') !== '' && $pass !== '' && $pass !== 'CHANGE-ME';
    }

    public static function to(): string
    {
        $to = trim((string) Env::get('MAIL_TO', ''));
        return filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : '';
    }

    /** @return string 'sent' | 'logged' */
    public static function send(string $subject, string $body): string
    {
        $subject = (string) preg_replace('/\s+/', ' ', $subject);
        $to = self::to();
        $autoload = Env::get('PHPMAILER_AUTOLOAD') ?? dirname(CAPTACION_ROOT) . '/vendor/autoload.php';
        if ($to !== '' && self::smtpConfigured() && is_file($autoload)) {
            require_once $autoload;
            if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
                $m = new \PHPMailer\PHPMailer\PHPMailer(true);
                $m->isSMTP();
                $m->Host = (string) Env::get('SMTP_HOST');
                $m->Port = Env::int('SMTP_PORT', 465);
                $m->SMTPAuth = true;
                $m->Username = (string) Env::get('SMTP_USER');
                $m->Password = (string) Env::get('SMTP_PASS');
                $secure = strtolower((string) Env::get('SMTP_SECURE', 'ssl'));
                $m->SMTPSecure = $secure === 'tls' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : ($secure === 'none' ? '' : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS);
                $m->SMTPAutoTLS = $secure !== 'none';
                $m->Timeout = 12;
                $m->CharSet = 'UTF-8';
                $m->setFrom(filter_var((string) Env::get('SMTP_USER'), FILTER_VALIDATE_EMAIL) ? (string) Env::get('SMTP_USER') : $to, 'NorthStar captacion');
                $m->addAddress($to);
                $m->isHTML(false);
                $m->Subject = $subject;
                $m->Body = $body;
                $m->send();   // si falla lanza: el job se reintenta
                Storage::log('mail', '[sent] to=' . $to . ' subject=' . $subject);
                return 'sent';
            }
        }
        $lines = array_map(static fn($l) => '  | ' . $l, preg_split('/\n/', $body) ?: []);
        Storage::log('mail', '[logged: ' . ($to === '' ? 'MAIL_TO vacio' : (self::smtpConfigured() ? 'PHPMailer no disponible' : 'SMTP sin configurar')) . '] subject: ' . $subject . "\n" . implode("\n", $lines));
        return 'logged';
    }
}
