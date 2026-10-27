# Product shadow-validation contract

This specification defines how an OpenPairing-powered ChessPairings candidate
may accumulate live product evidence without influencing published pairings.
It does not authorize a production integration and it is not active until an
exact product candidate has been selected.

## Safety boundary

The existing production engine remains the sole decision-maker. The shadow
path receives an immutable snapshot after normal input validation, runs
offline, and writes only to an isolated evidence sink. It must not update a
tournament, retry the primary action, publish pairings, call public production
endpoints or alter the operator workflow. A missing shadow result is an
infrastructure observation, never permission to substitute its output.

## Identity-scoped observation

Each attempted turn records one `openpairing.shadow-turn/1` envelope containing:

- opaque event ID, UTC time and exact ChessPairings product/build identity;
- SHA-256 of the validated `openpairing.thp-candidate-identity/1` manifest;
- OpenPairing package, engine, source-tree and runtime archive SHA-256 values;
- algorithm/schema versions and arithmetic backend;
- tournament mode, pairing system, round, player count and declared initial
  colour, with no player name, email, federation ID or other personal data;
- canonical input SHA-256 and primary/shadow functional-output SHA-256 values;
- primary elapsed time, shadow elapsed time, peak memory and observation
  status;
- validator outcome and, when needed, the independent classification reference.

Raw states containing personal data remain inside the controlled environment.
Reproduction fixtures leaving it must be minimized and pseudonymized. The two
comparison hashes cover the publishable product pairing projection: ordered
boards, colours and PAB. OpenPairing's isolated result additionally hashes its
float assignments, quality vector and violations for local validation, but that
engine-internal hash cannot be compared with an incumbent product that exposes
only pairings and is not exported as if it were the same projection.

The transport formats are pinned by
`schema/shadow-turn-1.schema.json` and
`schema/shadow-evidence-batch-1.schema.json`. A batch manifest records the
exact identity, event-stream SHA-256, attempted-turn denominator, product
budgets and hash-pinned fixture coverage. Each observation is first published
as one flushed immutable file by atomic same-filesystem promotion. A closed
batch deterministically orders those files and builds the newline-delimited
JSON stream; evidence is never accepted from a partial append and raw personal
state is not retained in the stream.

Close a directory of immutable event files into one new, deterministic stream
without overwriting an earlier batch:

```console
php tools/build_shadow_event_stream.php events/ shadow-events.ndjson
```

The builder rejects an empty directory, symbolic links, hidden temporary files,
unexpected names, filename/event-ID disagreement and an existing output. It
sorts event filenames, recursively normalises object keys, flushes the complete
stream and publishes it atomically. Its `eventsSha256` is the value to place in
the batch manifest before running the independent verifier.
The permanent builder regression and its PHP 8.3/GMP execution checkpoint are
recorded in
`evidence/shadow-event-stream-regression-checkpoint-20260816.json`.

For a live cohort, event files alone do not prove the denominator: a process
could fail before writing one. Every selected turn must first reserve one
immutable `openpairing.shadow-attempt/1` record conforming to
`schema/shadow-attempt-1.schema.json`. Build the official observation stream
with:

```console
php tools/build_shadow_observation_stream.php attempts/ events/ shadow-events.ndjson
```

This builder rejects outcomes without a reservation and outcomes whose
identity, tournament, canonical input or feature set differs from the reserved
attempt. A reservation without an outcome becomes an explicit `not-observed`
event, which makes the normal gate `NO_GO` instead of silently improving the
observation rate. The event-only builder remains useful for deterministic
laboratory fixtures, but is not proof of a live attempt denominator. The PHP
8.3 regression checkpoint for this fail-closed join is
`evidence/shadow-attempt-denominator-checkpoint-20260816.json`.

The matching isolated ChessPairings rc.20 primitive reserves that attempt
before invoking the engine, exposes the already verified combined identity,
and rejects duplicate IDs, identity drift and invalid feature labels. Its
source-freeze, portable integration test and bundle are attested in
`evidence/chesspairings-shadow-attempt-ledger-checkpoint-20260816.json`.
That checkpoint does not connect a live call site or start official shadow
evidence; those remain subject to the owner/privacy review and rollout steps
below.

