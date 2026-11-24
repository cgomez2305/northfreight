<?php
/**
 * Fusion manual de dos leads duplicados (fase AM). Solo administradores, con confirmacion, en UNA transaccion y bajo el mismo bloqueo del motor de captacion (para que una
 * entrada nueva no se cuele a mitad de la fusion).
 *
 * Se mueven al PRINCIPAL: notas, tareas, eventos, mensajes, consentimientos, citas, inscripciones en secuencias (si el principal ya tiene una de esa secuencia, la del secundario se
 * detiene, asi no hay dos activas), el referido (si el principal no tiene uno), los codigos de referido que posee, el prospecto FMCSA convertido, las entregas a NorthStar (sus
 * `attempt` se desplazan para no chocar con las del principal) y los avisos de webhooks. Los campos VACIOS del principal se rellenan con los del secundario (nunca se pisa un
 * valor); las banderas se unen (un STOP del secundario manda). Se conservan la etapa, el perfil, la fuente y las fechas de creacion del principal. El principal recalcula su
 * puntuacion y el secundario queda con borrado logico (deleted_at) y `merged_into`: su ficha redirige al principal.
 *
 * Reglas de seguridad: ambos deben compartir telefono o correo (los que la ficha ofrece como duplicados), no estar anonimizados, ser del mismo pipeline y, si el secundario ya
 * esta firmado/contratado, el principal tambien tiene que estarlo (si no, se elige el firmado como principal).
 *
 * SIN «Deshacer», a proposito: la fusion reasigna filas de una docena de tablas y rellena campos; deshacerla exigiria un diario fila a fila que el panel no lleva. En su lugar,
 * es una accion de administrador, pide confirmacion, el secundario se conserva (borrado logico) y queda en activity_log y en la linea de tiempo de los dos leads.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;

final class LeadMerge
{
    /** Campos que se rellenan en el principal solo si estan vacios. */
    private const FILL = ['last_name', 'state', 'city', 'cdl_class', 'cdl_type', 'english_level', 'years_experience', 'equipment_types', 'truck_count', 'authority_status', 'usdot',
        'authority_age_months', 'endorsements', 'contact_pref', 'best_time', 'job_slug', 'utm', 'landing_page', 'referrer', 'referral_code', 'fmcsa_data', 'fmcsa_checked_at',
        'first_contact_at', 'handed_off_at', 'handoff_status', 'signed_at', 'external_crm_id', 'last_inbound_at', 'wa_last_inbound_at'];
    private const MOVE = ['lead_notes', 'tasks', 'lead_events', 'messages', 'consents', 'appointments', 'webhook_events', 'rule_applications'];

    private static function empty(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [];
    }

    /** @return array{ok:bool,msg:string} */
    public static function problem(?array $p, ?array $s): array
    {
        if ($p === null || $s === null) {
            return ['ok' => false, 'msg' => 'Uno de los dos leads ya no existe.'];
        }
        if ((int) $p['id'] === (int) $s['id']) {
            return ['ok' => false, 'msg' => 'Elige dos leads distintos.'];
        }
        if (($p['anonymized_at'] ?? null) !== null || ($s['anonymized_at'] ?? null) !== null) {
            return ['ok' => false, 'msg' => 'No se fusionan leads anonimizados.'];
        }
        $same = ($p['phone_e164'] !== null && $p['phone_e164'] === $s['phone_e164']) || ($p['email'] !== null && $p['email'] === $s['email']);
        if (!$same) {
            return ['ok' => false, 'msg' => 'Solo se fusionan leads que comparten teléfono o correo.'];
        }
        if ($p['pipeline'] !== $s['pipeline']) {
            return ['ok' => false, 'msg' => 'Los dos leads son de pipelines distintos (owner-operators y company drivers): no se fusionan.'];
        }
        if (Dedupe::isSigned($s) && !Dedupe::isSigned($p)) {
            return ['ok' => false, 'msg' => 'El lead firmado tiene que ser el principal.'];
        }
        return ['ok' => true, 'msg' => ''];
    }

    /** @return array{ok:bool,msg:string,primary?:int} */
    public static function merge(int $primaryId, int $secondaryId, int $userId): array
    {
        if ($primaryId === $secondaryId) {
            return ['ok' => false, 'msg' => 'Elige dos leads distintos.'];
        }
        if (!Db::lock('captacion_ingest', 5)) {
            return ['ok' => false, 'msg' => 'El motor de captación está ocupado. Inténtalo de nuevo en unos segundos.'];
        }
        try {
            return Db::transaction(static function () use ($primaryId, $secondaryId, $userId): array {
                $first = Lead::find(min($primaryId, $secondaryId), true);   // siempre en el mismo orden: sin bloqueos cruzados
                $second = Lead::find(max($primaryId, $secondaryId), true);
                $p = $primaryId < $secondaryId ? $first : $second;
                $s = $primaryId < $secondaryId ? $second : $first;
                $why = self::problem($p, $s);
                if (!$why['ok']) {
                    return $why;
                }
                $now = Clock::now();
                $moved = [];
                foreach (self::MOVE as $t) {
                    $moved[$t] = Db::run("UPDATE `$t` SET lead_id = ? WHERE lead_id = ?", [$primaryId, $secondaryId])->rowCount();
                }
                Db::run('UPDATE IGNORE rule_simulation_rows SET lead_id = ? WHERE lead_id = ?', [$primaryId, $secondaryId]);
                Db::run('UPDATE referral_codes SET owner_lead_id = ? WHERE owner_lead_id = ?', [$primaryId, $secondaryId]);
                Db::run('UPDATE prospects_fmcsa SET converted_lead_id = ? WHERE converted_lead_id = ?', [$primaryId, $secondaryId]);

                // Entregas a NorthStar: sus `attempt` pasan por encima de los del principal (UNIQUE lead+attempt+version)
                $maxAttempt = (int) Db::value('SELECT COALESCE(MAX(attempt), 0) FROM handoffs WHERE lead_id = ?', [$primaryId]);
                $moved['handoffs'] = Db::run('UPDATE handoffs SET lead_id = ?, attempt = attempt + ? WHERE lead_id = ?', [$primaryId, $maxAttempt, $secondaryId])->rowCount();

                // Secuencias: una por lead y secuencia (UNIQUE); la del secundario se detiene si el principal ya tiene esa
                $moved['sequences'] = 0;
                $stopped = 0;
                foreach (Db::all('SELECT id, sequence_key, status FROM sequence_enrollments WHERE lead_id = ?', [$secondaryId]) as $e) {
                    if ((int) Db::value('SELECT COUNT(*) FROM sequence_enrollments WHERE lead_id = ? AND sequence_key = ?', [$primaryId, $e['sequence_key']]) > 0) {
                        if (in_array($e['status'], ['active', 'paused'], true)) {
                            Db::run("UPDATE sequence_enrollments SET status = 'stopped', paused_reason = 'merged', blocked_reason = NULL, next_run_at = NULL WHERE id = ?", [(int) $e['id']]);
                            $stopped++;
                        }
                    } else {
                        Db::run('UPDATE sequence_enrollments SET lead_id = ? WHERE id = ?', [$primaryId, (int) $e['id']]);
                        $moved['sequences']++;
                    }
                }
                // Referido: uno por lead
                $moved['referrals'] = 0;
                if ((int) Db::value('SELECT COUNT(*) FROM referrals WHERE lead_id = ?', [$primaryId]) === 0) {
                    $moved['referrals'] = Db::run('UPDATE referrals SET lead_id = ? WHERE lead_id = ?', [$primaryId, $secondaryId])->rowCount();
                }

                // Campos vacios del principal, rellenados con los del secundario
                $fill = [];
                if (self::empty($p['phone_e164']) && !self::empty($s['phone_e164'])) {
                    $fill['phone_e164'] = $s['phone_e164'];
                    $fill['phone_raw'] = $s['phone_raw'];
                }
                if (self::empty($p['email']) && !self::empty($s['email'])) {
                    $fill['email'] = $s['email'];
                }
                if (self::empty($p['first_name']) && !self::empty($s['first_name'])) {
                    $fill['first_name'] = $s['first_name'];
                }
                foreach (self::FILL as $f) {
                    if (self::empty($p[$f] ?? null) && !self::empty($s[$f] ?? null)) {
                        $fill[$f] = $s[$f];
                    }
                }
                $fill['flags'] = Flags::add((array) $p['flags'], ...array_values((array) $s['flags']));
                Db::update('leads', $primaryId, $fill);

                // Los trabajos pendientes del secundario ya no tienen sentido
                $cancelled = LeadErasure::cancelJobs($secondaryId);
                LeadActions::event($primaryId, 'merged', ['from' => $secondaryId], $userId);
                LeadActions::event($secondaryId, 'merged', ['into' => $primaryId], $userId);
                Db::run('UPDATE leads SET deleted_at = ?, merged_into = ? WHERE id = ?', [$now, $primaryId, $secondaryId]);
                LeadIngest::rescore($primaryId, 'merged', false);
                ActivityLog::record('lead_merge', $userId, 'lead', $primaryId, ['from' => $secondaryId, 'moved' => $moved, 'sequences_stopped' => $stopped, 'jobs_cancelled' => $cancelled, 'filled' => array_values(array_diff(array_keys($fill), ['flags']))]);
                ActivityLog::record('lead_merged_into', $userId, 'lead', $secondaryId, ['into' => $primaryId]);
                return ['ok' => true, 'msg' => 'Leads fusionados: ' . Fmt::code($secondaryId) . ' pasó a ' . Fmt::code($primaryId) . '. Esta acción no se puede deshacer.', 'primary' => $primaryId];
            });
        } finally {
            Db::unlock('captacion_ingest');
        }
    }
}
