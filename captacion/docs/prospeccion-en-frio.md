# Prospección en frío de transportistas: riesgos, reglas y cómo lo hace el sector

Documento de trabajo de la fase AU (2026-11-25). Responde a la pregunta de Carlos: «qué riesgos corremos al contactar prospectos en frío, qué debemos tener en cuenta y si esto ya lo hacen otras empresas».

> **No es asesoría legal.** Resume reglas públicas y fuentes secundarias (bufetes, la FTC, la FCC, proveedores de correo y telefonía) consultadas el 2026-11-25. Antes de escalar las llamadas hay que validarlo con un abogado de EE. UU. que conozca la TCPA. El panel ya lo marca como pendiente en «Reglas de las llamadas en frío».

---

## 1. Lo corto

1. **Sí, es práctica común en el sector.** Las empresas de dispatch llaman y escriben a las autoridades nuevas de FMCSA desde hace años, y hay un mercado entero que vende esas listas. Por lo mismo, **el transportista nuevo está saturado y desconfía**: llegan 20 o más llamadas al día y muchas son estafas. Lo que diferencia a NorthStar no es llamar, sino **cómo** llama.
2. **El mayor riesgo legal está en la automatización, no en llamar a mano.** Marcadores automáticos, mensajes grabados, **voces de IA** y SMS masivos a celulares exigen consentimiento previo por escrito. Las multas de la TCPA son de **500 a 1.500 dólares por llamada o mensaje** y hay demandas colectivas. Llamar a mano, con una persona al teléfono, es la vía de menor riesgo.
3. **El punto gris son los celulares personales.** Muchos owner-operators usan su celular personal como número de la empresa. Para la FCC eso puede contar como **línea residencial**, protegida por la lista nacional de no llamar (DNC) aunque la llamada sea entre empresas. Es lo que hay que consultar con el abogado.
4. **El correo en frío entre empresas es legal en EE. UU.** (CAN-SPAM no pide permiso previo), siempre que lleve dirección física, una baja que funcione y nada engañoso. El riesgo real del correo es **perder la entregabilidad del dominio**, no una multa.
5. **El riesgo de reputación pesa tanto como el legal.** Un número marcado «Spam Likely», una queja en un foro de camioneros o una llamada que suene a estafa le cuestan más a una empresa nueva que una multa.

## 2. ¿Ya lo hacen otras empresas?

Sí, y de forma masiva:

- **Listas de autoridades nuevas a la venta.** En Fiverr, Apify y tiendas propias se venden listas «frescas» sacadas del registro de FMCSA (nombre, teléfono, correo, flota, MC): desde unos 3 a 20 dólares por cada mil registros o paquetes de 100 por 25 dólares. Algunas filtran por flota (1 camión = owner-operator) justo para venderlas a empresas de dispatch.
- **Contacto a los pocos minutos.** La propia FMCSA avisa de que hay empresas que contactan a los transportistas nuevos «a los pocos minutos» de terminar su trámite.
- **Saturación y quejas.** En TheTruckersReport hay hilos titulados «New Authority. Getting solicited with 20+ calls a day»; un camionero escribe que no trabajaría con un dispatch que lo llama de la nada porque «parece un dispatch nuevo buscando sus primeros clientes».
- **Presión regulatoria.** En agosto de 2025 una flota pequeña pidió a la FMCSA que trate a los dispatchers como brókers (con fianza) y que **retire los datos de contacto de SAFER** por el acoso y el fraude. La petición sigue abierta; si prospera, la fuente de datos de esta estrategia podría cerrarse.

**Lectura para NorthStar:** el canal funciona porque el transportista nuevo necesita cargas, pero es un mercado ruidoso. La ventaja está en llegar con algo concreto (Amazon Relay y dry van, MC nuevos, papeleo en orden), presentarse con claridad, no presionar, y dar una forma fácil de verificar a la empresa (la web, WhatsApp).

## 3. Riesgos uno por uno

### 3.1 Llamadas (TCPA y lista nacional de no llamar)

