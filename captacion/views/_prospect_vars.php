<?php
use Captacion\Services\Fmt;
use Captacion\Services\ProspectConfig;
use Captacion\Services\Prospects;
use Captacion\Services\UsTimeZones;

/** Fase AU: datos derivados de un prospecto ($r) que usan la fila de la lista y el Modo llamada. Necesita $r, $now, $history, $dayFmt. */
    $id = (int) $r['id'];
    $name = Fmt::company((string) ($r['legal_name'] ?: ($r['dba'] ?: 'USDOT ' . $r['usdot'])));
    $tz = UsTimeZones::check($r['state'], $now);
    $dnc = (int) $r['do_not_call'] === 1 || $r['status'] === 'dnc';
    $conv = $r['converted_lead_id'] !== null;
    $open = !$dnc && !$conv;
    $cats = ProspectConfig::categoriesOf($r['cargo']);
    $hist = $history[$id] ?? [];
    $closed = in_array($r['status'], ['no_answer_closed', 'not_interested'], true);
    $statusChip = ['to_call' => 'warm', 'no_answer' => 'plain', 'no_answer_closed' => 'dq', 'talked' => 'plain', 'interested' => 'ok', 'not_interested' => 'dq', 'dnc' => 'bad'][$r['status']] ?? 'plain';
    $nextLabel = $r['next_call_at'] !== null && $open ? ((int) ($r['callback_exact'] ?? 0) === 1 ? Prospects::localLabel((string) $r['state'], (string) $r['next_call_at']) : $dayFmt((string) $r['next_call_at'])) : null;
    $callNow = $open && $r['phone'] !== null && $tz['allowed'];
    $replied = $open && !empty($r['email_replied_at']) && ($r['last_call_at'] === null || (string) $r['last_call_at'] < (string) $r['email_replied_at']);   // fase AT: respondió a un correo y aún no se le ha llamado
    $loc = trim(($r['city'] ? ucwords(strtolower((string) $r['city'])) . ', ' : '') . ($r['state'] ?? ''), ' ,') ?: '—';
    $prio = Prospects::priorityInfo($r, $now);
