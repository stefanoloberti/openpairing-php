# THP integration contract

This contract defines the minimum technical boundary for embedding
OpenPairingPHP in a future ChessPairings Tournament Handler Program (THP).
It is design evidence only: no current ChessPairings build is represented as
using OpenPairingPHP, and this document does not authorize a production
integration or a FIDE claim.

## One exact product identity

The submitted identity must pin, in one machine-readable record:

- ChessPairings product version, source commit and installer/runtime SHA-256;
- OpenPairing package version, source-tree, engine and runtime archive SHA-256;
- PHP version, arithmetic backend and operating-system profile;
- pairing system, algorithm version, TRF/schema versions and declared FIDE
  mode;
- PTC and RTG executable hashes from the same distribution.

The production pairing path, PTC and product shadow must resolve the same
OpenPairing source and algorithm identity. Copying equivalent source into a
different directory or rebuilding one executable independently is a new
identity until byte and semantic equivalence are re-proved.

The exact record uses `docs/schema/thp-candidate-identity-1.schema.json` and
is checked against a closed artifact directory with:

```console
php tools/verify_thp_candidate_identity.php candidate-identity.json artifacts/
```

The verifier rejects missing or extra identity fields, duplicate artifact
roles, unsafe paths, missing files and hash drift. The mandatory artifact
roles are the product installer, OpenPairing runtime archive, PTC and RTG.
Its `PASS` means only that one internally consistent byte identity was
observed; it never declares the product submission-ready or TEC-accepted.

Before that identity is created, freeze the exact product worktree and every
source-controlled migration path with:

```console
php tools/verify_thp_source_freeze.php PRODUCT_ROOT MIGRATION_PATH [MIGRATION_PATH ...]
```

The source-freeze verifier requires the exact root of one clean Git worktree,
a full commit, no untracked files, no tracked symbolic links or missing files,
at least one tracked file under every declared migration path, and a passing
value-redacting distribution secret scan. It emits deterministic source and
migration manifest hashes and then rechecks the commit and cleanliness. Its
`PASS` is read-only evidence for building a candidate identity; it does not
inspect a database, create an identity, deploy a build or authorize a release.

## Immutable input boundary

Before pairing, ChessPairings must construct one immutable canonical snapshot
containing all state that can affect the next round:

- tournament scoring, pairing system, round and initial colour;
- stable player IDs and pairing numbers, ratings/rank inputs and current
  availability;
- complete results, opponents, colours, PABs, withdrawals and unplayed-game
  semantics for every prior round;
- scheduled byes, Baku acceleration and prohibited-pairing groups;
- every declared score adjustment or a fail-closed rejection when the
  submitted scope does not support it.

The canonical input SHA-256 must be computed before calling the engine and
included in diagnostics/evidence. Database row order, localized labels,
display names and transport timestamps must not affect it. Ambiguous missing
state, including an indeducible initial colour, is an input error rather than
a default.

The pre-call envelope uses
`docs/schema/thp-pairing-request-1.schema.json`. Once a product adapter emits
the canonical JSON snapshot and envelope, verify their identity and derived
round metadata without calling the engine or changing the database:

```console
php tools/verify_thp_pairing_request.php request.json snapshot.json
```

This gate requires an opaque job ID, a product tournament revision and the
exact candidate-identity hash; checks the snapshot bytes, round, pairing
system, initial colour and player denominators; and rejects valid but
non-canonical JSON. Passing it is only the immutable pre-call boundary. The
same revision and input hash still have to be re-read immediately before the
atomic product commit.

The post-call envelope uses
`docs/schema/thp-pairing-result-1.schema.json`. Verify the complete result
against the same request, snapshot and closed candidate artifacts with:

```console
php tools/verify_thp_pairing_result.php request.json snapshot.json candidate-identity.json artifacts/ result.json
```

This second gate independently replays the candidate and request identity
checks; binds the result to the same job, revision and canonical input; checks
the engine version and hash; recomputes a trace-independent functional-output
hash; and runs `PairingResultValidator` against the canonical snapshot. A
locally invalid or incomplete result is an honest `NO_GO`; an identity or hash
drift is an infrastructure error. `GO` still does not re-read the live product
revision or authorize a database write.

## Call and failure semantics

The adapter calls only the supported surface in `API-STABILITY.md`. A success
is usable only when the returned result:

- accounts for every eligible participant exactly once;
- has no validation violation, partial output, no-solution or resource-limit
  marker;
- carries the expected algorithm and engine identity;
- passes the product-side reciprocal pairing, repeat-opponent, colour, PAB and
  prohibited-edge checks.

Any exception, timeout, identity drift, malformed output or non-success result
must leave the tournament unchanged. The FIDE-mode path must not silently
fall back to a different engine, manual pairing, a stale result or a partial
pairing. Operator intervention is explicit and outside the accepted evidence
stream.

## Transaction and publication boundary

Pairing calculation happens outside the database write transaction on the
immutable snapshot. Before commit, ChessPairings must re-check that the
tournament revision and canonical input hash are unchanged. The complete
pairing is then written atomically with its input/output hashes and identities.
If the revision changed, the result is discarded and recalculated from a new
snapshot; it is never patched board by board.

The portable half of that boundary is represented by
`docs/schema/thp-precommit-observation-1.schema.json` and can be checked with:

```console
php tools/verify_thp_precommit_guard.php request.json original-snapshot.json observation.json fresh-snapshot.json
```

