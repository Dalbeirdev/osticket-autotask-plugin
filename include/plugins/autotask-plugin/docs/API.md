# API Documentation

The plugin talks to the **Autotask PSA REST API v1.0** using native cURL.
All access is encapsulated in [`class.api.php`](../class.api.php)
(`Autotask\AutotaskApi`).

## Authentication

Three HTTP headers are sent on every authenticated request:

```
ApiIntegrationcode: <integration code>
UserName:           <api username>
Secret:             <api secret>
Content-Type:       application/json
```

## Zone detection

If no Zone URL is configured, the client resolves it once via:

```
GET https://webservices.autotask.net/atservicesrest/v1.0/zoneInformation?user=<username>
```

The returned `url` is normalised to the versioned API base, e.g.
`https://webservices2.autotask.net/atservicesrest/v1.0/`, and cached back into
the plugin config on successful save.

## Public methods (`Autotask\AutotaskApi`)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `testConnection()` | `Companies/query` (MaxRecords 1) | Validate creds; returns `{ok, message, zone_url}` |
| `resolveZone()` | `zoneInformation` | Detect/normalise zone base |
| `createTicket(array $fields)` | `POST Tickets` | Returns new ticket id |
| `updateTicket(int $id, array $fields)` | `PATCH Tickets` | Partial update |
| `getTicket(int $id)` | `GET Tickets/{id}` | Single ticket |
| `getTicketsModifiedSince(string $utc, int $max)` | `GET Tickets/query` | Inbound delta (filter `lastActivityDate gte`) |
| `createTicketNote(int $ticketId, ...)` | `POST Tickets/{id}/Notes` | Add note (publish 1=all, 2=internal) |
| `getTicketNotesSince(int $ticketId, string $utc)` | `GET Tickets/{id}/Notes/query` | Inbound note delta |
| `findContactByEmail(string $email)` | `GET Contacts/query` | Resolve contact + company |
| `findCompanyByName(string $name)` | `GET Companies/query` | Resolve company |
| `getFieldInfo(string $entity)` | `GET {entity}/entityInformation/fields` | Picklist metadata (look up status/priority/queue IDs) |

## Query filter shape

Autotask `query` endpoints accept a URL-encoded JSON `search` parameter:

```json
{
  "filter": [
    { "op": "gte", "field": "lastActivityDate", "value": "2026-06-30T00:00:00Z" }
  ],
  "MaxRecords": 50
}
```

## Error handling & rate limiting

- Non-2xx responses raise `Autotask\ApiException` carrying the HTTP status and a
  `retryable` flag.
- `401/403` → authentication failure (non-retryable, logged under `auth`).
- `429/503` → one short inline retry honouring `Retry-After` (bounded to 5s);
  persistent failures defer to the retry queue with exponential backoff.
- Transport errors (cURL) → retryable.

## Looking up picklist IDs (recipe)

From the dashboard host, a quick PHP one-off:

```php
$api = new \Autotask\AutotaskApi($creds);
foreach ($api->getFieldInfo('Tickets') as $f) {
    if (in_array($f['name'], ['status','priority','queueID','ticketType'])) {
        echo $f['name'], ":\n";
        foreach ($f['picklistValues'] ?? [] as $pv) {
            echo "  {$pv['value']} => {$pv['label']}\n";
        }
    }
}
```

## Attachment binaries — endpoint quirk

The **list** endpoint (`GET Tickets/{id}/Attachments`) always returns
`data: ""`. The binary is only returned by the **by-id** endpoint
(`GET Tickets/{id}/Attachments/{attachmentId}`). Inbound attachment sync
therefore lists first (metadata + ids), then fetches each new file by id.
Autotask caps attachment uploads at ~6 MB.

## Service-ticket hours quirk

On Service Desk tickets Autotask derives `hoursWorked` from the
`startDateTime`/`endDateTime` window; a PATCH that changes only
`hoursWorked` is ignored. Edits must move the window.
