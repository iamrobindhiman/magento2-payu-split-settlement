# RKD_PayuSplit: Magento 2 split payment module for PayU India

A Magento 2.4 module that adds **per-vendor split settlement** on top of the PayU India
payment gateway, for multi-vendor marketplaces built on Webkul Marketplace.

> **Status: archive.** Built in 2026 for the marketplace Allynor, then shelved when the
> project moved to a different gateway. The code was renamed from its original namespace to
> `RKD\PayuSplit` for this archive, and has **not been install-tested since the rename**.

For the story behind it (the problem, the design, the PayU API quirks found by experiment, the
review process and why it stopped), read [JOURNAL.md](JOURNAL.md).

## What it does

- **Split calculator**: splits each paid order into one segment per vendor plus the
  marketplace's commission. Money is always integer paise; there is no float arithmetic.
- **Split trigger**: a cron job scans paid orders and sends `payment_split` to PayU.
- **Refunds**: `var8` refunds charged against the right vendor segment and the matching
  commission leg. Guards cover over-refund, double-refund and mis-allocation.
- **Release**: releases a vendor's money only after delivery and the end of the return window.
- **Ledger and state model**: per-vendor segment states, a write-ahead ledger, and
  idempotent retries.
- **Admin diagnostics**: staff tools and alarms for money the system could not place.
- **Webkul "Pay Seller" suppressor**: stops the same money from being paid twice through
  Webkul's own payout path.
- **Seller onboarding** (`onboarding` branch only): sets up each seller as a PayU sub-merchant
  through PayU's OAuth onboarding API.

## Invariants

Conservation of money, the refund bound, refund attribution, refunds based on the amount
actually paid, split before refund, and refund before release. They are described in
[JOURNAL.md](JOURNAL.md).

## Dependencies

| Module | Why |
|---|---|
| `PayUIndia_Payu` (composer `payuindia/payu`) | The base PayU India payment method |
| `Webkul_Marketplace` 5.x | Vendor, order and payout tables (commercial module, not included) |
| `Fab_Returns` | Return-window close drives the release gate (project-specific, not included) |
| `Fab_ShiprocketMarketplace` | Delivery status drives the release gate (project-specific, not included) |

The two `Fab_*` modules belonged to the original project and are not in this repository.
To reuse this module, swap them for your own delivery-status and return-window providers.

## Branches

| Branch | Contents |
|---|---|
| `main` | The split engine (calculator, trigger, refunds, release, ledger) with the full release-gate hardening, gate items #1 to #22 |
| `onboarding` | Adds seller onboarding as a PayU sub-merchant: OAuth client, readiness rule, staff tools, Loki admin guards. It was cut from an earlier line, so it does **not** include gate items #6 to #22. The two branches were never merged |

**You are looking at the `main` branch.**

## Install (untested after the rename)

```
app/code/RKD/PayuSplit/   <- the repository root goes here
bin/magento module:enable RKD_PayuSplit
bin/magento setup:upgrade && bin/magento setup:di:compile
```

PayU credentials live in `app/etc/env.php`. They are never committed.

## Licence

MIT. See [LICENSE](LICENSE).
