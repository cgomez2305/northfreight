<?php
/** Destino 1: correo a NorthStar con la ficha completa (HTML y texto) y los botones «Firmó» / «Lo rechazamos». Destino: Configuracion > Entrega (handoff_email). */

declare(strict_types=1);

namespace Captacion\Services\Handoff;

use Captacion\Services\Handoff;
use Captacion\Services\HandoffCard;
use Captacion\Services\Mailer;

final class EmailTarget implements HandoffTarget
{
    public function key(): string
    {
        return 'email';
    }

    public function enabled(): bool
    {
        return Handoff::config()['email'] !== '';
    }

    public function send(array $card, array $handoff): array
    {
        $to = Handoff::config()['email'];
        if ($to === '') {
            throw new \RuntimeException('Destino de correo sin configurar.');
        }
        $test = ($handoff['reason'] ?? '') === 'test';
        $subject = ($test ? '[PRUEBA] ' : '') . 'Lead presentado a NorthStar: ' . $card['name'] . ' (' . $card['profile_label'] . ', ' . $card['score'] . '/100)' . (($handoff['version'] ?? 1) > 1 ? ' · reenvío' : '');
        $r = Mailer::deliver([
            'to' => $to, 'subject' => $subject, 'text' => HandoffCard::text($card, $handoff), 'html' => HandoffCard::html($card, $handoff),
            'reply_to' => Mailer::to(), 'from_name' => 'NorthStar Captación',
        ]);
        if ($r !== 'sent') {   // sin SMTP no salio nada: no se puede dar la entrega por hecha (queda en reintento y luego «fallida», visible en el panel)
            throw new \RuntimeException('Sin SMTP configurado: la ficha quedó solo en storage/mail.log y no se envió.');
        }
        return ['ok' => true, 'code' => null, 'msg' => 'Correo enviado a ' . $to . '.'];
    }
}
