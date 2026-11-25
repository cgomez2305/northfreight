-- 022_captacion_aw: más leads desde la prospección (fase AW, 2026-11-25; Carlos: «realiza todo lo que consideres que nos ayudará a captar más leads con el CRM»).
--  1) prospects_fmcsa.lang: idioma que prefiere el transportista (en | es | NULL = sin saber). Se anota en la llamada; decide el idioma del guion, de los correos
--     automáticos y de las campañas (segmento por idioma) y pasa al lead al convertirlo.
--  2) prospect_campaigns.lang: idioma de la campaña (pie, baja y variables en ese idioma).
--  3) prospect_auto_mails.kind: los correos automáticos ahora son una cadencia: intro (al entrar en la lista), missed (tras no contestar, el de la fase AU)
--     y breakup (último correo cuando se agotan los intentos). Uno de cada tipo por prospecto, como mucho.

ALTER TABLE prospects_fmcsa ADD COLUMN lang CHAR(2) NULL AFTER qual;
ALTER TABLE prospect_campaigns ADD COLUMN lang CHAR(2) NOT NULL DEFAULT 'en' AFTER segment;
ALTER TABLE prospect_auto_mails ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'missed' AFTER prospect_id;
ALTER TABLE prospect_auto_mails ADD UNIQUE KEY uq_pam_kind (prospect_id, kind);
ALTER TABLE prospect_auto_mails DROP INDEX uq_pam_prospect;
