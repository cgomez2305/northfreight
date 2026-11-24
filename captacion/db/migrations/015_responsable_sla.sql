-- 015_responsable_sla: fase AM-operacion. SQL estándar (MariaDB 10.11 y MySQL 8); el migrador registra la versión y no la repite.
-- 1) Responsable del lead: id del usuario (admin activo) a cargo. NULL = sin asignar. Sin FK a propósito: si un usuario se borra, el lead queda "sin asignar" por la lógica
--    de la aplicación (Assignment::assignable) y no se bloquea el borrado. El índice cubre «Mis leads» y los filtros por responsable.
ALTER TABLE leads ADD COLUMN assigned_to BIGINT UNSIGNED NULL AFTER external_crm_id;
ALTER TABLE leads ADD KEY idx_leads_assigned (assigned_to, deleted_at);
-- 2) Tipo de una tarea creada por el sistema (p. ej. 'sla_first_contact': «Primer contacto con …»); NULL = tarea normal. Sirve para no duplicarla y para cerrarla sola al contactar.
ALTER TABLE tasks ADD COLUMN kind VARCHAR(24) NULL AFTER priority;
ALTER TABLE tasks ADD KEY idx_tasks_kind (kind, lead_id);
