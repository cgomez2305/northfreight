<?php
/**
 * Destino de una entrega a NorthStar (doc §11). Cada implementacion recibe la ficha ya armada (HandoffCard::build) y los datos de la entrega, y devuelve el
 * resultado; si falla lanza \RuntimeException (la cola reintenta a 1, 5 y 15 min). Hoy: correo y webhook firmado; DispatchCrmTarget es el esqueleto del CRM grande.
 */

declare(strict_types=1);

namespace Captacion\Services\Handoff;

interface HandoffTarget
{
    /** Clave estable guardada en handoff_deliveries.target. */
    public function key(): string;

    /** ¿Esta configurado y activo? Si no, no se crea entrega para este destino. */
    public function enabled(): bool;

    /**
     * @param array $card     ficha (HandoffCard::build)
     * @param array $handoff  fila de handoffs (id, version, reason…) + 'urls' => ['signed' => url, 'rejected' => url] (vacios en las pruebas)
     * @return array{ok:bool,code:?int,msg:string}
     * @throws \RuntimeException si el destino rechaza o no responde
     */
    public function send(array $card, array $handoff): array;
}
