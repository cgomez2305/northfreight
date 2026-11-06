<?php
/**
 * DATOS DE DEMOSTRACION, SOLO LOCALES. Siembra ~120 leads ficticios (nombres genericos, telefonos 555-01XX, correos example.com) repartidos en las ultimas 14 semanas
 * con etapas, eventos, notas, tareas, citas, llamadas y consentimientos coherentes, para disenar y fotografiar el panel.
 *
 *   php captacion/bin/seed-demo.php [--reset] [--count=120] [--seed=2026]
 *
 * Se NIEGA a correr si:
 *   - APP_ENV es 'production' (o no es 'local'),
 *   - DB_HOST no es 127.0.0.1 / localhost / ::1,
 *   - DB_NAME no contiene 'dev', 'demo' o 'test' (o parece una base de Hostinger: u123456_nombre),
 *   - la tabla leads ya tiene leads que NO son de demostracion (site_lead_id distinto de 'demo_%'): nunca mezcla datos falsos con reales.
 * --reset borra solo los leads demo (y sus eventos, notas, tareas, citas, consentimientos y mensajes) antes de sembrar. NUNCA se despliega ni se ejecuta contra la base real.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Services\Catalog;
use Captacion\Services\Scoring;
use Captacion\Services\JobsPublisher;
use Captacion\Services\ProspectImport;
use Captacion\Services\Settings;

$opts = getopt('', ['reset', 'count::', 'seed::']);
$count = isset($opts['count']) ? max(10, min(400, (int) $opts['count'])) : 120;
mt_srand(isset($opts['seed']) ? (int) $opts['seed'] : 2026);

// --- Salvaguardas --------------------------------------------------------------------------------------------
$refuse = static function (string $why): never {
    fwrite(STDERR, "seed-demo se niega a correr: $why\n");
    exit(2);
};
if (Env::get('APP_ENV', 'production') !== 'local') {
    $refuse('APP_ENV no es "local" (los datos de demostracion son solo para una maquina de desarrollo).');
}
$host = strtolower((string) Env::get('DB_HOST', ''));
$name = strtolower((string) Env::get('DB_NAME', ''));
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    $refuse('DB_HOST "' . $host . '" no es local.');
}
if (preg_match('/(dev|demo|test)/', $name) !== 1 || preg_match('/^u\d+_/', $name) === 1) {
    $refuse('DB_NAME "' . $name . '" no parece una base de desarrollo (debe contener dev, demo o test y no ser de Hostinger).');
}
$real = (int) Db::value("SELECT COUNT(*) FROM leads WHERE site_lead_id IS NULL OR site_lead_id NOT LIKE 'demo\\_%'");
if ($real > 0) {
    $refuse('la tabla leads tiene ' . $real . ' lead(s) que no son de demostracion.');
}
if (isset($opts['reset'])) {
    // Q4a: prospectos, referidos y vacantes de demostracion (marcados con source='demo', nota 'demo' y created_by = 0)
    Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
    Db::pdo()->exec("DELETE FROM prospect_calls WHERE prospect_id IN (SELECT id FROM prospects_fmcsa WHERE source = 'demo')");
    Db::pdo()->exec("DELETE FROM prospects_fmcsa WHERE source = 'demo'");
    Db::pdo()->exec("DELETE FROM referrals WHERE referral_code_id IN (SELECT id FROM referral_codes WHERE note = 'demo')");
    Db::pdo()->exec("DELETE FROM referral_codes WHERE note = 'demo'");
    Db::pdo()->exec('DELETE FROM job_postings WHERE created_by = 0');
    Db::pdo()->exec("DELETE FROM webhook_events WHERE event_key LIKE 'demo\\_%'");
    Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    $ids = array_column(Db::all("SELECT id FROM leads WHERE site_lead_id LIKE 'demo\\_%'"), 'id');
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        Db::pdo()->exec("DELETE FROM handoff_deliveries WHERE handoff_id IN (SELECT id FROM handoffs WHERE lead_id IN ($in))");
        Db::pdo()->exec("DELETE FROM handoffs WHERE lead_id IN ($in)");
        foreach (['lead_events', 'lead_notes', 'tasks', 'appointments', 'messages', 'consents', 'sequence_enrollments'] as $t) {
            Db::pdo()->exec("DELETE FROM $t WHERE lead_id IN ($in)");
        }
        Db::pdo()->exec("DELETE FROM leads WHERE id IN ($in)");
        Db::pdo()->exec("DELETE FROM jobs WHERE dedupe_key LIKE 'demo:%'");
        Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        fwrite(STDOUT, 'Borrados ' . count($ids) . " leads demo.\n");
    }
} elseif ((int) Db::value('SELECT COUNT(*) FROM leads') > 0) {
    $refuse('ya hay leads demo; usa --reset para volver a sembrar.');
}

// --- Utilidades ----------------------------------------------------------------------------------------------
$rnd = static fn(): float => mt_rand() / mt_getrandmax();
$pick = static fn(array $a) => $a[mt_rand(0, count($a) - 1)];
$weighted = static function (array $w) use ($rnd): string {
    $t = array_sum($w);
    $r = $rnd() * $t;
    foreach ($w as $k => $v) {
        if (($r -= $v) <= 0) {
            return (string) $k;
        }
    }
    return (string) array_key_last($w);
};
$now = Clock::time();
$userId = Db::value('SELECT id FROM users ORDER BY id LIMIT 1');
$userId = $userId !== null ? (int) $userId : null;

$first = ['Luis', 'Marcos', 'Andre', 'Carlos', 'Dwayne', 'Brandon', 'Javier', 'Tyrone', 'Miguel', 'Kevin', 'Omar', 'Rafael', 'Terrence', 'Daniel', 'Victor', 'Jamal', 'Eduardo', 'Curtis', 'Hector', 'Darnell', 'Sergio', 'Reggie', 'Alex', 'Mario', 'Ray', 'Samuel', 'Jorge', 'Dennis', 'Franklin', 'Ivan', 'Maria', 'Denise', 'Angela', 'Rosa', 'Tamika', 'Karen', 'Yolanda', 'Brenda'];
$last = ['Ortega', 'Washington', 'Reyes', 'Johnson', 'Mendoza', 'Carter', 'Alvarez', 'Brooks', 'Castillo', 'Hughes', 'Navarro', 'Coleman', 'Ramos', 'Foster', 'Silva', 'Bennett', 'Herrera', 'Powell', 'Morales', 'Jenkins', 'Vargas', 'Turner', 'Gutierrez', 'Hayes', 'Rivera', 'Diaz', 'Simmons', 'Cruz', 'Patterson', 'Soto', 'Griffin', 'Medina', 'Ford', 'Aguilar', 'Webb', 'Salazar', 'Barnes', 'Pena'];
$areas = ['FL' => [305, 786, 813], 'TX' => [214, 713, 956], 'GA' => [404, 678], 'CA' => [213, 909, 559], 'IL' => [312, 773], 'NC' => [704, 919], 'TN' => [615, 901], 'OH' => [216, 614],
    'PA' => [215, 412], 'NJ' => [201, 973], 'AZ' => [602], 'IN' => [317], 'MO' => [314], 'AL' => [205], 'SC' => [803], 'VA' => [804], 'MI' => [313], 'KY' => [502], 'OK' => [405], 'NV' => [702]];
$stateW = ['FL' => 14, 'TX' => 14, 'GA' => 10, 'CA' => 7, 'IL' => 6, 'NC' => 6, 'TN' => 6, 'OH' => 5, 'PA' => 5, 'NJ' => 4, 'AZ' => 3, 'IN' => 4, 'MO' => 3, 'AL' => 4, 'SC' => 3, 'VA' => 3, 'MI' => 3, 'KY' => 2, 'OK' => 2, 'NV' => 2];
$dispatch = ['dry-van', 'reefer', 'flatbed', 'box-truck', 'hotshot', 'power-only'];
$consentText = 'I agree that NorthStar Freight Solutions LLC may contact me about my application and its services at the phone number and email I provided, by phone call, text message (SMS) and email, including messages sent with an automated system. Message and data rates may apply. Consent is not a condition of applying or of buying any service. I can opt out at any time by replying STOP or by telling the dispatch desk.';
$landings = ['owner_operator' => ['/careers/owner-operators/', '/careers/', '/dispatch-for-owner-operators/'], 'small_carrier' => ['/careers/owner-operators/', '/dispatch-for-owner-operators/', '/amazon-relay-dispatch/'], 'company_driver' => ['/careers/cdl-a-drivers/', '/careers/', '/careers/apply/']];
$noteBank = [
    'Habló con el esposo, prefiere que le llamen después de las 6 p. m. Está pagando 8% a su dispatcher actual.',
    'Tiene 2 camiones en Dallas, uno parado por reparación. Interesado en dry van regional.',
    'Pidió la tarifa por escrito antes de la llamada. Le mandé el resumen por WhatsApp.',
    'No quiere contrato largo. Explicarle que se puede cancelar semanalmente.',
    'Muy buen perfil: autoridad de 14 meses, seguro al día. Prioridad para presentar.',
    'Dice que ya trabaja con otro dispatcher pero no está contento con los rates de Florida.',
    'Preguntó por Amazon Relay. Confirmar con el equipo si lo despachamos para su caso.',
    'Chofer con 6 años de experiencia, CDL-A limpia. Busca algo en la zona de Atlanta.',
    'Número correcto pero no contesta por la mañana. Probar después del mediodía.',
    'Referido por otro owner-operator. Mencionar el bono cuando firme.',
    'Quiere saber si podemos hacer el papeleo de IFTA. Respondido: sí, en back office.',
    'Pidió hablar en español. Llamada pendiente con el compañero de la mañana.',
];
$loss = ['no_answer' => 30, 'chose_other' => 18, 'price' => 14, 'license' => 8, 'english' => 6, 'no_authority' => 8, 'equipment' => 6, 'curious' => 8, 'rejected' => 2];
$sources = static function (string $profile, float $ageDays) use ($weighted, $rnd): string {
    $recent = $ageDays <= 30;
    if ($profile === 'company_driver') {
        return $weighted(['web_careers' => 52, 'indeed' => $recent ? 22 : 30, 'meta' => $recent ? 20 : 8, 'referral' => 6, 'manual' => 2]);
    }
    return $weighted(['web_careers' => $recent ? 48 : 58, 'n8n_whatsapp' => 14, 'meta' => $recent ? 20 : 8, 'referral' => 6, 'fmcsa_outbound' => 4, 'manual' => 4]);
};

$demoNumber = 0;
$jobsFailed = 0;
$out = ['leads' => 0, 'events' => 0, 'notes' => 0, 'tasks' => 0, 'appointments' => 0, 'calls' => 0, 'consents' => 0, 'hot_open' => 0];
$ev = static function (int $leadId, string $type, int $ts, array $payload = [], ?int $uid = null) use (&$out): void {
    Db::insert('lead_events', ['lead_id' => $leadId, 'type' => $type, 'payload' => $payload ?: null, 'user_id' => $uid, 'created_at' => Clock::at($ts)]);
    $out['events']++;
};

// Momentos de llegada: dia con mas peso entre semana y mas reciente; hora ET con picos a media manana y por la tarde.
$dowW = [1 => 1.25, 2 => 1.3, 3 => 1.2, 4 => 1.2, 5 => 0.95, 6 => 0.45, 7 => 0.3];
$arrivals = [];
$tries = 0;
while (count($arrivals) < $count && $tries++ < 20000) {
    $age = $rnd() ** 1.15 * 98;   // dias atras (0 a 98): sesgo hacia lo reciente
    $day = (new DateTimeImmutable('@' . ($now - (int) ($age * 86400))))->setTimezone(new DateTimeZone('America/New_York'));
    if ($rnd() > ($dowW[(int) $day->format('N')] / 1.3)) {
        continue;
    }
    $hour = (float) $weighted(['7' => 3, '8' => 6, '9' => 9, '10' => 12, '11' => 11, '12' => 8, '13' => 7, '14' => 7, '15' => 7, '16' => 8, '17' => 9, '18' => 8, '19' => 6, '20' => 4, '21' => 2, '22' => 1]);
    $ts = $day->setTime((int) $hour, mt_rand(0, 59), mt_rand(0, 59))->getTimestamp();
    if ($ts < $now - 300) {
        $arrivals[] = $ts;
    }
}
sort($arrivals);
// Tres leads calientes recientes sin contactar (para el KPI y la campana): se fijan al final.
$forcedHot = [$now - 14 * 60, $now - 95 * 60, $now - 4 * 3600 - 20 * 60];
$arrivals = array_slice($arrivals, 0, max(0, $count - count($forcedHot)));
foreach ($forcedHot as $t) {
    $arrivals[] = $t;
}
$hotForced = array_flip($forcedHot);

$settings = Scoring::settings();
foreach ($arrivals as $ts) {
    $demoNumber++;
    $ageDays = ($now - $ts) / 86400;
    $profile = isset($hotForced[$ts]) ? $weighted(['owner_operator' => 70, 'small_carrier' => 30]) : $weighted(['owner_operator' => 50, 'small_carrier' => 18, 'company_driver' => 32]);
    $isDriver = $profile === 'company_driver';
    $state = $weighted($stateW);
    $fn = $pick($first);
    $ln = $pick($last);
    $phone = '+1' . $pick($areas[$state]) . '55501' . str_pad((string) mt_rand(0, 99), 2, '0', STR_PAD_LEFT);
    $good = isset($hotForced[$ts]);
    $cdl = $good ? 'standard' : $weighted(['standard' => 70, 'unsure' => 25, 'non_domiciled' => 5]);
    $english = $good ? 'yes' : $weighted(['yes' => 50, 'basic' => 46, 'no' => 4]);
    $years = $good ? mt_rand(3, 12) : ($isDriver ? (int) $weighted(['0' => 16, '1' => 20, '2' => 18, '3' => 14, '4' => 10, '5' => 8, '7' => 6, '10' => 5, '15' => 3]) : (int) $weighted(['0' => 14, '1' => 18, '2' => 20, '3' => 14, '5' => 14, '8' => 10, '12' => 7, '20' => 3]));
    $eq = [];
    $trucks = null;
    $usdot = null;
    $authority = null;
    $fm = null;
    $flags = [];
    if (!$isDriver) {
        $pool = $dispatch;
        shuffle($pool);
        $eq = array_slice($pool, 0, $weighted(['1' => 55, '2' => 35, '3' => 10]) === '1' ? 1 : ($rnd() < .7 ? 2 : 3));
        if ($rnd() < .08) {
            $eq[] = 'amazon-relay';
        }
        if ($rnd() < .35) {
            $eq = ['amazon-relay'];
        }
        $trucks = $profile === 'small_carrier' ? mt_rand(2, 14) : (int) $weighted(['1' => 62, '2' => 16, '3' => 9, '4' => 5, '5' => 3, '8' => 3, '12' => 2]);
        $authority = $good ? 'active' : $weighted(['active' => 38, 'getting' => 32, 'none' => 30]);
        if ($authority === 'active' || ($authority === 'getting' && $rnd() < .25) || $good) {
            $usdot = (string) mt_rand(1300000, 4199999);
            $r = $rnd();
            if ($r < .86 || $good) {
                $status = ($good || $rnd() < .78) ? 'active' : 'inactive';
                $insured = $good || $rnd() < .7;
                $age = mt_rand(2, 96);
                $red = [];
                if ($status !== 'active') {
                    $red[] = 'authority_inactive';
                }
                if (!$insured) {
                    $red[] = 'no_insurance';
                }
                $fm = [
                    'found' => true, 'usdot' => $usdot, 'legal_name' => strtoupper($ln) . ' ' . $pick(['TRANSPORT LLC', 'FREIGHT LLC', 'TRUCKING INC', 'LOGISTICS LLC', 'HAULING LLC']), 'dba' => null, 'state' => $state,
                    'allowed_to_operate' => $status === 'active', 'authority_status' => $status, 'authority_granted_at' => gmdate('Y-m-d', $now - $age * 30 * 86400), 'authority_age_months' => $age,
                    'insured' => $insured, 'bipd_on_file' => $insured ? 750 : 0, 'power_units' => $trucks + mt_rand(0, 1), 'drivers' => $trucks, 'driver_oos_rate' => round($rnd() * 6, 1), 'vehicle_oos_rate' => round(8 + $rnd() * 22, 1),
                    'safety_rating' => null, 'red_flags' => $red, 'checked_at' => Clock::at($ts + 90),
                ];
            } elseif ($r < .93) {
                $fm = ['found' => false, 'usdot' => $usdot, 'checked_at' => Clock::at($ts + 90), 'red_flags' => []];
            } else {
                $flags[] = 'fmcsa_pending';
            }
        }
    }
    $lead = [
        'profile' => $profile, 'first_name' => $fn, 'last_name' => $ln, 'phone_e164' => $phone, 'email' => strtolower($fn . '.' . $ln . mt_rand(1, 99)) . '@example.com', 'state' => $state, 'language' => $rnd() < .34 ? 'es' : 'en',
        'cdl_type' => $cdl, 'cdl_class' => $isDriver ? ($rnd() < .9 ? 'A' : 'B') : null, 'english_level' => $english, 'years_experience' => $years, 'equipment_types' => $eq, 'truck_count' => $trucks,
        'authority_status' => $authority, 'usdot' => $usdot, 'authority_age_months' => $fm['authority_age_months'] ?? null, 'endorsements' => $isDriver && $rnd() < .22 ? [$pick(['hazmat', 'tanker'])] : [],
        'referral_code' => $rnd() < .05 ? 'NS-' . strtoupper(substr(md5((string) $demoNumber), 0, 4)) : null, 'fmcsa_data' => $fm,
    ];
    if ($good && !$isDriver) {
        $lead['truck_count'] = max(2, (int) $trucks);
        $lead['equipment_types'] = array_values(array_unique([...$eq ?: ['dry-van'], 'dry-van']));
    }
    $sc = Scoring::score($lead, $settings);
    if ($good && $sc['temperature'] !== 'hot') {
        $lead['years_experience'] = 8;
        $lead['truck_count'] = 6;
        $sc = Scoring::score($lead, $settings);
    }
    $flagList = \Captacion\Services\Flags::derive($flags, $lead, $settings);
    $temp = $sc['temperature'];
    $src = $sources($profile, $ageDays);
    $pipeline = $isDriver ? 'B' : 'A';
    $stagesList = array_keys(Catalog::STAGES[$pipeline]);   // new ... closed, lost
    $closed = $isDriver ? 'hired' : 'signed';
    $mid = array_slice($stagesList, 1, -2);     // contacted .. presented (sin cierre ni perdido)

    // --- Embudo simulado ---
    $stage = 'new';
    $timeline = [];   // [ts, stage, reason|null]
    $cur = $ts;
    $firstContact = null;
    $lostReason = null;
    $lostAt = null;
    if (isset($hotForced[$ts])) {
        $stage = 'new';
    } elseif ($temp === 'disqualified') {
        if ($rnd() < .8) {
            $cur = $ts + mt_rand(600, 7200);
            if ($cur < $now) {
                $stage = 'lost';
                $lostReason = $english === 'no' ? 'english' : 'license';
                $lostAt = $cur;
                $timeline[] = [$cur, 'lost', $lostReason];
            }
        }
    } else {
        $pContact = $temp === 'hot' ? .98 : ($temp === 'warm' ? .9 : .6);
        if ($rnd() < $pContact) {
            $delay = (int) ($temp === 'hot' ? 60 + $rnd() ** 2 * 1800 : 600 + $rnd() ** 2 * 14400);
            $cur = $ts + $delay;
            if ($cur < $now) {
                $firstContact = $cur;
                $stage = 'contacted';
                $timeline[] = [$cur, 'contacted', null];
                $adv = $temp === 'hot' ? .8 : ($temp === 'warm' ? .55 : .2);
                foreach (array_slice($mid, 1) as $s) {   // qualified/prequalified, call_booked, presented
                    if ($rnd() < $adv) {
                        $cur += mt_rand(3600, 3 * 86400);
                        if ($cur >= $now) {
                            break;
                        }
                        $stage = $s;
                        $timeline[] = [$cur, $s, null];
                    } else {
                        break;
                    }
                }
                if ($stage === 'presented' && $rnd() < ($temp === 'hot' ? .6 : .35)) {
                    $cur += mt_rand(86400, 4 * 86400);
                    if ($cur < $now) {
                        $stage = $closed;
                        $timeline[] = [$cur, $closed, null];
                    }
                } elseif ($stage !== $closed && $stage !== 'presented' && $rnd() < ($temp === 'cold' ? .5 : .22)) {
                    $cur += mt_rand(86400, 9 * 86400);
                    if ($cur < $now) {
                        $stage = 'lost';
                        $lostReason = $weighted($loss);
                        $lostAt = $cur;
                        $timeline[] = [$cur, 'lost', $lostReason];
                    }
                }
            }
        } elseif ($ageDays > 5 && $rnd() < .5) {
            $stage = 'lost';
            $lostReason = 'no_answer';
            $lostAt = $ts + mt_rand(3, 6) * 86400;
            $timeline[] = [$lostAt, 'lost', 'no_answer'];
        }
    }
    $handedOff = null;
    $signedAt = null;
    foreach ($timeline as [$t, $s]) {
        if ($s === 'presented' && $handedOff === null) {
            $handedOff = $t;
        }
        if ($s === $closed) {
            $signedAt = $t;
            $handedOff ??= $t;
        }
    }
    $leadId = Db::insert('leads', [
        'site_lead_id' => 'demo_' . str_pad((string) $demoNumber, 4, '0', STR_PAD_LEFT), 'profile' => $profile, 'first_name' => $fn, 'last_name' => $ln, 'phone_e164' => $phone, 'phone_raw' => $phone,
        'email' => $lead['email'], 'state' => $state, 'language' => $lead['language'], 'cdl_class' => $lead['cdl_class'], 'cdl_type' => $cdl, 'english_level' => $english, 'years_experience' => $years,
        'equipment_types' => $eq ?: null, 'truck_count' => $lead['truck_count'], 'authority_status' => $authority, 'usdot' => $usdot, 'authority_age_months' => $lead['authority_age_months'],
        'endorsements' => $lead['endorsements'] ?: null, 'contact_pref' => $pick(['call', 'whatsapp', 'text']), 'best_time' => $pick(['morning', 'afternoon', 'evening', 'any']),
        'score' => $sc['score'], 'temperature' => $temp, 'score_breakdown' => $sc['breakdown'], 'disqualified_reason' => $sc['disqualified_reason'], 'pipeline' => $pipeline, 'stage' => $stage,
        'lost_reason' => $lostReason, 'lost_at' => $lostAt !== null ? Clock::at($lostAt) : null, 'attempt' => 1, 'source' => $src,
        'utm' => $src === 'meta' ? ['utm_source' => 'facebook', 'utm_medium' => 'paid', 'utm_campaign' => 'owner-op-nov'] : ($src === 'indeed' ? ['utm_source' => 'indeed', 'utm_medium' => 'job-board'] : null),
        'landing_page' => $pick($landings[$profile]), 'referrer' => $src === 'web_careers' ? $pick(['https://www.google.com/', 'https://www.google.com/', 'https://duckduckgo.com/', '']) : null, 'referral_code' => $lead['referral_code'],
        'fmcsa_data' => $fm, 'fmcsa_checked_at' => $fm !== null ? Clock::at($ts + 90) : null, 'flags' => $flagList ?: null,
        'first_contact_at' => $firstContact !== null ? Clock::at($firstContact) : null, 'handed_off_at' => $handedOff !== null ? Clock::at($handedOff) : null,
        'handoff_status' => $signedAt !== null ? 'signed' : ($handedOff !== null ? 'pending' : null), 'signed_at' => $signedAt !== null ? Clock::at($signedAt) : null,
        'created_at' => Clock::at($ts), 'updated_at' => Clock::at(max($ts, $cur)),
    ]);
    $out['leads']++;
    if ($temp === 'hot' && $stage === 'new') {
        $out['hot_open']++;
    }

    // Eventos del sistema
    $ev($leadId, 'created', $ts, ['source' => $src, 'site_lead_id' => 'demo_' . $demoNumber, 'landing_page' => $lead['usdot'] ? '/careers/owner-operators/' : '/careers/', 'consent_given' => true]);
    if ($fm !== null) {
        $ev($leadId, 'fmcsa', $ts + 90, ['found' => $fm['found'], 'authority_status' => $fm['authority_status'] ?? null, 'insured' => $fm['insured'] ?? null, 'red_flags' => $fm['red_flags']]);
    }
    $ev($leadId, 'scored', $ts + 91, ['score' => $sc['score'], 'temperature' => $temp, 'reason' => 'created']);
    if ($temp === 'disqualified') {
        $ev($leadId, 'disqualified', $ts + 92, ['reasons' => $sc['breakdown']['disqualified']]);
    }
    if ($temp === 'hot') {
        $ev($leadId, 'alert_sent', $ts + 95, ['channel' => 'telegram', 'kind' => 'hot']);
        if (!isset($hotForced[$ts]) && $firstContact !== null && $firstContact - $ts > 900) {
            $ev($leadId, 'alert_sent', $ts + 900, ['channel' => 'telegram', 'kind' => 'followup']);
        }
    }
    // Consentimiento (la mayoria lo da en el formulario)
    if ($rnd() < .86) {
        foreach (['call', 'sms', 'email'] as $ch) {
            Db::insert('consents', ['lead_id' => $leadId, 'channel' => $ch, 'consent_text' => $consentText, 'consent_version' => 'v1', 'ip' => '203.0.113.' . mt_rand(2, 250), 'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X)', 'granted_at' => Clock::at($ts)]);
            $out['consents']++;
        }
        if ($rnd() < .05) {
            Db::run('UPDATE consents SET revoked_at = ?, revoked_via = ? WHERE lead_id = ?', [Clock::at($ts + 86400), 'stop', $leadId]);
            Db::run('UPDATE leads SET flags = ? WHERE id = ?', [json_encode(array_values(array_unique([...$flagList, 'stop']))), $leadId]);
            $ev($leadId, 'consent_revoked', $ts + 86400, ['channel' => 'all', 'via' => 'stop', 'revoked' => 3]);
        }
    }
    // Etapas, llamadas y notas
    $prevStage = 'new';
    foreach ($timeline as [$t, $s, $reason]) {
        if ($s === 'contacted' && $rnd() < .8) {
            Db::insert('messages', ['lead_id' => $leadId, 'channel' => 'call', 'direction' => 'out', 'body' => null, 'status' => 'answered', 'duration_sec' => mt_rand(2, 14) * 60, 'user_id' => $userId, 'created_at' => Clock::at($t - 60)]);
            $ev($leadId, 'call_logged', $t - 60, ['outcome' => 'answered', 'minutes' => mt_rand(2, 14)], $userId);
            $out['calls']++;
        }
        $ev($leadId, 'stage_changed', $t, array_filter(['from' => $prevStage, 'to' => $s, 'pipeline' => $pipeline, 'reason' => $reason]), $userId);
        $prevStage = $s;
        Db::run('UPDATE leads SET stage_changed_at = ? WHERE id = ?', [Clock::at($t), $leadId]);
    }
    if ($firstContact !== null && $rnd() < .38) {
        for ($i = mt_rand(1, 2); $i > 0; $i--) {
            $nt = $firstContact + mt_rand(300, 86400);
            if ($nt >= $now) {
                continue;
            }
            $nid = Db::insert('lead_notes', ['lead_id' => $leadId, 'user_id' => $userId, 'body' => $pick($noteBank), 'created_at' => Clock::at($nt)]);
            $ev($leadId, 'note_added', $nt, ['note_id' => $nid], $userId);
            $out['notes']++;
        }
    }
    // Tareas: para leads abiertos con contacto
    if (!in_array($stage, ['lost', $closed, 'new'], true) && $rnd() < .45) {
        $titles = ['Llamar para confirmar documentos', 'Enviar resumen del servicio por WhatsApp', 'Pedir copia del seguro y la autoridad', 'Confirmar horario de la llamada con NorthStar', 'Seguimiento: no contestó, reintentar'];
        $dueDelta = (int) $weighted(['-2' => 2, '-1' => 3, '0' => 6, '1' => 5, '2' => 4, '4' => 3]);
        $dayStart = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('America/New_York'))->setTime(mt_rand(9, 17), $pick([0, 15, 30, 45]));
        $due = $dayStart->modify(($dueDelta >= 0 ? '+' : '') . $dueDelta . ' days')->getTimestamp();
        $done = $dueDelta < 0 && $rnd() < .5;
        $tid = Db::insert('tasks', ['title' => $pick($titles), 'lead_id' => $leadId, 'due_at' => Clock::at($due), 'priority' => (int) $weighted(['1' => 3, '2' => 5, '3' => 2]), 'created_by' => $userId, 'done_at' => $done ? Clock::at($due - 3600) : null, 'created_at' => Clock::at(max($ts, $cur))]);
        $ev($leadId, 'task_created', max($ts, $cur), ['task_id' => $tid, 'title' => 'Tarea', 'priority' => 2], $userId);
        $out['tasks']++;
    }
    if ($stage === 'call_booked') {
        $st = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('America/New_York'))->setTime(mt_rand(10, 17), $pick([0, 30]))->modify(($rnd() < .5 ? '+0' : '+' . mt_rand(1, 3)) . ' days')->getTimestamp();
        Db::insert('appointments', ['lead_id' => $leadId, 'starts_at' => Clock::at($st), 'ends_at' => Clock::at($st + 1800), 'status' => 'booked']);
        $out['appointments']++;
    }
    // Cola: los calientes recientes dejaron su alerta (fallida porque Telegram no esta configurado en esta maquina)
    if ($temp === 'hot' && isset($hotForced[$ts]) && $jobsFailed < 2) {
        Db::insert('jobs', ['type' => 'telegram_alert', 'payload' => ['lead_id' => $leadId, 'kind' => 'hot'], 'status' => 'failed', 'attempts' => 4, 'run_at' => Clock::at($ts), 'dedupe_key' => 'demo:telegram_alert:' . $leadId,
            'last_error' => 'Telegram sin configurar: faltan TELEGRAM_BOT_TOKEN o TELEGRAM_CHAT_ID en config/.env', 'finished_at' => Clock::at($ts + 1200)]);
        $jobsFailed++;
    }
}

// --- Q4a: prospectos FMCSA, referidos y vacantes de demostracion -------------------------------------------------
$nowEt = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('America/New_York'));
$today = $nowEt->format('Y-m-d');
$companies = ['Delta Haul', 'Brightline Freight', 'Rio Verde Transport', 'Cardinal Road', 'Sunrise Carriers', 'Lone Star Logistics', 'Peach State Trucking', 'Blue Ridge Hauling', 'Gulf Coast Express', 'Silver Pine Transport', 'Prairie Wind Freight',
    'Keystone Cargo', 'Magnolia Haulers', 'Copper Mesa Trucking', 'North Fork Carriers', 'Iron Valley Transport', 'Bayou Line', 'Summit Road Freight', 'Mission Trail Trucking', 'Redwood Haul', 'Harbor Point Cargo', 'Juniper Freight', 'Coastal Run LLC', 'Granite State Haul'];
$cities = ['FL' => ['Orlando', 'Tampa', 'Miami', 'Lakeland'], 'TX' => ['Houston', 'Dallas', 'Laredo', 'San Antonio'], 'GA' => ['Atlanta', 'Savannah'], 'CA' => ['Fresno', 'Bakersfield'], 'IL' => ['Joliet'], 'NC' => ['Charlotte'], 'TN' => ['Memphis'], 'OH' => ['Columbus'], 'AZ' => ['Phoenix'], 'NJ' => ['Newark']];
$pStates = array_keys($cities);
$cargoSets = [['genfreight'], ['genfreight', 'bldgmat'], ['genfreight', 'produce', 'coldfood'], ['genfreight', 'machlrg', 'construct'], []];
$statusPlan = array_merge(array_fill(0, 13, 'to_call'), ['no_answer', 'no_answer', 'no_answer_closed', 'talked', 'talked', 'interested', 'interested', 'not_interested', 'dnc', 'to_call', 'to_call']);
$prospIds = [];
foreach ($companies as $i => $co) {
    $st = $pStates[$i % count($pStates)];
    $units = $weighted(['1' => 60, '2' => 22, '3' => 10, '5' => 6, '8' => 2]);
    $granted = $nowEt->modify('-' . mt_rand(0, 85) . ' days')->format('Y-m-d');
    $cargo = $cargoSets[$i % count($cargoSets)];
    $area = $pick($areas[$st] ?? [305]);
    $phone = '+1' . $area . '555' . str_pad((string) (100 + $i), 4, '0', STR_PAD_LEFT);
    $status = $statusPlan[$i % count($statusPlan)];
    $attempts = $status === 'no_answer' ? mt_rand(1, 2) : ($status === 'no_answer_closed' ? 3 : 0);
    $email = $rnd() < .8 ? strtolower(preg_replace('/[^a-z]/i', '', $co)) . '@example.com' : null;
    $row = ['usdot' => (string) (4400000 + mt_rand(1000, 99999)), 'mc_number' => 'MC' . (880000 + $i * 7), 'legal_name' => strtoupper($co) . ' LLC', 'dba' => null, 'phone' => $phone, 'email' => $email, 'state' => $st, 'city' => strtoupper($pick($cities[$st])),
        'authority_granted_at' => $granted, 'fleet_size' => (int) $units, 'drivers' => max(1, (int) $units), 'cargo' => $cargo ?: null, 'completeness' => 4 + ($email !== null ? 1 : 0) + ($cargo ? 1 : 0) + 1, 'fmcsa_data' => ['census_status' => 'A', 'grants_dataset' => 'yu5v-wbh6', 'census_dataset' => 'az4n-8mr2'],
        'source' => 'demo', 'status' => $status, 'attempts' => $attempts, 'listed_on' => $today, 'created_at' => Clock::at($now - mt_rand(0, 3) * 86400), 'updated_at' => Clock::now()];
    if ($status === 'no_answer') {
        $row['next_call_at'] = Clock::at($now + ($i % 2 === 0 ? -3600 : 86400));
    }
    if (in_array($status, ['talked', 'interested'], true)) {
        $row['next_call_at'] = Clock::at($now - 1800);
    }
    if ($status !== 'to_call') {
        $row['last_call_at'] = Clock::at($now - mt_rand(3600, 3 * 86400));
        $row['last_result'] = $status === 'no_answer_closed' ? 'no_answer' : $status;
    }
    if ($status === 'dnc') {
        $row += ['do_not_call' => 1, 'dnc_at' => Clock::at($now - 86400), 'closed_at' => Clock::at($now - 86400)];
    }
    $pid = Db::insert('prospects_fmcsa', $row);
    $prospIds[$status][] = $pid;
    $calls = $status === 'no_answer_closed' ? 3 : (in_array($status, ['no_answer'], true) ? $attempts : ($status === 'to_call' ? 0 : 1));
    for ($c = 1; $c <= $calls; $c++) {
        $isNa = in_array($status, ['no_answer', 'no_answer_closed'], true);
        Db::insert('prospect_calls', ['prospect_id' => $pid, 'user_id' => $userId, 'result' => $isNa ? 'no_answer' : $status, 'note' => $isNa ? ($c === 1 ? 'Buzón de voz lleno' : null) : ($status === 'talked' ? 'Tiene 2 camiones, pidió que lo llamemos el jueves.' : null),
            'attempt_no' => $isNa ? $c : 0, 'created_at' => Clock::at($now - (($calls - $c + 1) * 86400))]);
    }
}
Settings::set('prospects.run', ['day' => $today, 'since' => $nowEt->modify('-12 months')->format('Ymd'), 'cursor' => null, 'finished' => true, 'capped' => false, 'started_at' => Clock::at($now - 7200), 'updated_at' => Clock::now(), 'finished_at' => Clock::at($now - 7100),
    'pages' => 3, 'requests' => 6, 'resumed' => 0, 'counters' => ['read' => 380, 'new' => count($companies), 'known' => 210, 'already_lead' => 4, 'dnc' => 1, 'no_census' => 2, 'census_inactive' => 9, 'not_us' => 14, 'state' => 0, 'fleet' => 31, 'no_phone' => 18, 'dup_phone' => 2, 'cargo' => 0, 'cap' => 0], 'error' => null]);
Settings::set('cron.prospects', ['at' => Clock::at($now - 600), 'status' => 'done', 'day' => $today, 'finished' => true]);
Settings::set(ProspectImport::COMPLETE_KEY, $today);

$signedDemo = Db::all("SELECT id FROM leads WHERE site_lead_id LIKE 'demo\\_%' AND stage IN ('signed', 'hired') ORDER BY id LIMIT 3");
$openDemo = Db::all("SELECT id FROM leads WHERE site_lead_id LIKE 'demo\\_%' AND stage NOT IN ('signed', 'hired', 'lost') ORDER BY id DESC LIMIT 8");
$codeOwners = [['Luis Ortega', $signedDemo[0]['id'] ?? null, 'LUIS-7K2M', 250.0], ['Marcos Reyes', $signedDemo[1]['id'] ?? null, 'MARCOS-4PQ9', 250.0], ['Rosa Gómez (grupo de Facebook)', null, 'ROSA-GRUPO', null]];
$codeIds = [];
foreach ($codeOwners as [$on, $ol, $cd, $amt]) {
    $codeIds[] = Db::insert('referral_codes', ['owner_name' => $on, 'owner_lead_id' => $ol, 'code' => $cd, 'bonus_amount' => $amt, 'active' => 1, 'note' => 'demo', 'created_by' => $userId, 'created_at' => Clock::at($now - 20 * 86400)]);
}
$refPlan = [[0, 'referred', 'none'], [0, 'qualified', 'none'], [1, 'signed', 'pending'], [0, 'signed', 'approved'], [1, 'signed', 'paid'], [2, 'referred', 'none']];
foreach ($refPlan as $i => [$ci, $rs, $bs]) {
    $lid = $openDemo[$i]['id'] ?? ($signedDemo[$i]['id'] ?? null);
    if ($lid === null) {
        continue;
    }
    Db::run("UPDATE leads SET referral_code = ? WHERE id = ?", [$codeOwners[$ci][2], (int) $lid]);
    Db::insert('referrals', ['referral_code_id' => $codeIds[$ci], 'lead_id' => (int) $lid, 'status' => $rs, 'bonus_status' => $bs, 'qualified_at' => $rs !== 'referred' ? Clock::at($now - 6 * 86400) : null, 'signed_at' => $rs === 'signed' ? Clock::at($now - 3 * 86400) : null,
        'bonus_amount' => $rs === 'signed' ? 250.0 : null, 'bonus_approved_at' => in_array($bs, ['approved', 'paid'], true) ? Clock::at($now - 2 * 86400) : null, 'bonus_paid_at' => $bs === 'paid' ? Clock::at($now - 86400) : null, 'created_at' => Clock::at($now - (10 - $i) * 86400)]);
}

$jobsDemo = [
    ['Regional CDL-A Company Driver', 'regional-cdl-a-company-driver', 'active', 'Lakeland', 'FL', '33801', 'Regional dry van routes out of Lakeland with home time most weekends.', 'Dry van', ["Run regional dry van loads", "Keep logs and trip paperwork current", "Report delays to the dispatch desk"], ["Standard CDL-A", "1 year of tractor-trailer experience", "Clean MVR"], ["Home most weekends"], ['min' => 1100, 'max' => 1400, 'unit' => 'WEEK']],
    ['OTR CDL-A Company Driver', 'otr-cdl-a-company-driver', 'paused', 'Atlanta', 'GA', null, 'Over-the-road dry van lanes across the Southeast.', 'Dry van', ["Run OTR dry van loads"], ["Standard CDL-A", "2 years of OTR experience"], [], null],
    ['Local CDL-A Driver Tampa', 'local-cdl-a-driver-tampa', 'draft', 'Tampa', 'FL', null, '', null, [], [], [], null],
];
foreach ($jobsDemo as $i => [$t, $slug, $stt, $city, $reg, $zip, $sum, $eq, $resp, $req, $ben, $pay]) {
    Db::insert('job_postings', ['title' => $t, 'slug' => $slug, 'status' => $stt, 'is_active' => $stt === 'active' ? 1 : 0, 'posted_on' => $nowEt->modify('-5 days')->format('Y-m-d'), 'valid_through' => $nowEt->modify('+40 days')->format('Y-m-d'),
        'employment_type' => 'FULL_TIME', 'location' => $city . ', ' . $reg, 'city' => $city, 'region' => $reg, 'postal' => $zip, 'summary' => $sum ?: null, 'equipment' => $eq, 'responsibilities' => $resp ?: null, 'requirements' => $req ?: null, 'benefits' => $ben ?: null,
        'pay_min' => $pay['min'] ?? null, 'pay_max' => $pay['max'] ?? null, 'pay_unit' => $pay['unit'] ?? null, 'pay_currency' => $pay ? 'USD' : null, 'published_at' => $stt === 'draft' ? null : Clock::at($now - 5 * 86400), 'created_by' => 0]);
}
try {
    JobsPublisher::publish();
} catch (Throwable $t) {
    fwrite(STDERR, 'aviso: no se pudo escribir jobs.json (' . $t->getMessage() . ")\n");
}
$out['prospects'] = count($companies);
$out['referral_codes'] = count($codeIds);
$out['job_postings'] = count($jobsDemo);

// Q4b: citas de Cal.com de demostracion (una cancelada), estado de los webhooks y un mapeo de Meta de ejemplo
foreach (Db::all('SELECT id FROM appointments ORDER BY id LIMIT 6') as $i => $a) {
    Db::run("UPDATE appointments SET source = 'calcom', calcom_id = ?, title = ?, tz = 'America/Chicago', meeting_url = 'https://meet.example.com/demo', last_event_at = ?, status = ? WHERE id = ?",
        ['demo-cal-' . ($i + 1), '30 min · Llamada con NorthStar', Clock::now(), $i === 5 ? 'cancelled' : 'booked', (int) $a['id']]);
}
Settings::set('integration.meta', ['last' => 'ok', 'ok_at' => Clock::at($now - 420), 'msg' => '2 lead(s) en cola', 'streak' => 0]);
Settings::set('integration.calcom', ['last' => 'ok', 'ok_at' => Clock::at($now - 3600), 'msg' => 'cita creada', 'streak' => 0]);
Settings::set('integration.twilio', ['last' => 'err', 'err_at' => Clock::at($now - 600), 'err' => 'Error interno: PDOException', 'streak' => 3, 'msg' => '']);
Settings::set('meta', ['default_profile' => 'owner_operator', 'consent_channels' => ['call', 'sms', 'email'], 'consent_text' => '',
    'map' => [['from' => 'what_type_of_equipment_do_you_run?', 'to' => 'equipment'], ['from' => 'how_many_trucks_do_you_have?', 'to' => 'trucks'], ['from' => 'usdot_number', 'to' => 'usdot'], ['from' => 'preferred_language', 'to' => 'language']]]);
foreach ([['meta', 'demo_lead_1', 'processed', 'created · 1 campos sin mapear'], ['meta', 'demo_lead_2', 'processed', 'created'], ['calcom', 'demo_c1', 'processed', 'cita creada'], ['calcom', 'demo_c2', 'ignored', 'evento obsoleto (ya se aplicó uno más reciente)'], ['twilio', 'demo_t1', 'processed', 'STOP: 2 consentimientos revocados']] as [$src, $k, $st, $sm]) {
    Db::run('INSERT IGNORE INTO webhook_events (source, event_key, status, summary, created_at) VALUES (?, ?, ?, ?, ?)', [$src, $k, $st, $sm, Clock::at($now - 1800)]);
}

Settings::set('cron.process_jobs', ['at' => Clock::now(), 'processed' => 3, 'done' => 3, 'retried' => 0, 'failed' => 0, 'seconds' => 0.4]);
fwrite(STDOUT, 'Sembrados: ' . json_encode($out) . "\n");
