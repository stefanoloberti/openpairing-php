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
Reproduction fixtures leaving it must be minimized and pseudonymized.
Functional hashes exclude elapsed-time diagnostics and trace prose; they cover
boards, colours, PAB, float assignments, quality vector and violations.

The transport formats are pinned by
`schema/shadow-turn-1.schema.json` and
`schema/shadow-evidence-batch-1.schema.json`. A batch manifest records the
exact identity, event-stream SHA-256, attempted-turn denominator, product
budgets and hash-pinned fixture coverage. The corresponding event stream is
newline-delimited JSON so it can be appended atomically without retaining raw
personal state.

Run `php tools/verify_shadow_evidence.php MANIFEST EVENTS_NDJSON`. The default
gate requires 10,000 observed turns; a lower `--minimum-turns=N` is only for
synthetic/staging checks. Exit `0` is `GO`, exit `1` is an honest `NO_GO`, and
exit `2` is malformed, incomplete or identity-mixed infrastructure evidence.
The verifier rejects duplicate event IDs, denominator/hash mismatches,
personal-data keys, identity drift, unclassified non-exact turns, missing
coverage and product-budget overruns. It does not enable a production shadow
path or authorize a product switch.

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
because an incumbent engine chose another legal optimum. Non-exact results
require the same Gacrux/oracle-first classification policy used by the corpus
gate, with the normative FIDE rule taking precedence over an oracle defect.

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
