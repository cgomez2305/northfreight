# Webhooks entrantes (Q4b)

| Archivo | Servicio | Variables del `.env` | Firma |
|---|---|---|---|
| `meta.php` | Meta Lead Ads (GET de verificacion + POST `leadgen`) | `META_VERIFY_TOKEN`, `META_APP_SECRET`, `META_PAGE_TOKEN` | `X-Hub-Signature-256` (HMAC-SHA256) |
| `calcom.php` | Cal.com (reserva creada, reprogramada, cancelada) | `CALCOM_WEBHOOK_SECRET` | `X-Cal-Signature-256` (HMAC-SHA256) |
| `sms.php` | Twilio (SMS entrantes, STOP, estado de entrega) | `TWILIO_TOKEN` | `X-Twilio-Signature` (HMAC-SHA1) |
| `n8n.php` | n8n (eventos de los flujos) | `N8N_WEBHOOK_SECRET` | `X-NS-Signature` + `X-NS-Timestamp` (HMAC-SHA256) |

Sin sus variables cada uno responde `503 not_configured`. Documentacion completa: `captacion/README.md` §20 y `captacion/docs/`.