The guard replays the original request gate, binds the observation to the same
job and candidate identity, verifies the fresh canonical snapshot, and returns
an honest `NO_GO` if either the revision or canonical-input hash changed. It
does not read the product database or prove that the supplied observation is
live. Even `GO` authorizes no write: the exact product adapter must produce the
observation from an immediate database reread and own the atomic transaction.

The isolated product transaction harness records one success and one injected
failure after each required write stage using
`docs/schema/thp-transaction-probe-batch-1.schema.json`. Validate its
identity, denominator, ordered write prefixes and before/after state hashes
with:

```console
php tools/verify_thp_transaction_probes.php transaction-probes.json
```

The required write stages are games, history, points, round state, bye resets
and the request/result attestation. Every failure probe must restore the exact
baseline revision and logical-state hash with zero commits; the success probe
must apply the complete set exactly once. The verifier rejects personal-data
keys and distinguishes incomplete/malformed evidence from an honest `NO_GO`.
It validates supplied records only: the exact product harness must derive the
hashes from a disposable database and perform the fault injections.

The asynchronous boundary has a separate four-scenario evidence batch in
`docs/schema/thp-recovery-probe-batch-1.schema.json`. Validate it with:

```console
php tools/verify_thp_recovery_probes.php recovery-probes.json
```

The batch must demonstrate a crash before commit, a crash after commit, a
late result for a superseded revision and duplicate delivery of an already
accepted result. Recovery is accepted only when the durable job identity is
reloaded, the observed logical-state hashes remain exactly on the permitted
baseline/committed/newer states, and commit/publication counts prove at-most-
once application. A second recovery pass must be idempotent. The verifier
validates supplied, privacy-minimised evidence; it neither restarts a worker
nor accesses a database, so `GO` is not a product acceptance verdict.

FIDE-mode routing is recorded separately in
`docs/schema/thp-mode-probe-batch-1.schema.json` and checked against the exact
candidate identity with:

```console
php tools/verify_thp_mode_probes.php mode-probes.json candidate.json artifact-root
```

There must be one successful route for every pairing system declared by the
candidate and exactly four fail-closed routes: unknown system, team input,
individual TRF record `299`, and another undeclared feature. Rejected routes
must make zero OpenPairing, legacy or manual-fallback calls and zero writes;
successful routes must call the selected declared system exactly once and
still make no write during this pre-transaction probe. The exact product
harness must count calls at the real GUI/API routing seam. The verifier only
validates the supplied, identity-bound and privacy-minimised call record.

The full pre-write product route has its own performance matrix in
`docs/schema/thp-performance-probe-batch-1.schema.json`. Validate the matrix
against the exact candidate with:

```console
php tools/verify_thp_performance_probes.php performance-probes.json candidate.json artifact-root
```

The batch declares the supported player range, explicit time/memory budgets
and at least three increasing player counts including the supported maximum.
Every declared pairing system must have one probe at every required size, with
at least three complete samples from the identical immutable input. Every
sample must finish within both budgets and return the same functional hash;
the pre-write probe must make zero writes. The measured interval belongs to
the product harness and must include snapshot mapping, the engine call and
independent result validation. The verifier does not execute or time that
route itself, so its `GO` is an evidence-format verdict only.

Publication remains a separate operator action. Shadow output cannot enter
this transaction, alter the primary result or trigger a retry of the primary
action.

## Format ownership

- ChessPairings owns rating-list import, tournament/player/result management,
  operator permissions, persistence, recovery and the English FIDE-mode UI.
- OpenPairing owns the documented canonical model, declared individual TRF26
  codec surface, pairing/standings result and public PTC/RTG behavior.
- The adapter owns the lossless mapping and must prove round trips for records
  `142`, `152`, `240`, `250` and `260`, plus all result/forfeit codes in the
  submitted scope.
- Generic individual record `299` remains fail-closed until its semantics are
  implemented end to end or TEC accepts its exclusion. The adapter may not
  discard it to obtain a green pairing or standings result.
- Team data is outside the initial individual integration and must be rejected
  before reaching the individual engine.

## PTC, RTG and controlled environment

The delivered THP must include the exact PTC and RTG binaries/scripts that use
the same packaged engines. The controlled environment must reproduce:

1. positive and deliberately negative PTC fixtures;
2. deterministic RTG output and RTG-to-PTC round trip;
3. product import, pairing, result entry, standings, export and recovery;
4. identity and canonical-hash diagnostics without exposing personal data;
5. fail-closed behavior for identity drift, unsupported records and a stale
   tournament revision.

## Evidence and rollout

1. Freeze an exact clean product source/migration snapshot, then create and
   verify the candidate identity and adapter mapping from that snapshot.
2. Run synthetic end-to-end fixtures with the existing product output kept
   authoritative.
3. Start the isolated observer described by `SHADOW-VALIDATION.md`; validate
   its NDJSON evidence with `tools/verify_shadow_evidence.php`.
4. Investigate and independently classify every non-exact turn. A different
   legal optimum is not automatically a failure, and equality alone is not
   proof of legality.
5. Close the product budget, rare-state coverage and complete THP workflow on
   the same identity.
6. Promote only through a separately authorized release with a documented
   rollback. No shadow result causes automatic promotion.

Any source, mapping, engine, runtime or product change after step 1 creates a
new candidate identity and invalidates only the identity-dependent evidence;
historical artifacts remain preserved and accurately labelled.
