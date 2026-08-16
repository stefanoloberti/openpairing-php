# ChessPairings integration inventory

This is a read-only inventory of the local ChessPairings web checkout observed
on 16 August 2026. It is planning evidence, not a review of a frozen product:
the checkout did not expose a usable Git identity and contains changes newer
than the latest `5.20.5` changelog entry. No production request, database write
or deployment was made during the inspection.

The exact inspected file hashes and findings are recorded in
`evidence/chesspairings-pairing-path-inventory-20260816.json`.

## Current seam

`classes/Abbinamenti.php` owns the individual pairing flow. It constructs a
TRF snapshot, selects one of four HTTP pairing services, submits synchronous
or asynchronous work, parses a compact text response and writes games and
history. OpenPairingPHP is not one of the selectable engines in this snapshot.
The existing code already models requested byes, withdrawals, initial colour,
record `142`, draft pairings and product ownership checks; those are useful
inputs to a future adapter, not proof that the adapter exists.

The desktop synchronisation schema has a revision counter, but the inspected
web pairing path does not use that revision as a compare-and-swap boundary.
The future adapter should reuse the concept, not infer that the current web
and Desktop identities are interchangeable.

## Submission-blocking gaps

These are product integration gaps, not defects attributed to the OpenPairing
engine:

1. **No exact integration identity.** The product path neither embeds nor
   identifies the OpenPairing package, engine, PTC or RTG. The checkout itself
   also lacks a usable source commit, so it cannot become the submitted
   identity as observed.
2. **No fail-closed FIDE-mode route.** Unknown engine values fall back to a
   legacy engine port and manual pairing remains a product option. A future
   FIDE mode needs an explicit allow-list and must not silently select another
   engine or permit a fallback into accepted evidence.
3. **No immutable input/revision check.** The asynchronous state file stores
   tournament data and TRF but no canonical input hash, product/engine identity
   or database revision. Completion does not prove the tournament is unchanged
   since submission.
4. **No atomic commit boundary.** Pairing, game/history writes, point updates,
   round advancement and bye resets are separate operations. Some paths delete
   existing rows before calculation or validation. The submitted path needs a
   transaction with a final revision/hash comparison and complete rollback.
5. **Output accounting is permissive.** The parser can skip absent or short
   pair lines and does not itself prove that every eligible player appears
   exactly once, IDs are unique and reciprocal, the count matches the declared
   output or all hard constraints remain valid. Draft warnings are explicitly
   non-blocking.
6. **First-round random colour is not frozen.** Before a saved first round, a
   retry can draw a new random initial colour while generating the TRF. The
   selected value must be persisted before the engine call and included in the
   canonical input identity.
7. **No FIDE-path request/result attestation.** Hash-linked engine request and
   response evidence exists for a separate USCF path, but not for the current
   FIDE engine routes.
8. **Post-engine transformations need ownership.** Requested byes and withdrawn
   players are removed from or appended to the engine output. The final
   candidate must either model these in the canonical engine input/output or
   attest and validate the deterministic transformation as part of the adapter.

## Required adapter boundary

The first isolated implementation should be a new adapter beside the current
path, never an in-place production switch. It must:

- build the canonical snapshot specified by `THP-INTEGRATION-CONTRACT.md`;
- persist an opaque job ID, tournament revision, input SHA-256 and the exact
  candidate identity without player names in evidence;
- call only the frozen OpenPairing public API and require the expected engine
  identity in the result;
- run independent product-side completeness and hard-constraint validation;
- re-read and compare the revision and input hash immediately before an atomic
  transaction;
- keep shadow output read-only and outside publication, notification and retry
  behavior;
- expose explicit failure states for stale input, identity drift, timeout,
  no-solution, resource limit, malformed output and unsupported TRF state.

## Safe implementation order

1. Freeze a source-controlled ChessPairings candidate branch and its database
   migration set.
2. Implement the canonical snapshot and golden mapping fixtures without an
   engine call, then validate each envelope with
   `tools/verify_thp_pairing_request.php`.
3. Add the local OpenPairing adapter and product-side validator behind an
   isolated shadow-only flag.
4. Prove stale-revision and injected-failure rollback tests before any live
   observation.
5. Bind every shadow event to the exact identity manifest and validate batches
   with `tools/verify_shadow_evidence.php`.
6. Only after the shadow and complete THP workflow gates close, create a new
   separately authorised release candidate. No step here authorises a
   production change.

The executable acceptance sequence and required synthetic fixture families
are enumerated in `THP-PRODUCT-ACCEPTANCE-PLAN.md`.
