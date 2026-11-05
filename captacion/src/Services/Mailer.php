<?php
/**
 * Correo del CRM. Usa PHPMailer del sitio (vendor/ de la raiz del repo) SOLO si existe y hay SMTP_* en el .env; si no, deja el mensaje completo en storage/mail.log
 * ("registrado": nadie lo recibe) y la interfaz lo dice. El CRM no depende de PHPMailer.
 *
 *  - send($asunto, $texto): aviso a Carlos (email_notify, prueba de SMTP). Destino: Configuracion > Alertas o MAIL_TO.
 *  - deliver([...]): correo a cualquier destinatario (entrega a NorthStar, informes, plantillas, secuencias) con HTML opcional, cabeceras y Reply-To.
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

    /** ¿Se puede entregar de verdad (SMTP configurado y PHPMailer disponible)? Si no, todo queda «registrado» en storage/mail.log. */
    public static function canDeliver(): bool
    {
        return self::smtpConfigured() && is_file(self::autoload());
    }

    private static function autoload(): string
    {
        return Env::get('PHPMAILER_AUTOLOAD') ?? dirname(CAPTACION_ROOT) . '/vendor/autoload.php';
    }

    public static function to(): string
    {
        return Alerts::mailTo();   // el de Configuracion > Alertas o, si esta vacio, MAIL_TO del .env
    }

    /** Remitente: SMTP_USER si es un correo; si no, el destinatario de avisos. */
    public static function fromAddress(): string
    {
        $u = (string) Env::get('SMTP_USER', '');
        return filter_var($u, FILTER_VALIDATE_EMAIL) ? $u : self::to();
    }

    /** @return string 'sent' | 'logged' */
    public static function send(string $subject, string $body): string
    {
        return self::deliver(['to' => self::to(), 'subject' => $subject, 'text' => $body, 'from_name' => 'NorthStar captacion']);
    }

    /**
     * @param array{to:string,subject:string,text:string,html?:string,headers?:array<string,string>,reply_to?:string,from_name?:string} $m
     * @return string 'sent' | 'logged' (lanza si el SMTP rechaza: quien llama decide si reintenta)
     */
    public static function deliver(array $m): string
    {
        $subject = (string) preg_replace('/\s+/', ' ', (string) $m['subject']);
        $to = trim((string) $m['to']);
        $headers = [];
        foreach (($m['headers'] ?? []) as $k => $v) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9-]{1,40}$/', (string) $k) === 1) {
                $headers[(string) $k] = (string) preg_replace('/[\r\n]+/', ' ', (string) $v);   // sin saltos de linea: nada de inyeccion de cabeceras
            }
        }
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Destinatario de correo no valido.');
        }
        if ($to !== '' && self::canDeliver()) {
            require_once self::autoload();
            if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = (string) Env::get('SMTP_HOST');
                $mail->Port = Env::int('SMTP_PORT', 465);
                $mail->SMTPAuth = true;
                $mail->Username = (string) Env::get('SMTP_USER');
                $mail->Password = (string) Env::get('SMTP_PASS');
                $secure = strtolower((string) Env::get('SMTP_SECURE', 'ssl'));
                $mail->SMTPSecure = $secure === 'tls' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : ($secure === 'none' ? '' : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS);
                $mail->SMTPAutoTLS = $secure !== 'none';
                $mail->Timeout = 12;
                $mail->CharSet = 'UTF-8';
                try {
                    $mail->setFrom(self::fromAddress() !== '' ? self::fromAddress() : $to, (string) ($m['from_name'] ?? 'NorthStar captacion'));
                    $mail->addAddress($to);
                    if (!empty($m['reply_to']) && filter_var($m['reply_to'], FILTER_VALIDATE_EMAIL)) {
                        $mail->addReplyTo((string) $m['reply_to']);
                    }
                    foreach ($headers as $k => $v) {
                        $mail->addCustomHeader($k, $v);
                    }
                    $mail->Subject = $subject;
                    if (!empty($m['html'])) {
                        $mail->isHTML(true);
                        $mail->Body = (string) $m['html'];
                        $mail->AltBody = (string) $m['text'];
                    } else {
                        $mail->isHTML(false);
                        $mail->Body = (string) $m['text'];
                    }
                    $mail->send();   // si falla lanza
                } catch (\Throwable $t) {
                    Integrations::record('smtp', false, $t->getMessage());
                    throw new \RuntimeException(Storage::redact($t->getMessage()));
                }
                Integrations::record('smtp', true);
                Storage::log('mail', '[sent] to=' . $to . ' subject=' . $subject);
                return 'sent';
            }
        }
        $lines = array_map(static fn($l) => '  | ' . $l, preg_split('/\n/', (string) $m['text']) ?: []);
        $hdr = $headers ? '  | headers: ' . implode(' ; ', array_map(static fn($k, $v) => $k . ': ' . $v, array_keys($headers), $headers)) . "\n" : '';
        Storage::log('mail', '[logged: ' . ($to === '' ? 'destinatario vacio' : (self::smtpConfigured() ? 'PHPMailer no disponible' : 'SMTP sin configurar')) . '] to=' . ($to !== '' ? $to : '-') . ' subject: ' . $subject . "\n" . $hdr . implode("\n", $lines));
        return 'logged';
    }
}
