# Customer notifications

This opt-in adapter hands a host application's notification to TROPIKAL Chat for WhatsApp/SMS delivery. The host retains its email renderer and email address. Existing installations keep their current email path unless the host explicitly enables and calls this API.

## Host integration

1. Run the package migrations. Use a durable queue and persistent database.
2. Bind `TropikalAI\ConnectFilament\Contracts\NotificationEmailSender` to a host implementation. Its `send(NotificationDelivery $delivery): void` method must send synchronously, using the delivery's local `context`. It must throw when successful delivery is unknown. It must never silently skip a missing recipient, enqueue another job, or retry a possibly successful send.
3. Set `connect-filament.notifications.enabled` to `true` (or `CONNECT_FILAMENT_NOTIFICATIONS_ENABLED=true`).
4. Resolve `Services\CustomerNotifications` and call `dispatchAfterCommit($installation, $request, $context)` in the host transaction. The method returns the durable `Models\NotificationDelivery` record. Reusing its event ID and exact request/context returns the same record; different content is rejected.

`Domain\NotificationRequest` constructor:

```php
new NotificationRequest(
    eventId: 'trial_requested_123',
    kind: 'trial_requested',
    locale: 'de',
    phone: '+41791234567',
    whatsapp: true,
    sms: false,
    variables: [1 => 'Ada', 2 => 'Boxing', 3 => '2026-11-16', 4 => '18:00–19:00'],
    smsBody: 'Your trial request was received.',
    emailAvailable: true,
);
```

Channel permissions must come from recorded consent. The email flag means the host can actually send this notification to its locally held email address. Use the host's existing email path before calling this adapter when the phone is missing or invalid. Event IDs allow ASCII letters, digits, underscore and hyphen, up to 128 characters. Variables allow at most 50 entries, with 1–120 ASCII letters/digits/underscores per key and 2,000 Unicode characters per value, including numeric template keys. Signed JSON preserves Unicode characters and variable objects; the maximum valid payload fits the App’s 512 KiB request limit. The host cannot choose a sender, workspace or provider template. Chat resolves these from the authenticated installation and its owner's configuration.

`NotificationDelivery::safeStatus()` exposes `event_id`, `status`, `channel` and `email_status`. Scope admin queries by installation. The model hides and encrypts payload, local context and claim token. Do not return those fields through a public resource or add the delivery model to generic discovery grants. No browser-facing route is added. The host can render this safe status beside its own notification controls.

## Wire contract

All calls use the installation's existing Connect signature over the exact method, relative path, query and JSON body. The fixed base is `/api/connect-filament/notifications/v1`. JSON responses carry `version: "customer_notifications.v1"`. The existing installation registration and strict public-chat acknowledgement contract are unchanged.

| Method/path | Request | Response |
| --- | --- | --- |
| `GET /capabilities` | none | `{version,supported:true,kinds:[{kind,locales,whatsapp,sms}]}` |
| `POST /deliveries` | `{event_id,kind,locale,recipient:{phone},permissions:{whatsapp,sms,email},variables,sms_body}` | `{version,event_id,status,channel}` |
| `GET /deliveries/{event_id}` | none | Same delivery result |
| `POST /deliveries/{event_id}/email-claim` | `{}` | Same result, with `email_claim_token` only for the first successful claim |
| `POST /deliveries/{event_id}/email-complete` | `{claim_token,outcome:"delivered"\|"uncertain"}` | Same delivery result |

Remote statuses: `accepted`, `pending`, `delivered`, `email_required`, `email_claimed`, `email_delivered`, `uncertain`, `unsupported`, `failed`. Channels are `null`, `whatsapp`, `sms`, `email`. Local records begin at `queued`. A phone-only request with no eligible channel ends at `failed`.

## Delivery and compatibility

Dispatch waits for transaction commit. A database lease serializes advancement, independently of queue-cache locks. Admission intent is persisted before POST. A timeout is reconciled through GET with the same event ID; it never triggers legacy email. A missing record after uncertain admission is exposed as `uncertain`.

A 404/405 capability probe, explicit `supported:false`, or an unmapped kind/locale permits legacy host email before admission. A valid versioned POST result `unsupported` is also a safe rejection: the App guarantees that no admission record or send exists for that result. A capability-only transport outage or unsuccessful HTTP response also permits existing host email, because no POST has started. Phone-only requests retry such outages. A 401/403 emits a configuration warning without credentials. Wrong versions, malformed responses, or any unknown admission outcome keep email blocked. New App with an old adapter is unaffected because notification invocation is opt-in and registration is unchanged. New adapter with an old App retains legacy email after the explicit unsupported probe.

For admitted notifications, Chat must decide `email_required` only after it can exclude late provider delivery. The adapter requires an atomic email claim and persists the returned token before sending. A claim timeout followed by a tokenless claimed result becomes `uncertain`. The local record is marked `sending` before the host call. If the process dies there, a later worker reports `uncertain` and never repeats the send. A known email result is retried only as `email-complete`, with the same persisted claim token. Completion requests must be idempotent in Chat. After exhausted queue attempts the local record becomes `uncertain`; this is a status for operator inspection, not permission to resend.

The host owns retention of the encrypted notification context and must account for its booking/member retention rules. Installation deletion cascades local delivery records. No request or provider error body is logged.
