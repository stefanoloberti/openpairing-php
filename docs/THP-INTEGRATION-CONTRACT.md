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

1. Freeze an exact candidate and its adapter mapping.
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
