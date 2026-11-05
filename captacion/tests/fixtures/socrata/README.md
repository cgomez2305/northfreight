# Fixtures de Socrata (data.transportation.gov)

Respuestas REALES de la API pública (consultadas el 2026-11-05, solo lectura), recortadas y anonimizadas:

- `authhist_granted.json`: filas de `yu5v-wbh6` (Motus AuthHist - All With History) con `op_auth_status = Active` y `reason = Granted` para «Motor Carrier of Property (Except Household Goods)». Los USDOT y MC se renumeraron (9100001 en adelante y MC8800001 en adelante) para que ninguna fila corresponda a una empresa real.
- `census.json`: filas de `az4n-8mr2` (Company Census File) con solo los campos que usa el CRM. Nombre legal, DBA, teléfono (555-01XX) y correo (example.com) sustituidos; ciudad, estado, flota, banderas de carga, fechas y códigos de estado son reales.
- `error_no_such_column.json`: error real que devuelve Socrata cuando se pide una columna que no existe.

Casos extremos ya incluidos entre las 14 filas: flota de 1, 2, 4, 6 y 50 unidades, un carrier de Columbia Británica y otro de Ontario (fuera de EE. UU.), un USDOT inactivo (`status_code = I`) y uno pendiente (`P`), una fila sin teléfono y otra sin banderas de carga (modificadas a mano), carga refrigerada y estados TX/FL (dos zonas horarias).
