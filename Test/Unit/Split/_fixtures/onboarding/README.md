# Recorded PayU onboarding responses

Captured from **live PayU UAT calls on 2026-09-10**, not from PayU's documentation.
That distinction matters: the documented samples differ from what the API returns,
and PayU's published onboarding documentation contained errors when these were captured (2026-09) (a production host
that does not resolve, a token TTL wrong by ~360×, and two APIs that do not exist).
These files are the ground truth the parser is tested against.

| File | Exchange | HTTP |
|---|---|---|
| `01-token.json` | `POST uat-accounts.payu.in/oauth/token`, scope `refer_child_merchant` | 200 |
| `02-create-child.json` | `POST /api/v3/product_accounts` — a correctly parented child | 200 |
| `03-attach-bank.json` | `PUT /api/v3/product_accounts/{uuid}` with `bank_detail` | 200 |
| `04-attach-bank-again.json` | the same PUT a second time — the one-shot refusal | 422 |
| `05-list-sub-accounts.json` | `GET /api/v3/product_accounts/{mid}/sub_accounts`, scope `fetch_child_merchants` | 200 |
| `06-token-fetch-scope.json` | token for scope `fetch_child_merchants` | 200 |
| `07-get-child-by-uuid.json` | `GET /api/v3/product_accounts/{uuid}`, ~2 min after creation | 200 |

## Redactions

This copy is sanitised for publication. Every identifying value was replaced with a
synthetic one of the same shape; the JSON structure is untouched:

- `access_token` → 64 `a` characters (the real shape, no live bearer in the repo)
- parent MID → `9000100`, child MIDs → `9000001`..`9000004`
- merchant keys (`pg_key`) → `CHLD01`..`CHLD04`
- UUIDs → `0000-0000-0000000N-0000-00000000000N`; internal numeric ids → `100000N`
- emails → `@example.invalid`, owner email → `owner@example.com`
- the settlement account number → `50100000000000`, IFSC → `HDFC0000000`
- the registered mobile → `9000000000`, PAN → `AAAPA0000A`

## What these fixtures prove, and why it is not obvious

**`02` vs `07` is the important pair.** They are the same account two minutes apart.

| field | at creation (`02`) | read back (`07`) |
|---|---|---|
| `onboarding_completed` | `true` | `true` |
| `document_status` | `Docs Approved` | `Pending` |
| `onboarding_status` | `Settlement Enables` | `Documentation & Website Verification in progress` |
| `bank_detail.bank_verification_status` | *(no bank yet)* | `Success` |
| `kyc_status.status` | `LOCKED` | `LOCKED` |
| `is_service_agreement_esigned` | `false` | `false` |

`onboarding_completed` is **true from the first second** — before a bank account,
before Schedule C, before PayU has looked at the account. It looked like the "strongest lead" for
detecting activation. It cannot be: it is true on an account that demonstrably
cannot be paid. `document_status` is worse — it goes *backwards*.

This is defect D-2 all over again, which is why `SubmerchantReadiness` is the only
class allowed to decide readiness, and why nothing here does.
