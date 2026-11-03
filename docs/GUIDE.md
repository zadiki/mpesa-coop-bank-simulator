# Payments Simulator Guide

How to run and use the local simulator for **Safaricom M-Pesa Daraja** (C2B, B2C) and **Co-op Bank Connect**
(transfers, statements, balances).

The simulator works like the real APIs. It accepts the same requests at the same paths and returns the same
synchronous responses. Then it **calls your app back** asynchronously, the way Safaricom and Co-op do. Every
request, response, callback and balance movement is stored in your local MySQL and can be viewed in a web UI.

- UI and API base URL: **http://localhost:8090**
- Database: `mpesa_sim` on local MySQL
- Code: `retailpay/mpesa-sim` (Laravel 13, PHP 8.4)

---

## Contents

1. [How it works](#1-how-it-works)
2. [Running it](#2-running-it)
3. [Credentials and seeded data](#3-credentials-and-seeded-data)
4. [M-Pesa Daraja](#4-m-pesa-daraja)
5. [Co-op Bank](#5-co-op-bank)
6. [Testing failures](#6-testing-failures)
7. [Using the UI](#7-using-the-ui)
8. [Connecting bizwiz and tap_be](#8-connecting-bizwiz-and-tap_be)
9. [Troubleshooting](#9-troubleshooting)
10. [Reference: codes](#10-reference-codes)

---

## 1. How it works

```
 Your app                         Simulator                              Your app
 ────────                         ─────────                              ────────
 1. Get token  ─────────────────▶ checks key/secret, returns token
 2. Send request ───────────────▶ validates, stores it, returns
                ◀──────────────── "accepted" (sync response)
                                  3. waits the callback delay (default 3s)
                                  4. moves the money and updates balances
                                  5. POSTs the result ───────────────────▶ your callback URL
                                  6. records your app's reply
 7. (optional) status enquiry ──▶ returns the same result
```

There are two processes:

| Process | Job |
|---|---|
| **api** (`php artisan serve` on :8090) | Daraja and Co-op endpoints, plus the UI |
| **worker** (`php artisan queue:work`) | Waits out the delay, moves money, sends callbacks |

> **Without the worker, nothing past step 2 happens.** Transfers and payments stay `pending`.

---

## 2. Running it

### With Docker (recommended)

```bash
cd retailpay/mpesa-sim
cp .env.example .env
# Edit .env: DB_USERNAME / DB_PASSWORD for your local MySQL.
# Keep DB_HOST=127.0.0.1, DB_DATABASE=mpesa_sim, APP_URL=http://localhost:8090.
php artisan key:generate
docker compose up -d --build
```

- `api` runs migrations and seeds on every start. Both are safe to repeat.
- `worker` starts once `api` is healthy.
- Both containers use **host networking** (Linux only). Inside a container, `localhost` is your machine. That
  means the containers reach MySQL at `127.0.0.1:3306`, and callbacks can reach apps that listen only on
  `127.0.0.1`, such as bizwiz on `:8000`.

Create the database once if it doesn't exist:

```sql
CREATE DATABASE mpesa_sim CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Useful commands:

```bash
docker compose logs -f worker        # watch callbacks being sent
docker compose restart worker        # after changing code
docker compose down
```

### Without Docker

```bash
cd retailpay/mpesa-sim
composer install
php artisan migrate --seed
php artisan serve --port=8090          # terminal 1
php artisan queue:work --tries=1       # terminal 2
```

Restart `queue:work` after code changes. It keeps the old code in memory.

### Configuration (`.env`)

| Variable | Default | Meaning |
|---|---|---|
| `APP_URL` | `http://localhost:8090` | Used to build the built-in callback catcher URLs |
| `SIM_CALLBACK_DELAY_SECONDS` | `3` | Delay before callbacks (can be changed in the UI) |
| `SIM_CALLBACK_TIMEOUT_SECONDS` | `10` | How long the simulator waits for your callback endpoint |
| `SIM_LONG_DELAY_SECONDS` | `60` | Delay used by the "delayed" scenarios |
| `SIM_DEFAULT_CONSUMER_KEY` / `_SECRET` | `sim_consumer_key` / `sim_consumer_secret` | M-Pesa credentials |
| `SIM_COOP_CONSUMER_KEY` / `_SECRET` | `coop_consumer_key` / `coop_consumer_secret` | Co-op credentials |

### Tests

```bash
php artisan test
```

---

## 3. Credentials and seeded data

The seeder runs on every container start and never overwrites existing rows.

**API credentials.** M-Pesa and Co-op tokens are separate. A token from one doesn't work on the other.

| Provider | Consumer key | Consumer secret |
|---|---|---|
| M-Pesa | `sim_consumer_key` | `sim_consumer_secret` |
| Co-op | `coop_consumer_key` | `coop_consumer_secret` |

You can add more under **Settings → API apps**.

**M-Pesa shortcodes**

| Shortcode | Type | Balance | URLs registered |
|---|---|---|---|
| 600000 | Paybill | 0 | Built-in catcher |
| 600981 | B2C | 1,000,000 | — |

Shortcodes a client uses for the first time are created automatically. New B2C shortcodes start with
KES 1,000,000.

**Co-op accounts**

| Account | Bank | Name | Notes |
|---|---|---|---|
| 01100000000100 | 11 (Co-op) | TAP DISBURSEMENT ACCOUNT | Own, KES 5,000,000 |
| 01100000000200 | 11 | TAP COLLECTIONS ACCOUNT | Own, KES 0 |
| 01100000012345 | 11 | JANE WANJIKU ENTERPRISES | Recipient |
| 1234567890 | 1 (KCB) | ACME SUPPLIERS LTD | Recipient |
| 0987654321 | 68 (Equity) | MAMA MBOGA WHOLESALERS | Recipient |
| 01100000099999 | 11 | DORMANT TEST ACCOUNT | Dormant |

"Own" accounts belong to the API client. Only they can be a transfer source or be queried for balance and
statements.

---

## 4. M-Pesa Daraja

Paths are the same as on `sandbox.safaricom.co.ke`. Use `http://localhost:8090` as the base URL.

| Method | Path | Purpose |
|---|---|---|
| GET | `/oauth/v1/generate?grant_type=client_credentials` | Access token |
| POST | `/mpesa/c2b/v1/registerurl` (also `v2`) | Register confirmation and validation URLs |
| POST | `/mpesa/c2b/v1/simulate` (also `v2`) | A customer pays a paybill or till |
| POST | `/mpesa/b2c/v1/paymentrequest` | Payout to a phone |
| POST | `/mpesa/b2c/v3/paymentrequest` | Payout to a phone (`OriginatorConversationID` required) |

### 4.1 Token

```bash
curl -u sim_consumer_key:sim_consumer_secret \
  "http://localhost:8090/oauth/v1/generate?grant_type=client_credentials"
```
```json
{ "access_token": "QxlcnjYtzLel0ZDBmVHDToSV86PP", "expires_in": "3599" }
```

Send it as `Authorization: Bearer <token>` on every other call. If the token is wrong or expired, you get
HTTP 401:

```json
{ "requestId": "9853-93059800-1", "errorCode": "404.001.03", "errorMessage": "Invalid Access Token" }
```

### 4.2 C2B: customer pays a business

**Register URLs**

```bash
curl -H "Authorization: Bearer $T" -H "Content-Type: application/json" \
  http://localhost:8090/mpesa/c2b/v1/registerurl -d '{
    "ShortCode": "600000",
    "ResponseType": "Completed",
    "ConfirmationURL": "http://localhost:8000/api/mpesa/confirmation",
    "ValidationURL": "http://localhost:8000/api/mpesa/validation"
  }'
```
```json
{ "OriginatorCoversationID": "55338-19142769-1", "ResponseCode": "0", "ResponseDescription": "Success" }
```

(`OriginatorCoversationID` is misspelled on purpose. Daraja spells it that way.)

**Simulate a payment**

```bash
curl -H "Authorization: Bearer $T" -H "Content-Type: application/json" \
  http://localhost:8090/mpesa/c2b/v1/simulate -d '{
    "ShortCode": "600000",
    "CommandID": "CustomerPayBillOnline",
    "Amount": 120,
    "Msisdn": "254708374149",
    "BillRefNumber": "INV-77"
  }'
```
```json
{ "OriginatorCoversationID": "...", "ResponseCode": "0", "ResponseDescription": "Accept the service request successfully." }
```

`CommandID` is `CustomerPayBillOnline` for a paybill or `CustomerBuyGoodsOnline` for a till.

**Callbacks that follow**

1. **Validation.** Sent when validation is **on** for the shortcode. Registering URLs with a `ValidationURL`
   turns it on, and registering without one turns it off, so the simulator calls both URLs you register. (On
   real Daraja the validation URL is only called when Safaricom has enabled external validation.) The C2B page
   can still switch it until the next registration.
   - Reply `{"ResultCode": "0", "ResultDesc": "Accepted"}` to accept the payment.
   - Reply with any other `ResultCode` (for example `C2B00012`) to reject it. The transaction becomes
     `rejected` and no confirmation is sent.
   - If your validation URL doesn't answer, the registered `ResponseType` decides: `Completed` accepts and
     `Cancelled` rejects.
2. **Confirmation.** Sent once the payment is accepted, with the same body as validation:

```json
{
  "TransactionType": "Pay Bill",
  "TransID": "TKYLKHGSCJ",
  "TransTime": "20261102175133",
  "TransAmount": "120.00",
  "BusinessShortCode": "600000",
  "BillRefNumber": "INV-77",
  "InvoiceNumber": "",
  "OrgAccountBalance": "120.00",
  "ThirdPartyTransID": "",
  "MSISDN": "254708374149",
  "FirstName": "James",
  "MiddleName": "",
  "LastName": "Achieng"
}
```

The customer's name comes from the phone number. The same number always gets the same name.

### 4.3 B2C: business pays a customer

```bash
curl -H "Authorization: Bearer $T" -H "Content-Type: application/json" \
  http://localhost:8090/mpesa/b2c/v3/paymentrequest -d '{
    "OriginatorConversationID": "payout-123",
    "InitiatorName": "testapi",
    "SecurityCredential": "anything",
    "CommandID": "BusinessPayment",
    "Amount": 250,
    "PartyA": "600981",
    "PartyB": "254708374149",
    "Remarks": "Salary",
    "QueueTimeOutURL": "http://localhost:8000/api/b2c/timeout?id=9",
    "ResultURL": "http://localhost:8000/api/b2c/result?id=9",
    "Occasion": ""
  }'
```
```json
{
  "ConversationID": "AG_20261102_1qihd8bugxd7ufjajt5k",
  "OriginatorConversationID": "payout-123",
  "ResponseCode": "0",
  "ResponseDescription": "Accept the service request successfully."
}
```

Field rules:

| Field | Rule |
|---|---|
| `CommandID` | `BusinessPayment`, `SalaryPayment` or `PromotionPayment` |
| `Amount` | 10 to 250,000 |
| `PartyB` | `2547XXXXXXXX` or `2541XXXXXXXX` |
| `SecurityCredential` | Any non-empty value; it isn't checked |

A bad field returns HTTP 400 in Daraja's format:

```json
{ "requestId": "...", "errorCode": "400.002.02", "errorMessage": "Bad Request - Invalid Amount" }
```

**Result callback** (POSTed to `ResultURL`; query strings such as `?id=9` are kept):

```json
{
  "Result": {
    "ResultType": 0,
    "ResultCode": 0,
    "ResultDesc": "The service request is processed successfully.",
    "OriginatorConversationID": "payout-123",
    "ConversationID": "AG_20261102_1qihd8bugxd7ufjajt5k",
    "TransactionID": "TKSK7OCFG6",
    "ResultParameters": {
      "ResultParameter": [
        { "Key": "TransactionAmount", "Value": 250 },
        { "Key": "TransactionReceipt", "Value": "TKSK7OCFG6" },
        { "Key": "ReceiverPartyPublicName", "Value": "254708374149 - James Achieng" },
        { "Key": "TransactionCompletedDateTime", "Value": "02.11.2026 17:51:36" },
        { "Key": "B2CUtilityAccountAvailableFunds", "Value": 999750 },
        { "Key": "B2CWorkingAccountAvailableFunds", "Value": 0 },
        { "Key": "B2CRecipientIsRegisteredCustomer", "Value": "Y" },
        { "Key": "B2CChargesPaidAccountAvailableFunds", "Value": 0 }
      ]
    },
    "ReferenceData": {
      "ReferenceItem": { "Key": "QueueTimeoutURL", "Value": "http://localhost:8000/api/b2c/timeout?id=9" }
    }
  }
}
```

- A **failed** payout has a non-zero `ResultCode` and no `ResultParameters`.
- A **timeout** goes to `QueueTimeOutURL` with `ResultType: 1` and `ResultCode: "SFC_IC0003"`.
- The shortcode's balance is only reduced when the payout succeeds. A payout larger than the balance fails
  with `ResultCode 1` (insufficient funds).

### 4.4 STK push (Lipa na M-Pesa Online)

| Method | Path |
|---|---|
| POST | `/mpesa/stkpush/v1/processrequest` |
| POST | `/mpesa/stkpushquery/v1/query` |

`Password` must be `base64(BusinessShortCode + passkey + Timestamp)`. The simulator has no passkey to compare,
so it checks only that the decoded value starts with the shortcode and ends with the timestamp. The
synchronous answer carries `MerchantRequestID` and `CheckoutRequestID`. After the callback delay, the result
goes to `CallBackURL`:

```json
{"Body": {"stkCallback": {"MerchantRequestID": "29115-34620561-1", "CheckoutRequestID": "ws_CO_0211202619371563",
 "ResultCode": 0, "ResultDesc": "The service request is processed successfully.",
 "CallbackMetadata": {"Item": [{"Name": "Amount", "Value": 700}, {"Name": "MpesaReceiptNumber", "Value": "TK09BJJCQV"},
 {"Name": "TransactionDate", "Value": 20261102223718}, {"Name": "PhoneNumber", "Value": 254708374149}]}}}}
```

The query answers `500.001.1001 The transaction is being processed` until the customer has acted, and the
`ResultCode` after that.

What the customer does depends on the phone number prompted:

| Phone | Outcome |
|---|---|
| 254700000201 | Cancels the prompt (`1032`) |
| 254700000202 | Unreachable (`1037`) |
| 254700000203 | Not enough money (`1`) |
| 254700000204 | Wrong PIN (`2001`) |
| 254700000205 | Pays, but **no callback**: only the query and the statement show it |
| 254700000206 | Pays, callback sent twice |
| 254700000207 | Pays, callback after a long delay |

Any other number pays. The outcomes can also be forced from the Dashboard (STK group).

### 4.5 Safaricom's records: Pull API and statements

- `POST /pulltransactions/v1/register` and `POST /pulltransactions/v1/query` (`ShortCode`, `StartDate` and
  `EndDate` as `YYYY-MM-DD HH:mm:ss` Kenyan time, `OffSetValue`). Returns the shortcode's paid-in C2B and STK
  receipts as `{"ResponseCode": "1000", "Response": [[{transactionId, trxDate, msisdn, sender, transactiontype,
  billreference, amount, organizationname}]]}`, up to 1,000 per page.
- **C2B page → Download a statement:** a CSV in the M-Pesa org portal's layout (header block, then `Receipt No.,
  Completion Time, Initiation Time, Details, Transaction Status, Paid In, Withdrawn, Balance, ..., Other Party
  Info, Linked Transaction ID, A/C No.`). Upload it to TAP's M-Pesa reconciliation page.

Both include payments whose callback never arrived (scenario "no callback"), which is how a client's
reconciliation recovers them.

---

## 5. Co-op Bank

Paths and request bodies are the same as Co-op's SIT environment (`openapi-sit.co-opbank.co.ke`), taken from
the SIT Postman collection. Swap the host for `http://localhost:8090`. Trailing slashes are accepted.

| Method | Path | Purpose | Answer comes by |
|---|---|---|---|
| POST | `/token` | Access token | Response |
| POST | `/FundsTransfer/Internal/A2A_v3/3.0.0/` | Pay a **Co-op** account | Callback |
| POST | `/FundsTransfer/External/A2A/PesaLink_v4/4.0.0/` | Pay an account at **another bank** | Callback |
| POST | `/FundsTransfer/External/A2M/Mpesa_v2/2.0.0/` | Pay an **M-Pesa** number | Callback |
| POST | `/Enquiry/TransactionStatus/2.0.0/` | Result of an earlier transfer | Response |
| POST | `/Enquiry/AccountBalance_v2/2.0.0/` | Balance | Response |
| POST | `/Enquiry/MiniStatement/Account_v2/2.0.0/` | Last 10 transactions | Response |
| POST | `/Enquiry/AccountFullStatementPaginated/1.0.0/` | Statement for a date range | Response |
| POST | `/Enquiry/Validation/IPSL/1.0.0/` | Account name check (PesaLink) | Response |

### 5.1 Token

```bash
curl -u coop_consumer_key:coop_consumer_secret -d grant_type=client_credentials \
  http://localhost:8090/token
```
```json
{ "access_token": "…", "scope": "am_application_scope default", "token_type": "Bearer", "expires_in": 3600 }
```

- Wrong key or secret: HTTP 401 `{"error":"invalid_client", ...}`.
- Missing, wrong or expired token on another call: HTTP 401 with a gateway fault:

```json
{ "fault": { "code": 900901, "message": "Invalid Credentials", "description": "Invalid Credentials. Make sure you have provided the correct security credentials" } }
```

### 5.2 Moving money (disbursing to an account)

Choose the endpoint by where the receiving account is held:

- Co-op account (bank code 11): **Internal transfer** (`A2A_v3`)
- Any other bank: **PesaLink** (`PesaLink_v4`); add `BankCode` to each destination
- M-Pesa wallet: **M-Pesa payout** (`Mpesa_v2`); use `MobileNumber` instead of `AccountNumber`

**Request** (internal transfer):

```bash
curl -H "Authorization: Bearer $T" -H "Content-Type: application/json" \
  http://localhost:8090/FundsTransfer/Internal/A2A_v3/3.0.0/ -d '{
    "MessageReference": "TAP-L10482-1",
    "UserId": "TAP",
    "ISO2CountryCode": "KE",
    "CallBackUrl": "http://localhost:8001/api/webhooks/coop/payouts",
    "Source": {
      "AccountNumber": "01100000000100",
      "Amount": 1500,
      "TransactionCurrency": "KES",
      "Narration": "TAP L10482"
    },
    "Destinations": [{
      "ReferenceNumber": "TAP-L10482-1_1",
      "AccountNumber": "01100000012345",
      "Amount": 1500,
      "TransactionCurrency": "KES",
      "Narration": "LOAN L10482"
    }]
  }'
```

PesaLink destination:
```json
{ "ReferenceNumber": "X_1", "AccountNumber": "1234567890", "BankCode": "1", "Amount": 1500, "TransactionCurrency": "KES", "Narration": "..." }
```

M-Pesa destination:
```json
{ "ReferenceNumber": "X_1", "MobileNumber": "254708374149", "Amount": "1500", "Narration": "REFUND" }
```

**Synchronous response.** This means the request was accepted. **No money has moved yet.**

```json
{
  "MessageReference": "TAP-L10482-1",
  "MessageDateTime": "2026-11-02T17:51:33.595Z",
  "MessageCode": "0",
  "MessageDescription": "REQUEST ACCEPTED FOR PROCESSING"
}
```

**Checks made before accepting.** A request that fails one of these is refused with HTTP 400 and no transfer
is created:

| Check | Code |
|---|---|
| Missing or invalid field | `-4` `INVALID REQUEST: <field>` |
| `MessageReference` already used | `-3` `DUPLICATE MESSAGE REFERENCE` |
| Source account unknown | `-1` `INVALID ACCOUNT NUMBER: SOURCE` |
| Source not owned by the client | `-8` `ACCOUNT AUTHORIZATION FAILURE` |
| Source dormant, closed or blocked | `-6` `ACCOUNT INACTIVE: SOURCE …` |
| Destination amounts don't add up to `Source.Amount` | `-5` `DEBIT AND CREDIT AMOUNTS DO NOT BALANCE` |

**Processing.** After the delay, the worker:

1. Decides each destination's result (test cases, account status, unknown accounts).
2. Checks funds: **available balance** must cover the successful amounts plus the charge.
   Available = booked − blocked + overdraft. If funds are short, **every** destination fails with `-2` and
   nothing moves.
3. Debits the source once per successful destination, plus one charge line if a charge is set.
4. Credits the destination account. Co-op and other-bank accounts are both tracked. M-Pesa payouts only debit
   the source.
5. Sends the callback to `CallBackUrl`.

**Callback (success)**

```json
{
  "MessageReference": "TAP-L10482-1",
  "MessageDateTime": "2026-11-02T17:51:36.687Z",
  "MessageCode": "0",
  "MessageDescription": "Success",
  "Source": {
    "AccountNumber": "01100000000100",
    "Amount": 1500,
    "TransactionCurrency": "KES",
    "Narration": "TAP L10482",
    "ResponseCode": "0",
    "ResponseDescription": "Success"
  },
  "Destinations": [{
    "ReferenceNumber": "TAP-L10482-1_1",
    "AccountNumber": "01100000012345",
    "Amount": 1500,
    "TransactionCurrency": "KES",
    "Narration": "LOAN L10482",
    "TransactionID": "FT26306MMTVDY",
    "ResponseCode": "0",
    "ResponseDescription": "Success"
  }]
}
```

**Callback (insufficient funds)**

```json
{
  "MessageReference": "E2E-IFT-2",
  "MessageDateTime": "2026-11-02T17:51:36.724Z",
  "MessageCode": "-2",
  "MessageDescription": "INSUFFICIENT FUNDS",
  "Source": { "AccountNumber": "01100000000200", "Amount": 900, "TransactionCurrency": "KES",
              "Narration": "REFUND", "ResponseCode": "-2", "ResponseDescription": "INSUFFICIENT FUNDS" },
  "Destinations": [{ "ReferenceNumber": "E2E-IFT-2_1", "AccountNumber": "01100000012345", "Amount": 900,
                     "TransactionCurrency": "KES", "Narration": "", "TransactionID": "",
                     "ResponseCode": "-2", "ResponseDescription": "INSUFFICIENT FUNDS" }]
}
```

**How to read the result**

| Situation | `MessageCode` | `MessageDescription` | Transfer status in UI |
|---|---|---|---|
| All destinations paid | `0` | `Success` | `success` |
| Some paid, some failed | `0` | `PARTIALLY SUCCESSFUL` | `partial` |
| None paid | The first failure's code | Its reason | `failed` |

Always check each destination's `ResponseCode`. In a partial success, `MessageCode` is still `0`.

### 5.3 Transaction status

Use this when no callback arrived, or to confirm one. The result is the same as the callback.

```bash
curl -H "Authorization: Bearer $T" -H "Content-Type: application/json" \
  http://localhost:8090/Enquiry/TransactionStatus/2.0.0/ \
  -d '{"MessageReference":"TAP-L10482-1","UserId":"TAP"}'
```

| State | HTTP | Body |
|---|---|---|
| Processed | 200 | Same body as the callback, with a fresh `MessageDateTime` |
| Still pending | 200 | `MessageCode: "-10"`, `TRANSACTION PENDING` |
| Unknown reference | 404 | `MessageCode: "-9"`, `MESSAGE REFERENCE NOT FOUND` |

### 5.4 Balance

```bash
curl -H "Authorization: Bearer $T" -H "Content-Type: application/json" \
  http://localhost:8090/Enquiry/AccountBalance_v2/2.0.0/ \
  -d '{"MessageReference":"bal-1","UserId":"TAP","AccountNumber":"01100000000100"}'
```
```json
{
  "MessageReference": "bal-1",
  "MessageDateTime": "2026-11-02T17:51:39.705Z",
  "MessageCode": "0",
  "MessageDescription": "Success",
  "AccountNumber": "01100000000100",
  "AccountName": "TAP DISBURSEMENT ACCOUNT",
  "Currency": "KES",
  "ProductName": "CURRENT ACCOUNT",
  "ClearedBalance": 4998500,
  "BookedBalance": 4998500,
  "BlockedBalance": 0,
  "AvailableBalance": 4998500,
  "ArrearsAmount": 0,
  "BranchName": "HEAD OFFICE",
  "BranchSortCode": "11000",
  "AverageBalance": 4998500,
  "UnclearedBalance": 0,
  "ODLimit": 0,
  "CreditLimit": 0
}
```

Querying an account that isn't yours returns HTTP 400 `-8`. An unknown account returns `-1`.

### 5.5 Mini statement

```bash
curl -H "Authorization: Bearer $T" -H "Content-Type: application/json" \
  http://localhost:8090/Enquiry/MiniStatement/Account_v2/2.0.0/ \
  -d '{"MessageReference":"m-1","UserId":"TAP","AccountNumber":"01100000000100"}'
```

This returns `AccountNumber`, `AccountName` and the **last 10** lines, newest first, in `Transactions`. Each
line looks like this:

```json
{
  "TransactionID": "FT26306MMTVDY",
  "TransactionDate": "2026-11-02T17:51:36",
  "ValueDate": "2026-11-02",
  "Narration": "TAP L10482 TO [11] 01100000012345",
  "TransactionType": "D",
  "ServicePoint": "API",
  "TransactionReference": "TAP-L10482-1",
  "CreditAmount": 0,
  "DebitAmount": 1500,
  "RunningClearedBalance": 4998500,
  "RunningBookBalance": 4998500,
  "DebitLimit": 0,
  "LimitExpiryDate": ""
}
```

`TransactionReference` holds the transfer's `MessageReference`, so reconciliation can match statement lines
to payouts. `TransactionType` is `D` (debit) or `C` (credit).

### 5.6 Full statement (paged)

```bash
curl -H "Authorization: Bearer $T" -H "Content-Type: application/json" \
  http://localhost:8090/Enquiry/AccountFullStatementPaginated/1.0.0/ -d '{
    "MessageReference": "stmt-1",
    "UserId": "TAP",
    "ISO2CountryCode": "KE",
    "AccountNumber": "01100000000100",
    "StartDate": "2026-11-01",
    "EndDate": "2026-11-02",
    "PageNumber": 1,
    "PageSize": 100
  }'
```

- `StartDate` and `EndDate` are `YYYY-MM-DD`, inclusive.
- `PageNumber` and `PageSize` are optional, with defaults 1 and 100 (maximum 500).
- Lines come **oldest first**.

The response has the usual header plus:

```json
{
  "AccountNumber": "01100000000100",
  "AccountName": "TAP DISBURSEMENT ACCOUNT",
  "Currency": "KES",
  "StartDate": "2026-11-01",
  "EndDate": "2026-11-02",
  "OpeningBalance": 0,
  "ClosingBalance": 4998490,
  "PageNumber": 1,
  "PageSize": 100,
  "TotalPages": 1,
  "TotalRecords": 3,
  "Transactions": [ /* same line format as the mini statement */ ]
}
```

`ClosingBalance` for a range ending today equals `BookedBalance` from the balance enquiry, so the
"statement matches balance" check can be tested.

### 5.7 Account validation (PesaLink name check)

```bash
curl -H "Authorization: Bearer $T" -H "Content-Type: application/json" \
  http://localhost:8090/Enquiry/Validation/IPSL/1.0.0/ \
  -d '{"MessageReference":"v-1","AccountNumber":"1234567890","RecipientBankIdentifier":"0001"}'
```
```json
{
  "MessageReference": "v-1",
  "MessageDateTime": "2026-11-02T17:51:33.580Z",
  "MessageCode": "0",
  "MessageDescription": "Success",
  "AccountNumber": "1234567890",
  "AccountName": "ACME SUPPLIERS LTD",
  "BankCode": "1",
  "Currency": "KES"
}
```

- `RecipientBankIdentifier` can have leading zeros: `0001`, `001` and `1` all mean KCB, and `0011` means Co-op.
- An unknown account returns HTTP 400 `-1`. A dormant, closed or blocked account returns `-6`.
- To test a name mismatch, change the account's name in the UI (Co-op → account → Account settings).

---

## 6. Testing failures

Each transaction's outcome is decided in this order:

1. **A forced next outcome**, if one is queued. It applies to the next transaction of that type only.
   Queue one from the Dashboard (all types) or the Co-op Accounts page.
2. **A test number or account** used in the request.
3. **Real state**: balances, holds, account status, and how your validation URL replies.
4. Otherwise the transaction **succeeds**.

### M-Pesa test phone numbers

These apply as the B2C `PartyB`, and as the C2B `Msisdn` where the outcome makes sense.

| Phone | Outcome |
|---|---|
| 254700000001 | Insufficient funds (`ResultCode 1`) |
| 254700000002 | Invalid initiator (`2001`) |
| 254700000003 | Recipient not registered (`2040`) |
| 254700000004 | Timeout: `QueueTimeOutURL` is called |
| 254700000005 | No callback at all |
| 254700000006 | Callback sent twice |
| 254700000007 | Callback after a long delay |
| 254700000008 | Recipient limit exceeded (`2006`) |

### Co-op test destinations

| Destination account / phone | Outcome |
|---|---|
| 01100000000001 / 254700000101 | `-1` Invalid account |
| 01100000000002 | `-6` Account inactive |
| 01100000000003 / 254700000103 | `-7` Limit exceeded |
| 01100000000004 | `-12` Destination bank unreachable |
| 01100000000005 / 254700000105 | Succeeds, **no callback** (use transaction status) |
| 01100000000006 / 254700000106 | Succeeds, callback sent **twice** |
| 01100000000007 / 254700000107 | Succeeds, callback after a **long delay** |

The first four fail only **their own destination**. Combine one with a normal destination to get a partial
success.

### Co-op forced outcomes

| Outcome | Effect |
|---|---|
| Insufficient funds | Whole transfer fails with `-2`, even if funds are there |
| Invalid / inactive account, limit, bank unreachable | Every destination fails with that code |
| First destination succeeds, the rest fail | Partial success |
| No callback / duplicate callback / long delay | As in the table above |
| **Co-op down** | The request itself is refused with **HTTP 503** `-11`, and nothing is created |

### Using real state

| To test | Do this in the UI |
|---|---|
| Insufficient funds | Withdraw from the source account, or set a **blocked amount** |
| Overdraft allowed | Set an **overdraft limit** |
| Dormant or closed account | Change the account's **status** |
| Unknown recipient | Turn off **Settings → create unknown recipient accounts automatically**; transfers to unknown accounts then fail with `-1` |
| Bank charges in statements | Set a charge per rail in **Settings**. It is posted as a separate debit line, e.g. `PESALINK CHARGE TAP-L10482-1` |
| C2B rejected by validation | Turn validation **On** for the shortcode and have your validation URL reply with a non-zero `ResultCode` |
| Callback endpoint down | Point the callback URL at something that isn't running. The attempt is recorded with the error |

---

## 7. Using the UI

Open **http://localhost:8090**.

| Page | What you can do |
|---|---|
| **Dashboard** | Today's counts, both sets of credentials, the endpoint list, shortcode balances, the forced-outcome queue, live recent M-Pesa transactions |
| **Transactions** | Search M-Pesa transactions by receipt, phone, account or conversation ID; filter by type and status |
| **Transaction page** | Request, sync response, and every callback sent with your app's reply and timing. **Send callback now** skips the delay; **Resend callback** tests duplicate handling |
| **C2B** | Pay as a customer, register URLs, turn validation on or off |
| **B2C** | Send a payout, see the test phone numbers |
| **Co-op Bank → Accounts** | Own accounts with available balance, recipient accounts, add an account, force the next outcome, test destinations |
| **Co-op account page** | Balances, **failed transfers with reasons**, statement by date, deposit or withdraw, edit name, status, hold and overdraft |
| **Co-op Bank → Transfers** | All transfers with status and result code; search by reference, account or phone |
| **Co-op transfer page** | Destinations with their results, request, response, **what a status enquiry would return**, callbacks sent. **Process now** / **Resend callback** |
| **Co-op Bank → Send money** | Send an internal, PesaLink or M-Pesa transfer through the same logic as the API |
| **Settings** | Callback delay, Co-op charges, auto-create recipients, API apps, shortcodes and balances |

List pages refresh every 3 seconds. Pending transaction pages reload every 2 seconds until the callback goes out.

### Built-in callback catcher

`/sim/catch/<anything>` accepts any request and replies `{"ResultCode":0,"ResultDesc":"Accepted"}`. Add
`?reject=1` to make it reply with a rejection, which is useful as a C2B validation URL. The UI forms use it as
their default callback URL, so you can test everything without a client app. The callbacks then appear on the
transaction or transfer page, **not in your app**.

---

## 8. Connecting bizwiz and tap_be

### bizwiz (M-Pesa)

In `bizwiz/.env`:

```dotenv
DARAJA_B2C_BASE_URL=http://localhost:8090
DARAJA_B2C_CONSUMER_KEY=sim_consumer_key
DARAJA_B2C_CONSUMER_SECRET=sim_consumer_secret
MPESA_AUTH_URL=http://localhost:8090
```

bizwiz builds its callback URLs from `APP_URL` (for example `APP_URL/api/disbursements/daraja/callback?...`),
so `APP_URL` must be an address the simulator can reach, such as `http://localhost:8000`.

Not simulated yet: B2B (`/mpesa/b2b/v1/paymentrequest`), transaction status, account balance and STK push.
bizwiz calls to those get a 404.

### tap_be (Co-op)

Set the Co-op settings (group `coop` in the settings store) to:

| Setting | Value |
|---|---|
| Host | `http://localhost:8090` |
| Consumer key / secret | `coop_consumer_key` / `coop_consumer_secret` |
| Disbursement account | `01100000000100` |
| Collections account | `01100000000200` |
| Callback base URL | tap_be's local URL, e.g. `http://localhost:8001` |

Matching the SIT test checklist in *TAP Co-op Bank Integration* (section 13):

| SIT test | How to run it here |
|---|---|
| Token: fetch, cache, refresh on 401 | Delete the Co-op app in Settings, which invalidates its tokens. Run `php artisan db:seed` (or restart the api container) to recreate the default credentials |
| Validation: matching / wrong name / closed account | Seeded recipients; rename an account; set its status to `closed` |
| Transfer success / failure | Normal transfer; test destinations or a forced outcome |
| No callback, then status check | Destination `01100000000005` or forced "no callback" |
| M-Pesa payout success / failure | `Mpesa_v2` to a normal number / `254700000101` |
| Same callback twice is recorded once | Destination `01100000000006`, or **Resend callback** |
| Full statement over several pages | Make several transfers, call with `PageSize: 1` |
| Co-op down: no money call sent twice | Forced outcome "Co-op down" (HTTP 503) |

Not simulated yet: Co-op STK prompt (`/FT/stk/1.0.0/`) and prompt status (`/Enquiry/STK/1.0.0/`).

---

## 9. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Transaction or transfer stuck on `pending` | The worker isn't running. Start `php artisan queue:work` or `docker compose up -d worker`. Use **Process now** to push one through. |
| "No callback" in my app | (1) The callback URL is the built-in catcher (UI default). Check the transfer page under **Callbacks sent**. (2) A no-callback test case was used. (3) The worker isn't running. |
| Callback attempt shows **No response** / connection refused | Your app isn't reachable from the simulator. Without host networking, `localhost` inside a container is the container itself. Use the provided compose file (host networking) or run without Docker. |
| Callback shows HTTP 4xx/5xx | Your endpoint rejected it. The response body is shown next to the payload. |
| Changes to code have no effect on callbacks | The worker keeps old code in memory. Run `php artisan queue:restart` or restart the worker container. |
| `401 Invalid Access Token` / `fault 900901` | The token is expired, or belongs to the other provider. M-Pesa and Co-op tokens are separate. |
| Co-op `-8 ACCOUNT AUTHORIZATION FAILURE` | The account isn't marked **Owned by the API client**. Tick it on the account page. |
| Co-op `-5` | The sum of `Destinations[].Amount` doesn't equal `Source.Amount`. |
| Co-op `-2` but the balance looks enough | Available = booked − **blocked** + overdraft, and the rail's **charge** is added to the amount. Check both on the account page and in Settings. |
| `SQLSTATE … Access denied` / can't connect | Check `DB_*` in `.env`, that MySQL listens on `127.0.0.1:3306`, and that `mpesa_sim` exists. |

---

## 10. Reference: codes

### M-Pesa B2C `ResultCode`

| Code | Meaning |
|---|---|
| 0 | Success |
| 1 | Insufficient balance |
| 2001 | Initiator information invalid |
| 2006 | Credit party has reached its account limit |
| 2040 | Recipient not registered / customer type not supported |
| SFC_IC0003 | Timed out (sent to `QueueTimeOutURL`) |

### Co-op `MessageCode` / `ResponseCode`

| Code | Description | Where |
|---|---|---|
| 0 | Success | Everywhere |
| -1 | INVALID ACCOUNT NUMBER | Sync (source) or callback (destination); validation; enquiries |
| -2 | INSUFFICIENT FUNDS | Callback |
| -3 | DUPLICATE MESSAGE REFERENCE | Sync |
| -4 | INVALID REQUEST: field | Sync |
| -5 | DEBIT AND CREDIT AMOUNTS DO NOT BALANCE | Sync |
| -6 | ACCOUNT INACTIVE | Sync (source), callback (destination), validation |
| -7 | TRANSACTION LIMIT EXCEEDED | Callback |
| -8 | ACCOUNT AUTHORIZATION FAILURE | Sync; enquiries on accounts that aren't yours |
| -9 | MESSAGE REFERENCE NOT FOUND | Transaction status (HTTP 404) |
| -10 | TRANSACTION PENDING | Transaction status |
| -11 | SERVICE TEMPORARILY UNAVAILABLE | Sync (HTTP 503, "Co-op down" outcome) |
| -12 | DESTINATION BANK UNREACHABLE | Callback |

> **Which codes are confirmed.** Co-op's SIT collection has request examples only. Response and callback
> shapes follow Co-op's published API docs. Only `0`, `-1`, `-5` and `-8` appear in Co-op's own samples. The
> other codes are the simulator's own. They are all in `app/Support/Coop.php`, so update them there when Co-op
> shares the official error list. Nothing else needs to change.

### Where things live in the code

| What | File |
|---|---|
| Daraja formats and IDs | `app/Support/Daraja.php` |
| M-Pesa outcomes and test numbers | `app/Support/Scenario.php` |
| Co-op codes, outcomes and test destinations | `app/Support/Coop.php` |
| C2B / B2C flows | `app/Services/C2BService.php`, `app/Services/B2CService.php` |
| Co-op transfers / enquiries | `app/Services/Coop/CoopTransferService.php`, `app/Services/Coop/CoopEnquiryService.php` |
| Account ledger (the only place balances change) | `app/Services/Coop/BankLedger.php` |
| Sending and recording callbacks | `app/Services/CallbackDispatcher.php` |
| API routes / UI routes | `routes/api.php`, `routes/web.php` |
