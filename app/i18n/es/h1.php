<?php
/**
 * Fase AF: los H1 españoles con acento {hl} salen de routes.es.php ('h1_hl'). Sus trozos ya están en español: se registran como
 * texto que se queda igual (valor = clave) para que el traductor y la auditoría no los traten como inglés sin traducir.
 */
$out = [];
foreach ((array) require __DIR__ . '/../routes.es.php' as $m) {
    if (!empty($m['h1_hl'])) {
        foreach (preg_split('/\{\/?hl\}/', (string) $m['h1_hl']) as $piece) {
            $piece = trim(preg_replace('/\s+/', ' ', $piece));
            if ($piece !== '' && !in_array($piece, ['owner-operators', 'IFTA'], true)) {   // ya viven en words2.php y lote4.php
                $out[$piece] = $piece;
            }
        }
    }
}
return $out;
