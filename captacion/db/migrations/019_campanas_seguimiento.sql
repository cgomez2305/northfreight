-- 019_campanas_seguimiento: segundo correo, clics y respuestas de las campañas a prospectos (fase AT, 2026-11-25; Carlos: «empieza por todas las de captación saliente»).
--  1) prospect_campaigns: segundo correo opcional a los N días (followup_days 0 = sin segundo correo) con asunto y cuerpo propios.
--  2) prospect_campaign_recipients: clic en un enlace del correo (clicked_at, click_count; /r/{token}), respuesta leída del buzón (replied_at) y estado del
--     segundo correo en la misma fila (fu_status: queued | sent | failed | skipped; fu_sent_at; fu_skip_reason).
--  3) prospects_fmcsa.email_replied_at: el prospecto respondió a un correo; entra en la «Lista de hoy» hasta que se le llame.

ALTER TABLE prospect_campaigns ADD COLUMN followup_days TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER body;
ALTER TABLE prospect_campaigns ADD COLUMN followup_subject VARCHAR(190) NULL AFTER followup_days;
ALTER TABLE prospect_campaigns ADD COLUMN followup_body MEDIUMTEXT NULL AFTER followup_subject;

ALTER TABLE prospect_campaign_recipients ADD COLUMN clicked_at DATETIME NULL AFTER open_count;
ALTER TABLE prospect_campaign_recipients ADD COLUMN click_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER clicked_at;
ALTER TABLE prospect_campaign_recipients ADD COLUMN replied_at DATETIME NULL AFTER click_count;
ALTER TABLE prospect_campaign_recipients ADD COLUMN fu_status VARCHAR(10) NULL AFTER replied_at;
ALTER TABLE prospect_campaign_recipients ADD COLUMN fu_sent_at DATETIME NULL AFTER fu_status;
ALTER TABLE prospect_campaign_recipients ADD COLUMN fu_skip_reason VARCHAR(60) NULL AFTER fu_sent_at;
ALTER TABLE prospect_campaign_recipients ADD KEY idx_pcr_email (email);

ALTER TABLE prospects_fmcsa ADD COLUMN email_replied_at DATETIME NULL AFTER last_emailed_at;