The follow-up isolated ChessPairings rc.21 coordinator now composes request,
attempt, engine call, comparison, post-compute fresh-snapshot callback,
precommit guard and immutable event sink. Its 34-check PHP 8.3/GMP regression,
PHPStan-max pass and reproducible source bundle are attested in
`evidence/chesspairings-shadow-observation-coordinator-checkpoint-20260816.json`.
A stale revision, changed canonical input or reread failure leaves the attempt
visible without an event. The callback is still synthetic: the checkpoint
does not prove a database read, transaction rollback or live product call site
and does not start official shadow evidence.

Run `php tools/verify_shadow_evidence.php MANIFEST EVENTS_NDJSON`. The default
gate requires 10,000 observed turns; a lower `--minimum-turns=N` is only for
synthetic/staging checks. Exit `0` is `GO`, exit `1` is an honest `NO_GO`, and
exit `2` is malformed, incomplete or identity-mixed infrastructure evidence.
The verifier rejects duplicate event IDs, denominator/hash mismatches,
personal-data keys, identity drift, unclassified non-exact turns, missing
coverage and product-budget overruns. It does not enable a production shadow
path or authorize a product switch.
The identity includes the exact `engineSha256`; the adapter, schema and
verifier alignment is exercised in
`evidence/shadow-engine-identity-contract-checkpoint-20260816.json`.

A locally detected output violation is recorded as a blocking `NO_GO` with a
failing validator, not misreported as an infrastructure error. Conversely,
an `exact` result must have equal functional hashes and a non-exact valid
result must not reuse that same hash. The executable semantics checkpoint is
`evidence/shadow-evidence-verifier-semantics-checkpoint-20260816.json`.

## Classification

Every observed turn belongs to exactly one category:

1. `exact`: boards, colours and PAB are identical;
2. `same-pairs-colour-variant`: opponents/PAB match but colours differ;
3. `legal-variant`: different pairing, independently proven no worse under all
   applicable FIDE quality criteria;
4. `inferior`: the shadow result is worse than an independently established
   attainable result;
5. `local-violation`: accounting, C1-C3, colour, PAB, score/history or another
   local validator fails;
6. `no-solution` or `resource-limit`;
7. `invalid-input`;
8. `infrastructure-error` or `not-observed`.

A difference is not accepted from equality alone and is not rejected merely
because an incumbent engine chose another legal optimum. Every non-exact
result, including a colour-only variant, requires classification and a
minimized reproduction reference under the same Gacrux/oracle-first policy
used by the corpus gate; a local violation requires its reproduction. The
normative FIDE rule takes precedence over an oracle defect.

## Acceptance gate

The first stable product candidate may close the shadow gate only when one
immutable identity has:

- at least 10,000 observed real pairing turns and no silent denominator loss;
- zero `inferior`, `local-violation`, `no-solution` and `resource-limit` results
  in the declared supported range;
- every non-exact turn classified and linked to a minimized reproduction;
- zero uninvestigated infrastructure errors, plus an explicit observation-rate
  denominator;
- both initial colours, odd/even fields, withdrawals, PABs, forfeits and
  scheduled byes observed or covered by exact-candidate end-to-end fixtures;
- accelerated tournaments observed or, if real volume is unavailable, closed
  by the immutable accelerated corpus partition;
- per-turn latency and memory within the product budget documented in the
  exact delivered build.

The 10,000-turn denominator is a planning threshold, not a TEC rule. TEC may
require a different sample or duration. Missing rare-case coverage remains an
open row and cannot be hidden by a high exact percentage.

## Rollout sequence

1. Freeze the pairing API in `API-STABILITY.md` and select one exact THP build.
2. Prove the same state reaches primary and shadow paths with matching hashes.
3. Run synthetic end-to-end fixtures with shadow writes isolated and primary
   output unchanged.
4. Enable observation for an internal/staging cohort, then a read-only live
   cohort only after owner and privacy review.
5. Review event denominators and classifications continuously; any inferior or
   local-violation result stops evidence acceptance for that identity.
6. Promote no engine from shadow automatically. A future product switch is a
   separate release, regression and rollback decision.

This contract deliberately keeps the live clock off the corpus critical path:
the product team can implement the observer while the offline partitions run,
but evidence starts only for an exact integrated candidate.
