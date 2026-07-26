# Inbound webhooks

Let another system raise tickets here by POSTing to a URL. The mirror of the
outbound webhooks in Workflow: those call *out* when something happens in
freeitsm; these are called *in* when something happens elsewhere.

**System → Inbound webhooks.** Each source is a row — no deploy needed for a new
integration.

## Setting one up

1. **New webhook**, give it a name (the sending tool, usually: "Grafana alerts").
2. Choose how it proves who it is — see below.
3. Map the payload onto ticket fields.
4. Save. **The secret is shown once.** Copy it into the sending tool with the URL.

The URL looks like:

```
https://your-itsm/api/inbound/webhook.php?hook=3f9c1a7e5b2d4086c1e7
```

The slug is random and unguessable; it identifies the source, it does not
authenticate it.

## Authentication

| Mode | How the sender proves itself | When to use it |
|---|---|---|
| **HMAC SHA-256** | Signs the raw body with the secret, sends the digest in a header | The strong option. The signature covers the payload, so it can't be replayed against different content. GitHub-style: header `X-Hub-Signature-256`, prefix `sha256=`, hex |
| **Header secret** | Sends the secret verbatim in a named header | Simple, fine over HTTPS, and what most tools can do |
| **URL token** | `&token=…` on the URL | Weakest — URLs land in logs and proxies. Only when the tool offers nothing else |

Comparisons use `hash_equals`. A failed check returns a bare `403`: the reason
goes to the delivery log, not to the caller, so probing tells an attacker nothing.

## Field mapping

Each field takes literal text, `{{dot.path}}` placeholders, or both. Array indices
are numeric segments, which is what most alerting payloads need:

```
Subject      Alert: {{alerts.0.labels.alertname}} on {{alerts.0.labels.instance}}
Priority     High
Description  {{alerts.0.annotations.description}}
```

A path that isn't in the payload resolves to nothing — `"Alert: {{missing}}"`
becomes `"Alert:"` rather than leaking the template. Status, priority, type,
category, subcategory, department, origin and customer are matched **by name**,
and an unknown name is skipped rather than guessed at.

Unmapped is safe: with no subject mapping you get `Inbound: <webhook name>`, and
with no requester you get `webhook+<slug>@localhost`, so automated tickets stay
attributable. The body defaults to the payload, pretty-printed.

## Correlation — the part that decides whether this is useful

A monitoring tool fires repeatedly for one condition. Without correlation, a
flapping check makes a hundred tickets.

- **Dedupe path** — point it at whatever the sender calls its alert id
  (`alerts.0.fingerprint`, `incident.id`, `alert_id`). The value is stored on the
  ticket as `external_ref`. A later delivery with the same value **appends a note
  to the open ticket** instead of raising a duplicate.
- **Resolve rule** — when *path* equals *value* (say `status` equals `resolved`),
  the matching open ticket gets a note and, optionally, a status you choose.

A resolve delivery with no matching open ticket is recorded as `ignored`, not an
error: the alert cleared before anyone raised anything, which is fine.

## The delivery log

Every delivery is recorded with its payload, accepted or not, with one of:

`created` · `appended` · `resolved` · `ignored` · `auth_failed` · `invalid` · `error`

That log is usually how you find out a secret is wrong, and it settles the
"what did you actually send us" conversation. It lives in
`inbound_webhook_events`, is wiped by the **logs** group in System → Reset data,
and holds up to 60KB of each payload.

Only `error` returns a 5xx, because that's the one case a retry might fix.
Everything else answers 200 — a sender that retries on our deliberate decisions
just repeats them.

## Worked example: Grafana / Alertmanager

Webhook config:

- Auth: **HMAC SHA-256**, header `X-Signature`, no prefix, hex — or header secret
  if your Grafana version can't sign.
- Subject: `{{alerts.0.labels.alertname}} — {{alerts.0.labels.instance}}`
- Description: `{{alerts.0.annotations.summary}}`
- Priority: `High`
- Dedupe path: `alerts.0.fingerprint`
- Resolve path: `status`, equals `resolved`, then set status to `Closed`

Test it by hand:

```bash
BODY='{"status":"firing","alerts":[{"fingerprint":"abc123","labels":{"alertname":"DiskFull","instance":"SRV-FILE01"},"annotations":{"summary":"Disk at 92%"}}]}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac 'YOUR_SECRET' -hex | sed 's/^.* //')
curl -sS -X POST "https://your-itsm/api/inbound/webhook.php?hook=YOUR_SLUG" -H "Content-Type: application/json" -H "X-Signature: $SIG" -d "$BODY"
```

Send it twice: the second answers `appended`. Change `"status"` to `"resolved"`
and it closes the ticket.

## What it does and doesn't do

- Tickets are created through `TicketsService`, so they get the initial email,
  audit trail, auto-assignment, SLA and the `ticket.created` workflow event — a
  webhook ticket is a real ticket, and workflows can act on it.
- The ticket is attributed to the webhook's configured analyst (the admin who
  created it by default), so the audit names a person.
- Form-encoded bodies are accepted as well as JSON, because some tools only send
  that.
- **Not** built: per-source rate limiting, replaying a logged delivery, and
  updating fields other than status on a correlated ticket. Say if you want them.

## Related

- Outbound (us → them): Workflow actions, queue and replay under
  System → Webhooks queue, plus [`webhook-cron-setup.md`](webhook-cron-setup.md).
- The REST API is the other way in when the sender can hold an API key and speak
  a defined schema: `POST /api/v1/tickets`. Webhooks suit senders whose payload
  you don't control.
- Messaging channels (WhatsApp/Twilio) have their own receiver at
  `api/messaging/webhook.php`.
