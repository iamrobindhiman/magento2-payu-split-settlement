# Engineering journal: per-vendor split settlement on PayU India

This is a record of building RKD_PayuSplit, a Magento 2 module that splits a customer's single
payment into per-vendor shares on PayU India, holds each share until the order is delivered and
the return window has closed, and then releases it. I built it in 2026 for a Webkul-based
marketplace called Allynor, which never switched it on in production. The client moved to a
different gateway and the project was shelved. What is left is a module with a lot of verified knowledge
behind it, and I think the knowledge is worth more than the code. This journal is for anyone
building marketplace payments on Magento, or on PayU specifically.

## 1. The problem

A marketplace takes one payment from a customer for an order that may contain items from several
vendors. Somebody then has to get each vendor their share, minus the marketplace's commission,
and give money back correctly when an item is cancelled or returned.

The naive version is to collect everything into the marketplace's own account and pay vendors out
later by bank transfer. In India that is a regulatory problem. The RBI's Payment Aggregator
directions (the master direction was issued on 15 September 2025) restrict who may hold
customer funds in transit. A marketplace that is not itself a licensed aggregator should not be
the party holding the money while it waits for delivery. My research notes concluded that the
marketplace does not need its own licence, provided no flow moves money outside the licensed
aggregator's custody. "Collect via PayU, pay vendors by bank transfer" is the flow to avoid. I am
a developer, not a lawyer, so treat that as the position I built against, not as advice.

PayU's answer is split settlement. The marketplace is the "parent" merchant. Each vendor is a
"child" (sub-merchant). A payment is split into segments, one per child plus a commission segment
on the parent, and the funds sit with PayU until the parent explicitly releases each child's share.
The holding period is the marketplace's to control: PayU has no per-child hold timer, so the
release call is the only lever. That is both the opportunity and the risk. The module's job is to
call that lever at exactly the right moment, never twice, and never for the wrong amount.

I locked one policy early: a vendor's money is released only after delivery and after the return
window closes. Releasing earlier and clawing back later was not an option, because I found no
documented way to reverse a release, and the "already accepted" error on a repeat call strongly
suggests it is a one-way state change. So release is treated as irreversible, and the whole
design is about making sure nothing irreversible happens early.

## 2. Architecture

The pipeline has four stages: calculator, trigger, refund and release.

**Calculator.** Given a paid order, it produces one segment per vendor plus the commission segment
on the parent, in integer paise with no float arithmetic anywhere in the money path. Vendor amounts
are a snapshot of each seller's totals taken at payment time and frozen into the split plan, never
re-read live, because the marketplace tables can change after payment and the split must match what
the customer paid. Sub-paise residue from rounding lands on the parent segment, so the segments
always sum to the payment exactly.

**Trigger.** A cron job scans paid orders and sends `payment_split`. I chose split-after rather
than split-during: the split call is made after payment, once the order is confirmed. Split-during
puts the split instructions inside the checkout request, which changes the request hash on the
critical payment path. Split-after worked on transactions at least 33 days old, so checkout stays
untouched. One caveat I never closed: whether funds sit in an ambiguous custody state between payment and a
later split cannot be proven in a sandbox, so split-during stayed in the notes as the conservative
fallback.

**Refund.** Refunds go through `cancel_refund_transaction` with a `var8` parameter that names which
child segment to debit and how much of the commission leg to debit with it. Only the parent can
refund a split transaction. An aggregator payment cannot be refunded at all until it is split, so
a full cancellation before shipping must split first and then refund.

**Release.** `release_settlement` per child per transaction, only when the segment's eligibility
conditions all hold: every order item delivered, every item's return window closed, no open
return or replacement, no hold on the segment, no refund still queued on it, the order's split
complete, and (for the commission segment on the parent) every vendor segment already terminal.

### The segment state machine

Each vendor segment moves through states such as pending, awaiting delivery, in return window,
return hold, eligible, release requested, released, cancelled, refund pending, partially refunded
and refunded. Two design points mattered more than the list.

