<?php
/**
 * Catalogos de etiquetas y reglas del panel (todo en espanol): perfiles, temperaturas, fuentes, equipos, motivos de perdida, resultados de llamada,
 * tipos de evento de la linea de tiempo y pipelines (doc §7). Fuente unica: las vistas y los servicios leen de aqui.
 */

declare(strict_types=1);

namespace Captacion\Services;

final class Catalog
{
    public const PROFILES = ['owner_operator' => 'Owner-operator', 'small_carrier' => 'Carrier pequeño', 'company_driver' => 'Company driver'];
    public const PROFILES_SHORT = ['owner_operator' => 'Owner-op', 'small_carrier' => 'Carrier', 'company_driver' => 'Driver'];
    public const TEMPS = ['hot' => 'Caliente', 'warm' => 'Tibio', 'cold' => 'Frío', 'disqualified' => 'Descartado'];
    public const TEMPS_PLURAL = ['hot' => 'Calientes', 'warm' => 'Tibios', 'cold' => 'Fríos', 'disqualified' => 'Descartados'];
    public const LANGS = ['en' => 'Inglés', 'es' => 'Español'];
    public const CDL = ['standard' => 'CDL estándar', 'non_domiciled' => 'CDL no domiciliada', 'unsure' => 'No está seguro'];
    public const ENGLISH = ['yes' => 'Sí', 'basic' => 'Básico', 'no' => 'No'];
    public const AUTHORITY = ['active' => 'Activa', 'getting' => 'En trámite', 'none' => 'Sin autoridad', 'inactive' => 'Inactiva', 'unknown' => 'Sin verificar'];

    public const SOURCES = [
        'web_careers' => 'Sitio · Careers', 'web' => 'Sitio web', 'n8n_whatsapp' => 'WhatsApp', 'manual' => 'Carga manual', 'import' => 'Importación',
        'meta' => 'Meta Ads', 'indeed' => 'Indeed', 'fmcsa_outbound' => 'FMCSA saliente', 'referral' => 'Referido',
    ];

    public const EQUIPMENT = [
        'dry-van' => 'Dry van', 'reefer' => 'Reefer', 'flatbed' => 'Flatbed', 'box-truck' => 'Box truck', 'hotshot' => 'Hotshot', 'power-only' => 'Power only',
        'amazon-relay' => 'Amazon Relay', 'step-deck' => 'Step deck', 'other' => 'Otro',
    ];

    /** Pipelines (doc §7). El orden de las etapas es el del embudo; 'lost' es la salida (Perdido en A, Descartado en B). */
    public const STAGES = [
        'A' => [
            'new' => 'Nuevo', 'contacted' => 'Contactado', 'qualified' => 'Calificado', 'call_booked' => 'Llamada agendada',
            'presented' => 'Presentado a NorthStar', 'signed' => 'Firmado', 'lost' => 'Perdido',
        ],
        'B' => [
            'new' => 'Nuevo', 'contacted' => 'Contactado', 'prequalified' => 'Precalificado',
            'presented' => 'Presentado a NorthStar', 'hired' => 'Contratado', 'lost' => 'Descartado',
        ],
    ];

    /** Motivos de perdida (lista fija del doc §7, para reportes). */
    public const LOSS_REASONS = [
        'no_answer' => 'No contesta', 'chose_other' => 'Eligió otro dispatcher', 'price' => 'Precio', 'license' => 'No apto por licencia',
        'english' => 'No apto por inglés', 'no_authority' => 'Sin autoridad o seguro', 'equipment' => 'Equipo que no se despacha',
        'curious' => 'Solo curioseaba', 'rejected' => 'Rechazado por NorthStar', 'other' => 'Otro',
    ];

    public const CALL_OUTCOMES = ['answered' => 'Contestó', 'no_answer' => 'No contestó', 'voicemail' => 'Buzón de voz', 'wrong_number' => 'Número equivocado'];
    public const PRIORITIES = [1 => 'Alta', 2 => 'Media', 3 => 'Baja'];

    public const DISQUALIFIED = ['cdl_non_domiciled' => 'CDL no domiciliada', 'english_no' => 'No habla inglés'];

    public const FLAGS = [
        'cdl_unsure' => 'CDL sin confirmar', 'below_min_experience' => 'Experiencia bajo el mínimo', 'fmcsa_pending' => 'FMCSA pendiente', 'usdot_not_found' => 'USDOT no encontrado',
        'phone_invalid' => 'Teléfono inválido', 'intl_phone' => 'Teléfono internacional', 'stop' => 'STOP (sin contacto)', 'stop_sms' => 'STOP (SMS)', 'stop_call' => 'STOP (llamada)', 'stop_email' => 'STOP (correo)',
        'red:authority_inactive' => 'Autoridad inactiva', 'red:no_insurance' => 'Sin seguro registrado',
    ];

