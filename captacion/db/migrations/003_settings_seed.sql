-- 003_settings_seed: pesos y umbrales de puntuacion (documento maestro §6) y ajustes PROVISIONALES hasta que NorthStar confirme.
-- Se editan desde Configuracion (fase Q2) sin tocar codigo. Los valores de abajo deben coincidir con Captacion\Services\Scoring::DEFAULTS (lo comprueba tests/run.php).
-- Tramos: {"lt": N, "points": P} = "menor que N"; "lt": null = el resto.

INSERT INTO settings (`key`, value) VALUES ('scoring.owner', '{"cdl_standard":20,"english_yes":15,"english_basic":5,"fmcsa_authority_active":15,"fmcsa_insured":10,"authority_age":[{"lt":6,"points":5},{"lt":24,"points":10},{"lt":null,"points":10}],"dispatched_equipment":10,"trucks":[{"lt":2,"points":5},{"lt":6,"points":10},{"lt":null,"points":15}],"experience_min_years":2,"experience":5,"referral":5,"complete":5,"complete_fields":["name","phone","email","state","language","truck_count","equipment_types","years_experience","authority_status","cdl_type","english_level"]}');

INSERT INTO settings (`key`, value) VALUES ('scoring.driver', '{"cdl_a_standard":25,"english_yes":15,"english_basic":5,"experience":[{"lt":1,"points":0},{"lt":3,"points":10},{"lt":null,"points":20}],"hiring_state":15,"endorsement":5,"referral":10,"complete":5,"complete_fields":["name","phone","email","state","language","years_experience","cdl_type","english_level"]}');

INSERT INTO settings (`key`, value) VALUES ('scoring.temperature', '{"hot_min":70,"warm_min":40}');

-- PROVISIONAL (documento maestro §6, [CONFIRMAR CON CLIENTE]): equipos que NorthStar despacha. Sembrado con los 6 equipos del §4.
INSERT INTO settings (`key`, value) VALUES ('dispatched_equipment', '["dry-van","reefer","flatbed","box-truck","hotshot","power-only"]');

-- PROVISIONAL: estados donde NorthStar contrata company drivers (lista vacia = nadie recibe los +15 hasta que el cliente la confirme).
INSERT INTO settings (`key`, value) VALUES ('hiring_states', '[]');

-- PROVISIONAL: experiencia minima (anos) de un company driver; por debajo se marca la bandera below_min_experience (no descarta).
INSERT INTO settings (`key`, value) VALUES ('driver_min_years', '1');

INSERT INTO settings (`key`, value) VALUES ('provisional', '["dispatched_equipment","hiring_states","driver_min_years","scoring.driver.experience"]');