First, state is layered. A single enum cannot represent facts that are true at once (one item
returned, another delivered, a hold applied, a refund queued), so per-item state is authoritative
and the segment derives eligibility from the item rows plus a separate set of holds and a money
state. The stored lifecycle label is for display, not decisions.

Second, states only move forward. Released is terminal. A refunded segment, or the refunded part
of one, is never released.

### The write-ahead ledger and idempotency

PayU gives no idempotency key. The protocol I derived from experiment is:

- Before sending anything money-moving, write the intent to the local ledger and claim it with a
  compare-and-set, so two overlapping cron runs cannot both send.
- A repeat `payment_split` on an already-split transaction returns error AGG-104. Treat that as
  success and read the split back with `get_split_info`. Never re-post a different allocation.
- AGG-103 means the transaction is not splittable. AGG-302 means no split exists, so it is safe to
  retry.
- A PENDING response (status 2) means poll `get_split_info`. Never re-post.
- Transport failures (the request may or may not have landed) are retried by a bounded
  reconciliation job that first re-checks the order's current cancel, return, hold and refund state,
  because the world may have changed since the original attempt.

Splits are atomic on PayU's side and only one successful split per transaction is allowed, so a
wrong split cannot be fixed later. Validation happens before the call.

## 3. Invariants

I wrote the invariants down before the code and every review wave measured against them.

1. **Money conservation.** The sum of all segments equals the payment, exactly, in paise. Customer
   refunds plus what vendors ultimately keep plus what the marketplace keeps equals what the
   customer paid.
2. **Refund bound.** Cumulative refunds attributed to a vendor never exceed that vendor's segment.
   The commission portion refunded never exceeds the parent's share. Total refunds never exceed the
   payment. This is enforced locally before the request is sent, never left to PayU to reject.
3. **Attribution.** Each refund debits the right segment and the matching commission leg, and no
   uninvolved vendor's segment is touched.
4. **Refunds based on what was actually paid.** The refund calculation uses the paid amounts that
   were snapshotted, not current order totals.
5. **Split before refund.** No refund is attempted on a transaction that has not been split.
6. **Refund before release.** A segment is never released while any refund against it is pending or
   queued, and a refunded amount is never released. Release is idempotent and happens at most once
   per segment.

Two more: the Webkul ledger must agree with the release, and the amount PayU actually released is
read back before a segment is marked released, with a mismatch freezing the segment.

## 4. The PayU API, as found by experiment

I started with documentation research and a list of questions for PayU, sent in April 2026. From
8 June I ran a sandbox lab against PayU's UAT environment and logged every call. What surprised me:

**Response formats are not uniform.** `get_split_info` on UAT returned PHP `print_r` text wrapped in
`<pre>` tags rather than JSON. `payment_split` returned JSON. A client that assumes one format
breaks on the other. Refund responses are JSON keyed by merchant key, with the status nested under
each key rather than at the top level, so the parser reads each leg separately.

**A split-enabled account does not split by itself.** Transactions stay unsplit until you call
`payment_split`. A `splitInfo` field appearing in a response was response-shaping, not a split.

**A 100% split to the parent alone is valid.** That meant I could test the whole split mechanism
before I had a single child merchant.

**The response hash changes when split information is present.** A wrong salt or hash gives AGG-300.
Getting a clean business error such as AGG-302 instead is how I confirmed my hash was right.

**One child-keyed refund produces two legs.** A single `var8` naming a child with a non-zero
aggregator refund amount queued two separate refunds, one against the child and one against the
parent's commission, each with its own request id. My design depended on this (refund vendor C's
portion plus its commission and leave A and B alone) and an earlier experiment had been over-cited as
proving it. It only proved the parent-keyed, zero-aggregator shape. A review caught the over-claim,
and a later experiment tested the real shape.