    public const EVENT_LABELS = [
        'created' => 'Lead recibido', 'reentry' => 'Volvió a escribir', 'reopened' => 'Reabierto por reingreso', 'reentry_signed' => 'Un lead firmado volvió a escribir', 'scored' => 'Puntuación recalculada',
        'fmcsa' => 'Consulta a FMCSA', 'disqualified' => 'Descartado automáticamente', 'alert_sent' => 'Alerta enviada', 'consent_revoked' => 'Consentimiento revocado',
        'stage_changed' => 'Cambio de etapa', 'note_added' => 'Nota añadida', 'note_edited' => 'Nota editada', 'note_deleted' => 'Nota eliminada', 'task_created' => 'Tarea creada',
        'task_done' => 'Tarea completada', 'call_logged' => 'Llamada registrada', 'reopened_manual' => 'Lead reabierto', 'a3_cancelled' => 'Segunda alerta cancelada',
        'task_reopened' => 'Tarea reabierta', 'task_snoozed' => 'Tarea pospuesta', 'task_edited' => 'Tarea editada', 'task_deleted' => 'Tarea eliminada',
        'handoff_created' => 'Entrega a NorthStar creada', 'handoff_resent' => 'Entrega a NorthStar reenviada', 'handoff_no_target' => 'Entrega sin destino configurado', 'handoff_sent' => 'Entrega enviada',
        'handoff_failed' => 'La entrega falló', 'handoff_confirmed' => 'NorthStar confirmó la firma', 'handoff_rejected' => 'NorthStar rechazó el lead', 'conversion' => 'Conversión registrada',
        'sequence_enrolled' => 'Inscrito en una secuencia', 'sequence_paused' => 'Secuencia en pausa', 'sequence_resumed' => 'Secuencia reanudada', 'sequence_stopped' => 'Secuencia detenida',
        'sequence_step_skipped' => 'Paso de secuencia omitido', 'sequence_email' => 'Correo de secuencia', 'lead_responded' => 'Marcado como respondido',
    ];

    /** Grupo de filtro de la linea de tiempo de cada tipo de evento. */
    public const EVENT_GROUPS = [
        'call_logged' => 'contact', 'stage_changed' => 'stage', 'reopened_manual' => 'stage', 'reopened' => 'stage',
        'note_added' => 'note', 'note_edited' => 'note', 'note_deleted' => 'note', 'task_created' => 'task', 'task_done' => 'task', 'task_reopened' => 'task', 'task_snoozed' => 'task', 'task_edited' => 'task', 'task_deleted' => 'task',
    ];

    /** Etiquetas del desglose de la puntuacion (las de Scoring van sin acentos). */
    public const SCORE_LABELS = [
        'cdl_standard' => 'CDL estándar', 'cdl_a_standard' => 'CDL-A estándar', 'english' => 'Inglés', 'fmcsa_authority_active' => 'Autoridad activa (FMCSA)',
        'fmcsa_insured' => 'Seguro vigente (FMCSA)', 'authority_age' => 'Antigüedad de la autoridad', 'dispatched_equipment' => 'Equipo que NorthStar despacha',
        'trucks' => 'Número de camiones', 'experience' => 'Experiencia', 'hiring_state' => 'Estado de contratación', 'endorsement' => 'Endosos (Hazmat, Tanker)',
        'referral' => 'Referido', 'complete' => 'Formulario completo',
    ];

    private const FIELD_LABELS = [
        'name' => 'nombre', 'phone' => 'teléfono', 'email' => 'correo', 'state' => 'estado', 'language' => 'idioma', 'truck_count' => 'camiones', 'equipment_types' => 'equipo',
        'years_experience' => 'experiencia', 'authority_status' => 'autoridad', 'cdl_type' => 'CDL', 'english_level' => 'inglés',
    ];

    /** Nota corta del desglose en español ("2 años", "falta: teléfono"). */
    public static function scoreNote(string $note): string
    {
        if ($note === '') {
            return '';
        }
        if ($note === 'cdl_unsure') {
            return 'sin confirmar';
        }
        if ($note === 'sin dato') {
            return 'sin dato';
        }
        if (isset(self::ENGLISH[$note])) {
            return self::ENGLISH[$note];
        }
        if (str_starts_with($note, 'falta: ')) {
            return 'falta ' . implode(', ', array_map(static fn($f) => self::FIELD_LABELS[trim($f)] ?? trim($f), explode(',', substr($note, 7))));
        }
        return (string) preg_replace_callback('/(\d+) anos?/', static fn($m) => $m[1] . ((int) $m[1] === 1 ? ' año' : ' años'), $note);
    }

    public static function label(array $map, ?string $key, string $fallback = '—'): string
    {
        if ($key === null || $key === '') {
            return $fallback;
        }
        return $map[$key] ?? ucfirst(str_replace(['_', '-'], ' ', $key));
    }

    public static function source(?string $key): string
    {
        return self::label(self::SOURCES, $key, 'Sin fuente');
    }

    public static function pipelineOf(string $profile): string
    {
        return $profile === 'company_driver' ? 'B' : 'A';
    }

    public static function stageLabel(string $pipeline, ?string $stage): string
    {
        $map = self::STAGES[$pipeline] ?? self::STAGES['A'];
        return $map[$stage ?? ''] ?? (self::STAGES['A'][$stage ?? ''] ?? self::STAGES['B'][$stage ?? ''] ?? ucfirst((string) $stage));
    }

    /** Nombre de etapa sin conocer el pipeline (listados): usa el de A y, si no, el de B. */
    public static function stageAny(?string $stage): string
    {
        return self::STAGES['A'][$stage ?? ''] ?? self::STAGES['B'][$stage ?? ''] ?? ucfirst((string) $stage);
    }

    /** Todas las claves de etapa conocidas (filtros de la bandeja). @return array<string,string> */
    public static function allStages(): array
    {
        return self::STAGES['A'] + ['prequalified' => 'Precalificado', 'hired' => 'Contratado'];
    }
}
