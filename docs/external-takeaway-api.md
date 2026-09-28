# External Takeaway API — quick start

Full contract: [`external-takeaway-api.yaml`](external-takeaway-api.yaml) (OpenAPI 3 — open it in
https://editor.swagger.io or any Swagger UI).

Base URL (local): `http://localhost:8000/api/v1/external/takeaway`

## 1. Create a key for the app

```bash
php artisan migrate
php artisan external-api:client create "Driver App" --webhook-url=https://driver-app.example.com/hooks/o2 --rate=120
# optional, only if the app calls the API from a browser:  --origin=https://driver-app.example.com
```

The command prints the **X-API-Key** and the **webhook secret** once. Only a SHA-256 hash of the key is stored,
so it cannot be shown again — revoke and create a new one if it is lost.

```bash
php artisan external-api:client list
php artisan external-api:client revoke 3            # by id, or by key prefix: o2tw_AbCdEfG
```

## 2. curl examples

```bash
KEY=o2tw_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
BASE=http://localhost:8000/api/v1/external/takeaway

# list (filters: status, order_type, branch_id, from, to, updated_since, page, per_page)
curl -s "$BASE/orders?status=new&per_page=20" -H "X-API-Key: $KEY" -H "Accept: application/json"

# polling: everything changed since the last updated_at you processed (inclusive, ascending)
curl -s "$BASE/orders?updated_since=2027-02-09T12:30:00Z" -H "X-API-Key: $KEY" -H "Accept: application/json"

# one order
curl -s "$BASE/orders/1532" -H "X-API-Key: $KEY" -H "Accept: application/json"

# status: new → accepted → preparing → ready → picked_up (→ delivered for delivery orders)
curl -s -X PATCH "$BASE/orders/1532/status" -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
     -d '{"status":"accepted","note":"Accepted by dispatcher"}'

# driver (delivery orders, before picked_up)
curl -s -X PATCH "$BASE/orders/1532/driver" -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
     -d '{"driver":{"name":"Samer","phone":"0598111222","external_id":"drv-7"}}'
```

Errors are always `{"error": {"code": "...", "message": "..."}}` — e.g. `401 invalid_api_key`,
`409 invalid_transition` (with `details.allowed_next_statuses`), `409 driver_required`, `422 validation_failed`,
`429 rate_limited` (see `Retry-After`).

The external app **cannot cancel** orders; cancellation is a decision made in the POS.

## 3. Webhooks

Events: `order.created` (call center closed the order — it is now yours), `order.status_changed`,
`order.driver_assigned`, `order.withdrawn` (re-opened in the POS; stop working on it). Body:

```json
{ "id": "<event id>", "type": "order.status_changed", "occurred_at": "2027-02-09T12:31:02Z", "data": { "...": "same Order object as the API" } }
```

Verify every request:

```js
// Node.js
const crypto = require('crypto');
function verify(req, rawBody, secret) {
  const ts = req.headers['x-timestamp'];
  if (Math.abs(Date.now() / 1000 - Number(ts)) > 300) return false;          // replay window
  const expected = 'sha256=' + crypto.createHmac('sha256', secret).update(`${ts}.${rawBody}`).digest('hex');
  const got = req.headers['x-signature'] || '';
  return got.length === expected.length && crypto.timingSafeEqual(Buffer.from(got), Buffer.from(expected));
}
```

De-duplicate on `X-Event-Id` (same value on every retry). Reply 2xx within 10 s; failures are retried with
backoff (30 s → 1 h, max 8 attempts). Every attempt is logged in `external_webhook_attempts`.

Webhooks are sent by the scheduler (`takeaway:dispatch`, every minute) — the server needs
`* * * * * php artisan schedule:run`. To send immediately: `php artisan takeaway:dispatch`.

## 4. Try it locally without a real takeaway app

```bash
php artisan migrate
php artisan external-api:client create "Local test" --webhook-url=https://webhook.site/<your-id>
php artisan serve                     # http://localhost:8000
# close a call-center takeaway/delivery order in the UI (paid + executed → F7), then:
curl -s "http://localhost:8000/api/v1/external/takeaway/orders" -H "X-API-Key: <key>"
php artisan takeaway:dispatch         # delivers the order.created webhook to webhook.site
```

**PHP requirement:** the `openssl` extension must be enabled (Laravel encryption of the webhook secret and
HTTPS webhooks both need it). In the current local Herd `php.ini` it is **not** enabled — add
`extension=openssl` (and `extension=curl`) there, or key creation with a webhook and webhook delivery fail with
`Call to undefined function openssl_cipher_iv_length()`.

Tests: `php -d extension=php_openssl.dll -d extension=php_curl.dll vendor/bin/phpunit --filter ExternalTakeawayApiTest`
