# Order Management API

A JSON API for creating and managing customer orders, built with Laravel 13 / PHP 8.3.

All pricing — subtotal, discount percentage, discount amount and total — is calculated
server-side. Values sent by the client for those fields are ignored.

## Install

```bash
composer install
cp .env.example .env
php artisan key:generate
```

## Database

The app uses **SQLite** by default; `.env.example` ships with `DB_CONNECTION=sqlite`
and no further configuration is needed.

```bash
touch database/database.sqlite   # only if it does not exist
php artisan migrate --seed
```

To use MySQL/PostgreSQL instead, set the usual variables in `.env` and re-run `migrate`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=orders
DB_USERNAME=root
DB_PASSWORD=
```

The seeder creates customers `123`, `124`, `125` and one order per discount tier, so the
example request below works immediately.

## Run

```bash
php artisan serve      # http://127.0.0.1:8000
```

## Tests

```bash
php artisan test
```

70 tests / 249 assertions. Tests run against an in-memory SQLite database
(configured in `phpunit.xml`); no setup required.

| Suite | Covers |
|---|---|
| `tests/Unit/DiscountPolicyTest.php` | Tier boundaries (0 / 4,500 / **5,000 / 5,001 / 10,000 / 10,001 / 20,000 / 20,001** / 1,000,000), the four worked examples, `subtotal - discount = total`, rounding |
| `tests/Feature/CreateOrderTest.php` | Backend-calculated pricing, client-supplied totals ignored, empty items, quantity ≤ 0, negative price, unknown customer, malformed payloads, transaction rollback |
| `tests/Feature/OrderIdempotencyTest.php` | Key replay, key reuse with a different payload, fingerprint fallback, window expiry |
| `tests/Feature/OrderStatusTest.php` | All 4 allowed transitions, all 12 forbidden ones, completed/cancelled immutability, unknown status |
| `tests/Feature/RetrieveOrdersTest.php` | Single order breakdown, listing, pagination, filtering, 404/405 |

## API

Base URL `/api`. All responses are JSON and wrapped in a `data` key
(Laravel's `JsonResource` convention).

### `POST /api/orders`

Headers: `Content-Type: application/json`, optional `Idempotency-Key: <string>`.

```json
{
  "customer_id": 123,
  "items": [
    { "product_id": 10, "quantity": 3, "unit_price": 250 },
    { "product_id": 15, "quantity": 2, "unit_price": 100 }
  ]
}
```

`201 Created` — or `200 OK` when the request was recognised as a retry.

```json
{
  "data": {
    "id": 1,
    "customer_id": 123,
    "status": "pending",
    "subtotal": 950,
    "discount": 0,
    "discount_percentage": 0,
    "total": 950,
    "items": [
      { "id": 1, "product_id": 10, "quantity": 3, "unit_price": 250, "line_total": 750 },
      { "id": 2, "product_id": 15, "quantity": 2, "unit_price": 100, "line_total": 200 }
    ],
    "allowed_transitions": ["processing", "cancelled"],
    "created_at": "2026-09-17T11:57:15+00:00",
    "updated_at": "2026-09-17T11:57:15+00:00"
  }
}
```

### `GET /api/orders/{id}`

`200 OK`, same shape as above. `404` if the order does not exist.

### `GET /api/orders`

Query parameters: `status`, `customer_id`, `per_page` (default 15, max 100), `page`.
Returns a paginated collection with `data`, `links` and `meta`.

### `PATCH /api/orders/{id}/status`

```json
{ "status": "processing" }
```

`200 OK` with the updated order. Allowed transitions:

```
pending    → processing, cancelled
processing → completed, cancelled
completed  → (final)
cancelled  → (final)
```

### Discounts

| Subtotal | Discount |
|---|---|
| ≤ 5,000 | 0% |
| 5,001 – 10,000 | 2% |
| 10,001 – 20,000 | 5% |
| > 20,000 | 10% |

Configurable in `config/orders.php`.

### Errors

| Status | When |
|---|---|
| `404` | Order or endpoint not found |
| `405` | Method not supported |
| `409` | `Idempotency-Key` reused with a different payload |
| `422` | Validation failure or a disallowed status transition |

Validation errors use Laravel's standard shape:

```json
{
  "message": "Item quantity must be greater than zero. (and 1 more error)",
  "errors": {
    "items.0.quantity": ["Item quantity must be greater than zero."],
    "items.0.unit_price": ["Item unit price cannot be negative."]
  }
}
```

A rejected transition also lists what *is* possible:

```json
{
  "message": "An order cannot move from processing to pending.",
  "errors": { "status": ["An order cannot move from processing to pending."] },
  "allowed_transitions": ["completed", "cancelled"]
}
```

## Assumptions

- **Money is integer minor units** (cents). The brief's examples use plain integers, and
  integers avoid floating-point rounding entirely. No currency is modelled — a single
  implied currency is assumed.
- **Discount amounts are rounded down** (`intdiv`), so we never discount more than the
  tier allows and `subtotal - discount == total` always holds exactly.
- **Tier bounds are inclusive at the top**: a subtotal of exactly 5,000 gets 0%, 5,001
  gets 2%. This is what "subtotal <= 5,000" in the brief states.
- **`unit_price` is supplied by the client** and stored per line, as the brief specifies.
  There is therefore no product catalogue in this service: `product_id` is an opaque
  reference to a product owned elsewhere, and the price is captured historically so a
  later catalogue change cannot alter a past order.
- **Customers are modelled**, since `customer_id` must refer to something real; a
  non-existent customer is a `422`.
- **Orders are created as `pending`**; a status in the create payload is ignored.
- **No authentication.** Out of scope for this exercise — see below.

## Technical decisions

- **Actions, not fat controllers.** `App\Actions\CreateOrder` and `UpdateOrderStatus`
  hold the business rules; controllers only translate HTTP to and from them. The rules
  are testable without touching the HTTP layer.
- **`DiscountPolicy` is the single source of pricing truth** (`app/Support/`), reading
  its tiers from `config/orders.php`. Nothing else may compute a discount.
- **The breakdown is persisted, not recomputed.** `subtotal`, `discount_percentage`,
  `discount_amount` and `total` are all stored columns. A past order can still be
  explained after the discount rules change — recomputing on read would silently rewrite
  history.
- **`line_total` is stored per item** so the arithmetic behind the subtotal is visible
  in the response without the client re-deriving it.
- **Status transitions live on the enum** (`OrderStatus::allowedTransitions()`), so the
  rule has one home. `isFinal()` expresses "completed and cancelled cannot be modified"
  as a consequence of having no outgoing transitions, rather than as a second rule that
  could drift.
- **Atomicity** via `DB::transaction` around the order and its items, covered by a test
  that forces the item insert to fail and asserts nothing is left behind.
- **Idempotency, two layers.** An `Idempotency-Key` header is authoritative: a repeat
  returns the original order with `200`, and reusing a key with a different payload is a
  `409` rather than a silently wrong replay. A unique index on the column closes the race
  between two simultaneous retries — the loser catches the constraint violation and
  returns the winner's order. For clients that retry *without* a key, an identical
  payload from the same customer within 60s (`config/orders.php`) is also treated as a
  retry; the fingerprint is order-insensitive, so re-ordered items still match.
- **Domain exceptions render themselves** (`app/Exceptions/`), keeping error shaping out
  of the controllers.
- **404/405 are rewritten for `api/*`** so consumers never see `No query results for
  model [App\Models\Order]`.

## What I would do next

- **Authentication and authorisation.** Sanctum tokens, then scope orders to the
  authenticated customer — right now any caller can read or modify any order. This is the
  first thing I would add.
- **Idempotency as middleware with its own store.** Today the key lives on `orders`,
  which only works for order creation. A dedicated `idempotency_keys` table storing the
  serialised response would generalise to every write endpoint and let keys expire.
- **Currency support** — a `currency` column plus a check that a basket is single-currency.
- **Optimistic locking** on status updates (a `version` column or `updated_at` check) so
  two concurrent transitions cannot race past the state machine.
- **A status history table** recording who changed what and when; today only the current
  status survives.
- **Rate limiting** on `POST /api/orders`, and structured logging of rejected transitions
  and idempotency conflicts.
- **OpenAPI spec** generated from the requests and resources, rather than this hand-written
  endpoint table.
