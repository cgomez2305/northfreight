<?php
declare(strict_types=1);

use CaptacionTests\T;

/** Q4b-D: el paquete de integracion (captacion/docs): flujos de n8n validos y sin secretos, y los documentos que prometio el brief. */
return static function (array $ctx): void {
    $dir = $ctx['root'] . '/docs';
    $expected = ['a1-bienvenida-whatsapp', 'a4-enlace-calcom', 'a5-recordatorios-whatsapp', 'a6-secuencia-whatsapp', 'a7-pausa-al-responder', 'entrega-google-sheets'];
    $files = array_map(static fn($f) => basename($f, '.json'), glob($dir . '/n8n/*.json') ?: []);
    sort($files);
    $want = $expected;
    sort($want);
    T::eq($files, $want, 'n8n: los 6 flujos (A1, A4, A5, A6, A7 y entrega a Google Sheets)');
    foreach ($expected as $key) {
        $raw = (string) file_get_contents("$dir/n8n/$key.json");
        $d = json_decode($raw, true);
        T::ok(is_array($d) && json_last_error() === JSON_ERROR_NONE, "n8n/$key.json es JSON valido");
        if (!is_array($d)) {
            continue;
        }
        $names = array_column($d['nodes'], 'name');
        $ids = array_column($d['nodes'], 'id');
        $badConn = [];
        foreach ($d['connections'] as $from => $c) {
            if (!in_array($from, $names, true)) {
                $badConn[] = $from;
            }
            foreach ($c['main'] as $outs) {
                foreach ($outs as $o) {
                    if (!in_array($o['node'], $names, true)) {
                        $badConn[] = $o['node'];
                    }
                }
            }
        }
        T::ok(count($names) === count(array_unique($names)) && count($ids) === count(array_unique($ids)) && !$badConn && ($d['active'] ?? true) === false, "  nodos con nombre e id unicos, conexiones que apuntan a nodos reales y flujo inactivo al importar" . ($badConn ? ' (' . implode(',', $badConn) . ')' : ''));
        $types = array_column($d['nodes'], 'type');
        T::ok(count(array_filter($types, static fn($t) => str_starts_with($t, 'n8n-nodes-base.'))) === count($types) && count(array_filter($d['nodes'], static fn($n) => is_numeric($n['typeVersion']))) === count($d['nodes']), '  tipos de nodo de n8n-nodes-base con typeVersion numerico');
        T::ok(str_contains($raw, 'NO PROBADO'), '  marcado «NO PROBADO en una instancia real de n8n»');
        $cred = 0;
        foreach ($d['nodes'] as $n) {
            foreach ($n['credentials'] ?? [] as $c) {
                $cred += str_starts_with((string) $c['id'], 'REEMPLAZAR_') ? 1 : 1000;
            }
        }
        T::ok($cred < 1000, '  las credenciales son marcadores REEMPLAZAR_… (ningun id real)');
        $secret = preg_match('/Bearer\s+[A-Za-z0-9_\-]{12,}|EAA[A-Za-z0-9]{20,}|\b[0-9a-f]{32,}\b|tok_[a-z0-9]{10,}|AC[0-9a-f]{32}|sk-[A-Za-z0-9]{20,}/', $raw) === 1;
        T::ok(!$secret, '  sin secretos: ni tokens, ni claves, ni cabeceras Bearer con valor');
        T::ok(!preg_match('/"(value|secret|token|password|apiKey)"\s*:\s*"(?!REEMPLAZAR|PEGAR|https?:|=|\s*")[A-Za-z0-9+\/=_-]{24,}"/', $raw), '  ningun valor largo sospechoso en parametros');
    }
    $docs = ['whatsapp-templates.md', 'bot-whatsapp-spec.md', 'setup-guide.md', 'compliance.md', 'job-boards.md'];
    foreach ($docs as $f) {
        $t = (string) @file_get_contents("$dir/$f");
        T::ok(strlen($t) > 2000, "docs/$f existe y no esta vacio (" . strlen($t) . ' bytes)');
        T::ok(!preg_match('/claude' . '-0|scratch' . 'pad|\/t' . 'mp\//', $t) && !preg_match('/tok_[a-f0-9]{10,}|[0-9a-f]{40,}/', $t), "  docs/$f sin residuos de pruebas ni secretos");
    }
    $tpl = (string) file_get_contents("$dir/whatsapp-templates.md");
    preg_match_all('/```\n(.*?)\n```/s', $tpl, $blocks);
    $texts = implode("\n", $blocks[1]);
    T::ok(substr_count($tpl, '_en') >= 8 && substr_count($tpl, '_es') >= 8 && count($blocks[1]) >= 14 && !preg_match('/\d+\s?%|\$\s?\d|guarantee|garant[ií]a|24\/7|within \d+ (hours|minutes)/i', $texts), 'plantillas de WhatsApp: cada una en EN y ES (14 textos), sin porcentajes, garantias ni tiempos de respuesta');
    T::ok(str_contains($tpl, 'UTILITY') && str_contains($tpl, 'MARKETING') && str_contains($tpl, 'NO enviar todavía'), '  con categoria propuesta y las que NO se envian a aprobacion');
    $spec = (string) file_get_contents("$dir/bot-whatsapp-spec.md");
    foreach (['nacionalidad', 'estatus migratorio', '24 h', 'Prompt de sistema', 'consent-check', 'handoff-ready', 'STOP'] as $w) {
        T::ok(str_contains($spec, $w), "bot: la especificacion cubre «{$w}»");
    }
    $guide = (string) file_get_contents("$dir/setup-guide.md");
    foreach (['WhatsApp Cloud API', 'Meta Lead Ads', 'Cal.com', 'Twilio', 'A2P 10DLC', 'n8n Cloud', 'SMTP', 'META_VERIFY_TOKEN', 'CALCOM_WEBHOOK_SECRET', 'TWILIO_TOKEN', 'N8N_WEBHOOK_SECRET', '/api/webhooks/meta.php', 'Probar'] as $w) {
        T::ok(str_contains($guide, $w), "guia de conexion: menciona «{$w}»");
    }
    $c = (string) file_get_contents("$dir/compliance.md");
    foreach (['TCPA', 'A2P 10DLC', 'WhatsApp Business Policy', 'CAN-SPAM', 'abogado'] as $w) {
        T::ok(str_contains($c, $w), "cumplimiento: cubre «{$w}»");
    }
    T::ok(str_contains((string) file_get_contents("$dir/job-boards.md"), 'Send me my results') && str_contains((string) file_get_contents("$dir/job-boards.md"), 'IMAP'), 'job-boards.md: diseño de bolsas de empleo y propuesta de «Send me my results»');
    foreach (glob($dir . '/*.md') ?: [] as $f) {
        T::ok(!preg_match('/META_PAGE_TOKEN=\S|TWILIO_TOKEN=\S|INTERNAL_API_TOKEN=\S/', (string) file_get_contents($f)), 'docs/' . basename($f) . ': ninguna variable del .env con valor');
    }
};
