# Payments Simulator: M-Pesa Daraja and Co-op Bank

A local copy of Safaricom M-Pesa Daraja for **C2B** and **B2C**, and of **Co-op Bank Connect** for transfers,
statements and balances (see the Co-op section below). For Daraja, it accepts the same requests as
`sandbox.safaricom.co.ke`, returns the same synchronous responses, and then sends the asynchronous
callbacks (validation, confirmation, result, timeout) to your app, in the same payload format as Daraja.

Full usage guide with request, response and callback examples: **[docs/GUIDE.md](docs/GUIDE.md)**.

Data lives in the `mpesa_sim` database on your local MySQL. UI: **http://localhost:8090**

## Run

```bash
cp .env.example .env        # then set DB_* (DB_HOST=127.0.0.1, DB_DATABASE=mpesa_sim) and APP_URL=http://localhost:8090
php artisan key:generate
docker compose up -d --build
```

Two containers start from the same image:

| Service  | Role |
|----------|------|
| `api`    | HTTP on :8090 (Daraja endpoints + UI). Runs migrations and seeds on start. |
| `worker` | `queue:work`: sends the delayed callbacks. |

Both use `network_mode: host` (Linux). The container reaches MySQL at `127.0.0.1` and can call back
apps that listen only on `127.0.0.1` (e.g. bizwiz on `:8000`).

Without Docker: `php artisan migrate --seed`, then run `php artisan serve --port=8090` and `php artisan queue:work`.

## Daraja endpoints

| Method | Path | Notes |
|---|---|---|
| GET  | `/oauth/v1/generate?grant_type=client_credentials` | Basic auth with consumer key/secret |
| POST | `/mpesa/c2b/v1/registerurl` (and `v2`) | |
| POST | `/mpesa/c2b/v1/simulate` (and `v2`) | Customer payment → validation (if enabled) → confirmation |
| POST | `/mpesa/b2c/v1/paymentrequest` | |
| POST | `/mpesa/b2c/v3/paymentrequest` | `OriginatorConversationID` required |

Seeded defaults: consumer key `sim_consumer_key`, secret `sim_consumer_secret`, paybill `600000`,
B2C shortcode `600981` (balance KES 1,000,000). Shortcodes a client uses for the first time are created automatically.

## Pointing bizwiz at it

In `bizwiz/.env`:

```dotenv
DARAJA_B2C_BASE_URL=http://localhost:8090
DARAJA_B2C_CONSUMER_KEY=sim_consumer_key
DARAJA_B2C_CONSUMER_SECRET=sim_consumer_secret
MPESA_AUTH_URL=http://localhost:8090
```

Callbacks go to the URLs bizwiz sends (`APP_URL/api/disbursements/daraja/...`), so `APP_URL` must be
reachable from the simulator.

## Controlling outcomes

The outcome of each transaction is decided in this order:

1. **Forced next outcome**: queue one from the Dashboard. It applies to the next C2B or B2C transaction only.
2. **Test phone numbers**: B2C to (or C2B from) these numbers:

   | Phone | Outcome |
   |---|---|
   | 254700000001 | Insufficient funds (`ResultCode 1`) |
   | 254700000002 | Invalid initiator (`2001`) |
   | 254700000003 | Recipient not registered (`2040`) |
   | 254700000004 | Timeout: `QueueTimeOutURL` is called |
   | 254700000005 | No callback at all |
   | 254700000006 | Callback sent twice (idempotency test) |
   | 254700000007 | Callback after a long delay (`SIM_LONG_DELAY_SECONDS`, default 60) |
   | 254700000008 | Recipient limit exceeded (`2006`) |

3. **Real state**: a B2C larger than the shortcode balance fails with insufficient funds. For C2B, your
   validation URL's `ResultCode` decides, and if it can't be reached the registered `ResponseType` applies.
4. Otherwise the transaction succeeds.

## UI

- **Dashboard**: counts, credentials, shortcode balances, forced-outcome queue, live list of recent transactions.
- **Transactions**: search and filter. Each detail page shows the request, the sync response, and every callback
  sent along with your app's reply. Use **Send callback now** to skip the delay and **Resend callback** to test idempotency.