**Release status can't be trusted on its own.** PayU's docs say `release_settlement` inverts the
status (0 = accepted, where the other commands use 1 for success). Every UAT release I sent came back
as status 0 *with* the error "Some problem occurred", so the status field alone can't tell an accepted
release from a failed one. A shared "is this response successful" helper would misread it either way.
I left a loud warning in the code, gave release its own interpretation, and confirm a release through
the settlement readback instead.

**Getting a release to work in UAT never happened.** For every input I sent, `release_settlement`
returned the same generic "Some problem occurred", status 0, including a deliberately invalid child
MID. Because the invalid input produced the same error, the request was clearly failing before it
reached any documented validation. I tried the MID and the merchant key as the child identifier, the parent payment id and the
child's segment id as the transaction identifier, both endpoint spellings, same-day and next-day. My first hypothesis was that funds only become releasable after the next settlement
cycle. The next-day retry ruled it out. `get_split_info` showed the split intact but exposed no settlement or held field at all. My
conclusion was that UAT does not simulate the settlement lifecycle. I drafted a support ticket
asking whether it does and how to validate release before go-live. The remaining plan was to verify
release in production with a small order on the marketplace's own child merchant, which keeps the
money in-house. I never got there. The practical consequence is that the release path, the readback
of the released amount and the question of whether a release after a partial refund pays the net or
the gross balance were all built and tested against my own model of PayU, not PayU.

**UAT refunds queue but never complete.** A refund is accepted and returns "Refund Request Queued"
(with an error code of 102 that accompanies a queued refund and is not a failure). Later I found a
status command, `check_action_status`, which takes the refund request id and returns per-leg status.
Every UAT refund I read back ended in failure, for both net banking and card payments, with no reason
given. PayU's dashboard also showed it automatically retrying a failed refund leg that I had not sent,
and the retry was invisible to the status APIs. So for UAT the only evidence I have is that a request
was accepted and correctly attributed, not that money moved. That is exactly why the refund path got
a status reconciler (below): a queued refund that quietly fails must not leave a customer unrefunded
with no alarm.

**Reconciliation has no webhooks.** PayU sends no split or settlement webhooks, so everything is
polling. `get_aggregator_transactions` returns split state per transaction and worked as a
reconciliation source.

**Onboarding facts.** Child merchants are created through an OAuth-authenticated API. Business
entity types expanded from 8 to 17 between my two scrapes, and my first mapping was wrong for
several. Activation requires a three-step e-signature of the merchant agreement (generate, OTP,
sign), a vendor-facing step.

## 5. Timeline

| Date (2026) | What happened |
|---|---|
| 17 April | Research finished; list of questions sent to PayU |
| 6 to 8 June | Documentation re-scraped; sandbox lab started; split-after, idempotency and refund-before-release proven |
| 8 to 17 June | Engine slices built: calculator, data layer, gateway client, state model, split trigger, refund executor, admin grid, delivery poll, release executor and recon, Webkul ledger mirror and the "Pay Seller" suppressor |
| 11 June | Child-keyed dual-leg refund proven |
| 17 June to 5 July | Release-gate hardening, items #1 to #22, driven by adversarial review |
| 20 June | Checkpoint: engine complete and switched off, roughly 510 unit tests, nothing deployed |
| 10 September | Seller onboarding work: state model, readiness rule, OAuth client (the `onboarding` branch) |
| 5 to 6 October | Refund readback discovered; every UAT refund observed ending in failure |
| November | Namespace renamed; module archived |

## 6. How it was reviewed

I built the engine in slices, each with unit tests. When the engine was complete I did something
more deliberate: a series of adversarial review waves where fresh-context reviewers were asked to
break it rather than confirm it. Each reviewer got the invariants and the recorded trade-offs, and
each finding was re-checked against the code before I acted on it. A reviewer's claim is a lead,
not a fact. The result was the release gate: a checklist of 22 items, each of which had to be
closed before the master switch (a config flag, off by default) could be turned on.

The bug classes were consistent, and none of them were typos:

