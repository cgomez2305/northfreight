<?php
/**
 * Destino 3 (ESQUELETO, NO ACTIVO): el CRM de dispatch de NorthStar, hoy en pausa (doc §11, "Mañana"). Cuando exista:
 *   1. enabled() devuelve true si hay DISPATCH_CRM_URL + DISPATCH_CRM_TOKEN en el .env y DISPATCH_CRM_ENABLED=1;
 *   2. send() hace POST firmado (mismo esquema y firma que WebhookTarget) a {DISPATCH_CRM_URL}/api/leads con la ficha y lee la respuesta {"id": "..."};
 *   3. ese id se guarda en leads.external_crm_id (columna ya existente) y el CRM devuelve el estado (firmado/rechazado) por webhook a /api/webhooks/dispatch-crm (a construir en Q4),
 *      que llamara a Handoff::confirm(..., 'crm').
 * Solo cambia este archivo y el registro en Handoff::targets(); el resto del sistema queda igual. Hoy enabled() es siempre false y send() lanza.
 */

declare(strict_types=1);

namespace Captacion\Services\Handoff;

final class DispatchCrmTarget implements HandoffTarget
{
    public function key(): string
    {
        return 'dispatch_crm';
    }

    public function enabled(): bool
    {
        return false;   // NO ACTIVO: ver la cabecera
    }

    public function send(array $card, array $handoff): array
    {
        throw new \RuntimeException('DispatchCrmTarget no está activo (el CRM de dispatch está en pausa).');
    }
}