- **C2B**: pay as a customer; register URLs; turn validation on or off per shortcode.
- **B2C**: send a payout from the UI.
- **Settings**: callback delay, API apps (consumer keys), shortcodes and balances.

`/sim/catch/{anything}` is a built-in callback receiver that always accepts (add `?reject=1` to reject a C2B
validation). Use it as a URL when testing without a client app.

## Co-op Bank (Co-op Bank Connect)

Same base URL (`http://localhost:8090`). Paths copy Co-op's SIT host (`openapi-sit.co-opbank.co.ke`), so a
client only swaps the host. Credentials: `coop_consumer_key` / `coop_consumer_secret` (separate from M-Pesa).

| Method | Path | Answer by |
|---|---|---|
| POST | `/token` (form `grant_type=client_credentials`, Basic auth) | Response |
| POST | `/FundsTransfer/Internal/A2A_v3/3.0.0/` | Callback to `CallBackUrl` |
| POST | `/FundsTransfer/External/A2A/PesaLink_v4/4.0.0/` | Callback |
| POST | `/FundsTransfer/External/A2M/Mpesa_v2/2.0.0/` | Callback |
| POST | `/Enquiry/TransactionStatus/2.0.0/` | Response (same body as the callback, or `-10` while pending) |
| POST | `/Enquiry/AccountBalance_v2/2.0.0/` | Response |
| POST | `/Enquiry/MiniStatement/Account_v2/2.0.0/` | Response (last 10 lines) |
| POST | `/Enquiry/AccountFullStatementPaginated/1.0.0/` | Response; optional `PageNumber`, `PageSize` (default 100) |
| POST | `/Enquiry/Validation/IPSL/1.0.0/` | Response with `AccountName` |

**Accounts and money.** Every account has a ledger, and statements and balances are read from it. Transfers
debit the source and credit the destination (Co-op or another bank). Available = booked − blocked + overdraft.
Seeded accounts:

| Account | Bank | Name | Notes |
|---|---|---|---|
| 01100000000100 | 11 | TAP DISBURSEMENT ACCOUNT | own, KES 5,000,000 |
| 01100000000200 | 11 | TAP COLLECTIONS ACCOUNT | own, KES 0 |
| 01100000012345 | 11 | JANE WANJIKU ENTERPRISES | recipient |
| 1234567890 | 1 | ACME SUPPLIERS LTD | recipient (KCB) |
| 0987654321 | 68 | MAMA MBOGA WHOLESALERS | recipient (Equity) |
| 01100000099999 | 11 | DORMANT TEST ACCOUNT | dormant |

Only **own** accounts can be a transfer `Source` or be queried; anything else gets `-8`.

**Checks made before accepting a transfer** (HTTP 400): missing field `-4`, duplicate `MessageReference` `-3`,
unknown source `-1`, source not owned `-8`, source inactive `-6`, destination amounts not equal to source `-5`.

**Outcomes after accepting** (sent in the callback): insufficient funds `-2` (nothing moves), invalid or inactive
destination `-1`/`-6`, limit `-7`, bank unreachable `-12`, partial success. Control them by:
- **Forced next outcome** on the Co-op page or Dashboard. This also includes "Co-op down" (HTTP 503 `-11`), "no callback" and "duplicate callback".
- **Test destinations**: account numbers `01100000000001`–`…07` and phones `254700000101`/`103`/`105`–`107`
  (the full list is in the UI).
- **Real state**: balance, blocked amount, overdraft and account status are all editable in the UI.
- **Settings**: charges per rail (posted as a separate debit line), and whether unknown recipient accounts are
  created automatically.

**Where the formats come from.** The request bodies come from Co-op's SIT Postman collection. That collection
has no sample responses, so the response and callback formats follow Co-op's published API docs. Of the
codes, only `0`, `-1`, `-5` and `-8` appear in Co-op samples. The others are simulator-defined and live in
`app/Support/Coop.php`, so they're easy to correct once Co-op shares their error list.

## Tests

```bash
php artisan test
```
