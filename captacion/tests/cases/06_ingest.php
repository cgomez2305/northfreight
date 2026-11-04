<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Models\Lead;
use Captacion\Services\Consent;
use Captacion\Services\Fmcsa;
use Captacion\Services\JobHandlers;
use Captacion\Services\LeadIngest;
use Captacion\Services\Queue;
use CaptacionTests\T;

return static function (array $ctx): void {
    $fx = static fn(string $n, string $grant = '2025-01-01') => str_replace('__GRANT__', $grant, (string) file_get_contents($ctx['root'] . '/tests/fixtures/fmcsa/' . $n . '.json'));
    $fm = static function (string $carrier, string $authority = 'authority_active', ?string $grant = null) use ($fx): Fmcsa {
        $grant ??= gmdate('Y-m-d', strtotime('-12 months -2 days'));
        return new Fmcsa(static function (string $url) use ($fx, $carrier, $authority, $grant): array {
            return str_contains($url, '/authority') ? ['status' => 200, 'body' => $fx($authority, $grant)] : ['status' => 200, 'body' => $fx($carrier)];
        }, 'K');
    };
    $base = [
        'profile' => 'owner-operator', 'name' => 'Maria Perez', 'phone' => '(863) 555-0101', 'email' => 'maria@example.com', 'state' => 'FL', 'language' => 'es',
        'cdl_standard' => 'yes', 'english' => 'yes', 'years' => '4', 'trucks' => '2', 'equipment' => ['dry-van'], 'authority' => 'active', 'contact_pref' => 'whatsapp', 'best_time' => 'morning',
        'attribution' => ['utm_source' => 'google', 'landing' => '/careers/owner-operators/'],
        'consent' => ['given' => true, 'text' => 'TEXTO EXACTO DEL CONSENTIMIENTO v1', 'version' => 'v1', 'at' => gmdate('c'), 'ip' => '203.0.113.9', 'user_agent' => 'UA-Test/1.0'],
    ];
    $count = static fn(string $t, string $where = '1=1') => (int) Db::value("SELECT COUNT(*) FROM $t WHERE $where");
    $jobs = static fn(string $type = '%') => Db::all('SELECT * FROM jobs WHERE type LIKE ? ORDER BY id', [$type]);
    $events = static fn(int $id) => array_column(Db::all('SELECT type FROM lead_events WHERE lead_id = ? ORDER BY id', [$id]), 'type');

    // ---------------------------------------------------------------- crear
    T::reset();
    $r = LeadIngest::handle($base, 'web_careers', ['ip' => '198.51.100.1']);
    T::ok($r['ok'] && $r['status'] === 'created' && $r['score'] === 65 && $r['temperature'] === 'warm', 'lead nuevo: created, 65 puntos, tibio');
    $lead = Lead::find((int) $r['lead_id']);
    T::eq([$lead['first_name'], $lead['last_name'], $lead['phone_e164'], $lead['email'], $lead['state'], $lead['language']], ['Maria', 'Perez', '+18635550101', 'maria@example.com', 'FL', 'es'], 'lead guardado y normalizado');
    T::eq([$lead['profile'], $lead['pipeline'], $lead['stage'], $lead['source'], $lead['attempt']], ['owner_operator', 'A', 'new', 'web_careers', 1], 'perfil, pipeline A, etapa new, fuente, intento 1');
    T::eq([$lead['cdl_type'], $lead['english_level'], $lead['years_experience'], $lead['truck_count'], $lead['equipment_types'], $lead['authority_status']], ['standard', 'yes', 4, 2, ['dry-van'], 'active'], 'datos de elegibilidad y operacion');
    T::eq([$lead['utm']['utm_source'] ?? null, $lead['landing_page']], ['google', '/careers/owner-operators/'], 'atribucion guardada');
    T::ok(is_array($lead['score_breakdown']['items'] ?? null) && $lead['score_breakdown']['score'] === 65, 'desglose de la puntuacion en score_breakdown');
    T::eq($events((int) $lead['id']), ['created', 'scored'], 'eventos: created, scored');
    $cs = Db::all('SELECT * FROM consents WHERE lead_id = ? ORDER BY channel', [$lead['id']]);
    T::eq(array_column($cs, 'channel'), ['call', 'email', 'sms'], 'consentimiento: una fila por canal cubierto (call, email, sms)');
    T::ok($cs[0]['consent_text'] === 'TEXTO EXACTO DEL CONSENTIMIENTO v1' && $cs[0]['ip'] === '203.0.113.9' && $cs[0]['user_agent'] === 'UA-Test/1.0' && $cs[0]['revoked_at'] === null && $cs[0]['consent_version'] === 'v1', 'consentimiento: texto exacto, IP, user agent, version y fecha');
    $j = $jobs();
    T::ok(count($j) === 1 && $j[0]['type'] === 'email_notify' && $j[0]['status'] === 'pending', 'lead tibio: solo email_notify');

    // sin consentimiento: no se crean filas
    T::reset();
    $r = LeadIngest::handle(['consent' => ['given' => false]] + $base, 'web_careers');
    T::eq($count('consents'), 0, 'sin consentimiento dado: no hay filas en consents');

    // ---------------------------------------------------------------- caliente (+ FMCSA con fixtures)
    T::reset();
    $r = LeadIngest::handle(['usdot' => '1234567'] + $base, 'web_careers', ['fmcsa' => $fm('carrier_active')]);
    $lead = Lead::find((int) $r['lead_id']);
    T::ok($r['temperature'] === 'hot' && $r['score'] === 100, 'lead con FMCSA activa+seguro+12 meses: caliente (100)');
    T::ok(($lead['fmcsa_data']['authority_status'] ?? '') === 'active' && $lead['authority_age_months'] === 12 && $lead['fmcsa_checked_at'] !== null && $lead['usdot'] === '1234567', 'FMCSA guardada en el lead (estado, antiguedad, fecha de consulta)');
    T::eq($events((int) $lead['id']), ['created', 'fmcsa', 'scored'], 'eventos: created, fmcsa, scored');
    $j = $jobs();
    T::eq(array_column($j, 'type'), ['telegram_alert', 'telegram_alert_followup'], 'lead caliente: A2 inmediato + A3 programado');
    $followAt = (int) Clock::parse($j[1]['run_at']);
    T::ok(abs($followAt - (Clock::time() + 900)) <= 5 && Clock::parse($j[0]['run_at']) <= Clock::time(), 'A3 programado a 15 minutos; A2 inmediato');
    T::ok(!in_array('fmcsa_pending', $lead['flags'], true) && $count('jobs', "type = 'fmcsa_enrich'") === 0, 'FMCSA resuelta en linea: sin fmcsa_pending ni job fmcsa_enrich');

    // bandera roja
    T::reset();
    $r = LeadIngest::handle(['usdot' => '2345678'] + $base, 'web_careers', ['fmcsa' => $fm('carrier_inactive', 'authority_active')]);
    $lead = Lead::find((int) $r['lead_id']);
    T::ok(in_array('red:authority_inactive', $lead['flags'], true) && $r['score'] === 85, 'FMCSA inactiva: bandera roja red:authority_inactive; no suma los +15 de autoridad activa (seguro +10 y antiguedad +10 siguen sumando segun el doc: 85)');
    T::reset();
    $r = LeadIngest::handle(['usdot' => '3456789'] + $base, 'web_careers', ['fmcsa' => $fm('carrier_no_insurance')]);
    T::ok(in_array('red:no_insurance', Lead::find((int) $r['lead_id'])['flags'], true), 'FMCSA sin seguro: bandera roja red:no_insurance');

    // ---------------------------------------------------------------- frio y descartado
    T::reset();
    $cold = ['profile' => 'owner-operator', 'name' => 'Pedro Gil', 'phone' => '305-234-5678', 'cdl_standard' => 'yes', 'english' => 'yes', 'years' => '1'];
    $r = LeadIngest::handle($cold, 'web_careers');
    T::ok($r['temperature'] === 'cold' && $r['score'] === 35, 'lead frio: 35 puntos');
    T::eq(array_column($jobs(), 'type'), ['email_notify'], 'lead frio: solo email_notify');

    T::reset();
    foreach ([['cdl_standard' => 'no'], ['english' => 'no']] as $i => $over) {
        $r = LeadIngest::handle(['phone' => '30523456' . (70 + $i), 'email' => "dq$i@example.com"] + $over + $base, 'web_careers');
        $lead = Lead::find((int) $r['lead_id']);
        T::ok($r['ok'] && $r['temperature'] === 'disqualified' && $r['score'] === 0 && $lead['disqualified_reason'] !== null, 'descartado (' . key($over) . '): se guarda con temperatura disqualified y motivo ' . $lead['disqualified_reason']);
        T::ok(in_array('disqualified', $events((int) $lead['id']), true), '  evento disqualified registrado');
    }
    T::eq($count('jobs'), 0, 'descartados: no se encola ninguna accion (solo el evento)');
    T::eq($count('leads'), 2, 'descartados: quedan guardados para revision manual');
    T::reset();
    $r = LeadIngest::handle(['cdl_standard' => 'unsure'] + $base, 'web_careers');
    $lead = Lead::find((int) $r['lead_id']);
    T::ok($r['temperature'] === 'warm' && $lead['cdl_type'] === 'unsure' && in_array('cdl_unsure', $lead['flags'], true), 'CDL "no estoy seguro": unsure, bandera cdl_unsure, sin puntos y sin descartar');

    // Company driver: pipeline B
    T::reset();
    $r = LeadIngest::handle(['profile' => 'company-driver', 'trucks' => null, 'equipment' => [], 'years' => '5'] + $base, 'web_careers');
    $lead = Lead::find((int) $r['lead_id']);
    T::ok($lead['profile'] === 'company_driver' && $lead['pipeline'] === 'B' && $r['score'] === 65, 'company driver: pipeline B y tabla de drivers (65)');

    // ---------------------------------------------------------------- FMCSA pendiente
    T::reset();
    Env::set('FMCSA_WEBKEY', null);
    $r = LeadIngest::handle(['usdot' => '1234567'] + $base, 'web_careers');
    $lead = Lead::find((int) $r['lead_id']);
    T::ok($r['ok'] && in_array('fmcsa_pending', $lead['flags'], true) && $lead['fmcsa_data'] === [] && $r['score'] === 65, 'sin webKey: el lead entra igual (65), bandera fmcsa_pending');
    $j = $jobs('fmcsa_enrich');
    T::ok(count($j) === 1 && $j[0]['status'] === 'pending', 'sin webKey: job fmcsa_enrich encolado');
    // La API cae: mismo comportamiento
    T::reset();
    $down = new Fmcsa(static function (string $u): array { throw new RuntimeException('timeout'); }, 'K');
    $r = LeadIngest::handle(['usdot' => '1234567'] + $base, 'web_careers', ['fmcsa' => $down]);
    T::ok($r['ok'] && in_array('fmcsa_pending', Lead::find((int) $r['lead_id'])['flags'], true) && $count('jobs', "type = 'fmcsa_enrich'") === 1, 'API de FMCSA caida: lead guardado, fmcsa_pending y job encolado');
    $leadId = (int) $r['lead_id'];
    // El job se ejecuta mas tarde con la API de vuelta: enriquece, reevalua y dispara la alerta caliente
    JobHandlers::$fmcsa = $fm('carrier_active');
    $stats = Queue::runBatch(JobHandlers::all(), 5);
    JobHandlers::$fmcsa = null;
    $lead = Lead::find($leadId);
    T::ok(!in_array('fmcsa_pending', $lead['flags'], true) && $lead['temperature'] === 'hot' && $lead['score'] === 100, 'job fmcsa_enrich: quita fmcsa_pending y reevalua (100, caliente)');
    T::ok($count('jobs', "type = 'telegram_alert'") === 1 && $count('jobs', "type = 'telegram_alert_followup'") === 1, 'al pasar a caliente tras FMCSA se encolan A2 y A3');
    T::ok(in_array('fmcsa', $events($leadId), true) && $count('lead_events', "lead_id = $leadId AND type = 'scored'") === 2, 'eventos fmcsa y un segundo scored');

    // ---------------------------------------------------------------- deduplicacion
    T::reset();
    $first = LeadIngest::handle(['email' => null, 'trucks' => null] + $base, 'web_careers');
    $id = (int) $first['lead_id'];
    $score0 = (int) $first['score'];
    // Regla 1: reingreso (otro formato de telefono) -> evento + completar vacios + reevaluar
    $r = LeadIngest::handle(['phone' => '+1 863 555 0101', 'email' => 'maria@example.com', 'trucks' => '8', 'state' => 'GA', 'consent' => ['given' => true, 'text' => 'OTRO TEXTO', 'version' => 'v1']] + $base, 'web_careers');
    $lead = Lead::find($id);
    T::ok($r['status'] === 'reentry' && (int) $r['lead_id'] === $id && $count('leads') === 1, 'regla 1: mismo telefono -> reingreso, sin lead nuevo');
    T::ok($lead['email'] === 'maria@example.com' && $lead['truck_count'] === 8, 'regla 1: se completan los campos vacios (correo, camiones)');
    T::eq($lead['state'], 'FL', 'regla 1: NO se pisan los campos que ya tenian valor (estado FL)');
    T::ok(in_array('reentry', $events($id), true), 'regla 1: evento reentry');
    T::ok($lead['score'] > $score0, "regla 1: se recalcula la puntuacion ($score0 -> {$lead['score']})");
    T::eq($count('consents', "lead_id = $id"), 6, 'regla 1: el nuevo consentimiento se suma (3 + 3 filas)');
    T::ok($count('jobs', "type = 'email_notify'") >= 2, 'regla 1: aviso por correo del reingreso');
    // Regla 1 por correo (otro telefono)
    $r = LeadIngest::handle(['phone' => '305-234-5678', 'email' => 'MARIA@example.com'] + $base, 'web_careers');
    T::ok($r['status'] === 'reentry' && (int) $r['lead_id'] === $id && $count('leads') === 1, 'regla 1: mismo correo (otro telefono) -> reingreso');
    T::eq(Lead::find($id)['phone_e164'], '+18635550101', '  el telefono original no se pisa');
    // Reingreso que lo vuelve caliente
    T::reset();
    $a = LeadIngest::handle(['email' => null, 'trucks' => null, 'equipment' => []] + $base, 'web_careers');
    $aid = (int) $a['lead_id'];
    $jobsBefore = $count('jobs', "type = 'telegram_alert'");
    $r = LeadIngest::handle(['trucks' => '10', 'equipment' => ['reefer'], 'referral_code' => 'AMIGO'] + $base, 'web_careers');
    T::ok($r['status'] === 'reentry' && Lead::find($aid)['temperature'] === 'hot' && $jobsBefore === 0 && $count('jobs', "type = 'telegram_alert'") === 1, 'reingreso que sube a caliente: se encola la alerta A2');
    // Reingreso de un descartado sigue siendo descartado (no se pisan CDL/ingles)
    // Regla 2: perdido hace mas de 90 dias -> reabrir
    T::reset();
    $a = LeadIngest::handle($base, 'web_careers');
    $id = (int) $a['lead_id'];
    Db::update('leads', $id, ['stage' => 'lost', 'lost_reason' => 'no_contesta', 'lost_at' => Clock::shift(-100 * 86400), 'first_contact_at' => Clock::shift(-101 * 86400)]);
    $r = LeadIngest::handle(['years' => '6', 'equipment' => ['reefer', 'flatbed']] + $base, 'web_careers');
    $lead = Lead::find($id);
    T::ok($r['status'] === 'reopened' && (int) $r['lead_id'] === $id && $count('leads') === 1, 'regla 2: perdido hace 100 dias -> se reabre (mismo lead, nuevo intento)');
    T::ok($lead['stage'] === 'new' && $lead['lost_reason'] === null && $lead['lost_at'] === null && $lead['attempt'] === 2 && $lead['first_contact_at'] === null, 'regla 2: etapa new, sin motivo de perdida, intento 2, contacto reiniciado');
    T::ok($lead['years_experience'] === 6 && $lead['equipment_types'] === ['reefer', 'flatbed'], 'regla 2: los datos nuevos reemplazan a los viejos');
    T::ok(in_array('reopened', $events($id), true), 'regla 2: evento reopened');
    T::ok($count('jobs', "dedupe_key = 'email_notify:$id:2'") === 1, 'regla 2: acciones del intento 2 encoladas (clave propia por intento)');
    // Perdido hace poco: reingreso, sigue perdido
    T::reset();
    $a = LeadIngest::handle($base, 'web_careers');
    $id = (int) $a['lead_id'];
    Db::update('leads', $id, ['stage' => 'lost', 'lost_reason' => 'precio', 'lost_at' => Clock::shift(-30 * 86400)]);
    $r = LeadIngest::handle($base, 'web_careers');
    $lead = Lead::find($id);
    T::ok($r['status'] === 'reentry' && $lead['stage'] === 'lost' && $lead['attempt'] === 1, 'perdido hace 30 dias: reingreso, NO se reabre');
    // Regla 3: ya entregado como firmado -> evento + alerta, sin lead nuevo
    T::reset();
    $a = LeadIngest::handle($base, 'web_careers');
    $id = (int) $a['lead_id'];
    Db::run('DELETE FROM jobs');
    Db::update('leads', $id, ['stage' => 'signed', 'handoff_status' => 'signed', 'signed_at' => Clock::shift(-10 * 86400), 'handed_off_at' => Clock::shift(-20 * 86400)]);
    $r = LeadIngest::handle($base, 'web_careers');
    T::ok($r['status'] === 'signed_reentry' && (int) $r['lead_id'] === $id && $count('leads') === 1, 'regla 3: ya firmado -> sin lead nuevo');
    T::ok(in_array('reentry_signed', $events($id), true), 'regla 3: evento reentry_signed');
    $j = $jobs('telegram_alert');
    T::ok(count($j) === 1 && (Db::decode($j[0]['payload'])['kind'] ?? '') === 'signed_reentry', 'regla 3: alerta a Carlos (telegram_alert, kind signed_reentry)');
    LeadIngest::handle($base, 'web_careers');
    T::eq($count('jobs', "type = 'telegram_alert'"), 1, 'regla 3: una segunda entrada el mismo dia no duplica la alerta');
    T::eq(Lead::find($id)['stage'], 'signed', 'regla 3: el lead firmado no se toca');
    // Idempotencia por id del sitio
    T::reset();
    $a = LeadIngest::handle(['site_lead_id' => 'ld_abc123'] + $base, 'web_careers');
    $b = LeadIngest::handle(['site_lead_id' => 'ld_abc123', 'name' => 'Otro Nombre'] + $base, 'web_careers');
    T::ok($a['status'] === 'created' && $b['status'] === 'duplicate_id' && $a['lead_id'] === $b['lead_id'] && $count('leads') === 1 && $count('lead_events', "type = 'reentry'") === 0, 'mismo id de lead del sitio: duplicate_id, sin cambios (reimportacion idempotente)');
    // Telefono invalido con correo: se guarda sin telefono; despues otro envio con el mismo correo es reingreso
    T::reset();
    $a = LeadIngest::handle(['phone' => '123-456-7890'] + $base, 'web_careers');
    $lead = Lead::find((int) $a['lead_id']);
    T::ok($a['ok'] && $lead['phone_e164'] === null && $lead['phone_raw'] === '123-456-7890' && in_array('phone_invalid', $lead['flags'], true), 'telefono invalido con correo valido: el lead se guarda (phone_e164 NULL, phone_raw, bandera)');
    $b = LeadIngest::handle($base, 'web_careers');
    T::ok($b['status'] === 'reentry' && Lead::find((int) $a['lead_id'])['phone_e164'] === '+18635550101', 'reingreso por correo completa el telefono valido');

    // ---------------------------------------------------------------- antispam y validacion
    T::reset();
    $r = LeadIngest::handle(['website' => 'http://spam.example'] + $base, 'web_careers');
    T::ok(!$r['ok'] && $r['reason'] === 'honeypot' && $count('leads') === 0, 'honeypot: rechazado, no se guarda');
    $r = LeadIngest::handle(['email' => 'x@mailinator.com'] + $base, 'web_careers');
    T::ok(!$r['ok'] && $r['reason'] === 'disposable_email' && $count('leads') === 0, 'correo desechable: bloqueado, no se guarda');
    $r = LeadIngest::handle(['profile' => 'nope'] + $base, 'web_careers');
    T::ok(!$r['ok'] && $r['reason'] === 'invalid' && isset($r['errors']['profile']) && $count('leads') === 0, 'validacion: rechazado con errors[profile]');
    // limite de 5 por hora por IP (solo para el formulario web: public=true)
    T::reset();
    $res = [];
    for ($i = 0; $i < 7; $i++) {
        $res[] = LeadIngest::handle(['phone' => '30523456' . (10 + $i), 'email' => "r$i@example.com"] + $base, 'web_careers', ['ip' => '192.0.2.7', 'public' => true]);
    }
    T::eq(array_map(static fn($x) => $x['ok'] ? 'ok' : $x['reason'], $res), ['ok', 'ok', 'ok', 'ok', 'ok', 'rate_limited', 'rate_limited'], 'limite: 5 envios por hora por IP; el 6.o y el 7.o se rechazan');
    $r = LeadIngest::handle(['phone' => '3052345699', 'email' => 'otra@example.com'] + $base, 'web_careers', ['ip' => '192.0.2.8', 'public' => true]);
    T::ok($r['ok'], 'otra IP no se ve afectada');
    $r = LeadIngest::handle(['phone' => '3052345698', 'email' => 'api@example.com'] + $base, 'api', ['ip' => '192.0.2.7']);
    T::ok($r['ok'], 'fuentes con token (public=false) no pasan por el limite de 5/h');

    // modo silencioso (importaciones)
    T::reset();
    LeadIngest::handle(['usdot' => '1234567', 'referral_code' => 'X', 'trucks' => '8'] + $base, 'web_careers', ['quiet' => true, 'fmcsa_sync' => false, 'skip_antispam' => true]);
    T::eq(array_column($jobs(), 'type'), ['fmcsa_enrich'], 'quiet: sin alertas ni avisos; el enriquecimiento FMCSA sigue encolado');

    // ---------------------------------------------------------------- transaccion
    T::reset();
    Db::pdo()->exec("CREATE TRIGGER trg_fail_consent BEFORE INSERT ON consents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fallo simulado'");
    T::throws(static fn() => LeadIngest::handle($base, 'web_careers'), 'fallo a mitad del ingreso lanza la excepcion', 'fallo simulado');
    Db::pdo()->exec('DROP TRIGGER trg_fail_consent');
    T::ok($count('leads') === 0 && $count('lead_events') === 0 && $count('jobs') === 0 && $count('consents') === 0, 'transaccion: el fallo deshace lead, eventos, consentimientos y jobs (nada a medias)');
    $r = LeadIngest::handle($base, 'web_careers');
    T::ok($r['ok'], 'el motor sigue funcionando tras el fallo (el bloqueo GET_LOCK se libero)');

    // ---------------------------------------------------------------- recalculo
    T::reset();
    $a = LeadIngest::handle($base, 'web_careers');
    $id = (int) $a['lead_id'];
    Db::update('leads', $id, ['cdl_type' => 'non_domiciled']);
    $rs = LeadIngest::rescore($id, 'manual_edit');
    $lead = Lead::find($id);
    T::ok($rs !== null && $rs['temperature'] === 'disqualified' && $lead['score'] === 0 && in_array('disqualified', $events($id), true), 'rescore: si cambia un dato (CDL no domiciliada) se recalcula y se descarta');
    Db::update('leads', $id, ['cdl_type' => 'standard']);
    $rs = LeadIngest::rescore($id, 'manual_edit');
    T::ok($rs['score'] === 65 && $rs['temperature'] === 'warm', 'rescore: vuelve a 65 al corregir el dato');
    T::eq(LeadIngest::rescore(999999), null, 'rescore de un lead inexistente -> null');

    // ---------------------------------------------------------------- consentimiento: revocacion (A15)
    T::reset();
    $a = LeadIngest::handle($base, 'web_careers');
    $id = (int) $a['lead_id'];
    T::ok(Consent::isGranted($id, 'sms') && Consent::isGranted($id, 'call') && Consent::isGranted($id, 'email'), 'consentimiento vigente en sms, call y email');
    T::ok(!Consent::isGranted($id, 'whatsapp'), 'WhatsApp NO esta cubierto por el texto del consentimiento');
    T::eq(Consent::revoke($id, 'sms', 'test'), 1, 'revoke(lead, sms): revoca solo ese canal');
    T::ok(!Consent::isGranted($id, 'sms') && Consent::isGranted($id, 'call'), '  sms revocado, call sigue vigente');
    T::ok(in_array('stop_sms', Lead::find($id)['flags'], true), '  bandera stop_sms');
    T::eq(Consent::revoke('863-555-0101', 'all', 'stop'), 2, 'revoke(telefono en otro formato, all): revoca lo que quedaba (call y email)');
    T::ok(!Consent::isGranted($id, 'call') && !Consent::isGranted($id, 'email') && !Consent::isGranted($id, 'sms'), '  nada vigente despues de STOP');
    $row = Db::one('SELECT revoked_at, revoked_via FROM consents WHERE lead_id = ? AND channel = ?', [$id, 'call']);
    T::ok($row['revoked_at'] !== null && $row['revoked_via'] === 'stop', '  revoked_at y revoked_via guardados');
    T::ok(in_array('consent_revoked', $events($id), true) && in_array('stop', Lead::find($id)['flags'], true), '  evento consent_revoked y bandera stop');
    T::eq(Consent::revoke('+1 999 111 2222', 'all'), 0, 'revoke de un telefono desconocido: 0 y sin error');
    T::eq(Consent::revoke($id, 'all'), 0, 'revoke repetido: 0 (idempotente)');
    // Un nuevo consentimiento posterior a STOP no reabre el canal mientras la bandera stop siga puesta
    LeadIngest::handle($base, 'web_careers');
    T::ok(!Consent::isGranted($id, 'sms'), 'tras STOP, un reingreso con casilla marcada no reactiva por si solo el canal (la bandera stop manda)');

    // ---------------------------------------------------------------- adaptador del sitio
    $siteLine = ['id' => 'ld_7f3a', 'created_at' => '2026-11-03T15:04:05+00:00', 'profile' => 'small-fleet', 'name' => 'Ana Ruiz', 'phone' => '+18635550123', 'email' => null, 'state' => 'TX', 'language' => 'en',
        'contact_pref' => 'call', 'best_time' => null, 'trucks' => 3, 'equipment' => ['box-truck', 'other'], 'years' => 7, 'authority' => 'getting', 'cdl_standard' => 'unsure', 'english' => 'basic',
        'consent' => ['given' => false, 'version' => 'v1', 'text' => 'T', 'at' => null, 'ip' => null, 'user_agent' => null], 'attribution' => ['utm_source' => 'fb', 'landing' => '/careers/', 'referrer' => '', 'first_seen' => '2026-11-01']];
    T::reset();
    $r = LeadIngest::handle(LeadIngest::fromSiteLead($siteLine), 'web_careers', ['skip_antispam' => true]);
    $lead = Lead::find((int) $r['lead_id']);
    T::ok($r['ok'] && $lead['site_lead_id'] === 'ld_7f3a' && $lead['profile'] === 'small_carrier' && $lead['cdl_type'] === 'unsure' && $lead['english_level'] === 'basic' && $lead['truck_count'] === 3 && $lead['created_at'] === '2026-11-03 15:04:05', 'adaptador del sitio: id, perfil small-fleet, CDL "unsure", ingles, camiones y fecha original');
    T::eq([$lead['utm']['utm_source'] ?? null, $lead['utm']['first_seen'] ?? null, $lead['landing_page']], ['fb', '2026-11-01', '/careers/'], 'adaptador: atribucion (utm, first_seen, landing)');
};
