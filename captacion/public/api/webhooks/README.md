# Webhooks (vacío en la fase Q1)

Aquí irán, en fases posteriores, los receptores firmados de terceros (cada uno verifica la firma o el token ANTES de procesar nada):

| Archivo | Fase | Servicio |
|---|---|---|
| `meta.php` | Q6 | Meta Lead Ads (verificación GET + `leadgen` POST, firma `X-Hub-Signature-256` con `META_APP_SECRET`) |
| `calcom.php` | Q4 | Cal.com (citas creadas/canceladas/reprogramadas, `CALCOM_WEBHOOK_SECRET`) |
| `sms.php` | Q4 | Twilio (respuestas SMS y STOP; al recibir STOP llamar a `Captacion\Services\Consent::revoke($telefono, 'all')`) |
| `n8n.php` | Q4 | n8n (eventos de los flujos, `N8N_WEBHOOK_SECRET`) |

No hay ningún receptor activo todavía. El directorio no ejecuta nada.
