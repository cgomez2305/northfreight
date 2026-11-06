-- 010_channels: fase Q4b. Canales externos (Meta Lead Ads, Cal.com, Twilio, n8n): registro de eventos entrantes para la idempotencia, citas de Cal.com,
-- ventana de 24 h de WhatsApp, seguimiento de los pasos por WhatsApp que envia n8n y recordatorios de citas.

-- Un evento entrante de un tercero = una fila (source, event_key). El UNIQUE hace imposible procesar dos veces el mismo evento (reintentos del emisor).
-- status: received | processed | ignored | error. event_at = instante del evento segun el emisor (sirve para ordenar eventos que llegan desordenados).
-- Sin datos personales: summary es una frase corta ("reserva creada", "STOP", "firma rechazada"), nunca el cuerpo del mensaje.
CREATE TABLE webhook_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source VARCHAR(20) NOT NULL,
  event_key VARCHAR(128) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'received',
  lead_id BIGINT UNSIGNED NULL,
  summary VARCHAR(190) NULL,
  event_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_webhook_event (source, event_key),
  KEY idx_webhook_created (source, created_at),
  KEY idx_webhook_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- appointments (001, 005): citas de Cal.com. calcom_id = uid de la reserva (UNIQUE); prev_calcom_id = uid de la reserva que esta reemplazo al reprogramar
-- (para que un "creada" tardio de la reserva vieja se reconozca como obsoleto). last_event_at ordena los eventos. status: booked | cancelled | completed.
ALTER TABLE appointments ADD COLUMN meeting_url VARCHAR(255) NULL AFTER title;
ALTER TABLE appointments ADD COLUMN tz VARCHAR(40) NULL AFTER meeting_url;
ALTER TABLE appointments ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER tz;
ALTER TABLE appointments ADD COLUMN prev_calcom_id VARCHAR(60) NULL AFTER calcom_id;
ALTER TABLE appointments ADD COLUMN cancelled_at DATETIME NULL AFTER status;
ALTER TABLE appointments ADD COLUMN last_event_at DATETIME NULL AFTER cancelled_at;
ALTER TABLE appointments ADD COLUMN reschedules SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_event_at;
ALTER TABLE appointments ADD COLUMN wa_rem24_at DATETIME NULL AFTER reschedules;
ALTER TABLE appointments ADD COLUMN wa_rem1_at DATETIME NULL AFTER wa_rem24_at;
ALTER TABLE appointments ADD UNIQUE KEY uq_appt_calcom (calcom_id);
ALTER TABLE appointments ADD KEY idx_appt_prev (prev_calcom_id);

-- La tarea "Llamada con X" de una cita se enlaza para poder moverla o cancelarla.
ALTER TABLE tasks ADD COLUMN appointment_id BIGINT UNSIGNED NULL AFTER prospect_id;
ALTER TABLE tasks ADD KEY idx_tasks_appt (appointment_id);

-- Ventana de 24 h de WhatsApp: el ultimo mensaje entrante del lead (cualquier canal) y el ultimo de WhatsApp. NO es consentimiento de marketing.
ALTER TABLE leads ADD COLUMN last_inbound_at DATETIME NULL;
ALTER TABLE leads ADD COLUMN wa_last_inbound_at DATETIME NULL;

-- Paso de la secuencia por el que va el canal de WhatsApp (lo envia n8n, que lee GET /internal/sequences/due): independiente del paso de correo que envia el CRM.
ALTER TABLE sequence_enrollments ADD COLUMN wa_step SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER step;

-- Estado de entrega de SMS/WhatsApp (MessageSid de Twilio, wamid de WhatsApp).
ALTER TABLE messages ADD KEY idx_messages_provider (provider_id);
