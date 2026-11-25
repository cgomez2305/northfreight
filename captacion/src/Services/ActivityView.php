<?php
/**
 * Registro de actividad (/actividad, doc §9.11): consulta con filtros (tipo, usuario, fechas, texto), descripcion legible de cada fila, exportacion CSV y
 * retencion configurable (settings['activity.retention_days'], 365 por defecto; el cron diario purga lo mas viejo). Nunca muestra ni exporta secretos: el log
 * no los guarda (ver ActivityLog) y los detalles se recortan.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class ActivityView
{
    public const PER = 50;
    public const EXPORT_MAX = 20000;
    public const RETENTION_DEFAULT = 365;
    public const RETENTION_MIN = 30;
    public const RETENTION_MAX = 3650;

    /** grupo => [etiqueta, acciones] */
    public const GROUPS = [
        'login'  => ['Accesos y seguridad', ['login_ok', 'login_failed', 'login_2fa_failed', 'login_blocked', 'recovery_code_used', 'logout', 'reauth_ok', 'reauth_failed', 'reauth_blocked', 'setup_admin', 'setup_failed']],
        'export' => ['Exportaciones y descargas', ['leads_export', 'activity_export', 'backup_download']],
        'config' => ['Cambios de configuración', ['settings_change', 'cost_save', 'scoring_recalc', 'integration_test', 'backup_run', 'activity_retention']],
        'stage'  => ['Cambios de etapa', ['lead_stage', 'leads_bulk_stage', 'lead_reopen']],
        'delete' => ['Borrados', ['note_delete', 'task_delete', 'cost_delete', 'backup_purge', 'activity_purge']],
        'lead'   => ['Trabajo sobre leads', ['note_add', 'note_edit', 'task_add', 'task_done', 'task_reopen', 'task_edit', 'task_snooze', 'task_resched', 'call_log', 'consent_revoke']],
    ];

    public const LABELS = [
        'login_ok' => 'Inicio de sesión', 'login_failed' => 'Contraseña incorrecta', 'login_2fa_failed' => 'Código 2FA incorrecto', 'login_blocked' => 'Acceso bloqueado', 'recovery_code_used' => 'Código de recuperación usado',
        'logout' => 'Cierre de sesión', 'reauth_ok' => 'Identidad confirmada', 'reauth_failed' => 'Confirmación fallida', 'reauth_blocked' => 'Confirmación bloqueada', 'setup_admin' => 'Alta del administrador', 'setup_failed' => 'Alta fallida',
        'leads_export' => 'Exportación de leads', 'activity_export' => 'Exportación del registro', 'backup_download' => 'Descarga de copia',
        'settings_change' => 'Ajuste cambiado', 'cost_save' => 'Costo guardado', 'scoring_recalc' => 'Recálculo de puntuación', 'integration_test' => 'Prueba de integración', 'backup_run' => 'Copia de seguridad', 'activity_retention' => 'Retención cambiada',
        'lead_stage' => 'Cambio de etapa', 'leads_bulk_stage' => 'Cambio de etapa masivo', 'lead_reopen' => 'Lead reabierto',
        'note_delete' => 'Nota eliminada', 'task_delete' => 'Tarea eliminada', 'cost_delete' => 'Costo eliminado', 'backup_purge' => 'Copias antiguas borradas', 'activity_purge' => 'Registro purgado',
        'note_add' => 'Nota añadida', 'note_edit' => 'Nota editada', 'task_add' => 'Tarea creada', 'task_done' => 'Tarea completada', 'task_reopen' => 'Tarea reabierta', 'task_edit' => 'Tarea editada', 'task_snooze' => 'Tarea pospuesta', 'task_resched' => 'Tarea movida de día',
        'call_log' => 'Llamada registrada', 'consent_revoke' => 'Consentimiento revocado',
        // fase AR: acciones de las fases Q3 a AQ que salían en inglés («Prospect campaign launch»)
        'daily_digest' => 'Resumen diario enviado', 'handoff' => 'Entrega a NorthStar', 'handoff_sent' => 'Entrega enviada', 'handoff_failed' => 'Entrega fallida', 'handoff_rejected' => 'NorthStar rechazó',
        'handoff_signed' => 'NorthStar confirmó la firma', 'handoff_resend' => 'Entrega reenviada', 'handoff_test' => 'Prueba de entrega', 'handoff_retry_pending' => 'Reintento de entregas', 'handoff_secret_changed' => 'Secreto de entrega cambiado',
        'invite_accepted' => 'Invitación aceptada', 'user_invite' => 'Usuario invitado', 'user_activate' => 'Usuario activado', 'user_relink' => 'Enlace de acceso renovado', 'user_reset_2fa' => '2FA restablecido',
        'job_create' => 'Vacante creada', 'job_update' => 'Vacante editada', 'job_delete' => 'Vacante borrada', 'internal_patch' => 'Cambio por la API interna',
        'lead_assign' => 'Responsable asignado', 'leads_bulk_assign' => 'Asignación en lote', 'leads_assign_undo' => 'Asignación deshecha', 'lead_create_manual' => 'Lead creado a mano', 'lead_anonymize' => 'Lead anonimizado',
        'lead_merge' => 'Leads fusionados', 'lead_merged_into' => 'Fusionado en otro lead', 'lead_responded' => 'Primera respuesta', 'lead_stage_undo' => 'Cambio de etapa deshecho', 'lead_template_sent' => 'Plantilla enviada', 'lead_whatsapp_opened' => 'WhatsApp abierto',
        'lead_import_upload' => 'Importación: archivo subido', 'lead_import_start' => 'Importación iniciada', 'lead_import_done' => 'Importación terminada', 'lead_import_discard' => 'Importación descartada', 'note_restore' => 'Nota restaurada',
        'prospect_call' => 'Llamada a prospecto', 'prospect_convert' => 'Prospecto convertido en lead', 'prospect_reopen' => 'Prospecto reabierto', 'prospect_unsubscribe' => 'Baja de correo de un prospecto', 'prospects_export' => 'Exportación de prospectos', 'prospects_window_reset' => 'Ventana de llamadas reiniciada',
        'prospect_campaign_create' => 'Campaña creada', 'prospect_campaign_update' => 'Campaña editada', 'prospect_campaign_test' => 'Prueba de campaña enviada', 'prospect_campaign_launch' => 'Campaña lanzada', 'prospect_campaign_pause' => 'Campaña pausada',
        'prospect_campaign_autopause' => 'Campaña pausada sola', 'prospect_campaign_resume' => 'Campaña reanudada', 'prospect_campaign_cancel' => 'Campaña cancelada', 'prospect_campaign_done' => 'Campaña terminada', 'prospect_campaign_delete' => 'Campaña borrada', 'prospect_mail_settings' => 'Ajustes de correos a prospectos', 'prospect_reply' => 'Un prospecto respondió', 'prospects_relist' => 'No interesados de vuelta a la lista',
        'referral_code_create' => 'Código de referido creado', 'referral_code_enable' => 'Código de referido activado', 'referral_code_amount' => 'Monto del bono cambiado', 'referral_signed' => 'Referido firmado',
        'report_generate' => 'Informe generado', 'report_publish' => 'Informe publicado', 'report_send' => 'Informe enviado', 'report_actions' => 'Acciones del informe editadas', 'report_export' => 'Exportación de reporte', 'report_weekly_sent' => 'Resumen semanal enviado',
        'rules_draft_save' => 'Borrador de reglas guardado', 'rules_draft_discard' => 'Borrador de reglas descartado', 'rules_publish' => 'Reglas publicadas', 'rules_unpublish' => 'Reglas retiradas', 'rules_revert' => 'Reglas revertidas', 'rules_reset_all' => 'Reglas restablecidas',
        'rules_set_create' => 'Conjunto de reglas creado', 'rules_set_delete' => 'Conjunto de reglas borrado', 'rules_sim_start' => 'Simulación de reglas', 'rules_sim_export' => 'Simulación exportada',
        'sequence_pause' => 'Secuencia pausada', 'sequence_resume' => 'Secuencia reanudada', 'sequence_remove' => 'Secuencia quitada', 'unsubscribe' => 'Baja de correo',
        'template_create' => 'Plantilla creada', 'template_update' => 'Plantilla editada', 'template_delete' => 'Plantilla borrada', 'template_restore' => 'Plantilla restaurada', 'template_test' => 'Prueba de plantilla',
        'view_create' => 'Vista creada', 'view_update' => 'Vista actualizada', 'view_rename' => 'Vista renombrada', 'view_delete' => 'Vista borrada', 'webhooks_out_test' => 'Prueba de webhooks', 'webhooks_out_secret_changed' => 'Secreto de webhooks cambiado',
    ];

    public static function groupOf(string $action): string
    {
        foreach (self::GROUPS as $k => [, $actions]) {
            if (in_array($action, $actions, true)) {
                return $k;
            }
        }
        return 'lead';
    }

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? ucfirst(str_replace('_', ' ', $action));
    }

    /** @return array<string,string> filtros validos de la URL */
    public static function filters(array $in): array
    {
        $s = static fn(string $k, int $max = 100): string => is_string($in[$k] ?? null) ? mb_substr(trim($in[$k]), 0, $max) : '';
        $f = [];
        if (isset(self::GROUPS[$s('ty')])) {
            $f['ty'] = $s('ty');
        }
        if (preg_match('/^\d{1,10}$/', $s('us')) === 1) {
            $f['us'] = $s('us');
        }
        foreach (['from', 'to'] as $k) {
            if (Fmt::fromEtInput($s($k)) !== null) {
                $f[$k] = $s($k);
            }
        }
        if ($s('q') !== '') {
            $f['q'] = $s('q');
        }
        return $f;
    }

    /** @return array{0:string,1:list<mixed>} */
    private static function where(array $f): array
    {
        $w = ['1=1'];
        $p = [];
        if (isset($f['ty'])) {
            $actions = self::GROUPS[$f['ty']][1];
            $w[] = 'a.action IN (' . implode(',', array_fill(0, count($actions), '?')) . ')';
            array_push($p, ...$actions);
        }
        if (isset($f['us'])) {
            $w[] = 'a.user_id = ?';
            $p[] = (int) $f['us'];
        }
        if (isset($f['from'])) {
            $w[] = 'a.created_at >= ?';
            $p[] = Fmt::fromEtInput($f['from']);
        }
        if (isset($f['to'])) {
            $w[] = 'a.created_at <= ?';
            $p[] = Fmt::fromEtInput($f['to'], true);
        }
        if (isset($f['q'])) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
            $w[] = '(a.action LIKE ? OR a.entity_id LIKE ? OR a.ip LIKE ? OR a.agent LIKE ? OR a.changes LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
            array_push($p, $like, $like, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $w), $p];
    }

    /** @return array{rows:list<array>,total:int,page:int,pages:int} */
    public static function query(array $f, int $page, int $per = self::PER): array
    {
        [$where, $p] = self::where($f);
        $total = (int) Db::value("SELECT COUNT(*) FROM activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE $where", $p);
        $pages = max(1, (int) ceil($total / $per));
        $page = max(1, min($page, $pages));
        $rows = Db::all(
            "SELECT a.id, a.user_id, a.action, a.entity_type, a.entity_id, a.changes, a.ip, a.agent, a.created_at, u.name AS user_name, u.email AS user_email
               FROM activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE $where ORDER BY a.id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per),
            $p
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    private static function val(mixed $v): string
    {
        if (is_array($v)) {
            return mb_substr((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 60);
        }
        return $v === null ? '—' : mb_substr((string) (is_bool($v) ? ($v ? 'sí' : 'no') : $v), 0, 60);
    }

    /** Descripcion corta y legible del detalle de una fila (texto plano; la vista lo escapa). */
    public static function describe(array $r): string
    {
        $c = Db::decode($r['changes'] ?? null);
        $a = (string) $r['action'];
        switch ($a) {
            case 'login_ok':
                return ($c['via'] ?? '') === 'recovery' ? 'Con código de recuperación' : 'Con contraseña y código de la app';
            case 'login_failed':
            case 'login_blocked':
                return isset($c['email']) ? 'Correo: ' . self::val($c['email']) : '';
            case 'lead_stage':
                return 'Lead #' . $r['entity_id'] . ': ' . Catalog::stageAny((string) ($c['from'] ?? '')) . ' → ' . Catalog::stageAny((string) ($c['to'] ?? '')) . (!empty($c['reason']) ? ' (' . Catalog::label(Catalog::LOSS_REASONS, (string) $c['reason']) . ')' : '') . (!empty($c['override']) ? ' · con salto de orden' : '');
            case 'leads_bulk_stage':
                return ($c['changed'] ?? 0) . ' cambiados, ' . ($c['skipped'] ?? 0) . ' omitidos → ' . Catalog::stageAny((string) ($c['stage'] ?? ''));
            case 'leads_export':
                return ($c['rows'] ?? 0) . ' filas · ' . ($c['scope'] ?? '');
            case 'activity_export':
                return ($c['rows'] ?? 0) . ' filas';
            case 'settings_change':
                $parts = [];
                foreach (array_slice((array) ($c['diff'] ?? []), 0, 3, true) as $path => $pair) {
                    $parts[] = $path . ': ' . self::val($pair[0] ?? null) . ' → ' . self::val($pair[1] ?? null);
                }
                $more = count((array) ($c['diff'] ?? [])) - 3;
                return $r['entity_id'] . ' · ' . implode(' · ', $parts) . ($more > 0 ? ' · y ' . $more . ' más' : '');
            case 'cost_save':
            case 'cost_delete':
                return Catalog::source((string) ($c['source'] ?? '')) . ' ' . ($c['period'] ?? '') . ': ' . self::val($c['before'] ?? null) . ' → ' . self::val($c['after'] ?? null);
            case 'integration_test':
                return $r['entity_id'] . ': ' . (!empty($c['ok']) ? 'correcto' : 'falló');
            case 'prospect_campaign_launch':
                return 'Campaña #' . $r['entity_id'] . ' · ' . (int) ($c['recipients'] ?? 0) . ' destinatarios';
            case 'prospect_campaign_done':
                return 'Campaña #' . $r['entity_id'] . ' · ' . (int) ($c['sent'] ?? 0) . ' enviados, ' . (int) ($c['skipped'] ?? 0) . ' omitidos, ' . (int) ($c['failed'] ?? 0) . ' fallidos';
            case 'prospect_campaign_create':
            case 'prospect_campaign_update':
            case 'prospect_campaign_test':
            case 'prospect_campaign_pause':
            case 'prospect_campaign_resume':
            case 'prospect_campaign_cancel':
            case 'prospect_campaign_delete':
                return 'Campaña #' . $r['entity_id'] . (!empty($c['reason']) ? ' · ' . self::val($c['reason']) : '');
            case 'prospect_unsubscribe':
                return 'Prospecto #' . $r['entity_id'] . (($c['via'] ?? '') === 'unsubscribe_link' ? ' · desde el enlace del correo' : '');
            case 'backup_download':
                return 'Copia ' . ($c['file'] ?? '#' . $r['entity_id']);
            case 'backup_run':
            case 'backup_purge':
            case 'activity_purge':
            case 'activity_retention':
            default:
                $parts = [];
                foreach ($c as $k => $v) {
                    $parts[] = $k . ': ' . self::val($v);
                    if (count($parts) >= 4) {
                        break;
                    }
                }
                return ($r['entity_type'] !== null && $r['entity_id'] !== null && !in_array($a, ['backup_run', 'backup_purge', 'activity_purge', 'activity_retention'], true) ? $r['entity_type'] . ' #' . $r['entity_id'] . ($parts ? ' · ' : '') : '') . implode(' · ', $parts);
        }
    }

    /** CSV (UTF-8 con BOM, protegido contra formulas). @param list<array> $rows */
    public static function csv(array $rows): string
    {
        $out = "\xEF\xBB\xBF" . Csv::line(['Fecha (' . Fmt::zone() . ')', 'Usuario', 'Correo', 'Tipo', 'Acción', 'Detalle', 'Entidad', 'IP', 'Agente']);
        foreach ($rows as $r) {
            $out .= Csv::line([
                Fmt::et((string) $r['created_at'])?->format('Y-m-d H:i:s') ?? '', $r['user_name'] ?? 'Sistema', $r['user_email'] ?? '', self::GROUPS[self::groupOf((string) $r['action'])][0], self::label((string) $r['action']),
                self::describe($r), ($r['entity_type'] ?? '') . ($r['entity_id'] !== null ? ' #' . $r['entity_id'] : ''), $r['ip'] ?? '', $r['agent'] ?? '',
            ]);
        }
        return $out;
    }

    // --- Retencion ------------------------------------------------------------------------------------------------------

    public static function retentionDays(): int
    {
        $d = (int) Settings::get('activity.retention_days', self::RETENTION_DEFAULT);
        return $d >= self::RETENTION_MIN && $d <= self::RETENTION_MAX ? $d : self::RETENTION_DEFAULT;
    }

    /** @return array{ok:bool,msg:string} */
    public static function setRetention(mixed $days, int $userId): array
    {
        $raw = is_string($days) ? trim($days) : '';
        if (preg_match('/^\d{2,4}$/', $raw) !== 1 || (int) $raw < self::RETENTION_MIN || (int) $raw > self::RETENTION_MAX) {
            return ['ok' => false, 'msg' => 'Escribe los días de retención entre ' . self::RETENTION_MIN . ' y ' . self::RETENTION_MAX . '.'];
        }
        $before = self::retentionDays();
        if ($before === (int) $raw) {
            return ['ok' => true, 'msg' => 'Sin cambios: la retención ya era de ' . $before . ' días.'];
        }
        Settings::set('activity.retention_days', (int) $raw);
        ActivityLog::record('activity_retention', $userId, 'settings', 'activity.retention_days', ['before' => $before, 'after' => (int) $raw]);
        return ['ok' => true, 'msg' => 'Retención guardada: ' . (int) $raw . ' días. El cron diario borra lo más antiguo.'];
    }

    /** Borra el registro mas antiguo que la retencion y deja una fila que lo cuenta. @return int filas borradas */
    public static function purge(): int
    {
        $cut = Clock::shift(-self::retentionDays() * 86400);
        $n = Db::run('DELETE FROM activity_log WHERE created_at < ?', [$cut])->rowCount();
        if ($n > 0) {
            ActivityLog::record('activity_purge', null, 'settings', 'activity.retention_days', ['deleted' => $n, 'older_than' => $cut]);
        }
        return $n;
    }
}
