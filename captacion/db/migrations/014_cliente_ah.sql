-- 014_cliente_ah: fase AH (respuestas del cliente, 2026-11-20). NorthStar solo atiende Amazon Relay y dry van, y a un company driver le pide 2 años de experiencia.
-- Solo se cambia lo que sigue exactamente como lo sembró 003: si Carlos ya lo editó desde Configuración, no se toca. Estándar en MariaDB y MySQL 8.
UPDATE settings SET value = '["dry-van","amazon-relay"]'
 WHERE `key` = 'dispatched_equipment' AND value = '["dry-van","reefer","flatbed","box-truck","hotshot","power-only"]';
UPDATE settings SET value = '2' WHERE `key` = 'driver_min_years' AND value = '1';
-- Equipos y años mínimos dejan de ser provisionales; estados de contratación y tramos de experiencia siguen pendientes.
UPDATE settings SET value = '["hiring_states","scoring.driver.experience"]'
 WHERE `key` = 'provisional' AND value = '["dispatched_equipment","hiring_states","driver_min_years","scoring.driver.experience"]';
