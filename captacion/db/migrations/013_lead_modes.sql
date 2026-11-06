-- 013_lead_modes: fase Q5c (vistas Tabla, Calendario y Cronología). SQL estándar (MariaDB y MySQL 8); el migrador registra la versión y no la repite.
-- 1) Una vista guardada recuerda también el modo (lista, tablero, tabla, calendario o cronología). NULL = la pantalla en la que se abre.
ALTER TABLE saved_views ADD COLUMN mode VARCHAR(12) NULL AFTER pipeline;
-- 2) Cronología global: los eventos de todos los leads por fecha (desc) con cursor (created_at, id); sin este índice cada página recorrería la tabla entera.
ALTER TABLE lead_events ADD KEY idx_events_created (created_at, id);
-- 3) Calendario por rango visible: tareas por fecha de vencimiento (hechas o no) y entregas programadas (jobs pendientes por tipo y hora).
ALTER TABLE tasks ADD KEY idx_tasks_due_range (deleted_at, due_at);
ALTER TABLE jobs ADD KEY idx_jobs_type_run (type, status, run_at);