| Práctica | Riesgo | Qué hace el CRM |
|---|---|---|
| Llamada marcada a mano, persona al teléfono | Bajo, si se respetan horario, lista interna de no llamar e identificación | Es lo único que permite: el botón «Llamar» abre el teléfono (`tel:`), sin marcador |
| Marcador automático (autodialer), predictivo o «power dialer» que marca solo | Alto a celulares sin consentimiento | No existe en el panel. El Modo llamada solo muestra el siguiente; marca la persona |
| Mensaje grabado o voz de IA | **Muy alto**: desde febrero de 2024 la FCC trata las voces generadas por IA como «artificiales» y exigen consentimiento previo por escrito | No existe. Por eso **las llamadas con IA (ElevenLabs) quedan fuera** hasta tener consentimiento |
| Llamar fuera de 8 a. m. a 9 p. m. hora local | Infracción directa | El botón se bloquea fuera de horario, con las dos zonas de los estados divididos |
| Volver a llamar a quien pidió que no | Infracción directa | «No llamar más» es permanente y guarda el teléfono aunque FMCSA lo vuelva a entregar |
| Celular personal de un owner-operator en la lista nacional DNC | **Gris**: la FCC mira cómo se usa el teléfono; un celular de uso mixto puede contar como residencial, y en el 9.º circuito el que llama debe probar que era de negocio | Pendiente de abogado. Opción prudente: suscribirse a la lista nacional (SAN de la FTC) y cruzarla antes de llamar |

Además:

- **Regla de Ventas por Teléfono de la FTC (TSR).** Desde mayo de 2024 prohíbe las **afirmaciones falsas o engañosas también en llamadas entre empresas**. No inventar resultados, no decir que se llama de FMCSA, del DOT o de Amazon, no prometer ingresos.
- **Leyes estatales («mini-TCPA»).** Florida y Oklahoma tienen leyes propias más estrictas (Florida las suavizó en 2023, pero siguen exigiendo más que la federal); otros estados tienen listas propias de no llamar. Florida y Texas concentran muchos prospectos.
- **Grabación de llamadas.** California, Florida, Illinois, Maryland, Massachusetts, Montana, New Hampshire, Pensilvania y Washington, entre otros, exigen el consentimiento de las dos partes. No grabar sin avisar al empezar.

### 3.2 SMS y WhatsApp

- Un SMS de marketing a un celular sin consentimiento previo por escrito es el caso típico de demanda TCPA. Además, enviar desde un número de 10 dígitos exige registro A2P 10DLC. **El cliente ya decidió no usar SMS** (fase AN).
- WhatsApp Business solo permite iniciar conversaciones con plantillas aprobadas y a quien dio su número para eso. Escribir en frío por WhatsApp a números de FMCSA arriesga el bloqueo del número de la empresa (que además es el número de contacto del sitio).

### 3.3 Correo

- **CAN-SPAM** (sin permiso previo entre empresas) exige: remitente y asunto honestos, identificarlo como comercial si lo es, **dirección física**, baja que funcione y se respete en 10 días hábiles. El CRM ya lo cumple en campañas y en el correo tras no contestar.
- **Gmail y Yahoo** (desde 2024) piden a quien envía mucho: SPF, DKIM y DMARC, **baja de un clic** (List-Unsubscribe) y una tasa de quejas por debajo del **0,3 %** (Google recomienda menos de 0,1 %). El CRM pone List-Unsubscribe de un clic en cada correo a prospectos.
- **Riesgo de dominio:** los correos salen de `contact@northstarfreightsolutions.us`, el mismo buzón que reciben los clientes. Si los correos en frío generan quejas, también sufren los correos normales. Recomendación: volumen bajo (el tope por hora de 30 ayuda), textos útiles y, si el volumen crece, un subdominio o dominio aparte para la prospección.
- Las reglas de Hostinger para correo masivo también aplican: un pico de envíos puede suspender el buzón.

### 3.4 Datos y listas compradas

- Los datos de FMCSA son públicos, pero **público no es lo mismo que consentimiento**: la ley de llamadas se aplica igual.
- Una lista comprada no traslada la responsabilidad: quien llama responde. El diálogo de importación lo recuerda.
- Ojo con el dato de FMCSA: los transportistas nuevos todavía no siempre tienen MC (desde la migración a Motus de 2026 los MC nuevos tienen 8 dígitos) y el correo del censo a veces es del gestor que hizo el trámite, no del dueño.

### 3.5 Reputación

- **«Spam Likely».** Las operadoras marcan los números que hacen muchas llamadas cortas. Registrar los números de salida en el **Free Caller Registry** (gratis, cubre las tres empresas de análisis que usan las operadoras de EE. UU.), poner un nombre de empresa en el identificador de llamadas y no quemar un número con volumen excesivo.
- **Desconfianza por estafas.** La FMCSA avisa de fraudes que se hacen pasar por ella. El guion dice desde la primera frase que no se llama de FMCSA ni del gobierno, y que nunca se pedirán contraseñas ni datos bancarios por teléfono.
- **Foros y grupos.** Una mala experiencia se publica. Mejor pocas llamadas buenas que muchas insistentes: el CRM limita a 3 intentos sin respuesta.

## 4. Qué tener en cuenta (lista de operación)