- **Cancel and return signals not reaching the release gate** (#1). A late cancellation could let a
  seller be paid before the customer was refunded.
- **Bounded shipping on full unwind** (#2, #8). A refund returned the wrong amount of shipping, or a
  fully discounted line silently dropped the shipping owed.
- **Concurrency** (#3, one concurrent-write residual). Overlapping cron runs could refund the same
  customer twice; the fix was an atomic compare-and-set claim plus a parent-row lock.
- **Two payout systems disagreeing** (#4). Webkul has its own "Pay Seller" button. A refunded order
  could still be paid out by it, so the module closes the seller in Webkul's ledger and suppresses
  that path for settled orders.
- **Money moved outside the engine** (#5, #18, F3). An admin credit memo, or an adjustment or
  shipping credit memo, created outside the split could let a vendor be overpaid. The release now
  freezes for review. The blast radius (a credit memo freezes the whole order, not one segment) is a
  recorded, deliberate trade-off.
- **Silent failure** (#6, #9, #11, #13). A queued refund that failed, an approved return that blocked a
  release, a frozen hold: each now raises an alarm, and a guarded break-glass admin action clears a
  frozen hold once someone has reconciled it by hand.
- **Transport failure** (#10, F2). A release whose network call never landed is re-fired by a bounded
  recon job, but only after re-checking cancel, return, hold and refund state.
- **Partial delivery** (F1, F1-qty, #22). Delivery is stamped per order item, and release is gated on
  delivered quantity, so a partial shipment cannot release the whole segment. A segment with a
  non-integer ordered quantity is frozen rather than guessed at.
- **Response-parsing assumptions** (F4, #21). A numeric merchant key was dropped when parsing a
  refund leg, and payment-settlement vocabulary had leaked into the set of values treated as refund
  success. Both are parsing bugs that tests with tidy fixtures would not find.
- **Partial refund then release** (#12). After a completed or partial refund the remainder should
  eventually pay out. It reversed an earlier guard and depends on a PayU behaviour I could not
  verify (does release pay the net balance or the gross?).

Deterministic gates ran first and reviewers second; a reviewer should not be spent confirming what a
test run already proves.

## 7. Why it was shelved

The client moved to a different gateway before the module went live. Several things were still open
when that happened, and I list them because they are the real distance between "built" and "safe to
turn on":

- Release had never been verified against a real PayU settlement. The plan was a small production
  order on the marketplace's own child merchant.
- Whether release pays net or gross after a partial refund was unverified.
- UAT refunds never completed, so I had no end-to-end proof of money returning.
- How PayU treats a suspended child merchant's share is undocumented and cannot be simulated.
- Whether the commission segment on the parent auto-settles was unanswered. If it does, the rule
  that the parent is released last cannot be enforced, and the fallback would be to route commission
  through a marketplace-owned child merchant.
- The returns module the refund path depended on was still waiting on a second version.


## 8. What I would do differently

- **Probe the hard API on day one.** I built the whole engine around a release call I could not run.
  A day spent proving or disproving release in production, with the smallest possible real order,
  would have been worth more than a week of engine work on top of an assumption.
- **Treat a sandbox as an unreliable witness.** UAT showed me request shapes, not what the money
  does. I should have tagged each capability "proven by UAT", "docs only" or "unverified".
- **Keep leading with the state model and invariants.** That order worked. The bugs that survived to
  review were mostly at the boundaries between systems (Webkul, returns, credit memos), not inside
  the calculator.
- **Name the call that proved each claim.** One early experiment was quoted as proof of something it
  did not test.
- **Keep one branch.** The onboarding work was cut from an earlier line and the two were never
  merged, so the onboarding branch lacks the later hardening. Merging as I went would have avoided
  a fork that now has to be read carefully.
- **Build the alarms with the feature.** Silent failure was the largest class of review finding.
  Every money-moving path should ship with its own "this is stuck" alarm.

The parts most likely to transfer are integer paise, the snapshot at payment, the layered state
model, a compare-and-set claim before every send, and a master switch that stays off until a written
gate is closed. The PayU specifics are a snapshot of one API as it behaved in mid-2026.