1. Llamar solo a mano, con una persona; nada de IA, grabaciones ni SMS en frío.
2. Presentarse: nombre, NorthStar Freight Solutions y motivo. Pedir permiso para seguir.
3. Respetar 8 a. m. a 9 p. m. hora local (el panel lo impone).
4. «No me llame más» → «No llamar más» en esa misma llamada, siempre.
5. Afirmar solo lo que NorthStar hace hoy (Amazon Relay y dry van) y dar los precios como estimación.
6. No grabar sin avisar.
7. Máximo 3 intentos sin respuesta; después, cerrar.
8. Correos: pocos, útiles, con baja de un clic; vigilar quejas.
9. Registrar los números de salida en el Free Caller Registry.
10. **Antes de escalar:** abogado (TCPA, lista nacional DNC y listas estatales) y decidir si se suscribe la lista nacional.

## 5. Lo que el CRM hace en esta fase (fase AU)

- **Modo llamada:** un prospecto a la vez, el siguiente que se puede llamar YA, por prioridad, con el guion abierto; «Saltar» lo deja para después.
- **Prioridad:** primero quien respondió o abrió un enlace de un correo, quien pidió que lo llamaran a una hora que ya llegó, los seguimientos que tocan, flotas de 1 a 10, autoridades recientes y carga general (encaja con dry van y Relay); resta los intentos fallidos. Cada fila dice por qué está arriba.
- **Guion editable** en inglés y español (Configuración > Prospectos) con las reglas de esta guía ya incluidas.
- **Resultados nuevos:** «Dejé mensaje» (cuenta como intento) y «Número equivocado» (cierra sin «no llamar»).
- **Rellamada a una hora concreta** en la hora local del prospecto («llámame a las 3»), validada contra el horario permitido.
- **Datos de la llamada:** contacto, correo dado por teléfono, equipo, cuenta de Relay, si ya tiene dispatcher y mejor horario. Pasan al lead.
- **«Interesado» convierte en lead** en el mismo clic (se puede apagar), con lo anotado y la última nota.
- **Correo automático tras no contestar** (apagado por defecto): uno por prospecto, 10 minutos después, con las mismas reglas de los correos en lote.
- **Meta diaria de llamadas** y conteo de conversaciones e interesados del día.
- **Más fuentes:** añadir un prospecto escribiendo su USDOT (se completa con FMCSA) e importar listas en CSV, siempre filtrando teléfonos de «No llamar más», borrados y leads existentes.

**Lo que NO se hizo, a propósito:** marcador automático, llamadas con IA, SMS y WhatsApp en frío. Cualquiera de ellos necesita primero consentimiento previo por escrito y la revisión del abogado.

### Llamadas por Vonage (fase AV)

El CRM puede llamar por la Voice API de Vonage desde el navegador («clic para llamar»: un clic, una llamada, un número, una persona al teléfono). Para la ley sigue siendo una llamada manual: no hay marcador, ni detección de contestador, ni mensaje grabado. El prospecto ve el número de EE. UU. de NorthStar en lugar de uno extranjero, y el servidor vuelve a comprobar el horario y la lista de no llamar justo antes de marcar. No se graba.

## 6. Ideas para más adelante

- Cruzar la lista nacional DNC (con suscripción SAN) antes de que un prospecto entre a la lista.
- Llamadas con IA **solo para quien ya dio su consentimiento por escrito** (por ejemplo, recordatorios a leads que lo aceptaron en el formulario), nunca en frío.
- Un dominio aparte para la prospección por correo si el volumen pasa de unas decenas al día.
- Medir la tasa de contacto por hora local y por día de la semana para llamar cuando más contestan.

## 7. Fuentes consultadas

- FCC, fallo declaratorio sobre voces de IA (FCC 24-17, febrero de 2024), y análisis de Cooley, Wiley, Orrick y Mayer Brown.
- FTC, enmienda de la Telemarketing Sales Rule de 2024 (Federal Register, 16-04-2024) y análisis de Husch Blackwell y Hunton.
- Lista nacional DNC y celulares de negocio: National Law Review y TCPAWorld; FCC, órdenes de 2003 y 2005.
- Leyes estatales: Venable, Goodwin y McGuireWoods sobre las reformas de Florida (HB 761, 2023) y la ley de Oklahoma (2022).
- Requisitos de Gmail y Yahoo para envíos masivos: ayuda de Google, Mailgun y Bird.
- Free Caller Registry y «Spam Likely»: CallRail, Dialpad, ServiceTitan.
- Mercado de listas de FMCSA: anuncios en Fiverr y Apify; saturación y quejas: TheTruckersReport; alerta de fraude de la FMCSA; petición de Alpha Drivers Transportation a la FMCSA (agosto de 2025, Overdrive).
